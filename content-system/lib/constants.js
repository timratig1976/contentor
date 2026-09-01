"use strict";

const { SkillError, ERROR_CODES } = require("../../shared/errors");

// ---------------------------------------------------------------------------
// Unit-basierte Konfiguration — jede Unit hat eigene Ordner-IDs UND ein
// eigenes Regelwerk (rules). Verhindert Ueberschreiben zwischen Units.
// ---------------------------------------------------------------------------

// Vereinfachte Struktur: ein contentSystemRootId pro Unit,
// darunter flache Ordner (angles, plan, output).
// Alte Einzel-IDs bleiben als Fallback fuer viscale bestehen.
const CONTENT_SYSTEM_SUBFOLDERS = Object.freeze({
  angles: "angles",
  plan: "plan",
  output: "output",
});

// Sheet-Namen und Spalten fuer die vereinfachte Struktur.
const CONTENT_SYSTEM_SHEETS = Object.freeze({
  ANGLE_LIBRARY: {
    name: "Angle Library",
    columns: ["Angle-ID", "Datum", "Angle", "ICP", "Pain-Cluster", "Statement-Typ", "Quelle", "Status", "Verwendet-in-IDs"],
  },
  CONTENT_PLAN: {
    name: "Content System",
    columns: ["ID", "Datum", "Typ", "Status", "Input", "Angle-ID", "ICP", "Pain-Cluster", "Statement-Typ", "Format", "Titel", "Owner", "Live-Datum", "Output-Doc-ID", "Output-URL", "Quelle", "Notizen"],
  },
});

const UNITS = Object.freeze({
  viscale: {
    name: "viscale",
    // Vereinfachte Root: Content System Ordner unter VIM Products > viscale > Marketing & Sales
    contentSystemRootId: "1t3T3xt75z4KSohzwMTepXJZxZuYewX6i",
    // Legacy-Fallbacks: bestehende verstreute Ordner-IDs (bleiben bis Migration).
    marketingSalesRootId: "17LTgdewH58QDq-24G6TWM0Hs5guLhFJa",
    personasFolderId: "1sHRREjiyFObIlH7WbqaJOl5tThPXxFKX",
    ideasInboxFolderId: "1-4_tyuyt3AJ5bi2yo0GX1USH72xE57bD",
    ideasInboxDocId: "1xgThGkr4qlWYbC7rC5cEBDgC5aRUW2wiRTRqzpUkvuU",
    angleLibraryFolderId: "18JHSVaz4gfweM5Omh3gmhVJxv1xyLQAj",
    editorialPlanFolderId: "1BIkJGPnYp1omUujn1nSQOb2WZD75eSFa",
    editorialPlanDocId: "1hnxegldcEpH5gfw_sK8_YJbGhD-FPjTYRvkP0fcv9dk",
    foundationIndexDocId: "1IPZxoBk_Ei-WhwftHJd8ysmkIFenV4qHMJx2_WfX5NE",
    bkBacklogName: "Newsletter-BK-Backlog",
    bkFolderName: "Newsletter-BK",
    // Legacy-Doc-IDs fuer Migration (alte Docs, die in neue Sheets migriert werden)
    legacyIdeasInboxDocId: "1xgThGkr4qlWYbC7rC5cEBDgC5aRUW2wiRTRqzpUkvuU",
    legacyEditorialPlanDocId: "1hnxegldcEpH5gfw_sK8_YJbGhD-FPjTYRvkP0fcv9dk",
    rules: {
      icpKeys: ["B2B-1", "B2B-2", "B2B-3", "B2C", "UNI", "BK"],
      hashtags: ["#viscale", "#Vertriebssystem", "#HubSpot", "#Mittelstand"],
      signoff: "das viscale-Team",
      editorialPlanTitle: "Redaktionsplan · viscale",
      defaultOwner: "viscale",
      clusters: [
        { key: "cluster_1", code: "E3-02", name: "Vertrieb hängt an Personen", match: /person|vertriebsleiter|kopf/ },
        { key: "cluster_2", code: "E3-01", name: "Blindflug im Forecast", match: /forecast|pipeline|signen|unterschreiben/ },
        { key: "cluster_3", code: "E3-03", name: "Leads versickern unbemerkt", match: /lead|versick|nachverfolg/ },
        { key: "cluster_4", code: "E3-05", name: "Wachstum wird teurer statt effizienter", match: /teuer|effizienz|cac|wachs/ },
        { key: "cluster_5", code: "E3-06", name: "Datenchaos & fehlende Datenhygiene", match: /daten|property|chaos|hygiene/ },
        { key: "cluster_6", code: "E1-02", name: "KI-Druck ohne Fundament", match: /ki|ai|breeze/ },
      ],
      defaultClusterKey: "cluster_2",
      icpGuesser: [
        { icp: "BK", match: /bestand|renewal|adoption|retainer/ },
        { icp: "UNI", match: /uni|hochschule|forschung/ },
        { icp: "B2C", match: /b2c|consumer|ecommerce/ },
        { icp: "B2B-2", match: /forecast|pipeline|head of sales|sales/ },
        { icp: "B2B-3", match: /crm|chaos|struktur|hubspot/ },
      ],
      defaultIcp: "B2B-1",
      harvestCandidatePattern: /crm|forecast|lead|hubspot|pipeline/i,
      forbiddenPatterns: [
        /!/g,
        /\bbeste[nrsm]?\b/i,
        /\beinzigartig(?:e|er|es|en)?\b/i,
        /\brevolution[aä]r(?:e|er|es|en)?\b/i,
        /dein vertrieb kann mehr/i,
        /verbessere deinen vertrieb/i,
      ],
      genericBkPatterns: [
        /wir freuen uns/i,
        /spannend(?:e|er|es|en)?/i,
        /interessant(?:e|er|es|en)?/i,
        /bucht jetzt/i,
        /demo anfragen/i,
      ],
      toneLabel: "viscale-Tonalitätsregeln",
      monitoring: {
        gaps: [
          "B2B-2 Forecast-Beweislast wird im Markt meist nur oberflächlich adressiert.",
          "Niemand besetzt aktuell die Kombination aus HubSpot-Mechanismus und Führungslogik konsequent.",
        ],
        gapLine: "Lücken für viscale herausarbeiten",
        toneGapHint: "Danach Lücken gegen viscale-Tonalität spiegeln.",
      },
      bk: {
        defaultTopic: "Property-Hygiene · 3 Fehler die wir oft sehen",
        preheader: "Konkrete Einordnung aus Delivery und ein nächster Schritt in HubSpot.",
        mainCta: "Prüft diese Woche eine aktive Pipeline auf fehlende Pflichtfelder und antwortet auf diese Mail, wenn ihr die Logik gemeinsam durchgehen wollt.",
        pathCheck: /Settings → Objects → Deals → Pipelines/,
        topics: [
          "Revenue Hub · was sich für euch ändert",
          "Property-Hygiene · 3 Fehler die wir oft sehen",
          "Renewal-Pipeline · der unterschätzte Hebel",
        ],
      },
    },
  },
  vitalents: {
    name: "vitalents",
    // Vereinfachte Root: Content System Ordner unter VIM Products > vitalents > Marketing & Sales
    contentSystemRootId: "1LWkUz8oVVUC9xRyswq5twxA21l2MpNCA",
    // Legacy-Fallbacks (aktuell nicht vorhanden — werden durch contentSystemRootId ersetzt).
    marketingSalesRootId: "1AMWUGOHSgaoIkkU9rVFZqE6FJY1b08kj",
    personasFolderId: null,
    ideasInboxFolderId: null,
    ideasInboxDocId: null,
    angleLibraryFolderId: null,
    editorialPlanFolderId: null,
    editorialPlanDocId: null,
    foundationIndexDocId: null,
    bkBacklogName: "Newsletter-BK-Backlog",
    bkFolderName: "Newsletter-BK",
    rules: {
      icpKeys: ["B2B-1", "B2B-2", "B2B-3", "B2C", "UNI", "BK"],
      hashtags: ["#vitalents", "#Recruiting", "#Klinik", "#Pflege"],
      signoff: "das vitalents-Team",
      editorialPlanTitle: "Redaktionsplan · vitalents",
      defaultOwner: "vitalents",
      clusters: [
        { key: "cluster_1", code: "V1-01", name: "Fachkräftemangel in Klinik und Pflege", match: /fachkr|pflege|klinik|personal/ },
        { key: "cluster_2", code: "V1-02", name: "Recruiting-Prozesse ohne System", match: /recruit|bewerb|prozess|system/ },
        { key: "cluster_3", code: "V1-03", name: "Kandidaten springen im Prozess ab", match: /kandidat|absprung|abbruch/ },
        { key: "cluster_4", code: "V1-04", name: "Employer Branding ohne Beweislast", match: /employer|brand|image/ },
        { key: "cluster_5", code: "V1-05", name: "Datenchaos im Bewerbermanagement", match: /daten|ats|chaos|hygiene/ },
      ],
      defaultClusterKey: "cluster_1",
      icpGuesser: [
        { icp: "BK", match: /bestand|renewal|adoption|retainer/ },
        { icp: "B2B-2", match: /klinik|pflege|recruiting|personal/ },
      ],
      defaultIcp: "B2B-1",
      harvestCandidatePattern: /recruiting|pflege|klinik|kandidat|fachkr/i,
      forbiddenPatterns: [
        /!/g,
        /\bbeste[nrsm]?\b/i,
        /\beinzigartig(?:e|er|es|en)?\b/i,
        /\brevolution[aä]r(?:e|er|es|en)?\b/i,
      ],
      genericBkPatterns: [
        /wir freuen uns/i,
        /spannend(?:e|er|es|en)?/i,
        /interessant(?:e|er|es|en)?/i,
        /bucht jetzt/i,
        /demo anfragen/i,
      ],
      toneLabel: "vitalents-Tonalitätsregeln",
      monitoring: {
        gaps: [
          "Recruiting-Prozesse werden im Markt selten mit Systemlogik adressiert.",
          "Die Kombination aus Pflege-Fachkräftemangel und Prozesssteuerung ist kaum besetzt.",
        ],
        gapLine: "Lücken für vitalents herausarbeiten",
        toneGapHint: "Danach Lücken gegen vitalents-Tonalität spiegeln.",
      },
      bk: {
        defaultTopic: "Bewerber-Hygiene · 3 Fehler die wir oft sehen",
        preheader: "Konkrete Einordnung aus Delivery und ein nächster Schritt im System.",
        mainCta: "Prüft diese Woche einen aktiven Recruiting-Prozess und antwortet auf diese Mail, wenn ihr die Logik gemeinsam durchgehen wollt.",
        pathCheck: null,
        topics: [
          "Kandidaten-Kommunikation · wo Prozesse brechen",
          "Bewerber-Hygiene · 3 Fehler die wir oft sehen",
          "Onboarding-Pipeline · der unterschätzte Hebel",
        ],
      },
    },
  },
});

const UNIT_KEYS = Object.freeze(Object.keys(UNITS));
const DEFAULT_UNIT = "viscale";

// Felder, die pro Unit eine gueltige Drive-Konfiguration brauchen.
// Mit contentSystemRootId reicht eine ID; die Unterordner werden on-demand angelegt.
// Ohne contentSystemRootId werden die Legacy-Einzel-IDs geprueft.
const UNIT_DRIVE_FIELDS = Object.freeze([
  "marketingSalesRootId",
  "personasFolderId",
  "ideasInboxFolderId",
  "ideasInboxDocId",
  "angleLibraryFolderId",
  "editorialPlanFolderId",
  "editorialPlanDocId",
  "foundationIndexDocId",
]);

function resolveUnit(value) {
  const key = String(value || "").trim().toLowerCase() || DEFAULT_UNIT;
  const unit = UNITS[key];
  if (!unit) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Unbekannte Unit '${value}'. Verfügbar: ${UNIT_KEYS.join(", ")}.`, {
      field: "unit",
      received: value,
      available_units: UNIT_KEYS,
    });
  }
  return key;
}

function getUnitConfig(unit) {
  return UNITS[resolveUnit(unit)];
}

function getUnitRules(unit) {
  return getUnitConfig(unit).rules;
}

// Wirft einen klaren Fehler, wenn fuer die Unit keine Drive-IDs hinterlegt sind.
// Wenn contentSystemRootId gesetzt ist, gilt die Unit als konfiguriert —
// Unterordner und Docs werden on-demand angelegt.
function assertUnitDriveConfigured(unit) {
  const key = resolveUnit(unit);
  const cfg = UNITS[key];
  if (cfg.contentSystemRootId) return; // vereinfachte Struktur: eine ID genuegt
  const missing = UNIT_DRIVE_FIELDS.filter((field) => !cfg[field]);
  if (missing.length) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, `Unit '${key}' hat keine vollständige Drive-Konfiguration. Fehlende IDs: ${missing.join(", ")}. Bitte in skills/content-system/lib/constants.js hinterlegen.`, {
      unit: key,
      missing_fields: missing,
    });
  }
}

// Fallback fuer die Default-Unit (bisherige viscale-Konfiguration).
const DEFAULT_ROOTS = Object.freeze({
  marketingSalesRootId: UNITS.viscale.marketingSalesRootId,
  personasFolderId: UNITS.viscale.personasFolderId,
  ideasInboxFolderId: UNITS.viscale.ideasInboxFolderId,
  ideasInboxDocId: UNITS.viscale.ideasInboxDocId,
  angleLibraryFolderId: UNITS.viscale.angleLibraryFolderId,
  editorialPlanFolderId: UNITS.viscale.editorialPlanFolderId,
  editorialPlanDocId: UNITS.viscale.editorialPlanDocId,
  foundationIndexDocId: UNITS.viscale.foundationIndexDocId,
});

// Unit-unabhaengige, geteilte Konstanten.
const STATEMENT_TYPES = Object.freeze(["Direkt", "Drastisch", "Bedrohlich", "Humorvoll", "Sarkastisch", "Gain"]);
const PRODUCTION_FORMATS = Object.freeze(["linkedin_post", "ad_copy", "newsletter_acquisition", "landing_page_headlines", "newsletter_bk"]);
const COMMAND_KEYS = Object.freeze(["idee", "produzieren", "redaktionsplan", "harvest", "monitoring", "angle_speichern", "newsletter_bk", "bk_newsletter"]);
const SOURCE_TYPES = Object.freeze(["wettbewerber", "markt", "kundenzitat", "intern", "hubspot", "branche", "beobachtung", "url", "screenshot"]);
const CHANNEL_KEYS = Object.freeze(["linkedin_organic", "paid_social", "newsletter", "landing_page"]);
const BK_BACKLOG_NAME = "Newsletter-BK-Backlog";
const BK_FOLDER_NAME = "Newsletter-BK";

module.exports = {
  UNITS,
  UNIT_KEYS,
  DEFAULT_UNIT,
  CONTENT_SYSTEM_SUBFOLDERS,
  CONTENT_SYSTEM_SHEETS,
  resolveUnit,
  getUnitConfig,
  getUnitRules,
  assertUnitDriveConfigured,
  DEFAULT_ROOTS,
  STATEMENT_TYPES,
  PRODUCTION_FORMATS,
  COMMAND_KEYS,
  SOURCE_TYPES,
  CHANNEL_KEYS,
  BK_BACKLOG_NAME,
  BK_FOLDER_NAME,
};
