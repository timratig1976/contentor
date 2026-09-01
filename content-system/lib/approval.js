"use strict";

const WRITE_ACTIONS = new Set(["redaktionsplan", "angle_speichern", "newsletter_bk"]);

function requiresApproval(action, params = {}) {
  if (!WRITE_ACTIONS.has(action)) return false;
  if (action === "redaktionsplan" && params.mode === "show") return false;
  if (action === "newsletter_bk" && params.mode !== "draft") return false;
  return true;
}

function buildApprovalDescription(action, params = {}) {
  const lines = [`Content-System-Aktion: ${action}`];
  const fields = {
    format: "Format",
    icp: "ICP",
    status: "Status",
    angle: "Angle",
    angle_short: "Angle kurz",
    owner: "Owner",
    topic: "Thema",
    live_date: "Live-Datum",
  };
  for (const [field, label] of Object.entries(fields)) {
    if (params[field] !== undefined) lines.push(`${label}: ${params[field]}`);
  }
  if (action === "redaktionsplan" && params.mode !== "show") lines.push("Schreibt einen neuen Eintrag in den Redaktionsplan.");
  if (action === "angle_speichern") lines.push("Legt ein neues Angle-Dokument in der Angle-Library an.");
  if (action === "newsletter_bk" && params.mode === "draft") lines.push("Erstellt oder aktualisiert ein BK-Newsletter-Dokument in Drive.");
  return lines.join("\n");
}

module.exports = { WRITE_ACTIONS, requiresApproval, buildApprovalDescription };
