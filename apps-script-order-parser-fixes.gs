/*
  GNEX Record Order parser fixes.

  Cara pakai:
  1. Dalam Apps Script, replace function lama dengan function dalam file ini.
  2. Function yang perlu replace:
     - buildOrdersFromMessages
     - parseAutoOrder
     - detectGame
  3. Tambah helper baru:
     - pushOrderCopies
     - detectAutoQuantity

  Fix utama:
  - ML auto order tidak lagi tersalah jadi FF bila package ada perkataan Diamond.
  - Player ID + Server ID ML masih digabung sebagai ID customer.
  - Harga: MYR84.50 5x akan masuk 5 order untuk ID yang sama.
*/

function buildOrdersFromMessages(messages) {
  var orders = [];
  var errors = [];
  var pending = null;

  for (var i = 0; i < messages.length; i++) {
    var msg = messages[i];
    var text = cleanOrderText(msg.text);

    if (!text) continue;

    if (isAutoOrderText(text)) {
      var autoOrder = parseAutoOrder(msg);

      if (autoOrder) {
        pushOrderCopies(orders, autoOrder);
      } else {
        errors.push(makePasteError(msg, "Auto order tak cukup data / code tak jumpa", text));
      }

      pending = null;
      continue;
    }

    var inlineOrders = parseInlineOrders(msg);
    if (inlineOrders.length > 0) {
      for (var inlineIndex = 0; inlineIndex < inlineOrders.length; inlineIndex++) {
        pushOrderCopies(orders, inlineOrders[inlineIndex]);
      }
      pending = null;
      continue;
    }

    var idOnly = parseIdOnly(text);
    if (idOnly) {
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

  return { orders: orders, errors: errors };
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
