const chartData = {
  7: [530, 715, 620, 910, 790, 1120, 1046],
  30: [410, 525, 490, 610, 570, 680, 650, 730, 710, 890, 820, 760, 940, 880, 1010, 980, 1100, 1040, 1170, 1090, 1220, 1180, 1320, 1260, 1430, 1360, 1510, 1470, 1600, 1560],
  90: [920, 1050, 980, 1160, 1090, 1280, 1210, 1390, 1330, 1510, 1460, 1580, 1690, 1610, 1780, 1710, 1880, 1810]
};

const chartLabels = {
  7: ["8 Aug", "9 Aug", "10 Aug", "11 Aug", "12 Aug", "13 Aug", "14 Aug"],
  30: ["1 Aug", "7 Aug", "14 Aug", "21 Aug", "30 Aug"],
  90: ["May", "Jun", "Jul", "Aug"]
};

const pageChartMeta = {
  all: {label:"All website", scale:1},
  home: {label:"Homepage", scale:.43},
  clash: {label:"Clash League", scale:.29},
  laga: {label:"GNEX Laga", scale:.19},
  tournament: {label:"Tournament", scale:.14},
  mlbb: {label:"MLBB", scale:.11}
};
const pageChartData = Object.fromEntries(Object.entries(pageChartMeta).map(([key, meta], pageIndex) => [key, chartData[30].map((value, index) => Math.max(1, Math.round(value * meta.scale * (1 + Math.sin(index * .78 + pageIndex) * .09))))]));
pageChartData.all = [...chartData[30]];

const canvas = document.getElementById("trafficChart");
const tooltip = document.getElementById("chartTooltip");
let activePeriod = 30;
let chartPoints = [];
let trafficEntryAnimationPlayed = false;
const profitCanvas = document.getElementById("profitChart");
const profitTooltip = document.getElementById("profitTooltip");
const monthlyProfit = [6420, 7180, 6890, 8240, 7760, 9120, 9680, 8940, 10380, 11260, 10890, 12140];
let profitPoints = [];

function drawChart(animate = false) {
  const rect = canvas.getBoundingClientRect();
  const ratio = window.devicePixelRatio || 1;
  canvas.width = Math.max(1, Math.round(rect.width * ratio));
  canvas.height = Math.max(1, Math.round(rect.height * ratio));
  const ctx = canvas.getContext("2d");
  ctx.scale(ratio, ratio);
  const width = rect.width;
  const height = rect.height;
  const pad = { top: 12, right: 9, bottom: 7, left: 8 };
  const values = chartData[activePeriod];
  const min = Math.min(...values) * .78;
  const max = Math.max(...values) * 1.08;
  const bluePanel = window.matchMedia("(min-width: 1051px)").matches;

  chartPoints = values.map((value, index) => ({
    x: pad.left + (index / (values.length - 1)) * (width - pad.left - pad.right),
    y: pad.top + ((max - value) / (max - min)) * (height - pad.top - pad.bottom),
    value
  }));

  function traceSmoothLine(points) {
    if (!points.length) return;
    ctx.moveTo(points[0].x, points[0].y);
    for (let index = 1; index < points.length - 1; index++) {
      const current = points[index];
      const next = points[index + 1];
      const middleX = (current.x + next.x) / 2;
      const middleY = (current.y + next.y) / 2;
      ctx.quadraticCurveTo(current.x, current.y, middleX, middleY);
    }
    const last = points.at(-1);
    const beforeLast = points.at(-2);
    ctx.quadraticCurveTo(beforeLast.x, beforeLast.y, last.x, last.y);
  }

  function render(progress, glowing = false) {
    ctx.clearRect(0, 0, width, height);
    ctx.strokeStyle = bluePanel ? "rgba(255,255,255,.18)" : "#edf1f7";
    ctx.lineWidth = 1;
    for (let i = 0; i < 4; i++) {
      const y = pad.top + ((height - pad.top - pad.bottom) / 3) * i;
      ctx.beginPath(); ctx.setLineDash([3, 5]); ctx.moveTo(pad.left, y); ctx.lineTo(width - pad.right, y); ctx.stroke();
    }
    ctx.setLineDash([]);
    ctx.save();
    ctx.beginPath(); ctx.rect(0, 0, width * progress, height); ctx.clip();
    const fill = ctx.createLinearGradient(0, 0, 0, height);
    fill.addColorStop(0, bluePanel ? "rgba(255,255,255,.28)" : "rgba(0,78,224,.24)");
    fill.addColorStop(1, bluePanel ? "rgba(255,255,255,.02)" : "rgba(0,78,224,0)");
    ctx.beginPath(); traceSmoothLine(chartPoints);
    ctx.lineTo(chartPoints.at(-1).x, height - pad.bottom); ctx.lineTo(chartPoints[0].x, height - pad.bottom); ctx.closePath(); ctx.fillStyle = fill; ctx.fill();
    ctx.beginPath(); traceSmoothLine(chartPoints);
    ctx.strokeStyle = bluePanel ? "#ffffff" : "#004ee0"; ctx.lineWidth = 2.5; ctx.lineJoin = "round"; ctx.lineCap = "round";
    if (glowing) { ctx.shadowColor = bluePanel ? "rgba(255,255,255,.95)" : "rgba(70,145,255,.95)"; ctx.shadowBlur = 14; }
    ctx.stroke(); ctx.restore();
  }

  if (animate && !trafficEntryAnimationPlayed && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    trafficEntryAnimationPlayed = true;
    const start = performance.now();
    const duration = 1900;
    const frame = now => {
      const raw = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - raw, 3);
      render(eased, raw < 1);
      if (raw < 1) requestAnimationFrame(frame); else render(1, false);
    };
    requestAnimationFrame(frame);
  } else {
    trafficEntryAnimationPlayed = true;
    render(1, false);
  }
}

function updateChart(period, animate = false) {
  activePeriod = Number(period);
  const total = chartData[activePeriod].reduce((sum, value) => sum + value, 0);
  document.getElementById("chartTotal").textContent = total.toLocaleString("en-MY");
  document.getElementById("chartAxis").innerHTML = chartLabels[activePeriod].map(label => `<span>${label}</span>`).join("");
  canvas.setAttribute("aria-label", `Website views chart for ${activePeriod} days`);
  drawChart(animate);
}

function drawProfitChart(animate = false) {
  const rect = profitCanvas.getBoundingClientRect();
  if (!rect.width || !rect.height) return;
  const ratio = window.devicePixelRatio || 1;
  profitCanvas.width = Math.round(rect.width * ratio);
  profitCanvas.height = Math.round(rect.height * ratio);
  const ctx = profitCanvas.getContext("2d");
  ctx.scale(ratio, ratio);
  const width = rect.width;
  const height = rect.height;
  const pad = {top:14,right:8,bottom:9,left:8};
  const max = Math.max(...monthlyProfit) * 1.08;
  const plotHeight = height - pad.top - pad.bottom;
  const slotWidth = (width - pad.left - pad.right) / monthlyProfit.length;
  const barWidth = Math.min(38, slotWidth * .58);
  profitPoints = monthlyProfit.map((value,index) => {
    const barHeight = (value / max) * plotHeight;
    return {x:pad.left + slotWidth * index + slotWidth / 2,y:height - pad.bottom - barHeight,value,barHeight,barWidth};
  });

  function renderBars(progress) {
    ctx.clearRect(0, 0, width, height);
    ctx.strokeStyle = "#edf1f7"; ctx.lineWidth = 1;
    for (let i = 0; i < 4; i++) {
      const y = pad.top + (plotHeight / 3) * i;
      ctx.beginPath(); ctx.setLineDash([3,5]); ctx.moveTo(pad.left,y); ctx.lineTo(width-pad.right,y); ctx.stroke();
    }
    ctx.setLineDash([]);
    profitPoints.forEach((point,index) => {
      const currentHeight = point.barHeight * progress;
      const top = height - pad.bottom - currentHeight;
      const gradient = ctx.createLinearGradient(0,top,0,height-pad.bottom);
      gradient.addColorStop(0,index === monthlyProfit.length - 1 ? "#4f92ff" : "#1768ed");
      gradient.addColorStop(1,"#0042bd");
      ctx.beginPath();
      if (ctx.roundRect) ctx.roundRect(point.x - point.barWidth / 2, top, point.barWidth, currentHeight, [7,7,2,2]);
      else ctx.rect(point.x - point.barWidth / 2, top, point.barWidth, currentHeight);
      ctx.fillStyle = gradient; ctx.fill();
      ctx.fillStyle = "rgba(255,255,255,.17)";
      ctx.fillRect(point.x - point.barWidth / 2 + 4, top + 5, 3, Math.max(0,currentHeight - 10));
    });
  }
  if (animate && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    const start = performance.now();
    const frame = now => {
      const raw = Math.min(1,(now-start)/850);
      renderBars(1-Math.pow(1-raw,3));
      if (raw < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  } else renderBars(1);
}

document.getElementById("pageChartSelect").addEventListener("change", event => {
  const page = event.target.value;
  chartData[30] = [...pageChartData[page]];
  document.getElementById("trafficTitle").textContent = `${pageChartMeta[page].label} views`;
  trafficEntryAnimationPlayed = false;
  updateChart(30, true);
});
canvas.addEventListener("mousemove", event => {
  if (!chartPoints.length) return;
  const bounds = canvas.getBoundingClientRect();
  const x = event.clientX - bounds.left;
  const nearest = chartPoints.reduce((best, point) => Math.abs(point.x - x) < Math.abs(best.x - x) ? point : best);
  tooltip.textContent = `${nearest.value.toLocaleString("en-MY")} view`;
  tooltip.style.display = "block";
  tooltip.style.left = `${Math.min(nearest.x + 8, bounds.width - 75)}px`;
  tooltip.style.top = `${Math.max(nearest.y - 34, 0)}px`;
});
canvas.addEventListener("mouseleave", () => tooltip.style.display = "none");

const sidebar = document.getElementById("sidebar");
const overlay = document.getElementById("mobileOverlay");
const menuButton = document.getElementById("menuButton");
function closeMenu() { sidebar.classList.remove("is-open"); overlay.classList.remove("is-open"); menuButton.setAttribute("aria-expanded", "false"); }
menuButton.addEventListener("click", () => {
  const open = sidebar.classList.toggle("is-open");
  overlay.classList.toggle("is-open", open);
  menuButton.setAttribute("aria-expanded", String(open));
});
overlay.addEventListener("click", closeMenu);

function animateNumber(element) {
  const targetText = element.dataset.countTarget || element.textContent.trim();
  const normalized = targetText.replace(/,/g, "");
  const match = normalized.match(/-?\d+(?:\.\d+)?/);
  if (!match) return;
  element.dataset.countTarget = targetText;
  const target = Number(match[0]);
  const prefix = normalized.slice(0, match.index);
  const suffix = normalized.slice(match.index + match[0].length);
  const decimals = (match[0].split(".")[1] || "").length;
  const start = performance.now();
  const duration = 620;
  const frame = now => {
    const progress = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - progress, 4);
    const value = target * eased;
    element.textContent = prefix + value.toLocaleString("en-MY", {minimumFractionDigits:decimals, maximumFractionDigits:decimals}) + suffix;
    if (progress < 1) requestAnimationFrame(frame); else element.textContent = targetText;
  };
  requestAnimationFrame(frame);
}

function animateView(view) {
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  const cards = view.querySelectorAll(".traffic-panel,.top-pages-panel,.customer-card,.side-metrics .metric-card,.country-panel,.device-panel,.finance-card,.profit-panel,.event-panel,.tournament-summary article,.tournament-card,.guild-summary article,.guild-table-panel,.support-summary article,.support-inbox,.support-conversation,.bug-summary article,.bug-feed,.bug-detail,.capture-panel,.account-summary article,.account-table-panel");
  cards.forEach((card, index) => {
    card.classList.remove("card-enter");
    card.style.setProperty("--enter-delay", `${index * 45}ms`);
    void card.offsetWidth;
    card.classList.add("card-enter");
  });
  view.querySelectorAll(".view-metrics strong,.chart-heading strong,.top-page-list em strong,.customer-card>strong,.side-metrics h3,.country-list>div>strong,.donut strong,.device-list>div>strong,.finance-card>strong,.profit-legend>strong,.event-total>strong,.event-list article>strong,.tournament-summary strong,.tour-card-stats strong,.guild-summary strong,.guild-table td:nth-child(6) b,.support-summary strong,.bug-summary strong,.account-summary strong").forEach((number, index) => setTimeout(() => animateNumber(number), 80 + index * 22));
}

let currentPage = "Dashboard";
let sidebarHoverArmed = true;
document.querySelectorAll(".nav-item").forEach(button => button.addEventListener("click", () => {
  if (window.matchMedia("(min-width: 701px)").matches && !sidebar.classList.contains("is-expanded")) {
    sidebar.classList.add("is-expanded");
    return;
  }
  document.querySelectorAll(".nav-item").forEach(item => { item.classList.remove("is-active"); item.removeAttribute("aria-current"); });
  button.classList.add("is-active");
  button.setAttribute("aria-current", "page");
  document.getElementById("pageTitle").textContent = button.dataset.page;
  closeMenu();
  const topupActive = button.dataset.page === "GNEX Topup";
  const tournamentsActive = button.dataset.page === "Tournaments";
  const guildActive = button.dataset.page === "GNEX Free Fire";
  const supportActive = button.dataset.page === "Customer Support";
  const bugActive = button.dataset.page === "Bug Monitor";
  const accountsActive = button.dataset.page === "Users";
  const returningToDashboard = button.dataset.page === "Dashboard" && currentPage !== "Dashboard";
  document.getElementById("dashboardView").classList.toggle("is-active", !topupActive && !tournamentsActive && !guildActive && !supportActive && !bugActive && !accountsActive);
  document.getElementById("topupView").classList.toggle("is-active", topupActive);
  document.getElementById("tournamentsView").classList.toggle("is-active", tournamentsActive);
  document.getElementById("guildView").classList.toggle("is-active", guildActive);
  document.getElementById("supportView").classList.toggle("is-active", supportActive);
  document.getElementById("bugView").classList.toggle("is-active", bugActive);
  document.getElementById("accountsView").classList.toggle("is-active", accountsActive);
  if (topupActive) requestAnimationFrame(() => { drawProfitChart(true); animateView(document.getElementById("topupView")); });
  else if (tournamentsActive) requestAnimationFrame(() => animateView(document.getElementById("tournamentsView")));
  else if (guildActive) requestAnimationFrame(() => animateView(document.getElementById("guildView")));
  else if (supportActive) requestAnimationFrame(() => animateView(document.getElementById("supportView")));
  else if (bugActive) requestAnimationFrame(() => animateView(document.getElementById("bugView")));
  else if (accountsActive) requestAnimationFrame(() => animateView(document.getElementById("accountsView")));
  else if (button.dataset.page === "Dashboard") {
    if (returningToDashboard) trafficEntryAnimationPlayed = false;
    requestAnimationFrame(() => { drawChart(returningToDashboard); animateView(document.getElementById("dashboardView")); });
  }
  else showToast(`${button.dataset.page} is a design preview for now.`);
  currentPage = button.dataset.page;
  sidebar.classList.remove("is-expanded");
  sidebarHoverArmed = false;
  button.blur();
}));

document.querySelector(".brand").addEventListener("click", () => {
  if (window.matchMedia("(min-width: 701px)").matches) {
    const expanded = sidebar.classList.toggle("is-expanded");
    sidebarHoverArmed = expanded;
  }
});

sidebar.addEventListener("mouseleave", () => {
  if (!window.matchMedia("(min-width: 701px)").matches) return;
  sidebar.classList.remove("is-expanded");
  sidebarHoverArmed = true;
});

sidebar.addEventListener("mouseenter", () => {
  if (window.matchMedia("(min-width: 701px)").matches && sidebarHoverArmed) sidebar.classList.add("is-expanded");
});

function showToast(message) {
  const toast = document.getElementById("toast");
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(showToast.timer);
  showToast.timer = setTimeout(() => toast.classList.remove("show"), 2400);
}

const formatNumber = value => Number(value || 0).toLocaleString("en-MY");
const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[character]));

async function loadAnalytics() {
  try {
    const response = await fetch("api/analytics.php", {headers:{"Accept":"application/json"}});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || "Analytics is unavailable.");

    document.getElementById("todayViews").textContent = formatNumber(data.views.today);
    document.getElementById("weekViews").textContent = formatNumber(data.views.week);
    document.getElementById("monthViews").textContent = formatNumber(data.views.month);
    document.getElementById("allViews").textContent = formatNumber(data.views.allTime);
    document.getElementById("totalUsers").textContent = formatNumber(data.users.total);
    document.getElementById("returningUsers").textContent = formatNumber(data.users.returning);

    const trend = (data.trend || []).map(item => Number(item.views));
    if (trend.length) {
      chartData[90] = trend;
      chartData[30] = trend.slice(-30);
      chartData[7] = trend.slice(-7);
      pageChartData.all = [...chartData[30]];
      updateChart(activePeriod);
    }

    if (data.pages?.length) {
      document.getElementById("topPageList").innerHTML = data.pages.slice(0, 5).map((page, index) => `<article><b>${index + 1}</b><span><strong>${escapeHtml(page.title)}</strong><small>${escapeHtml(page.path)}</small></span><em><strong>${formatNumber(page.views)}</strong><small>${formatNumber(page.users)} users</small></em></article>`).join("");
    }
    const status = document.getElementById("dataStatus");
    status.classList.add("is-live");
    status.innerHTML = "<i></i> Live Google Analytics";
  } catch (error) {
    console.info("Google Analytics demo fallback:", error.message);
  }
}

document.getElementById("downloadButton").addEventListener("click", () => showToast("Report download will be connected after the design is approved."));
const loginScreen = document.getElementById("loginScreen");
const loginForm = document.getElementById("adminLoginForm");

function showLogin() {
  currentPage = "Dashboard";
  document.getElementById("pageTitle").textContent = "Dashboard";
  document.querySelectorAll(".nav-item").forEach(item => {
    const active = item.dataset.page === "Dashboard";
    item.classList.toggle("is-active", active);
    if (active) item.setAttribute("aria-current", "page"); else item.removeAttribute("aria-current");
  });
  document.querySelectorAll(".dashboard-view").forEach(view => view.classList.toggle("is-active", view.id === "dashboardView"));
  sidebar.classList.remove("is-expanded");
  loginScreen.classList.remove("is-hidden", "is-exiting");
  document.body.classList.add("is-locked");
  loginForm.reset();
  document.getElementById("loginPassword").type = "password";
  document.getElementById("togglePassword").textContent = "Show";
  requestAnimationFrame(() => document.getElementById("loginAdminId").focus());
}

loginForm.addEventListener("submit", event => {
  event.preventDefault();
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  loginScreen.classList.add("is-exiting");
  setTimeout(() => {
    document.body.classList.remove("is-locked");
    loginScreen.classList.add("is-hidden");
    trafficEntryAnimationPlayed = false;
    updateChart(30, true);
    animateView(document.getElementById("dashboardView"));
  }, reducedMotion ? 80 : 6900);
});

document.getElementById("togglePassword").addEventListener("click", event => {
  const password = document.getElementById("loginPassword");
  const visible = password.type === "text";
  password.type = visible ? "password" : "text";
  event.currentTarget.textContent = visible ? "Show" : "Hide";
  password.focus();
});

document.querySelector(".logout-button").addEventListener("click", showLogin);
document.querySelector(".quiet-button").addEventListener("click", () => showToast("The full country report will be added in the next phase."));
document.querySelector(".topup-report").addEventListener("click", () => showToast("The GNEX Topup report will be connected later."));
document.querySelector(".event-details").addEventListener("click", () => showToast("The complete event expense list will be added later."));
document.querySelector(".create-tournament").addEventListener("click", () => showToast("Tournament creation will be connected after the design phase."));
document.querySelectorAll(".tour-admin-button").forEach(button => button.addEventListener("click", () => showToast(`${button.dataset.tournament} admin panel will be connected later.`)));
document.querySelector(".add-player").addEventListener("click", () => showToast("The add player form will be connected later."));
document.querySelectorAll("[data-player]").forEach(button => button.addEventListener("click", () => showToast(`${button.dataset.player} player controls will be connected later.`)));
document.getElementById("guildSearch").addEventListener("input", event => {
  const query = event.target.value.trim().toLowerCase();
  document.querySelectorAll("#guildPlayerRows tr").forEach(row => row.classList.toggle("is-hidden", !row.textContent.toLowerCase().includes(query)));
});
document.querySelectorAll("[data-support-filter]").forEach(button => button.addEventListener("click", () => {
  document.querySelectorAll("[data-support-filter]").forEach(item => item.classList.toggle("is-active", item === button));
  document.querySelectorAll(".ticket-item").forEach(ticket => ticket.hidden = button.dataset.supportFilter !== "all" && ticket.dataset.ticketType !== button.dataset.supportFilter);
}));
document.querySelectorAll(".ticket-item").forEach(ticket => ticket.addEventListener("click", () => {
  document.querySelectorAll(".ticket-item").forEach(item => item.classList.toggle("is-active", item === ticket));
  document.getElementById("conversationType").textContent = ticket.dataset.ticketType === "report" ? "Submitted form" : "Customer chat";
  document.getElementById("conversationTitle").textContent = ticket.dataset.ticketTitle;
  document.getElementById("conversationUser").textContent = ticket.dataset.ticketUser;
  document.getElementById("conversationMessage").textContent = ticket.dataset.ticketMessage;
  document.getElementById("conversationMeta").textContent = ticket.dataset.ticketMeta;
}));
document.getElementById("supportReplyForm").addEventListener("submit", event => {
  event.preventDefault();
  const input = document.getElementById("supportReply");
  if (!input.value.trim()) return showToast("Write a reply first.");
  input.value = "";
  showToast("Reply saved in this design preview.");
});
document.querySelector(".resolve-ticket").addEventListener("click", () => showToast("Ticket marked as resolved in this design preview."));
document.querySelector(".resolve-bug").addEventListener("click", () => showToast("Bug marked as resolved in this design preview."));
function filterAccounts() {
  const query = document.getElementById("accountSearch").value.trim().toLowerCase();
  const status = document.getElementById("accountStatus").value;
  document.querySelectorAll("#accountRows tr").forEach(row => {
    const matchesText = row.textContent.toLowerCase().includes(query);
    const matchesStatus = status === "all" || row.dataset.accountStatus === status;
    row.classList.toggle("is-hidden", !matchesText || !matchesStatus);
  });
}
document.getElementById("accountSearch").addEventListener("input", filterAccounts);
document.getElementById("accountStatus").addEventListener("change", filterAccounts);
document.querySelector(".invite-user").addEventListener("click", () => showToast("The customer account form will be connected with the login system later."));
document.querySelectorAll("[data-account]").forEach(button => button.addEventListener("click", () => showToast(`${button.dataset.account} account controls will be connected later.`)));
profitCanvas.addEventListener("mousemove", event => {
  if (!profitPoints.length) return;
  const bounds = profitCanvas.getBoundingClientRect();
  const x = event.clientX - bounds.left;
  const nearest = profitPoints.reduce((best, point) => Math.abs(point.x - x) < Math.abs(best.x - x) ? point : best);
  profitTooltip.textContent = `RM ${nearest.value.toLocaleString("en-MY")}`;
  profitTooltip.style.display = "block";
  profitTooltip.style.left = `${Math.min(nearest.x + 8, bounds.width - 82)}px`;
  profitTooltip.style.top = `${Math.max(nearest.y - 34, 0)}px`;
});
profitCanvas.addEventListener("mouseleave", () => profitTooltip.style.display = "none");
window.addEventListener("resize", () => { drawChart(); drawProfitChart(); });
updateChart(30, false);
loadAnalytics();
