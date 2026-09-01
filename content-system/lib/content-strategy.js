"use strict";

/**
 * Content Strategy Modul — zentrale Strategie-Definition pro Unit.
 *
 * Keys:
 *   brand_voice          — Tonalität, Personality, No-Gos, Signature Elements
 *   channel_rules        — Kanäle, Frequenz, Formate, ICPs
 *   icp_channel_mapping  — ICP → Kanal-Mapping
 *   media_logic          — Wann Text / Bild / Video / Karussell
 *   editorial_rhythm     — Wochentage, Owner, Review-Prozess
 *   content_strategy     — Haupt-Strategie-Dokument (Aggregat)
 *   post_templates       — Templates + Regeln pro Format (linkedin_post, ad_copy, ...)
 *   content_personas     — Personen-Posts: Themen, Kadenz, Abgrenzung pro Persona
 */

const { successResponse } = require("../../shared/response");
const { escapeHtml } = require("../../shared/rendering");
const { SkillError, ERROR_CODES } = require("../../shared/errors");
const { DEFAULT_UNIT, getUnitRules } = require("./constants");
const {
  isDbConfigured,
  dbSetStrategy,
  dbGetStrategy,
  dbListStrategies,
  dbDeleteStrategy,
} = require("./db-client");

const STRATEGY_KEYS = Object.freeze([
  "brand_voice",
  "channel_rules",
  "icp_channel_mapping",
  "media_logic",
  "editorial_rhythm",
  "content_strategy",
  "post_templates",
  "content_personas",
]);

const STRATEGY_LABELS = Object.freeze({
  brand_voice: "Brand Voice",
  channel_rules: "Kanal-Regelwerk",
  icp_channel_mapping: "ICP → Kanal Mapping",
  media_logic: "Medien-Logik",
  editorial_rhythm: "Redaktions-Rhythmus",
  content_strategy: "Content-Strategie",
  post_templates: "Post-Templates & Regeln",
  content_personas: "Personen-Posts & Themen",
});

/**
 * Speichert einen Strategy-Key für eine Unit.
 */
async function saveStrategy(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "strategy_speichern" });
  }
  const strategyKey = args.strategy_key;
  if (!STRATEGY_KEYS.includes(strategyKey)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR,
      `Ungültiger strategy_key: "${strategyKey}". Gültig: ${STRATEGY_KEYS.join(", ")}`,
      { strategy_key: strategyKey });
  }
  // Content aus args.content JSON parsen oder als String nehmen
  let content = args.content;
  if (typeof content === "string") {
    try { content = JSON.parse(content); } catch (_) { /* keep as string */ }
  }
  if (!content || (typeof content === "object" && Object.keys(content).length === 0)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "content ist leer oder kein gültiges JSON-Objekt.", { strategy_key: strategyKey });
  }

  const result = await dbSetStrategy(config, { unit_id: unit, strategy_key: strategyKey, content });
  return successResponse({
    data: { operation: "strategy_speichern", unit, strategy_key: strategyKey, saved: result },
    message: `✅ ${STRATEGY_LABELS[strategyKey] || strategyKey} für ${unit} gespeichert.`,
    html: `<div><h3>${STRATEGY_LABELS[strategyKey] || strategyKey} gespeichert</h3><p><b>Unit:</b> ${escapeHtml(unit)}</p><p><b>Key:</b> ${escapeHtml(strategyKey)}</p></div>`,
    context: { workflow: "strategy_speichern", unit, strategy_key: strategyKey, nextSuggestedActions: ["strategy_abrufen", "strategy_list"] },
  });
}

/**
 * Ruft einen Strategy-Key ab.
 */
async function getStrategyValue(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "strategy_abrufen" });
  }
  const strategyKey = args.strategy_key;
  if (!STRATEGY_KEYS.includes(strategyKey)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR,
      `Ungültiger strategy_key: "${strategyKey}". Gültig: ${STRATEGY_KEYS.join(", ")}`,
      { strategy_key: strategyKey });
  }

  const result = await dbGetStrategy(config, { unit_id: unit, strategy_key: strategyKey });
  if (!result) {
    return successResponse({
      data: { operation: "strategy_abrufen", unit, strategy_key: strategyKey, found: false },
      message: `ℹ️ Keine ${STRATEGY_LABELS[strategyKey] || strategyKey} für ${unit} hinterlegt.`,
      html: `<div><h3>${STRATEGY_LABELS[strategyKey] || strategyKey}</h3><p>Noch keine Definition für <b>${escapeHtml(unit)}</b> hinterlegt.</p></div>`,
      context: { workflow: "strategy_abrufen", unit, strategy_key: strategyKey, nextSuggestedActions: ["strategy_speichern"] },
    });
  }
  return successResponse({
    data: { operation: "strategy_abrufen", unit, strategy_key: strategyKey, content: result.content, version: result.version, updated_at: result.updated_at },
    message: `✅ ${STRATEGY_LABELS[strategyKey] || strategyKey} für ${unit} geladen (v${result.version}).`,
    html: `<div><h3>${STRATEGY_LABELS[strategyKey] || strategyKey} · ${escapeHtml(unit)}</h3><pre><code>${escapeHtml(JSON.stringify(result.content, null, 2))}</code></pre></div>`,
    context: { workflow: "strategy_abrufen", unit, strategy_key: strategyKey },
  });
}

/**
 * Listet alle Strategy-Keys einer Unit.
 */
async function listStrategiesAction(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "strategy_list" });
  }

  const results = await dbListStrategies(config, { unit_id: unit });
  const keys = (results || []).map((r) => ({
    strategy_key: r.strategy_key,
    label: STRATEGY_LABELS[r.strategy_key] || r.strategy_key,
    version: r.version,
    updated_at: r.updated_at,
  }));

  return successResponse({
    data: { operation: "strategy_list", unit, keys, total: keys.length },
    message: `✅ ${keys.length} Strategie-Definitionen für ${unit} geladen.`,
    html: `<div><h3>Content-Strategie · ${escapeHtml(unit)}</h3>
      <ul>${keys.map((k) => `<li><b>${escapeHtml(k.label)}</b> (${escapeHtml(k.strategy_key)}) · v${k.version} · ${escapeHtml(String(k.updated_at))}</li>`).join("")}</ul>
      </div>`,
    context: { workflow: "strategy_list", unit, keys, nextSuggestedActions: ["strategy_abrufen", "strategy_speichern"] },
  });
}

/**
 * Löscht einen Strategy-Key.
 */
async function deleteStrategyDb(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "strategy_loeschen" });
  }
  const strategyKey = args.strategy_key;
  if (!STRATEGY_KEYS.includes(strategyKey)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR,
      `Ungültiger strategy_key: "${strategyKey}".`,
      { strategy_key: strategyKey });
  }

  await dbDeleteStrategy(config, { unit_id: unit, strategy_key: strategyKey });
  return successResponse({
    data: { operation: "strategy_loeschen", unit, strategy_key: strategyKey, deleted: true },
    message: `🗑️ ${STRATEGY_LABELS[strategyKey] || strategyKey} für ${unit} gelöscht.`,
    html: `<div><h3>Strategie gelöscht</h3><p><b>Unit:</b> ${escapeHtml(unit)} · <b>Key:</b> ${escapeHtml(strategyKey)}</p></div>`,
    context: { workflow: "strategy_loeschen", unit, strategy_key: strategyKey, nextSuggestedActions: ["strategy_list"] },
  });
}

/**
 * Baut einen Aggregierten Strategy-Context für die Produktion.
 * Wird von `idee`, `produzieren`, `redaktionsplan` etc. genutzt.
 */
async function getStrategyContext(config, unit) {
  const resolvedUnit = unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) return null;
  try {
    const results = await dbListStrategies(config, { unit_id: resolvedUnit });
    if (!results || results.length === 0) return null;
    const ctx = {};
    for (const row of results) {
      ctx[row.strategy_key] = row.content;
    }
    return ctx;
  } catch (err) {
    console.error("[content-system strategy] getStrategyContext:", err.message);
    return null;
  }
}

/**
 * Lädt Persona-Definitionen aus der DB und matched gegen owner/icp.
 * 
 * content_personas JSON-Struktur:
 * {
 *   "personas": [
 *     {
 *       "id": "tim",
 *       "name": "Tim Ratig",
 *       "topics": ["CRM", "HubSpot", "Vertriebssystem"],
 *       "icps": ["B2B-2", "B2B-3"],
 *       "cadence": "1x/Woche",
 *       "tone_notes": "Direkt, fordernd, beweislastig",
 *       "forbidden_topics": ["Recruiting", "Pflege"]
 *     }
 *   ]
 * }
 */
function getPersonaContext(strategyCtx, owner, icp) {
  const personas = strategyCtx?.content_personas?.personas || [];
  if (!personas.length) return null;

  // Match by owner first, then by ICP
  let match = personas.find((p) => p.id === owner || p.name === owner);
  if (!match && icp) {
    match = personas.find((p) => (p.icps || []).includes(icp));
  }
  return match || null;
}

/**
 * Prüft ob ein Thema für eine bestimmte Persona verboten ist.
 */
function isTopicForbidden(persona, topic) {
  if (!persona?.forbidden_topics?.length) return false;
  const lower = String(topic || "").toLowerCase();
  return persona.forbidden_topics.some((ft) => lower.includes(ft.toLowerCase()));
}

/**
 * Gibt Persona-spezifische Tonalitäts-Hinweise zurück.
 */
function getPersonaTone(persona, fallbackBrandVoice) {
  if (!persona) return fallbackBrandVoice?.personality || "Direkt";
  return persona.tone_notes || fallbackBrandVoice?.personality || "Direkt";
}

module.exports = {
  STRATEGY_KEYS,
  STRATEGY_LABELS,
  saveStrategy,
  getStrategyValue,
  listStrategiesAction,
  deleteStrategyDb,
  getStrategyContext,
  getPersonaContext,
  isTopicForbidden,
  getPersonaTone,
};