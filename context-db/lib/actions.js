"use strict";

const { query } = require("./db");

// ── Kunden ────────────────────────────────────────────────────────

async function listKunden(conn) {
  const r = await query(conn, "SELECT id, name, kkz, drive_folder_id, status FROM kunden ORDER BY name");
  return r.rows;
}

async function findKunde(conn, { name, kkz }) {
  if (kkz) {
    const r = await query(conn, "SELECT * FROM kunden WHERE kkz = $1", [kkz.toUpperCase()]);
    return r.rows[0] || null;
  }
  const r = await query(conn,
    "SELECT * FROM kunden WHERE lower(name) LIKE lower($1) ORDER BY name LIMIT 5",
    [`%${name}%`]
  );
  return r.rows;
}

async function createKunde(conn, { name, kkz, drive_folder_id, clickup_project_id, hubspot_company_id }) {
  const r = await query(conn,
    "INSERT INTO kunden (name, kkz, drive_folder_id, clickup_project_id, hubspot_company_id, status) VALUES ($1, $2, $3, $4, $5, 'aktiv') RETURNING *",
    [name, kkz?.toUpperCase(), drive_folder_id || null, clickup_project_id || null, hubspot_company_id || null]
  );
  return r.rows[0];
}

async function updateKunde(conn, { id, drive_folder_id, clickup_project_id, hubspot_company_id, status }) {
  const sets = [];
  const vals = [];
  let i = 1;
  if (drive_folder_id !== undefined) { sets.push(`drive_folder_id = $${i++}`); vals.push(drive_folder_id); }
  if (clickup_project_id !== undefined) { sets.push(`clickup_project_id = $${i++}`); vals.push(clickup_project_id); }
  if (hubspot_company_id !== undefined) { sets.push(`hubspot_company_id = $${i++}`); vals.push(hubspot_company_id); }
  if (status !== undefined) { sets.push(`status = $${i++}`); vals.push(status); }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  vals.push(id);
  const r = await query(conn, `UPDATE kunden SET ${sets.join(", ")} WHERE id = $${i} RETURNING *`, vals);
  return r.rows[0];
}

// ── Projekte ──────────────────────────────────────────────────────

async function listProjekte(conn, { kunden_id, status }) {
  let sql = "SELECT p.*, k.name AS kunden_name FROM projekte p JOIN kunden k ON k.id = p.kunden_id WHERE 1=1";
  const vals = [];
  let i = 1;
  if (kunden_id) { sql += ` AND p.kunden_id = $${i++}`; vals.push(kunden_id); }
  if (status) { sql += ` AND p.status = $${i++}`; vals.push(status); }
  sql += " ORDER BY p.started_at DESC";
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function createProjekt(conn, { kunden_id, unit_id, typ, status, drive_folder_id, clickup_project_id, hubspot_deal_id, hubspot_project_id, integrations }) {
  const r = await query(conn,
    "INSERT INTO projekte (kunden_id, unit_id, typ, status, drive_folder_id, clickup_project_id, hubspot_deal_id, hubspot_project_id, integrations, started_at) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now()) RETURNING *",
    [kunden_id, unit_id, typ, status || "aktiv", drive_folder_id || null, clickup_project_id || null, hubspot_deal_id || null, hubspot_project_id || null, integrations ? JSON.stringify(integrations) : null]
  );
  return r.rows[0];
}

async function updateProjekt(conn, { id, status, unit_id, drive_folder_id, clickup_project_id, hubspot_deal_id, hubspot_project_id, integrations }) {
  const sets = [];
  const vals = [];
  let i = 1;
  if (status !== undefined) { sets.push(`status = $${i++}`); vals.push(status); }
  if (unit_id !== undefined) { sets.push(`unit_id = $${i++}`); vals.push(unit_id); }
  if (drive_folder_id !== undefined) { sets.push(`drive_folder_id = $${i++}`); vals.push(drive_folder_id); }
  if (clickup_project_id !== undefined) { sets.push(`clickup_project_id = $${i++}`); vals.push(clickup_project_id); }
  if (hubspot_deal_id !== undefined) { sets.push(`hubspot_deal_id = $${i++}`); vals.push(hubspot_deal_id); }
  if (hubspot_project_id !== undefined) { sets.push(`hubspot_project_id = $${i++}`); vals.push(hubspot_project_id); }
  if (integrations !== undefined) { sets.push(`integrations = $${i++}`); vals.push(JSON.stringify(integrations)); }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  vals.push(id);
  const r = await query(conn, `UPDATE projekte SET ${sets.join(", ")} WHERE id = $${i} RETURNING *`, vals);
  return r.rows[0];
}

// ── Kontext-Items ─────────────────────────────────────────────────

async function getKontext(conn, { projekt_id, step_code, keys }) {
  // keys kann String (JSON-Array oder komma-separiert) oder echtes Array sein
  let keysArr = null;
  if (keys) {
    if (Array.isArray(keys)) {
      keysArr = keys;
    } else if (typeof keys === "string") {
      const trimmed = keys.trim();
      if (trimmed.startsWith("[")) {
        try { keysArr = JSON.parse(trimmed); } catch (_) { keysArr = [trimmed]; }
      } else {
        keysArr = trimmed.split(/,\s*/).map(k => k.trim()).filter(Boolean);
      }
    }
  }
  let sql = "SELECT id, step_code, typ, key, content, drive_file_id, version, source_skill, created_by, created_at, updated_at FROM kontext_items WHERE projekt_id = $1";
  const vals = [projekt_id];
  let i = 2;
  if (step_code) { sql += ` AND step_code = $${i++}`; vals.push(step_code); }
  if (keysArr?.length) { sql += ` AND key = ANY($${i++})`; vals.push(keysArr); }
  sql += " ORDER BY created_at DESC";
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function setKontext(conn, { projekt_id, step_code, typ, key, content, drive_file_id, source_skill, created_by }) {
  const r = await query(conn, `
    INSERT INTO kontext_items (projekt_id, step_code, typ, key, content, drive_file_id, version, source_skill, created_by, created_at, updated_at)
    VALUES ($1, $2, $3, $4, $5, $6, 1, $7, $8, now(), now())
    ON CONFLICT (projekt_id, step_code, key)
    DO UPDATE SET content = EXCLUDED.content, drive_file_id = EXCLUDED.drive_file_id,
                  version = kontext_items.version + 1, source_skill = EXCLUDED.source_skill,
                  created_by = EXCLUDED.created_by, updated_at = now()
    RETURNING *`,
    [projekt_id, step_code, typ || "text", key, content, drive_file_id || null, source_skill || null, created_by || null]
  );
  return r.rows[0];
}

// ── SOP-Runs ──────────────────────────────────────────────────────

async function getSopRun(conn, { projekt_id }) {
  const r = await query(conn,
    "SELECT sr.*, array_agg(row_to_json(ssr)) AS steps FROM sop_runs sr LEFT JOIN sop_step_runs ssr ON ssr.sop_run_id = sr.id WHERE sr.projekt_id = $1 GROUP BY sr.id ORDER BY sr.id DESC LIMIT 1",
    [projekt_id]
  );
  return r.rows[0] || null;
}

async function advanceSopStep(conn, { sop_run_id, completed_step_code, next_step_code }) {
  // Aktuellen Step abschließen
  await query(conn,
    "UPDATE sop_step_runs SET status = 'completed', completed_at = now() WHERE sop_run_id = $1 AND step_code = $2",
    [sop_run_id, completed_step_code]
  );
  // Nächsten Step starten
  await query(conn,
    "INSERT INTO sop_step_runs (sop_run_id, step_code, status, started_at) VALUES ($1, $2, 'aktiv', now()) ON CONFLICT DO NOTHING",
    [sop_run_id, next_step_code]
  );
  // SOP-Run aktualisieren
  const r = await query(conn,
    "UPDATE sop_runs SET current_step_code = $1 WHERE id = $2 RETURNING *",
    [next_step_code, sop_run_id]
  );
  return r.rows[0];
}

// ── Datei-Referenzen ──────────────────────────────────────────────

async function listDateien(conn, { projekt_id, step_code }) {
  let sql = "SELECT * FROM dateien WHERE projekt_id = $1";
  const vals = [projekt_id];
  if (step_code) { sql += " AND step_code = $2"; vals.push(step_code); }
  sql += " ORDER BY created_at DESC";
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function registerDatei(conn, { projekt_id, step_code, drive_file_id, name, mime_type, hash }) {
  const r = await query(conn,
    "INSERT INTO dateien (projekt_id, step_code, drive_file_id, name, mime_type, version, hash, created_at) VALUES ($1, $2, $3, $4, $5, 1, $6, now()) RETURNING *",
    [projekt_id, step_code, drive_file_id, name, mime_type, hash || null]
  );
  return r.rows[0];
}

// ── Setup / Migrations ────────────────────────────────────────────

async function setupSchema(conn) {
  const sql = `
    CREATE TABLE IF NOT EXISTS kunden (
      id SERIAL PRIMARY KEY,
      name TEXT NOT NULL,
      kkz TEXT UNIQUE,
      drive_folder_id TEXT,
      clickup_project_id TEXT,
      hubspot_company_id TEXT,
      status TEXT DEFAULT 'aktiv',
      created_at TIMESTAMPTZ DEFAULT now()
    );
    -- Migration: neue Spalten hinzufügen falls noch nicht vorhanden
    ALTER TABLE kunden ADD COLUMN IF NOT EXISTS clickup_project_id TEXT;
    ALTER TABLE kunden ADD COLUMN IF NOT EXISTS hubspot_company_id TEXT;
    ALTER TABLE kunden ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT now();

    CREATE TABLE IF NOT EXISTS projekte (
      id SERIAL PRIMARY KEY,
      kunden_id INT REFERENCES kunden(id),
      unit_id TEXT,
      typ TEXT,
      status TEXT DEFAULT 'aktiv',
      drive_folder_id TEXT,
      clickup_project_id TEXT,
      hubspot_deal_id TEXT,
      hubspot_project_id TEXT,
      started_at TIMESTAMPTZ DEFAULT now()
    );
    -- Migration: neue Spalten hinzufügen falls noch nicht vorhanden
    ALTER TABLE projekte ADD COLUMN IF NOT EXISTS drive_folder_id TEXT;
    ALTER TABLE projekte ADD COLUMN IF NOT EXISTS clickup_project_id TEXT;
    ALTER TABLE projekte ADD COLUMN IF NOT EXISTS hubspot_deal_id TEXT;
    ALTER TABLE projekte ADD COLUMN IF NOT EXISTS hubspot_project_id TEXT;
    ALTER TABLE projekte ADD COLUMN IF NOT EXISTS integrations JSONB;
    COMMENT ON COLUMN projekte.integrations IS 'Tool-Integrationen: {meta_ads_account_id, google_ads_customer_id, linkedin_page_id, instagram_account_id, ...}';

    CREATE TABLE IF NOT EXISTS sop_steps (
      unit_id TEXT NOT NULL,
      step_code TEXT NOT NULL,
      order_index INT,
      PRIMARY KEY (unit_id, step_code)
    );
    CREATE TABLE IF NOT EXISTS sop_runs (
      id SERIAL PRIMARY KEY,
      projekt_id INT REFERENCES projekte(id),
      unit_id TEXT,
      current_step_code TEXT,
      status TEXT DEFAULT 'aktiv'
    );
    CREATE TABLE IF NOT EXISTS sop_step_runs (
      id SERIAL PRIMARY KEY,
      sop_run_id INT REFERENCES sop_runs(id),
      step_code TEXT,
      status TEXT DEFAULT 'aktiv',
      started_at TIMESTAMPTZ,
      completed_at TIMESTAMPTZ,
      UNIQUE(sop_run_id, step_code)
    );
    CREATE TABLE IF NOT EXISTS kontext_items (
      id SERIAL PRIMARY KEY,
      projekt_id INT REFERENCES projekte(id),
      step_code TEXT,
      typ TEXT DEFAULT 'text',
      key TEXT NOT NULL,
      content TEXT,
      drive_file_id TEXT,
      version INT DEFAULT 1,
      source_skill TEXT,
      created_by TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now(),
      UNIQUE(projekt_id, step_code, key)
    );
    -- Migration: neue Spalten hinzufügen falls noch nicht vorhanden
    ALTER TABLE kontext_items ADD COLUMN IF NOT EXISTS source_skill TEXT;
    ALTER TABLE kontext_items ADD COLUMN IF NOT EXISTS created_by TEXT;
    ALTER TABLE kontext_items ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT now();
    CREATE TABLE IF NOT EXISTS dateien (
      id SERIAL PRIMARY KEY,
      projekt_id INT REFERENCES projekte(id),
      step_code TEXT,
      drive_file_id TEXT,
      name TEXT,
      mime_type TEXT,
      version INT DEFAULT 1,
      hash TEXT,
      created_at TIMESTAMPTZ DEFAULT now()
    );

    -- ── Content System ────────────────────────────────────────────

    CREATE TABLE IF NOT EXISTS content_angles (
      id SERIAL PRIMARY KEY,
      angle_id TEXT UNIQUE NOT NULL,
      unit_id TEXT NOT NULL,
      angle TEXT NOT NULL,
      icp TEXT,
      pain_cluster TEXT,
      statement_type TEXT,
      source TEXT,
      status TEXT DEFAULT 'aktiv',
      used_in_ids TEXT[],
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS content_angles_unit_idx ON content_angles(unit_id);
    CREATE INDEX IF NOT EXISTS content_angles_status_idx ON content_angles(status);
    -- Migration: neue Felder für Source-Verknüpfung, Batch, Funnel
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS source_id TEXT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS batch_key TEXT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS funnel TEXT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS viscale_phase TEXT;
    CREATE INDEX IF NOT EXISTS content_angles_source_idx ON content_angles(source_id);
    CREATE INDEX IF NOT EXISTS content_angles_batch_idx ON content_angles(batch_key);
    -- Migration: Ranking-Felder (4 Kriterien + Score + Rang)
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS r_zielgruppe SMALLINT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS r_viscale_fit SMALLINT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS r_schaerfe SMALLINT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS r_timing SMALLINT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS ranking_score SMALLINT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS ranking_rang INT;
    ALTER TABLE content_angles ADD COLUMN IF NOT EXISTS ranking_updated_at TIMESTAMPTZ;
    CREATE INDEX IF NOT EXISTS content_angles_score_idx ON content_angles(ranking_score);

    -- ── Content Sources ───────────────────────────────────────────
    -- Eine Quelle (PDF, URL, Interview, Research) kann mehrere Angles erzeugen.

    CREATE TABLE IF NOT EXISTS content_sources (
      id SERIAL PRIMARY KEY,
      source_id TEXT UNIQUE NOT NULL,
      unit_id TEXT NOT NULL,
      type TEXT NOT NULL,            -- "pdf" | "url" | "interview" | "intern" | "research"
      title TEXT NOT NULL,
      date TEXT,                     -- frei z.B. "2026-05" oder "2026-05-14"
      file_ref TEXT,                 -- Dateiname oder Drive-Pfad, z.B. hubspot_pricing_2026-05.pdf
      drive_file_id TEXT,            -- Drive-File-ID, falls bereits hochgeladen
      visibility TEXT DEFAULT 'intern',  -- "intern" | "extern" | "confidential"
      notes TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS content_sources_unit_idx ON content_sources(unit_id);
    CREATE INDEX IF NOT EXISTS content_sources_type_idx ON content_sources(type);

    CREATE TABLE IF NOT EXISTS content_items (
      id SERIAL PRIMARY KEY,
      item_id TEXT UNIQUE NOT NULL,
      unit_id TEXT NOT NULL,
      typ TEXT NOT NULL,
      status TEXT DEFAULT 'idee',
      input TEXT,
      angle_id TEXT REFERENCES content_angles(angle_id),
      icp TEXT,
      pain_cluster TEXT,
      statement_type TEXT,
      format TEXT,
      title TEXT,
      owner TEXT,
      live_date DATE,
      content TEXT,
      output_doc_id TEXT,
      output_url TEXT,
      source TEXT,
      notes TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS content_items_unit_idx ON content_items(unit_id);
    CREATE INDEX IF NOT EXISTS content_items_status_idx ON content_items(status);
    CREATE INDEX IF NOT EXISTS content_items_typ_idx ON content_items(typ);
    CREATE INDEX IF NOT EXISTS content_items_angle_idx ON content_items(angle_id);

    -- Migration: persona_id für Personen-Posts
    ALTER TABLE content_items ADD COLUMN IF NOT EXISTS persona_id TEXT;
    CREATE INDEX IF NOT EXISTS content_items_persona_idx ON content_items(persona_id);

    -- ── Content Strategy ──────────────────────────────────────────

    CREATE TABLE IF NOT EXISTS content_strategies (
      id SERIAL PRIMARY KEY,
      unit_id TEXT NOT NULL,
      strategy_key TEXT NOT NULL,
      content JSONB NOT NULL,
      version INT DEFAULT 1,
      updated_at TIMESTAMPTZ DEFAULT now(),
      UNIQUE(unit_id, strategy_key)
    );
    CREATE INDEX IF NOT EXISTS content_strategies_unit_idx ON content_strategies(unit_id);

    -- ── Content Media ─────────────────────────────────────────────

    CREATE TABLE IF NOT EXISTS content_media (
      id SERIAL PRIMARY KEY,
      media_id TEXT UNIQUE NOT NULL,
      item_id TEXT NOT NULL REFERENCES content_items(item_id) ON DELETE CASCADE,
      unit_id TEXT NOT NULL,
      media_type TEXT NOT NULL,
      status TEXT DEFAULT 'briefing',
      prompt TEXT,
      generation_params JSONB,
      url TEXT,
      drive_file_id TEXT,
      position INT DEFAULT 0,
      notes TEXT,
      created_at TIMESTAMPTZ DEFAULT now(),
      updated_at TIMESTAMPTZ DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS content_media_item_idx ON content_media(item_id);
    CREATE INDEX IF NOT EXISTS content_media_status_idx ON content_media(status);
    CREATE INDEX IF NOT EXISTS content_media_type_idx ON content_media(media_type);
  `;
  await query(conn, sql);
  return { status: "schema_ready" };
}

async function debugAction(conn) {
  const r = await query(conn, `
    SELECT
      (SELECT count(*) FROM kunden) AS kunden,
      (SELECT count(*) FROM projekte) AS projekte,
      (SELECT count(*) FROM kontext_items) AS kontext_items,
      (SELECT count(*) FROM sop_runs) AS sop_runs,
      (SELECT count(*) FROM dateien) AS dateien,
      (SELECT count(*) FROM content_angles) AS content_angles,
      (SELECT count(*) FROM content_items) AS content_items,
      (SELECT count(*) FROM content_strategies) AS content_strategies,
      (SELECT count(*) FROM content_media) AS content_media
  `);
  return r.rows[0];
}

async function saveOutput(conn, { projekt_id, step_code, key, content, typ, drive_file_id }) {
  if (!projekt_id) throw new Error("projekt_id erforderlich für save_output.");
  return setKontext(conn, { projekt_id, step_code: step_code || "output", key: key || "result", content, typ: typ || "text", drive_file_id });
}

async function getFile(conn, { projekt_id, step_code, key, filename }) {
  // Lädt einen kontext_item-Inhalt direkt als Download-Link
  // ohne dass das LLM den Inhalt transferieren muss
  let sql = "SELECT key, typ, content, source_skill, created_at FROM kontext_items WHERE 1=1";
  const vals = [];
  let i = 1;
  if (projekt_id) { sql += ` AND projekt_id = $${i++}`; vals.push(projekt_id); }
  else { sql += " AND projekt_id IS NULL"; }
  if (step_code) { sql += ` AND step_code = $${i++}`; vals.push(step_code); }
  if (key) { sql += ` AND key = $${i++}`; vals.push(key); }
  sql += " ORDER BY updated_at DESC LIMIT 1";
  const r = await query(conn, sql, vals);
  if (!r.rows[0]) return null;

  const item = r.rows[0];
  const content = item.content || "";
  const mimeType = item.typ === "html" ? "text/html"
    : item.typ === "markdown" ? "text/markdown"
    : item.typ === "json" ? "application/json"
    : "text/plain";

  const ext = item.typ === "html" ? ".html"
    : item.typ === "markdown" ? ".md"
    : item.typ === "json" ? ".json"
    : ".txt";

  const dlFilename = filename || `${(key || item.key || "datei").replace(/[^a-z0-9\-_]/gi, "-")}${ext}`;
  const base64 = Buffer.from(content, "utf8").toString("base64");
  const dataUrl = `data:${mimeType};base64,${base64}`;

  return {
    key: item.key,
    typ: item.typ,
    filename: dlFilename,
    size_chars: content.length,
    source_skill: item.source_skill,
    created_at: item.created_at,
    download_url: dataUrl,
    download_html: `<div style="padding:16px;border:1px solid #ddd;border-radius:8px;background:#f9f9f9;">
      <h3 style="margin:0 0 8px 0;">📄 ${dlFilename}</h3>
      <p style="margin:0 0 8px 0;color:#666;font-size:13px;">${content.length.toLocaleString()} Zeichen · ${mimeType} · erzeugt von: ${item.source_skill || "?"} · ${new Date(item.created_at).toLocaleString("de-DE")}</p>
      <a href="${dataUrl}" download="${dlFilename}" style="display:inline-block;padding:10px 20px;background:#E8500A;color:#fff;border-radius:4px;text-decoration:none;font-weight:bold;">⬇ ${dlFilename} herunterladen</a>
    </div>`,
  };
}

// ── Content Angles ────────────────────────────────────────────────

function generateContentId(prefix) {
  const ts = Date.now().toString(36).toUpperCase();
  const rnd = Math.random().toString(36).slice(2, 6).toUpperCase();
  return `${prefix}-${ts}${rnd}`;
}

async function createAngle(conn, { unit_id, angle, icp, pain_cluster, statement_type, source, source_id, batch_key, funnel, viscale_phase, r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing }) {
  if (!unit_id || !angle) throw new Error("unit_id und angle sind Pflichtfelder.");
  const angle_id = generateContentId("ANG");
  // Score berechnen falls Kriterien mitgegeben
  const score = (r_zielgruppe && r_viscale_fit && r_schaerfe && r_timing)
    ? (Number(r_zielgruppe) + Number(r_viscale_fit) + Number(r_schaerfe) + Number(r_timing))
    : null;
  const r = await query(conn,
    `INSERT INTO content_angles
       (angle_id, unit_id, angle, icp, pain_cluster, statement_type, source, status,
        source_id, batch_key, funnel, viscale_phase,
        r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing, ranking_score, ranking_updated_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,'aktiv',$8,$9,$10,$11,$12,$13,$14,$15,$16,$17) RETURNING *`,
    [angle_id, unit_id, angle, icp || null, pain_cluster || null, statement_type || null, source || null,
     source_id || null, batch_key || null, funnel || null, viscale_phase || null,
     r_zielgruppe || null, r_viscale_fit || null, r_schaerfe || null, r_timing || null,
     score, score ? new Date() : null]
  );
  return r.rows[0];
}

async function listAngles(conn, { unit_id, status, icp, source_id, batch_key, funnel, limit = 50 }) {
  let sql = "SELECT * FROM content_angles WHERE 1=1";
  const vals = []; let i = 1;
  if (unit_id)   { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (status)    { sql += ` AND status = $${i++}`; vals.push(status); }
  if (icp)       { sql += ` AND icp = $${i++}`; vals.push(icp); }
  if (source_id) { sql += ` AND source_id = $${i++}`; vals.push(source_id); }
  if (batch_key) { sql += ` AND batch_key = $${i++}`; vals.push(batch_key); }
  if (funnel)    { sql += ` AND funnel = $${i++}`; vals.push(funnel); }
  sql += ` ORDER BY created_at DESC LIMIT $${i}`; vals.push(Number(limit) || 50);
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function getAngle(conn, { angle_id }) {
  if (!angle_id) throw new Error("angle_id ist Pflichtfeld.");
  const r = await query(conn, "SELECT * FROM content_angles WHERE angle_id = $1", [angle_id]);
  return r.rows[0] || null;
}

async function updateAngle(conn, { angle_id, status, used_in_ids, icp, pain_cluster, statement_type, source, source_id, batch_key, funnel, viscale_phase, r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing }) {
  if (!angle_id) throw new Error("angle_id ist Pflichtfeld.");
  const sets = []; const vals = []; let i = 1;
  if (status !== undefined)        { sets.push(`status = $${i++}`); vals.push(status); }
  if (used_in_ids !== undefined)   { sets.push(`used_in_ids = $${i++}`); vals.push(used_in_ids); }
  if (source_id !== undefined)     { sets.push(`source_id = $${i++}`); vals.push(source_id); }
  if (batch_key !== undefined)     { sets.push(`batch_key = $${i++}`); vals.push(batch_key); }
  if (funnel !== undefined)        { sets.push(`funnel = $${i++}`); vals.push(funnel); }
  if (viscale_phase !== undefined) { sets.push(`viscale_phase = $${i++}`); vals.push(viscale_phase); }
  if (r_zielgruppe !== undefined)  { sets.push(`r_zielgruppe = $${i++}`); vals.push(r_zielgruppe); }
  if (r_viscale_fit !== undefined) { sets.push(`r_viscale_fit = $${i++}`); vals.push(r_viscale_fit); }
  if (r_schaerfe !== undefined)    { sets.push(`r_schaerfe = $${i++}`); vals.push(r_schaerfe); }
  if (r_timing !== undefined)      { sets.push(`r_timing = $${i++}`); vals.push(r_timing); }
  if (icp !== undefined)         { sets.push(`icp = $${i++}`); vals.push(icp); }
  if (pain_cluster !== undefined){ sets.push(`pain_cluster = $${i++}`); vals.push(pain_cluster); }
  if (statement_type !== undefined) { sets.push(`statement_type = $${i++}`); vals.push(statement_type); }
  if (source !== undefined)      { sets.push(`source = $${i++}`); vals.push(source); }
  // Score neu berechnen wenn mindestens ein Kriterium geändert wurde
  const hasCriteria = [r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing].some(v => v !== undefined);
  if (hasCriteria) {
    // Score aus aktuellen Werten + neuen Werten berechnen (nach dem Update)
    sets.push(`ranking_score = COALESCE(r_zielgruppe,0) + COALESCE(r_viscale_fit,0) + COALESCE(r_schaerfe,0) + COALESCE(r_timing,0)`);
    sets.push(`ranking_updated_at = now()`);
  }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  sets.push(`updated_at = now()`);
  vals.push(angle_id);
  const r = await query(conn, `UPDATE content_angles SET ${sets.join(", ")} WHERE angle_id = $${i} RETURNING *`, vals);
  return r.rows[0] || null;
}

/**
 * Setzt Ranking für einen Angle (4 Kriterien) und berechnet Score.
 * Aktualisiert danach den Rang aller Angles im selben batch_key.
 */
async function rankAngle(conn, { angle_id, r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing }) {
  if (!angle_id) throw new Error("angle_id ist Pflichtfeld.");
  const z = Number(r_zielgruppe); const v = Number(r_viscale_fit);
  const s = Number(r_schaerfe);   const t = Number(r_timing);
  for (const [name, val] of [["r_zielgruppe",z],["r_viscale_fit",v],["r_schaerfe",s],["r_timing",t]]) {
    if (![1,2,3].includes(val)) throw new Error(`${name} muss 1, 2 oder 3 sein. Erhalten: ${val}`);
  }
  const score = z + v + s + t;
  // Angle updaten
  const upd = await query(conn,
    `UPDATE content_angles
     SET r_zielgruppe=$1, r_viscale_fit=$2, r_schaerfe=$3, r_timing=$4,
         ranking_score=$5, ranking_updated_at=now(), updated_at=now()
     WHERE angle_id=$6 RETURNING *`,
    [z, v, s, t, score, angle_id]
  );
  const updated = upd.rows[0] || null;
  if (!updated) return null;

  // Rang für alle Angles im selben batch_key neu berechnen
  let ranked = [];
  if (updated.batch_key) {
    const batch = await query(conn,
      `SELECT angle_id, ranking_score, r_schaerfe, r_zielgruppe
       FROM content_angles WHERE batch_key=$1 AND ranking_score IS NOT NULL
       ORDER BY ranking_score DESC, r_schaerfe DESC, r_zielgruppe DESC`,
      [updated.batch_key]
    );
    for (let idx = 0; idx < batch.rows.length; idx++) {
      await query(conn,
        `UPDATE content_angles SET ranking_rang=$1 WHERE angle_id=$2`,
        [idx + 1, batch.rows[idx].angle_id]
      );
    }
    ranked = batch.rows.map((row, idx) => ({ angle_id: row.angle_id, rang: idx + 1, score: row.ranking_score }));
  }
  // Aktuellen Rang zurücklesen
  const final = await query(conn, `SELECT * FROM content_angles WHERE angle_id=$1`, [angle_id]);
  return { angle: final.rows[0] || updated, batch_ranking: ranked };
}

/**
 * Gibt alle Angles eines Batches sortiert nach Rang/Score zurück.
 */
async function listAngleRanking(conn, { batch_key, unit_id, min_score, limit = 50 }) {
  if (!batch_key) throw new Error("batch_key ist Pflichtfeld.");
  let sql = `SELECT * FROM content_angles WHERE batch_key=$1`;
  const vals = [batch_key]; let i = 2;
  if (unit_id)   { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (min_score) { sql += ` AND ranking_score >= $${i++}`; vals.push(Number(min_score)); }
  sql += ` ORDER BY COALESCE(ranking_rang, 999) ASC, COALESCE(ranking_score,0) DESC LIMIT $${i}`;
  vals.push(Number(limit) || 50);
  const r = await query(conn, sql, vals);
  return r.rows;
}

// ── Content Items ─────────────────────────────────────────────────

async function createContentItem(conn, {
  unit_id, typ, input, angle_id, icp, pain_cluster, statement_type,
  format, title, owner, persona_id, live_date, content, source, notes,
}) {
  if (!unit_id || !typ) throw new Error("unit_id und typ sind Pflichtfelder.");
  const item_id = generateContentId("CNT");
  const r = await query(conn,
    `INSERT INTO content_items
       (item_id, unit_id, typ, status, input, angle_id, icp, pain_cluster, statement_type,
        format, title, owner, persona_id, live_date, content, source, notes)
     VALUES ($1,$2,$3,'idee',$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16) RETURNING *`,
    [item_id, unit_id, typ,
     input || null, angle_id || null, icp || null, pain_cluster || null, statement_type || null,
     format || null, title || null, owner || null, persona_id || null,
     live_date || null, content || null, source || null, notes || null]
  );
  return r.rows[0];
}

async function listContentItems(conn, { unit_id, status, typ, format, limit = 50 }) {
  let sql = "SELECT * FROM content_items WHERE 1=1";
  const vals = []; let i = 1;
  if (unit_id) { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (status)  { sql += ` AND status = $${i++}`; vals.push(status); }
  if (typ)     { sql += ` AND typ = $${i++}`; vals.push(typ); }
  if (format)  { sql += ` AND format = $${i++}`; vals.push(format); }
  sql += ` ORDER BY created_at DESC LIMIT $${i}`; vals.push(Number(limit) || 50);
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function getContentItem(conn, { item_id }) {
  if (!item_id) throw new Error("item_id ist Pflichtfeld.");
  const r = await query(conn, "SELECT * FROM content_items WHERE item_id = $1", [item_id]);
  return r.rows[0] || null;
}

async function updateContentItem(conn, {
  item_id, status, title, owner, persona_id, live_date, content,
  output_doc_id, output_url, notes, angle_id, icp, pain_cluster,
}) {
  if (!item_id) throw new Error("item_id ist Pflichtfeld.");
  const sets = []; const vals = []; let i = 1;
  if (status !== undefined)       { sets.push(`status = $${i++}`); vals.push(status); }
  if (title !== undefined)        { sets.push(`title = $${i++}`); vals.push(title); }
  if (owner !== undefined)        { sets.push(`owner = $${i++}`); vals.push(owner); }
  if (persona_id !== undefined)   { sets.push(`persona_id = $${i++}`); vals.push(persona_id); }
  if (live_date !== undefined)    { sets.push(`live_date = $${i++}`); vals.push(live_date); }
  if (content !== undefined)      { sets.push(`content = $${i++}`); vals.push(content); }
  if (output_doc_id !== undefined){ sets.push(`output_doc_id = $${i++}`); vals.push(output_doc_id); }
  if (output_url !== undefined)   { sets.push(`output_url = $${i++}`); vals.push(output_url); }
  if (notes !== undefined)        { sets.push(`notes = $${i++}`); vals.push(notes); }
  if (angle_id !== undefined)     { sets.push(`angle_id = $${i++}`); vals.push(angle_id); }
  if (icp !== undefined)          { sets.push(`icp = $${i++}`); vals.push(icp); }
  if (pain_cluster !== undefined) { sets.push(`pain_cluster = $${i++}`); vals.push(pain_cluster); }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  sets.push(`updated_at = now()`);
  vals.push(item_id);
  const r = await query(conn, `UPDATE content_items SET ${sets.join(", ")} WHERE item_id = $${i} RETURNING *`, vals);
  return r.rows[0] || null;
}

// ── Content Strategy ──────────────────────────────────────────────

async function setStrategy(conn, { unit_id, strategy_key, content }) {
  if (!unit_id || !strategy_key || content === undefined) throw new Error("unit_id, strategy_key und content sind Pflichtfelder.");
  const contentJson = typeof content === "string" ? content : JSON.stringify(content);
  const r = await query(conn,
    `INSERT INTO content_strategies (unit_id, strategy_key, content, version, updated_at)
     VALUES ($1, $2, $3::jsonb, 1, now())
     ON CONFLICT (unit_id, strategy_key)
     DO UPDATE SET content = $3::jsonb, version = content_strategies.version + 1, updated_at = now()
     RETURNING *`,
    [unit_id, strategy_key, contentJson]
  );
  return r.rows[0];
}

async function getStrategy(conn, { unit_id, strategy_key }) {
  if (!unit_id || !strategy_key) throw new Error("unit_id und strategy_key sind Pflichtfelder.");
  const r = await query(conn,
    "SELECT * FROM content_strategies WHERE unit_id = $1 AND strategy_key = $2",
    [unit_id, strategy_key]
  );
  return r.rows[0] || null;
}

async function listStrategies(conn, { unit_id }) {
  if (!unit_id) throw new Error("unit_id ist Pflichtfeld.");
  const r = await query(conn,
    "SELECT strategy_key, content, version, updated_at FROM content_strategies WHERE unit_id = $1 ORDER BY strategy_key",
    [unit_id]
  );
  return r.rows;
}

async function deleteStrategy(conn, { unit_id, strategy_key }) {
  if (!unit_id || !strategy_key) throw new Error("unit_id und strategy_key sind Pflichtfelder.");
  await query(conn,
    "DELETE FROM content_strategies WHERE unit_id = $1 AND strategy_key = $2",
    [unit_id, strategy_key]
  );
  return { deleted: true };
}

// ── Content Media ─────────────────────────────────────────────────

async function createMedia(conn, { item_id, unit_id, media_type, prompt, generation_params, position, notes }) {
  if (!item_id || !unit_id || !media_type) throw new Error("item_id, unit_id und media_type sind Pflichtfelder.");
  const media_id = generateContentId("MED");
  const r = await query(conn,
    `INSERT INTO content_media
       (media_id, item_id, unit_id, media_type, status, prompt, generation_params, url, drive_file_id, position, notes)
     VALUES ($1,$2,$3,$4,'briefing',$5,$6,$7,$8,$9,$10) RETURNING *`,
    [media_id, item_id, unit_id, media_type,
     prompt || null, generation_params ? JSON.stringify(generation_params) : null,
     null, null, position || 0, notes || null]
  );
  return r.rows[0];
}

async function listMedia(conn, { item_id, unit_id, media_type, status, limit = 50 }) {
  let sql = "SELECT * FROM content_media WHERE 1=1";
  const vals = []; let i = 1;
  if (item_id) { sql += ` AND item_id = $${i++}`; vals.push(item_id); }
  if (unit_id) { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (media_type) { sql += ` AND media_type = $${i++}`; vals.push(media_type); }
  if (status) { sql += ` AND status = $${i++}`; vals.push(status); }
  sql += ` ORDER BY position ASC, created_at ASC LIMIT $${i}`; vals.push(Number(limit) || 50);
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function getMedia(conn, { media_id }) {
  if (!media_id) throw new Error("media_id ist Pflichtfeld.");
  const r = await query(conn, "SELECT * FROM content_media WHERE media_id = $1", [media_id]);
  return r.rows[0] || null;
}

async function updateMedia(conn, { media_id, status, url, drive_file_id, prompt, generation_params, notes, position }) {
  if (!media_id) throw new Error("media_id ist Pflichtfeld.");
  const sets = []; const vals = []; let i = 1;
  if (status !== undefined)            { sets.push(`status = $${i++}`); vals.push(status); }
  if (url !== undefined)               { sets.push(`url = $${i++}`); vals.push(url); }
  if (drive_file_id !== undefined)     { sets.push(`drive_file_id = $${i++}`); vals.push(drive_file_id); }
  if (prompt !== undefined)            { sets.push(`prompt = $${i++}`); vals.push(prompt); }
  if (generation_params !== undefined) { sets.push(`generation_params = $${i++}`); vals.push(JSON.stringify(generation_params)); }
  if (notes !== undefined)             { sets.push(`notes = $${i++}`); vals.push(notes); }
  if (position !== undefined)          { sets.push(`position = $${i++}`); vals.push(position); }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  sets.push(`updated_at = now()`);
  vals.push(media_id);
  const r = await query(conn, `UPDATE content_media SET ${sets.join(", ")} WHERE media_id = $${i} RETURNING *`, vals);
  return r.rows[0] || null;
}

async function deleteMedia(conn, { media_id }) {
  if (!media_id) throw new Error("media_id ist Pflichtfeld.");
  await query(conn, "DELETE FROM content_media WHERE media_id = $1", [media_id]);
  return { deleted: true };
}

// ── Content Sources ───────────────────────────────────────────────

async function createSource(conn, { unit_id, type, title, date, file_ref, drive_file_id, visibility, notes }) {
  if (!unit_id || !type || !title) throw new Error("unit_id, type und title sind Pflichtfelder.");
  const source_id = generateContentId("SRC");
  const r = await query(conn,
    `INSERT INTO content_sources
       (source_id, unit_id, type, title, date, file_ref, drive_file_id, visibility, notes)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING *`,
    [source_id, unit_id, type, title,
     date || null, file_ref || null, drive_file_id || null,
     visibility || "intern", notes || null]
  );
  return r.rows[0];
}

async function listSources(conn, { unit_id, type, visibility, limit = 50 }) {
  let sql = "SELECT * FROM content_sources WHERE 1=1";
  const vals = []; let i = 1;
  if (unit_id)    { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (type)       { sql += ` AND type = $${i++}`; vals.push(type); }
  if (visibility) { sql += ` AND visibility = $${i++}`; vals.push(visibility); }
  sql += ` ORDER BY created_at DESC LIMIT $${i}`; vals.push(Number(limit) || 50);
  const r = await query(conn, sql, vals);
  return r.rows;
}

async function getSource(conn, { source_id }) {
  if (!source_id) throw new Error("source_id ist Pflichtfeld.");
  const r = await query(conn, "SELECT * FROM content_sources WHERE source_id = $1", [source_id]);
  return r.rows[0] || null;
}

async function updateSource(conn, { source_id, type, title, date, file_ref, drive_file_id, visibility, notes }) {
  if (!source_id) throw new Error("source_id ist Pflichtfeld.");
  const sets = []; const vals = []; let i = 1;
  if (type !== undefined)         { sets.push(`type = $${i++}`); vals.push(type); }
  if (title !== undefined)        { sets.push(`title = $${i++}`); vals.push(title); }
  if (date !== undefined)         { sets.push(`date = $${i++}`); vals.push(date); }
  if (file_ref !== undefined)     { sets.push(`file_ref = $${i++}`); vals.push(file_ref); }
  if (drive_file_id !== undefined){ sets.push(`drive_file_id = $${i++}`); vals.push(drive_file_id); }
  if (visibility !== undefined)   { sets.push(`visibility = $${i++}`); vals.push(visibility); }
  if (notes !== undefined)        { sets.push(`notes = $${i++}`); vals.push(notes); }
  if (!sets.length) throw new Error("Nichts zu aktualisieren.");
  sets.push(`updated_at = now()`);
  vals.push(source_id);
  const r = await query(conn, `UPDATE content_sources SET ${sets.join(", ")} WHERE source_id = $${i} RETURNING *`, vals);
  return r.rows[0] || null;
}

async function deleteSource(conn, { source_id }) {
  if (!source_id) throw new Error("source_id ist Pflichtfeld.");
  await query(conn, "DELETE FROM content_sources WHERE source_id = $1", [source_id]);
  return { deleted: true };
}

// Gibt alle Angles zurück, die aus einer bestimmten Quelle stammen.
async function listAnglesBySource(conn, { source_id, unit_id, batch_key, limit = 100 }) {
  let sql = "SELECT * FROM content_angles WHERE 1=1";
  const vals = []; let i = 1;
  if (source_id) { sql += ` AND source_id = $${i++}`; vals.push(source_id); }
  if (unit_id)   { sql += ` AND unit_id = $${i++}`; vals.push(unit_id); }
  if (batch_key) { sql += ` AND batch_key = $${i++}`; vals.push(batch_key); }
  sql += ` ORDER BY created_at DESC LIMIT $${i}`; vals.push(Number(limit) || 100);
  const r = await query(conn, sql, vals);
  return r.rows;
}

module.exports = {
  listKunden, findKunde, createKunde, updateKunde,
  listProjekte, createProjekt, updateProjekt,
  getKontext, setKontext, saveOutput,
  getFile,
  getSopRun, advanceSopStep,
  listDateien, registerDatei,
  createAngle, listAngles, getAngle, updateAngle,
  createContentItem, listContentItems, getContentItem, updateContentItem,
  setStrategy, getStrategy, listStrategies, deleteStrategy,
  createMedia, listMedia, getMedia, updateMedia, deleteMedia,
  createSource, listSources, getSource, updateSource, deleteSource, listAnglesBySource,
  rankAngle, listAngleRanking,
  setupSchema, debugAction,
};
