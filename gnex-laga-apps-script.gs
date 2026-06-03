const REGISTRATION_SHEET_NAME = "Pendaftaran";
const SCHEDULE_SHEET_NAME = "Sheet1";
const PAYMENT_FOLDER_NAME = "GNEX LAGA PAYMENT SS";
const SPREADSHEET_ID = "1msJE-sP71Oo7aQQ4mwtEsLNd2k65DvjZTk0_wjZ9xA";
const SPREADSHEET_URL = "https://docs.google.com/spreadsheets/d/1msJE-sP71Oo7aQQ4mwtEsLNd2k65DvjZTk0_wjZ9xA/edit";
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
    const teamName = String(payload.team_name || "").trim();
    let paymentUrl = "";

    debugLog_(ss, "doPost payload", {
      wakil,
      teamName,
      hasPayment: Boolean(payload.payment_proof && payload.payment_proof.data),
      rawLength: e && e.postData && e.postData.contents ? e.postData.contents.length : 0,
      parameters: e && e.parameter ? e.parameter : {}
    });
    executionLog_("doPost:payload-parsed", {
      wakil,
      teamName,
      hasPayment: Boolean(payload.payment_proof && payload.payment_proof.data),
      paymentName: payload.payment_proof ? payload.payment_proof.name : "",
      paymentType: payload.payment_proof ? payload.payment_proof.type : ""
    });

    if (!wakil || !teamName) {
      throw new Error("Payload kosong atau tidak lengkap. Wakil dan Team name wajib ada.");
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
    sheet.getRange(nextRow, 1, 1, 6).setValues([[wakil, teamName, paymentUrl, "", "", false]]);
    executionLog_("doPost:write-done", {
      nextRow,
      wakil,
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

function onEdit(e) {
  if (!e || !e.range) return;

  const editedSheet = e.range.getSheet();
  if (editedSheet.getName() !== REGISTRATION_SHEET_NAME) return;
  if (e.range.getRow() === 1) return;

  const headers = getHeaders_(editedSheet);
  const slotCol = headers["slot"];
  if (!slotCol || e.range.getColumn() !== slotCol) return;

  const isChecked = String(e.value).toUpperCase() === "TRUE";
  if (!isChecked) return;

  confirmSlotToSchedule_(editedSheet, e.range.getRow(), headers);
}

function confirmSlotToSchedule_(registrationSheet, row, headers) {
  const ss = openSpreadsheet_();
  const scheduleSheet = ss.getSheetByName(SCHEDULE_SHEET_NAME) || ss.getSheetByName("JADUAL LAGA");
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
  if (sheet.getLastRow() === 0) sheet.appendRow(headers);
  return sheet;
}

function findNextEmptyRegistrationRow_(sheet) {
  const lastRow = Math.max(sheet.getLastRow(), 2);
  const values = sheet.getRange(2, 1, lastRow - 1, 2).getValues();
  const emptyIndex = values.findIndex(row => {
    const wakil = String(row[0] || "").trim();
    const teamName = String(row[1] || "").trim();
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
