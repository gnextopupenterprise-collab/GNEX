let databaseProducts = [];
let adminStream = null;
let adminDetector = null;
let adminScanTimer = null;
const $ = id => document.getElementById(id);
const money = value => `RM ${Number(value).toFixed(2)}`;

function showStatus(message, error = false) {
  $("formStatus").textContent = message;
  $("formStatus").classList.toggle("error", error);
}

function toast(message) {
  $("toast").textContent = message;
  $("toast").classList.add("show");
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => $("toast").classList.remove("show"), 2200);
}

async function api(url, options) {
  const response = await fetch(url, options);
  const data = await response.json().catch(() => ({ok:false,message:"Respons server tidak sah."}));
  if (!response.ok) throw new Error(data.message || "Permintaan gagal.");
  return data;
}

async function loadProducts() {
  try {
    const data = await api("api/cashier.php?action=list&all=1");
    databaseProducts = data.products;
    renderRows();
  } catch (error) {
    $("databaseCount").textContent = "Database tidak dapat dibuka";
    showStatus(error.message, true);
  }
}

function renderRows() {
  const q = $("adminSearch").value.trim().toLowerCase();
  const list = databaseProducts.filter(p => p.name.toLowerCase().includes(q) || p.barcode.toLowerCase().includes(q));
  $("databaseCount").textContent = `${databaseProducts.length} produk berdaftar`;
  $("productRows").innerHTML = list.map(p => `<tr>
    <td class="product-cell"><strong>${escapeHtml(p.name)}</strong><small>${escapeHtml(p.barcode)} · ${escapeHtml(p.category)}</small></td>
    <td class="price-cell">${money(p.price)}</td><td>${p.stock} ${escapeHtml(p.unit)}</td>
    <td><button class="edit-product" data-edit="${escapeHtml(p.barcode)}">Ubah</button></td>
  </tr>`).join("");
  $("databaseEmpty").classList.toggle("hidden", list.length > 0);
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
}

function fillProduct(product) {
  ["barcode","name","price","stock","category","unit"].forEach(key => $(key).value = product[key] ?? "");
  $("name").focus();
  showStatus("Produk dijumpai. Anda boleh ubah maklumat dan simpan semula.");
}

async function lookupBarcode() {
  const barcode = $("barcode").value.trim();
  if (!barcode) return showStatus("Masukkan atau imbas barcode dahulu.", true);
  try {
    const data = await api(`api/cashier.php?action=get&barcode=${encodeURIComponent(barcode)}`);
    fillProduct(data.product);
  } catch (error) {
    if (error.message.includes("belum didaftarkan")) {
      $("name").focus();
      showStatus("Barcode baharu. Lengkapkan nama, harga dan stok.");
    } else showStatus(error.message, true);
  }
}

async function saveProduct(event) {
  event.preventDefault();
  const payload = {
    barcode:$("barcode").value.trim(), name:$("name").value.trim(),
    price:$("price").value, stock:$("stock").value,
    category:$("category").value.trim(), unit:$("unit").value.trim(),
    admin_password:$("adminPassword").value
  };
  $("saveBtn").disabled = true;
  showStatus("Sedang menyimpan…");
  try {
    const data = await api("api/cashier.php?action=save", {method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)});
    showStatus(data.message);
    toast(data.message);
    await loadProducts();
  } catch (error) {
    showStatus(error.message, true);
  } finally {
    $("saveBtn").disabled = false;
  }
}

function resetForm() {
  $("productForm").reset();
  $("stock").value = "0";
  $("barcode").focus();
  showStatus("");
}

async function startAdminCamera() {
  try {
    adminStream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}},audio:false});
    $("adminVideo").srcObject = adminStream;
    await $("adminVideo").play();
    $("adminCameraPlaceholder").classList.add("hidden");
    $("startAdminCamera").classList.add("hidden");
    if (!("BarcodeDetector" in window)) {
      $("adminScannerStatus").textContent = "Browser ini tidak menyokong imbasan automatik. Taip nombor barcode.";
      return;
    }
    const supported = await BarcodeDetector.getSupportedFormats();
    adminDetector = new BarcodeDetector({formats:["ean_13","ean_8","upc_a","upc_e","code_128","code_39","qr_code"].filter(f => supported.includes(f))});
    $("adminScannerStatus").textContent = "Kamera aktif — halakan barcode ke dalam kotak.";
    detectAdminFrame();
  } catch (_) {
    $("adminScannerStatus").textContent = "Kamera gagal dibuka. Semak kebenaran kamera dan HTTPS.";
  }
}

async function detectAdminFrame() {
  if (!adminStream || !adminDetector) return;
  try {
    const codes = await adminDetector.detect($("adminVideo"));
    if (codes.length) {
      $("barcode").value = codes[0].rawValue;
      navigator.vibrate?.(100);
      closeAdminCamera();
      await lookupBarcode();
      return;
    }
  } catch (_) {}
  adminScanTimer = setTimeout(detectAdminFrame, 220);
}

function closeAdminCamera() {
  clearTimeout(adminScanTimer);
  adminStream?.getTracks().forEach(track => track.stop());
  adminStream = null;
  adminDetector = null;
  $("adminScanner").close();
  $("adminVideo").srcObject = null;
  $("adminCameraPlaceholder").classList.remove("hidden");
  $("startAdminCamera").classList.remove("hidden");
}

$("productForm").addEventListener("submit", saveProduct);
$("lookupBtn").addEventListener("click", lookupBarcode);
$("resetBtn").addEventListener("click", resetForm);
$("adminSearch").addEventListener("input", renderRows);
$("productRows").addEventListener("click", e => {
  const button = e.target.closest("[data-edit]");
  const product = button && databaseProducts.find(p => p.barcode === button.dataset.edit);
  if (product) fillProduct(product);
});
$("scanAdminBtn").addEventListener("click", () => $("adminScanner").showModal());
$("startAdminCamera").addEventListener("click", startAdminCamera);
$("closeAdminScanner").addEventListener("click", closeAdminCamera);
$("adminScanner").addEventListener("close", () => {
  if (adminStream) {
    clearTimeout(adminScanTimer);
    adminStream.getTracks().forEach(track => track.stop());
    adminStream = null;
  }
});
loadProducts();
