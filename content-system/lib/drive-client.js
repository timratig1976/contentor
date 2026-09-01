"use strict";

const { ERROR_CODES, SkillError } = require("../../shared/errors");
const { callAppsScript, throwForRemoteError, extractRemoteData } = require("../../drive-automation/lib/apps-script-client");

function ensureDriveConfig(config) {
  if (!config.driveScriptUrl || !config.driveToken) {
    throw new SkillError(ERROR_CODES.CONFIGURATION_ERROR, "DRIVE_SCRIPT_URL oder DRIVE_TOKEN fehlt für den Content-System-Skill.", {
      drive_script_url_configured: Boolean(config.driveScriptUrl),
      drive_token_configured: Boolean(config.driveToken),
    });
  }
}

// Write-Actions die baseFolderId brauchen (isAllowed-Check im Apps Script)
const WRITE_ACTIONS = new Set([
  "createFolder", "createDoc", "createSheet", "createPresentation",
  "writeDoc", "appendToDoc", "appendBlock", "replaceText",
  "writeSheet", "clearSheet", "appendRow", "writeCell", "formatSheet",
  "uploadFile", "importFileFromUrl", "moveFile", "deleteFile",
  "copyFile", "rename", "setFileDescription", "shareFile", "unshareFile",
  "setPublicAccess", "batchCreateFolders", "batchCreateDocs",
  "insertLink", "insertTable", "insertImage", "insertRule",
  "insertPageBreak", "formatText", "fillTemplate",
]);

async function driveCall(config, action, params) {
  ensureDriveConfig(config);
  // Bei Write-Actions: baseFolderId mitsenden damit isAllowed() im Apps Script passt.
  // Der Content System Root Folder ist der erlaubte Schreibbereich.
  const baseFolderId =
    config.contentSystemRootId ||
    config.vimindsProductsRootId ||
    null;
  const enrichedParams =
    WRITE_ACTIONS.has(action) && baseFolderId && !params?.baseFolderId
      ? { ...params, baseFolderId }
      : params;

  const result = await callAppsScript({
    scriptUrl: config.driveScriptUrl,
    token: config.driveToken,
    action,
    params: enrichedParams,
  });
  throwForRemoteError(result, action);
  return result;
}

async function driveData(config, action, params) {
  const result = await driveCall(config, action, params);
  return extractRemoteData(result);
}

module.exports = { ensureDriveConfig, driveCall, driveData };
