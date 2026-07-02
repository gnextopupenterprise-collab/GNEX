const SPREADSHEET_ID = "1cHQf5SAkvEkbaOdn-jY5uyDismBQNZn5ZM1prcOVwPpQ";
const SPREADSHEET_URL = "https://docs.google.com/spreadsheets/d/1cHQf5SAkvEkbaOdn-jY5uyDismBQNZn5ZM1prcOVwPpQ/edit";
const REGISTRATION_SHEET_NAME = "LIST PENDAFTARAN";
const PUBLIC_TEAM_SHEET_NAME = "PROSES KE PAGE";
const TEAM_LOGO_FOLDER_NAME = "CLASH LEAGUE TEAM LOGO";
const DEBUG_SHEET_NAME = "DEBUG LOG";

const REG_HEADERS = [
  "NAMA TEAM",
  "LOGO TEAM",
  "CONFIRM SLOT",
  "DATA SALAH",
  "NUMBER TELEFON",
  "NAMA COACH",
  "NAMA MANAGER",
  "P1 IGN",
  "P1 ID",
  "P2 IGN",
  "P2 ID",
  "P3 IGN",
  "P3 ID",
  "P4 IGN",
  "P4 ID",
  "P5 IGN",
  "P5 ID",
  "P6 IGN",
  "P6 ID",
  "REGISTER AT"
];

const PUBLIC_HEADERS = [
  "TEAM_ID",
  "NAMA_TEAM",
  "LOGO_TEAM",
  "SLOT_ID",
  "STATUS",
  "UPDATE_AT",
  "NUMBER_TELEFON",
  "NAMA_COACH",
  "NAMA_MANAGER",
  "P1_IGN",
  "P1_ID",
  "P2_IGN",
  "P2_ID",
  "P3_IGN",
  "P3_ID",
  "P4_IGN",
  "P4_ID",
  "P5_IGN",
  "P5_ID",
  "P6_IGN",
  "P6_ID"
];

function doGet(e) {
  const action = String(e && e.parameter && e.parameter.action || "");
  const callback = String(e && e.parameter && e.parameter.callback || "");

  if (action === "teams") {
    return jsonOutput_({ ok: true, teams: getPublicTeams_() }, callback);
  }

  return jsonOutput_({ ok: true, message: "Clash League Sheet API live." }, callback);
}

function doPost(e) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);

  try {
    const payload = parsePayload_(e);
    if (payload.action !== "registerTeam") {
      throw new Error("Action tidak valid.");
    }

    const teamName = clean_(payload.team_name);
    const phone = clean_(payload.phone);
    if (!teamName || !phone) {
      throw new Error("Nama team dan nombor telefon wajib isi.");
    }

    const ss = openSpreadsheet_();
    const sheet = getOrCreateSheet_(ss, REGISTRATION_SHEET_NAME, REG_HEADERS);
    ensureHeaders_(sheet, REG_HEADERS);

    const logoUrl = payload.logo && payload.logo.data
      ? saveLogo_(payload.logo, teamName)
      : "";

    sheet.appendRow([
      teamName,
      logoUrl,
      false,
      false,
      phone,
      clean_(payload.coach_name),
      clean_(payload.manager_name),
      clean_(payload.p1_ign),
      clean_(payload.p1_id),
      clean_(payload.p2_ign),
      clean_(payload.p2_id),
      clean_(payload.p3_ign),
      clean_(payload.p3_id),
      clean_(payload.p4_ign),
      clean_(payload.p4_id),
      clean_(payload.p5_ign),
      clean_(payload.p5_id),
      clean_(payload.p6_ign),
      clean_(payload.p6_id),
      new Date()
    ]);

    return jsonOutput_({ ok: true });
  } catch (error) {
    logDebug_("doPost error", error.message, error.stack || "");
    return jsonOutput_({ ok: false, error: error.message });
  } finally {
    lock.releaseLock();
  }
}

function onEdit(e) {
  if (!e || !e.range) return;

  const sheet = e.range.getSheet();
  if (sheet.getName() !== REGISTRATION_SHEET_NAME) return;
  if (e.range.getRow() < 2) return;

  const headers = headerMap_(sheet);
  const confirmCol = headers["CONFIRM SLOT"];
  if (!confirmCol || e.range.getColumn() !== confirmCol) return;

  const checked = String(e.value).toUpperCase() === "TRUE";
  syncConfirmedTeam_(sheet, e.range.getRow(), checked);
}

function refreshPublicTeams() {
  const ss = openSpreadsheet_();
  const registrationSheet = getOrCreateSheet_(ss, REGISTRATION_SHEET_NAME, REG_HEADERS);
  const headers = headerMap_(registrationSheet);
  const confirmCol = headers["CONFIRM SLOT"];
  if (!confirmCol) throw new Error("Column CONFIRM SLOT tidak jumpa.");

  const lastRow = registrationSheet.getLastRow();
  for (let row = 2; row <= lastRow; row++) {
    const checked = String(registrationSheet.getRange(row, confirmCol).getValue()).toUpperCase() === "TRUE";
    if (checked) syncConfirmedTeam_(registrationSheet, row, true);
  }
}

function syncConfirmedTeam_(registrationSheet, row, checked) {
  const ss = openSpreadsheet_();
  const publicSheet = getOrCreateSheet_(ss, PUBLIC_TEAM_SHEET_NAME, PUBLIC_HEADERS);
  ensureHeaders_(publicSheet, PUBLIC_HEADERS);

  const regHeaders = headerMap_(registrationSheet);
  const teamName = readCell_(registrationSheet, row, regHeaders, ["NAMA TEAM", "NAMA_TEAM"], 1);
  const logoUrl = readCell_(registrationSheet, row, regHeaders, ["LOGO TEAM", "LOGO_TEAM"], 2);
  if (!teamName) return;

  const publicHeaders = headerMap_(publicSheet);
  const existingRow = findRowByValue_(publicSheet, publicHeaders["TEAM_ID"] || publicHeaders["NAMA_TEAM"], teamName);

  if (!checked) {
    if (existingRow) {
      publicSheet.getRange(existingRow, publicHeaders["STATUS"]).setValue("removed");
      publicSheet.getRange(existingRow, publicHeaders["UPDATE_AT"]).setValue(new Date());
    }
    return;
  }

  const currentSlot = existingRow ? clean_(readCell_(publicSheet, existingRow, publicHeaders, ["SLOT_ID", "SLOT NO"], 4)) : "";
  const slotNo = /^\d+$/.test(currentSlot)
    ? currentSlot
    : Math.max(existingRow ? existingRow - 1 : publicSheet.getLastRow(), 1);
  const values = [[
    teamName,
    teamName,
    logoUrl,
    slotNo,
    "accepted",
    new Date(),
    readCell_(registrationSheet, row, regHeaders, ["NUMBER TELEFON", "NUMBER_TELEFON", "PHONE"], 5),
    readCell_(registrationSheet, row, regHeaders, ["NAMA COACH", "NAMA_COACH"], 6),
    readCell_(registrationSheet, row, regHeaders, ["NAMA MANAGER", "NAMA_MANAGER"], 7),
    readCell_(registrationSheet, row, regHeaders, ["P1 IGN", "P1_IGN", "P1"], 8),
    readCell_(registrationSheet, row, regHeaders, ["P1 ID", "P1_ID"], 9),
    readCell_(registrationSheet, row, regHeaders, ["P2 IGN", "P2_IGN", "P2"], 10),
    readCell_(registrationSheet, row, regHeaders, ["P2 ID", "P2_ID"], 11),
    readCell_(registrationSheet, row, regHeaders, ["P3 IGN", "P3_IGN", "P3"], 12),
    readCell_(registrationSheet, row, regHeaders, ["P3 ID", "P3_ID"], 13),
    readCell_(registrationSheet, row, regHeaders, ["P4 IGN", "P4_IGN", "P4"], 14),
    readCell_(registrationSheet, row, regHeaders, ["P4 ID", "P4_ID"], 15),
    readCell_(registrationSheet, row, regHeaders, ["P5 IGN", "P5_IGN", "P5"], 16),
    readCell_(registrationSheet, row, regHeaders, ["P5 ID", "P5_ID"], 17),
    readCell_(registrationSheet, row, regHeaders, ["P6 IGN", "P6_IGN", "P6"], 18),
    readCell_(registrationSheet, row, regHeaders, ["P6 ID", "P6_ID"], 19)
  ]];

  if (existingRow) {
    publicSheet.getRange(existingRow, 1, 1, PUBLIC_HEADERS.length).setValues(values);
  } else {
    publicSheet.appendRow(values[0]);
  }
}

function getPublicTeams_() {
  const ss = openSpreadsheet_();
  const sheet = getOrCreateSheet_(ss, PUBLIC_TEAM_SHEET_NAME, PUBLIC_HEADERS);
  ensureHeaders_(sheet, PUBLIC_HEADERS);

  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return [];

  return sheet.getRange(2, 1, lastRow - 1, PUBLIC_HEADERS.length)
    .getValues()
    .filter((row) => clean_(row[0]) && clean_(row[4]) !== "removed")
    .map((row) => ({
      team_id: clean_(row[0]),
      team_name: clean_(row[1]) || clean_(row[0]),
      logo_url: clean_(row[2]),
      slot_no: clean_(row[3]),
      status: clean_(row[4]) || "accepted",
      phone: clean_(row[6]),
      coach_name: clean_(row[7]),
      manager_name: clean_(row[8]),
      players: [
        { slot: "P1", ign: clean_(row[9]), id: clean_(row[10]) },
        { slot: "P2", ign: clean_(row[11]), id: clean_(row[12]) },
        { slot: "P3", ign: clean_(row[13]), id: clean_(row[14]) },
        { slot: "P4", ign: clean_(row[15]), id: clean_(row[16]) },
        { slot: "P5", ign: clean_(row[17]), id: clean_(row[18]) },
        { slot: "P6", ign: clean_(row[19]), id: clean_(row[20]) }
      ].filter((player) => player.ign || player.id)
    }));
}

function saveLogo_(file, teamName) {
  const folder = getOrCreateFolder_(TEAM_LOGO_FOLDER_NAME);
  const blob = Utilities.newBlob(
    Utilities.base64Decode(String(file.data || "")),
    file.type || "image/png",
    `${new Date().toISOString()}-${teamName}-${file.name || "logo.png"}`
  );

  const driveFile = folder.createFile(blob);
  driveFile.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
  return `https://drive.google.com/thumbnail?id=${driveFile.getId()}&sz=w256`;
}

function parsePayload_(e) {
  if (e && e.parameter && e.parameter.payload) {
    return JSON.parse(e.parameter.payload);
  }

  if (!e || !e.postData || !e.postData.contents) return {};
  return JSON.parse(e.postData.contents);
}

function openSpreadsheet_() {
  const active = SpreadsheetApp.getActiveSpreadsheet();
  if (active) return active;

  try {
    return SpreadsheetApp.openById(SPREADSHEET_ID);
  } catch (idError) {
    return SpreadsheetApp.openByUrl(SPREADSHEET_URL);
  }
}

function getOrCreateSheet_(ss, name, headers) {
  const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
  ensureHeaders_(sheet, headers);
  return sheet;
}

function ensureHeaders_(sheet, headers) {
  const current = sheet.getRange(1, 1, 1, headers.length).getValues()[0];
  const hasHeaders = current.some((value) => String(value || "").trim());
  if (!hasHeaders) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    return;
  }

  const merged = current.map((value, index) => String(value || "").trim() || headers[index]);
  if (merged.some((value, index) => value !== current[index])) {
    sheet.getRange(1, 1, 1, headers.length).setValues([merged]);
  }
}

function headerMap_(sheet) {
  const lastColumn = Math.max(sheet.getLastColumn(), 1);
  const headers = sheet.getRange(1, 1, 1, lastColumn).getValues()[0];
  return headers.reduce((map, header, index) => {
    const key = String(header || "").trim().toUpperCase();
    if (key) map[key] = index + 1;
    return map;
  }, {});
}

function findRowByValue_(sheet, column, value) {
  if (!column || sheet.getLastRow() < 2) return 0;
  const values = sheet.getRange(2, column, sheet.getLastRow() - 1, 1).getValues();
  const target = clean_(value).toLowerCase();
  for (let i = 0; i < values.length; i++) {
    if (clean_(values[i][0]).toLowerCase() === target) return i + 2;
  }
  return 0;
}

function readCell_(sheet, row, headers, names, fallbackColumn) {
  for (let i = 0; i < names.length; i++) {
    const col = headers[String(names[i]).toUpperCase()];
    if (col) return clean_(sheet.getRange(row, col).getValue());
  }
  if (fallbackColumn) return clean_(sheet.getRange(row, fallbackColumn).getValue());
  return "";
}

function getOrCreateFolder_(name) {
  const folders = DriveApp.getFoldersByName(name);
  if (folders.hasNext()) return folders.next();
  return DriveApp.createFolder(name);
}

function jsonOutput_(data, callback) {
  const json = JSON.stringify(data);
  const body = callback ? `${callback}(${json});` : json;
  const mime = callback ? ContentService.MimeType.JAVASCRIPT : ContentService.MimeType.JSON;
  return ContentService.createTextOutput(body).setMimeType(mime);
}

function clean_(value) {
  return String(value == null ? "" : value).trim();
}

function logDebug_(title, message, stack) {
  try {
    const ss = openSpreadsheet_();
    const sheet = getOrCreateSheet_(ss, DEBUG_SHEET_NAME, ["TIME", "TITLE", "MESSAGE", "STACK"]);
    sheet.appendRow([new Date(), title, message, stack]);
  } catch (error) {
    // Ignore debug failures.
  }
}
