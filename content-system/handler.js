"use strict";

// Cache-Bust: lib/-Module bei jedem Skill-Reload frisch einlesen (siehe shared/hot-reload.js)
require("../shared/hot-reload").bustLocalModuleCache(__dirname);

const { readConfig } = require("../shared/config");
const { errorResponse } = require("../shared/response");
const { normalizeError } = require("../shared/errors");
const { logExecution } = require("../shared/logging");
const { validateInput } = require("./lib/validation");
const { buildApprovalDescription, requiresApproval } = require("./lib/approval");
const { runAction } = require("./lib/operations");
const { UNITS, DEFAULT_UNIT } = require("./lib/constants");

module.exports.runtime = {
  handler: async function (rawArgs = {}) {
    const startedAt = Date.now();
    const skillName = this.config?.name || "content-system";
    const skillVersion = this.config?.version || "unknown";
    let action = rawArgs?.action || "unknown";

    try {
      const args = validateInput(rawArgs);
      action = args.action;
      const unit = args.unit || DEFAULT_UNIT;
      const unitConfig = UNITS[unit] || UNITS[DEFAULT_UNIT];
      const config = {
        unit,
        driveScriptUrl: readConfig(this, "DRIVE_SCRIPT_URL"),
        driveToken: readConfig(this, "DRIVE_TOKEN"),
        vimindsProductsRootId: readConfig(this, "VIMINDS_PRODUCTS_ROOT_ID", "114CTBTNRUG-wIcyc08RdgREN-OlCdosh"),
        contentSystemRootId: readConfig(this, "CONTENT_SYSTEM_ROOT_ID", unitConfig.contentSystemRootId || null),
        dbUrl: readConfig(this, "DB_URL", null),
      };

      if (requiresApproval(action, args) && typeof this.requestToolApproval === "function") {
        const approval = await this.requestToolApproval({
          description: buildApprovalDescription(action, args),
          payload: { action, params: args },
        });
        if (!approval?.approved) {
          throw Object.assign(new Error(approval?.message || "Aktion wurde nicht genehmigt."), {
            code: "APPROVAL_DENIED",
            details: { action },
          });
        }
      }

      const result = await runAction(this, args, config);
      logExecution(this, {
        skillName,
        skillVersion,
        action,
        input: rawArgs,
        startedAt,
        status: result.error ? "partial" : "success",
        errorCode: result.error?.code || null,
      });
      return result;
    } catch (error) {
      const normalized = normalizeError(error);
      this.introspect?.(`❌ ${skillName} ${action}: ${normalized.message}`);
      logExecution(this, {
        skillName,
        skillVersion,
        action,
        input: rawArgs,
        startedAt,
        status: "error",
        errorCode: normalized.code,
      });
      return errorResponse({
        code: normalized.code,
        message: normalized.message,
        details: normalized.details,
        context: { action },
      });
    }
  },
};
