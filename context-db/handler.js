"use strict";

require("../shared/hot-reload").bustLocalModuleCache(__dirname);

const actions = require("./lib/actions");

module.exports.runtime = {
  handler: async function (args = {}) {
    const action = args.action || "debug";
    const conn = this.runtimeArgs?.DB_URL
      || this.config?.setup_args?.DB_URL?.value
      || process.env.CONTEXT_DB_URL
      // Auto-Fallback: intern wenn postgres-context auflösbar, sonst extern
      || "postgresql://aicontext:67omQO5jkD5C25AwZZ7XoAgipBXz6GFBqzDaMYhAS5j5Y5MloEUBJ2Z33SrX9cWb@ai.viminds.de:5433/context_db";

    if (!conn) return JSON.stringify({ error: "DB_URL nicht konfiguriert." });

    this.introspect?.(`🗄 context-db: ${action} ...`);

    try {
      let result;
      switch (action) {

        // ── Schema ──────────────────────────────────────────────────
        case "setup_schema":
          result = await actions.setupSchema(conn);
          break;

        case "debug":
          result = await actions.debugAction(conn);
          break;

        // ── Kunden ───────────────────────────────────────────────────
        case "list_kunden":
          result = await actions.listKunden(conn);
          break;

        case "find_kunde":
          result = await actions.findKunde(conn, args);
          break;

        case "create_kunde":
          result = await actions.createKunde(conn, args);
          break;

        case "update_kunde":
          result = await actions.updateKunde(conn, args);
          break;

        // ── Projekte ─────────────────────────────────────────────────
        case "list_projekte":
          result = await actions.listProjekte(conn, args);
          break;

        case "create_projekt":
          result = await actions.createProjekt(conn, args);
          break;

        case "update_projekt":
          result = await actions.updateProjekt(conn, args);
          break;

        case "get_integrations": {
          // Gibt nur integrations-Feld eines Projekts zurück
          const projRows = await actions.listProjekte(conn, { kunden_id: args.kunden_id, status: null });
          const proj = args.id
            ? projRows.find((p) => p.id === Number(args.id))
            : projRows[0];
          result = proj ? { projekt_id: proj.id, integrations: proj.integrations || {} } : { integrations: {} };
          break;
        }

        case "set_integrations": {
          // Merged neue Integration-Keys in bestehende (nicht überschreiben)
          if (!args.id) throw new Error("id ist Pflicht für set_integrations.");
          const projRows2 = await actions.listProjekte(conn, { kunden_id: null, status: null });
          const proj2 = projRows2.find((p) => p.id === Number(args.id));
          const current = proj2?.integrations || {};
          const newIntegrations = { ...current, ...(args.integrations || {}) };
          result = await actions.updateProjekt(conn, { id: Number(args.id), integrations: newIntegrations });
          break;
        }

        // ── Kontext ──────────────────────────────────────────────────
        case "get_kontext":
          result = await actions.getKontext(conn, args);
          break;

        case "set_kontext":
          result = await actions.setKontext(conn, args);
          break;

        case "save_output":
          result = await actions.saveOutput(conn, args);
          break;

        case "get_file": {
          // Lädt kontext_item direkt als Download-HTML — ohne LLM-Transfer des Inhalts
          const fileResult = await actions.getFile(conn, args);
          if (!fileResult) return JSON.stringify({ success: false, action, error: "Datei nicht gefunden." });
          return JSON.stringify({ success: true, action, data: fileResult, html: fileResult.download_html });
        }

        // ── SOP-Runs ─────────────────────────────────────────────────
        case "get_sop_run":
          result = await actions.getSopRun(conn, args);
          break;

        case "advance_sop_step":
          result = await actions.advanceSopStep(conn, args);
          break;

        // ── Dateien ──────────────────────────────────────────────────
        case "list_dateien":
          result = await actions.listDateien(conn, args);
          break;

        case "register_datei":
          result = await actions.registerDatei(conn, args);
          break;

        // ── Content Angles ───────────────────────────────────────────
        case "create_angle":
          result = await actions.createAngle(conn, args);
          break;

        case "list_angles":
          result = await actions.listAngles(conn, args);
          break;

        case "get_angle":
          result = await actions.getAngle(conn, args);
          break;

        case "update_angle":
          result = await actions.updateAngle(conn, args);
          break;

        // ── Content Items ─────────────────────────────────────────────
        case "create_content_item":
          result = await actions.createContentItem(conn, args);
          break;

        case "list_content_items":
          result = await actions.listContentItems(conn, args);
          break;

        case "get_content_item":
          result = await actions.getContentItem(conn, args);
          break;

        case "update_content_item":
          result = await actions.updateContentItem(conn, args);
          break;

        // ── Content Strategy ──────────────────────────────────────────
        case "set_strategy":
          result = await actions.setStrategy(conn, args);
          break;

        case "get_strategy":
          result = await actions.getStrategy(conn, args);
          break;

        case "list_strategies":
          result = await actions.listStrategies(conn, args);
          break;

        case "delete_strategy":
          result = await actions.deleteStrategy(conn, args);
          break;

        // ── Content Media ────────────────────────────────────────────
        case "create_media":
          result = await actions.createMedia(conn, args);
          break;

        case "list_media":
          result = await actions.listMedia(conn, args);
          break;

        case "get_media":
          result = await actions.getMedia(conn, args);
          break;

        case "update_media":
          result = await actions.updateMedia(conn, args);
          break;

        case "delete_media":
          result = await actions.deleteMedia(conn, args);
          break;

        default:
          return JSON.stringify({ error: `Unbekannte Action: ${action}` });
      }

      return JSON.stringify({ success: true, action, data: result });

    } catch (err) {
      this.introspect?.(`❌ context-db ${action}: ${err.message}`);
      return JSON.stringify({ success: false, action, error: err.message });
    }
  },
};
