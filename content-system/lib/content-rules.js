"use strict";

const { SkillError, ERROR_CODES } = require("../../shared/errors");
const { getUnitRules, DEFAULT_UNIT } = require("./constants");

// ---------------------------------------------------------------------------
// Unit-abhaengige Regelwerke.
// Konstanten (constants.js) sind Fallback. Strategy-Context aus DB hat Vorrang.
// ---------------------------------------------------------------------------

/**
 * Merged Brand Voice Rules: DB-first, constants.js als Fallback.
 */
function getBrandVoiceRules(unit = DEFAULT_UNIT, strategyCtx = null) {
  const constants = getUnitRules(unit);
  const db = strategyCtx?.brand_voice || {};

  return {
    personality: db.personality || constants.toneLabel || "Direkt",
    always: db.always || [],
    never: db.never || [],
    signature_elements: db.signature_elements || [],
    // Fallback: constants.forbiddenPatterns als "never" Patterns
    forbiddenPatterns: constants.forbiddenPatterns || [],
    genericBkPatterns: constants.genericBkPatterns || [],
    toneLabel: db.personality || constants.toneLabel || "Tonalitätsregeln",
  };
}

/**
 * Merged Post Template Rules: DB-first, hartcodierte Defaults als Fallback.
 */
function getPostTemplateRules(format, unit = DEFAULT_UNIT, strategyCtx = null) {
  const db = strategyCtx?.post_templates || {};
  const formatRules = db[format] || db.templates?.[format] || {};

  // Defaults pro Format – fallen zurück wenn kein DB-Eintrag
  const defaults = {
    linkedin_post: {
      structure: ["hook", "problem", "cost", "solution", "question", "hashtags"],
      min_words: 120,
      max_words: 250,
      require_blank_line_after_hook: true,
      require_closing_question: true,
      require_hashtags: true,
      forbidden: ["!", "beste", "besten", "einzigartig", "revolutionär"],
      notes: "LinkedIn-Post: Hook → Problem → Cost → Solution → Frage → Hashtags",
    },
    ad_copy: {
      structure: ["headline", "pain", "solution", "cta"],
      min_words: 30,
      max_words: 150,
      require_blank_line_after_hook: false,
      require_closing_question: false,
      require_hashtags: false,
      forbidden: ["!", "kostenlos", "gratis"],
      notes: "Ad Copy: kurze Varianten mit klarem CTA",
    },
    newsletter_acquisition: {
      structure: ["subject", "preheader", "body", "cta", "ps"],
      min_words: 200,
      max_words: 500,
      require_blank_line_after_hook: false,
      require_closing_question: false,
      require_hashtags: false,
      forbidden: [],
      notes: "Akquise-Newsletter: 200-500 Wörter, ein CTA",
    },
    newsletter_bk: {
      structure: ["subject", "preheader", "body", "cta"],
      min_words: 350,
      max_words: 500,
      require_blank_line_after_hook: false,
      require_closing_question: false,
      require_hashtags: false,
      forbidden: ["wir freuen uns", "spannend", "interessant", "bucht jetzt", "demo anfragen"],
      notes: "BK-Newsletter: 350-500 Wörter, kein Werbe-Ton",
    },
    landing_page_headlines: {
      structure: ["headlines"],
      min_words: 0,
      max_words: 0,
      require_blank_line_after_hook: false,
      require_closing_question: false,
      require_hashtags: false,
      forbidden: [],
      notes: "Landing-Page-Headlines: 5-10 Varianten",
    },
  };

  // Merge: DB überschreibt Defaults
  return {
    ...(defaults[format] || {}),
    ...formatRules,
    // forbidden Patterns mergen: DB + Defaults
    forbidden: [...new Set([
      ...(formatRules.forbidden || []),
      ...(defaults[format]?.forbidden || []),
    ])],
  };
}

function detectInputType(input) {
  const trimmed = String(input || "").trim();
  if (/^https?:\/\//i.test(trimmed)) return "url";
  if (/screenshot|bildschirm|screen/i.test(trimmed)) return "screenshot";
  if (trimmed.includes("“") || trimmed.includes("”") || trimmed.includes('"')) return "kundenzitat";
  return "freitext";
}

function guessSourceType(input, explicitType) {
  if (explicitType) return explicitType;
  const type = detectInputType(input);
  if (type === "url") return "url";
  if (type === "screenshot") return "screenshot";
  if (type === "kundenzitat") return "kundenzitat";
  return "beobachtung";
}

function pickPainCluster(text, unit = DEFAULT_UNIT) {
  const rules = getUnitRules(unit);
  const lower = String(text || "").toLowerCase();
  const hit = (rules.clusters || []).find((cluster) => cluster.match && cluster.match.test(lower));
  if (hit) return hit;
  return (rules.clusters || []).find((cluster) => cluster.key === rules.defaultClusterKey) || rules.clusters?.[0];
}

function pickStatementType({ format, sourceType, input }) {
  const lower = String(input || "").toLowerCase();
  if (format === "landing_page_headlines") return "Gain";
  if (format === "ad_copy") return /roi|zahl|kosten/.test(lower) ? "Drastisch" : "Bedrohlich";
  if (sourceType === "kundenzitat") return "Direkt";
  if (/absurd|lächerlich|iron/.test(lower)) return "Sarkastisch";
  return "Direkt";
}

function guessIcp(input, explicitIcp, unit = DEFAULT_UNIT) {
  if (explicitIcp) return explicitIcp;
  const rules = getUnitRules(unit);
  const lower = String(input || "").toLowerCase();
  const hit = (rules.icpGuesser || []).find((entry) => entry.match && entry.match.test(lower));
  return hit ? hit.icp : rules.defaultIcp;
}

function buildAngleSentence({ input, painCluster, icp, statementType }) {
  const base = String(input || "").trim();
  if (/\d/.test(base)) return base;
  const prefix = statementType === "Bedrohlich"
    ? "Euer Systemproblem kostet euch mehr als der Markt"
    : statementType === "Drastisch"
      ? "Mehr Leads lösen kein Strukturproblem"
      : "Ein CRM ohne Mechanismus simuliert nur Sicherheit";
  return `${prefix} — ${painCluster.name.toLowerCase()} ist für ${icp} kein Tool-, sondern ein Führungsproblem.`;
}

function buildChannelRecommendations(statementType) {
  return {
    linkedin_organic: { suitable: true, reason: `${statementType}-Frame funktioniert als LinkedIn-These mit Beweislast.` },
    paid_social: { suitable: ["Direkt", "Drastisch", "Bedrohlich"].includes(statementType), reason: "Paid braucht klaren Pain-Frame und schnelle Beweislast." },
    newsletter: { suitable: true, reason: "Newsletter kann Mechanismus und Zahlen sauber ausführen." },
    landing_page: { suitable: statementType === "Gain" || statementType === "Direkt", reason: "Landing Pages brauchen klaren Outcome- oder Mechanismus-Frame." },
  };
}

/**
 * Erzwingt Tonalitäts-Regeln. Strategy-Context-first, constants.js als Fallback.
 */
function enforceTone(text, { format, unit = DEFAULT_UNIT, strategyCtx = null } = {}) {
  const brandVoice = getBrandVoiceRules(unit, strategyCtx);
  const template = getPostTemplateRules(format, unit, strategyCtx);

  let output = String(text || "");

  // Brand Voice: "never" Patterns ersetzen
  for (const phrase of (brandVoice.never || [])) {
    const regex = typeof phrase === "string" ? new RegExp(phrase.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "gi") : phrase;
    if (regex instanceof RegExp) output = output.replace(regex, "");
  }

  // Post-Template: forbidden Patterns ersetzen
  for (const phrase of template.forbidden || []) {
    const regex = typeof phrase === "string" ? new RegExp(phrase.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "gi") : phrase;
    if (regex instanceof RegExp) output = output.replace(regex, "");
  }

  // Fallback: constants.forbiddenPatterns
  for (const pattern of (brandVoice.forbiddenPatterns || []).slice(1)) {
    output = output.replace(pattern, "konkret");
  }

  // Ausrufezeichen
  output = output.replace(/!/g, ".");

  // Leerzeile nach Hook (linkedin_post)
  if (template.require_blank_line_after_hook && !/\n\n/.test(output)) {
    const [first, ...rest] = output.split("\n");
    output = [first, "", ...rest].join("\n");
  }

  return output.replace(/\s+/g, " ").replace(/\n{3,}/g, "\n\n").trim();
}

/**
 * Prüft ob der Text verbotene Patterns enthält. Strategy-Context-first.
 */
function assertNoForbiddenTone(text, unit = DEFAULT_UNIT, strategyCtx = null) {
  const brandVoice = getBrandVoiceRules(unit, strategyCtx);
  const raw = String(text || "");

  // Brand Voice "never"
  const brandViolations = (brandVoice.never || []).filter((phrase) => {
    const regex = typeof phrase === "string" ? new RegExp(phrase.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i") : phrase;
    return regex instanceof RegExp && regex.test(raw);
  });
  if (brandViolations.length) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR,
      `Output verletzt Brand Voice Regeln: ${brandViolations.slice(0, 3).map((v) => `"${typeof v === "string" ? v : String(v)}"`).join(", ")}`, {
        unit,
        violated: brandViolations.slice(0, 3),
      });
  }

  // Fallback: constants
  const failed = (brandVoice.forbiddenPatterns || []).filter((pattern) => pattern.test(raw));
  if (failed.length) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Output verletzt die ${brandVoice.toneLabel}.`, {
      unit,
      failed_rules: failed.map((rule) => String(rule)),
    });
  }
}

function containsEmoji(text) {
  return /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(String(text || ""));
}

function countMatches(text, regex) {
  return (String(text || "").match(regex) || []).length;
}

/**
 * Tone-Checks: Strategy-Context-first.
 */
function buildToneChecks(text, { format, unit = DEFAULT_UNIT, strategyCtx = null } = {}) {
  const brandVoice = getBrandVoiceRules(unit, strategyCtx);
  const template = getPostTemplateRules(format, unit, strategyCtx);
  const raw = String(text || "");

  const brandViolations = (brandVoice.never || []).filter((phrase) => {
    const regex = typeof phrase === "string" ? new RegExp(phrase.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i") : phrase;
    return regex instanceof RegExp && regex.test(raw);
  });

  return {
    emoji_free: !containsEmoji(raw),
    exclamation_free: !/!/.test(raw),
    brand_voice_violations: brandViolations.slice(0, 5),
    brand_voice_ok: brandViolations.length === 0,
    template_forbidden_ok: !(template.forbidden || []).some((phrase) => {
      const regex = typeof phrase === "string" ? new RegExp(phrase.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i") : phrase;
      return regex instanceof RegExp && regex.test(raw);
    }),
    word_count: countWords(raw),
    word_count_ok: template.min_words ? countWords(raw) >= template.min_words : true,
    has_blank_line_after_hook: template.require_blank_line_after_hook ? raw.includes("\n\n") : true,
    has_hashtags: template.require_hashtags ? /#\w+/.test(raw) : true,
    has_closing_question: template.require_closing_question ? /\?$/.test(raw.trim()) : true,
  };
}

function countWords(text) {
  return String(text || "").trim().split(/\s+/).filter(Boolean).length;
}

module.exports = {
  getBrandVoiceRules,
  getPostTemplateRules,
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
  containsEmoji,
  countMatches,
  countWords,
};
