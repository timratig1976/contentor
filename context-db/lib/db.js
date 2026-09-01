"use strict";

const { Pool } = require("pg");

// URL-Mapping: extern → intern (automatisch auf Server)
const INTERN_URL = "postgresql://aicontext:67omQO5jkD5C25AwZZ7XoAgipBXz6GFBqzDaMYhAS5j5Y5MloEUBJ2Z33SrX9cWb@postgres-context:5432/context_db";
const EXTERN_URL = "postgresql://aicontext:67omQO5jkD5C25AwZZ7XoAgipBXz6GFBqzDaMYhAS5j5Y5MloEUBJ2Z33SrX9cWb@ai.viminds.de:5433/context_db";

let _pool = null;
let _resolvedUrl = null;

async function resolveUrl(connectionString) {
  if (_resolvedUrl) return _resolvedUrl;

  // Schnell prüfen ob postgres-context (interner Docker-Host) auflösbar ist
  try {
    const dns = require("dns/promises");
    await dns.lookup("postgres-context", { timeout: 500 });
    // Intern erreichbar → interne URL nutzen (auch wenn externe konfiguriert)
    _resolvedUrl = INTERN_URL;
    return _resolvedUrl;
  } catch (_) {
    // Nicht auflösbar → externe URL
    _resolvedUrl = connectionString || EXTERN_URL;
    return _resolvedUrl;
  }
}

async function getPool(connectionString) {
  if (_pool) return _pool;
  const url = await resolveUrl(connectionString);
  _pool = new Pool({
    connectionString: url,
    connectionTimeoutMillis: 5000,
    idleTimeoutMillis: 10000,
    max: 3,
  });
  return _pool;
}

async function query(connectionString, sql, params = []) {
  const pool = await getPool(connectionString);
  const client = await pool.connect();
  try {
    const result = await client.query(sql, params);
    return result;
  } finally {
    client.release();
  }
}

module.exports = { query };
