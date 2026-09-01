"use strict";

/**
 * Leichtgewichtiger DB-Client für den content-system Skill.
 * Ruft den context-db Skill über seine Actions-Funktionen direkt auf —
 * ohne HTTP-Roundtrip, da beide Skills im selben Node-Prozess laufen.
 * Falls DB_URL nicht konfiguriert ist, fällt jede Funktion still auf null zurück
 * (Drive-Pfad bleibt dann aktiv als Fallback).
 */

const INTERN_URL = "postgresql://aicontext:67omQO5jkD5C25AwZZ7XoAgipBXz6GFBqzDaMYhAS5j5Y5MloEUBJ2Z33SrX9cWb@postgres-context:5432/context_db";
const EXTERN_URL = "postgresql://aicontext:67omQO5jkD5C25AwZZ7XoAgipBXz6GFBqzDaMYhAS5j5Y5MloEUBJ2Z33SrX9cWb@ai.viminds.de:5433/context_db";

let _resolvedUrl = null;
let _schemaEnsured = false;

async function resolveDbUrl(configuredUrl) {
  if (_resolvedUrl) return _resolvedUrl;
  try {
    const dns = require("dns/promises");
    await dns.lookup("postgres-context", { timeout: 500 });
    _resolvedUrl = INTERN_URL;
  } catch (_) {
    _resolvedUrl = configuredUrl || EXTERN_URL;
  }
  return _resolvedUrl;
}

function getDbUrl(config = {}) {
  return config.dbUrl
    || config.DB_URL
    || process.env.CONTEXT_DB_URL
    || null;
}

/**
 * Gibt eine Verbindungs-URL zurück oder null wenn keine konfiguriert.
 * Der context-db-Skill teilt denselben Pool — kein neuer Client.
 * Stellt beim ersten DB-Zugriff sicher, dass das Schema existiert.
 */
async function getConn(config = {}) {
  const configured = getDbUrl(config);
  if (!configured) return null;
  const conn = await resolveDbUrl(configured);
  if (!_schemaEnsured) {
    try {
      const { setupSchema } = require("../../context-db/lib/actions");
      await setupSchema(conn);
      _schemaEnsured = true;
    } catch (err) {
      console.error("[content-system db-client] Schema-Init fehlgeschlagen:", err.message);
    }
  }
  return conn;
}

/**
 * Prüft ob eine DB-Verbindung konfiguriert ist.
 */
function isDbConfigured(config = {}) {
  return Boolean(getDbUrl(config));
}

// ── Angle-Operationen ─────────────────────────────────────────────

async function dbCreateAngle(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { createAngle } = require("../../context-db/lib/actions");
    return await createAngle(conn, params);
  } catch (err) {
    console.error("[content-system db-client] createAngle:", err.message);
    return null;
  }
}

async function dbListAngles(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listAngles } = require("../../context-db/lib/actions");
    return await listAngles(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listAngles:", err.message);
    return null;
  }
}

async function dbUpdateAngle(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { updateAngle } = require("../../context-db/lib/actions");
    return await updateAngle(conn, params);
  } catch (err) {
    console.error("[content-system db-client] updateAngle:", err.message);
    return null;
  }
}

// ── Content-Item-Operationen ──────────────────────────────────────

async function dbCreateContentItem(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { createContentItem } = require("../../context-db/lib/actions");
    return await createContentItem(conn, params);
  } catch (err) {
    console.error("[content-system db-client] createContentItem:", err.message);
    return null;
  }
}

async function dbListContentItems(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listContentItems } = require("../../context-db/lib/actions");
    return await listContentItems(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listContentItems:", err.message);
    return null;
  }
}

async function dbGetContentItem(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { getContentItem } = require("../../context-db/lib/actions");
    return await getContentItem(conn, params);
  } catch (err) {
    console.error("[content-system db-client] getContentItem:", err.message);
    return null;
  }
}

async function dbUpdateContentItem(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { updateContentItem } = require("../../context-db/lib/actions");
    return await updateContentItem(conn, params);
  } catch (err) {
    console.error("[content-system db-client] updateContentItem:", err.message);
    return null;
  }
}

// ── Strategy-Operationen ──────────────────────────────────────────

async function dbSetStrategy(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { setStrategy } = require("../../context-db/lib/actions");
    return await setStrategy(conn, params);
  } catch (err) {
    console.error("[content-system db-client] setStrategy:", err.message);
    return null;
  }
}

async function dbGetStrategy(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { getStrategy } = require("../../context-db/lib/actions");
    return await getStrategy(conn, params);
  } catch (err) {
    console.error("[content-system db-client] getStrategy:", err.message);
    return null;
  }
}

async function dbListStrategies(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listStrategies } = require("../../context-db/lib/actions");
    return await listStrategies(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listStrategies:", err.message);
    return null;
  }
}

async function dbDeleteStrategy(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { deleteStrategy } = require("../../context-db/lib/actions");
    return await deleteStrategy(conn, params);
  } catch (err) {
    console.error("[content-system db-client] deleteStrategy:", err.message);
    return null;
  }
}

// ── Media-Operationen ─────────────────────────────────────────────

async function dbCreateMedia(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { createMedia } = require("../../context-db/lib/actions");
    return await createMedia(conn, params);
  } catch (err) {
    console.error("[content-system db-client] createMedia:", err.message);
    return null;
  }
}

async function dbListMedia(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listMedia } = require("../../context-db/lib/actions");
    return await listMedia(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listMedia:", err.message);
    return null;
  }
}

async function dbGetMedia(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { getMedia } = require("../../context-db/lib/actions");
    return await getMedia(conn, params);
  } catch (err) {
    console.error("[content-system db-client] getMedia:", err.message);
    return null;
  }
}

async function dbUpdateMedia(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { updateMedia } = require("../../context-db/lib/actions");
    return await updateMedia(conn, params);
  } catch (err) {
    console.error("[content-system db-client] updateMedia:", err.message);
    return null;
  }
}

async function dbDeleteMedia(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { deleteMedia } = require("../../context-db/lib/actions");
    return await deleteMedia(conn, params);
  } catch (err) {
    console.error("[content-system db-client] deleteMedia:", err.message);
    return null;
  }
}

// ── Source-Operationen ────────────────────────────────────────────

async function dbCreateSource(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { createSource } = require("../../context-db/lib/actions");
    return await createSource(conn, params);
  } catch (err) {
    console.error("[content-system db-client] createSource:", err.message);
    return null;
  }
}

async function dbListSources(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listSources } = require("../../context-db/lib/actions");
    return await listSources(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listSources:", err.message);
    return null;
  }
}

async function dbGetSource(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { getSource } = require("../../context-db/lib/actions");
    return await getSource(conn, params);
  } catch (err) {
    console.error("[content-system db-client] getSource:", err.message);
    return null;
  }
}

async function dbUpdateSource(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { updateSource } = require("../../context-db/lib/actions");
    return await updateSource(conn, params);
  } catch (err) {
    console.error("[content-system db-client] updateSource:", err.message);
    return null;
  }
}

async function dbDeleteSource(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { deleteSource } = require("../../context-db/lib/actions");
    return await deleteSource(conn, params);
  } catch (err) {
    console.error("[content-system db-client] deleteSource:", err.message);
    return null;
  }
}

async function dbListAnglesBySource(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listAnglesBySource } = require("../../context-db/lib/actions");
    return await listAnglesBySource(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listAnglesBySource:", err.message);
    return null;
  }
}

async function dbRankAngle(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { rankAngle } = require("../../context-db/lib/actions");
    return await rankAngle(conn, params);
  } catch (err) {
    console.error("[content-system db-client] rankAngle:", err.message);
    return null;
  }
}

async function dbListAngleRanking(config, params) {
  const conn = await getConn(config);
  if (!conn) return null;
  try {
    const { listAngleRanking } = require("../../context-db/lib/actions");
    return await listAngleRanking(conn, params);
  } catch (err) {
    console.error("[content-system db-client] listAngleRanking:", err.message);
    return null;
  }
}

module.exports = {
  isDbConfigured,
  dbCreateAngle,
  dbListAngles,
  dbUpdateAngle,
  dbCreateContentItem,
  dbListContentItems,
  dbGetContentItem,
  dbUpdateContentItem,
  dbSetStrategy,
  dbGetStrategy,
  dbListStrategies,
  dbDeleteStrategy,
  dbCreateMedia,
  dbListMedia,
  dbGetMedia,
  dbUpdateMedia,
  dbDeleteMedia,
  dbCreateSource,
  dbListSources,
  dbGetSource,
  dbUpdateSource,
  dbDeleteSource,
  dbListAnglesBySource,
  dbRankAngle,
  dbListAngleRanking,
};
