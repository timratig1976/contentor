"use strict";

/**
 * Content App Skill — schlanker HTTP-Client zur Content App API.
 * Ersetzt den monolithischen content-system Skill.
 * Nur 4 Actions: save, query, plan, produzieren.
 */

async function handler(runtime, args, config) {
  const apiUrl = (config.CONTENT_API_URL || "https://content.viminds.de").replace(/\/+$/, "");
  const apiToken = config.CONTENT_API_TOKEN || null;

  const headers = { "Content-Type": "application/json", "Accept": "application/json" };
  if (apiToken) headers["Authorization"] = `Bearer ${apiToken}`;

  async function api(method, path, body = null) {
    const opts = { method, headers };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(`${apiUrl}/api${path}`, opts);
    const data = await res.json();
    if (!res.ok) {
      throw new Error(`API Error ${res.status}: ${data.message || JSON.stringify(data)}`);
    }
    return data;
  }

  const { action } = args;

  try {
    switch (action) {
      // ── SAVE ──────────────────────────────────────────────
      case "save": {
        const { type, data: rawData, unit } = args;
        const data = typeof rawData === "string" ? JSON.parse(rawData) : rawData;
        if (unit && !data.unit) data.unit = unit;

        switch (type) {
          case "angle":
            return success(await api("POST", "/angles", data));
          case "source":
            return success(await api("POST", "/sources", data));
          case "idee":
            return success(await api("POST", "/content/idee", data));
          case "strategy":
            return success(await api("POST", "/strategy", {
              unit: data.unit || unit,
              key: args.strategy_key || data.key,
              content: data.content || data,
            }));
          default:
            return error(`Unbekannter save type: ${type}. Verfügbar: angle, source, idee, strategy`);
        }
      }

      // ── QUERY ─────────────────────────────────────────────
      case "query": {
        const { type, unit, batch_key, icp, status, strategy_key } = args;
        const params = new URLSearchParams();
        if (unit) params.set("unit", unit);
        if (batch_key) params.set("batch", batch_key);
        if (icp) params.set("icp", icp);
        if (status) params.set("status", status);
        const qs = params.toString() ? `?${params}` : "";

        switch (type) {
          case "angles":
            return success(await api("GET", `/angles${qs}`));
          case "sources":
            return success(await api("GET", `/sources${qs}`));
          case "content":
            return success(await api("GET", `/content${qs}`));
          case "overview":
            return success(await api("GET", `/content/overview${qs}`));
          case "batch_ranking":
            return success(await api("GET", `/angles/batch/${batch_key}${qs}`));
          case "strategy": {
            const sUnit = unit || "viscale";
            if (strategy_key) {
              return success(await api("GET", `/strategy/${sUnit}/${strategy_key}`));
            }
            return success(await api("GET", `/strategy/${sUnit}`));
          }
          case "media":
            return success(await api("GET", `/media${qs}`));
          default:
            return error(`Unbekannter query type: ${type}. Verfügbar: angles, sources, content, overview, batch_ranking, strategy, media`);
        }
      }

      // ── PLAN ──────────────────────────────────────────────
      case "plan": {
        const { mode, unit } = args;
        const params = new URLSearchParams();
        if (unit) params.set("unit", unit);
        if (mode === "kanban") params.set("mode", "kanban");
        const qs = params.toString() ? `?${params}` : "";

        if (mode === "write") {
          // For writing plan entries
          const data = typeof args.data === "string" ? JSON.parse(args.data) : args.data;
          if (unit && !data.unit) data.unit = unit;
          return success(await api("POST", "/redaktionsplan", data));
        }

        return success(await api("GET", `/redaktionsplan${qs}`));
      }

      // ── PRODUZIEREN ───────────────────────────────────────
      case "produzieren": {
        const { angle_id, format, unit } = args;
        if (!angle_id) return error("angle_id ist Pflicht für produzieren.");
        if (!format) return error("format ist Pflicht für produzieren.");

        return success(await api("POST", "/content/produzieren", {
          angle_id,
          format,
          unit: unit || undefined,
        }));
      }

      default:
        return error(`Unbekannte action: ${action}. Verfügbar: save, query, plan, produzieren`);
    }
  } catch (err) {
    return error(err.message);
  }
}

function success(data) {
  return {
    success: true,
    data: typeof data === "string" ? data : JSON.stringify(data, null, 2),
  };
}

function error(message) {
  return {
    success: false,
    data: `❌ ${message}`,
  };
}

module.exports = { handler };
