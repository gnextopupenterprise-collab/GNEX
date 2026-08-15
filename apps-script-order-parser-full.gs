/*
  GNEX Record Order - FULL FIXED CODE
  Generated for Apps Script copy-paste.

  Fix included:
  - Mobile Legends tidak tersalah detect sebagai Free Fire bila package ada Diamond.
  - ML Player ID + Server ID digabung untuk ID customer.
  - Harga MYR84.50 5x / 2x / 3x akan duplicate order ikut jumlah x.
  - Code Indo guna ID tanpa WB. Contoh FD40WB -> FD40ID, MT84.5WB -> MT84.5ID.
  - Support User ID + Server ID pending, ID(Server) ML, WDP ML, dan "5t ml id".
  - Support code ringkas ML/PUBG/FF: mt10, md50, ft20.
  - Support code dahulu kemudian ID selepasnya: mt10 -> Player ID/Server ID.
  - Pending ID/code hanya dipair kalau mesej dekat dari segi timestamp.
  - Combo membership tanpa payment default ke Digi: FDC.
*/
// Samakan token ini dengan order-record-sheet-config.php sebelum deploy Web App.
var ORDER_WEB_TOKEN = "gnex-order-2026-9f4d2c7a81e6b350";

// Public read endpoint for the Order Record page. This replaces the fragile
// "Publish to web" CSV URL, which changes whenever publishing is disabled.
function doGet(e) {
  try {
    var action = String((e && e.parameter && e.parameter.action) || "");
    if (action !== "readOrders") return orderWebJson_({ok:false,message:"Action tidak sah"});

    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var recordSheet = ss.getSheetByName("RecordOrder");
    if (!recordSheet) throw new Error('Sheet "RecordOrder" tak jumpa.');

    // The daily tab is identified by its stable gid, so renaming the tab is safe.
    var dailySheet = ss.getSheets().filter(function (sheet) {
      return sheet.getSheetId() === 357721629;
    })[0] || null;

    return orderWebJson_({
      ok: true,
      all_rows: orderWebSheetValues_(recordSheet),
      daily_rows: dailySheet ? orderWebSheetValues_(dailySheet) : [],
      all_live: true,
      daily_live: !!dailySheet
    });
  } catch (error) {
    return orderWebJson_({ok:false,message:error.message || String(error)});
  }
}

function orderWebSheetValues_(sheet) {
  var lastRow = sheet.getLastRow();
  var lastColumn = sheet.getLastColumn();
  if (!lastRow || !lastColumn) return [];
  return sheet.getRange(1, 1, lastRow, lastColumn).getDisplayValues();
}

function doPost(e) {
  try {
    var payload = JSON.parse((e && e.postData && e.postData.contents) || "{}");
    // Keep the deployed endpoint self-contained. Some earlier deployments only
    // copied the web-handler functions and therefore missed the global variable.
    var expectedToken = "gnex-order-2026-9f4d2c7a81e6b350";
    if (!payload || payload.token !== expectedToken) return orderWebJson_({ok:false,message:"Unauthorized"});
    if (payload.action !== "appendOrder") return orderWebJson_({ok:false,message:"Action tidak sah"});
    var id = String(payload.id || "").trim();
    var code = String(payload.code || "").trim().toUpperCase();
    if (!id || !code) return orderWebJson_({ok:false,message:"ID dan code wajib ada"});
    // Jangan tunggu ScriptLock di sini. Trigger processPendingOrders boleh
    // memegang lock agak lama dan menyebabkan permintaan web tamat selepas 30s.
    // getNextRecordOrderRow memilih baris kosong sebelum data ditulis terus.
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("RecordOrder");
    if (!sheet) throw new Error('Sheet "RecordOrder" tak jumpa.');
    var row = getNextRecordOrderRow(sheet);
    var now = new Date();
    sheet.getRange(row, 3, 1, 2).setValues([[id, code]]);
    sheet.getRange(row, 11, 1, 2).setValues([[
      Utilities.formatDate(now, Session.getScriptTimeZone(), "HH:mm:ss"),
      Utilities.formatDate(now, Session.getScriptTimeZone(), "M/d/yyyy")
    ]]);
    processCodeItem(row, code);
    return orderWebJson_({ok:true,row:row,id:id,code:code});
  } catch (error) {
    return orderWebJson_({ok:false,message:error.message || String(error)});
  }
}

function orderWebJson_(value) {
  return ContentService.createTextOutput(JSON.stringify(value)).setMimeType(ContentService.MimeType.JSON);
}

function onEdit(e) {
  if (!e || typeof e.value === "undefined") return;

  var sheet = e.range.getSheet();
  var sheetName = sheet.getName();

  if (sheetName === "RecordOrder") {
    var range = e.range;
    if (range.getNumRows() > 1 || range.getNumColumns() > 1) return;

    var column = range.getColumn();
    var row = range.getRow();
    var value = e.value;

    if (column === 4 && value) {
      processCodeItem(row, String(value).trim().toUpperCase());
    }
  }
}

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu("GNEX")
    .addItem("Setup Paste Order Sheet", "setupPasteOrderSheets")
    .addItem("Proses Paste Order", "processPasteOrder")
    .addItem("Proses Pending Order", "processPendingOrders")
    .addItem("Debug Paste Order", "debugPasteOrder")
    .addToUi();
}

/* =========================
   PROCESS RECORD ORDER
========================= */

function processPendingOrders() {
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) return;

  try {
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var recordSheet = ss.getSheetByName("RecordOrder");
    if (!recordSheet) return;

    var lastRow = recordSheet.getLastRow();
    if (lastRow < 2) return;

    var values = recordSheet.getRange(2, 1, lastRow - 1, 14).getValues();

    for (var i = 0; i < values.length; i++) {
      var row = i + 2;
      var id = values[i][2];       // C
      var code = values[i][3];     // D
      var masa = values[i][10];    // K
      var tarikh = values[i][11];  // L
      var status = String(values[i][13] || "").toLowerCase().trim(); // N

      if (!id || !code || !masa || !tarikh) continue;
      if (status === "successful") continue;

      code = String(code).trim().toUpperCase();
      if (!/[A-Z]/.test(code)) continue;

      processCodeItem(row, code);
    }
  } finally {
    lock.releaseLock();
  }
}

function processCodeItem(row, codeItem) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var recordSheet = ss.getSheetByName("RecordOrder");
  var tableSheet = ss.getSheetByName("KeuntunganTable");

  if (!recordSheet || !tableSheet) return;

  codeItem = String(codeItem || "").trim().toUpperCase();

  var lastTableRow = tableSheet.getLastRow();
  if (lastTableRow < 2) {
    recordSheet.getRange(row, 14).setValue("failed").setFontColor("red");
    return;
  }

  // Cari code tepat dalam column E sahaja. Ini jauh lebih ringan daripada
  // memuatkan keseluruhan KeuntunganTable bagi setiap order.
  var codeCell = tableSheet
    .getRange(2, 5, lastTableRow - 1, 1)
    .createTextFinder(codeItem)
    .matchCase(false)
    .matchEntireCell(true)
    .findNext();

  if (codeCell) {
    var data = tableSheet.getRange(codeCell.getRow(), 1, 1, 7).getValues()[0];
    var jumlahPembelian = data[2];
    var jenisPembayaran = data[0];
    var jenisItem = data[1];
    var profitValue = data[3];
    var membership = data[5];
    var modal = data[6];

    // E hingga J ditulis sekali, termasuk keuntungan di column J.
    recordSheet.getRange(row, 5, 1, 6).setValues([[
      jumlahPembelian,
      jenisPembayaran,
      jenisItem,
      membership,
      modal,
      Number(profitValue)
    ]]);

    var rowValues = recordSheet.getRange(row, 1, 1, 12).getDisplayValues()[0];
    processKeuntungan(
      row,
      rowValues,
      jumlahPembelian,
      jenisPembayaran,
      jenisItem,
      membership,
      profitValue
    );
    return;
  }

  recordSheet.getRange(row, 14).setValue("failed").setFontColor("red");
}

function processKeuntungan(row, rowValues, jumlahPembelian, jenisPembayaran, jenisItem, membership, knownProfitValue) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var profitSheet = ss.getSheetByName("Keuntungan");
  var recordSheet = ss.getSheetByName("RecordOrder");

  if (!profitSheet || !recordSheet) return;

  var numberSiri = rowValues[0];
  var idCustomer = rowValues[2];
  var masa = rowValues[10];
  var tarikh = rowValues[11];

  var profitValue = typeof knownProfitValue !== "undefined"
    ? knownProfitValue
    : getProfitFromTable(jumlahPembelian, jenisPembayaran, jenisItem, membership);

  if (profitValue !== null) {
    if (isDuplicateEntry(numberSiri, idCustomer, tarikh, masa)) {
      recordSheet.getRange(row, 14).setValue("successful").setFontColor("blue");
      return;
    }

    profitSheet.appendRow([
      numberSiri,
      tarikh,
      masa,
      idCustomer,
      jumlahPembelian,
      jenisPembayaran,
      jenisItem,
      Number(jumlahPembelian) - Number(profitValue),
      profitValue,
      membership
    ]);

    recordSheet.getRange(row, 14).setValue("successful").setFontColor("blue");
  } else {
    recordSheet.getRange(row, 14).setValue("failed").setFontColor("red");
  }
}

function isDuplicateEntry(numberSiri, idCustomer, tarikh, masa) {
  var profitSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("Keuntungan");
  if (!profitSheet) return false;
  var serial = String(numberSiri || "").trim();
  var lastRow = profitSheet.getLastRow();
  if (!serial || lastRow < 2) return false;

  // S/N adalah unik. Cari satu cell tepat di column A tanpa memindahkan lebih
  // 12,000 baris Keuntungan ke memori pada setiap klik PROSES.
  return profitSheet
    .getRange(2, 1, lastRow - 1, 1)
    .createTextFinder(serial)
    .matchCase(false)
    .matchEntireCell(true)
    .findNext() !== null;
}

function getProfitFromTable(jumlahPembelian, jenisPembayaran, jenisItem, membership) {
  var tableSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("KeuntunganTable");
  if (!tableSheet) return null;

  var data = tableSheet.getDataRange().getValues();

  for (var i = 1; i < data.length; i++) {
    if (
      sameText(data[i][0], jenisPembayaran) &&
      sameText(data[i][1], jenisItem) &&
      sameAmount(data[i][2], jumlahPembelian) &&
      sameText(data[i][5], membership)
    ) {
      return data[i][3];
    }
  }

  return null;
}

function sameText(a, b) {
  return String(a || "").trim().toUpperCase() === String(b || "").trim().toUpperCase();
}

function sameAmount(a, b) {
  return Number(a) === Number(b);
}

function setupAutoProcessTrigger() {
  var triggers = ScriptApp.getProjectTriggers();

  for (var i = 0; i < triggers.length; i++) {
    if (triggers[i].getHandlerFunction() === "processPendingOrders") {
      ScriptApp.deleteTrigger(triggers[i]);
    }
  }

  ScriptApp.newTrigger("processPendingOrders")
    .timeBased()
    .everyMinutes(1)
    .create();
}

/* =========================
   SETUP SHEET
========================= */

function setupPasteOrderSheets() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();

  var pasteSheet = ss.getSheetByName("PasteOrder") || ss.insertSheet("PasteOrder");
  pasteSheet.getRange("A1").setValue("Paste mesej WhatsApp panjang dekat A2 ke bawah, lepas tu run GNEX > Proses Paste Order");

  var errorSheet = ss.getSheetByName("PasteError") || ss.insertSheet("PasteError");
  errorSheet.clear();
  errorSheet.getRange(1, 1, 1, 5).setValues([["Date", "Time", "Raw Message", "Reason", "Detected"]]);
}

/* =========================
   PROCESS PASTE ORDER
========================= */

function processPasteOrder() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var pasteSheet = ss.getSheetByName("PasteOrder");
  var recordSheet = ss.getSheetByName("RecordOrder");
  var errorSheet = ss.getSheetByName("PasteError") || ss.insertSheet("PasteError");

  if (!pasteSheet || !recordSheet) return;

  var lastRow = Math.max(2, pasteSheet.getLastRow());

  // Baca column A dan B. Kalau A kosong, sistem ambil B.
  // Jadi masih jalan walaupun mesej nampak macam berada di B sebab column A sempit.
  var rawValues = pasteSheet.getRange(2, 1, lastRow - 1, 2).getDisplayValues();
  var rawText = rawValues.map(function (r) {
    var colA = String(r[0] || "").trim();
    var colB = String(r[1] || "").trim();
    return colA || colB;
  }).join("\n").trim();

  if (!rawText) return;

  var messages = parseWhatsappMessages(rawText);
  var parsed = buildOrdersFromMessages(messages);

  var orders = removeDuplicatePasteOrders(recordSheet, parsed.orders, parsed.errors);

  if (orders.length > 0) {
    var startRow = getNextRecordOrderRow(recordSheet);

    if (startRow + orders.length - 1 > recordSheet.getMaxRows()) {
      recordSheet.insertRowsAfter(
        recordSheet.getMaxRows(),
        startRow + orders.length - 1 - recordSheet.getMaxRows()
      );
    }

    var rows = orders.map(function (order) {
      return [
        "",
        order.id,
        order.code,
        "", "", "", "", "", "",
        order.time,
        order.date,
        "",
        ""
      ];
    });

    recordSheet.getRange(startRow, 2, rows.length, 13).setValues(rows);
    SpreadsheetApp.flush();

    processPendingOrders();
  }

  writePasteErrors(errorSheet, parsed.errors);

  var keepErrors = parsed.errors.filter(function (err) {
    return String(err.reason || "").toLowerCase().indexOf("duplicate") === -1;
  });

  var keepRaw = uniqueRawMessages(keepErrors.map(function (err) {
    return err.raw;
  }));

  rewritePasteOrderSheet(pasteSheet, keepRaw);
}

function rewritePasteOrderSheet(pasteSheet, rawMessages) {
  var maxRows = pasteSheet.getMaxRows();

  if (maxRows > 1) {
    pasteSheet.getRange(2, 1, maxRows - 1, 1).clearContent();
  }

  if (!rawMessages || rawMessages.length === 0) return;

  var rows = rawMessages.map(function (raw) {
    return [raw];
  });

  pasteSheet.getRange(2, 1, rows.length, 1).setValues(rows);
}

function uniqueRawMessages(rawList) {
  var seen = {};
  var result = [];

  for (var i = 0; i < rawList.length; i++) {
    var raw = String(rawList[i] || "").trim();
    if (!raw) continue;

    var key = raw.replace(/\s+/g, " ").trim();

    if (!seen[key]) {
      seen[key] = true;
      result.push(raw);
    }
  }

  return result;
}

/* =========================
   PARSE WHATSAPP
========================= */

function parseWhatsappMessages(rawText) {
  rawText = String(rawText || "")
    .replace(/\u202f/g, " ")
    .replace(/\u00a0/g, " ")
    .replace(/\r/g, "");

  var lines = rawText.split("\n");
  var messages = [];
  var current = null;

  // Support:
  // [25/06, 12:40] Gnex Topup: text
  // [6/24, 2:54 PM] G-NEX: text
  // Nama WhatsApp akan diabaikan.
  var headerRegex = /^\[(\d{1,2})\/(\d{1,2}),\s*(\d{1,2}):(\d{2})(?:\s*([AP]M))?\]\s*[^:]+:\s*(.*)$/i;

  for (var i = 0; i < lines.length; i++) {
    var line = lines[i];
    var match = line.match(headerRegex);

    if (match) {
      if (current) messages.push(current);

      var firstDate = Number(match[1]);
      var secondDate = Number(match[2]);
      var hour = Number(match[3]);
      var minute = Number(match[4]);
      var ampm = match[5] ? match[5].toUpperCase() : "";

      var month;
      var day;

      // 25/06 = DD/MM
      if (firstDate > 12) {
        day = firstDate;
        month = secondDate;
      }
      // 6/24 = MM/DD
      else if (secondDate > 12) {
        month = firstDate;
        day = secondDate;
      }
      // Kalau dua-dua bawah 12
      else {
        if (ampm) {
          month = firstDate;
          day = secondDate;
        } else {
          day = firstDate;
          month = secondDate;
        }
      }

      current = {
        month: month,
        day: day,
        hour: hour,
        minute: minute,
        ampm: ampm,
        text: match[6] || "",
        raw: line
      };
    } else if (current) {
      current.text += "\n" + line;
      current.raw += "\n" + line;
    }
  }

  if (current) messages.push(current);

  return messages.map(function (msg) {
    return {
      date: formatPasteDate(msg.month, msg.day),
      time: formatPasteTime(msg.hour, msg.minute, msg.ampm),
      text: String(msg.text || "").trim(),
      raw: msg.raw
    };
  });
}

var PENDING_PAIR_MAX_MINUTES = 15;

function buildOrdersFromMessages(messages) {
  var orders = [];
  var errors = [];
  var pending = null;
  var pendingCode = null;

  for (var i = 0; i < messages.length; i++) {
    var msg = messages[i];
    var text = cleanOrderText(msg.text);

    if (!text) continue;

    if (pending && !canPairPendingMessages(pending, msg)) {
      pushPendingIdTimeoutError(errors, pending, msg);
      pending = null;
    }

    if (pendingCode && !canPairPendingMessages(pendingCode, msg)) {
      pushPendingCodeTimeoutError(errors, pendingCode, msg);
      pendingCode = null;
    }

    if (isAutoOrderText(text)) {
      if (pending) {
        pushPendingIdInterruptedError(errors, pending, msg);
        pending = null;
      }

      if (pendingCode) {
        pushPendingCodeInterruptedError(errors, pendingCode, msg);
        pendingCode = null;
      }

      var autoOrder = parseAutoOrder(msg);

      if (autoOrder) {
        pushOrderCopies(orders, autoOrder);
      } else {
        errors.push(makePasteError(msg, "Auto order tak cukup data / code tak jumpa", text));
      }

      pending = null;
      pendingCode = null;
      continue;
    }

    var inlineOrders = parseInlineOrders(msg);
    if (inlineOrders.length > 0) {
      if (pending) {
        pushPendingIdInterruptedError(errors, pending, msg);
      }

      if (pendingCode) {
        pushPendingCodeInterruptedError(errors, pendingCode, msg);
      }

      for (var inlineIndex = 0; inlineIndex < inlineOrders.length; inlineIndex++) {
        pushOrderCopies(orders, inlineOrders[inlineIndex]);
      }
      pending = null;
      pendingCode = null;
      continue;
    }

    var idOnly = parseIdOnly(text);
    if (idOnly) {
      if (pendingCode && pendingCode.codes.length > 0) {
        for (var pc = 0; pc < pendingCode.codes.length; pc++) {
          pushOrderCopies(orders, {
            id: idOnly.id,
            code: pendingCode.codes[pc],
            date: pendingCode.date,
            time: pendingCode.time,
            raw: pendingCode.raw + "\n" + msg.raw,
            quantity: pendingCode.quantity
          });
        }
        pending = null;
        pendingCode = null;
        continue;
      }

      pending = {
        id: idOnly.id,
        date: msg.date,
        time: msg.time,
        raw: msg.raw
      };
      continue;
    }

    if (pending) {
      var codes = buildCodesFromText(text);
      var qty = detectAutoQuantity(text);

      if (codes.length > 0) {
        for (var c = 0; c < codes.length; c++) {
          pushOrderCopies(orders, {
            id: pending.id,
            code: codes[c],
            date: msg.date,
            time: msg.time,
            raw: pending.raw + "\n" + msg.raw,
            quantity: qty
          });
        }
      } else {
        errors.push({
          date: msg.date,
          time: msg.time,
          raw: pending.raw + "\n" + msg.raw,
          reason: "Tak dapat detect code order",
          detected: "ID: " + pending.id + " | Text: " + text
        });
      }

      pending = null;
      pendingCode = null;
      continue;
    }

    var looseCodes = buildCodesFromText(text);
    if (looseCodes.length > 0) {
      if (pendingCode) {
        errors.push({
          date: pendingCode.date,
          time: pendingCode.time,
          raw: pendingCode.raw,
          reason: "Order code lama tak ada ID selepasnya",
          detected: "Code: " + pendingCode.codes.join(", ")
        });
      }

      pendingCode = {
        codes: looseCodes,
        quantity: detectAutoQuantity(text),
        date: msg.date,
        time: msg.time,
        raw: msg.raw
      };
      continue;
    }

    errors.push(makePasteError(msg, "Tak ada ID untuk order ni", text));
  }

  if (pending) {
    errors.push({
      date: pending.date,
      time: pending.time,
      raw: pending.raw,
      reason: "ID ada tapi code order tak ada selepas ID",
      detected: "ID: " + pending.id
    });
  }

  if (pendingCode) {
    errors.push({
      date: pendingCode.date,
      time: pendingCode.time,
      raw: pendingCode.raw,
      reason: "Order code ada tapi ID tak ada selepasnya",
      detected: "Code: " + pendingCode.codes.join(", ")
    });
  }

  return { orders: orders, errors: errors };
}

function canPairPendingMessages(first, second) {
  var firstMs = pasteMessageMillis(first);
  var secondMs = pasteMessageMillis(second);

  if (firstMs === null || secondMs === null) return true;

  var diff = secondMs - firstMs;
  return diff >= 0 && diff <= PENDING_PAIR_MAX_MINUTES * 60 * 1000;
}

function pasteMessageMillis(item) {
  var dateMatch = String(item.date || "").match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  var timeMatch = String(item.time || "").match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);

  if (!dateMatch || !timeMatch) return null;

  return Date.UTC(
    Number(dateMatch[3]),
    Number(dateMatch[1]) - 1,
    Number(dateMatch[2]),
    Number(timeMatch[1]),
    Number(timeMatch[2]),
    Number(timeMatch[3] || 0)
  );
}

function pushPendingIdTimeoutError(errors, pending, msg) {
  errors.push({
    date: pending.date,
    time: pending.time,
    raw: pending.raw,
    reason: "ID lama tidak diproses sebab mesej seterusnya terlalu jauh masa",
    detected: "ID: " + pending.id + " | Next: " + msg.date + " " + msg.time
  });
}

function pushPendingCodeTimeoutError(errors, pendingCode, msg) {
  errors.push({
    date: pendingCode.date,
    time: pendingCode.time,
    raw: pendingCode.raw,
    reason: "Order code lama tidak diproses sebab ID seterusnya terlalu jauh masa",
    detected: "Code: " + pendingCode.codes.join(", ") + " | Next: " + msg.date + " " + msg.time
  });
}

function pushPendingIdInterruptedError(errors, pending, msg) {
  errors.push({
    date: pending.date,
    time: pending.time,
    raw: pending.raw,
    reason: "ID pending dihentikan sebab mesej order baru bermula",
    detected: "ID: " + pending.id + " | Next: " + msg.date + " " + msg.time
  });
}

function pushPendingCodeInterruptedError(errors, pendingCode, msg) {
  errors.push({
    date: pendingCode.date,
    time: pendingCode.time,
    raw: pendingCode.raw,
    reason: "Order code pending dihentikan sebab mesej order baru bermula",
    detected: "Code: " + pendingCode.codes.join(", ") + " | Next: " + msg.date + " " + msg.time
  });
}

function pushOrderCopies(orders, order) {
  var qty = Math.max(1, Math.min(99, Number(order.quantity || 1)));

  for (var i = 0; i < qty; i++) {
    orders.push({
      id: order.id,
      code: order.code,
      date: order.date,
      time: order.time,
      raw: order.raw,
      quantity: 1
    });
  }
}

function parseAutoOrder(msg) {
  var text = String(msg.text || "")
    .replace(/\u202f/g, " ")
    .replace(/\u00a0/g, " ")
    .replace(/\r/g, "");

  var flatText = cleanOrderText(text);
  var game = detectGame(flatText);

  if (!game) {
    if (/server\s*id|mobile\s*legends|\bml\b/i.test(flatText)) game = "ml";
    else if (/pubg|\buc\b/i.test(flatText)) game = "pubg";
    else game = "ff";
  }

  var playerMatch = flatText.match(/Player\s*ID\s*:?\s*(\d+)/i);
  if (!playerMatch) return null;

  var id = playerMatch[1];

  if (game === "ml") {
    var serverMatch = flatText.match(/Server\s*ID\s*:?\s*\(?\s*(\d+)\s*\)?/i);
    if (serverMatch) id = id + serverMatch[1];
  }

  var hargaMatch = flatText.match(/(?:Harga|Price)\s*:?\s*(?:RM|MYR)?\s*([\d.]+)/i);
  var amount = hargaMatch ? parseFloat(hargaMatch[1]) : 0;
  if (!amount) return null;

  var quantity = detectAutoQuantity(flatText);
  var packageMatch = flatText.match(/Package\s*:?\s*(.*?)(?:Player\s*ID|Payment|Channel|Harga|Price|Server\s*ID|$)/i);
  var paymentMatch = flatText.match(/(?:Payment|Channel)\s*:?\s*(.*?)(?:Harga|Price|Player\s*ID|Server\s*ID|Package|$)/i);

  var packageText = packageMatch ? cleanOrderText(packageMatch[1]) : "";
  var paymentText = paymentMatch ? cleanOrderText(paymentMatch[1]) : "";
  var code = "";

  // Priority game mesti ML/PUBG dahulu. Jangan check FF dahulu sebab ML package pun guna perkataan Diamond.
  if (game === "ml") {
    code = buildMLCode(
      packageText + " " + paymentText + " rm" + amount + " ml " + flatText,
      amount,
      paymentText,
      packageText
    );
  } else if (game === "pubg") {
    code = buildPUBGCode(
      packageText + " " + paymentText + " rm" + amount + " pubg " + flatText,
      amount,
      paymentText,
      packageText
    );
  } else {
    code = buildFreeFireCode(
      packageText + " " + paymentText + " rm" + amount + " ff " + flatText,
      amount,
      paymentText,
      packageText
    );
  }

  if (!code) return null;

  return {
    id: id,
    code: code,
    date: msg.date,
    time: msg.time,
    raw: msg.raw,
    quantity: quantity
  };
}

function parseInlineOrders(msg) {
  var parsed = parseIdWithRest(msg.text);
  if (!parsed || !parsed.id || !parsed.rest) return [];

  var codes = buildCodesFromText(parsed.rest);
  if (codes.length === 0) return [];

  return codes.map(function (code) {
    return {
      id: parsed.id,
      code: code,
      date: msg.date,
      time: msg.time,
      raw: msg.raw
    };
  });
}

function parseIdOnly(text) {
  var parsed = parseIdWithRest(text);
  if (!parsed || parsed.rest) return null;
  return { id: parsed.id };
}

function parseIdWithRest(text) {
  text = cleanOrderText(text);

  var multilineUserServer = text.match(/(?:user\s*id|player\s*id|uid)\s*:?\s*(\d+).*?server\s*id\s*:?\s*\(?\s*(\d+)\s*\)?\s*(.*)$/i);
  if (multilineUserServer) {
    return {
      id: multilineUserServer[1] + multilineUserServer[2],
      rest: cleanOrderText(multilineUserServer[3])
    };
  }

  // Support format:
  // id : 5551423800
  // ID 5551423800 monthly epep
  // User ID: 267578395
  // player id : 12485030622 weekly pass
  var labelId = text.match(/^(?:id|user\s*id|player\s*id|uid)\s*:?\s*(\d+)\s*(.*)$/i);
  if (labelId) {
    var labelRest = cleanOrderText(labelId[2]);
    var labelServer = labelRest.match(/^Server\s*ID\s*:?\s*\(?\s*(\d+)\s*\)?\s*(.*)$/i);

    if (labelServer) {
      return {
        id: labelId[1] + labelServer[1],
        rest: cleanOrderText(labelServer[2])
      };
    }

    return {
      id: labelId[1],
      rest: labelRest
    };
  }

  var paren = text.match(/^(\d+)\s*\((\d+)\)\s*(.*)$/i);
  if (paren) {
    return {
      id: paren[1] + paren[2],
      rest: cleanOrderText(paren[3])
    };
  }

  var first = text.match(/^(\d+)\s*(.*)$/i);
  if (!first) return null;

  var id = first[1];
  var rest = cleanOrderText(first[2]);

  var plainServer = rest.match(/^Server\s*ID\s*:?\s*\(?\s*(\d+)\s*\)?\s*(.*)$/i);
  if (plainServer) {
    return {
      id: id + plainServer[1],
      rest: cleanOrderText(plainServer[2])
    };
  }

  var server = rest.match(/^(\d{4,6})\s+(.+\bml\b.*)$/i);
  if (server) {
    id = id + server[1];
    rest = cleanOrderText(server[2]);
  }

  return { id: id, rest: rest };
}

/* =========================
   BUILD CODE
========================= */

function buildCodesFromText(text) {
  text = cleanOrderText(text);

  var compactShortcutCodes = buildCompactShortcutCodes(text);
  if (compactShortcutCodes.length > 0) {
    return applyIndoSuffixToCodes(uniqueCodeList(compactShortcutCodes), text);
  }

  var game = detectGame(text);
  if (!game && /\bml\b|mobile/i.test(text)) game = "ml";
  if (!game && /\bpubg\b|\buc\b/i.test(text)) game = "pubg";
  if (!game) game = "ff";

  if (game !== "ff") {
    return buildNonFFCodesFromText(text, game);
  }

  var codes = [];

  // Code pendek:
  // ft21 = Free Fire + TNG + RM21 => FT21WB
  // fd21 = Free Fire + DIGI + RM21 => FD21
  // fc21 = Free Fire + CELCOM + RM21 => FC21
  // fb21 = Free Fire + BANK/QR + RM21 => FT21WB
  var shortcutCodes = buildFFShortcutCodes(text);
  for (var s = 0; s < shortcutCodes.length; s++) {
    codes.push(shortcutCodes[s]);
  }

  var payment = detectFFPaymentOnly(text, "");
  if (isComboMembershipText(text) && !hasExplicitPayment(text)) payment = "d";
  var prefix = getFFPrefix(payment);
  if (!prefix) return [];

  // Membership FF:
  // detect monthly/weekly sahaja. Perkataan "pass" dan harga package akan diabaikan.
  // Boleh detect multi item: monthly+weekly+rm5 d ff Indo => FDMID, FDWID, FD5ID.
  var membershipCodes = detectFFMembershipCodes(text, payment);
  for (var m = 0; m < membershipCodes.length; m++) {
    codes.push(membershipCodes[m]);
  }

  if (/combo/i.test(text)) codes.push(prefix + "C");

  if (/\blite\b/i.test(text)) {
    var lx = detectKeywordMultiplier(text, "lite");
    codes.push(prefix + "WL" + (lx > 1 ? lx + "X" : ""));
  }

  // Kalau ada membership, amount hanya dikira jika memang format gabung pakai +
  // contoh: monthly+weekly+rm5 d ff Indo => FDMID, FDWID, FD5ID
  // contoh auto order Weekly Pass Harga RM10 => FDW sahaja, bukan FD10.
  var hasMembership = membershipCodes.length > 0;
  var allowAmountWithMembership = /\+\s*(?:rm\s*)?\d/i.test(text) || /\d+(?:\.\d+)?\s*\+/i.test(text);

  if (!hasMembership || allowAmountWithMembership) {
    var amounts = detectAmounts(text);

    for (var i = 0; i < amounts.length; i++) {
      var amountCode = buildFreeFireAmountCode(amounts[i], payment);
      if (amountCode) codes.push(amountCode);
    }
  }

  if (codes.length === 0) {
    var fallbackCode = buildCodeFromText(text);
    if (fallbackCode) codes.push(fallbackCode);
  }

  codes = uniqueCodeList(codes);
  codes = applyIndoSuffixToCodes(codes, text);

  return codes;
}

function buildNonFFCodesFromText(text, game) {
  text = cleanOrderText(text);

  var codes = [];
  var amounts = detectAmounts(text);

  if (amounts.length === 0) {
    var oneCode = buildCodeFromText(text);
    return oneCode ? [oneCode] : [];
  }

  for (var i = 0; i < amounts.length; i++) {
    var amount = amounts[i];
    var payment = detectPayment(text) || "t";

    if (game === "ml") {
      codes.push(buildMLCode(text, amount, payment, text));
    } else if (game === "pubg") {
      codes.push(buildPUBGCode(text, amount, payment, text));
    }
  }

  return codes.filter(function (code) { return !!code; });
}

function buildCodeFromText(text) {
  text = cleanOrderText(text);

  var amount = detectAmount(text);
  var game = detectGame(text);
  var payment = detectPayment(text);

  if (!game && /\bml\b|mobile/i.test(text)) game = "ml";
  if (!game && /\bpubg\b|\buc\b/i.test(text)) game = "pubg";
  if (!game) game = "ff";

  if (game === "ff") return buildFreeFireCode(text, amount, payment, text);
  if (game === "ml") return buildMLCode(text, amount, payment, text);
  if (game === "pubg") return buildPUBGCode(text, amount, payment, text);

  return "";
}

function buildFreeFireCode(text, amount, payment, packageText) {
  text = cleanOrderText(text + " " + packageText);

  // Payment ikut order: digi/d, celcom/c, tng/t, bank/qr.
  // Kalau kosong, default FF = TNG.
  payment = isComboMembershipText(text) && !hasExplicitPayment(text) ? "d" : detectFFPaymentOnly(text, payment);

  var prefix = getFFPrefix(payment);
  if (!prefix) return "";

  // Membership FF: detect monthly/weekly dahulu dan abaikan "pass" serta harga package.
  var membershipCode = detectFFMembershipCode(text, payment);
  if (membershipCode) {
    return applyIndoSuffixToCode(membershipCode, text);
  }

  var code = "";

  if (/combo/i.test(text)) {
    code = prefix + "C";
    return applyIndoSuffixToCode(code, text);
  }

  if (/\blite\b/i.test(text)) {
    var lx = detectMultiplier(text);
    code = prefix + "WL" + (lx > 1 ? lx + "X" : "");
    return applyIndoSuffixToCode(code, text);
  }

  if (!amount) return "";

  code = buildFreeFireAmountCode(amount, payment);
  return applyIndoSuffixToCode(code, text);
}

function buildFreeFireAmountCode(amount, payment) {
  payment = normalizePayment(payment) || payment || "t";

  var prefix = getFFPrefix(payment);
  if (!prefix || !amount) return "";

  var amountText = formatCodeAmount(amount);
  var useWB = false;

  if ((payment === "t" || payment === "b") && amount >= 20) useWB = true;
  if ((payment === "d" || payment === "c") && amount > 25) useWB = true;

  return prefix + amountText + (useWB ? "WB" : "");
}

function buildMLCode(text, amount, payment, packageText) {
  text = cleanOrderText(text + " " + packageText);
  payment = normalizePayment(payment) || detectPayment(text) || "t";

  var prefix = getMLPrefix(payment);
  if (!prefix) return "";

  if (/weekly|week|wdp|weekly\s*diamond\s*pass/i.test(text) || /\bw\b/i.test(text)) {
    var wx = detectMultiplier(text);
    return applyIndoSuffixToCode(prefix + "W" + (wx > 1 ? wx + "X" : "") + "WB", text);
  }

  if (!amount) return "";

  return applyIndoSuffixToCode(prefix + formatCodeAmount(amount) + "WB", text);
}

function buildPUBGCode(text, amount, payment, packageText) {
  text = cleanOrderText(text + " " + packageText);
  payment = normalizePayment(payment) || detectPayment(text) || "t";

  var prefix = getPUBGPrefix(payment);
  if (!prefix || !amount) return "";

  return prefix + String(Math.floor(Number(amount)));
}

/* =========================
   DETECT AMOUNT / PAYMENT
========================= */

function detectAmounts(text) {
  text = cleanOrderText(text);

  var amounts = [];

  var plus = text.match(/\b(?:rm\s*)?(\d+(?:\.\d+)?(?:\s*\+\s*\d+(?:\.\d+)?)+)\s*(?:tng|t|digi|d|celcom|c|bank|b)\b/i);
  if (plus) {
    var parts = plus[1].split("+");
    for (var p = 0; p < parts.length; p++) {
      var n = parseFloat(parts[p]);
      if (n) amounts.push(n);
    }
    return amounts;
  }

  var amountMatches = text.match(/(?:rm\s*)?\d+(?:\.\d+)?\s*(?:tng|t|digi|d|celcom|c|bank|b)\b/gi);
  if (amountMatches) {
    for (var i = 0; i < amountMatches.length; i++) {
      var a = detectAmount(amountMatches[i]);
      if (a) amounts.push(a);
    }
  }

  var compactMatches = text.match(/\b(?:t|d|c|b)\d+(?:\.\d+)?\b/gi);
  if (compactMatches) {
    for (var j = 0; j < compactMatches.length; j++) {
      var c = detectAmount(compactMatches[j]);
      if (c) amounts.push(c);
    }
  }

  if (amounts.length === 0) {
    var fallback = detectAmount(text);
    if (fallback) amounts.push(fallback);
  }

  return amounts;
}

function detectAmount(text) {
  text = cleanOrderText(text);

  var rm = text.match(/\b(?:rm|myr)\s*([\d.]+)/i);
  if (rm) return parseFloat(rm[1]) || 0;

  var direct = text.match(/\b([0-9]+(?:\.[0-9]+)?)\s*(?:tng|t|digi|d|celcom|c|bank|b)\b/i);
  if (direct) return parseFloat(direct[1]) || 0;

  var compact = text.match(/\b(?:t|d|c|b)([0-9]+(?:\.[0-9]+)?)\b/i);
  if (compact) return parseFloat(compact[1]) || 0;

  return 0;
}

function detectPayment(text) {
  text = cleanOrderText(text).toLowerCase();

  if (/online\s*banking|\bbank\b|\bqr\b|qr\s*transfer/.test(text)) return "b";
  if (/touch\s*n\s*go|tng|\bt\b|\d\s*t\b|\bt\d/.test(text)) return "t";
  if (/digi|\bd\b|\d\s*d\b|\bd\d/.test(text)) return "d";
  if (/celcom|\bc\b|\d\s*c\b|\bc\d/.test(text)) return "c";

  return "";
}

function detectFFPaymentOnly(text, payment) {
  var p = normalizePayment(payment);
  if (p) return p;

  text = cleanOrderText(text).toLowerCase();

  // Priority: Digi > Celcom > Bank/QR > TNG.
  // Single letter d/c/t hanya dikira kalau berdiri sendiri atau bersama nombor.
  if (/\bdigi\b|\bd\b|\bd\d|\d\s*d\b/.test(text)) return "d";
  if (/\bcelcom\b|\bc\b|\bc\d|\d\s*c\b/.test(text)) return "c";
  if (/\bbank\b|\bqr\b|online\s*banking|qr\s*transfer/.test(text)) return "b";
  if (/touch\s*n\s*go|\btng\b|\bt\b|\bt\d|\d\s*t\b/.test(text)) return "t";

  // Default FF kalau tiada payment ditulis.
  return "t";
}

function detectFFMembershipCodes(text, payment) {
  text = cleanOrderText(text).toLowerCase();

  var prefix = getFFPrefix(payment);
  if (!prefix) return [];

  var codes = [];

  // Monthly: detect monthly / month / epep.
  if (/\bmonthly\b|\bmonth\b|\bepep\b/.test(text)) {
    var mx = detectKeywordMultiplier(text, "monthly");
    codes.push(prefix + "M" + (mx > 1 ? mx + "X" : ""));
  }

  // Weekly: detect weekly/week/wk/minggu sahaja.
  // Perkataan "pass" sengaja tidak digunakan supaya sistem tak keliru.
  if (/\bweekly\b|\bweek\b|\bwk\b|\bminggu\b/.test(text)) {
    var wx = detectKeywordMultiplier(text, "weekly");
    codes.push(prefix + "W" + (wx > 1 ? wx + "X" : ""));
  }

  return codes;
}

function detectFFMembershipCode(text, payment) {
  var codes = detectFFMembershipCodes(text, payment);
  return codes.length > 0 ? codes[0] : "";
}

function buildFFShortcutCodes(text) {
  text = cleanOrderText(text).toLowerCase();

  var codes = [];

  // ft21 = f/ff + tng + RM21
  // fd21 = f/ff + digi + RM21
  // fc21 = f/ff + celcom + RM21
  // fb21 = f/ff + bank + RM21
  var re = /\bf+\s*([tdcb])\s*(?:rm\s*)?(\d+(?:\.\d+)?)\b/g;
  var match;

  while ((match = re.exec(text)) !== null) {
    var payment = normalizePayment(match[1]);
    var amount = parseFloat(match[2]);

    var code = buildFreeFireAmountCode(amount, payment);
    if (code) codes.push(code);
  }

  return codes;
}

function buildCompactShortcutCodes(text) {
  text = cleanOrderText(text).toLowerCase();

  var codes = [];
  var re = /\b([fmp])([tdcb])\s*(?:rm\s*)?(\d+(?:\.\d+)?)\b/g;
  var match;

  while ((match = re.exec(text)) !== null) {
    var gameKey = match[1];
    var payment = normalizePayment(match[2]);
    var amount = parseFloat(match[3]);
    var code = "";

    if (gameKey === "f") {
      code = buildFreeFireAmountCode(amount, payment);
    } else if (gameKey === "m") {
      code = buildMLCode(text, amount, payment, text);
    } else if (gameKey === "p") {
      code = buildPUBGCode(text, amount, payment, text);
    }

    if (code) codes.push(code);
  }

  return codes;
}

function hasExplicitPayment(text) {
  text = cleanOrderText(text).toLowerCase();
  return /\bdigi\b|\bd\b|\bd\d|\d\s*d\b|\bcelcom\b|\bc\b|\bc\d|\d\s*c\b|\bbank\b|\bqr\b|online\s*banking|qr\s*transfer|touch\s*n\s*go|\btng\b|\bt\b|\bt\d|\d\s*t\b/.test(text);
}

function isComboMembershipText(text) {
  text = cleanOrderText(text).toLowerCase();
  return /\bcombo\b/.test(text) && /\bmembership\b|\bmember\b|\bmb\b/.test(text);
}

function uniqueCodeList(codes) {
  var seen = {};
  var result = [];

  for (var i = 0; i < codes.length; i++) {
    var code = String(codes[i] || "").trim().toUpperCase();
    if (!code) continue;

    if (!seen[code]) {
      seen[code] = true;
      result.push(code);
    }
  }

  return result;
}


function normalizePayment(payment) {
  var text = cleanOrderText(payment).toLowerCase();

  if (!text) return "";
  if (text.indexOf("touch") !== -1 || text.indexOf("tng") !== -1 || text === "t") return "t";
  if (text.indexOf("bank") !== -1 || text.indexOf("qr") !== -1 || text === "b") return "b";
  if (text.indexOf("digi") !== -1 || text === "d") return "d";
  if (text.indexOf("celcom") !== -1 || text === "c") return "c";

  return "";
}

function detectAutoQuantity(text) {
  text = cleanOrderText(text);

  var priceQtyAfter = text.match(/(?:Harga|Price)\s*:?\s*(?:RM|MYR)?\s*[\d.]+\s*(\d+)\s*x\b/i);
  if (priceQtyAfter) return Number(priceQtyAfter[1]) || 1;

  var priceQtyBefore = text.match(/(?:Harga|Price)\s*:?\s*(?:RM|MYR)?\s*[\d.]+\s*x\s*(\d+)\b/i);
  if (priceQtyBefore) return Number(priceQtyBefore[1]) || 1;

  // Fallback untuk format pendek seperti "84.50 5x ml".
  var anyQty = text.match(/\b(\d+)\s*x\b/i);
  if (anyQty) return Number(anyQty[1]) || 1;

  return 1;
}

function detectGame(text) {
  text = cleanOrderText(text);

  // ML mesti menang jika ada Server ID, sebab auto order ML ada "Diamond" juga.
  if (/server\s*id|mobile\s*legends|\bmlbb\b|\bml\b/i.test(text)) return "ml";
  if (/pubg|\buc\b/i.test(text)) return "pubg";
  if (/free\s*fire|freefire|\bff\b|\bepep\b|garena/i.test(text)) return "ff";

  return "";
}

function detectMultiplier(text) {
  text = cleanOrderText(text);

  var x1 = text.match(/(\d+)\s*x/i);
  if (x1) return Number(x1[1]) || 1;

  var x2 = text.match(/\b(?:monthly|weekly|lite|w)\s*(\d+)\b/i);
  if (x2) return Number(x2[1]) || 1;

  var x3 = text.match(/-\s*(\d+)/i);
  if (x3) return Number(x3[1]) || 1;

  return 1;
}

function detectKeywordMultiplier(text, keyword) {
  text = cleanOrderText(text);

  var escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

  var reAfter = new RegExp("\\b" + escaped + "\\s*(\\d+)\\b", "i");
  var after = text.match(reAfter);
  if (after) return Number(after[1]) || 1;

  var reBefore = new RegExp("\\b(\\d+)\\s*" + escaped + "\\b", "i");
  var before = text.match(reBefore);
  if (before) return Number(before[1]) || 1;

  return 1;
}

/* =========================
   PREFIX CODE
========================= */

function getFFPrefix(payment) {
  if (payment === "t" || payment === "b") return "FT";
  if (payment === "d") return "FD";
  if (payment === "c") return "FC";
  return "";
}

function getMLPrefix(payment) {
  if (payment === "t" || payment === "b") return "MT";
  if (payment === "d") return "MD";
  if (payment === "c") return "MC";
  return "";
}

function getPUBGPrefix(payment) {
  if (payment === "t" || payment === "b") return "PT";
  return "";
}

/* =========================
   INDO SUFFIX
========================= */

function isIndoOrder(text) {
  text = cleanOrderText(text).toLowerCase();
  text = text
    .replace(/\bplayer\s*id\b/g, "playerid")
    .replace(/\buser\s*id\b/g, "userid")
    .replace(/\bserver\s*id\b/g, "serverid");

  return /\bindo\b|\bindonesia\b|\bffindo\b|\bfree\s*fire\s*indo\b|\bff\s*indo\b|\bid\b/.test(text);
}

function isFreeFireIndo(text) {
  return isIndoOrder(text);
}

function applyIndoSuffixToCode(code, text) {
  code = String(code || "").trim().toUpperCase();

  if (!code) return "";
  if (isIndoOrder(text)) {
    code = code.replace(/WBID$/i, "ID").replace(/WB$/i, "");
    return /ID$/i.test(code) ? code : code + "ID";
  }

  return code;
}

function applyIndoSuffixToCodes(codes, text) {
  return codes.map(function (code) {
    return applyIndoSuffixToCode(code, text);
  });
}

/* =========================
   DUPLICATE / ERROR
========================= */

function getNextRecordOrderRow(recordSheet) {
  var maxRows = recordSheet.getMaxRows();
  var values = recordSheet.getRange(2, 2, maxRows - 1, 13).getDisplayValues();
  var lastUsed = 1;

  for (var i = 0; i < values.length; i++) {
    var rowHasOrderData = values[i].some(function (cell) {
      return String(cell || "").trim() !== "";
    });

    if (rowHasOrderData) lastUsed = i + 2;
  }

  return lastUsed + 1;
}

function countExistingPasteOrder(recordSheet, order) {
  var lastRow = recordSheet.getLastRow();
  if (lastRow < 2) return 0;

  var data = recordSheet.getRange(2, 3, lastRow - 1, 10).getDisplayValues();

  var targetId = String(order.id || "").trim();
  var targetCode = String(order.code || "").trim().toUpperCase();
  var targetTime = String(order.time || "").trim();
  var targetDate = String(order.date || "").trim();

  var count = 0;

  for (var i = 0; i < data.length; i++) {
    var id = String(data[i][0] || "").trim();
    var code = String(data[i][1] || "").trim().toUpperCase();
    var time = String(data[i][8] || "").trim();
    var date = String(data[i][9] || "").trim();

    if (
      id === targetId &&
      code === targetCode &&
      time === targetTime &&
      date === targetDate
    ) {
      count++;
    }
  }

  return count;
}

function removeDuplicatePasteOrders(recordSheet, orders, errors) {
  var cleanOrders = [];
  var seenCountInPaste = {};

  for (var i = 0; i < orders.length; i++) {
    var key = [
      orders[i].id,
      String(orders[i].code || "").toUpperCase(),
      orders[i].time,
      orders[i].date
    ].join("|");

    seenCountInPaste[key] = (seenCountInPaste[key] || 0) + 1;

    var existingCount = countExistingPasteOrder(recordSheet, orders[i]);

    if (existingCount >= seenCountInPaste[key]) {
      errors.push({
        date: orders[i].date,
        time: orders[i].time,
        raw: orders[i].raw,
        reason: "Duplicate order, skip",
        detected: orders[i].id + " | " + orders[i].code
      });
      continue;
    }

    cleanOrders.push(orders[i]);
  }

  return cleanOrders;
}

function writePasteErrors(errorSheet, errors) {
  errorSheet.clear();
  errorSheet.getRange(1, 1, 1, 5).setValues([["Date", "Time", "Raw Message", "Reason", "Detected"]]);

  if (!errors || errors.length === 0) return;

  var rows = errors.map(function (err) {
    return [err.date, err.time, err.raw, err.reason, err.detected];
  });

  errorSheet.getRange(2, 1, rows.length, 5).setValues(rows);
}

function makePasteError(msg, reason, detected) {
  return {
    date: msg.date,
    time: msg.time,
    raw: msg.raw,
    reason: reason,
    detected: detected || ""
  };
}

/* =========================
   HELPER
========================= */

function isAutoOrderText(text) {
  return /Hello\s+GNEX/i.test(text) ||
    /Player\s*ID\s*:/i.test(text) ||
    /Package\s*:/i.test(text) ||
    /Harga\s*:/i.test(text);
}

function cleanOrderText(text) {
  return String(text || "")
    .replace(/\u202f/g, " ")
    .replace(/\u00a0/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function formatCodeAmount(amount) {
  amount = Number(amount);
  if (Math.floor(amount) === amount) return String(amount);
  return String(amount).replace(/\.?0+$/, "");
}

function formatPasteDate(month, day) {
  var year = new Date().getFullYear();
  return Number(month) + "/" + Number(day) + "/" + year;
}

function formatPasteTime(hour, minute, ampm) {
  hour = Number(hour);
  minute = Number(minute);
  ampm = String(ampm || "").toUpperCase();

  if (ampm === "PM" && hour !== 12) hour += 12;
  if (ampm === "AM" && hour === 12) hour = 0;

  return pad2(hour) + ":" + pad2(minute) + ":00";
}

function pad2(n) {
  n = Number(n);
  return n < 10 ? "0" + n : String(n);
}

/* =========================
   DEBUG
========================= */

function debugPasteOrder() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();

  var pasteSheet = ss.getSheetByName("PasteOrder");
  var recordSheet = ss.getSheetByName("RecordOrder");

  if (!pasteSheet) throw new Error('Sheet "PasteOrder" tak jumpa.');
  if (!recordSheet) throw new Error('Sheet "RecordOrder" tak jumpa.');

  var lastRow = Math.max(2, pasteSheet.getLastRow());
  var rawValues = pasteSheet.getRange(2, 1, lastRow - 1, 1).getDisplayValues();
  var rawText = rawValues.map(function (r) { return r[0]; }).join("\n").trim();

  Logger.log("Last row PasteOrder: " + lastRow);
  Logger.log("Raw length: " + rawText.length);
  Logger.log("Raw sample: " + rawText.slice(0, 1000));

  if (!rawText) {
    throw new Error("PasteOrder A2 ke bawah kosong.");
  }

  var messages = parseWhatsappMessages(rawText);
  Logger.log("Messages detected: " + messages.length);
  Logger.log(JSON.stringify(messages.slice(0, 10), null, 2));

  var parsed = buildOrdersFromMessages(messages);
  Logger.log("Orders detected: " + parsed.orders.length);
  Logger.log("Errors detected: " + parsed.errors.length);

  Logger.log("Orders sample:");
  Logger.log(JSON.stringify(parsed.orders.slice(0, 20), null, 2));

  Logger.log("Errors sample:");
  Logger.log(JSON.stringify(parsed.errors.slice(0, 20), null, 2));
}
