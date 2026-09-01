"use strict";

/**
 * Content Media Modul — Medien-Elemente zu Content-Items.
 *
 * Jedes Content-Item kann 0-n Medien-Elemente haben:
 *   image, video, graphic, carousel_slide, ad_creative
 *
 * Lebenszyklus: briefing → generiert → in_drive → live
 */

const { successResponse } = require("../../shared/response");
const { escapeHtml } = require("../../shared/rendering");
const { SkillError, ERROR_CODES } = require("../../shared/errors");
const { DEFAULT_UNIT } = require("./constants");
const {
  isDbConfigured,
  dbCreateMedia,
  dbListMedia,
  dbGetMedia,
  dbUpdateMedia,
  dbDeleteMedia,
} = require("./db-client");

const MEDIA_TYPES = Object.freeze([
  "image",
  "video",
  "graphic",
  "carousel_slide",
  "ad_creative",
]);

const MEDIA_STATUSES = Object.freeze([
  "briefing",
  "generiert",
  "in_drive",
  "live",
  "verworfen",
]);

/**
 * Erstellt ein Medien-Briefing für ein Content-Item.
 * Wird von `produzieren` aus aufgerufen, wenn das Format Medien erfordert.
 */
function buildMediaBriefing(args, format, strategyCtx) {
  const briefings = [];

  const mediaLogic = strategyCtx?.media_logic?.rules || [];

  if (format === "linkedin_post") {
    // LinkedIn Post: 1 Bild optional (Karussell wenn mehrere)
    const rule = mediaLogic.find((r) => r.format === "Bild" || r.format === "image");
    briefings.push({
      media_type: "image",
      position: 0,
      prompt_hint: buildImagePrompt(args, strategyCtx),
      generation_params: {
        style: rule?.style || "Dunkel, reduziert, Brand Colors",
        aspect_ratio: "1.91:1",
        model: "flux-pro",
      },
      notes: "Hero-Image für LinkedIn-Post. Optional — Text-only ist auch möglich.",
    });
  }

  if (format === "ad_copy") {
    // Ad Creative: 1 Bild + optional 1 Video
    briefings.push({
      media_type: "image",
      position: 0,
      prompt_hint: buildImagePrompt(args, strategyCtx),
      generation_params: { aspect_ratio: "1:1", model: "flux-pro" },
      notes: "Ad Creative Image. 1:1 für Meta/LinkedIn Feed.",
    });
  }

  if (format === "newsletter_acquisition" || format === "newsletter_bk") {
    briefings.push({
      media_type: "image",
      position: 0,
      prompt_hint: buildImagePrompt(args, strategyCtx),
      generation_params: { aspect_ratio: "1.91:1", model: "flux-pro" },
      notes: "Header-Bild für Newsletter.",
    });
  }

  return briefings;
}

/**
 * Baut einen Bild-Prompt aus Content-Strategy + Angle.
 */
function buildImagePrompt(args, strategyCtx) {
  const brandVoice = strategyCtx?.brand_voice || {};
  const style = brandVoice.personality || "professionell, clean";
  const angle = args.angle || "";
  const icp = args.icp || "";
  return `Corporate style: ${style}. Context: ${angle}. Target: ${icp}. No text overlay. Dark, muted tones.`;
}

/**
 * Erstellt ein Medien-Briefing in der DB (nur Briefing, noch kein Asset).
 */
async function createMediaBriefing(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "media_briefing" });
  }
  if (!args.item_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "item_id ist Pflicht für media_briefing.");
  if (!args.media_type || !MEDIA_TYPES.includes(args.media_type)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `media_type muss einer von: ${MEDIA_TYPES.join(", ")} sein.`);
  }

  const result = await dbCreateMedia(config, {
    item_id: args.item_id,
    unit_id: unit,
    media_type: args.media_type,
    prompt: args.prompt || null,
    generation_params: args.generation_params || null,
    position: args.position || 0,
    notes: args.notes || null,
  });

  return successResponse({
    data: { operation: "media_briefing", media_id: result.media_id, item_id: args.item_id, media_type: args.media_type },
    message: `📸 Medien-Briefing ${result.media_id} für ${args.item_id} erstellt.`,
    html: `<div><h3>Medien-Briefing</h3><p><b>ID:</b> ${escapeHtml(result.media_id)}</p><p><b>Typ:</b> ${escapeHtml(args.media_type)}</p><p><b>Item:</b> ${escapeHtml(args.item_id)}</p></div>`,
    context: { workflow: "media_briefing", item_id: args.item_id, media_id: result.media_id, nextSuggestedActions: ["media_list", "media_generieren"] },
  });
}

/**
 * Listet alle Medien eines Content-Items.
 */
async function listMediaForItem(config, args) {
  const unit = args.unit || config.unit || DEFAULT_UNIT;
  if (!args.item_id && !args.unit_id) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "item_id oder unit_id ist Pflicht für media_list.");
  }
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "media_list" });
  }

  const results = await dbListMedia(config, {
    item_id: args.item_id || null,
    unit_id: args.item_id ? null : unit,
    media_type: args.media_type || null,
    status: args.status || null,
    limit: args.limit || 50,
  });

  const items = (results || []).map((m) => ({
    media_id: m.media_id,
    item_id: m.item_id,
    media_type: m.media_type,
    status: m.status,
    prompt: m.prompt,
    url: m.url,
    drive_file_id: m.drive_file_id,
    position: m.position,
    notes: m.notes,
    created_at: m.created_at,
  }));

  return successResponse({
    data: { operation: "media_list", items, total: items.length, item_id: args.item_id || null },
    message: `📸 ${items.length} Medien-Elemente geladen.`,
    html: `<div><h3>Medien-Elemente</h3>
      ${items.length === 0 ? "<p>Keine Medien-Elemente.</p>" : `<ul>${items.map((m) => `<li>${escapeHtml(m.media_id)} · ${escapeHtml(m.media_type)} · ${escapeHtml(m.status)} · ${m.url ? "🔗" : "📝"}</li>`).join("")}</ul>`}
      </div>`,
    context: { workflow: "media_list", item_id: args.item_id || null, nextSuggestedActions: ["media_briefing", "media_generieren"] },
  });
}

/**
 * Aktualisiert ein Medien-Item (Status, URL, Drive-ID).
 */
async function updateMediaItem(config, args) {
  if (!args.media_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "media_id ist Pflicht für media_update.");
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "media_update" });
  }
  if (args.status && !MEDIA_STATUSES.includes(args.status)) {
    throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `status muss einer von: ${MEDIA_STATUSES.join(", ")} sein.`);
  }

  const result = await dbUpdateMedia(config, {
    media_id: args.media_id,
    status: args.status,
    url: args.url,
    drive_file_id: args.drive_file_id,
    prompt: args.prompt,
    generation_params: args.generation_params,
    notes: args.notes,
    position: args.position,
  });

  return successResponse({
    data: { operation: "media_update", media_id: args.media_id, updated: result },
    message: `📸 Medien-Item ${args.media_id} aktualisiert.`,
    html: `<div><h3>Medien-Update</h3><p><b>ID:</b> ${escapeHtml(args.media_id)}</p><p><b>Status:</b> ${escapeHtml(result.status)}</p>${result.url ? `<p><b>URL:</b> ${escapeHtml(result.url)}</p>` : ""}</div>`,
    context: { workflow: "media_update", media_id: args.media_id, nextSuggestedActions: ["media_list"] },
  });
}

/**
 * Löscht ein Medien-Item.
 */
async function deleteMediaItem(config, args) {
  if (!args.media_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "media_id ist Pflicht für media_delete.");
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "media_delete" });
  }

  await dbDeleteMedia(config, { media_id: args.media_id });
  return successResponse({
    data: { operation: "media_delete", media_id: args.media_id, deleted: true },
    message: `🗑️ Medien-Item ${args.media_id} gelöscht.`,
    html: `<div><h3>Medien gelöscht</h3><p><b>ID:</b> ${escapeHtml(args.media_id)}</p></div>`,
    context: { workflow: "media_delete", nextSuggestedActions: ["media_list"] },
  });
}

/**
 * Baut ein Generierungs-Briefing für den image-generation Skill.
 * Wird von `media_generieren` aufgerufen.
 */
async function buildGenerationBriefing(config, args) {
  if (!args.media_id) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, "media_id ist Pflicht für media_generieren.");
  if (!isDbConfigured(config)) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DB_URL nicht konfiguriert.", { action: "media_generieren" });
  }

  const media = await dbGetMedia(config, { media_id: args.media_id });
  if (!media) throw new SkillError(ERROR_CODES.VALIDATION_ERROR, `Medien-Item ${args.media_id} nicht gefunden.`);

  const genParams = media.generation_params || {};

  return successResponse({
    data: {
      operation: "media_generieren",
      media_id: media.media_id,
      item_id: media.item_id,
      media_type: media.media_type,
      prompt: media.prompt,
      generation_params: genParams,
      image_generation_call: {
        skill: "image-generation",
        action: "generate",
        prompt: media.prompt,
        image_size: genParams.aspect_ratio === "1:1" ? "square_hd" : "landscape_16_9",
      },
      after_generation: {
        skill: "content-system",
        action: "media_update",
        media_id: media.media_id,
        status: "generiert",
        url: "[CDN_URL_VON_image_generation]",
      },
      after_drive_save: {
        skill: "drive-automation",
        action: "saveGeneratedAsset",
        url: "[CDN_URL]",
        assetType: media.media_type === "video" ? "video" : "image",
      },
    },
    message: `🎨 Generierungs-Briefing für ${media.media_id} (${media.media_type}).`,
    html: `<div><h3>Generierungs-Briefing</h3><p><b>Media-ID:</b> ${escapeHtml(media.media_id)}</p><p><b>Typ:</b> ${escapeHtml(media.media_type)}</p><pre style="white-space:pre-wrap;font-family:monospace;">${escapeHtml(media.prompt || "—")}</pre></div>`,
    context: {
      workflow: "media_generieren",
      media_id: media.media_id,
      item_id: media.item_id,
      nextSuggestedActions: ["media_update", "media_list"],
      resources: { image_generation_prompt: media.prompt },
    },
  });
}

module.exports = {
  MEDIA_TYPES,
  MEDIA_STATUSES,
  buildMediaBriefing,
  createMediaBriefing,
  listMediaForItem,
  updateMediaItem,
  deleteMediaItem,
  buildGenerationBriefing,
};