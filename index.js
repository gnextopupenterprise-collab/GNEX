function setBottomNavActive(navName){
document.querySelectorAll(".bottom-nav-item[data-nav]").forEach((item) => {
item.classList.toggle("is-active", item.dataset.nav === navName);
});
}

function goHome(){
const priceList = document.getElementById("price-list");
if(priceList && priceList.classList.contains("is-open")){
priceList.classList.remove("is-open");
priceList.setAttribute("aria-hidden", "true");
document.body.classList.remove("modal-open");
document.body.classList.remove("topup-chat-open");
}

const chatCenter = document.getElementById("chat-center");
if(chatCenter && chatCenter.classList.contains("is-open")){
if(window.closeChatCenter) closeChatCenter();
}

setBottomNavActive("home");
window.scrollTo({top:0, behavior:"smooth"});
}

let topupApiState = {csrf:"", customer:null, pendingCustomer:null, lastMessageId:0, pollTimer:null};

function renderTopupUserProfile(data = {}){
const customer = Object.prototype.hasOwnProperty.call(data, "customer") ? data.customer : topupApiState.customer;
const authCard = document.getElementById("topup-auth-card");
const profileCard = document.getElementById("topup-user-profile-card");
if(authCard) authCard.hidden = Boolean(customer);
if(profileCard) profileCard.hidden = !customer;
if(!customer) return;
const name = String(customer.name || "GNEX Player");
const totals = data.totals || {};
const game = Array.isArray(data.game_accounts) ? data.game_accounts[0] : null;
const gameNames = {ff:"FREE FIRE", ml:"MLBB", pubg:"PUBG"};
const setText = (id, value) => { const element = document.getElementById(id); if(element) element.textContent = value; };
setText("topup-user-profile-avatar", name.trim().charAt(0).toUpperCase() || "G");
setText("topup-user-profile-name", name);
setText("topup-user-profile-phone", customer.login_id || "-");
setText("topup-user-profile-orders", Number(totals.orders || 0));
setText("topup-user-profile-spent", `RM${Number(totals.total_rm || 0).toFixed(2)}`);
setText("topup-user-profile-game", game ? (gameNames[game.game_code] || String(game.game_code).toUpperCase()) : "-");
setText("topup-user-profile-game-id", game ? `ID: ${game.game_id || "-"}${game.server_id ? ` · Server: ${game.server_id}` : ""}` : "Belum ada akaun game");
}

async function loadTopupState(){
try{
const response = await fetch("api/topup.php?action=state", {credentials:"same-origin"});
const data = await response.json();
if(!response.ok || !data.ok) throw new Error(data.message || "API tidak tersedia.");
topupApiState.csrf = data.csrf || topupApiState.csrf;
topupApiState.customer = data.customer || null;
topupApiState.pendingCustomer = data.pending_customer || null;
document.body.classList.toggle("topup-user-logged-in", Boolean(data.customer));
renderTopupUserProfile(data);
if(data.customer){
try{ localStorage.setItem("gnex_topup_user", data.customer.name || "customer"); }catch(error){}
}else{
try{ localStorage.removeItem("gnex_topup_user"); }catch(error){}
}
return data;
}catch(error){
document.body.classList.remove("topup-user-logged-in");
renderTopupUserProfile({customer:null});
return null;
}
}

function updateTopupLoginPrompt(){
loadTopupState();
}

async function postTopup(action, payload, retried = false){
if(!topupApiState.csrf) await loadTopupState();
const response = await fetch(`api/topup.php?action=${encodeURIComponent(action)}`, {
method:"POST",
credentials:"same-origin",
headers:{"Content-Type":"application/json"},
body:JSON.stringify({...payload, csrf:topupApiState.csrf})
});
let data;
try{ data = await response.json(); }catch(error){ throw new Error("Server tidak memberi respons yang sah. Cuba semula."); }
if(data.csrf) topupApiState.csrf = data.csrf;
if(response.status === 419 && !retried){
topupApiState.csrf = "";
await loadTopupState();
return postTopup(action, payload, true);
}
if(!response.ok || !data.ok) throw new Error(data.message || "Permintaan gagal.");
return data;
}

function setTopupAuthStatus(message, isError = false){
const status = document.getElementById("topup-auth-status");
if(!status) return;
status.textContent = message;
status.classList.toggle("is-error", isError);
}

async function submitTopupLogin(event){
event.preventDefault();
const form = event.currentTarget;
const button = form.querySelector("[type=submit]");
button.disabled = true;
setTopupAuthStatus("Sedang login...");
try{
const data = await postTopup("login", Object.fromEntries(new FormData(form)));
if(data.admin){
setTopupAuthStatus("Login admin berjaya. Membuka inbox...");
window.location.href = data.redirect || "topup-admin.html";
return;
}
topupApiState.customer = data.customer;
document.body.classList.add("topup-user-logged-in");
try{ localStorage.setItem("gnex_topup_user", data.customer.name); }catch(error){}
setTopupAuthStatus(`Selamat kembali, ${data.customer.name}.`);
await loadTopupState();
}catch(error){ setTopupAuthStatus(error.message, true); }
button.disabled = false;
}

async function submitTopupRegister(event){
event.preventDefault();
const form = event.currentTarget;
const button = form.querySelector("[type=submit]");
button.disabled = true;
setTopupAuthStatus("Sedang mencipta akaun...");
try{
const data = await postTopup("register", Object.fromEntries(new FormData(form)));
topupApiState.pendingCustomer = data.pending_customer;
document.body.classList.remove("topup-user-logged-in");
form.reset();
setTopupAuthStatus(data.message || "Permohonan dihantar. Tunggu admin sahkan.");
}catch(error){ setTopupAuthStatus(error.message, true); }
button.disabled = false;
}

function goSection(sectionId){
const priceList = document.getElementById("price-list");
if(priceList && priceList.classList.contains("is-open")){
priceList.classList.remove("is-open");
priceList.setAttribute("aria-hidden", "true");
document.body.classList.remove("modal-open");
document.body.classList.remove("topup-chat-open");
}
if(sectionId === "support"){
setBottomNavActive("support");
}
setTimeout(() => {
document.getElementById(sectionId)
.scrollIntoView({behavior:"smooth"});
}, 40);
}

function openPriceListPanel(){
const priceList = document.getElementById("price-list");
if(!priceList) return;

setBottomNavActive("topup");
priceList.classList.add("is-open");
priceList.setAttribute("aria-hidden", "false");
document.body.classList.add("modal-open");
}

async function logoutTopupUser(){
  try{
    await postTopup("logout", {});
  }catch(error){
    console.error(error);
  }
  topupApiState.customer = null;
  topupApiState.pendingCustomer = null;
  document.body.classList.remove("topup-user-logged-in");
  renderTopupUserProfile({customer:null});
  try{ localStorage.removeItem("gnex_topup_user"); }catch(error){}
  closeTopupProfile(false);
  closeTopupRanking(false);
  if(window.openChatCenter) openChatCenter();
  if(window.openChatNavTab) openChatNavTab("chat");
}

function escapeTopupText(value){
return String(value ?? "").replace(/[&<>"']/g, char => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[char]));
}

function renderTopupMessages(items, reset = false){
const messages = document.getElementById("topup-chat-messages");
if(!messages) return;
if(reset){
  messages.innerHTML = `
    <div class="chat-date-chip">Hari ini</div>

    <div class="chat-row is-admin">
      <img src="images/logo baru gnex .webp" alt="" class="chat-avatar">

      <div>
        <div class="chat-name">GNEX Admin</div>
        <div class="chat-bubble">
          Sila WhatsApp admin <b>01115421017</b> untuk sebarang pembelian kerana sistem di sini masih belum siap sepenuhnya.
        </div>
        <time></time>
      </div>
    </div>
  `;

  topupApiState.lastMessageId = 0;
 }
items.forEach(message => {
if(Number(message.id) <= topupApiState.lastMessageId) return;
const isAdmin = ["admin","system"].includes(message.sender_type);
const row = document.createElement("div");
row.className = `chat-row ${isAdmin ? "is-admin" : "is-user"}`;
const clock = new Date(String(message.created_at).replace(" ","T"));
row.innerHTML = `${isAdmin ? 
  '<img src="images/logo baru gnex .webp" alt="" class="chat-avatar">' : ''}<div>${isAdmin ? '<div class="chat-name">GNEX Admin</div>' : ''}<div class="chat-bubble">${escapeTopupText(message.body)}</div><time>${Number.isNaN(clock.getTime()) ? "" : clock.toLocaleTimeString("en-MY",{hour:"numeric",minute:"2-digit"})}</time></div>`;
messages.appendChild(row);

topupApiState.lastMessageId = Math.max(topupApiState.lastMessageId, Number(message.id));
});
if(items.length) messages.scrollTop = messages.scrollHeight;
}

async function loadTopupMessages(reset = false){
try{
const after = reset ? 0 : topupApiState.lastMessageId;
const response = await fetch(`api/topup.php?action=messages&after=${after}`, {credentials:"same-origin",cache:"no-store"});
const data = await response.json();
if(!response.ok || !data.ok) return;
if(data.csrf) topupApiState.csrf = data.csrf;
renderTopupMessages(data.messages || [], reset);
}catch(error){}
}

function openTopupRanking(){
const ranking = document.getElementById("topup-ranking-view");
if(!ranking) return;
closeTopupProfile(false);
ranking.classList.add("is-open");
ranking.setAttribute("aria-hidden", "false");
setBottomNavActive("ranking");
toggleGameList(false);
loadTopupState();
}

function closeTopupRanking(restoreNav = true){
const ranking = document.getElementById("topup-ranking-view");
if(!ranking) return;
ranking.classList.remove("is-open");
ranking.setAttribute("aria-hidden", "true");
if(restoreNav) setBottomNavActive("topup");
}

function openTopupProfile(){
const profile = document.getElementById("topup-profile-view");
if(!profile) return;
closeTopupRanking(false);
profile.classList.add("is-open");
profile.setAttribute("aria-hidden", "false");
setBottomNavActive("profile");
toggleGameList(false);
}

function closeTopupProfile(restoreNav = true){
const profile = document.getElementById("topup-profile-view");
if(!profile) return;
profile.classList.remove("is-open");
profile.setAttribute("aria-hidden", "true");
if(restoreNav) setBottomNavActive("topup");
}

function selectProfileCard(type){
const authCard = document.getElementById("topup-auth-card");
if(!authCard) return;
authCard.classList.toggle("is-login-mode", type === "login");
authCard.classList.toggle("is-register-mode", type === "register");
authCard.querySelectorAll("[data-auth-tab]").forEach((tab) => tab.classList.toggle("is-active", tab.dataset.authTab === type));
}

function toggleChatSearch(forceState){
const search = document.getElementById("topup-chat-search");
const toggle = document.querySelector(".topup-chat-search-toggle");
const input = document.getElementById("topup-chat-search-input");
if(!search || !toggle || !input) return;

const shouldOpen = typeof forceState === "boolean" ? forceState : !search.classList.contains("is-open");
search.classList.toggle("is-open", shouldOpen);
search.setAttribute("aria-hidden", String(!shouldOpen));
toggle.setAttribute("aria-expanded", String(shouldOpen));
if(shouldOpen){
setTimeout(() => input.focus(), 80);
}else{
input.value = "";
searchTopupChat("");
}
}

function searchTopupChat(query){
const keyword = query.trim().toLowerCase();
document.querySelectorAll("#topup-chat-messages .chat-row").forEach((row) => {
const matches = !keyword || row.textContent.toLowerCase().includes(keyword);
row.classList.toggle("is-search-hidden", !matches);
});
}

function toggleGameList(forceState){
const menu = document.getElementById("game-list-menu");
const toggle = document.getElementById("game-list-toggle");
if(!menu || !toggle) return;

const shouldOpen = typeof forceState === "boolean" ? forceState : !menu.classList.contains("is-open");
menu.classList.toggle("is-open", shouldOpen);
menu.setAttribute("aria-hidden", String(!shouldOpen));
toggle.setAttribute("aria-expanded", String(shouldOpen));
}

async function sendTopupMessage(event){
event.preventDefault();
const input = document.getElementById("topup-chat-input");
const messages = document.getElementById("topup-chat-messages");
if(!input || !messages) return;

const message = input.value.trim();
if(!message) return;

input.disabled = true;
try{
await postTopup("sendMessage", {body:message});
}catch(error){
input.disabled = false;
input.focus({preventScroll:true});
return;
}

input.value = "";
input.disabled = false;
input.focus({preventScroll:true});
await loadTopupMessages(false);
}

const topupInput = document.getElementById("topup-chat-input");

if(topupInput){
  topupInput.addEventListener("focus", () => {
    setTimeout(() => {
      const messages = document.getElementById("topup-chat-messages");

      if(messages){
        messages.scrollTop = messages.scrollHeight;
      }
    }, 150);
  });
}

function updateKeyboardHeight(){
  if(!window.visualViewport) return;

  const vv = window.visualViewport;

  const keyboardHeight =
    window.innerHeight - vv.height - vv.offsetTop;

  const height = Math.max(0, keyboardHeight);

  document.documentElement.style.setProperty(
    "--keyboard-height",
    `${height}px`
  );

  document.body.classList.toggle(
    "keyboard-open",
    height > 100
  );
}

if(window.visualViewport){
  window.visualViewport.addEventListener("resize", updateKeyboardHeight);
  window.visualViewport.addEventListener("scroll", updateKeyboardHeight);
}

updateKeyboardHeight();


function closePriceListPanel(){
const priceList = document.getElementById("price-list");
if(!priceList) return;

priceList.classList.remove("is-open");
priceList.setAttribute("aria-hidden", "true");
document.body.classList.remove("modal-open");
setBottomNavActive("home");
}

function scrollToPriceList(){
openPriceListPanel();
}

function openGnexEsportImage(){
const modal = document.getElementById("gnexEsportImageModal");
if(!modal) return;

modal.classList.add("is-open");
modal.setAttribute("aria-hidden", "false");
document.body.classList.add("modal-open");
}

function closeGnexEsportImage(){
const modal = document.getElementById("gnexEsportImageModal");
if(!modal) return;

modal.classList.remove("is-open");
modal.setAttribute("aria-hidden", "true");

const priceList = document.getElementById("price-list");
if(!priceList || !priceList.classList.contains("is-open")){
document.body.classList.remove("modal-open");
}
}

const promoSlides = Array.from(document.querySelectorAll(".promo-slide"));
const promoDots = Array.from(document.querySelectorAll(".promo-dot"));
const promoPositions = ["is-active", "is-right", "is-hidden", "is-left"];
let promoIndex = 0;

function setPromoSlide(nextIndex){
promoIndex = (nextIndex + promoSlides.length) % promoSlides.length;

promoSlides.forEach((slide,index) => {
slide.classList.remove(...promoPositions);
const offset = (index - promoIndex + promoSlides.length) % promoSlides.length;
slide.classList.add(promoPositions[offset] || "is-hidden");
});

promoDots.forEach((dot,index) => {
dot.classList.toggle("is-active", index === promoIndex);
});
}

function movePromoSlide(direction){
setPromoSlide(promoIndex + direction);
}

function handlePromoSlideClick(event, index){
const slide = event.currentTarget;
if(index !== promoIndex){
setPromoSlide(index);
return;
}

const link = slide.dataset.promoLink;
if(link){
window.location.href = link;
}
}

let isTournamentTransitioning = false;

function playTournamentTransition(event){
event.preventDefault();

if(isTournamentTransitioning) return;
isTournamentTransitioning = true;

const targetUrl = event.currentTarget.getAttribute("href") || "tournament.html";
const cards = [
document.querySelector(".tournament-card-button"),
document.querySelector(".topup-card-button"),
document.querySelector(".jersey-card-button"),
document.querySelector(".gnex-esport-card-button")
].filter(Boolean);
const tournamentCard = cards[0];
const esportCard = cards[3];

if(!tournamentCard || !esportCard || window.matchMedia("(prefers-reduced-motion: reduce)").matches || window.matchMedia("(max-width: 767px)").matches){
window.location.href = targetUrl;
return;
}

const stage = document.createElement("div");
stage.className = "tour-transition-stage";
document.body.appendChild(stage);
document.body.classList.add("tour-transition-active");

const esportRect = esportCard.getBoundingClientRect();
const clones = cards.map((card) => {
const rect = card.getBoundingClientRect();
const clone = card.cloneNode(true);
clone.classList.add("tour-transition-clone");
clone.style.left = `${rect.left}px`;
clone.style.top = `${rect.top}px`;
clone.style.width = `${rect.width}px`;
clone.style.height = `${rect.height}px`;
clone.style.borderRadius = getComputedStyle(card).borderRadius;
clone.style.transformOrigin = "top left";
stage.appendChild(clone);
card.classList.add("is-transition-source-hidden");
return {card, clone, rect, isTournament: card === tournamentCard};
});

clones.forEach(({clone, rect, isTournament}) => {
const x = esportRect.left - rect.left;
const y = esportRect.top - rect.top;

clone.animate([
{transform:"translate(0, 0) scale(1)", opacity:1},
{transform:`translate(${x}px, ${y}px) scale(${isTournament ? 1.02 : .94})`, opacity:isTournament ? 1 : .18}
], {
duration:720,
easing:"cubic-bezier(.2,.8,.2,1)",
fill:"forwards"
});
});

setTimeout(() => {
const tournamentClone = clones.find((item) => item.isTournament);
if(!tournamentClone){
window.location.href = targetUrl;
return;
}

tournamentClone.clone.getAnimations().forEach((animation) => animation.cancel());
tournamentClone.clone.style.left = `${esportRect.left}px`;
tournamentClone.clone.style.top = `${esportRect.top}px`;
tournamentClone.clone.style.width = `${esportRect.width}px`;
tournamentClone.clone.style.height = `${esportRect.height}px`;
tournamentClone.clone.style.transform = "translate3d(0, 0, 0) scale(1)";
tournamentClone.clone.style.transformOrigin = "top left";

clones.forEach(({clone, isTournament}) => {
if(!isTournament){
clone.animate([
{opacity:.18},
{opacity:0}
], {
duration:180,
easing:"ease",
fill:"forwards"
});
}
});

stage.classList.add("is-title-view");
tournamentClone.clone.animate([
{opacity:1, transform:"translate3d(0, 0, 0) scale(1)"},
{opacity:0, transform:"translate3d(0, -10px, 0) scale(.96)"}
], {
duration:300,
easing:"ease",
fill:"forwards"
});

const titleWrap = document.createElement("div");
titleWrap.className = "tour-title-view";
titleWrap.innerHTML = '<div class="tour-title-kicker">GNEX CENTER</div><div class="tour-title-type">TOURNAMENT</div>';
stage.appendChild(titleWrap);

setTimeout(() => {
window.location.href = targetUrl;
}, 1500);
}, 760);
}

window.addEventListener("load", () => {
updateTopupLoginPrompt();
const notificationButton = document.getElementById("userNotificationBtn");
const notificationsOn = localStorage.getItem("gnex_user_notifications") === "on" && "Notification" in window && Notification.permission === "granted";
if(notificationButton){
notificationButton.textContent = notificationsOn ? "NOTIFIKASI ON" : "ON NOTIFIKASI";
notificationButton.classList.toggle("is-on", notificationsOn);
}
if(notificationsOn){
window.subscribeGnexUserPush?.().catch(error => console.error("User push subscription:", error));
}
const params = new URLSearchParams(window.location.search);
if(params.get("chat") === "guest"){
requestAnimationFrame(() => {
if(window.openChatCenter) openChatCenter();
const chatDepartment = params.get("department");
if(chatDepartment && window.openDepartmentChat) openDepartmentChat(chatDepartment);
else if(window.openChatNavTab) openChatNavTab("chat");
history.replaceState({}, "", window.location.pathname);
});
}
if(params.get("topup") === "1" || window.location.hash === "#price-list"){
openPriceListPanel();
if(params.get("view") === "ranking"){
openTopupRanking();
}
}
});

document.addEventListener("keydown", (event) => {
if(event.key === "Escape"){
closeGnexEsportImage();
closePriceListPanel();
}
});


async function registerGnexPushWorker(){

  if(!("serviceWorker" in navigator)){
    console.log("Service Worker tidak support.");
    return null;
  }

  try{

    const registration =
      await navigator.serviceWorker.register("scrim-sw.js?v=16",{updateViaCache:"none"});

    console.log(
      "GNEX Push Worker registered",
      registration
    );

    return registration;

  }catch(error){

    console.error(
      "Service Worker error:",
      error
    );

    return null;
  }
}

window.enableUserNotifications = async function enableUserNotifications(){
  const button = document.getElementById("userNotificationBtn");
  if(!("Notification" in window) || !("serviceWorker" in navigator)){
    alert("Browser ini tidak menyokong notifikasi.");
    return;
  }
  const permission = await Notification.requestPermission();
  const enabled = permission === "granted";
  localStorage.setItem("gnex_user_notifications", enabled ? "on" : "off");
  if(enabled){
    await registerGnexPushWorker();
    try{await window.subscribeGnexUserPush?.();}catch(error){alert(error.message||"Push notification gagal diaktifkan.");}
  }
  if(button){
    button.textContent = enabled ? "NOTIFIKASI ON" : "ON NOTIFIKASI";
    button.classList.toggle("is-on", enabled);
  }
}

function setupHomepagePerformance(){
  const root=document.documentElement;
  const zones=[...document.querySelectorAll("header.hero-glow,.promo-carousel,.card-button-grid,#payment")];
  const hero=document.querySelector("header.hero-glow");
  const heroVideo=hero?.querySelector("video");
  if("IntersectionObserver" in window){
    const observer=new IntersectionObserver(entries=>{
      entries.forEach(entry=>{
        entry.target.classList.toggle("is-performance-offscreen",!entry.isIntersecting);
        if(entry.target===hero&&heroVideo){
          if(entry.isIntersecting&&!document.hidden) heroVideo.play().catch(()=>{});
          else heroVideo.pause();
        }
      });
    },{rootMargin:"180px 0px",threshold:0});
    zones.forEach(zone=>observer.observe(zone));
  }
  document.addEventListener("visibilitychange",()=>{
    root.classList.toggle("is-performance-hidden",document.hidden);
    if(heroVideo){
      if(document.hidden||hero?.classList.contains("is-performance-offscreen")) heroVideo.pause();
      else heroVideo.play().catch(()=>{});
    }
  });
  let scrollTimer=0;
  let isScrolling=false;
  const handlePerformanceScroll=()=>{
    if(!isScrolling){
      isScrolling=true;
      document.body.classList.add("is-performance-scrolling");
      heroVideo?.pause();
    }
    clearTimeout(scrollTimer);
    scrollTimer=setTimeout(()=>{
      isScrolling=false;
      document.body.classList.remove("is-performance-scrolling");
      if(heroVideo&&!document.hidden&&!hero?.classList.contains("is-performance-offscreen")) heroVideo.play().catch(()=>{});
    },600);
  };
  [window,...document.querySelectorAll(".modal-panel,.gc-home-scroll,.gc-messages,.gc-group-messages")]
    .forEach(surface=>{
      surface.addEventListener("wheel",handlePerformanceScroll,{passive:true});
      surface.addEventListener("touchmove",handlePerformanceScroll,{passive:true});
      surface.addEventListener("scroll",handlePerformanceScroll,{passive:true});
    });
}

if("requestIdleCallback" in window) requestIdleCallback(setupHomepagePerformance,{timeout:1200});
else window.addEventListener("load",setupHomepagePerformance,{once:true});
