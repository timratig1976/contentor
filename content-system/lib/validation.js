"use strict";

const { requireFields, validateEnum, validationError, validateUrl } = require("../../shared/validation");
const { resolveUnit, getUnitRules, STATEMENT_TYPES, PRODUCTION_FORMATS, COMMAND_KEYS, SOURCE_TYPES, CHANNEL_KEYS } = require("./constants");

const ACTIONS = Object.freeze([
  "debug",
  "start_content",
  "idee",
  "produzieren",
  "redaktionsplan",
  "harvest",
  "monitoring",
  "angle_speichern",
  "angle_source_save",
  "source_save",
  "source_list",
  "source_angles",
  "rank_angle",
  "rank_batch",
  "newsletter_bk",
  "bk_newsletter",
  "foundation_index",
  "migrate",
  "overview",
  "list_content",
  "update_content",
  "export_to_drive",
  "migrate_content",
  "strategy_speichern",
  "strategy_abrufen",
  "strategy_list",
  "strategy_loeschen",
  "strategy_session",
  "media_briefing",
  "media_list",
  "media_update",
  "media_delete",
  "media_generieren",
]);

function normalizeString(value, fallback = "") {
  return String(value || fallback).trim() || fallback;
}

function parseJsonLike(value, field) {
  if (value === undefined || value === null || value === "") return undefined;
  if (typeof value === "object") return value;
  if (typeof value !== "string") validationError(`${field} muss ein JSON-String oder Objekt sein.`, { field, received: typeof value });
  try {
    return JSON.parse(value);
  } catch (_) {
    validationError(`${field} enthält ungültiges JSON.`, { field });
  }
}

function parseListLike(value, field) {
  if (value === undefined || value === null || value === "") return [];
  if (Array.isArray(value)) return value;
  if (typeof value === "object") return [value];
  if (typeof value !== "string") validationError(`${field} muss Liste oder String sein.`, { field, received: typeof value });
  const trimmed = value.trim();
  if (!trimmed) return [];
  if (trimmed.startsWith("[") || trimmed.startsWith("{")) {
    const parsed = parseJsonLike(trimmed, field);
    return Array.isArray(parsed) ? parsed : [parsed];
  }
  return trimmed.split(/\r?\n|,/).map((item) => item.trim()).filter(Boolean);
}

function coerceBoolean(value, field) {
  if (value === undefined || value === null || value === "") return false;
  if (typeof value === "boolean") return value;
  if (value === "true") return true;
  if (value === "false") return false;
  validationError(`${field} muss true oder false sein.`, { field, received: value });
}

function validateInput(args = {}) {
  const action = validateEnum(args.action, ACTIONS, "action");
  const normalized = { ...args, action };

  // Unit-Parameter für alle Actions (optional, default: DEFAULT_UNIT aus constants).
  // Wirft VALIDATION_ERROR bei unbekannter Unit.
  normalized.unit = resolveUnit(args.unit);
  const unitRules = getUnitRules(normalized.unit);
  const icpKeys = unitRules.icpKeys;

  if (action === "debug") {
    return normalized;
  }

  if (action === "start_content") {
    normalized.input = args.input ? normalizeString(args.input, "") : undefined;
    normalized.icp = args.icp ? validateEnum(args.icp, icpKeys, "icp") : undefined;
    normalized.intent = args.intent ? normalizeString(args.intent, "") : undefined;
    return normalized;
  }

  if (action === "idee") {
    requireFields(args, ["input"]);
    normalized.input = normalizeString(args.input, "");
    normalized.icp = args.icp ? validateEnum(args.icp, icpKeys, "icp") : undefined;
    normalized.source_type = args.source_type ? validateEnum(args.source_type, SOURCE_TYPES, "source_type") : undefined;
    normalized.source_url = args.source_url ? validateUrl(args.source_url, "source_url") : undefined;
    normalized.statement_type = args.statement_type ? validateEnum(args.statement_type, STATEMENT_TYPES, "statement_type") : undefined;
  }

  if (action === "produzieren") {
    requireFields(args, ["format", "angle"]);
    normalized.format = validateEnum(args.format, PRODUCTION_FORMATS, "format");
    normalized.angle = normalizeString(args.angle, "");
    normalized.icp = args.icp ? validateEnum(args.icp, icpKeys, "icp") : undefined;
    normalized.statement_type = args.statement_type ? validateEnum(args.statement_type, STATEMENT_TYPES, "statement_type") : undefined;
    normalized.pain_cluster = args.pain_cluster ? normalizeString(args.pain_cluster, "") : undefined;
    normalized.metric = args.metric ? normalizeString(args.metric, "") : undefined;
    normalized.mechanism = args.mechanism ? normalizeString(args.mechanism, "") : undefined;
    normalized.owner = normalizeString(args.owner, unitRules.defaultOwner);
    normalized.cta = args.cta ? normalizeString(args.cta, "") : undefined;
    normalized.notes = args.notes ? normalizeString(args.notes, "") : undefined;
    normalized.kpis = parseListLike(args.kpis, "kpis");
    normalized.proofs = parseListLike(args.proofs, "proofs");
  }

  if (action === "redaktionsplan") {
    normalized.mode = normalizeString(args.mode, "show");
    normalized.status = args.status ? normalizeString(args.status, "") : undefined;
    normalized.format = args.format ? validateEnum(args.format, PRODUCTION_FORMATS, "format") : undefined;
    normalized.icp = args.icp ? validateEnum(args.icp, icpKeys, "icp") : undefined;
    normalized.angle_short = args.angle_short ? normalizeString(args.angle_short, "") : undefined;
    normalized.owner = args.owner ? normalizeString(args.owner, unitRules.defaultOwner) : undefined;
    normalized.live_date = args.live_date ? normalizeString(args.live_date, "") : undefined;
    normalized.link = args.link ? validateUrl(args.link, "link") : undefined;
  }

  if (action === "harvest") {
    normalized.limit = Number.isInteger(Number(args.limit)) ? Number(args.limit) : 10;
  }

  if (action === "monitoring") {
    normalized.competitors = parseListLike(args.competitors, "competitors");
    normalized.channels = parseListLike(args.channels, "channels");
  }

  if (action === "angle_speichern") {
    requireFields(args, ["angle", "icp", "pain_cluster", "statement_type"]);
    normalized.angle = normalizeString(args.angle, "");
    // Mehrere ICPs tolerieren (z.B. "B2B-2, B2B-3") — ersten gültigen Wert nehmen
    const icpRaw = String(args.icp || "").split(/[,/]/)[0].trim();
    normalized.icp = validateEnum(icpRaw, icpKeys, "icp");
    normalized.pain_cluster = normalizeString(args.pain_cluster, "");
    normalized.statement_type = validateEnum(args.statement_type, STATEMENT_TYPES, "statement_type");
    normalized.source = normalizeString(args.source, "intern");
    normalized.channels = parseListLike(args.channels, "channels");
    normalized.status = normalizeString(args.status, "Validiert");
    normalized.assets = parseListLike(args.assets, "assets");
    // Neu: Source-Verknüpfung, Batch, Funnel
    normalized.source_id = args.source_id ? normalizeString(args.source_id, "") : undefined;
    normalized.batch_key = args.batch_key ? normalizeString(args.batch_key, "") : undefined;
    normalized.funnel = args.funnel ? normalizeString(args.funnel, "") : undefined;
    normalized.viscale_phase = args.viscale_phase ? normalizeString(args.viscale_phase, "") : undefined;
  }

  if (action === "angle_source_save") {
    // Angle + ausführlicher Kontext (Faktbasis/PDF) direkt in die DB —
    // erfordert eine DB-Verbindung, schreibt KEINE Dateien.
    requireFields(args, ["angle", "content"]);
    normalized.angle = normalizeString(args.angle, "");
    normalized.content = args.content; // String oder JSON-Objekt (Factbase)
    normalized.icp = args.icp ? validateEnum(args.icp, icpKeys, "icp") : undefined;
    normalized.pain_cluster = args.pain_cluster ? normalizeString(args.pain_cluster, "") : undefined;
    normalized.statement_type = args.statement_type ? validateEnum(args.statement_type, STATEMENT_TYPES, "statement_type") : undefined;
    normalized.format = args.format ? normalizeString(args.format, "factbase") : "factbase";
    normalized.title = args.title ? normalizeString(args.title, "") : undefined;
    normalized.owner = args.owner ? normalizeString(args.owner, "") : undefined;
    normalized.source = args.source ? normalizeString(args.source, "angle_source_save") : "angle_source_save";
    normalized.notes = args.notes ? normalizeString(args.notes, "") : undefined;
    normalized.angle_id = args.angle_id ? normalizeString(args.angle_id, "") : undefined;
  }

  if (action === "source_save") {
    requireFields(args, ["title"]);
    normalized.title = normalizeString(args.title, "");
    const SOURCE_TYPES_EXT = ["pdf", "url", "interview", "intern", "research"];
    normalized.type = args.type ? validateEnum(args.type, SOURCE_TYPES_EXT, "type") : "pdf";
    normalized.date = args.date ? normalizeString(args.date, "") : undefined;
    normalized.file_ref = args.file_ref ? normalizeString(args.file_ref, "") : undefined;
    normalized.drive_file_id = args.drive_file_id ? normalizeString(args.drive_file_id, "") : undefined;
    normalized.visibility = args.visibility || "intern";
    normalized.notes = args.notes ? normalizeString(args.notes, "") : undefined;
  }

  if (action === "source_list") {
    normalized.type = args.type ? normalizeString(args.type, "") : undefined;
    normalized.visibility = args.visibility ? normalizeString(args.visibility, "") : undefined;
    normalized.limit = Number.isInteger(Number(args.limit)) ? Number(args.limit) : 30;
  }

  if (action === "source_angles") {
    normalized.source_id = args.source_id ? normalizeString(args.source_id, "") : undefined;
    normalized.batch_key = args.batch_key ? normalizeString(args.batch_key, "") : undefined;
    normalized.limit = Number.isInteger(Number(args.limit)) ? Number(args.limit) : 100;
  }

  if (action === "rank_angle") {
    requireFields(args, ["angle_id", "r_zielgruppe", "r_viscale_fit", "r_schaerfe", "r_timing"]);
    normalized.angle_id = normalizeString(args.angle_id, "");
    for (const field of ["r_zielgruppe", "r_viscale_fit", "r_schaerfe", "r_timing"]) {
      const val = Number(args[field]);
      if (![1, 2, 3].includes(val)) validationError(`${field} muss 1, 2 oder 3 sein.`, { field, received: args[field] });
      normalized[field] = val;
    }
  }

  if (action === "rank_batch") {
    requireFields(args, ["batch_key"]);
    normalized.batch_key = normalizeString(args.batch_key, "");
    normalized.min_score = args.min_score ? Number(args.min_score) : undefined;
    normalized.limit = Number.isInteger(Number(args.limit)) ? Number(args.limit) : 50;
  }

  if (action === "newsletter_bk") {
    normalized.topic = args.topic ? normalizeString(args.topic, "") : undefined;
    normalized.mode = normalizeString(args.mode, normalized.topic ? "draft" : "backlog");
    normalized.sources = parseListLike(args.sources, "sources");
    normalized.phase_scope = args.phase_scope ? normalizeString(args.phase_scope, "") : undefined;
    normalized.main_cta = args.main_cta ? normalizeString(args.main_cta, "") : undefined;
  }

  if (action === "bk_newsletter") {
    normalized.mode = normalizeString(args.mode, "backlog");
  }

  if (action === "foundation_index") {
    normalized.refresh = coerceBoolean(args.refresh, "refresh");
  }

  if (action === "migrate") {
    normalized.source = normalizeString(args.source, "all");
    if (!["all", "ideas", "plan"].includes(normalized.source)) {
      validationError("source muss 'all', 'ideas' oder 'plan' sein.", { field: "source", received: args.source });
    }
  }

  if (action === "strategy_speichern") {
    requireFields(args, ["strategy_key", "content"]);
    normalized.strategy_key = normalizeString(args.strategy_key, "");
    normalized.content = args.content; // JSON-Objekt oder String
  }

  if (action === "strategy_abrufen") {
    requireFields(args, ["strategy_key"]);
    normalized.strategy_key = normalizeString(args.strategy_key, "");
  }

  if (action === "strategy_loeschen") {
    requireFields(args, ["strategy_key"]);
    normalized.strategy_key = normalizeString(args.strategy_key, "");
  }

  if (action === "strategy_session") {
    normalized.interactive = args.interactive !== undefined ? coerceBoolean(args.interactive, "interactive") : true;
    normalized.strategy_key = args.strategy_key ? normalizeString(args.strategy_key, "") : undefined;
  }

  if (action === "media_briefing") {
    requireFields(args, ["item_id", "media_type"]);
    normalized.item_id = normalizeString(args.item_id, "");
    normalized.media_type = normalizeString(args.media_type, "");
    normalized.prompt = args.prompt ? normalizeString(args.prompt, "") : undefined;
    normalized.generation_params = parseJsonLike(args.generation_params, "generation_params");
    normalized.position = args.position !== undefined ? Number(args.position) : 0;
    normalized.notes = args.notes ? normalizeString(args.notes, "") : undefined;
  }

  if (action === "media_list") {
    normalized.item_id = args.item_id ? normalizeString(args.item_id, "") : undefined;
    normalized.media_type = args.media_type ? normalizeString(args.media_type, "") : undefined;
    normalized.status = args.status ? normalizeString(args.status, "") : undefined;
    normalized.limit = Number.isInteger(Number(args.limit)) ? Number(args.limit) : 50;
  }

  if (action === "media_update") {
    requireFields(args, ["media_id"]);
    normalized.media_id = normalizeString(args.media_id, "");
    normalized.status = args.status ? normalizeString(args.status, "") : undefined;
    normalized.url = args.url ? validateUrl(args.url, "url") : undefined;
    normalized.drive_file_id = args.drive_file_id ? normalizeString(args.drive_file_id, "") : undefined;
    normalized.prompt = args.prompt ? normalizeString(args.prompt, "") : undefined;
    normalized.generation_params = parseJsonLike(args.generation_params, "generation_params");
    normalized.notes = args.notes ? normalizeString(args.notes, "") : undefined;
    normalized.position = args.position !== undefined ? Number(args.position) : undefined;
  }

  if (action === "media_delete") {
    requireFields(args, ["media_id"]);
    normalized.media_id = normalizeString(args.media_id, "");
  }

  if (action === "media_generieren") {
    requireFields(args, ["media_id"]);
    normalized.media_id = normalizeString(args.media_id, "");
  }

  if (args.command) {
    normalized.command = validateEnum(args.command, COMMAND_KEYS, "command");
  }

  return normalized;
}

module.exports = { ACTIONS, validateInput };
