let products = [
  {id:"9556001122334",name:"Susu Segar Farm Fresh",price:7.90,unit:"1L",icon:"🥛",tone:"#edf5f1"},
  {id:"9556123456789",name:"Roti Gardenia Original",price:3.50,unit:"400g",icon:"🍞",tone:"#fff2d9"},
  {id:"9557001002003",name:"Beras Wangi Tempatan",price:18.90,unit:"5kg",icon:"🍚",tone:"#f4efe5"},
  {id:"9555009001234",name:"Telur Ayam Gred A",price:13.20,unit:"10 biji",icon:"🥚",tone:"#fff4df"},
  {id:"9556400123412",name:"Minyak Masak Seri Murni",price:15.80,unit:"2kg",icon:"🫗",tone:"#fff5c8"},
  {id:"9556500987654",name:"Milo Activ-Go",price:12.40,unit:"500g",icon:"☕",tone:"#eee4d7"},
  {id:"9556015050505",name:"Mi Segera Kari",price:5.60,unit:"5 pek",icon:"🍜",tone:"#fee7df"},
  {id:"9556200778899",name:"Air Mineral Spritzer",price:2.20,unit:"1.5L",icon:"💧",tone:"#e2f3fa"},
  {id:"9557000111222",name:"Epal Fuji",price:8.90,unit:"1kg",icon:"🍎",tone:"#fde8e4"},
  {id:"9557111222333",name:"Pisang Berangan",price:6.50,unit:"1kg",icon:"🍌",tone:"#fff5cf"},
  {id:"9557222333444",name:"Sabun Pencuci Pinggan",price:6.90,unit:"900ml",icon:"🧴",tone:"#e0f3ec"},
  {id:"9557333444555",name:"Tisu Muka Premium",price:4.80,unit:"3 pek",icon:"🧻",tone:"#e8f2f4"}
];

let cart = {};
let stream = null;
let scanTimer = null;
let detector = null;
let lastDetected = "";

const $ = id => document.getElementById(id);
const money = value => `RM ${value.toFixed(2)}`;

function renderProducts(list = products) {
  $("productGrid").innerHTML = list.map(p => `
    <button class="product-card" data-product="${p.id}" aria-label="Tambah ${p.name}">
      <span class="add-badge">+</span>
      <span class="product-visual" style="--tone:${p.tone}">${p.icon}</span>
      <span class="product-info">
        <span class="product-name">${p.name}</span>
        <span class="product-meta"><strong>${money(p.price)}</strong><span>${p.unit}</span></span>
      </span>
    </button>`).join("");
  $("productCount").textContent = `${list.length} produk`;
  $("emptyProducts").classList.toggle("hidden", list.length > 0);
}

function addProduct(id) {
  const product = products.find(p => p.id === id);
  if (!product) {
    toast(`Barcode ${id} belum didaftarkan`);
    return false;
  }
  cart[id] = (cart[id] || 0) + 1;
  renderCart();
  toast(`${product.name} ditambah`);
  return true;
}

function renderCart() {
  const entries = Object.entries(cart);
  $("cartItems").innerHTML = entries.map(([id, qty]) => {
    const p = products.find(item => item.id === id);
    return `<div class="cart-item">
      <span class="cart-thumb" style="--tone:${p.tone}">${p.icon}</span>
      <span class="cart-copy"><strong>${p.name}</strong><small>${money(p.price * qty)}</small></span>
      <span class="qty-control">
        <button data-qty="${id}" data-change="-1" aria-label="Kurangkan">−</button>
        <span>${qty}</span>
        <button data-qty="${id}" data-change="1" aria-label="Tambah">+</button>
      </span>
    </div>`;
  }).join("");
  const total = entries.reduce((sum,[id,qty]) => sum + products.find(p => p.id === id).price * qty, 0);
  $("emptyCart").classList.toggle("hidden", entries.length > 0);
  ["subtotal","total","payAmount","modalTotal"].forEach(id => $(id).textContent = money(total));
  $("payBtn").disabled = !entries.length;
}

function changeQty(id, amount) {
  cart[id] += amount;
  if (cart[id] <= 0) delete cart[id];
  renderCart();
}

function toast(message) {
  $("toast").textContent = message;
  $("toast").classList.add("show");
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => $("toast").classList.remove("show"), 2200);
}

async function startCamera() {
  if (!navigator.mediaDevices?.getUserMedia) {
    $("scannerStatus").textContent = "Browser ini tidak menyokong akses kamera.";
    return;
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"},width:{ideal:1280},height:{ideal:720}},audio:false});
    $("cameraVideo").srcObject = stream;
    await $("cameraVideo").play();
    $("cameraPlaceholder").classList.add("hidden");
    $("startCameraBtn").classList.add("hidden");
    if ("BarcodeDetector" in window) {
      const supported = await BarcodeDetector.getSupportedFormats();
      const formats = ["ean_13","ean_8","upc_a","upc_e","code_128","code_39","qr_code"].filter(f => supported.includes(f));
      detector = new BarcodeDetector({formats});
      $("scannerStatus").textContent = "Kamera aktif — halakan barcode ke dalam kotak.";
      detectFrame();
    } else {
      $("scannerStatus").textContent = "Imbasan automatik tidak disokong. Gunakan ruangan barcode manual.";
    }
  } catch (error) {
    $("scannerStatus").textContent = "Kamera tidak dapat dibuka. Benarkan akses kamera dan cuba lagi.";
  }
}

async function detectFrame() {
  if (!stream || !detector) return;
  try {
    const codes = await detector.detect($("cameraVideo"));
    if (codes.length && codes[0].rawValue !== lastDetected) {
      lastDetected = codes[0].rawValue;
      if (navigator.vibrate) navigator.vibrate(100);
      if (addProduct(lastDetected)) closeModal("scannerModal");
      setTimeout(() => lastDetected = "", 1800);
    }
  } catch (_) {}
  scanTimer = setTimeout(detectFrame, 220);
}

function stopCamera() {
  clearTimeout(scanTimer);
  stream?.getTracks().forEach(track => track.stop());
  stream = null;
  detector = null;
  $("cameraVideo").srcObject = null;
  $("cameraPlaceholder").classList.remove("hidden");
  $("startCameraBtn").classList.remove("hidden");
  $("scannerStatus").textContent = "Kamera belum dihidupkan.";
}

function closeModal(id) {
  if (id === "scannerModal") stopCamera();
  $(id).close();
}

function finishPayment(method) {
  const total = Object.entries(cart).reduce((sum,[id,qty]) => sum + products.find(p => p.id === id).price * qty, 0);
  const receipt = `K01-${new Date().toISOString().slice(2,10).replaceAll("-","")}-${String(Date.now()).slice(-4)}`;
  const transaction = {receipt,method,total,items:{...cart},date:new Date().toISOString()};
  const history = JSON.parse(localStorage.getItem("kaunter-transactions") || "[]");
  history.unshift(transaction);
  localStorage.setItem("kaunter-transactions", JSON.stringify(history.slice(0,100)));
  $("paymentModal").close();
  $("receiptNumber").textContent = receipt;
  $("successMessage").textContent = `${money(total)} dibayar melalui ${method}.`;
  $("successModal").showModal();
}

$("productGrid").addEventListener("click", e => {
  const card = e.target.closest("[data-product]");
  if (card) addProduct(card.dataset.product);
});
$("cartItems").addEventListener("click", e => {
  const button = e.target.closest("[data-qty]");
  if (button) changeQty(button.dataset.qty, Number(button.dataset.change));
});
$("searchInput").addEventListener("input", e => {
  const q = e.target.value.trim().toLowerCase();
  renderProducts(products.filter(p => p.name.toLowerCase().includes(q) || p.id.includes(q)));
});
$("searchInput").addEventListener("keydown", e => {
  if (e.key === "Enter") {
    const value = e.target.value.trim();
    if (products.some(p => p.id === value)) {
      addProduct(value);
      e.target.value = "";
      renderProducts();
    }
  }
});
$("openScannerBtn").addEventListener("click", () => $("scannerModal").showModal());
$("startCameraBtn").addEventListener("click", startCamera);
$("manualAddBtn").addEventListener("click", () => {
  const value = $("manualBarcode").value.trim();
  if (value && addProduct(value)) {
    $("manualBarcode").value = "";
    closeModal("scannerModal");
  }
});
$("manualBarcode").addEventListener("keydown", e => { if (e.key === "Enter") $("manualAddBtn").click(); });
$("clearCartBtn").addEventListener("click", () => { cart = {}; renderCart(); });
$("payBtn").addEventListener("click", () => $("paymentModal").showModal());
document.querySelectorAll("[data-method]").forEach(button => button.addEventListener("click", () => finishPayment(button.dataset.method)));
document.querySelectorAll("[data-close]").forEach(button => button.addEventListener("click", () => closeModal(button.dataset.close)));
$("newSaleBtn").addEventListener("click", () => { cart = {}; renderCart(); $("successModal").close(); });
$("fullscreenBtn").addEventListener("click", () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.());
$("scannerModal").addEventListener("close", stopCamera);
document.querySelectorAll("dialog").forEach(dialog => dialog.addEventListener("click", e => {
  if (e.target === dialog) closeModal(dialog.id);
}));

setInterval(() => $("clock").textContent = new Intl.DateTimeFormat("ms-MY",{hour:"2-digit",minute:"2-digit",hour12:false}).format(new Date()),1000);
renderProducts();
renderCart();
$("clock").textContent = new Intl.DateTimeFormat("ms-MY",{hour:"2-digit",minute:"2-digit",hour12:false}).format(new Date());

async function loadDatabaseProducts() {
  try {
    const response = await fetch("api/cashier.php?action=list", {cache:"no-store"});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message);
    if (data.products.length) {
      const icons = ["📦","🛍️","🥫","🧃","🍪","🧴"];
      const tones = ["#edf5f1","#fff2d9","#e2f3fa","#fde8e4","#fff5cf"];
      products = data.products.map((p,index) => ({
        id:p.barcode,name:p.name,price:Number(p.price),unit:p.unit,
        icon:icons[index % icons.length],tone:tones[index % tones.length],stock:Number(p.stock)
      }));
      renderProducts();
    }
  } catch (_) {
    toast("Mod demo digunakan — database belum tersedia");
  }
}
loadDatabaseProducts();
