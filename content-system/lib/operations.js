"use strict";

const { successResponse } = require("../../shared/response");
const { escapeHtml } = require("../../shared/rendering");
const { SkillError, ERROR_CODES } = require("../../shared/errors");
const { getModuleVersions } = require("../../shared/module-versions");
const { UNITS, DEFAULT_ROOTS, BK_BACKLOG_NAME, BK_FOLDER_NAME, DEFAULT_UNIT, getUnitRules, assertUnitDriveConfigured, CONTENT_SYSTEM_SUBFOLDERS, CONTENT_SYSTEM_SHEETS } = require("./constants");
const { driveData } = require("./drive-client");
const {
  isDbConfigured,
  dbCreateAngle, dbListAngles, dbUpdateAngle,
  dbCreateContentItem, dbListContentItems, dbGetContentItem, dbUpdateContentItem,
  dbListMedia,
  dbCreateSource, dbListSources, dbGetSource, dbUpdateSource, dbDeleteSource, dbListAnglesBySource,
  dbRankAngle, dbListAngleRanking,
} = require("./db-client");
const {
  detectInputType,
  guessSourceType,
  pickPainCluster,
  pickStatementType,
  guessIcp,
  buildAngleSentence,
  buildChannelRecommendations,
  enforceTone,
  assertNoForbiddenTone,
  buildToneChecks,
  countMatches,
} = require("./content-rules");
const strategy = require("./content-strategy");
const media = require("./content-media");

function toBulletHtml(items) {
  return `<ul>${items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>`;
}

function progress(runtime, message) {
  runtime?.introspect?.(message);
}

function resolveUnitFrom(config = {}, unit) {
  return unit || config.unit || DEFAULT_UNIT;
}

function hashtagsForContent(unit = DEFAULT_UNIT) {
  return getUnitRules(unit).hashtags || [];
}

// Liefert die Unit-Konfiguration. Sucht den Content System Ordner unter
// VIMINDS_PRODUCTS_ROOT_ID > [Unit] > Marketing & Sales > Content System.
// Sonst Fallback auf Legacy-Einzel-IDs.
async function getRoots(config = {}, unit) {
  const resolved = resolveUnitFrom(config, unit);
  const unitConfig = UNITS[resolved] || UNITS[DEFAULT_UNIT];

  // Wenn contentSystemRootId explizit gesetzt ist (setup_args oder constants), nutze sie
  if (config.contentSystemRootId) {
    return {
      unit: resolved,
      contentSystemRootId: config.contentSystemRootId,
      marketingSalesRootId: config.contentSystemRootId,
      personasFolderId: null,
      ideasInboxFolderId: null,
      ideasInboxDocId: null,
      angleLibraryFolderId: null,
      editorialPlanFolderId: null,
      editorialPlanDocId: null,
      foundationIndexDocId: null,
      _config: config,
      _unitConfig: unitConfig,
    };
  }

  // Suche Content System Ordner unter VIMINDS_PRODUCTS_ROOT_ID
  if (config.vimindsProductsRootId) {
    try {
      // 1. Unit-Ordner suchen (z.B. "viscale" oder "VIM / viscale" unter Viminds Products)
      const unitSearch = await driveData(config, "findByName", { name: resolved, rootId: config.vimindsProductsRootId });
      const unitItems = unitSearch.items || unitSearch.results || unitSearch.files || [];
      const unitFolder = unitItems.find((item) => {
        const name = (item.name || "").trim().toLowerCase();
        return name === resolved.toLowerCase() || name === `vim / ${resolved.toLowerCase()}` || name.includes(resolved.toLowerCase());
      });
      if (!unitFolder) throw new Error(`Unit-Ordner '${resolved}' nicht gefunden unter Viminds Products`);

      // 2. Marketing & Sales Ordner suchen (echter Name: "Marketing und Sales")
      const msSearch = await driveData(config, "findByName", { name: "Marketing und Sales", rootId: unitFolder.id });
      const msItems = msSearch.items || msSearch.results || msSearch.files || [];
      const msFolder = msItems.find((item) => {
        const name = (item.name || "").trim().toLowerCase();
        return name === "marketing und sales" || name === "marketing & sales" || name.includes("marketing");
      });
      if (!msFolder) throw new Error(`Marketing & Sales Ordner nicht gefunden unter ${resolved}`);

      // 3. Content System Ordner suchen (oder erstellen)
      const csFolderId = await findOrCreateSubfolder(config, msFolder.id, "Content System");

      // 4. 03-Personas-Positioning Ordner suchen (optional, für Foundation)
      const psSearch = await driveData(config, "findByName", { name: "03-Personas-Positioning", rootId: msFolder.id });
      const psItems = psSearch.items || psSearch.results || psSearch.files || [];
      const psFolder = psItems.find((item) => (item.name || "").trim().toLowerCase().includes("persona")) || null;

      return {
        unit: resolved,
        contentSystemRootId: csFolderId,
        marketingSalesRootId: msFolder.id,
        personasFolderId: psFolder?.id || null,
        ideasInboxFolderId: null,
        ideasInboxDocId: null,
        angleLibraryFolderId: null,
        editorialPlanFolderId: null,
        editorialPlanDocId: null,
        foundationIndexDocId: null, // wird lazy via foundation_index geladen
        _config: config,
        _unitConfig: unitConfig,
      };
    } catch (err) {
      // Fallback auf Legacy wenn Suche fehlschlägt — Fehler detailliert loggen
      const detail = err?.details?.remote_message || err?.message || String(err);
      progress(config.__runtime, `⚠️ Content System Ordner-Auflösung fehlgeschlagen (${detail}). Nutze Legacy-Fallback.`);
      console.error(`[content-system] getRoots fallback für Unit '${resolved}':`, detail);
    }
  }

  // Legacy: Einzel-IDs aus Config oder Unit-Defaults
  return {
    unit: resolved,
    contentSystemRootId: null,
    marketingSalesRootId: config.marketingSalesRootId || unitConfig.marketingSalesRootId || DEFAULT_ROOTS.marketingSalesRootId,
    personasFolderId: config.personasFolderId || unitConfig.personasFolderId || DEFAULT_ROOTS.personasFolderId,
    ideasInboxFolderId: config.ideasInboxFolderId || unitConfig.ideasInboxFolderId || DEFAULT_ROOTS.ideasInboxFolderId,
    ideasInboxDocId: config.ideasInboxDocId || unitConfig.ideasInboxDocId || DEFAULT_ROOTS.ideasInboxDocId,
    angleLibraryFolderId: config.angleLibraryFolderId || unitConfig.angleLibraryFolderId || DEFAULT_ROOTS.angleLibraryFolderId,
    editorialPlanFolderId: config.editorialPlanFolderId || unitConfig.editorialPlanFolderId || DEFAULT_ROOTS.editorialPlanFolderId,
    editorialPlanDocId: config.editorialPlanDocId || unitConfig.editorialPlanDocId || DEFAULT_ROOTS.editorialPlanDocId,
    foundationIndexDocId: config.foundationIndexDocId || unitConfig.foundationIndexDocId || DEFAULT_ROOTS.foundationIndexDocId,
  };
}

// Sucht oder erstellt einen Unterordner unter contentSystemRootId.
// Prueft zuerst ob der Ordner existiert, legt nur an wenn nicht gefunden.
async function findOrCreateSubfolder(config, parentId, name) {
  const search = await driveData(config, "findByName", { name, rootId: parentId });
  const items = search.items || search.results || search.files || [];
  const existing = items.find((item) => (item.name || "").trim() === name)?.id || null;
  if (existing) return existing;
  const created = await driveData(config, "createFolder", { parentId, name });
  return created.id || created.folderId || null;
}

// Sucht oder erstellt ein Doc unter einem Ordner.
// Prueft zuerst ob das Doc existiert, legt nur an wenn nicht gefunden.
async function findOrCreateDoc(config, parentId, name, initialContent = "") {
  const search = await driveData(config, "findByName", { name, rootId: parentId });
  const items = search.items || search.results || search.files || [];
  const existing = items.find((item) => (item.name || "").trim() === name)?.id || null;
  if (existing) return existing;
  const created = await driveData(config, "createDoc", { parentId, name });
  const docId = created.id || created.fileId || created.docId || null;
  if (docId && initialContent) {
    await driveData(config, "writeDoc", { docId, content: initialContent });
  }
  return docId;
}

// Sucht oder erstellt ein Sheet unter einem Ordner und initialisiert Header.
// Prueft zuerst ob das Sheet existiert, legt nur an wenn nicht gefunden.
async function findOrCreateSheet(config, parentId, name, columns = []) {
  const search = await driveData(config, "findByName", { name, rootId: parentId });
  const items = search.items || search.results || search.files || [];
  const existing = items.find((item) => (item.name || "").trim() === name)?.id || null;
  if (existing) return existing;
  const created = await driveData(config, "createSheet", { parentId, name });
  const sheetId = created.id || created.fileId || created.sheetId || null;
  if (sheetId && columns.length) {
    // Kurz warten damit Google Drive das Sheet vollständig anlegt bevor wir schreiben
    await new Promise((resolve) => setTimeout(resolve, 2000));
    await driveData(config, "appendRow", { sheetId, values: columns });
  }
  return sheetId;
}

// Liefert die IDs der Standard-Unterordner unter contentSystemRootId.
// Foundation kommt aus dem bestehenden 03-Personas-Positioning Ordner (Sibling).
async function getContentSystemFolders(config, unit) {
  const roots = await getRoots(config, unit);
  if (!roots.contentSystemRootId) return roots; // Legacy-Modus

  const [angles, plan, output] = await Promise.all([
    findOrCreateSubfolder(config, roots.contentSystemRootId, CONTENT_SYSTEM_SUBFOLDERS.angles),
    findOrCreateSubfolder(config, roots.contentSystemRootId, CONTENT_SYSTEM_SUBFOLDERS.plan),
    findOrCreateSubfolder(config, roots.contentSystemRootId, CONTENT_SYSTEM_SUBFOLDERS.output),
  ]);

  return {
    ...roots,
    angleLibraryFolderId: angles,
    editorialPlanFolderId: plan,
    outputFolderId: output,
  };
}

// Liefert die Sheet-IDs für Angle Library und Content System.
async function getContentSystemDocs(config, unit) {
  const folders = await getContentSystemFolders(config, unit);
  if (!folders.contentSystemRootId) return folders; // Legacy-Modus

  const rules = getUnitRules(unit);
  const angleSheet = await findOrCreateSheet(
    config,
    folders.angleLibraryFolderId,
    `Angle Library · ${rules.name || unit}`,
    CONTENT_SYSTEM_SHEETS.ANGLE_LIBRARY.columns
  );
  const planSheet = await findOrCreateSheet(
    config,
    folders.editorialPlanFolderId,
    `Content System · ${rules.name || unit} · ${new Date().getFullYear()}`,
    CONTENT_SYSTEM_SHEETS.CONTENT_PLAN.columns
  );

  return {
    ...folders,
    angleLibrarySheetId: angleSheet,
    contentPlanSheetId: planSheet,
  };
}

function getBkNames(config = {}, unit) {
  const resolved = resolveUnitFrom(config, unit);
  const unitConfig = UNITS[resolved] || UNITS[DEFAULT_UNIT];
  return {
    backlogName: config.bkBacklogName || unitConfig.bkBacklogName || BK_BACKLOG_NAME,
    folderName: config.bkFolderName || unitConfig.bkFolderName || BK_FOLDER_NAME,
  };
}

function buildHandoffContext({ workflow, nextSuggestedActions = [], resources = {}, icp = null, unit = null } = {}) {
  return {
    domain: "content-system",
    workflow,
    icp,
    unit,
    next_suggested_actions: nextSuggestedActions,
    resources,
  };
}

function debugAction(runtime, config) {
  return successResponse({
    data: {
      operation: "debug",
      runtime_arg_keys: Object.keys(runtime?.runtimeArgs || {}),
      resolved_config: {
        drive_script_url_configured: Boolean(config.driveScriptUrl),
        drive_token_configured: Boolean(config.driveToken),
        viminds_products_root_configured: Boolean(config.vimindsProductsRootId),
        content_system_root_configured: Boolean(config.contentSystemRootId),
      },
    },
    message: "✅ Content-System-Konfiguration geprüft.",
    html: `<div><h3>Content System Debug</h3><p><b>Drive konfiguriert:</b> ${escapeHtml(String(Boolean(config.driveScriptUrl && config.driveToken)))}</p><p><b>Viminds Products Root:</b> ${escapeHtml(String(Boolean(config.vimindsProductsRootId)))}</p></div>`,
    context: buildHandoffContext({ workflow: "debug", unit: config.unit || DEFAULT_UNIT }),
  });
}

function startContentAction(runtime, args) {
  progress(runtime, "🧭 Starte Content-Intake ...");
  const missing = [];
  if (!args.input) missing.push({ field: "input", question: "Welche Idee, URL, Beobachtung oder welches Zitat soll verarbeitet werden?" });
  if (!args.intent) missing.push({ field: "intent", question: "Willst du eher einen Angle entwickeln, direkt produzieren oder den Redaktionsplan aktualisieren?" });
  if (missing.length) {
    return successResponse({
      data: {
        operation: "intake_start",
        workflow: "content_system_intake",
        missing_fields: missing,
      },
      message: "✅ Ich kann den Content-Workflow starten. Mir fehlen noch ein paar Angaben.",
      html: `<div><h3>Content Intake</h3>${toBulletHtml(missing.map((item) => `${item.field}: ${item.question}`))}</div>`,
      context: buildHandoffContext({ workflow: "start_content", unit: args.unit || DEFAULT_UNIT, nextSuggestedActions: ["idee", "produzieren", "redaktionsplan"] }),
    });
  }

  return successResponse({
    data: {
      operation: "intake_ready",
      input: args.input,
      icp: args.icp || null,
      unit: args.unit || DEFAULT_UNIT,
      intent: args.intent,
      next_step: args.intent.includes("plan") ? "redaktionsplan" : args.intent.includes("produ") ? "produzieren" : "idee",
    },
    message: "✅ Intake vollständig genug für den nächsten Schritt.",
    html: `<div><h3>Content Intake bereit</h3><p><b>Intent:</b> ${escapeHtml(args.intent)}</p><p><b>Input:</b> ${escapeHtml(args.input)}</p></div>`,
    context: buildHandoffContext({ workflow: "start_content", icp: args.icp || null, unit: args.unit || DEFAULT_UNIT, nextSuggestedActions: ["idee", "produzieren", "redaktionsplan"] }),
  });
}

function normalizeNewlines(value) {
  return String(value || "").replace(/\r\n/g, "\n");
}

function countWords(text) {
  return String(text || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean).length;
}

function isoWeekLabel(date = new Date()) {
  const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  const dayNr = (target.getUTCDay() + 6) % 7;
  target.setUTCDate(target.getUTCDate() - dayNr + 3);
  const firstThursday = new Date(Date.UTC(target.getUTCFullYear(), 0, 4));
  const firstDayNr = (firstThursday.getUTCDay() + 6) % 7;
  firstThursday.setUTCDate(firstThursday.getUTCDate() - firstDayNr + 3);
  const week = 1 + Math.round((target - firstThursday) / 604800000);
  return `KW ${String(week).padStart(2, "0")}`;
}

async function fetchSourceExcerpt(url) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await fetch(url, {
      headers: { "User-Agent": "AnythingLLM Content System" },
      signal: controller.signal,
    });
    const raw = await response.text();
    return raw
      .replace(/<script[\s\S]*?<\/script>/gi, " ")
      .replace(/<style[\s\S]*?<\/style>/gi, " ")
      .replace(/<[^>]+>/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .slice(0, 2500);
  } catch (_) {
    return "";
  } finally {
    clearTimeout(timeout);
  }
}

async function readFoundationIndex(config, unit) {
  const roots = await getRoots(config, unit);
  // Foundation-Index-Doc dynamisch suchen: zuerst in personasFolderId, dann Foundation-Index Doc per Name
  let foundationDocId = null;
  if (roots.personasFolderId) {
    const search = await driveData(config, "findByName", { name: "00_UEBERGABE_MARKETING", rootId: roots.personasFolderId });
    const items = search.items || search.results || search.files || [];
    const doc = items.find((item) => (item.name || "").includes("UEBERGABE") || (item.name || "").includes("INDEX") || (item.name || "").startsWith("00"));
    foundationDocId = doc?.id || null;
  }
  if (!foundationDocId && roots.foundationIndexDocId) {
    foundationDocId = roots.foundationIndexDocId;
  }
  if (!foundationDocId) {
    return { available: false, doc_id: null, title: "Foundation-Index nicht gefunden", content: "", url: null, mappings: parseFoundationIndex("") };
  }
  const result = await driveData(config, "readDoc", { docId: foundationDocId });
  const content = extractDocText(result);
  return {
    doc_id: foundationDocId,
    title: result.title || "00 INDEX Marketing Foundation",
    content,
    url: result.url || null,
    mappings: parseFoundationIndex(content),
  };
}

function extractDocText(docResult) {
  if (!docResult || typeof docResult !== "object") return "";
  if (typeof docResult.content === "string" && docResult.content.trim()) return docResult.content;
  if (typeof docResult.text === "string" && docResult.text.trim()) return docResult.text;
  if (Array.isArray(docResult.blocks)) {
    return docResult.blocks
      .map((block) => (typeof block?.text === "string" ? block.text : ""))
      .filter(Boolean)
      .join("\n")
      .trim();
  }
  return "";
}

function parseFoundationIndex(content) {
  const lines = normalizeNewlines(content).split("\n").map((line) => line.trim()).filter(Boolean);
  const entries = [];
  const idPattern = /([A-Za-z0-9_-]{20,})/g;

  for (const line of lines) {
    const matches = [...line.matchAll(idPattern)].map((match) => match[1]);
    if (!matches.length) continue;
    const label = line.replace(idPattern, "").replace(/[:|`]/g, " ").replace(/\s+/g, " ").trim() || "Unbenannter Index-Eintrag";
    for (const id of matches) {
      entries.push({ label, doc_id: id });
    }
  }

  const icp_docs = entries.filter((entry) => /\bicp\b/i.test(entry.label));
  const statement_docs = entries.filter((entry) => /statement/i.test(entry.label));
  const argumentarium_docs = entries.filter((entry) => /argument/i.test(entry.label));
  const competition_docs = entries.filter((entry) => /wettbewerb|compet/i.test(entry.label));
  const product_docs = entries.filter((entry) => /produkt|product/i.test(entry.label));

  return {
    entries,
    icp_docs,
    statement_docs,
    argumentarium_docs,
    competition_docs,
    product_docs,
  };
}

async function loadFoundationContext(config, { required = false, unit } = {}) {
  const resolved = resolveUnitFrom(config, unit);
  if (!config.driveScriptUrl || !config.driveToken) {
    if (required) {
      throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "Für Foundation-First ist DRIVE_SCRIPT_URL und DRIVE_TOKEN erforderlich oder der ICP muss explizit gesetzt werden.", {
        drive_script_url_configured: Boolean(config.driveScriptUrl),
        drive_token_configured: Boolean(config.driveToken),
      });
    }
    return { available: false, index: null };
  }
  const index = await readFoundationIndex(config, resolved);
  return { available: true, index };
}

function matchFoundationDocs(mappings, keywords = []) {
  const entries = Array.isArray(mappings?.entries) ? mappings.entries : [];
  if (!entries.length || !keywords.length) return [];
  return entries.filter((entry) => keywords.some((keyword) => entry.label.toLowerCase().includes(String(keyword).toLowerCase()))).slice(0, 8);
}

function buildFoundationRefs({ mappings, icp, painCluster, sourceType, unit } = {}) {
  const resolved = resolveUnitFrom({}, unit);
  const fallbackIndexDocId = (UNITS[resolved] || UNITS[DEFAULT_UNIT]).foundationIndexDocId || DEFAULT_ROOTS.foundationIndexDocId;
  if (!mappings) {
    return {
      index_doc_id: fallbackIndexDocId,
      icp_docs: [],
      statement_docs: [],
      argumentarium_docs: [],
      competition_docs: [],
      product_docs: [],
    };
  }

  const painKeywords = [painCluster?.code, painCluster?.name, "Statements_Universal", `Statements_${icp || ""}`].filter(Boolean);
  const icpKeywords = [icp, `ICP ${icp}`, `ICP-${icp}`].filter(Boolean);
  const competitionKeywords = sourceType === "wettbewerber" ? ["Wettbewerb", "Competitor"] : [];

  return {
    index_doc_id: fallbackIndexDocId,
    icp_docs: matchFoundationDocs(mappings, icpKeywords).length ? matchFoundationDocs(mappings, icpKeywords) : mappings.icp_docs || [],
    statement_docs: matchFoundationDocs(mappings, painKeywords).length ? matchFoundationDocs(mappings, painKeywords) : mappings.statement_docs || [],
    argumentarium_docs: mappings.argumentarium_docs || [],
    competition_docs: competitionKeywords.length ? matchFoundationDocs(mappings, competitionKeywords) : (mappings.competition_docs || []),
    product_docs: mappings.product_docs || [],
  };
}

function uniqueDocsById(items = []) {
  const seen = new Set();
  const result = [];
  for (const item of items) {
    const id = item?.doc_id;
    if (!id || seen.has(id)) continue;
    seen.add(id);
    result.push(item);
  }
  return result;
}

function shortenText(text, maxLength = 500) {
  const normalized = normalizeNewlines(text).replace(/\s+/g, " ").trim();
  if (normalized.length <= maxLength) return normalized;
  return `${normalized.slice(0, maxLength - 1).trim()}…`;
}

async function readFoundationDocs(config, refs) {
  if (!config.driveScriptUrl || !config.driveToken) return null;

  const selected = uniqueDocsById([
    ...(refs.icp_docs || []).slice(0, 2),
    ...(refs.statement_docs || []).slice(0, 2),
    ...(refs.argumentarium_docs || []).slice(0, 1),
  ]).slice(0, 4);

  if (!selected.length) return {
    docs: [],
    summary: [],
  };

  const docs = [];
  for (const ref of selected) {
    try {
      const doc = await driveData(config, "readDoc", { docId: ref.doc_id });
      const content = extractDocText(doc);
      docs.push({
        label: ref.label,
        doc_id: ref.doc_id,
        title: doc.title || ref.label,
        url: doc.url || null,
        excerpt: shortenText(content, 600),
      });
    } catch (_) {
      docs.push({
        label: ref.label,
        doc_id: ref.doc_id,
        title: ref.label,
        url: null,
        excerpt: "Dokument konnte nicht gelesen werden.",
      });
    }
  }

  return {
    docs,
    summary: docs.map((doc) => `${doc.title}: ${doc.excerpt}`),
  };
}

function extractSearchItems(result) {
  if (Array.isArray(result.items)) return result.items;
  if (Array.isArray(result.results)) return result.results;
  if (Array.isArray(result.files)) return result.files;
  return [];
}

async function findAngleMatches(config, angle, unit) {
  const roots = await getContentSystemFolders(config, unit);
  if (!config.driveScriptUrl || !config.driveToken) return [];
  assertUnitDriveConfigured(roots.unit);
  const keyword = String(angle || "").split(/\s+/).slice(0, 4).join(" ").trim();
  if (!keyword) return [];
  const result = await driveData(config, "findByName", { name: keyword, rootId: roots.angleLibraryFolderId });
  return extractSearchItems(result).slice(0, 5).map((item) => ({
    id: item.id || item.fileId || null,
    name: item.name || item.title || keyword,
    url: item.url || null,
  }));
}

async function summarizeIdea(args, config) {
  progress(null, "");
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  // Roots werden nur für Foundation-Lese-Operationen benötigt — kein Drive-Write mehr
  const roots = await getContentSystemDocs(config, unit);

  // Strategy-Context laden (für Brand Voice Validierung)
  const strategyCtx = await strategy.getStrategyContext(config, unit);

  // Foundation-Validierung: Versuchen, aber nicht blockieren wenn nicht vorhanden
  let foundation = { available: false, index: null };
  let foundationWarning = null;
  try {
    foundation = await loadFoundationContext(config, { required: false, unit });
    if (!foundation.available) {
      foundationWarning = "Keine Foundation-Unterlagen für diese Unit konfiguriert. Output wird NICHT gegen Marketing-Foundation validiert.";
    }
  } catch (err) {
    foundationWarning = `Foundation konnte nicht geladen werden: ${err.message}. Output wird NICHT validiert.`;
  }

  if (!args.icp) {
    // Ohne ICP: Versuche aus Foundation zu erraten, sonst frage
    if (foundation.available && foundation.index?.mappings?.icp_docs?.length) {
      // ICP aus Foundation ableiten (erster Treffer)
      const firstIcp = foundation.index.mappings.icp_docs[0]?.label?.match(/(B2B-[123]|B2C|UNI)/i)?.[1];
      if (firstIcp) {
        args.icp = firstIcp.toUpperCase();
        progress(config.__runtime, `🎯 ICP automatisch aus Foundation abgeleitet: ${args.icp}`);
      }
    }
    if (!args.icp) {
      throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "ICP unklar und keine Foundation-Unterlagen verfügbar. Bitte ICP explizit angeben (B2B-1, B2B-2, B2B-3, B2C, UNI).", {
        question: "Welcher ICP passt?",
        foundation_available: foundation.available,
        foundation_warning: foundationWarning,
      });
    }
  }

  progress(config.__runtime, `🧭 Analysiere Idee für ICP ${args.icp} (${unit}) ...`);
  if (foundationWarning) {
    progress(config.__runtime, `⚠️ ${foundationWarning}`);
  }

  const sourceText = args.source_url ? await fetchSourceExcerpt(args.source_url) : "";
  const combinedInput = [args.input, sourceText].filter(Boolean).join(" ");
  const inputType = detectInputType(args.source_url || args.input);
  const sourceType = guessSourceType(args.source_url || args.input, args.source_type);
  const icp = args.icp;
  const painCluster = pickPainCluster(combinedInput, unit);
  const statementType = args.statement_type || pickStatementType({ format: "linkedin_post", sourceType, input: combinedInput });
  const angle = buildAngleSentence({ input: combinedInput || args.input, painCluster, icp, statementType });
  const channels = buildChannelRecommendations(statementType);

  progress(config.__runtime, "🔎 Suche ähnliche Angles in der Library ...");
  const existingMatches = await findAngleMatches(config, angle, unit);

  // Foundation-Refs nur wenn verfügbar
  const foundationRefs = foundation.available ? buildFoundationRefs({
    mappings: foundation.index?.mappings,
    icp,
    painCluster,
    sourceType,
    unit,
  }) : null;
  const foundationGuidance = foundation.available ? await readFoundationDocs(config, foundationRefs) : null;

  progress(config.__runtime, "🧱 Formuliere Angle-Kandidat ...");
  const similarityNote = existingMatches.length
    ? "Ähnliches Material in der Angle-Library gefunden. Differenzierung prüfen."
    : "Kein offensichtlicher Duplikat-Treffer in der Angle-Library erkannt.";

  const validationStatus = foundation.available
    ? "✅ Validiert gegen Foundation"
    : "⚠️ NICHT validiert (keine Foundation-Unterlagen)";

  // Idee als Content-Item in DB persistieren (best-effort, kein Fehler wenn DB nicht konfiguriert)
  let dbItem = null;
  if (isDbConfigured(config)) {
    // Persona prüfen: Wenn owner gesetzt → persona_id aus strategy ableiten
    const owner = args.owner || null;
    const persona = strategy.getPersonaContext(strategyCtx, owner, icp);
    const personaId = persona?.id || owner || null;

    // Themen-Abgrenzung: Prüfen ob Thema für Persona erlaubt ist
    if (persona && strategy.isTopicForbidden(persona, combinedInput)) {
      progress(config.__runtime, `⚠️ Thema für Persona "${persona.name}" nicht erlaubt (forbidden_topics).`);
    }

    dbItem = await dbCreateContentItem(config, {
      unit_id: unit,
      typ: "idee",
      input: args.input || "",
      icp,
      pain_cluster: `${painCluster.code} · ${painCluster.name}`,
      statement_type: statementType,
      source: args.source_url || args.source_type || "idee",
      persona_id: personaId,
      owner: owner || icp,
    });
    if (dbItem) progress(config.__runtime, `💾 Idee in DB gespeichert: ${dbItem.item_id}${personaId ? ` (Persona: ${personaId})` : ""}`);
  }

  return successResponse({
    data: {
      operation: "angle_candidate",
      angle,
      icp,
      unit,
      item_id: dbItem?.item_id || null,
      pain_cluster: `${painCluster.key} · ${painCluster.code} · ${painCluster.name}`,
      statement_type: statementType,
      source_type: sourceType,
      input_type: inputType,
      source_excerpt: sourceText || null,
      similarity_note: similarityNote,
      existing_matches: existingMatches,
      channel_recommendations: channels,
      validation_status: validationStatus,
      foundation_available: foundation.available,
      foundation_index_doc_id: roots.foundationIndexDocId,
      foundation_refs: foundationRefs,
      foundation_guidance: foundationGuidance,
      foundation_warning: foundationWarning,
      strategy: strategyCtx || null,
    },
    message: `✅ Angle-Kandidat erzeugt. ${validationStatus}`,
    html: `<div><h3>ANGLE-KANDIDAT</h3><p><b>Angle:</b> ${escapeHtml(angle)}</p><p><b>ICP:</b> ${escapeHtml(icp)}</p><p><b>Unit:</b> ${escapeHtml(unit)}</p><p><b>Pain-Cluster:</b> ${escapeHtml(`${painCluster.code} · ${painCluster.name}`)}</p><p><b>Statement-Typ:</b> ${escapeHtml(statementType)}</p><p><b>Validierung:</b> ${escapeHtml(validationStatus)}</p>${dbItem ? `<p><b>DB:</b> ${escapeHtml(dbItem.item_id)}</p>` : ""}${foundationWarning ? `<p><b>⚠️ Warnung:</b> ${escapeHtml(foundationWarning)}</p>` : ""}${strategyCtx?.brand_voice ? `<p style="color:#888;font-size:0.85em;">📐 Brand Voice: ${escapeHtml(String(strategyCtx.brand_voice.personality || strategyCtx.brand_voice.tone || "—"))}</p>` : ""}</div>`,
    context: buildHandoffContext({
      workflow: "idee",
      icp,
      unit,
      nextSuggestedActions: ["produzieren", "angle_speichern", "redaktionsplan"],
      resources: {
        foundation_index_doc_id: roots.foundationIndexDocId,
        marketing_sales_root_id: roots.marketingSalesRootId,
        foundation_available: foundation.available,
        foundation_index_url: foundation.index?.url || null,
        foundation_refs: foundationRefs,
        foundation_guidance: foundationGuidance,
      },
    }),
  });
}

function buildLinkedInPost(args, strategyCtx = null) {
  const unit = args.unit || DEFAULT_UNIT;
  const hook = `${args.angle}`;
  const problem_lines = [
    args.mechanism || "Das Problem ist nicht zu wenig Aktivität — sondern fehlende Steuerbarkeit im System.",
    args.metric || "Ohne klare Logik sieht jede Pipeline sauber aus und liefert trotzdem Blindflug.",
    (args.proofs?.[0] || "Sicherheit wird simuliert, obwohl keine Beweislast hinter den Zahlen liegt."),
  ];
  const cost_lines = [
    "Dann hängen Forecast, Übergaben und Prioritäten weiter an Personen.",
    "Und Wachstum wird teurer statt planbarer.",
  ];
  const solution_lines = [
    "Was hilft, ist kein neues Feature.",
    "Sondern ein System, das Mechanismus, Datenhygiene und Führung zusammenzieht.",
    args.notes || "Dann wird aus Forecast, Übergabe und Priorisierung wieder eine belastbare Führungslogik.",
  ];
  const question = args.cta || "Würdest du eure aktuelle Forecast-Zahl selbst unterschreiben?";
  const hashtags = hashtagsForContent(unit);
  let text = [hook, "", ...problem_lines, "", ...cost_lines, "", ...solution_lines, "", "Denn das Problem ist nicht die Aktivität im Vertrieb.", "Das Problem ist die fehlende Beweislast hinter euren Entscheidungen.", "", question, "", hashtags.join(" ")].join("\n");
  if (countWords(text) < 120) {
    text += "\n\nWenn Zahlen nicht signierbar sind, wird aus jeder Forecast-Runde wieder Interpretation statt Steuerung.";
  }
  const toned = enforceTone(text, { format: "linkedin_post", unit, strategyCtx });
  assertNoForbiddenTone(toned, unit, strategyCtx);
  return {
    hook,
    problem_lines,
    cost_lines,
    solution_lines,
    question,
    hashtags,
    text: toned,
    quality_checks: {
      ...buildToneChecks(toned, { format: "linkedin_post", unit, strategyCtx }),
      has_blank_line_after_hook: toned.includes(`${hook}\n\n`),
      hashtag_count: hashtags.length,
      has_specific_metric_or_mechanism: Boolean(args.metric || args.mechanism || args.proofs?.[0]),
      closing_question_present: /\?$/.test(question),
    },
  };
}

function buildAdCopy(args, strategyCtx = null) {
  const unit = args.unit || DEFAULT_UNIT;
  const variants = [
    {
      frame: "Direkt",
      headline: "Forecast ohne Beweislast",
      primary_text: args.metric || `${args.angle}`,
      description: "System statt Blindflug",
    },
    {
      frame: "Bedrohlich",
      headline: "Teurer als kein CRM",
      primary_text: args.mechanism || "Wenn das CRM Sicherheit simuliert, werden Fehler später nur teurer.",
      description: "Struktur vor Skalierung",
    },
    {
      frame: "ROI",
      headline: "Gleiche Leads. Mehr Umsatz.",
      primary_text: args.metric || "Mit System wird aus derselben Nachfrage ein belastbarer Prozess.",
      description: "Mechanismus statt Mehrarbeit",
    },
  ].map((variant) => {
    const primaryText = enforceTone(variant.primary_text, { unit, strategyCtx });
    return {
      ...variant,
      primary_text: primaryText,
      quality_checks: {
        ...buildToneChecks(`${variant.headline} ${primaryText}`, { unit, strategyCtx }),
        headline_length_ok: variant.headline.length <= 40,
      },
    };
  });
  return variants;
}

function buildAcquisitionNewsletter(args, strategyCtx = null) {
  const unit = args.unit || DEFAULT_UNIT;
  const subject = args.metric || "Warum Forecasts im Mittelstand oft nur sauber aussehen";
  const intro = `${args.angle}`;
  const body = `${intro}\n\nDas Problem beginnt nicht bei der Menge der Leads, sondern bei der Art, wie sie durch den Vertrieb laufen. ${args.mechanism || "Wenn Übergaben, Zuständigkeiten und Pflichtfelder nicht zusammenpassen, sieht jede Pipeline sauber aus und bleibt trotzdem Blindflug."}\n\n${args.metric || "Ohne Zahlen mit Kontext wird jede Forecast-Runde zur Vertrauensfrage."} Genau dort entstehen die Kosten des Problems: Prioritäten werden falsch gesetzt, Forecasts werden weich und Führung wird reaktiv statt steuerbar.\n\nDas Gegenbild ist kein neues Feature, sondern ein System, das Beweislast erzeugt. Wenn Datenlogik, Ownership und Pipeline-Regeln zusammenpassen, wird aus derselben Nachfrage ein belastbarer Prozess.\n\n${args.cta || "Wenn ihr prüfen wollt, wo euer System gerade Sicherheit simuliert, antwortet auf diese Mail."}`;
  const toned = enforceTone(body, { unit, strategyCtx });
  assertNoForbiddenTone(toned, unit, strategyCtx);
  return {
    subject,
    intro,
    body: toned,
    word_count: countWords(toned),
    quality_checks: {
      ...buildToneChecks(`${subject} ${toned}`, { unit, strategyCtx }),
      subject_is_not_question: !subject.includes("?"),
      word_range_ok: countWords(toned) >= 80 && countWords(toned) <= 220,
    },
  };
}

function buildLandingPageHeadlines(args, strategyCtx = null) {
  const unit = args.unit || DEFAULT_UNIT;
  const variants = [
    { category: "Pain", headline: "Wenn euer Forecast nicht belastbar ist, ist euer Wachstum es auch nicht.", subline: "System statt Schätzlogik im Vertrieb." },
    { category: "Pain", headline: "Ein CRM ohne Mechanismus macht Fehler nur unsichtbar.", subline: "Erst Datenlogik, dann Skalierung." },
    { category: "Gain", headline: "Mehr Abschlüsse aus derselben Nachfrage.", subline: "Mit klarer Pipeline- und Führungslogik." },
    { category: "Gain", headline: "Aus Bauchgefühl wird ein steuerbarer Vertrieb.", subline: "Wenn Struktur, Zahlen und Zuständigkeiten zusammenpassen." },
    { category: "Direkt", headline: args.metric || "Von Blindflug zu belastbarer Forecast-Logik.", subline: "Konkrete Steuerung statt kosmetischer CRM-Pflege." },
  ].map((item) => ({
    ...item,
    headline: enforceTone(item.headline, { unit, strategyCtx }),
    subline: enforceTone(item.subline, { unit, strategyCtx }),
    quality_checks: buildToneChecks(`${item.headline} ${item.subline}`, { unit, strategyCtx }),
  }));
  return variants;
}

function ensureBkWordRange(text) {
  const words = countWords(text);
  if (words < 350 || words > 500) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "BK-Newsletter muss zwischen 350 und 500 Wörtern liegen.", { word_count: words });
  }
}

function buildBkNewsletter(args, strategyCtx = null) {
  const unit = args.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  const bk = rules.bk || {};
  const topic = args.topic || bk.defaultTopic;
  const subject = topic;
  const preheader = bk.preheader;
  const body = [
    "HALLO ZUSAMMEN,",
    "",
    "wir sehen gerade in mehreren Setups dass kleine Strukturfehler größere Folgeeffekte auslösen als fehlende Features.",
    "Diese Ausgabe zeigt, wo ihr zuerst hinschauen solltet und was ihr direkt im System prüfen könnt.",
    "",
    "## Haupt-Thema",
    `${topic} ist kein kosmetisches Detail. Wenn Pflichtfelder, Lifecycle-Logik oder Pipeline-Eigentümer nicht sauber gepflegt sind, wird aus jeder Auswertung schnell eine Interpretationsfrage. Das sehen wir nicht nur einmalig, sondern wiederkehrend in laufenden Setups.`,
    `Für euer System heißt das konkret: erst die Datenlogik stabilisieren, dann Automatisierung oder Reporting ausbauen. Sonst skaliert ihr Unschärfe statt Steuerbarkeit.`,
    `Wir sehen dabei oft drei Muster: Properties werden historisch mitgeschleppt, Phasen sind operativ nicht eindeutig und Verantwortlichkeiten wechseln zwischen Team und Einzelperson. Genau dadurch werden Reports weich und Nachverfolgung aufwendig.`,
    `Die Folge ist nicht nur ein unsauberes CRM, sondern spürbare Mehrarbeit im Alltag. Forecast-Runden dauern länger, weil Zahlen diskutiert statt gelesen werden. Übergaben werden abhängig von Einzelpersonen. Und selbst gute Automatisierungen liefern am Ende schwache Ergebnisse, wenn die zugrunde liegenden Felder nicht sauber geführt sind.`,
    `Gerade bei Bestandskunden ist das relevant, weil der eigentliche Hebel selten ein neues Tool ist. Der größere Hebel liegt fast immer darin, bestehende Datenstrukturen so zu pflegen, dass Teams Entscheidungen schneller und mit weniger Abstimmung treffen können. Genau dort entsteht Adoption: wenn das System im Alltag Arbeit spart und nicht zusätzliche Interpretation verlangt.`,
    `Was ihr jetzt tun könnt: Öffnet in HubSpot Settings → Objects → Deals → Pipelines und prüft, ob jede aktive Deal-Phase einen klaren Owner, Pflichtfelder und einen sauberen Exit-Kriteriensatz hat.`,
    `Wenn ihr dabei eine Phase findet, die im Alltag unterschiedlich verstanden wird, ist das meist das beste Signal für den nächsten Bereinigungsschritt. Erst wenn dieser Standard klar ist, lohnt es sich, Reports oder Automationen darauf aufzubauen.`,
    "",
    "## Zweites Thema · Kurz",
    "Aufgefallen bei Delivery: Teams mit klarer Renewal-Logik beantworten weniger Rückfragen, weil Zuständigkeiten und Zeitpunkte schon im System sichtbar sind. Das wirkt klein, spart aber im Alltag jedes Mal Abstimmung, wenn eine Verlängerung oder ein Folgegespräch ansteht.",
    "Das ist auch deshalb relevant, weil saubere Renewal-Pfade nicht nur für SCALE-Kunden wichtig sind. Schon in kleineren Setups entsteht spürbarer Nutzen, wenn Vertragsende, nächster Kontaktpunkt und Verantwortlichkeit an einer Stelle verlässlich gepflegt werden.",
    "",
    `Hauptaktion: ${args.main_cta || bk.mainCta}`,
    "",
    "In zwei Wochen schauen wir auf Renewal-Pipelines als Hebel für mehr Bestandssicherheit.",
    "Antwortet einfach auf diese Mail wenn ihr Fragen habt.",
    "",
    "Liebe Grüße",
    rules.signoff,
  ].join("\n");
  const toned = enforceTone(body, { unit, strategyCtx });
  assertNoForbiddenTone(toned, unit, strategyCtx);
  ensureBkWordRange(toned);
  return {
    topic,
    subject,
    preheader,
    body: toned,
    word_count: countWords(toned),
    quality_checks: {
      ...buildToneChecks(`${subject} ${preheader} ${toned}`, { format: "newsletter_bk", unit, strategyCtx }),
      single_cta: countMatches(toned, /Hauptaktion:/g) === 1,
      contains_hubspot_path: bk.pathCheck ? bk.pathCheck.test(toned) : true,
      word_range_ok: countWords(toned) >= 350 && countWords(toned) <= 500,
    },
  };
}

async function produceAsset(args, config) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  progress(config.__runtime, `🛠️ Erzeuge Asset ${args.format} ...`);
  const [foundation, strategyCtx] = await Promise.all([
    loadFoundationContext(config, { required: false, unit }),
    strategy.getStrategyContext(config, unit),
  ]);
  if (!args.icp) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "Für PRODUZIEREN muss ein ICP angegeben werden.", { field: "icp" });
  }

  let asset;
  const derivedPainCluster = pickPainCluster(`${args.angle} ${args.metric || ""} ${args.mechanism || ""}`, unit);
  progress(config.__runtime, "📚 Lade passende Foundation-Hinweise ...");
  const foundationRefs = buildFoundationRefs({
    mappings: foundation.index?.mappings,
    icp: args.icp,
    painCluster: derivedPainCluster,
    sourceType: null,
    unit,
  });
  const foundationGuidance = await readFoundationDocs(config, foundationRefs);
  progress(config.__runtime, "✍️ Baue finalen Inhalt ...");

  // Persona-Context für Tonalität
  const persona = strategy.getPersonaContext(strategyCtx, args.persona_id || args.owner, args.icp);
  const personaTone = strategy.getPersonaTone(persona, strategyCtx?.brand_voice);
  if (persona) {
    progress(config.__runtime, `👤 Persona: ${persona.name} (${persona.tone_notes || personaTone})`);
  }

  if (args.format === "linkedin_post") asset = buildLinkedInPost(args, strategyCtx);
  else if (args.format === "ad_copy") asset = { variants: buildAdCopy(args, strategyCtx) };
  else if (args.format === "newsletter_acquisition") asset = buildAcquisitionNewsletter(args, strategyCtx);
  else if (args.format === "landing_page_headlines") asset = { variants: buildLandingPageHeadlines(args, strategyCtx) };
  else if (args.format === "newsletter_bk") asset = buildBkNewsletter(args, strategyCtx);
  else throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Nicht unterstütztes Format: ${args.format}`);

  // Medien-Briefing bauen
  const mediaBriefings = media.buildMediaBriefing(args, args.format, strategyCtx);

  return successResponse({
    data: {
      operation: "produce_asset",
      format: args.format,
      icp: args.icp,
      unit,
      angle: args.angle,
      persona_id: persona?.id || null,
      persona_name: persona?.name || null,
      persona_tone: personaTone,
      foundation_refs: foundationRefs,
      foundation_guidance: foundationGuidance,
      asset,
      quality_checks: asset.quality_checks || null,
      strategy: strategyCtx || null,
      media_briefings: mediaBriefings,
    },
    message: `✅ Asset für ${args.format} erzeugt${mediaBriefings.length ? ` + ${mediaBriefings.length} Medien-Briefing(s)` : ""}${persona ? ` (${persona.name})` : ""}.`,
    html: `<div><h3>${escapeHtml(args.format)}</h3><p><b>ICP:</b> ${escapeHtml(args.icp)}</p><p><b>Unit:</b> ${escapeHtml(unit)}</p><p><b>Angle:</b> ${escapeHtml(args.angle)}</p>${asset.text ? `<pre style="white-space:pre-wrap;font-family:monospace;">${escapeHtml(asset.text)}</pre>` : ""}${mediaBriefings.length ? `<p style="margin-top:8px;color:#888;">📸 ${mediaBriefings.length} Medien-Briefing(s) erstellt. Nächster Schritt: <code>media_briefing</code> in DB speichern, dann <code>media_generieren</code></p>` : ""}</div>`,
    context: buildHandoffContext({
      workflow: "produzieren",
      icp: args.icp,
      unit,
      nextSuggestedActions: mediaBriefings.length
        ? ["media_briefing", "media_generieren", "redaktionsplan", "angle_speichern"]
        : ["redaktionsplan", "angle_speichern"],
      resources: {
        foundation_index_doc_id: foundation.index?.doc_id || null,
        foundation_refs: foundationRefs,
        foundation_guidance: foundationGuidance,
        media_briefings: mediaBriefings,
      },
    }),
  });
}

async function showEditorialPlan(config, unit) {
  const resolved = resolveUnitFrom(config, unit);
  const weekLabel = isoWeekLabel();
  const rules = getUnitRules(resolved);

  // DB-First
  if (isDbConfigured(config)) {
    progress(config.__runtime, "📋 Lade Content Items aus DB ...");
    const items = await dbListContentItems(config, { unit_id: resolved, limit: 200 }) || [];
    const counts = { ideas: 0, validated: 0, ready: 0, live: 0, total: items.length };
    for (const item of items) {
      if (item.status === "idee") counts.ideas += 1;
      else if (item.status === "in_produktion" || item.status === "fertig") counts.validated += 1;
      else if (item.status === "verarbeitet") counts.ready += 1;
      else if (item.status === "live") counts.live += 1;
    }
    const recent = items.slice(0, 10).map((i) => ({
      item_id: i.item_id,
      status: i.status,
      typ: i.typ,
      format: i.format,
      title: i.title || (i.input || "").slice(0, 60) || "—",
      icp: i.icp,
      live_date: i.live_date,
    }));
    return successResponse({
      data: {
        operation: "redaktionsplan_show",
        unit: resolved,
        week_label: weekLabel,
        title: `Content System · ${rules.name || resolved} · ${new Date().getFullYear()}`,
        summary: counts,
        total_rows: items.length,
        recent,
        db: true,
      },
      message: "✅ Content System aus DB geladen.",
      html: `<div><h3>CONTENT SYSTEM · ${escapeHtml(weekLabel)}</h3><p>💡 ${counts.ideas} · ✅ ${counts.validated} · 📋 ${counts.ready} · 🟢 ${counts.live}</p><p>Gesamt: ${items.length} Einträge</p></div>`,
      context: buildHandoffContext({ workflow: "redaktionsplan", unit: resolved, nextSuggestedActions: ["redaktionsplan", "produzieren", "list_content"] }),
    });
  }

  // Fallback: Drive Sheet
  progress(config.__runtime, "📋 Lade Content System Sheet (Drive) ...");
  const roots = await getContentSystemDocs(config, resolved);
  assertUnitDriveConfigured(resolved);
  const sheet = await driveData(config, "readSheet", { sheetId: roots.contentPlanSheetId });
  const rows = sheet.values || sheet.rows || [];
  const headers = rows[0] || [];
  const dataRows = rows.slice(1);
  const counts = { ideas: 0, validated: 0, ready: 0, live: 0 };
  for (const row of dataRows) {
    const status = String(row[headers.indexOf("Status")] || "").trim();
    if (status.includes("💡")) counts.ideas += 1;
    else if (status.includes("✅")) counts.validated += 1;
    else if (status.includes("📋")) counts.ready += 1;
    else if (status.includes("🟢")) counts.live += 1;
  }
  return successResponse({
    data: {
      operation: "redaktionsplan_show",
      unit: resolved,
      sheet_id: roots.contentPlanSheetId,
      title: `Content System · ${rules.name || resolved} · ${new Date().getFullYear()}`,
      week_label: weekLabel,
      summary: counts,
      total_rows: dataRows.length,
      url: sheet.url || null,
      db: false,
    },
    message: "✅ Content System Sheet geladen (Drive-Fallback).",
    html: `<div><h3>CONTENT SYSTEM · ${escapeHtml(weekLabel)}</h3><p>💡 ${counts.ideas} · ✅ ${counts.validated} · 📋 ${counts.ready} · 🟢 ${counts.live}</p><p>Gesamt: ${dataRows.length} Einträge</p></div>`,
    context: buildHandoffContext({ workflow: "redaktionsplan", unit: resolved, nextSuggestedActions: ["redaktionsplan", "produzieren"] }),
  });
}

async function addEditorialEntry(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  progress(config.__runtime, "📝 Ergänze Content System Eintrag ...");

  // DB-First: Content Item direkt in DB anlegen
  if (isDbConfigured(config)) {
    const dbItem = await dbCreateContentItem(config, {
      unit_id: unit,
      typ: args.format || "post",
      input: args.input || "",
      angle_id: args.angle_id || null,
      icp: args.icp || null,
      pain_cluster: args.pain_cluster || null,
      statement_type: args.statement_type || null,
      format: args.format || null,
      title: args.angle_short || args.title || null,
      owner: args.owner || rules.defaultOwner,
      live_date: args.live_date || null,
      output_url: args.link || null,
      source: args.source || null,
      notes: args.notes || null,
    });
    if (dbItem) {
      progress(config.__runtime, `💾 Eintrag in DB gespeichert: ${dbItem.item_id}`);
      return successResponse({
        data: {
          operation: "redaktionsplan_write",
          unit,
          item_id: dbItem.item_id,
          entry_id: dbItem.item_id,
          db: true,
        },
        message: "✅ Content System Eintrag in DB gespeichert.",
        html: `<div><h3>Content System aktualisiert (DB)</h3><p><b>ID:</b> ${escapeHtml(dbItem.item_id)}</p><p><b>Typ:</b> ${escapeHtml(dbItem.typ)}</p><p><b>Status:</b> ${escapeHtml(dbItem.status)}</p></div>`,
        context: buildHandoffContext({ workflow: "redaktionsplan", icp: args.icp || null, unit, nextSuggestedActions: ["produzieren"] }),
      });
    }
  }

  // Fallback: Drive Sheet (wenn keine DB oder DB-Write fehlgeschlagen)
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);
  const id = `CONT-${new Date().toISOString().slice(0, 10).replace(/-/g, "")}-${String(Date.now()).slice(-4)}`;
  const values = [
    id,
    new Date().toISOString().slice(0, 10),
    args.format || "format",
    args.status || "📋",
    args.input || "",
    args.angle_id || "",
    args.icp || "ICP",
    args.pain_cluster || "",
    args.statement_type || "",
    args.format || "format",
    args.angle_short || args.title || "Angle kurz",
    args.owner || rules.defaultOwner,
    args.live_date || "offen",
    args.output_doc_id || "",
    args.link || "",
    args.source || "",
    args.notes || "",
  ];
  await driveData(config, "appendRow", { sheetId: roots.contentPlanSheetId, values });

  return successResponse({
    data: {
      operation: "redaktionsplan_write",
      unit,
      sheet_id: roots.contentPlanSheetId,
      entry_id: id,
      db: false,
    },
    message: "✅ Content System Eintrag ergänzt (Drive-Fallback).",
    html: `<div><h3>Content System aktualisiert</h3><p><b>ID:</b> ${escapeHtml(id)}</p><p><b>Typ:</b> ${escapeHtml(args.format || "format")}</p><p><b>Status:</b> ${escapeHtml(args.status || "📋")}</p></div>`,
    context: buildHandoffContext({ workflow: "redaktionsplan", icp: args.icp || null, unit, nextSuggestedActions: ["produzieren"] }),
  });
}

async function harvestInbox(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  const limit = Math.max(1, args.limit || 10);

  // DB-First: Ideen aus content_items holen und Status updaten
  if (isDbConfigured(config)) {
    progress(config.__runtime, "🌾 Lese Ideen aus DB ...");
    const dbIdeas = await dbListContentItems(config, { unit_id: unit, status: "idee", limit }) || [];
    const ideas = dbIdeas.map((row) => {
      const input = row.input || "";
      const icp = row.icp || guessIcp(input, null, unit);
      const pain = pickPainCluster(input, unit);
      const rejected = input.length < 20;
      const parked = !rejected && !(rules.harvestCandidatePattern || /./).test(input);
      return {
        item_id: row.item_id,
        input,
        angle: buildAngleSentence({ input, painCluster: pain, icp, statementType: "Direkt" }),
        icp,
        pain_cluster: `${pain.code} · ${pain.name}`,
        recommended_format: "linkedin_post",
        status: rejected ? "verwerfen" : parked ? "parken" : "kandidat",
      };
    });

    // Status der verarbeiteten Items in DB aktualisieren
    if (ideas.length) {
      progress(config.__runtime, "🗂️ Aktualisiere Status in DB ...");
      for (const idea of ideas) {
        await dbUpdateContentItem(config, {
          item_id: idea.item_id,
          status: idea.status === "kandidat" ? "verarbeitet" : idea.status,
        });
      }
    }

    const candidates = ideas.filter((i) => i.status === "kandidat");
    const parked = ideas.filter((i) => i.status === "parken");
    const rejected = ideas.filter((i) => i.status === "verwerfen");
    return successResponse({
      data: { operation: "harvest", unit, processed_count: ideas.length, candidates, parked, rejected, db: true },
      message: `✅ ${ideas.length} Inbox-Ideen verarbeitet (DB).`,
      html: `<div><h3>Harvest (DB)</h3><p>Kandidaten: ${candidates.length} · Parken: ${parked.length} · Verwerfen: ${rejected.length}</p>${toBulletHtml(candidates.map((i) => `${i.angle} · ${i.icp}`))}</div>`,
      context: buildHandoffContext({ workflow: "harvest", unit, nextSuggestedActions: ["produzieren", "redaktionsplan", "angle_speichern"] }),
    });
  }

  // Fallback: Drive Sheet
  progress(config.__runtime, "🌾 Lese Content System Sheet (Typ: idee) ...");
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);

  const sheet = await driveData(config, "readSheet", { sheetId: roots.contentPlanSheetId });
  const rows = sheet.values || sheet.rows || [];
  const headers = rows[0] || [];
  const dataRows = rows.slice(1);

  const typeIdx = headers.indexOf("Typ");
  const statusIdx = headers.indexOf("Status");
  const inputIdx = headers.indexOf("Input");

  const unprocessed = dataRows
    .filter((row) => String(row[typeIdx] || "").trim() === "idee")
    .filter((row) => !["verwerfen", "verarbeitet", "archiviert"].includes(String(row[statusIdx] || "").trim()))
    .slice(0, limit);

  const ideas = unprocessed.map((row) => {
    const input = String(row[inputIdx] || "");
    const icp = guessIcp(input, null, unit);
    const pain = pickPainCluster(input, unit);
    const rejected = input.length < 20;
    const parked = !rejected && !(rules.harvestCandidatePattern || /./).test(input);
    return {
      input,
      angle: buildAngleSentence({ input, painCluster: pain, icp, statementType: "Direkt" }),
      icp,
      pain_cluster: `${pain.code} · ${pain.name}`,
      recommended_format: "linkedin_post",
      status: rejected ? "verwerfen" : parked ? "parken" : "kandidat",
    };
  });

  if (config.driveScriptUrl && config.driveToken && unprocessed.length) {
    progress(config.__runtime, "🗂️ Markiere verarbeitete Ideen im Sheet ...");
    for (let i = 0; i < unprocessed.length; i++) {
      const row = unprocessed[i];
      const idea = ideas[i];
      const rowIndex = dataRows.indexOf(row) + 2;
      await driveData(config, "writeCell", {
        sheetId: roots.contentPlanSheetId,
        cell: `D${rowIndex}`,
        value: idea.status === "kandidat" ? "verarbeitet" : idea.status,
      });
    }
  }

  const candidates = ideas.filter((i) => i.status === "kandidat");
  const parked = ideas.filter((i) => i.status === "parken");
  const rejected = ideas.filter((i) => i.status === "verwerfen");

  return successResponse({
    data: { operation: "harvest", unit, processed_count: ideas.length, candidates, parked, rejected, db: false },
    message: `✅ ${ideas.length} Inbox-Ideen verarbeitet (Drive-Fallback).`,
    html: `<div><h3>Harvest</h3><p>Kandidaten: ${candidates.length} · Parken: ${parked.length} · Verwerfen: ${rejected.length}</p>${toBulletHtml(candidates.map((i) => `${i.angle} · ${i.icp}`))}</div>`,
    context: buildHandoffContext({ workflow: "harvest", unit, nextSuggestedActions: ["produzieren", "redaktionsplan", "angle_speichern"] }),
  });
}

async function monitoring(args) {
  const unit = args.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  progress(null, "");
  return successResponse({
    data: {
      operation: "monitoring_brief",
      unit,
      competitors: args.competitors,
      channels: args.channels,
      gaps: rules.monitoring?.gaps || [],
      instructions: [
        "Meta Ad Library Skill für direkte Wettbewerber aufrufen.",
        "5-8 aktuelle LinkedIn-Posts pro Wettbewerber zusammenfassen.",
        rules.monitoring?.toneGapHint || "Danach Lücken gegen die Unit-Tonalität spiegeln.",
      ],
    },
    message: "✅ Monitoring-Briefing erzeugt.",
    html: `<div><h3>Monitoring</h3>${toBulletHtml(["Meta Ad Library prüfen", "LinkedIn-Posts vergleichen", rules.monitoring?.gapLine || "Lücken herausarbeiten"])}</div>`,
    context: buildHandoffContext({ workflow: "monitoring", unit, nextSuggestedActions: ["idee", "produzieren"] }),
  });
}

async function saveAngle(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  progress(config.__runtime, `💾 Speichere Angle für ${args.icp} ...`);

  // DB-First: Angle in content_angles + verknüpftes Content-Item anlegen
  if (isDbConfigured(config)) {
    const dbAngle = await dbCreateAngle(config, {
      unit_id: unit,
      angle: args.angle,
      icp: args.icp,
      pain_cluster: args.pain_cluster || null,
      statement_type: args.statement_type || null,
      source: args.source || "intern",
      source_id: args.source_id || null,
      batch_key: args.batch_key || null,
      funnel: args.funnel || null,
      viscale_phase: args.viscale_phase || null,
    });
    if (dbAngle) {
      progress(config.__runtime, `💾 Angle in DB: ${dbAngle.angle_id}`);
      // Zugehöriges Content-Item als Referenz anlegen
      await dbCreateContentItem(config, {
        unit_id: unit,
        typ: "angle",
        angle_id: dbAngle.angle_id,
        icp: args.icp,
        pain_cluster: args.pain_cluster || null,
        statement_type: args.statement_type || null,
        title: args.angle.split(/\s+/).slice(0, 8).join(" "),
        owner: rules.defaultOwner,
        source: args.source || "intern",
        notes: `Angle gespeichert: ${dbAngle.angle_id}`,
        status: "fertig",
      });
      return successResponse({
        data: {
          operation: "angle_save",
          unit,
          angle_id: dbAngle.angle_id,
          angle: args.angle,
          icp: args.icp,
          db: true,
        },
        message: "✅ Angle in DB gespeichert.",
        html: `<div><h3>Angle gespeichert (DB)</h3><p><b>ID:</b> ${escapeHtml(dbAngle.angle_id)}</p><p><b>Angle:</b> ${escapeHtml(args.angle)}</p></div>`,
        context: buildHandoffContext({
          workflow: "angle_speichern",
          icp: args.icp,
          unit,
          nextSuggestedActions: ["produzieren", "redaktionsplan"],
          resources: { angle_id: dbAngle.angle_id },
        }),
      });
    }
  }

  // Fallback: Drive Sheets
  progress(config.__runtime, `🔍 Lade Content System Docs (Drive-Fallback) ...`);
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);

  const date = new Date().toISOString().slice(0, 10);
  const angleId = `ANG-${date.replace(/-/g, "")}-${String(Date.now()).slice(-4)}`;

  if (!roots.angleLibrarySheetId) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, `Angle Library Sheet nicht gefunden für Unit '${unit}'.`, { unit });
  }
  if (!roots.contentPlanSheetId) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, `Content Plan Sheet nicht gefunden für Unit '${unit}'.`, { unit });
  }

  await driveData(config, "appendRow", {
    sheetId: roots.angleLibrarySheetId,
    values: [angleId, date, args.angle, args.icp, args.pain_cluster, args.statement_type, args.source || "intern", args.status || "Validiert", (args.assets || []).join(", ") || ""],
  });
  await driveData(config, "appendRow", {
    sheetId: roots.contentPlanSheetId,
    values: [`CONT-${date.replace(/-/g, "")}-${String(Date.now()).slice(-4)}`, date, "angle", "✅", "", angleId, args.icp, args.pain_cluster, args.statement_type, "", args.angle.split(/\s+/).slice(0, 8).join(" "), rules.defaultOwner, "", "", "", args.source || "intern", `Angle gespeichert: ${angleId}`],
  });

  return successResponse({
    data: { operation: "angle_save", unit, angle_id: angleId, angle: args.angle, icp: args.icp, db: false },
    message: "✅ Angle in der Library gespeichert (Drive-Fallback).",
    html: `<div><h3>Angle gespeichert</h3><p><b>ID:</b> ${escapeHtml(angleId)}</p><p><b>Angle:</b> ${escapeHtml(args.angle)}</p></div>`,
    context: buildHandoffContext({
      workflow: "angle_speichern",
      icp: args.icp,
      unit,
      nextSuggestedActions: ["produzieren", "redaktionsplan"],
      resources: { angle_id: angleId },
    }),
  });
}

/**
 * Speichert eine Quelle (PDF, URL, Interview, Research) in der DB.
 * Eine Quelle kann Basis für mehrere Angles sein.
 * Liefert source_id (SRC-…) zurück. Kein Drive-Write.
 */
async function saveSource(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(
      ERROR_CODES.CONFIGURATION_ERROR,
      "DB_URL nicht konfiguriert. source_save erfordert eine DB-Verbindung.",
      { action: "source_save", unit }
    );
  }
  const dbSource = await dbCreateSource(config, {
    unit_id: unit,
    type: args.type || "pdf",
    title: args.title,
    date: args.date || null,
    file_ref: args.file_ref || null,
    drive_file_id: args.drive_file_id || null,
    visibility: args.visibility || "intern",
    notes: args.notes || null,
  });
  if (!dbSource) {
    throw new SkillError(ERROR_CODES.STORAGE_ERROR, "Quelle konnte nicht in der DB gespeichert werden.", { action: "source_save" });
  }
  return successResponse({
    data: {
      operation: "source_save",
      unit,
      source_id: dbSource.source_id,
      type: dbSource.type,
      title: dbSource.title,
      date: dbSource.date,
      file_ref: dbSource.file_ref,
      visibility: dbSource.visibility,
      db: true,
    },
    message: `✅ Quelle in DB gespeichert: ${dbSource.source_id} · "${dbSource.title}"`,
    html: `<div><h3>Quelle gespeichert (DB)</h3><table style="width:100%;border-collapse:collapse;font-size:13px;"><tr><td style="padding:4px 8px;font-weight:bold;">ID</td><td><code>${escapeHtml(dbSource.source_id)}</code></td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Typ</td><td>${escapeHtml(dbSource.type)}</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Titel</td><td>${escapeHtml(dbSource.title)}</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Datum</td><td>${escapeHtml(dbSource.date || "—")}</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Datei</td><td>${escapeHtml(dbSource.file_ref || "—")}</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Sichtbarkeit</td><td>${escapeHtml(dbSource.visibility)}</td></tr></table><p style="color:#888;font-size:0.85em;margin-top:8px;">Nächster Schritt: Angles mit <code>source_id: "${dbSource.source_id}"</code> und <code>batch_key</code> speichern.</p></div>`,
    context: buildHandoffContext({
      workflow: "source_save",
      unit,
      nextSuggestedActions: ["angle_speichern", "source_list", "source_angles"],
      resources: { source_id: dbSource.source_id, title: dbSource.title, visibility: dbSource.visibility },
    }),
  });
}

/**
 * Listet alle Quellen einer Unit.
 */
async function listSources(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "source_list" });
  }
  const sources = await dbListSources(config, {
    unit_id: unit,
    type: args.type || null,
    visibility: args.visibility || null,
    limit: args.limit || 30,
  });
  return successResponse({
    data: { operation: "source_list", unit, sources: sources || [], total: (sources || []).length },
    message: `✅ ${(sources || []).length} Quelle(n) geladen.`,
    html: `<div><h3>Quellen · ${escapeHtml(unit)}</h3>${toBulletHtml((sources || []).map((s) => `${s.source_id} · ${s.type} · ${s.visibility} · ${s.title}`))}</div>`,
    context: buildHandoffContext({ workflow: "source_list", unit, nextSuggestedActions: ["source_angles", "angle_speichern"] }),
  });
}

/**
 * Listet alle Angles zu einer Quelle (source_id oder batch_key).
 */
async function listAnglesBySource(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "source_angles" });
  }
  if (!args.source_id && !args.batch_key) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "source_id oder batch_key erforderlich für source_angles.", { action: "source_angles" });
  }
  const angles = await dbListAnglesBySource(config, {
    source_id: args.source_id || null,
    unit_id: unit,
    batch_key: args.batch_key || null,
    limit: args.limit || 100,
  });
  return successResponse({
    data: { operation: "source_angles", unit, source_id: args.source_id || null, batch_key: args.batch_key || null, angles: angles || [], total: (angles || []).length },
    message: `✅ ${(angles || []).length} Angle(s) für diese Quelle.`,
    html: `<div><h3>Angles · ${escapeHtml(args.source_id || args.batch_key || "—")}</h3>${toBulletHtml((angles || []).map((a) => `${a.angle_id} · ${a.status} · ${a.icp || "—"} · ${a.angle.slice(0, 60)}`))}</div>`,
    context: buildHandoffContext({ workflow: "source_angles", unit, nextSuggestedActions: ["produzieren", "redaktionsplan"] }),
  });
}

/**
 * Bewertet einen Angle nach dem 4-Kriterien-Modell (je 1–3 Punkte).
 * Berechnet Score (4–12) und aktualisiert Rang im Batch automatisch.
 */
async function rankAngleAction(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "rank_angle" });
  }
  const result = await dbRankAngle(config, {
    angle_id: args.angle_id,
    r_zielgruppe: args.r_zielgruppe,
    r_viscale_fit: args.r_viscale_fit,
    r_schaerfe: args.r_schaerfe,
    r_timing: args.r_timing,
  });
  if (!result) {
    throw new SkillError(ERROR_CODES.RESOURCE_NOT_FOUND, `Angle ${args.angle_id} nicht gefunden.`, { action: "rank_angle" });
  }
  const a = result.angle;
  const score = a.ranking_score;
  const label = score >= 10 ? "🟢 Stark (10–12) — prioritär produzieren"
    : score >= 7 ? "🟡 Solide (7–9) — produzieren mit Format-Match"
    : "🔴 Schwach (4–6) — nur mit starkem Framing";
  const batchTable = result.batch_ranking.length
    ? "<table style=\"width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;\"><tr style=\"background:#f0f0f0;\"><th style=\"padding:4px 8px;text-align:left;\">Rang</th><th>Angle-ID</th><th>Score</th></tr>" +
      result.batch_ranking.map(function(r) {
        return "<tr><td style=\"padding:4px 8px;font-weight:bold;\">#" + r.rang + "</td><td><code>" + escapeHtml(r.angle_id) + "</code></td><td>" + r.score + "</td></tr>";
      }).join("") + "</table>"
    : "";
  return successResponse({
    data: {
      operation: "rank_angle",
      unit,
      angle_id: a.angle_id,
      angle: a.angle,
      r_zielgruppe: a.r_zielgruppe,
      r_viscale_fit: a.r_viscale_fit,
      r_schaerfe: a.r_schaerfe,
      r_timing: a.r_timing,
      score: a.ranking_score,
      rang: a.ranking_rang,
      batch_key: a.batch_key,
      batch_ranking: result.batch_ranking,
    },
    message: `✅ Angle bewertet: Score ${score}/12 · Rang #${a.ranking_rang || "—"} · ${label}`,
    html: `<div><h3>Angle-Ranking</h3><table style="width:100%;border-collapse:collapse;font-size:13px;"><tr><td style="padding:4px 8px;font-weight:bold;">Angle</td><td>${escapeHtml(a.angle || "—")}</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Zielgruppe</td><td>${a.r_zielgruppe}/3</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">viscale-Fit</td><td>${a.r_viscale_fit}/3</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Schärfe</td><td>${a.r_schaerfe}/3</td></tr><tr><td style="padding:4px 8px;font-weight:bold;">Timing</td><td>${a.r_timing}/3</td></tr><tr style="background:#f9f9f9;font-weight:bold;"><td style="padding:4px 8px;">Score</td><td>${score}/12 · Rang #${a.ranking_rang || "—"}</td></tr><tr><td style="padding:4px 8px;">Bewertung</td><td>${escapeHtml(label)}</td></tr></table>${batchTable}</div>`,
    context: buildHandoffContext({
      workflow: "rank_angle",
      unit,
      nextSuggestedActions: score >= 10 ? ["produzieren", "redaktionsplan"] : ["rank_batch", "produzieren"],
      resources: { angle_id: a.angle_id, score, rang: a.ranking_rang, batch_key: a.batch_key },
    }),
  });
}

/**
 * Zeigt das vollständige Ranking aller Angles eines Batches (nach Rang sortiert).
 */
async function showRanking(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "rank_batch" });
  }
  if (!args.batch_key) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "batch_key ist Pflichtfeld für rank_batch.", { action: "rank_batch" });
  }
  const angles = await dbListAngleRanking(config, {
    batch_key: args.batch_key,
    unit_id: unit,
    min_score: args.min_score || null,
    limit: args.limit || 50,
  });
  const rows = (angles || []).map(function(a) {
    const score = a.ranking_score;
    const indicator = score >= 10 ? "🟢" : score >= 7 ? "🟡" : score ? "🔴" : "⚪";
    const rang = a.ranking_rang ? "#" + a.ranking_rang : "—";
    const scoreStr = score != null ? score + "/12" : "—";
    return "<tr><td style=\"padding:4px 8px;font-weight:bold;\">" + rang + "</td><td><code>" + escapeHtml(a.angle_id) + "</code></td><td>" + scoreStr + "</td><td>" + (a.r_zielgruppe || "—") + "</td><td>" + (a.r_viscale_fit || "—") + "</td><td>" + (a.r_schaerfe || "—") + "</td><td>" + (a.r_timing || "—") + "</td><td>" + indicator + "</td><td style=\"max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;\">" + escapeHtml(a.angle || "") + "</td></tr>";
  });
  const ranked = (angles || []).filter(a => a.ranking_score != null).length;
  return successResponse({
    data: {
      operation: "rank_batch",
      unit,
      batch_key: args.batch_key,
      angles: angles || [],
      total: (angles || []).length,
      ranked,
      unranked: (angles || []).length - ranked,
    },
    message: `✅ Ranking · Batch "${args.batch_key}" · ${(angles || []).length} Angles · ${ranked} bewertet.`,
    html: `<div><h3>Batch-Ranking · ${escapeHtml(args.batch_key)}</h3><table style="width:100%;border-collapse:collapse;font-size:12px;"><tr style="background:#f0f0f0;"><th style="padding:4px 8px;">Rang</th><th>ID</th><th>Score</th><th>ZG</th><th>VF</th><th>S</th><th>T</th><th></th><th>Angle</th></tr>${rows.join("")}</table><p style="color:#888;font-size:0.85em;margin-top:8px;">ZG=Zielgruppe · VF=viscale-Fit · S=Schärfe · T=Timing · 🟢≥10 🟡7-9 🔴≤6</p></div>`,
    context: buildHandoffContext({
      workflow: "rank_batch",
      unit,
      nextSuggestedActions: ["produzieren", "rank_angle", "redaktionsplan"],
      resources: { batch_key: args.batch_key, top_angle: angles?.[0]?.angle_id || null },
    }),
  });
}

function suggestBkTopicsFromSpec(unit = DEFAULT_UNIT) {
  const rules = getUnitRules(unit);
  return rules.bk?.topics || [];
}

/**
 * Speichert einen Angle MIT ausführlichem Kontext (Faktbasis, PDF-Analyse, Hooks)
 * als Content-Item in der DB. DB-Pflicht — kein Drive-, Sheet- oder Datei-Write.
 * Verwendet für: "speichere den PDF-Inhalt als Angle/Quelle in der Content-System-DB".
 */
async function saveAngleSource(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(
      ERROR_CODES.CONFIGURATION_ERROR,
      "DB_URL nicht konfiguriert. angle_source_save erfordert eine DB-Verbindung. Bitte DB_URL im Skill-Setup hinterlegen — es wird keine Datei geschrieben.",
      { action: "angle_source_save", unit }
    );
  }
  const summary = args.notes || (args.angle ? String(args.angle).split(/\s+/).slice(0, 12).join(" ") : "");
  const dbItem = await dbCreateContentItem(config, {
    unit_id: unit,
    typ: "angle",
    angle_id: args.angle_id || null,
    icp: args.icp || null,
    pain_cluster: args.pain_cluster || null,
    statement_type: args.statement_type || null,
    format: args.format || "factbase",
    title: args.title || args.angle || "Angle + Faktbasis",
    owner: args.owner || "system",
    live_date: null,
    content: typeof args.content === "string" ? args.content : JSON.stringify(args.content || {}, null, 2),
    source: args.source || "angle_source_save",
    notes: summary,
    status: "fertig",
  });
  if (!dbItem) {
    throw new SkillError(ERROR_CODES.STORAGE_ERROR, "Angle-Kontext konnte nicht in der DB gespeichert werden.", { action: "angle_source_save", unit });
  }
  return successResponse({
    data: { operation: "angle_source_save", unit, item_id: dbItem.item_id, angle: args.angle || null, icp: args.icp || null, typ: "angle", status: dbItem.status, source: args.source || "angle_source_save", db: true },
    message: `✅ Angle + Kontext in DB gespeichert: ${dbItem.item_id}`,
    html: `<div><h3>Angle + Kontext gespeichert (DB)</h3><p><b>ID:</b> <code>${escapeHtml(dbItem.item_id)}</code></p><p><b>Unit:</b> ${escapeHtml(unit)}</p><p><b>ICP:</b> ${escapeHtml(args.icp || "—")}</p><p style="color:#888;font-size:0.85em;">Nur DB — keine Datei geschrieben. Abrufbar via <code>list_content</code> oder <code>overview</code>.</p></div>`,
    context: buildHandoffContext({ workflow: "angle_source_save", icp: args.icp || null, unit, nextSuggestedActions: ["produzieren", "redaktionsplan", "list_content"], resources: { item_id: dbItem.item_id, source: args.source || "angle_source_save" } }),
  });
}

async function ensureBkAssets(config, unit) {
  const resolved = resolveUnitFrom(config, unit);
  progress(config.__runtime, "📁 Prüfe BK-Backlog im Content System Sheet ...");
  const roots = await getContentSystemDocs(config, resolved);
  assertUnitDriveConfigured(resolved);

  // BK-Backlog ist ein Typ im Content System Sheet, kein separates Doc
  const sheet = await driveData(config, "readSheet", { sheetId: roots.contentPlanSheetId });
  const rows = sheet.values || sheet.rows || [];
  const headers = rows[0] || [];
  const typeIdx = headers.indexOf("Typ");
  const statusIdx = headers.indexOf("Status");

  const hasBkEntries = rows.slice(1).some((row) => String(row[typeIdx] || "").trim() === "bk_backlog");

  // Wenn keine BK-Einträge existieren, initialen Eintrag anlegen
  if (!hasBkEntries) {
    const rules = getUnitRules(resolved);
    const topics = rules.bk?.topics || [];
    for (const topic of topics.slice(0, 3)) {
      await driveData(config, "appendRow", {
        sheetId: roots.contentPlanSheetId,
        values: [
          `BK-${new Date().toISOString().slice(0, 10).replace(/-/g, "")}-${String(Date.now()).slice(-4)}`,
          new Date().toISOString().slice(0, 10),
          "bk_backlog",
          "💡",
          "",
          "",
          rules.defaultIcp || "B2B-1",
          "",
          "",
          "newsletter_bk",
          topic,
          rules.defaultOwner,
          "",
          "",
          "",
          "Support-Fragen, Delivery-Best-Practice",
          "Initialer BK-Backlog-Eintrag",
        ],
      });
    }
  }

  return { sheetId: roots.contentPlanSheetId, folderId: roots.outputFolderId };
}

async function showBkBacklog(config, unit) {
  const resolved = resolveUnitFrom(config, unit);
  const suggestions = suggestBkTopicsFromSpec(resolved);
  // BK ist eine Lifecycle-Phase, kein ICP — Zielgruppe bleibt das Segment-Default der Unit
  const bkIcp = getUnitRules(resolved).defaultIcp || "B2B-1";

  // DB-First
  if (isDbConfigured(config)) {
    progress(config.__runtime, "📬 Lade BK-Backlog aus DB ...");
    let items = await dbListContentItems(config, { unit_id: resolved, typ: "bk_backlog", limit: 30 }) || [];
    // Falls leer: initiale Seed-Einträge anlegen
    if (!items.length && suggestions.length) {
      progress(config.__runtime, "🌱 Lege initiale BK-Backlog-Einträge in DB an ...");
      for (const topic of suggestions.slice(0, 3)) {
        await dbCreateContentItem(config, {
          unit_id: resolved, typ: "bk_backlog", icp: bkIcp, format: "newsletter_bk",
          title: topic, source: "Support-Fragen, Delivery-Best-Practice",
          notes: "Initialer BK-Backlog-Eintrag",
        });
      }
      items = await dbListContentItems(config, { unit_id: resolved, typ: "bk_backlog", limit: 30 }) || [];
    }
    const bkEntries = items.map((i) => ({
      item_id: i.item_id, status: i.status,
      topic: i.title || i.input || "—",
      planned_date: i.live_date || "", sources: i.source || "",
    }));
    return successResponse({
      data: { operation: "bk_backlog", unit: resolved, bk_entries: bkEntries, total_entries: bkEntries.length, topic_suggestions: suggestions, db: true },
      message: "✅ BK-Backlog aus DB geladen.",
      html: `<div><h3>BK-Backlog (DB)</h3><p>${bkEntries.length} Einträge</p>${toBulletHtml(bkEntries.map((e) => `${e.status} ${e.topic}`))}</div>`,
      context: buildHandoffContext({ workflow: "bk_newsletter", icp: bkIcp, unit: resolved, nextSuggestedActions: ["newsletter_bk"] }),
    });
  }

  // Fallback: Drive Sheet
  progress(config.__runtime, "📬 Lade BK-Backlog aus Content System Sheet (Drive) ...");
  const roots = await getContentSystemDocs(config, resolved);
  assertUnitDriveConfigured(resolved);

  const sheet = await driveData(config, "readSheet", { sheetId: roots.contentPlanSheetId });
  const rows = sheet.values || sheet.rows || [];
  const headers = rows[0] || [];
  const typeIdx = headers.indexOf("Typ");
  const statusIdx = headers.indexOf("Status");
  const titleIdx = headers.indexOf("Titel");
  const dateIdx = headers.indexOf("Live-Datum");
  const sourceIdx = headers.indexOf("Quelle");

  const bkEntries = rows.slice(1)
    .filter((row) => String(row[typeIdx] || "").trim() === "bk_backlog")
    .map((row) => ({
      id: row[0],
      date: row[1],
      status: String(row[statusIdx] || "").trim(),
      topic: String(row[titleIdx] || "").trim(),
      planned_date: String(row[dateIdx] || "").trim(),
      sources: String(row[sourceIdx] || "").trim(),
    }));

  return successResponse({
    data: {
      operation: "bk_backlog",
      unit: resolved,
      sheet_id: roots.contentPlanSheetId,
      bk_entries: bkEntries,
      total_entries: bkEntries.length,
      topic_suggestions: suggestions,
      db: false,
    },
    message: "✅ BK-Backlog geladen (Drive-Fallback).",
    html: `<div><h3>BK-Backlog</h3><p>${bkEntries.length} Einträge</p>${toBulletHtml(bkEntries.map((e) => `${e.status} ${e.topic}`))}</div>`,
    context: buildHandoffContext({ workflow: "bk_newsletter", icp: bkIcp, unit: resolved, nextSuggestedActions: ["newsletter_bk"] }),
  });
}

async function draftBkNewsletter(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  progress(config.__runtime, "📰 Erstelle BK-Newsletter-Draft ...");

  const rules = getUnitRules(unit);
  // BK ist eine Lifecycle-Phase, kein ICP — Zielgruppe bleibt das Segment-Default der Unit
  const bkIcp = rules.defaultIcp || "B2B-1";

  const strategyCtx = await strategy.getStrategyContext(config, unit);

  if (!args.topic) {
    return successResponse({
      data: {
        operation: "newsletter_bk_topic_suggestions",
        unit,
        topic_suggestions: suggestBkTopicsFromSpec(unit),
      },
      message: "✅ Kein Thema gesetzt. Hier sind BK-Themenvorschläge.",
      html: `<div><h3>BK-Themenvorschläge</h3>${toBulletHtml(suggestBkTopicsFromSpec(unit))}</div>`,
      context: buildHandoffContext({ workflow: "newsletter_bk", icp: bkIcp, unit, nextSuggestedActions: ["newsletter_bk"] }),
    });
  }

  const newsletter = buildBkNewsletter(args, strategyCtx);
  const sendDate = new Date().toISOString().slice(0, 10);
  const fullContent = [
    `Betreff: ${newsletter.subject}`,
    `Preheader: ${newsletter.preheader}`,
    "",
    newsletter.body,
    "",
    `Versanddatum geplant: ${sendDate}`,
    `Quellen: ${(args.sources || []).join(", ") || "noch ergänzen"}`,
    "KPI-Slot: [leer]",
  ].join("\n");

  // DB-First: Draft als Content-Item in DB speichern
  if (isDbConfigured(config)) {
    const dbItem = await dbCreateContentItem(config, {
      unit_id: unit,
      typ: "newsletter_bk",
      icp: bkIcp,
      format: "newsletter_bk",
      title: newsletter.topic,
      owner: rules.defaultOwner,
      live_date: sendDate,
      content: fullContent,
      source: (args.sources || []).join(", ") || null,
      notes: "BK-Newsletter Draft — export_to_drive bei Freigabe",
      status: "in_produktion",
    });
    if (dbItem) {
      progress(config.__runtime, `💾 Newsletter-Draft in DB: ${dbItem.item_id}`);

      // Medien-Briefing für Header-Bild
      const mediaBriefings = media.buildMediaBriefing({ angle: newsletter.topic, icp: bkIcp }, "newsletter_bk", strategyCtx);

      return successResponse({
        data: {
          operation: "newsletter_bk_draft",
          unit,
          item_id: dbItem.item_id,
          topic: newsletter.topic,
          subject: newsletter.subject,
          preheader: newsletter.preheader,
          body: newsletter.body,
          full_content: fullContent,
          db: true,
          export_hint: `Zum Drive-Export: action=export_to_drive, item_id=${dbItem.item_id}`,
          media_briefings: mediaBriefings,
        },
        message: `✅ BK-Newsletter-Draft in DB gespeichert${mediaBriefings.length ? ` + ${mediaBriefings.length} Medien-Briefing(s)` : ""}.`,
        html: `<div><h3>${escapeHtml(newsletter.topic)}</h3><p><b>Betreff:</b> ${escapeHtml(newsletter.subject)}</p><p><b>DB-ID:</b> ${escapeHtml(dbItem.item_id)}</p><p style="color:#888;font-size:0.85em;">Zum Drive-Export: action=export_to_drive, item_id=${escapeHtml(dbItem.item_id)}</p>${mediaBriefings.length ? `<p style="color:#888;">📸 ${mediaBriefings.length} Medien-Briefing(s) → <code>media_briefing</code> → <code>media_generieren</code></p>` : ""}</div>`,
        context: buildHandoffContext({
          workflow: "newsletter_bk",
          icp: bkIcp,
          unit,
          nextSuggestedActions: mediaBriefings.length
            ? ["media_briefing", "export_to_drive", "bk_newsletter", "redaktionsplan"]
            : ["export_to_drive", "bk_newsletter", "redaktionsplan"],
          resources: { item_id: dbItem.item_id, media_briefings: mediaBriefings },
        }),
      });
    }
  }

  // Fallback: Drive Doc anlegen (wenn keine DB oder DB-Write fehlgeschlagen)
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);
  const shortTitle = newsletter.topic.split(/\s+/).slice(0, 4).join(" ");
  const name = `BK-Newsletter ${sendDate} ${shortTitle}`;

  const created = await driveData(config, "createDoc", { parentId: roots.outputFolderId, name });
  const docId = created.id || created.fileId || created.docId;
  if (!docId) throw new SkillError(ERROR_CODES.EXTERNAL_API_ERROR, "Drive createDoc lieferte keine Doc-ID für BK-Newsletter.");
  await driveData(config, "writeDoc", { docId, content: fullContent });
  await driveData(config, "appendRow", {
    sheetId: roots.contentPlanSheetId,
    values: [`CONT-${sendDate.replace(/-/g, "")}-${String(Date.now()).slice(-4)}`, sendDate, "newsletter_bk", "✅", "", "", bkIcp, "", "", "newsletter_bk", newsletter.topic, rules.defaultOwner, sendDate, docId, created.url || "", (args.sources || []).join(", ") || "Quelle offen", "BK-Newsletter produziert"],
  });

  return successResponse({
    data: {
      operation: "newsletter_bk_draft",
      unit,
      newsletter_doc_id: docId,
      newsletter_doc_url: created.url || null,
      topic: newsletter.topic,
      subject: newsletter.subject,
      preheader: newsletter.preheader,
      body: newsletter.body,
      db: false,
    },
    message: "✅ BK-Newsletter-Draft erstellt (Drive-Fallback).",
    html: `<div><h3>${escapeHtml(newsletter.topic)}</h3><p><b>Betreff:</b> ${escapeHtml(newsletter.subject)}</p></div>`,
    context: buildHandoffContext({
      workflow: "newsletter_bk",
      icp: bkIcp,
      unit,
      nextSuggestedActions: ["bk_newsletter", "redaktionsplan"],
      resources: { newsletter_doc_id: docId, newsletter_doc_url: created.url || null },
    }),
  });
}

async function exportToDrive(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!args.item_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "item_id ist für export_to_drive Pflicht.");

  const dbItem = await dbGetContentItem(config, { item_id: args.item_id });
  if (!dbItem) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Content-Item ${args.item_id} nicht gefunden.`);
  if (!dbItem.content) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Content-Item ${args.item_id} hat keinen Inhalt zum Exportieren.`);

  // Zugehörige Medien laden
  const mediaItems = await dbListMedia(config, { item_id: args.item_id, limit: 20 }) || [];
  const mediaUrls = mediaItems.filter((m) => m.url).map((m) => `${m.media_type}: ${m.url}`);

  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);

  const sendDate = new Date().toISOString().slice(0, 10);
  const name = args.name || `${dbItem.format || dbItem.typ} ${sendDate} ${(dbItem.title || "").split(/\s+/).slice(0, 4).join(" ")}`.trim();

  // Content mit Media-URLs anreichern
  let exportContent = dbItem.content;
  if (mediaUrls.length) {
    exportContent += `\n\n---\n## Medien\n\n${mediaUrls.join("\n")}`;
  }

  const created = await driveData(config, "createDoc", { parentId: roots.outputFolderId, name });
  const docId = created.id || created.fileId || created.docId;
  if (!docId) throw new SkillError(ERROR_CODES.EXTERNAL_API_ERROR, "Drive createDoc lieferte keine Doc-ID.");
  await driveData(config, "writeDoc", { docId, content: exportContent });

  // DB-Item mit Drive-Referenz aktualisieren + Status → fertig
  await dbUpdateContentItem(config, {
    item_id: args.item_id,
    output_doc_id: docId,
    output_url: created.url || null,
    status: "fertig",
  });

  return successResponse({
    data: { operation: "export_to_drive", item_id: args.item_id, doc_id: docId, doc_url: created.url || null, name, media_count: mediaUrls.length, media_urls: mediaUrls },
    message: `✅ Content-Item ${args.item_id} nach Drive exportiert${mediaUrls.length ? ` + ${mediaUrls.length} Medien-Referenzen` : ""}.`,
    html: `<div><h3>Drive-Export</h3><p><b>ID:</b> ${escapeHtml(args.item_id)}</p><p><b>Doc:</b> <a href="${escapeHtml(created.url || "#")}">${escapeHtml(name)}</a></p></div>`,
    context: buildHandoffContext({ workflow: "export_to_drive", unit, nextSuggestedActions: ["bk_newsletter", "redaktionsplan"] }),
  });
}

async function listContentDb(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert. list_content erfordert eine DB-Verbindung.", { action: "list_content" });
  }
  const items = await dbListContentItems(config, {
    unit_id: unit,
    status: args.status || null,
    typ: args.typ || null,
    format: args.format || null,
    limit: args.limit || 20,
  });
  const rules = getUnitRules(unit);
  return successResponse({
    data: { operation: "list_content", unit, items: items || [], total: (items || []).length },
    message: `✅ ${(items || []).length} Content-Items geladen.`,
    html: `<div><h3>Content Items · ${escapeHtml(unit)}</h3>${toBulletHtml((items || []).slice(0, 10).map((i) => `${i.item_id} · ${i.status} · ${i.typ} · ${i.title || i.input?.slice(0, 40) || "—"}`))}</div>`,
    context: buildHandoffContext({ workflow: "list_content", unit, nextSuggestedActions: ["produzieren", "update_content"] }),
  });
}

async function updateContentDb(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!args.item_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "item_id ist Pflicht für update_content.");
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "update_content" });
  }
  const updated = await dbUpdateContentItem(config, {
    item_id: args.item_id,
    status: args.status,
    title: args.title,
    owner: args.owner,
    live_date: args.live_date,
    content: args.content,
    output_doc_id: args.output_doc_id,
    output_url: args.output_url,
    notes: args.notes,
    angle_id: args.angle_id,
  });
  return successResponse({
    data: { operation: "update_content", item_id: args.item_id, updated },
    message: `✅ Content-Item ${args.item_id} aktualisiert.`,
    html: `<div><h3>Content Item aktualisiert</h3><p><b>ID:</b> ${escapeHtml(args.item_id)}</p><p><b>Status:</b> ${escapeHtml(updated?.status || "—")}</p></div>`,
    context: buildHandoffContext({ workflow: "update_content", unit, nextSuggestedActions: ["list_content"] }),
  });
}

async function migrateContentFromDrive(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL ist Pflicht für migrate_content.", { action: "migrate_content" });
  }
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);
  const results = { angles_imported: 0, items_imported: 0, errors: 0 };

  // 1. Angle Library Sheet → content_angles
  if (roots.angleLibrarySheetId) {
    progress(config.__runtime, "📥 Migriere Angle Library Sheet → DB ...");
    try {
      const sheet = await driveData(config, "readSheet", { sheetId: roots.angleLibrarySheetId });
      const rows = sheet.values || sheet.rows || [];
      const headers = rows[0] || [];
      const dataRows = rows.slice(1).filter((r) => r.some(Boolean));
      const hIdx = (n) => headers.indexOf(n);
      for (const row of dataRows) {
        try {
          const angle = String(row[hIdx("Angle")] || "").trim();
          if (!angle) continue;
          await dbCreateAngle(config, {
            unit_id: unit,
            angle,
            icp: String(row[hIdx("ICP")] || "").trim() || null,
            pain_cluster: String(row[hIdx("Pain-Cluster")] || "").trim() || null,
            statement_type: String(row[hIdx("Statement-Typ")] || "").trim() || null,
            source: String(row[hIdx("Quelle")] || "intern").trim() || "intern",
          });
          results.angles_imported += 1;
        } catch (_) { results.errors += 1; }
      }
    } catch (err) { progress(config.__runtime, `⚠️ Angle-Migration: ${err.message}`); }
  }

  // 2. Content Plan Sheet → content_items
  if (roots.contentPlanSheetId) {
    progress(config.__runtime, "📥 Migriere Content Plan Sheet → DB ...");
    try {
      const sheet = await driveData(config, "readSheet", { sheetId: roots.contentPlanSheetId });
      const rows = sheet.values || sheet.rows || [];
      const headers = rows[0] || [];
      const dataRows = rows.slice(1).filter((r) => r.some(Boolean));
      const hIdx = (n) => headers.indexOf(n);
      for (const row of dataRows) {
        try {
          const typ = String(row[hIdx("Typ")] || "idee").trim() || "idee";
          const input = String(row[hIdx("Input")] || "").trim();
          const title = String(row[hIdx("Titel")] || "").trim() || null;
          if (!input && !title) continue;
          await dbCreateContentItem(config, {
            unit_id: unit,
            typ,
            input: input || null,
            icp: String(row[hIdx("ICP")] || "").trim() || null,
            pain_cluster: String(row[hIdx("Pain-Cluster")] || "").trim() || null,
            statement_type: String(row[hIdx("Statement-Typ")] || "").trim() || null,
            format: String(row[hIdx("Format")] || "").trim() || null,
            title,
            owner: String(row[hIdx("Owner")] || "").trim() || null,
            live_date: String(row[hIdx("Live-Datum")] || "").trim() || null,
            output_url: String(row[hIdx("Output-URL")] || "").trim() || null,
            source: String(row[hIdx("Quelle")] || "Migration").trim() || "Migration",
            notes: String(row[hIdx("Notizen")] || "").trim() || null,
          });
          results.items_imported += 1;
        } catch (_) { results.errors += 1; }
      }
    } catch (err) { progress(config.__runtime, `⚠️ Item-Migration: ${err.message}`); }
  }

  return successResponse({
    data: { operation: "migrate_content", unit, ...results },
    message: `✅ Migration abgeschlossen: ${results.angles_imported} Angles, ${results.items_imported} Items, ${results.errors} Fehler.`,
    html: `<div><h3>Migration</h3><p>Angles: ${results.angles_imported} · Items: ${results.items_imported} · Fehler: ${results.errors}</p></div>`,
    context: buildHandoffContext({ workflow: "migrate_content", unit, nextSuggestedActions: ["list_content"] }),
  });
}

async function contentOverview(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);

  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "overview benötigt eine DB-Verbindung (DB_URL). Für Drive-only-Setups bitte redaktionsplan show nutzen.", { action: "overview" });
  }

  progress(config.__runtime, `📊 Lade Content-Übersicht für ${unit} ...`);

  const [allItems, allAngles, strategyCtx, allMedia] = await Promise.all([
    dbListContentItems(config, { unit_id: unit, limit: 500 }) || [],
    dbListAngles(config, { unit_id: unit, limit: 200 }) || [],
    strategy.getStrategyContext(config, unit),
    dbListMedia(config, { unit_id: unit, limit: 1000 }) || [],
  ]);

  // Media-Statistiken
  const mediaStats = { total: allMedia.length, by_type: {}, by_status: {} };
  for (const m of allMedia) {
    mediaStats.by_type[m.media_type] = (mediaStats.by_type[m.media_type] || 0) + 1;
    mediaStats.by_status[m.status] = (mediaStats.by_status[m.status] || 0) + 1;
  }

  // Status-Verteilung
  const byStatus = {};
  for (const item of allItems) {
    byStatus[item.status] = (byStatus[item.status] || 0) + 1;
  }

  // Format-Verteilung
  const byFormat = {};
  for (const item of allItems) {
    const f = item.format || item.typ || "unbekannt";
    byFormat[f] = (byFormat[f] || 0) + 1;
  }

  // ICP-Verteilung
  const byIcp = {};
  for (const item of allItems) {
    if (item.icp) byIcp[item.icp] = (byIcp[item.icp] || 0) + 1;
  }

  // Nächste Live-Dates (fertig/in_produktion mit live_date)
  const upcoming = allItems
    .filter((i) => i.live_date && ["fertig", "in_produktion"].includes(i.status))
    .sort((a, b) => new Date(a.live_date) - new Date(b.live_date))
    .slice(0, 5)
    .map((i) => ({
      item_id: i.item_id,
      title: i.title || (i.input || "").slice(0, 50) || "—",
      format: i.format || i.typ,
      icp: i.icp,
      live_date: i.live_date,
      status: i.status,
    }));

  // Offene Ideen (top 5 nach Datum)
  const openIdeas = allItems
    .filter((i) => i.status === "idee")
    .slice(0, 5)
    .map((i) => ({
      item_id: i.item_id,
      input: (i.input || i.title || "").slice(0, 80),
      icp: i.icp,
      created_at: i.created_at,
    }));

  // Aktive Angles
  const activeAngles = (allAngles || [])
    .filter((a) => a.status === "aktiv")
    .slice(0, 5)
    .map((a) => ({
      angle_id: a.angle_id,
      angle: a.angle.slice(0, 80),
      icp: a.icp,
      pain_cluster: a.pain_cluster,
    }));

  // In Produktion
  const inProduktion = allItems
    .filter((i) => i.status === "in_produktion")
    .slice(0, 5)
    .map((i) => ({
      item_id: i.item_id,
      title: i.title || (i.input || "").slice(0, 60),
      format: i.format || i.typ,
      icp: i.icp,
    }));

  const weekLabel = isoWeekLabel();

  const statusRows = Object.entries(byStatus)
    .sort((a, b) => b[1] - a[1])
    .map(([s, n]) => `<tr><td>${escapeHtml(s)}</td><td><b>${n}</b></td></tr>`)
    .join("");

  const html = [
    `<div><h2>Content System Übersicht · ${escapeHtml(unit)} · ${escapeHtml(weekLabel)}</h2>`,
    `<h3>Status-Verteilung (${allItems.length} Items gesamt · ${(allAngles || []).length} Angles)</h3>`,
    `<table border="1" cellpadding="4" style="border-collapse:collapse">${statusRows}</table>`,
    upcoming.length ? `<h3>Nächste Live-Dates</h3>${toBulletHtml(upcoming.map((i) => `${i.live_date} · ${i.format || "—"} · ${i.title} (${i.icp || "—"})`))}` : "",
    inProduktion.length ? `<h3>In Produktion (${inProduktion.length})</h3>${toBulletHtml(inProduktion.map((i) => `${i.item_id} · ${i.format || "—"} · ${i.title}`))}` : "",
    openIdeas.length ? `<h3>Offene Ideen (${byStatus["idee"] || 0} gesamt)</h3>${toBulletHtml(openIdeas.map((i) => `${i.item_id} · ${i.input}`))}` : "",
    activeAngles.length ? `<h3>Aktive Angles (${(allAngles || []).filter((a) => a.status === "aktiv").length} gesamt)</h3>${toBulletHtml(activeAngles.map((a) => `${a.angle_id} · ${a.icp || "—"} · ${a.angle}`))}` : "",
    "</div>",
  ].join("");

  const strategyKeys = strategyCtx ? Object.keys(strategyCtx) : [];
  if (strategyKeys.length) {
    html += `<div style="margin-top:12px"><h3>📐 Content-Strategie (${strategyKeys.length} Keys)</h3>${toBulletHtml(strategyKeys.map((k) => `${k} · v${escapeHtml(String(strategyCtx[k]?.version || "—"))}`))}</div>`;
  }

  if (mediaStats.total > 0) {
    const mediaRows = Object.entries(mediaStats.by_type)
      .map(([t, n]) => `${escapeHtml(t)}: ${n}`).join(" · ");
    html += `<div style="margin-top:12px"><h3>📸 Medien (${mediaStats.total} gesamt)</h3><p>${mediaRows}</p><p>Status: ${Object.entries(mediaStats.by_status).map(([s, n]) => `${escapeHtml(s)}: ${n}`).join(" · ")}</p></div>`;
  }

  return successResponse({
    data: {
      operation: "overview",
      unit,
      week_label: weekLabel,
      total_items: allItems.length,
      total_angles: (allAngles || []).length,
      by_status: byStatus,
      by_format: byFormat,
      by_icp: byIcp,
      upcoming,
      open_ideas: openIdeas,
      open_ideas_total: byStatus["idee"] || 0,
      in_produktion: inProduktion,
      active_angles: activeAngles,
      strategy_keys: strategyKeys,
      media: mediaStats,
    },
    message: `✅ Content-Übersicht für ${unit}: ${allItems.length} Items, ${(allAngles || []).length} Angles.`,
    html,
    context: buildHandoffContext({
      workflow: "overview",
      unit,
      nextSuggestedActions: ["idee", "produzieren", "list_content", "redaktionsplan"],
    }),
  });
}

async function strategySession(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "strategy_session" });
  }

  const existing = await strategy.listStrategiesAction(config, { unit });
  const existingKeys = (existing.data?.keys || []).map((k) => k.strategy_key);

  const missingKeys = strategy.STRATEGY_KEYS.filter((k) => !existingKeys.includes(k));

  const html = [
    `<div><h2>📐 Content-Strategie-Session · ${escapeHtml(unit)}</h2>`,
    existingKeys.length > 0
      ? `<h3>✅ Bereits definiert (${existingKeys.length}/${strategy.STRATEGY_KEYS.length})</h3>${toBulletHtml(existingKeys.map((k) => `${strategy.STRATEGY_LABELS[k] || k} (${k})`))}`
      : "<p>Noch keine Strategie-Definitionen für diese Unit.</p>",
    missingKeys.length > 0
      ? `<h3>📋 Noch zu definieren (${missingKeys.length})</h3>${toBulletHtml(missingKeys.map((k) => `${strategy.STRATEGY_LABELS[k] || k} (${k})`))}`
      : "<p>🎉 Alle Strategie-Keys sind definiert!</p>",
    "<h3>📦 Empfohlene Reihenfolge</h3>",
    "<ol>",
    "<li><b>brand_voice</b> — Wer spricht? Wie? Was nie?</li>",
    "<li><b>channel_rules</b> — Welche Kanäle? Welche Frequenz?</li>",
    "<li><b>icp_channel_mapping</b> — Welcher ICP auf welchem Kanal?</li>",
    "<li><b>media_logic</b> — Wann Text / Bild / Video / Karussell?</li>",
    "<li><b>editorial_rhythm</b> — Wochentage, Owner, Prozess</li>",
    "<li><b>content_strategy</b> — Zusammenfassung als Referenz-Dokument</li>",
    "</ol>",
    "<p><b>Next:</b> <code>strategy_speichern</code> mit <code>strategy_key</code> und <code>content</code> (JSON)</p>",
    "</div>",
  ].join("");

  return successResponse({
    data: {
      operation: "strategy_session",
      unit,
      existing_keys: existingKeys,
      missing_keys: missingKeys,
      total_keys: strategy.STRATEGY_KEYS.length,
      progress: Math.round((existingKeys.length / strategy.STRATEGY_KEYS.length) * 100),
    },
    message: `📐 Strategie-Session: ${existingKeys.length}/${strategy.STRATEGY_KEYS.length} Keys definiert.`,
    html,
    context: {
      workflow: "strategy_session",
      unit,
      nextSuggestedActions: missingKeys.length ? ["strategy_speichern"] : ["idee", "produzieren", "redaktionsplan"],
    },
  });
}

async function runAction(runtime, args, config = {}) {
  config.__runtime = runtime;
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  switch (args.action) {
    case "debug":
      return successResponse({
        data: {
          operation: "debug",
          unit,
          version: runtime?.config?.version || "unknown",
          modules: getModuleVersions("content-system"),
          configuration: {
            drive_script_url_configured: Boolean(config.driveScriptUrl),
            drive_token_configured: Boolean(config.driveToken),
            viminds_products_root_configured: Boolean(config.vimindsProductsRootId),
            content_system_root_configured: Boolean(config.contentSystemRootId),
          },
        },
        message: `✅ Content-System v${runtime?.config?.version || "unknown"} bereit (Unit: ${unit}).`,
        context: { operation: { type: "debug", completed: true }, unit },
      });
    case "start_content":
      return startContentAction(runtime, args);
    case "idee":
      return summarizeIdea(args, config);
    case "produzieren":
      return produceAsset(args, config);
    case "redaktionsplan":
      return args.mode === "show" ? showEditorialPlan(config, unit) : addEditorialEntry(config, args);
    case "harvest":
      return harvestInbox(config, args);
    case "monitoring":
      return monitoring(args);
    case "angle_speichern":
      return saveAngle(config, args);
    case "angle_source_save":
      return saveAngleSource(config, args);
    case "source_save":
      return saveSource(config, args);
    case "source_list":
      return listSources(config, args);
    case "source_angles":
      return listAnglesBySource(config, args);
    case "rank_angle":
      return rankAngleAction(config, args);
    case "rank_batch":
      return showRanking(config, args);
    case "newsletter_bk":
      return args.mode === "draft" ? draftBkNewsletter(config, args) : showBkBacklog(config, unit);
    case "bk_newsletter":
      return showBkBacklog(config, unit);
    case "export_to_drive":
      return exportToDrive(config, args);
    case "overview":
      return contentOverview(config, args);
    case "list_content":
      return listContentDb(config, args);
    case "update_content":
      return updateContentDb(config, args);
    case "migrate_content":
      return migrateContentFromDrive(config, args);
    case "foundation_index":
      {
        const roots = await getRoots(config, unit);
        return successResponse({
          data: { operation: "foundation_index", unit, foundation: await readFoundationIndex(config, unit) },
          message: "✅ Foundation-Index geladen.",
          html: `<div><h3>Foundation-Index</h3><p>Doc-ID: <code>${escapeHtml(roots.foundationIndexDocId)}</code></p></div>`,
          context: buildHandoffContext({ workflow: "foundation_index", unit, nextSuggestedActions: ["idee", "produzieren"] }),
        });
      }
    case "migrate":
      return migrateLegacyDocs(config, args);
    case "strategy_speichern":
      return strategy.saveStrategy(config, args);
    case "strategy_abrufen":
      return strategy.getStrategyValue(config, args);
    case "strategy_list":
      return strategy.listStrategiesAction(config, args);
    case "strategy_loeschen":
      return strategy.deleteStrategyDb(config, args);
    case "strategy_session":
      return strategySession(config, args);
    case "media_briefing":
      return media.createMediaBriefing(config, args);
    case "media_list":
      return media.listMediaForItem(config, args);
    case "media_update":
      return media.updateMediaItem(config, args);
    case "media_delete":
      return media.deleteMediaItem(config, args);
    case "media_generieren":
      return media.buildGenerationBriefing(config, args);
    default:
      throw new Error(`Unsupported action: ${args.action}`);
  }
}

// Migriert alte Google Docs (Ideas Inbox, Redaktionsplan) in die neuen Sheets.
async function migrateLegacyDocs(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  const rules = getUnitRules(unit);
  const roots = await getContentSystemDocs(config, unit);
  assertUnitDriveConfigured(unit);

  const results = {
    ideas_migrated: 0,
    ideas_errors: 0,
    plan_entries_migrated: 0,
    plan_errors: 0,
  };

  // 1. Ideas Inbox migrieren
  if (args.source === "ideas" || args.source === "all") {
    progress(config.__runtime, "📥 Migriere Ideas Inbox ...");
    const legacyDocId = UNITS[unit]?.legacyIdeasInboxDocId || UNITS[DEFAULT_UNIT].legacyIdeasInboxDocId;
    if (!legacyDocId) {
      throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, `Keine Legacy Ideas Inbox Doc-ID für Unit '${unit}' hinterlegt.`, { unit });
    }

    const doc = await driveData(config, "readDoc", { docId: legacyDocId });
    const content = doc.content || doc.text || "";
    const lines = content.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

    for (const line of lines) {
      try {
        // Format A (Legacy): [Status] | Typ | ICP | Text | Owner | Datum | Link
        const matchA = line.match(/^\[(.*?)\]\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)$/);
        // Format B (Inbox): ANGLE N - Text | ICP: B2B-X | Cluster N | Statement | Status: ...
        const matchB = line.match(/^ANGLE\s+\d+\s*[-–]\s*(.+?)\s*\|\s*ICP:\s*([^\|]+?)\s*\|\s*Cluster\s*(\d+)\s*\|\s*([^\|]+?)\s*(?:\|\s*Status:\s*(.+))?$/i);

        if (matchA) {
          const [, status, type, icp, text, owner, date, link] = matchA;
          const values = [
            `MIG-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            new Date().toISOString().slice(0, 10),
            type || "idee",
            status || "💡",
            text || "",
            "",
            icp || "B2B-1",
            "",
            "",
            "",
            text?.slice(0, 50) || "",
            owner || rules.defaultOwner,
            date || "",
            "",
            link || "",
            "Migration aus Ideas Inbox",
            line.slice(0, 100),
          ];
          await driveData(config, "appendRow", { sheetId: roots.contentPlanSheetId, values });
          results.ideas_migrated += 1;
        } else if (matchB) {
          const [, text, icp, clusterNum, statementType, status] = matchB;
          const clusterKey = `cluster_${clusterNum.trim()}`;
          const icpClean = icp.trim().split("/")[0].trim(); // ersten ICP nehmen wenn mehrere
          const values = [
            `MIG-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            new Date().toISOString().slice(0, 10),
            "idee",
            status?.trim() === "Eingang" ? "💡" : (status?.trim() || "💡"),
            text.trim(),
            "",
            icpClean || "B2B-1",
            clusterKey,
            statementType?.trim() || "",
            "",
            text.trim().slice(0, 50),
            rules.defaultOwner,
            "",
            "",
            "",
            "Migration aus Ideas Inbox",
            line.slice(0, 100),
          ];
          await driveData(config, "appendRow", { sheetId: roots.contentPlanSheetId, values });
          results.ideas_migrated += 1;
        }
      } catch (err) {
        results.ideas_errors += 1;
      }
    }
  }

  // 2. Redaktionsplan migrieren
  if (args.source === "plan" || args.source === "all") {
    progress(config.__runtime, "📥 Migriere Redaktionsplan ...");
    const legacyDocId = UNITS[unit]?.legacyEditorialPlanDocId || UNITS[DEFAULT_UNIT].legacyEditorialPlanDocId;
    if (!legacyDocId) {
      throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, `Keine Legacy Redaktionsplan Doc-ID für Unit '${unit}' hinterlegt.`, { unit });
    }

    const doc = await driveData(config, "readDoc", { docId: legacyDocId });
    const content = doc.content || doc.text || "";
    const lines = content.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

    for (const line of lines) {
      try {
        // Format: [Status] | Format | ICP | Angle | Owner | Live-Datum | Link
        const match = line.match(/^\[(.*?)\]\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)\s*\|\s*(.*?)$/);
        if (match) {
          const [, status, format, icp, angle, owner, liveDate, link] = match;
          const values = [
            `MIG-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
            new Date().toISOString().slice(0, 10),
            format || "linkedin_post",
            status || "📋",
            "",
            "",
            icp || "B2B-1",
            "",
            "",
            format || "linkedin_post",
            angle?.slice(0, 50) || "",
            owner || rules.defaultOwner,
            liveDate || "",
            "",
            link || "",
            "Migration aus Redaktionsplan",
            line.slice(0, 100),
          ];
          await driveData(config, "appendRow", { sheetId: roots.contentPlanSheetId, values });
          results.plan_entries_migrated += 1;
        }
      } catch (err) {
        results.plan_errors += 1;
      }
    }
  }

  return successResponse({
    data: {
      operation: "migrate",
      unit,
      ...results,
    },
    message: `✅ Migration abgeschlossen: ${results.ideas_migrated} Ideen, ${results.plan_entries_migrated} Plan-Einträge.`,
    html: `<div><h3>Migration</h3><p>Ideen: ${results.ideas_migrated} migriert, ${results.ideas_errors} Fehler</p><p>Plan: ${results.plan_entries_migrated} migriert, ${results.plan_errors} Fehler</p></div>`,
    context: buildHandoffContext({ workflow: "migrate", unit, nextSuggestedActions: ["redaktionsplan", "angle_speichern"] }),
  });
}

module.exports = { runAction };
