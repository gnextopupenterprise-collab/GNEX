const REGISTRATION_SHEET_NAME = "Pendaftaran";
const SCHEDULE_SHEET_NAME = "Sheet1";
const HISTORY_SHEET_NAME = "History match";
const TIER_SHEET_NAME = "Tier";
const PAYMENT_FOLDER_NAME = "GNEX LAGA PAYMENT SS";
const TEAM_LOGO_FOLDER_ID = "1eBRyKAlddLwbvKPK1wm2AIKB0NxggN0K";
const TEAM_LOGO_FOLDER_NAME = "GNEX LAGA";
const SPREADSHEET_ID = "1msJE-sP71Oo7aQQ4mwtEsLNd2k65DvjZYtk0_wjZ9xA";
const SPREADSHEET_URL = "https://docs.google.com/spreadsheets/d/1msJE-sP71Oo7aQQ4mwtEsLNd2k65DvjZYtk0_wjZ9xA/edit";
const DEBUG_SHEET_NAME = "Debug";

function doPost(e) {
  const lock = LockService.getScriptLock();
  executionLog_("doPost:start", {
    hasEvent: Boolean(e),
    hasPostData: Boolean(e && e.postData),
    postDataType: e && e.postData ? e.postData.type : "",
    rawLength: e && e.postData && e.postData.contents ? e.postData.contents.length : 0
  });
  lock.waitLock(30000);

  try {
    const ss = openSpreadsheet_();
    executionLog_("doPost:spreadsheet-opened", {
      spreadsheetId: SPREADSHEET_ID,
      spreadsheetUrl: SPREADSHEET_URL,
      spreadsheetName: ss.getName()
    });

    const sheet = getOrCreateSheet_(ss, REGISTRATION_SHEET_NAME, [
      "Wakil",
      "Nombor Telefon",
      "Team name",
      "Pembayaran",
      "Tarikh",
      "Masa",
      "Slot"
    ]);
    executionLog_("doPost:registration-sheet-ready", {
      sheetName: sheet.getName(),
      lastRow: sheet.getLastRow(),
      lastColumn: sheet.getLastColumn()
    });

    const payload = getPayload_(e);
    const wakil = String(payload.wakil || "").trim();
    const phoneNumber = String(payload.phone_number || "").trim();
    const teamName = String(payload.team_name || "").trim();
    let paymentUrl = "";

    debugLog_(ss, "doPost payload", {
      wakil,
      phoneNumber,
      teamName,
      hasPayment: Boolean(payload.payment_proof && payload.payment_proof.data),
      rawLength: e && e.postData && e.postData.contents ? e.postData.contents.length : 0,
      parameters: e && e.parameter ? e.parameter : {}
    });
    executionLog_("doPost:payload-parsed", {
      wakil,
      phoneNumber,
      teamName,
      hasPayment: Boolean(payload.payment_proof && payload.payment_proof.data),
      paymentName: payload.payment_proof ? payload.payment_proof.name : "",
      paymentType: payload.payment_proof ? payload.payment_proof.type : ""
    });

    if (!wakil || !phoneNumber || !teamName) {
      throw new Error("Payload kosong atau tidak lengkap. Wakil, nombor telefon dan Team name wajib ada.");
    }

    if (payload.payment_proof && payload.payment_proof.data) {
      executionLog_("doPost:payment-upload-start", {
        fileName: payload.payment_proof.name || "payment-proof.png",
        fileType: payload.payment_proof.type || "image/png"
      });

      const folder = getOrCreateFolder_(PAYMENT_FOLDER_NAME);
      const proof = payload.payment_proof;
      const blob = Utilities.newBlob(
        Utilities.base64Decode(proof.data),
        proof.type || "image/png",
        proof.name || "payment-proof.png"
      );
      const safeTeam = teamName || "team";
      blob.setName(`${new Date().toISOString()}-${safeTeam}-${blob.getName()}`);
      paymentUrl = folder.createFile(blob).getUrl();
      executionLog_("doPost:payment-upload-done", { paymentUrl });
    }

    const nextRow = findNextEmptyRegistrationRow_(sheet);
    executionLog_("doPost:write-start", { nextRow });
    const headers = getHeaders_(sheet);
    sheet.getRange(nextRow, headers["wakil"]).setValue(wakil);
    sheet.getRange(nextRow, headers["nombor telefon"]).setValue(phoneNumber);
    sheet.getRange(nextRow, headers["team name"]).setValue(teamName);
    sheet.getRange(nextRow, headers["pembayaran"]).setValue(paymentUrl);
    sheet.getRange(nextRow, headers["tarikh"]).setValue("");
    sheet.getRange(nextRow, headers["masa"]).setValue("");
    sheet.getRange(nextRow, headers["slot"]).setValue(false);
    executionLog_("doPost:write-done", {
      nextRow,
      wakil,
      phoneNumber,
      teamName,
      paymentUrl
    });

    return ContentService
      .createTextOutput(JSON.stringify({ ok: true }))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (error) {
    executionLog_("doPost:error", {
      message: error.message,
      stack: error.stack
    });
    try {
      debugLog_(openSpreadsheet_(), "doPost error", { error: error.message });
    } catch (logError) {
      // Ignore logging failure so the endpoint can still return JSON.
    }

    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: error.message }))
      .setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
    executionLog_("doPost:lock-released");
  }
}

function doGet() {
  return ContentService
    .createTextOutput(JSON.stringify({ ok: true, message: "GNEX Laga registration endpoint is live." }))
    .setMimeType(ContentService.MimeType.JSON);
}

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu("GNEX Laga")
    .addItem("Update Logo Links", "updateScheduleLogoLinks")
    .addToUi();
}

function onEdit(e) {
  if (!e || !e.range) return;

  const editedSheet = e.range.getSheet();
  if (isScheduleSheet_(editedSheet)) {
    updateScheduleLogoLinksOnEdit_(e);
    appendHistoryOnEdit_(e);
    return;
  }

  if (editedSheet.getName() !== REGISTRATION_SHEET_NAME) return;
  if (e.range.getRow() === 1) return;

  const headers = getHeaders_(editedSheet);
  const slotCol = headers["slot"];
  if (!slotCol || e.range.getColumn() !== slotCol) return;

  const isChecked = String(e.value).toUpperCase() === "TRUE";
  if (!isChecked) return;

  confirmSlotToSchedule_(editedSheet, e.range.getRow(), headers);
}

function updateScheduleLogoLinks() {
  const ss = openSpreadsheet_();
  const sheet = getScheduleSheet_(ss);
  if (!sheet) throw new Error("Schedule sheet tidak jumpa.");

  const config = getScheduleLogoColumnConfig_(sheet);
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) {
    SpreadsheetApp.getActive().toast("Schedule belum ada row untuk update logo.", "GNEX Laga", 5);
    return;
  }

  let updated = 0;
  for (let row = 2; row <= lastRow; row++) {
    updated += updateScheduleLogoLinksForRow_(sheet, row, config);
  }

  SpreadsheetApp.getActive().toast(`Logo links updated untuk ${updated} cell.`, "GNEX Laga", 5);
}

function updateScheduleLogoLinksOnEdit_(e) {
  if (e.range.getRow() === 1) return;

  const sheet = e.range.getSheet();
  const config = getScheduleLogoColumnConfig_(sheet);
  const editedColumn = e.range.getColumn();
  const logoNameColumns = [config.team1LogoNameCol, config.team2LogoNameCol].filter(Boolean);
  if (!logoNameColumns.includes(editedColumn)) return;

  const startRow = e.range.getRow();
  const rowCount = e.range.getNumRows();
  let updated = 0;
  for (let offset = 0; offset < rowCount; offset++) {
    updated += updateScheduleLogoLinksForRow_(sheet, startRow + offset, config);
  }
  if (updated) SpreadsheetApp.getActive().toast("Logo link jadual updated.", "GNEX Laga", 3);
}

function appendHistoryOnEdit_(e) {
  if (e.range.getRow() === 1) return;

  const sheet = e.range.getSheet();
  const headers = getHeaders_(sheet);
  const historyCol = headers["history"] || headers["history match"];
  if (!historyCol || e.range.getColumn() !== historyCol) return;

  const isChecked = String(e.value).toUpperCase() === "TRUE";
  if (!isChecked) return;

  appendScheduleRowToHistory_(sheet, e.range.getRow(), headers);
}

function appendScheduleRowToHistory_(scheduleSheet, row, scheduleHeaders) {
  const ss = openSpreadsheet_();
  const historySheet = getOrCreateSheet_(ss, HISTORY_SHEET_NAME, [
    "Laga V?",
    "Team 1",
    "Point 1",
    "Team 2",
    "Point 2",
    "Link 1",
    "Link 2",
    "Tier 1",
    "Tier 2"
  ]);
  const historyHeaders = getHeaders_(historySheet);
  const scheduleLogoConfig = getScheduleLogoColumnConfig_(scheduleSheet);
  const tierMap = buildTierMap_(ss);

  const team1Col = scheduleHeaders["team 1"] || scheduleHeaders["team1"] || scheduleHeaders["team a"];
  const team2Col = scheduleHeaders["team 2"] || scheduleHeaders["team2"] || scheduleHeaders["team b"];
  const team1 = getCellValue_(scheduleSheet, row, team1Col);
  const team2 = getCellValue_(scheduleSheet, row, team2Col);
  if (!team1 || !team2) {
    SpreadsheetApp.getActive().toast("Team 1/Team 2 kosong. Tak boleh hantar ke History.", "GNEX Laga", 5);
    return;
  }

  const link1 = getCellValue_(scheduleSheet, row, scheduleLogoConfig.team1LogoLinkCol);
  const link2 = getCellValue_(scheduleSheet, row, scheduleLogoConfig.team2LogoLinkCol);
  const existingRow = findHistoryRow_(historySheet, historyHeaders, team1, team2);
  const targetRow = existingRow || historySheet.getLastRow() + 1;
  const rowValues = new Array(Math.max(historySheet.getLastColumn(), 9)).fill("");

  setHistoryValue_(rowValues, historyHeaders, "team 1", team1);
  setHistoryValue_(rowValues, historyHeaders, "team 2", team2);
  setHistoryValue_(rowValues, historyHeaders, "link 1", link1);
  setHistoryValue_(rowValues, historyHeaders, "link 2", link2);
  setHistoryValue_(rowValues, historyHeaders, "tier 1", tierMap[normalizeTeamKey_(team1)] || "NO TIER");
  setHistoryValue_(rowValues, historyHeaders, "tier 2", tierMap[normalizeTeamKey_(team2)] || "NO TIER");

  if (!existingRow) {
    setHistoryValue_(rowValues, historyHeaders, "laga v?", "");
    setHistoryValue_(rowValues, historyHeaders, "point 1", "");
    setHistoryValue_(rowValues, historyHeaders, "point 2", "");
    historySheet.getRange(targetRow, 1, 1, rowValues.length).setValues([rowValues]);
  } else {
    const current = historySheet.getRange(targetRow, 1, 1, rowValues.length).getValues()[0];
    rowValues.forEach((value, index) => {
      if (value !== "") current[index] = value;
    });
    historySheet.getRange(targetRow, 1, 1, current.length).setValues([current]);
  }

  SpreadsheetApp.getActive().toast("Match dihantar ke History match.", "GNEX Laga", 4);
}

function setHistoryValue_(rowValues, headers, header, value) {
  const col = headers[header];
  if (col) rowValues[col - 1] = value;
}

function findHistoryRow_(historySheet, headers, team1, team2) {
  const team1Col = headers["team 1"] || headers["team1"];
  const team2Col = headers["team 2"] || headers["team2"];
  if (!team1Col || !team2Col || historySheet.getLastRow() < 2) return 0;

  const values = historySheet.getRange(2, 1, historySheet.getLastRow() - 1, historySheet.getLastColumn()).getValues();
  const team1Key = normalizeTeamKey_(team1);
  const team2Key = normalizeTeamKey_(team2);
  const index = values.findIndex(row => normalizeTeamKey_(row[team1Col - 1]) === team1Key && normalizeTeamKey_(row[team2Col - 1]) === team2Key);
  return index >= 0 ? index + 2 : 0;
}

function buildTierMap_(ss) {
  const sheet = ss.getSheetByName(TIER_SHEET_NAME);
  const tierMap = {};
  if (!sheet || sheet.getLastRow() < 2) return tierMap;

  const values = sheet.getRange(1, 1, sheet.getLastRow(), sheet.getLastColumn()).getValues();
  values[0].forEach((header, columnIndex) => {
    const tier = String(header || "").trim();
    if (!tier) return;
    for (let row = 1; row < values.length; row++) {
      const team = String(values[row][columnIndex] || "").trim();
      const cleanTeam = cleanTierTeamName_(team);
      if (cleanTeam) tierMap[normalizeTeamKey_(cleanTeam)] = tier;
    }
  });
  return tierMap;
}

function updateScheduleLogoLinksForRow_(sheet, row, config) {
  const team1LogoName = getCellValue_(sheet, row, config.team1LogoNameCol);
  const team2LogoName = getCellValue_(sheet, row, config.team2LogoNameCol);
  const team1LogoLink = resolveTeamLogoUrl_(team1LogoName);
  const team2LogoLink = resolveTeamLogoUrl_(team2LogoName);
  let updated = 0;

  if (config.team1LogoLinkCol) {
    sheet.getRange(row, config.team1LogoLinkCol).setValue(team1LogoLink);
    if (team1LogoLink) updated++;
  }

  if (config.team2LogoLinkCol) {
    sheet.getRange(row, config.team2LogoLinkCol).setValue(team2LogoLink);
    if (team2LogoLink) updated++;
  }

  return updated;
}

function getScheduleLogoColumnConfig_(sheet) {
  const headers = getHeaders_(sheet);
  return {
    team1LogoNameCol: headers["team 1 logo filename"] || headers["team1 logo filename"] || headers["team a logo filename"] || headers["logo 1 filename"] || headers["logo1 filename"] || headers["team 1 logo file"] || headers["logo 1"] || headers["logo1"] || 5,
    team2LogoNameCol: headers["team 2 logo filename"] || headers["team2 logo filename"] || headers["team b logo filename"] || headers["logo 2 filename"] || headers["logo2 filename"] || headers["team 2 logo file"] || headers["logo 2"] || headers["logo2"] || 6,
    team1LogoLinkCol: headers["team 1 logo link"] || headers["team1 logo link"] || headers["team a logo link"] || headers["logo 1 link"] || headers["logo1 link"] || 7,
    team2LogoLinkCol: headers["team 2 logo link"] || headers["team2 logo link"] || headers["team b logo link"] || headers["logo 2 link"] || headers["logo2 link"] || 8
  };
}

function resolveTeamLogoUrl_(fileName) {
  const name = String(fileName || "").trim();
  if (!name) return "";

  const folder = getTeamLogoFolder_();
  if (!folder) throw new Error("Folder logo team tidak jumpa. Isi TEAM_LOGO_FOLDER_ID atau betulkan TEAM_LOGO_FOLDER_NAME.");

  const files = folder.getFilesByName(name);
  if (!files.hasNext()) return "";

  const file = files.next();
  try {
    file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  } catch (error) {
    executionLog_("logo:set-sharing-failed", { fileName: name, message: error.message });
  }
  return makeDriveImageUrl_(file.getId());
}

function getTeamLogoFolder_() {
  if (TEAM_LOGO_FOLDER_ID) return DriveApp.getFolderById(TEAM_LOGO_FOLDER_ID);
  const folders = DriveApp.getFoldersByName(TEAM_LOGO_FOLDER_NAME);
  return folders.hasNext() ? folders.next() : null;
}

function makeDriveImageUrl_(fileId) {
  return `https://drive.google.com/thumbnail?id=${fileId}&sz=w256`;
}

function getScheduleSheet_(ss) {
  return ss.getSheetByName(SCHEDULE_SHEET_NAME) || ss.getSheetByName("JADUAL LAGA");
}

function isScheduleSheet_(sheet) {
  return sheet.getName() === SCHEDULE_SHEET_NAME || sheet.getName() === "JADUAL LAGA";
}

function confirmSlotToSchedule_(registrationSheet, row, headers) {
  const ss = openSpreadsheet_();
  const scheduleSheet = getScheduleSheet_(ss);
  if (!scheduleSheet) throw new Error("Schedule sheet tidak jumpa.");

  const teamName = getCellValue_(registrationSheet, row, headers["team name"]);
  const matchDate = getCellValue_(registrationSheet, row, headers["tarikh"]);
  const matchTime = getCellValue_(registrationSheet, row, headers["masa"]);

  if (!teamName || !matchDate || !matchTime) {
    SpreadsheetApp.getActive().toast("Isi Team name, Tarikh dan Masa dulu sebelum tick Slot.", "GNEX Laga", 5);
    return;
  }

  const scheduleHeaders = getHeaders_(scheduleSheet);
  const dateCol = scheduleHeaders["date"] || scheduleHeaders["tarikh"];
  const timeCol = scheduleHeaders["time"] || scheduleHeaders["masa"];
  const team1Col = scheduleHeaders["team 1"] || scheduleHeaders["team1"];
  const team2Col = scheduleHeaders["team 2"] || scheduleHeaders["team2"];

  if (!dateCol || !timeCol || !team1Col || !team2Col) {
    throw new Error("Header schedule mesti ada DATE, TIME, TEAM 1, TEAM 2.");
  }

  const scheduleLastRow = scheduleSheet.getLastRow();
  if (scheduleLastRow < 2) {
    throw new Error("Schedule belum ada row tarikh/masa.");
  }

  const dateKey = normalizeDate_(matchDate);
  const timeKey = normalizeTime_(matchTime);

  for (let r = 2; r <= scheduleLastRow; r++) {
    const rowDateKey = normalizeDate_(scheduleSheet.getRange(r, dateCol).getValue());
    const rowTimeKey = normalizeTime_(scheduleSheet.getRange(r, timeCol).getValue());
    if (rowDateKey !== dateKey || rowTimeKey !== timeKey) continue;

    const team1 = String(scheduleSheet.getRange(r, team1Col).getValue() || "").trim();
    const team2 = String(scheduleSheet.getRange(r, team2Col).getValue() || "").trim();

    if (sameTeam_(team1, teamName) || sameTeam_(team2, teamName)) {
      SpreadsheetApp.getActive().toast("Team ini sudah ada dalam slot jadual.", "GNEX Laga", 5);
      return;
    }

    if (isEmptySlot_(team1)) {
      scheduleSheet.getRange(r, team1Col).setValue(teamName);
      SpreadsheetApp.getActive().toast("Team masuk TEAM 1.", "GNEX Laga", 5);
      return;
    }

    if (isEmptySlot_(team2)) {
      scheduleSheet.getRange(r, team2Col).setValue(teamName);
      SpreadsheetApp.getActive().toast("Team masuk TEAM 2.", "GNEX Laga", 5);
      return;
    }

    SpreadsheetApp.getActive().toast("Slot tarikh/masa ini sudah penuh.", "GNEX Laga", 5);
    return;
  }

  SpreadsheetApp.getActive().toast("Tarikh dan masa tidak jumpa dalam JADUAL LAGA.", "GNEX Laga", 5);
}

function getHeaders_(sheet) {
  const values = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  const map = {};
  values.forEach((header, index) => {
    const key = String(header || "").trim().toLowerCase();
    if (key) map[key] = index + 1;
  });
  return map;
}

function openSpreadsheet_() {
  return SpreadsheetApp.openByUrl(SPREADSHEET_URL);
}

function getPayload_(e) {
  executionLog_("getPayload:start", {
    hasPostData: Boolean(e && e.postData),
    rawLength: e && e.postData && e.postData.contents ? e.postData.contents.length : 0,
    hasParameter: Boolean(e && e.parameter && Object.keys(e.parameter).length)
  });

  if (e && e.postData && e.postData.contents) {
    try {
      const payload = JSON.parse(e.postData.contents);
      executionLog_("getPayload:json-ok", {
        keys: Object.keys(payload || {})
      });
      return payload;
    } catch (error) {
      executionLog_("getPayload:json-failed", { message: error.message });
      // Fallback for older form posts.
    }
  }
  executionLog_("getPayload:parameter-fallback", {
    keys: e && e.parameter ? Object.keys(e.parameter) : []
  });
  return (e && e.parameter) || {};
}

function getCellValue_(sheet, row, col) {
  if (!col) return "";
  return sheet.getRange(row, col).getValue();
}

function getOrCreateSheet_(ss, name, headers) {
  const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
  } else {
    const existingHeaders = getHeaders_(sheet);
    headers.forEach(header => {
      const key = String(header).trim().toLowerCase();
      if (!existingHeaders[key]) {
        sheet.getRange(1, sheet.getLastColumn() + 1).setValue(header);
        existingHeaders[key] = sheet.getLastColumn();
      }
    });
  }
  return sheet;
}

function findNextEmptyRegistrationRow_(sheet) {
  const lastRow = Math.max(sheet.getLastRow(), 2);
  const headers = getHeaders_(sheet);
  const wakilValues = sheet.getRange(2, headers["wakil"], lastRow - 1, 1).getValues();
  const teamValues = sheet.getRange(2, headers["team name"], lastRow - 1, 1).getValues();
  const emptyIndex = wakilValues.findIndex((row, index) => {
    const wakil = String(row[0] || "").trim();
    const teamName = String(teamValues[index][0] || "").trim();
    return !wakil && !teamName;
  });

  if (emptyIndex >= 0) return emptyIndex + 2;
  return lastRow + 1;
}

function getOrCreateFolder_(name) {
  const folders = DriveApp.getFoldersByName(name);
  if (folders.hasNext()) return folders.next();
  return DriveApp.createFolder(name);
}

function debugLog_(ss, action, data) {
  const sheet = getOrCreateSheet_(ss, DEBUG_SHEET_NAME, ["Time", "Action", "Data"]);
  sheet.appendRow([
    new Date(),
    action,
    typeof data === "string" ? data : safeStringify_(data)
  ]);
}

function executionLog_(message, data) {
  const line = data === undefined ? message : `${message} ${safeStringify_(data)}`;
  console.log(line);
  Logger.log(line);
}

function safeStringify_(data) {
  try {
    return JSON.stringify(data);
  } catch (error) {
    return String(data);
  }
}

function isEmptySlot_(value) {
  const text = String(value || "").trim().toLowerCase();
  return !text || text === "tbd" || text === "tdb" || text === "-";
}

function sameTeam_(a, b) {
  return String(a || "").trim().toLowerCase() === String(b || "").trim().toLowerCase();
}

function normalizeTeamKey_(value) {
  return String(value || "").trim().replace(/\s+/g, " ").toLowerCase();
}

function cleanTierTeamName_(value) {
  return String(value || "")
    .split(">")[0]
    .replace(/\([^)]*\)/g, "")
    .trim();
}

function normalizeDate_(value) {
  if (value instanceof Date) {
    return Utilities.formatDate(value, Session.getScriptTimeZone(), "yyyy-MM-dd");
  }
  const date = new Date(String(value).trim());
  if (!isNaN(date.getTime())) {
    return Utilities.formatDate(date, Session.getScriptTimeZone(), "yyyy-MM-dd");
  }
  return String(value || "").trim().toLowerCase();
}

function normalizeTime_(value) {
  if (value instanceof Date) {
    return Utilities.formatDate(value, Session.getScriptTimeZone(), "HH:mm");
  }
  const date = new Date(`January 1, 2026 ${String(value).trim()}`);
  if (!isNaN(date.getTime())) {
    return Utilities.formatDate(date, Session.getScriptTimeZone(), "HH:mm");
  }
  return String(value || "").trim().toLowerCase();
}
