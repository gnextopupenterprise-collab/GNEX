const API_URL = 'api/scrim.php';
if (location.port === '5500') {
  location.replace('http://localhost/Training%20coding%203%20(website%20gnex)/scrim.html');
}
let state = {ok:false, team:null, admin:null, scrims:[], requests:[], stats:[], history:[], messages:[], support_messages:[], group_messages:[]};
let activeFilter = 'all';
let activeView = 'home';
let activeDealId = 0;
let pendingDealScrollToBottom = false;
let openScrimDetailIds = new Set();
let seenMessageIds = new Set();
let unreadChatCount = 0;
let unreadChatByScrim = {};
let statePollTimer = null;
let isPollingState = false;
const STATE_POLL_MS = 3500;

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#039;'
  }[char]));
}

function statusClass(status){
  return ['open','pending','confirmed','completed','rejected','reported','no_show_pending','no_show_accepted'].includes(status) ? status : '';
}

const MALAYSIA_TIME_ZONE = 'Asia/Kuala_Lumpur';

function malaysiaDate(value){
  if (!value) return null;
  const text = String(value).trim();
  const hasTimezone = /(?:z|[+-]\d{2}:?\d{2})$/i.test(text);
  const normalized = text.includes('T') ? text : text.replace(' ', 'T');
  const date = new Date(hasTimezone ? normalized : `${normalized}+08:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatMalaysiaTime(date){
  return date.toLocaleTimeString('en-MY', {
    timeZone:MALAYSIA_TIME_ZONE,
    hour:'2-digit',
    minute:'2-digit',
    hour12:true
  }).replace(/\s/g, '').toUpperCase();
}

function formatDate(value){
  if (!value) return '-';
  const date = malaysiaDate(value);
  if (!date) return escapeHtml(value);
  const datePart = date.toLocaleDateString('ms-MY', {
    timeZone:MALAYSIA_TIME_ZONE,
    day:'2-digit',
    month:'short',
    year:'numeric'
  });
  return `${datePart}, ${formatMalaysiaTime(date)}`;
}

function inputDateTimeValue(value){
  const date = malaysiaDate(value);
  if (!date) return '';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone:MALAYSIA_TIME_ZONE,
    year:'numeric',
    month:'2-digit',
    day:'2-digit',
    hour:'2-digit',
    minute:'2-digit',
    hour12:false
  }).formatToParts(date).reduce((output, part) => {
    output[part.type] = part.value;
    return output;
  }, {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function malaysiaInputDateTime(dateValue, timeValue){
  if (!dateValue || !timeValue) return '';
  return `${dateValue}T${timeValue}:00+08:00`;
}

function showToast(message){
  const toast = $('#toast');
  toast.textContent = message;
  toast.classList.add('is-visible');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
}

function setAuthFormMessage(message = ''){
  const output = $('#authFormMessage');
  if (!output) return;
  output.textContent = message;
  output.hidden = !message;
}

function strongPasswordError(password){
  if (String(password).length < 4) return 'Password mesti sekurang-kurangnya 4 aksara.';
  if (!/[A-Z]/.test(password)) return 'Password mesti mempunyai sekurang-kurangnya 1 huruf besar.';
  if (!/[0-9]/.test(password)) return 'Password mesti mempunyai sekurang-kurangnya 1 nombor.';
  if (!/[^A-Za-z0-9\s]/.test(password)) return 'Password mesti mempunyai sekurang-kurangnya 1 simbol seperti !, @ atau #.';
  return '';
}

function canUseBrowserNotifications(){
  return 'Notification' in window && (location.protocol === 'https:' || location.hostname === 'localhost');
}

function canUseWebPush(){
  return canUseBrowserNotifications() && 'serviceWorker' in navigator && 'PushManager' in window;
}

function urlBase64ToUint8Array(value){
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);
  for (let index = 0; index < raw.length; index += 1) {
    output[index] = raw.charCodeAt(index);
  }
  return output;
}

async function savePushTokenToWorker(registration, token){
  const readyRegistration = await navigator.serviceWorker.ready;
  const worker = readyRegistration.active || registration.active;
  if (!worker) throw new Error('Service worker belum ready. Cuba aktifkan noti semula.');
  await new Promise((resolve, reject) => {
    const channel = new MessageChannel();
    const timer = window.setTimeout(() => reject(new Error('Push setup timeout.')), 5000);
    channel.port1.onmessage = () => {
      window.clearTimeout(timer);
      resolve();
    };
    worker.postMessage({type:'SET_PUSH_TOKEN', token}, [channel.port2]);
  });
}

async function enableWebPushNotifications(){
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (isIos && !isStandalone) {
    window.alert('Untuk iPhone: tekan Share, pilih Add to Home Screen, kemudian buka GNEX Scrim dari ikon Home Screen dan aktifkan notification semula.');
    return;
  }
  if (!canUseWebPush()) {
    showToast('Push noti perlu HTTPS dan browser support.');
    return;
  }
  if (!state.push_public_key) {
    showToast('Push key belum setup di server.');
    return;
  }
  const permission = Notification.permission === 'granted'
    ? 'granted'
    : await Notification.requestPermission();
  if (permission !== 'granted') {
    showToast('Notification tidak diaktifkan.');
    return;
  }

  const registration = await navigator.serviceWorker.register('scrim-sw.js?v=23');
  const existing = await registration.pushManager.getSubscription();
  const subscription = existing || await registration.pushManager.subscribe({
    userVisibleOnly:true,
    applicationServerKey:urlBase64ToUint8Array(state.push_public_key)
  });
  const data = new FormData();
  data.set('action', 'save_push_subscription');
  data.set('subscription', JSON.stringify(subscription));
  const savedSubscription = await postForm(data);
  if (savedSubscription.push_device_token) {
    await savePushTokenToWorker(registration, savedSubscription.push_device_token);
  }
  const testData = new FormData();
  testData.set('action', 'test_push');
  await postForm(testData);
}

async function showScrimNotification(title, body, tag = 'scrim-chat'){
  if (!canUseBrowserNotifications() || Notification.permission !== 'granted') return;
  if ('serviceWorker' in navigator) {
    const registration = await navigator.serviceWorker.getRegistration('/')
      || await navigator.serviceWorker.getRegistration()
      || await navigator.serviceWorker.register('scrim-sw.js?v=23');
    await registration.showNotification(title, {
      body,
      tag,
      icon:'images/logo-gnex-esport-64x64.png',
      badge:'images/logo-gnex-esport-64x64.png',
      data:{url:'scrim.html'}
    });
    return;
  }
  const notification = new Notification(title, {
    body,
    tag,
    icon:'images/logo-gnex-esport-64x64.png'
  });
  notification.onclick = () => {
    window.focus();
    toggleProfile(true);
  };
}

function notifyIncomingChat(message){
  const title = `GNEX Scrim: ${message.sender_name || 'Team'}`;
  const body = String(message.message || '').slice(0, 120);
  showScrimNotification(title, body, `scrim-chat-${message.scrim_id}`).catch(console.warn);
  showToast(`Chat baru dari ${message.sender_name || 'team lawan'}. Phone push dihantar jika device subscribed.`);
}

function dealIsOpen(){
  return activeView === 'deal';
}

function activeDealIsOpen(scrimId){
  return dealIsOpen() && Number(activeDealId || 0) === Number(scrimId || 0);
}

function syncIncomingMessages(nextState, shouldNotify = true){
  const teamId = Number(nextState.team?.id || 0);
  const freshMessages = [];
  (nextState.messages || []).forEach((message) => {
    const messageId = Number(message.id || 0);
    if (!messageId || seenMessageIds.has(messageId)) return;
    seenMessageIds.add(messageId);
    if (teamId && Number(message.sender_team_id) !== teamId) {
      freshMessages.push(message);
    }
  });
  if (shouldNotify && freshMessages.length) {
    freshMessages.forEach((message) => {
      const scrimId = Number(message.scrim_id || 0);
      if (!scrimId || activeDealIsOpen(scrimId)) return;
      unreadChatByScrim[scrimId] = Number(unreadChatByScrim[scrimId] || 0) + 1;
    });
    unreadChatCount = Object.values(unreadChatByScrim).reduce((total, count) => total + Number(count || 0), 0);
    if (dealIsOpen()) {
      renderDealApp();
    }
    notifyIncomingChat(freshMessages[freshMessages.length - 1]);
  }
}

async function readApiResponse(response){
  const raw = await response.text();
  try {
    return JSON.parse(raw);
  } catch (error) {
    const status = response.status ? ` (${response.status})` : '';
    throw new Error(`API server error${status}. Semak PHP dan database hosting.`);
  }
}

async function postForm(formOrData, options = {}){
  const body = formOrData instanceof FormData ? formOrData : new FormData(formOrData);
  const response = await fetch(API_URL, {method:'POST', body});
  const payload = await readApiResponse(response);
  if (!payload.ok) {
    const error = new Error(payload.message || 'Request gagal.');
    error.needsPhone = Boolean(payload.needs_phone);
    throw error;
  }
  if (payload.scrims) {
    syncIncomingMessages(payload, Boolean(state.team));
    state = payload;
    if (options.preserveChatScroll) {
      renderPreservingChatDrafts(options);
      if (options.scrollChatToBottom) {
        scrollChatToBottom(options.scrollChatToBottom);
      }
    } else {
      render();
    }
  }
  showToast(payload.message || 'Berjaya.');
  return payload;
}

async function sendChatForm(form){
  const input = $('input[name="message"]', form);
  const scrimId = $('input[name="scrim_id"]', form)?.value;
  if (!input || !input.value.trim()) {
    showToast('Tulis mesej dulu.');
    input?.focus();
    return;
  }
  await postForm(form, {preserveChatScroll:true, scrollChatToBottom:scrimId, clearChatDraft:scrimId});
  const nextForm = $$('.chat-form').find((item) => $('input[name="scrim_id"]', item)?.value === String(scrimId));
  const nextInput = nextForm ? $('input[name="message"]', nextForm) : input;
  if (nextInput) nextInput.value = '';
  input?.blur();
}

function currentTeamId(){
  return state.team ? Number(state.team.id) : 0;
}

function isAdmin(){
  return Boolean(state.admin);
}

function openAuthGate(mode = 'login'){
  const gate = $('#authGate');
  if (!gate) return;
  gate.hidden = false;
  document.body.classList.add('auth-modal-open');
  const tab = $(`[data-auth-tab="${mode}"]`, gate);
  tab?.click();
  window.setTimeout(() => $('#teamName')?.focus(), 0);
}

function closeAuthGate(){
  const gate = $('#authGate');
  if (!gate) return;
  gate.hidden = true;
  document.body.classList.remove('auth-modal-open');
}

function requireTeamLogin(message = 'Sila login atau register team dahulu.'){
  if (state.team) return true;
  showToast(message);
  openAuthGate('login');
  return false;
}

function hasTeamPhone(){
  return Boolean(String(state.team?.phone_number || '').trim());
}

function isScrimReady(){
  return hasTeamPhone()
    && Boolean(String(state.team?.captain_name || '').trim())
    && Boolean(String(state.team?.player_ign || '').trim())
    && Boolean(String(state.team?.player_game_id || '').trim());
}

function openPhoneRequiredPanel(){
  toggleProfile(true);
  setCollapsible('#profileEditPanel', true);
  $('#editProfileBtn')?.setAttribute('aria-expanded', 'true');
  const target = !state.team?.captain_name
    ? $('#profileCaptain')
    : (!state.team?.phone_number
      ? $('#profilePhone')
      : (!state.team?.player_ign ? $('#profilePlayerIgn') : $('#profilePlayerId')));
  target?.focus();
  showToast('Lengkapkan Captain, telefon, Player IGN dan Player ID sebelum create atau join scrim.');
}

function requestFor(scrim){
  return state.requests.find((request) => Number(request.scrim_id) === Number(scrim.id) && Number(request.requester_team_id) === currentTeamId());
}

function canControl(scrim){
  const teamId = currentTeamId();
  return teamId && [Number(scrim.creator_team_id), Number(scrim.opponent_team_id || 0)].includes(teamId);
}

function messagesFor(scrimId){
  return (state.messages || []).filter((message) => Number(message.scrim_id) === Number(scrimId));
}

function isDealChatExpired(scrim){
  const scrimTime = malaysiaDate(scrim?.date_time)?.getTime();
  return Boolean(scrimTime) && Date.now() >= scrimTime + (3 * 24 * 60 * 60 * 1000);
}

function clearExpiredDealUnread(){
  const expiredIds = new Set((state.scrims || [])
    .filter(isDealChatExpired)
    .map((scrim) => Number(scrim.id)));
  Object.keys(unreadChatByScrim).forEach((scrimId) => {
    if (expiredIds.has(Number(scrimId))) delete unreadChatByScrim[scrimId];
  });
  unreadChatCount = Object.values(unreadChatByScrim)
    .reduce((total, count) => total + Number(count || 0), 0);
}

function activeDeals(){
  const latestMessageTime = new Map();
  (state.messages || []).forEach((message) => {
    const scrimId = Number(message.scrim_id || 0);
    const timestamp = malaysiaDate(message.created_at)?.getTime() || Number(message.id || 0);
    if (timestamp > Number(latestMessageTime.get(scrimId) || 0)) {
      latestMessageTime.set(scrimId, timestamp);
    }
  });
  return state.scrims
    .filter((scrim) => !isDealChatExpired(scrim)
      && (isAdmin() || canControl(scrim))
      && ['pending','confirmed','completed'].includes(scrim.status))
    .sort((first, second) => {
      const firstActivity = Number(latestMessageTime.get(Number(first.id)))
        || malaysiaDate(first.created_at)?.getTime()
        || Number(first.id || 0);
      const secondActivity = Number(latestMessageTime.get(Number(second.id)))
        || malaysiaDate(second.created_at)?.getTime()
        || Number(second.id || 0);
      return secondActivity - firstActivity || Number(second.id || 0) - Number(first.id || 0);
    });
}

function dealContactName(scrim){
  if (isAdmin()) return `${scrim.creator_name || 'Host'} VS ${scrim.opponent_name || 'Opponent'}`;
  return currentTeamId() === Number(scrim.creator_team_id)
    ? (scrim.opponent_name || 'Opponent')
    : scrim.creator_name;
}

function dealInitials(name){
  return String(name || 'T').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase();
}

function collectChatDrafts(){
  const drafts = {};
  $$('.chat-form').forEach((form) => {
    const scrimId = $('input[name="scrim_id"]', form)?.value;
    const input = $('input[name="message"]', form);
    if (scrimId && input && input.value) {
      drafts[scrimId] = input.value;
    }
  });
  return drafts;
}

function collectScrollState(){
  const profileCard = $('.profile-card');
  const chatLogs = {};
  $$('.chat-form').forEach((form) => {
    const scrimId = $('input[name="scrim_id"]', form)?.value;
    const log = form.closest('.deal-chat, .deal-chat-screen')?.querySelector('.deal-chat-log, .deal-chat-full-log');
    if (scrimId && log) {
      chatLogs[scrimId] = {
        top:log.scrollTop,
        bottom:log.scrollHeight - log.scrollTop - log.clientHeight
      };
    }
  });
  return {
    profileTop:profileCard ? profileCard.scrollTop : 0,
    chatLogs
  };
}

function restoreChatDrafts(drafts){
  Object.entries(drafts).forEach(([scrimId, value]) => {
    const form = $$('.chat-form').find((item) => $('input[name="scrim_id"]', item)?.value === scrimId);
    const input = form ? $('input[name="message"]', form) : null;
    if (input) input.value = value;
  });
}

function restoreScrollState(scrollState){
  const profileCard = $('.profile-card');
  if (profileCard) {
    profileCard.scrollTop = scrollState.profileTop || 0;
  }
  Object.entries(scrollState.chatLogs || {}).forEach(([scrimId, value]) => {
    const log = chatLogForScrim(scrimId);
    if (!log) return;
    if (value.bottom < 40) {
      log.scrollTop = log.scrollHeight;
      return;
    }
    log.scrollTop = value.top;
  });
}

function chatLogForScrim(scrimId){
  const id = String(scrimId);
  const forms = $$('.chat-form').filter((item) => $('input[name="scrim_id"]', item)?.value === id);
  const dealView = $('#dealView');
  const dealForm = forms.find((form) => dealView && !dealView.hidden && dealView.contains(form));
  const form = dealForm || forms.find((item) => item.offsetParent !== null) || forms[0];
  return form?.closest('.deal-chat, .deal-chat-screen')?.querySelector('.deal-chat-log, .deal-chat-full-log') || null;
}

function scrollChatToBottom(scrimId){
  const log = chatLogForScrim(scrimId);
  if (log) {
    log.scrollTop = log.scrollHeight;
  }
}

function activeChatInput(){
  const element = document.activeElement;
  return element?.matches?.('.chat-form input[name="message"]') ? element : null;
}

function syncTopNavHeight(){
  const nav = $('.scrim-nav');
  if (!nav) return;
  const baseHeight = Math.ceil(nav.getBoundingClientRect().height || nav.offsetHeight || 69);
  const compactPhone = window.matchMedia('(max-width: 430px) and (max-height: 940px)').matches;
  document.documentElement.style.setProperty('--scrim-top-nav-height', `${baseHeight}px`);
  document.documentElement.style.setProperty('--scrim-content-top-offset', `${baseHeight + (compactPhone ? 28 : 0)}px`);
}

function syncKeyboardViewport(){
  document.body.classList.toggle('chat-keyboard-open', Boolean(activeChatInput()));
}

function forceDealViewportTop(){
  if (activeView !== 'deal') return;
  window.scrollTo({top:0, left:0, behavior:'auto'});
  document.documentElement.scrollTop = 0;
  document.body.scrollTop = 0;
}

function settleDealViewportAfterKeyboard(){
  syncKeyboardViewport();
  forceDealViewportTop();
}

function renderPreservingChatDrafts(options = {}){
  const drafts = collectChatDrafts();
  if (options.clearChatDraft) {
    delete drafts[String(options.clearChatDraft)];
  }
  const scrollState = collectScrollState();
  render();
  restoreChatDrafts(drafts);
  restoreScrollState(scrollState);
}

function formatScrimDate(value){
  const date = malaysiaDate(value);
  if (!date) return {date:'-', time:'-'};
  return {
    date:date.toLocaleDateString('ms-MY', {timeZone:MALAYSIA_TIME_ZONE, day:'2-digit', month:'long', year:'numeric'}).toUpperCase(),
    time:formatMalaysiaTime(date)
  };
}

function scrimDateObject(value){
  return malaysiaDate(value);
}

function isOpenBoardScrim(scrim){
  const matchDate = scrimDateObject(scrim.date_time);
  return scrim.status === 'open'
    && !Number(scrim.opponent_team_id || 0)
    && (!matchDate || matchDate.getTime() >= Date.now());
}

function pendingResultDeals(){
  const teamId = currentTeamId();
  if (!teamId) return [];
  return state.scrims.filter((scrim) => Number(scrim.opponent_team_id || 0) === teamId && scrim.status === 'confirmed' && scrim.result_status === 'pending');
}

function hostResultDeals(){
  const teamId = currentTeamId();
  if (!teamId) return [];
  return state.scrims.filter((scrim) => {
    const isHost = Number(scrim.creator_team_id) === teamId;
    const isConfirmed = scrim.status === 'confirmed';
    const canSubmit = !['pending','reported','no_show_pending'].includes(String(scrim.result_status || ''));
    return isHost && isConfirmed && canSubmit;
  });
}

function scoreRowsFor(scrim){
  const parts = String(scrim.pending_result_score || '').split('-').map((part) => part.trim()).filter(Boolean);
  const winnerScore = parts[0] || '0';
  const loserScore = parts[1] || '0';
  const creatorWon = Number(scrim.pending_winner_team_id || 0) === Number(scrim.creator_team_id);
  return [
    {
      name: scrim.creator_name,
      score: creatorWon ? winnerScore : loserScore
    },
    {
      name: scrim.opponent_name || 'Opponent',
      score: creatorWon ? loserScore : winnerScore
    }
  ];
}

function renderSession(){
  const profileTeamName = $('#profileTeamName');
  const profileCardName = $('#profileCardName');
  const profileCardMeta = $('#profileCardMeta');
  const profileCaptain = $('#profileCaptain');
  const profilePhone = $('#profilePhone');
  const profilePlayerIgn = $('#profilePlayerIgn');
  const profilePlayerId = $('#profilePlayerId');
  const profileRankBadge = $('#profileRankBadge');
  const profileResultHint = $('#profileResultHint');
  const rosterGames = $('#rosterGames');
  const profileBadge = $('#profileBadge');
  const notifyButton = $('#enableNotifyBtn');
  const rosterWins = $('#rosterWins');
  const rosterLosses = $('#rosterLosses');
  const rosterRank = $('#rosterRank');
  const pendingRequests = state.team
    ? state.requests.filter((request) => Number(request.creator_team_id) === currentTeamId() && request.status === 'pending')
    : [];
  const pendingResults = pendingResultDeals();
  const hostResults = hostResultDeals();
  const resultBadge = $('#resultBadge');
  const dealNavBadge = $('#dealNavBadge');
  const reviewNavBadge = $('#reviewNavBadge');
  const reviewActionCount = pendingRequests.length + pendingResults.length + hostResults.length;

  if (resultBadge) {
    const resultCount = pendingResults.length + hostResults.length;
    resultBadge.textContent = resultCount;
    resultBadge.classList.toggle('hidden', resultCount === 0);
  }

  if (reviewNavBadge) {
    reviewNavBadge.textContent = reviewActionCount;
    reviewNavBadge.classList.toggle('hidden', reviewActionCount === 0);
  }

  if (dealNavBadge) {
    dealNavBadge.textContent = unreadChatCount;
    dealNavBadge.classList.toggle('hidden', unreadChatCount === 0);
  }

  if (notifyButton) {
    const enabled = canUseBrowserNotifications() && Notification.permission === 'granted';
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const blocked = canUseBrowserNotifications() && Notification.permission === 'denied';
    const label = enabled ? 'NOTI ON' : (isIos && !isStandalone ? 'INSTALL APP' : (blocked ? 'NOTI BLOCKED' : 'ENABLE NOTI'));
    notifyButton.classList.toggle('on', enabled);
    notifyButton.classList.toggle('off', !enabled);
    notifyButton.innerHTML = `<span aria-hidden="true"></span>${label}`;
  }

  if (state.team) {
    if (profileTeamName) profileTeamName.textContent = state.team.name;
    if (profileCardName) profileCardName.textContent = state.team.name;
    if (profileCardMeta) {
      const captainText = state.team.captain_name || 'Belum set';
      const phoneText = state.team.phone_number || 'Belum set';
      const playerIgnText = state.team.player_ign || 'Belum set';
      const playerIdText = state.team.player_game_id || 'Belum set';
      profileCardMeta.innerHTML = `
        <div><span>Team ID</span><strong>#${escapeHtml(state.team.id)}</strong></div>
        <div><span>Captain</span><strong>${escapeHtml(captainText)}</strong></div>
        <div><span>Phone</span><strong>${escapeHtml(phoneText)}</strong></div>
        <div><span>Player IGN</span><strong>${escapeHtml(playerIgnText)}</strong></div>
        <div><span>Player ID</span><strong>${escapeHtml(playerIdText)}</strong></div>
      `;
    }
    if (profileCaptain && document.activeElement !== profileCaptain) {
      profileCaptain.value = state.team.captain_name || '';
    }
    if (profilePhone && document.activeElement !== profilePhone) {
      profilePhone.value = state.team.phone_number || '';
    }
    if (profilePlayerIgn && document.activeElement !== profilePlayerIgn) {
      profilePlayerIgn.value = state.team.player_ign || '';
    }
    if (profilePlayerId && document.activeElement !== profilePlayerId) {
      profilePlayerId.value = state.team.player_game_id || '';
    }
    if (profileBadge) {
      const profileBadgeCount = pendingRequests.length;
      profileBadge.textContent = profileBadgeCount;
      profileBadge.classList.toggle('hidden', profileBadgeCount === 0);
    }
    const statIndex = state.stats.findIndex((item) => item.name === state.team.name);
    const teamStats = statIndex >= 0 ? state.stats[statIndex] : null;
    const rankText = statIndex >= 0 ? `#${statIndex + 1}` : '-';
    if (profileRankBadge) profileRankBadge.textContent = `RANK ${rankText}`;
    if (rosterGames) rosterGames.textContent = Number(teamStats?.played || 0);
    if (rosterWins) rosterWins.textContent = Number(teamStats?.wins || 0);
    if (rosterLosses) rosterLosses.textContent = Number(teamStats?.losses || 0);
    if (rosterRank) rosterRank.textContent = rankText;
    if (profileResultHint) {
      const resultCount = pendingResults.length + hostResults.length;
      profileResultHint.innerHTML = resultCount
        ? `<button class="btn primary block" type="button" id="openProfileResultBtn">${resultCount} update point action</button>`
        : (isScrimReady()
          ? '<p class="empty">Tiada result pending untuk confirm.</p>'
          : '<button class="btn gold block" type="button" id="profilePhoneRequiredBtn">Lengkapkan Profile Scrim</button>');
    }
  } else {
    if (profileTeamName) profileTeamName.textContent = isAdmin() ? 'ADMIN' : 'LOGIN';
    if (profileCardName) profileCardName.textContent = 'TEAM';
    if (profileCardMeta) profileCardMeta.textContent = 'Logged in team';
    if (profileCaptain) profileCaptain.value = '';
    if (profilePhone) profilePhone.value = '';
    if (profilePlayerIgn) profilePlayerIgn.value = '';
    if (profilePlayerId) profilePlayerId.value = '';
    if (profileRankBadge) profileRankBadge.textContent = 'RANK -';
    if (profileBadge) profileBadge.classList.add('hidden');
    if (rosterGames) rosterGames.textContent = '0';
    if (rosterWins) rosterWins.textContent = '0';
    if (rosterLosses) rosterLosses.textContent = '0';
    if (rosterRank) rosterRank.textContent = '-';
    if (profileResultHint) profileResultHint.innerHTML = '';
  }
}

function renderResultReview(){
  const box = $('#resultReviewContent');
  if (!box) return;
  const pendingResults = pendingResultDeals();
  const hostResults = hostResultDeals();
  const scrim = pendingResults[0];

  if (!scrim) {
    const hostScrim = hostResults[0];
    if (hostScrim) {
      const winnerOptions = [
        [hostScrim.creator_team_id, hostScrim.creator_name],
        [hostScrim.opponent_team_id, hostScrim.opponent_name]
      ].filter(([id]) => id).map(([id, name]) => `<option value="${id}">${escapeHtml(name)}</option>`).join('');

      box.innerHTML = `
        <article class="result-review-panel">
          <div class="result-review-head">
            <div>
              <h3>${escapeHtml(hostScrim.title)}</h3>
              <p>${escapeHtml(hostScrim.creator_name)} vs ${escapeHtml(hostScrim.opponent_name || 'TBD')} - ${formatDate(hostScrim.date_time)}</p>
            </div>
            <span class="chip confirmed">host update</span>
          </div>
          <form class="form-grid result-form">
            <input type="hidden" name="action" value="update_result">
            <input type="hidden" name="scrim_id" value="${hostScrim.id}">
            <div class="field">
              <label>Winner</label>
              <select name="winner_team_id" required>${winnerOptions}</select>
            </div>
            <div class="field">
              <label>Score</label>
              <input name="result_score" placeholder="Contoh: 2-0" required>
            </div>
            <button class="btn green block" type="submit">Submit Result To Opponent</button>
          </form>
        </article>
      `;
      return;
    }

    const reportedDeal = state.scrims.find((deal) => Number(deal.opponent_team_id || 0) === currentTeamId() && deal.result_status === 'reported');
    const waitingHostDeal = state.scrims.find((deal) => Number(deal.creator_team_id || 0) === currentTeamId() && deal.status === 'confirmed' && deal.result_status === 'pending');
    const hostReportedDeal = state.scrims.find((deal) => Number(deal.creator_team_id || 0) === currentTeamId() && deal.status === 'confirmed' && deal.result_status === 'reported');
    const waitingTitle = waitingHostDeal
      ? 'Menunggu opponent confirm'
      : hostReportedDeal
        ? 'Result sedang admin review'
        : (reportedDeal ? 'Report sedang menunggu admin' : 'Tiada result pending');
    const waitingText = waitingHostDeal
      ? 'Result sudah dihantar kepada team lawan. Point masuk ranking selepas mereka confirm.'
      : hostReportedDeal
        ? 'Opponent report score. Admin perlu semak sebelum point dimasukkan.'
        : (reportedDeal ? 'Admin akan semak report score sebelum point dimasukkan.' : 'Bila host submit result, notification akan keluar dekat button ini.');

    box.innerHTML = `
      <div class="result-empty">
        <h3>${waitingTitle}</h3>
        <p>${waitingText}</p>
        <button class="btn primary" type="button" id="resultJoinScrimBtn">Join Scrim</button>
      </div>
    `;
    return;
  }

  const rows = scoreRowsFor(scrim);
  box.innerHTML = `
    <article class="result-review-panel">
      <div class="result-review-head">
        <div>
          <h3>${escapeHtml(scrim.title)}</h3>
          <p>${formatDate(scrim.date_time)}</p>
        </div>
        <span class="chip pending">pending confirm</span>
      </div>
      <div class="score-review-box">
        ${rows.map((row) => `
          <div class="score-review-row">
            <strong>${escapeHtml(row.name)}</strong>
            <span>${escapeHtml(row.score)}</span>
          </div>
        `).join('')}
      </div>
      <div class="inline-actions result-review-actions">
        <button class="btn green" type="button" data-action="confirm_result" data-decision="accept" data-id="${scrim.id}">Submit</button>
        <button class="btn red" type="button" id="toggleReportResultBtn">Report</button>
      </div>
      <form class="form-grid report-form" id="reportResultForm" hidden>
        <input type="hidden" name="action" value="report_result">
        <input type="hidden" name="scrim_id" value="${scrim.id}">
        <div class="field">
          <label>Score sebenar</label>
          <input name="reported_score" placeholder="Contoh: 1-2" required>
        </div>
        <div class="field">
          <label>Nota report</label>
          <textarea name="message" placeholder="Terangkan kenapa score salah"></textarea>
        </div>
        <button class="btn gold block" type="submit">Hantar Report Admin</button>
      </form>
    </article>
  `;
}

function renderHeroDeal(){
  const box = $('#heroDealContent');
  if (!box) return;

  const activeDeals = state.scrims.filter((scrim) => canControl(scrim) && ['pending','confirmed'].includes(scrim.status));
  const deal = activeDeals[0];

  if (!deal) {
    box.innerHTML = `
      <p class="mini-title">SCRIM DEAL</p>
      <div class="hero-empty-deal">
        <h3>Anda belum menyertai scrim</h3>
        <p>Join open scrim dulu untuk buka scrim deal room.</p>
        <button class="btn primary block" type="button" id="heroJoinScrimBtn">Join Scrim</button>
      </div>
    `;
    return;
  }

  const isCreator = currentTeamId() === Number(deal.creator_team_id);
  const actionLabel = isCreator
    ? 'Open Deal Room'
    : (deal.status === 'confirmed' && deal.room_id ? 'Display Room ID' : 'Open Deal Room');

  box.innerHTML = `
    <p class="mini-title">SCRIM DEAL</p>
    <div class="versus-row hero-deal-versus">
      <strong>${escapeHtml(deal.creator_name)}</strong>
      <span>VS</span>
      <strong>${escapeHtml(deal.opponent_name || 'TBD')}</strong>
    </div>
    <p class="hero-deal-date">${formatDate(deal.date_time)}</p>
    <div class="inline-actions">
      <span class="chip ${statusClass(deal.status)}">${escapeHtml(deal.status)}</span>
      ${deal.result_status === 'pending' ? '<span class="chip pending">result pending</span>' : ''}
    </div>
    <button class="btn block hero-deal-button" type="button" id="heroOpenDealBtn">${actionLabel}</button>
  `;
}

function renderAccess(){
  const scrimApp = $('#scrimApp');
  const primaryNavLink = $('#primaryNavLink');
  const isAuthed = Boolean(state.team || state.admin);
  document.body.classList.toggle('is-authed', isAuthed);
  document.body.classList.toggle('is-guest', !isAuthed);
  document.body.classList.toggle('is-admin', isAdmin());
  document.body.classList.remove('is-loading');
  if (scrimApp) {
    scrimApp.hidden = false;
  }
  if (isAuthed) closeAuthGate();
  if (primaryNavLink) {
    primaryNavLink.textContent = isAdmin() ? 'ADMIN PANEL' : 'TOURNAMENT';
    primaryNavLink.href = isAdmin() ? '#admin-panel' : 'https://gnexcenter.com/tournament.html';
  }
  $$('.desktop-app-nav .nav-link').forEach((item) => {
    item.classList.toggle('is-active',
      item.dataset.nav === activeView
      || (activeView === 'home' && item.dataset.nav === 'scrim-home')
      || (activeView === 'all' && item.dataset.nav === 'all-scrim')
    );
  });
}

function setCollapsible(id, isVisible){
  const panel = $(id);
  if (!panel) return;
  panel.hidden = !isVisible;
  panel.classList.toggle('is-visible', isVisible);
  panel.setAttribute('aria-hidden', String(!isVisible));
}

function toggleCollapsible(id, button){
  const panel = $(id);
  if (!panel) return false;
  const isVisible = !panel.classList.contains('is-visible');
  setCollapsible(id, isVisible);
  if (button) {
    button.setAttribute('aria-expanded', String(isVisible));
  }
  return isVisible;
}

function toggleCreatePanel(event){
  if (event) {
    event.stopPropagation();
  }
  if (!requireTeamLogin('Login atau register untuk buat scrim.')) return;
  if (state.team && !isScrimReady()) {
    openPhoneRequiredPanel();
    return;
  }
  if (activeView !== 'all') {
    setAppView('all');
  }
  const visible = toggleCollapsible('#createPanel', $('#toggleCreateBtn'));
  if (visible) {
    window.requestAnimationFrame(() => {
      $('#createPanel')?.scrollIntoView({behavior:'smooth', block:'start'});
    });
  }
}

window.toggleCreatePanel = toggleCreatePanel;

function toggleProfile(isVisible){
  const overlay = $('#profileOverlay');
  const button = $('#profileBtn');
  if (!overlay) return;
  const nextState = typeof isVisible === 'boolean' ? isVisible : overlay.hidden;
  overlay.hidden = !nextState;
  overlay.classList.toggle('is-visible', nextState);
  overlay.setAttribute('aria-hidden', String(!nextState));
  button?.setAttribute('aria-expanded', String(nextState));
  renderSession();
}

function toggleRanking(isVisible){
  const overlay = $('#rankingOverlay');
  const button = $('#viewRankingBtn');
  if (!overlay) return;
  const nextState = typeof isVisible === 'boolean' ? isVisible : overlay.hidden;
  overlay.hidden = !nextState;
  overlay.classList.toggle('is-visible', nextState);
  overlay.setAttribute('aria-hidden', String(!nextState));
  button?.setAttribute('aria-expanded', String(nextState));
}

function toggleGtmlSlotInfo(isVisible){
  const overlay = $('#gtmlSlotOverlay');
  const button = $('#viewGtmlSlotBtn');
  if (!overlay) return;
  const nextState = typeof isVisible === 'boolean' ? isVisible : overlay.hidden;
  overlay.hidden = !nextState;
  overlay.classList.toggle('is-visible', nextState);
  overlay.setAttribute('aria-hidden', String(!nextState));
  button?.setAttribute('aria-expanded', String(nextState));
}

function toggleResultReview(isVisible){
  const overlay = $('#resultOverlay');
  const button = $('#updateResultBtn');
  if (!overlay) return;
  const nextState = typeof isVisible === 'boolean' ? isVisible : overlay.hidden;
  overlay.hidden = !nextState;
  overlay.classList.toggle('is-visible', nextState);
  overlay.setAttribute('aria-hidden', String(!nextState));
  button?.setAttribute('aria-expanded', String(nextState));
}

function updateRosterRail(){
  const roster = $('#playerRoster');
  if (!roster) return;
  const cards = $$('.player-card', roster);
  if (!cards.length) return;

  const viewportCenter = roster.scrollLeft + (roster.clientWidth / 2);
  let activeIndex = 0;
  let closestDistance = Infinity;
  cards.forEach((card, index) => {
    const distance = Math.abs((card.offsetLeft + card.offsetWidth / 2) - viewportCenter);
    if (distance < closestDistance) {
      closestDistance = distance;
      activeIndex = index;
    }
  });

  cards.forEach((card, index) => card.classList.toggle('is-active', index === activeIndex));
  const maxScroll = Math.max(0, roster.scrollWidth - roster.clientWidth);
  const progress = maxScroll ? (roster.scrollLeft / maxScroll) * 400 : 0;
  roster.style.setProperty('--roster-progress', `${progress}%`);
  const previous = $('#rosterPrevBtn');
  const next = $('#rosterNextBtn');
  if (previous) previous.disabled = roster.scrollLeft <= 2;
  if (next) next.disabled = roster.scrollLeft >= maxScroll - 2;
}

function scrollRoster(direction){
  const roster = $('#playerRoster');
  const card = roster ? $('.player-card', roster) : null;
  if (!roster || !card) return;
  const styles = getComputedStyle(roster);
  const gap = Number.parseFloat(styles.columnGap || styles.gap) || 0;
  roster.scrollBy({left:direction * (card.offsetWidth + gap), behavior:'smooth'});
}

function renderScrims(){
  const list = $('#scrimList');
  const teamId = currentTeamId();
  let scrims = state.scrims.filter(isOpenBoardScrim);
  if (activeFilter === 'deal') {
    scrims = [];
  }

  if (!scrims.length) {
    list.innerHTML = '<p class="empty">Tiada open scrim aktif sekarang.</p>';
    return;
  }

  list.innerHTML = scrims.map((scrim, index) => {
    const isCreator = teamId > 0 && teamId === Number(scrim.creator_team_id);
    const existingRequest = requestFor(scrim);
    const isGuest = !state.team && !state.admin;
    const canRequest = state.team && scrim.status === 'open' && !isCreator && !existingRequest;
    const needsPhone = canRequest && !isScrimReady();
    const opponentName = scrim.opponent_name || 'Menunggu opponent';
    const creatorName = scrim.creator_name || 'Open Slot - mana-mana team boleh join';
    const matchupName = Number(scrim.admin_open_slots)
      ? `${scrim.creator_name || 'TBD'} VS ${scrim.opponent_name || 'TBD'}`
      : creatorName;
    const requestNote = existingRequest ? `<span class="chip ${statusClass(existingRequest.status)}">Request ${escapeHtml(existingRequest.status)}</span>` : '';
    const schedule = formatScrimDate(scrim.date_time);
    const detailId = `scrimDetail${scrim.id}`;
    const isDetailOpen = openScrimDetailIds.has(detailId);
    const actionLabel = needsPhone ? 'UPDATE PHONE' : (Number(scrim.admin_open_slots) ? 'JOIN SLOT' : 'REQUEST JOIN');

    return `
      <article class="scrim-card scrim-card-${statusClass(scrim.status)}">
        <div class="scrim-card-main">
          <span class="scrim-number">#${String(index + 1).padStart(2, '0')}</span>
          <div class="scrim-identity">
            <span class="scrim-format">${escapeHtml(scrim.format)}</span>
            <h3>${escapeHtml(matchupName)}</h3>
            <p>${escapeHtml(scrim.title)}</p>
          </div>
          <div class="scrim-schedule">
            <strong>${escapeHtml(schedule.date)}</strong>
            <span>${escapeHtml(schedule.time)}</span>
          </div>
          <span class="chip scrim-status ${statusClass(scrim.status)}">${escapeHtml(scrim.status)}</span>
          <div class="scrim-card-actions">
            <button class="btn scrim-detail-button" type="button" data-toggle-panel="${detailId}" aria-expanded="${String(isDetailOpen)}">DETAIL</button>
            ${isGuest
              ? `<button class="btn primary scrim-join-button" type="button" data-action="login_to_join">LOGIN TO JOIN</button>`
              : (canRequest
              ? `<button class="btn primary scrim-join-button" type="button" data-action="${needsPhone ? 'phone_required' : 'request'}" data-id="${scrim.id}" data-direct="${Number(scrim.admin_open_slots) ? '1' : '0'}">${actionLabel}</button>`
              : (isCreator && scrim.status === 'open' ? '<span class="scrim-action-state open">WAITING</span>' : ''))}
          </div>
        </div>
        <div class="scrim-card-detail" id="${detailId}" ${isDetailOpen ? '' : 'hidden'}>
          <div class="scrim-detail-item">
            <span>OPPONENT</span>
            <strong>${escapeHtml(opponentName)}</strong>
          </div>
          <div class="scrim-detail-item">
            <span>NOTA</span>
            <p>${escapeHtml(scrim.notes || 'Tiada nota tambahan.')}</p>
          </div>
          <div class="scrim-detail-item">
            <span>POINT MODE</span>
            <strong>${scrim.point_mode === 'challenge' ? 'TABRAK TEAM ATAS' : 'NORMAL SCRIM'}</strong>
          </div>
          <div class="scrim-detail-item scrim-detail-flags">
            ${requestNote}
            ${needsPhone ? '<span class="chip pending">Phone required</span>' : ''}
            ${isCreator && scrim.status === 'open' ? '<span class="chip open">Waiting request</span>' : ''}
            ${canControl(scrim) && scrim.status !== 'open' ? '<span class="chip confirmed">Private deal active</span>' : ''}
            ${scrim.status === 'completed' ? `<span class="chip completed">Winner: ${escapeHtml(scrim.winner_name || '-')}</span>` : ''}
            ${scrim.status === 'completed' && scrim.winner_point_delta !== null
              ? `<span class="chip confirmed">Point +${Number(scrim.winner_point_delta)} / ${Number(scrim.loser_point_delta)}</span>`
              : ''}
          </div>
        </div>
      </article>
    `;
  }).join('');
}

function renderRequests(){
  const list = $('#requestList');
  const teamId = currentTeamId();
  const ownedRequests = state.requests.filter((request) => Number(request.creator_team_id) === teamId && request.status === 'pending');

  if (!state.team) {
    list.innerHTML = '<p class="empty">Login untuk lihat request.</p>';
    return;
  }

  if (!ownedRequests.length) {
    list.innerHTML = '<p class="empty">Tiada request pending.</p>';
    return;
  }

  list.innerHTML = ownedRequests.map((request) => `
    <article class="request-row">
      <h3>${escapeHtml(request.requester_name)} request ${escapeHtml(request.scrim_title)}</h3>
      <p>${escapeHtml(request.message || 'Tiada message.')}</p>
      <div class="inline-actions">
        <span class="chip ${statusClass(request.status)}">${escapeHtml(request.status)}</span>
        ${request.status === 'pending' ? `
          <button class="btn green" type="button" data-action="respond" data-decision="accept" data-id="${request.id}">Accept</button>
          <button class="btn red" type="button" data-action="respond" data-decision="reject" data-id="${request.id}">Reject</button>
        ` : ''}
      </div>
    </article>
  `).join('');
}

function renderDealRooms(){
  const list = $('#dealRooms');
  if (!list) return;
  const deals = state.scrims.filter((scrim) => canControl(scrim) && ['pending','confirmed'].includes(scrim.status));

  if (!state.team) {
    list.innerHTML = '<p class="empty">Login untuk akses deal room.</p>';
    return;
  }

  if (!deals.length) {
    list.innerHTML = '<p class="empty">Tiada scrim deal aktif.</p>';
    return;
  }

  list.innerHTML = deals.map((scrim) => {
    const isCreator = currentTeamId() === Number(scrim.creator_team_id);
    const isOpponent = currentTeamId() === Number(scrim.opponent_team_id || 0);
    const roomVisible = scrim.status === 'confirmed' && scrim.room_id;
    const resultPending = scrim.result_status === 'pending';
    const resultReported = scrim.result_status === 'reported';
    const resultRejected = scrim.result_status === 'rejected';
    const noShowPending = scrim.result_status === 'no_show_pending';
    const resultLocked = resultPending || resultReported || noShowPending;
    const matchDate = scrimDateObject(scrim.date_time);
    const canReportNoShow = scrim.status === 'confirmed'
      && matchDate
      && matchDate.getTime() <= Date.now()
      && !resultLocked;
    const roomPanelId = `roomPanel-${scrim.id}`;
    const roomFormId = `roomForm-${scrim.id}`;
    const chatMessages = messagesFor(scrim.id);
    const winnerOptions = [
      [scrim.creator_team_id, scrim.creator_name],
      [scrim.opponent_team_id, scrim.opponent_name]
    ].filter(([id]) => id).map(([id, name]) => `<option value="${id}">${escapeHtml(name)}</option>`).join('');

    return `
      <article class="deal-room">
        <div class="deal-matchup">
          <strong>${escapeHtml(scrim.creator_name)}</strong>
          <span>VS</span>
          <strong>${escapeHtml(scrim.opponent_name || 'TBD')}</strong>
        </div>
        <h3>${escapeHtml(scrim.title)}</h3>
        <p class="deal-time">${formatDate(scrim.date_time)}</p>
        <p class="deal-point-mode">${scrim.point_mode === 'challenge' ? 'Challenger vs Defender Rank' : 'Normal Scrim Point'}</p>
        <div class="inline-actions"><span class="chip ${statusClass(scrim.status)}">${escapeHtml(scrim.status)}</span></div>

        ${isCreator ? `
          <button class="btn block" type="button" data-toggle-panel="${roomFormId}">Submit Room ID</button>
          <form class="form-grid room-form deal-toggle-panel" id="${roomFormId}" hidden>
            <input type="hidden" name="action" value="set_room">
            <input type="hidden" name="scrim_id" value="${scrim.id}">
            <div class="field">
              <label>Room ID</label>
              <input name="room_id" value="${escapeHtml(scrim.room_id || '')}" placeholder="Contoh: 8493021" required>
            </div>
            <button class="btn gold block" type="submit">Confirm Scrim</button>
          </form>
        ` : `
          <button class="btn block" type="button" data-toggle-panel="${roomPanelId}" ${roomVisible ? '' : 'disabled'}>Display Room ID</button>
          ${!roomVisible ? '<p class="empty" style="margin-top:10px">Room ID belum disubmit oleh host.</p>' : ''}
        `}

        ${roomVisible ? `
          <div class="room-box deal-toggle-panel" id="${roomPanelId}" ${isOpponent ? 'hidden' : ''}>
            <div class="secret"><span>Room ID</span><strong>${escapeHtml(scrim.room_id)}</strong></div>
          </div>
        ` : ''}

        <section class="deal-chat" aria-label="Scrim Deal Room chat">
          <div class="deal-chat-head">
            <div>
              <strong>Scrim Deal Room</strong>
              <p>Chat host dan opponent untuk Room ID, masa, rules atau apa-apa update.</p>
            </div>
            <span class="chip confirmed">team 1 + team 2</span>
          </div>
          <div class="deal-chat-log">
            ${chatMessages.length ? chatMessages.map((message) => {
              const isMine = Number(message.sender_team_id) === currentTeamId();
              return `
                <div class="chat-bubble ${isMine ? 'mine' : 'theirs'}">
                  <span>${escapeHtml(message.sender_name || 'Team')}</span>
                  <p>${escapeHtml(message.message)}</p>
                  <small>${formatDate(message.created_at)}</small>
                </div>
              `;
            }).join('') : '<p class="empty">Belum ada chat. Tanya Room ID, confirm masa atau bincang rules di sini.</p>'}
          </div>
          <div class="quick-chat-actions">
            <button class="quick-chat" type="button" data-action="quick_chat" data-id="${scrim.id}" data-message="Room ID berapa ya?">Room ID?</button>
            <button class="quick-chat" type="button" data-action="quick_chat" data-id="${scrim.id}" data-message="Confirm scrim ikut masa ini ya.">Confirm masa</button>
          </div>
          <form class="deal-chat-form chat-form">
            <input type="hidden" name="action" value="send_message">
            <input type="hidden" name="scrim_id" value="${scrim.id}">
            <input name="message" autocomplete="off" placeholder="Tulis mesej..." required>
            <button class="btn primary" type="button" data-action="send_chat">Send</button>
          </form>
        </section>

        ${scrim.status === 'confirmed' ? `
          ${resultPending ? `
            <div class="result-confirm-box">
              <p>Pending result dari host:</p>
              <div class="secret"><span>Score</span><strong>${escapeHtml(scrim.pending_result_score || '-')}</strong></div>
              <div class="secret"><span>Winner</span><strong>${escapeHtml(scrim.pending_winner_name || scrim.winner_name || '-')}</strong></div>
              ${isOpponent ? `
                <div class="inline-actions" style="margin-top:12px">
                  <button class="btn green" type="button" data-action="confirm_result" data-decision="accept" data-id="${scrim.id}">Confirm Result</button>
                  <button class="btn red" type="button" data-action="confirm_result" data-decision="reject" data-id="${scrim.id}">Reject</button>
                </div>
              ` : '<p style="margin-top:10px">Menunggu opponent confirm result.</p>'}
            </div>
          ` : ''}

          ${resultReported ? '<p class="empty" style="margin-top:12px">Result telah direport. Menunggu admin review.</p>' : ''}

          ${noShowPending ? `
            <div class="result-confirm-box">
              <p>No-show report pending:</p>
              <div class="secret"><span>Winner jika disahkan</span><strong>${escapeHtml(scrim.pending_winner_name || '-')}</strong></div>
              <div class="secret"><span>Penalty</span><strong>Forfeit / Lose -2</strong></div>
              ${Number(scrim.pending_winner_team_id || 0) !== currentTeamId() ? `
                <div class="inline-actions" style="margin-top:12px">
                  <button class="btn green" type="button" data-action="respond_no_show" data-decision="accept" data-id="${scrim.id}">Confirm No Show</button>
                  <button class="btn red" type="button" data-action="respond_no_show" data-decision="dispute" data-id="${scrim.id}">Dispute</button>
                </div>
              ` : '<p style="margin-top:10px">Menunggu lawan confirm/dispute. Auto-complete selepas 15 minit.</p>'}
            </div>
          ` : ''}

          ${resultRejected && isCreator ? '<p class="empty" style="margin-top:12px">Opponent reject result sebelum ini. Submit semula score yang betul.</p>' : ''}

          ${canReportNoShow ? `
            <button class="btn red block" type="button" data-action="report_no_show" data-id="${scrim.id}" style="margin-top:12px">Report No Show</button>
          ` : ''}

          ${isCreator && !resultLocked && !resultReported ? `
          <form class="form-grid result-form" style="margin-top:12px">
            <input type="hidden" name="action" value="update_result">
            <input type="hidden" name="scrim_id" value="${scrim.id}">
            <div class="field">
              <label>Winner</label>
              <select name="winner_team_id" required>${winnerOptions}</select>
            </div>
            <div class="field">
              <label>Score</label>
              <input name="result_score" placeholder="Contoh: 2-1" required>
            </div>
            <button class="btn green block" type="submit">Submit Result To Opponent</button>
          </form>
          ` : ''}
        ` : ''}
      </article>
    `;
  }).join('');
}

function setAppView(view){
  activeView = ['home','support','deal','all','review','admin'].includes(view) ? view : 'home';
  const supportView = $('#supportView');
  const dealView = $('#dealView');
  const reviewView = $('#reviewView');
  const adminView = $('#adminView');
  if (dealView) {
    dealView.hidden = activeView !== 'deal';
  }
  if (reviewView) {
    reviewView.hidden = activeView !== 'review';
  }
  if (adminView) {
    adminView.hidden = activeView !== 'admin';
  }
  if (supportView) supportView.hidden = activeView !== 'support';
  document.body.classList.toggle('scrim-view-home', activeView === 'home');
  document.body.classList.toggle('scrim-view-deal', activeView === 'deal');
  document.body.classList.toggle('scrim-view-all', activeView === 'all');
  document.body.classList.toggle('scrim-view-review', activeView === 'review');
  document.body.classList.toggle('scrim-view-admin', activeView === 'admin');
  document.body.classList.toggle('scrim-view-support', activeView === 'support');
  $$('.bottom-app-nav .bottom-nav-item, .desktop-app-nav .nav-link').forEach((item) => {
    item.classList.toggle('is-active',
      item.dataset.nav === activeView
      || (activeView === 'home' && item.dataset.nav === 'scrim-home')
      || (activeView === 'all' && item.dataset.nav === 'all-scrim')
    );
  });
  if (activeView === 'deal') {
    renderDealApp();
    window.scrollTo({top:0, behavior:'smooth'});
  }
  if (activeView === 'all') {
    window.scrollTo({top:0, behavior:'smooth'});
  }
  if (activeView === 'review') {
    window.scrollTo({top:0, behavior:'smooth'});
  }
}

function renderSupport(){
  const renderMessages = (messages) => messages.length ? messages.map((message) => `
    <div class="support-message ${message.sender_type === 'admin' ? 'is-admin' : ''}"><strong>${escapeHtml(message.sender_type === 'admin' ? 'Admin' : message.guest_name || message.sender_name)}</strong><p>${escapeHtml(message.message)}</p><small>${formatDate(message.created_at)}</small></div>
  `).join('') : '<p class="empty">Belum ada mesej.</p>';
  const supportLog = $('#supportChatLog');
  if (supportLog) supportLog.innerHTML = renderMessages(state.support_messages || []);
  const groupLog = $('#groupChatLog');
  if (groupLog) groupLog.innerHTML = renderMessages(state.group_messages || []);
  const inbox = $('#adminSupportInbox');
  if (inbox) {
    const conversations = new Map();
    (state.support_messages || []).forEach((message) => {
      if (!conversations.has(message.guest_key)) conversations.set(message.guest_key, []);
      conversations.get(message.guest_key).push(message);
    });
    inbox.innerHTML = conversations.size ? [...conversations.entries()].map(([key, messages]) => {
      const guestName = messages.find((item) => item.sender_type === 'guest')?.guest_name || 'Guest';
      return `<article class="admin-support-thread"><h4>${escapeHtml(guestName)}</h4><div class="support-chat-log">${renderMessages(messages)}</div><form class="admin-support-reply"><input type="hidden" name="action" value="admin_reply_support"><input type="hidden" name="guest_key" value="${escapeHtml(key)}"><input type="hidden" name="guest_name" value="${escapeHtml(guestName)}"><input name="message" placeholder="Reply guest..." required><button class="btn primary" type="submit">REPLY</button></form></article>`;
    }).join('') : '<p class="empty">Tiada mesej support.</p>';
  }
}

function renderDealApp(){
  const box = $('#dealViewContent');
  const backBtn = $('#dealBackListBtn');
  const dealView = $('#dealView');
  if (!box) return;
  const deals = activeDeals();
  const selectedDeal = deals.find((deal) => Number(deal.id) === Number(activeDealId));
  const selectedGroup = Number(activeDealId) === -1;
  dealView?.classList.toggle('is-chat-open', Boolean(selectedDeal) || selectedGroup);

  if (!state.team && !state.admin) {
    if (backBtn) backBtn.hidden = true;
    box.innerHTML = '<p class="empty" style="padding:22px">Login untuk akses deal chat.</p>';
    return;
  }

  if (!selectedDeal) {
    if (selectedGroup) {
      if (backBtn) backBtn.hidden = false;
      const groupMessages = state.group_messages || [];
      box.innerHTML = `
        <section class="deal-chat-screen group-question-screen">
          <div class="deal-chat-screen-head">
            <span class="deal-contact-avatar question-room-avatar" aria-hidden="true"></span>
            <div>
              <h3>Group Pertanyaan</h3>
              <p>Ruang umum untuk semua team bertanya dan berbincang dengan admin.</p>
            </div>
            <button class="btn panel-toggle deal-chat-back" type="button" data-action="back_deal_list">BACK</button>
          </div>
          <div class="deal-chat-full-log" id="dealGroupChatLog">
            ${groupMessages.length ? groupMessages.map((message) => {
              const isAdminMessage = message.sender_type === 'admin';
              const isMine = isAdmin()
                ? isAdminMessage
                : Number(message.sender_team_id || 0) === currentTeamId();
              return `
                <div class="chat-bubble ${isMine ? 'mine' : 'theirs'} ${isAdminMessage ? 'is-admin' : ''}">
                  <span>${escapeHtml(isAdminMessage ? 'ADMIN' : (message.sender_name || 'Team'))}</span>
                  <p>${escapeHtml(message.message)}</p>
                  <small>${formatDate(message.created_at)}</small>
                </div>
              `;
            }).join('') : '<p class="empty">Belum ada pertanyaan. Mulakan perbualan dengan semua team dan admin.</p>'}
          </div>
          <form class="deal-chat-composer group-chat-form">
            <input type="hidden" name="action" value="send_group_message">
            <input name="message" maxlength="500" autocomplete="off" placeholder="Tulis pertanyaan..." required>
            <button class="btn primary chat-send-button" type="submit" aria-label="Hantar pertanyaan">
              <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="m22 2-7 20-4-9-9-4 20-7Z"></path>
                <path d="M22 2 11 13"></path>
              </svg>
            </button>
          </form>
        </section>
      `;
      window.requestAnimationFrame(() => {
        const log = $('#dealGroupChatLog');
        if (log) log.scrollTop = log.scrollHeight;
      });
      return;
    }
    activeDealId = 0;
    if (backBtn) backBtn.hidden = true;
    const groupMessages = state.group_messages || [];
    const lastGroupMessage = groupMessages[groupMessages.length - 1];
    const renderDealContact = (scrim) => {
      const contactName = dealContactName(scrim);
      const chatMessages = messagesFor(scrim.id);
      const lastMessage = chatMessages[chatMessages.length - 1];
      const unreadCount = Number(unreadChatByScrim[Number(scrim.id)] || 0);
      return `
        <button class="deal-contact-item" type="button" data-action="open_deal_chat" data-id="${scrim.id}">
          <span class="deal-contact-avatar">${escapeHtml(dealInitials(contactName))}</span>
          <span class="deal-contact-main">
            <strong>${escapeHtml(contactName)}</strong>
            <span>${escapeHtml(scrim.title)} - ${formatDate(scrim.date_time)}</span>
            <span>${lastMessage ? escapeHtml(lastMessage.message) : 'Belum ada chat.'}</span>
          </span>
          <span class="deal-contact-meta">
            ${unreadCount ? `<strong class="deal-contact-badge">${unreadCount}</strong>` : ''}
            <span>${escapeHtml(scrim.status)}</span>
            ${scrim.result_status ? `<span class="chip ${statusClass(scrim.result_status)}">${escapeHtml(scrim.result_status)}</span>` : ''}
          </span>
        </button>
      `;
    };
    const unreadDeals = deals.filter((scrim) => Number(unreadChatByScrim[Number(scrim.id)] || 0) > 0);
    const regularDeals = deals.filter((scrim) => Number(unreadChatByScrim[Number(scrim.id)] || 0) === 0);
    box.innerHTML = `
      <div class="deal-contact-list">
        ${unreadDeals.length ? `
          <div class="deal-contact-section-label"><span>UNREAD</span><strong>${unreadDeals.length}</strong></div>
          ${unreadDeals.map(renderDealContact).join('')}
        ` : ''}
        <div class="deal-contact-section-label"><span>GROUP</span></div>
        <button class="deal-contact-item group-question-contact" type="button" data-action="open_group_chat">
          <span class="deal-contact-avatar question-room-avatar" aria-hidden="true"></span>
          <span class="deal-contact-main">
            <strong>Group Pertanyaan</strong>
            <span>Semua team &amp; admin</span>
            <span>${lastGroupMessage ? escapeHtml(lastGroupMessage.message) : 'Tanya apa-apa berkaitan scrim.'}</span>
          </span>
          <span class="deal-contact-meta"><span class="chip open">PUBLIC</span></span>
        </button>
        <div class="deal-contact-section-label"><span>SCRIM DEAL</span><strong>${regularDeals.length}</strong></div>
        ${regularDeals.length
          ? regularDeals.map(renderDealContact).join('')
          : '<p class="deal-contact-empty">Tiada chat scrim lain.</p>'}
      </div>
    `;
    return;
  }

  if (backBtn) backBtn.hidden = false;
  const contactName = dealContactName(selectedDeal);
  const chatMessages = messagesFor(selectedDeal.id);

  box.innerHTML = `
    <section class="deal-chat-screen">
      <div class="deal-chat-screen-head">
        <span class="deal-contact-avatar">${escapeHtml(dealInitials(contactName))}</span>
        <div>
          <h3>${escapeHtml(contactName)}</h3>
          <p>${escapeHtml(selectedDeal.title)} - ${formatDate(selectedDeal.date_time)}</p>
        </div>
        <button class="btn panel-toggle deal-chat-back" type="button" data-action="back_deal_list">BACK</button>
      </div>
      <div class="deal-chat-full-log">
        ${chatMessages.length ? chatMessages.map((message) => {
          const isAdminMessage = message.sender_type === 'admin';
          const isMine = isAdmin() ? isAdminMessage : Number(message.sender_team_id) === currentTeamId();
          return `
            <div class="chat-bubble ${isMine ? 'mine' : 'theirs'} ${isAdminMessage ? 'is-admin' : ''}">
              <span>${escapeHtml(isAdminMessage ? 'ADMIN' : (message.sender_name || 'Team'))}</span>
              <p>${escapeHtml(message.message)}</p>
              <small>${formatDate(message.created_at)}</small>
            </div>
          `;
        }).join('') : '<p class="empty">Belum ada chat. Deal player, confirm masa atau bincang rules di sini.</p>'}
      </div>
      ${selectedDeal.status === 'completed' ? `
        <div class="deal-chat-archived">
          MATCH COMPLETED — CHAT DISIMPAN SEHINGGA 3 HARI SELEPAS MASA SCRIM
        </div>
      ` : `<form class="deal-chat-composer chat-form">
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="scrim_id" value="${selectedDeal.id}">
        <input name="message" autocomplete="off" placeholder="Tulis mesej..." required>
        <button class="btn primary chat-send-button" type="button" data-action="send_chat" aria-label="Send message">
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="m22 2-7 20-4-9-9-4 20-7Z"></path>
            <path d="M22 2 11 13"></path>
          </svg>
        </button>
      </form>`}
    </section>
  `;
  if (pendingDealScrollToBottom) {
    pendingDealScrollToBottom = false;
    window.requestAnimationFrame(() => scrollChatToBottom(selectedDeal.id));
  }
}

function renderRanking(){
  const rankList = $('#rankList');
  const topCards = $('#rankingTopCards');
  if (!rankList || !topCards) return;

  const teams = [...state.stats].sort((a, b) => {
    const pointDifference = Number(b.points || 0) - Number(a.points || 0);
    if (pointDifference) return pointDifference;
    const winDifference = Number(b.wins || 0) - Number(a.wins || 0);
    if (winDifference) return winDifference;
    return Number(a.losses || 0) - Number(b.losses || 0);
  });

  if (!teams.length) {
    topCards.innerHTML = '';
    rankList.innerHTML = '<p class="empty">Belum ada team.</p>';
    return;
  }

  topCards.innerHTML = teams.slice(0, 3).map((team, index) => `
    <article class="ranking-podium-card rank-${index + 1}">
      <p class="ranking-top-label">TOP ${index + 1}</p>
      <div class="ranking-team-head">
        <img src="images/logo baru gnex .webp" alt="" aria-hidden="true">
        <div>
          <h3>${escapeHtml(team.name)}</h3>
          <p>${Number(team.played || 0)} SCRIM PLAYED</p>
        </div>
      </div>
      <div class="ranking-card-stats">
        <div><strong>${Number(team.points || 0)}</strong><span>POINT</span></div>
        <div><strong>${Number(team.wins || 0)}</strong><span>WIN</span></div>
        <div><strong>${Number(team.losses || 0)}</strong><span>LOSE</span></div>
      </div>
    </article>
  `).join('');

  rankList.innerHTML = teams.map((team, index) => `
    <article class="ranking-table-row rank-${index + 1}">
      <strong class="ranking-position">#${index + 1}</strong>
      <div class="ranking-table-team">
        <img src="images/logo baru gnex .webp" alt="" aria-hidden="true">
        <strong>${escapeHtml(team.name)}</strong>
      </div>
      <div class="ranking-cell points" data-label="POINT">${Number(team.points || 0)}</div>
      <div class="ranking-cell wins" data-label="WIN">${Number(team.wins || 0)}</div>
      <div class="ranking-cell losses" data-label="LOSE">${Number(team.losses || 0)}</div>
    </article>
  `).join('');
}

function renderAdminPanel(){
  const adminView = $('#adminView');
  if (!adminView) return;
  const teams = [...(state.stats || [])].sort((a, b) => String(a.name).localeCompare(String(b.name)));
  const teamOptions = '<option value="">Pilih team</option>' + teams.map((team) => `
    <option value="${Number(team.id)}">${escapeHtml(team.name)}</option>
  `).join('');
  ['#adminTeamOne', '#adminTeamTwo'].forEach((selector) => {
    const select = $(selector);
    if (select && !select.matches(':focus')) {
      const currentValue = select.value;
      select.innerHTML = selector === '#adminTeamTwo'
        ? teamOptions.replace('Pilih team', 'Kosongkan untuk Open Scrim')
        : teamOptions.replace('Pilih team', 'Kosongkan untuk Open Scrim (2 slot)');
      select.value = currentValue;
    }
  });

  const teamList = $('#adminTeamList');
  if (teamList) {
    teamList.innerHTML = teams.length ? teams.map((team) => {
      const editPanelId = `adminTeamEdit${Number(team.id)}`;
      const isEditOpen = openScrimDetailIds.has(editPanelId);
      return `
      <article class="admin-team-row">
        <div class="admin-team-row-head">
          <div>
            <strong>${escapeHtml(team.name)}</strong>
            <span>Captain: ${escapeHtml(team.captain_name || 'Belum set')} | Phone: ${escapeHtml(team.phone_number || 'Belum set')}</span>
          </div>
          <div class="admin-team-row-actions">
            <small>#${Number(team.id)} | ${Number(team.points || 0)} pts</small>
            <button class="btn admin-team-edit-toggle" type="button" data-toggle-panel="${editPanelId}" data-open-label="CLOSE" data-closed-label="EDIT" aria-expanded="${String(isEditOpen)}">${isEditOpen ? 'CLOSE' : 'EDIT'}</button>
          </div>
        </div>
        <form class="admin-team-edit" id="${editPanelId}" data-admin-edit-team ${isEditOpen ? '' : 'hidden'}>
          <input type="hidden" name="action" value="admin_update_team">
          <input type="hidden" name="team_id" value="${Number(team.id)}">
          <div class="field">
            <label>Nama team</label>
            <input name="team_name" value="${escapeHtml(team.name)}" required>
          </div>
          <div class="field">
            <label>Captain</label>
            <input name="captain_name" value="${escapeHtml(team.captain_name || '')}">
          </div>
          <div class="field">
            <label>Telefon</label>
            <input name="phone_number" type="tel" value="${escapeHtml(team.phone_number || '')}">
          </div>
          <div class="field">
            <label>Point</label>
            <input name="points" type="number" value="${Number(team.points || 0)}" required>
          </div>
          <div class="field">
            <label>Win</label>
            <input name="wins" type="number" min="0" value="${Number(team.wins || 0)}" required>
          </div>
          <div class="field">
            <label>Lose</label>
            <input name="losses" type="number" min="0" value="${Number(team.losses || 0)}" required>
          </div>
          <div class="field">
            <label>Total Played Scrim</label>
            <input name="played" type="number" min="0" value="${Number(team.played || 0)}" required>
          </div>
          <div class="field">
            <label>Password baharu</label>
            <input name="password" type="password" minlength="4" pattern="(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9\\s]).{4,}" title="Mesti ada 1 huruf besar, 1 nombor dan 1 simbol" placeholder="Kosongkan jika kekal">
          </div>
          <button class="btn primary" type="submit">SAVE TEAM</button>
          <button class="btn red" type="button" data-action="admin_delete_team" data-id="${Number(team.id)}" data-name="${escapeHtml(team.name)}">REMOVE</button>
        </form>
      </article>
    `;
    }).join('') : '<p class="empty">Belum ada team.</p>';
  }

  const scrimList = $('#adminScrimList');
  if (scrimList) {
    const scrims = [...(state.scrims || [])].sort((a, b) => Number(b.id || 0) - Number(a.id || 0));
    scrimList.innerHTML = scrims.length ? scrims.map((scrim) => {
      const creatorId = Number(scrim.creator_team_id || 0);
      const opponentId = Number(scrim.opponent_team_id || 0);
      const opponentOptions = '<option value="">Pilih opponent</option>' + teams
        .filter((team) => Number(team.id) !== creatorId)
        .map((team) => `<option value="${Number(team.id)}" ${Number(team.id) === opponentId ? 'selected' : ''}>${escapeHtml(team.name)}</option>`)
        .join('');
      return `
      <article class="admin-scrim-row">
        <strong>#${Number(scrim.id)} ${escapeHtml(scrim.title)}</strong>
        <span>${escapeHtml(scrim.creator_name || '-')} VS ${escapeHtml(scrim.opponent_name || 'TBD')} | ${escapeHtml(scrim.status || '-')}</span>
        <small>${formatDate(scrim.date_time)} | ${escapeHtml(scrim.format || '-')}</small>
        <form class="admin-scrim-edit" data-admin-edit-scrim>
          <input type="hidden" name="action" value="admin_update_scrim">
          <input type="hidden" name="scrim_id" value="${Number(scrim.id)}">
          <div class="field admin-opponent-field">
            <label>Opponent (pilih untuk confirm terus)</label>
            <select name="opponent_team_id">${opponentOptions}</select>
          </div>
          <div class="field">
            <label>Nama</label>
            <input name="title" value="${escapeHtml(scrim.title || '')}" required>
          </div>
          <div class="field">
            <label>Masa</label>
            <input name="date_time" type="datetime-local" value="${escapeHtml(inputDateTimeValue(scrim.date_time))}" required>
          </div>
          <div class="field">
            <label>Format</label>
            <select name="format">
              ${['BO1','BO3','BO5','Training'].map((format) => `<option ${String(scrim.format) === format ? 'selected' : ''}>${format}</option>`).join('')}
            </select>
          </div>
          <div class="field">
            <label>Sistem point</label>
            <select name="point_mode">
              <option value="normal" ${scrim.point_mode !== 'challenge' ? 'selected' : ''}>Normal Scrim</option>
              <option value="challenge" ${scrim.point_mode === 'challenge' ? 'selected' : ''}>Tabrak Team Atas</option>
            </select>
          </div>
          <div class="field admin-info-field">
            <label>Info / Nota</label>
            <input name="notes" value="${escapeHtml(scrim.notes || '')}" placeholder="Info scrim">
          </div>
          <button class="btn primary" type="submit">Save Match</button>
          ${opponentId && ['pending','confirmed','completed'].includes(scrim.status)
            ? `<button class="btn green" type="button" data-admin-open-deal="${Number(scrim.id)}">${scrim.status === 'completed' ? 'VIEW CHAT ARCHIVE' : 'OPEN DEAL CHAT'}</button>`
            : ''}
          <button class="btn red" type="button" data-action="admin_delete_scrim" data-id="${Number(scrim.id)}">Delete</button>
        </form>
        ${opponentId ? `
          ${scrim.status === 'completed' ? `
            <div class="admin-result-complete">
              <span>RESULT DIKIRA</span>
              <strong>${escapeHtml(scrim.creator_name)} ${escapeHtml(scrim.result_score || '-')} ${escapeHtml(scrim.opponent_name)}</strong>
              <small>Winner: ${escapeHtml(scrim.winner_name || '-')} | Ranking sudah dikemaskini</small>
            </div>
          ` : `
            <form class="admin-point-edit" data-admin-record-result>
              <input type="hidden" name="action" value="admin_record_result">
              <input type="hidden" name="scrim_id" value="${Number(scrim.id)}">
              <div class="field admin-score-field">
                <label>Score: ${escapeHtml(scrim.creator_name)} - ${escapeHtml(scrim.opponent_name)}</label>
                <input name="result_score" inputmode="numeric" pattern="[0-9]{1,2}\\s*-\\s*[0-9]{1,2}" placeholder="Contoh: 2-1" required>
              </div>
              <button class="btn gold" type="submit">UPDATE RESULT & RANKING</button>
            </form>
          `}
        ` : '<p class="admin-point-hint">Pilih dan save opponent dahulu untuk update point.</p>'}
      </article>
    `;
    }).join('') : '<p class="empty">Belum ada scrim.</p>';
  }
}

function filterAdminTeams(value){
  const query = String(value || '').trim().toLocaleLowerCase('ms-MY');
  const teamList = $('#adminTeamList');
  const rows = teamList ? $$('.admin-team-row', teamList) : [];
  let visibleCount = 0;
  rows.forEach((row) => {
    const matches = !query || row.textContent.toLocaleLowerCase('ms-MY').includes(query);
    row.hidden = !matches;
    if (matches) visibleCount += 1;
  });
  const result = $('#adminTeamSearchResult');
  if (result) result.textContent = `${visibleCount} team`;
}

function render(){
  syncTopNavHeight();
  clearExpiredDealUnread();
  renderAccess();
  renderSession();
  renderResultReview();
  renderHeroDeal();
  renderScrims();
  renderRequests();
  renderDealRooms();
  renderDealApp();
  renderRanking();
  renderAdminPanel();
  renderSupport();
  filterAdminTeams($('#adminTeamSearch')?.value);
}

$('#authForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const action = $('#authAction')?.value || 'login';
  const submitButton = $('#authSubmit');
  const password = $('#teamPassword')?.value || '';
  setAuthFormMessage();

  if (!form.checkValidity()) {
    const message = 'Sila lengkapkan semua maklumat yang wajib diisi.';
    setAuthFormMessage(message);
    showToast(message);
    form.reportValidity();
    return;
  }

  if (action === 'register') {
    const passwordError = strongPasswordError(password);
    if (passwordError) {
      setAuthFormMessage(passwordError);
      showToast(passwordError);
      $('#teamPassword')?.focus();
      return;
    }
  }

  if (submitButton?.disabled) return;
  const originalLabel = submitButton?.textContent || '';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = action === 'register' ? 'REGISTERING...' : 'LOADING...';
  }
  try {
    await postForm(form);
    form.reset();
  } catch (error) {
    if (error.needsPhone) {
      openPhoneRequiredPanel();
      return;
    }
    setAuthFormMessage(error.message);
    showToast(error.message);
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = originalLabel;
    }
  }
});

$('#createForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!requireTeamLogin('Login atau register untuk buat scrim.')) return;
  const form = event.currentTarget;
  const dateInput = $('#scrimDate', form);
  const timeInput = $('#scrimClock', form);
  const dateTimeInput = $('#scrimDateTime', form);
  if (dateInput && timeInput && dateTimeInput) {
    if (!dateInput.value || !timeInput.value) {
      showToast('Pilih tarikh dan masa scrim.');
      return;
    }
    dateTimeInput.value = malaysiaInputDateTime(dateInput.value, timeInput.value);
  }
  try {
    await postForm(form);
    form.reset();
    setCollapsible('#createPanel', false);
    $('#toggleCreateBtn')?.setAttribute('aria-expanded', 'false');
  } catch (error) {
    if (error.needsPhone) {
      openPhoneRequiredPanel();
      return;
    }
    showToast(error.message);
  }
});

document.addEventListener('submit', async (event) => {
  if (!event.target.matches('.room-form,.result-form,.report-form,.chat-form,.profile-form,.support-chat-form,.group-chat-form,.admin-support-reply,#adminCreateMatchForm,#adminCreateTeamForm,[data-admin-edit-team],[data-admin-edit-scrim],[data-admin-record-result]')) return;
  event.preventDefault();
  try {
    if (event.target.matches('[data-admin-record-result]')) {
      const score = $('input[name="result_score"]', event.target)?.value || '';
      if (!window.confirm(`Confirm result ${score}? Win, lose dan point ranking akan terus dikira.`)) return;
    }
    if (event.target.matches('.chat-form')) {
      await sendChatForm(event.target);
      return;
    }
    if (event.target.matches('#adminCreateMatchForm')) {
      const dateInput = $('input[name="scrim_date"]', event.target);
      const timeInput = $('input[name="scrim_time"]', event.target);
      const dateTimeInput = $('input[name="date_time"]', event.target);
      if (!dateInput?.value || !timeInput?.value || !dateTimeInput) {
        showToast('Pilih tarikh dan masa scrim.');
        return;
      }
      dateTimeInput.value = malaysiaInputDateTime(dateInput.value, timeInput.value);
    }
    await postForm(event.target);
    if (event.target.matches('.support-chat-form,.group-chat-form,.admin-support-reply')) {
      const messageInput = $('input[name="message"]', event.target);
      if (messageInput) messageInput.value = '';
    }
    if (event.target.matches('#adminCreateMatchForm,#adminCreateTeamForm')) {
      event.target.reset();
    }
    if (event.target.matches('.report-form')) {
      toggleResultReview(false);
    }
    if (event.target.matches('.profile-form')) {
      setCollapsible('#profileEditPanel', false);
      $('#editProfileBtn')?.setAttribute('aria-expanded', 'false');
    }
  } catch (error) {
    showToast(error.message);
  }
});

document.addEventListener('focusout', (event) => {
  if (!event.target.matches('.chat-form input[name="message"]')) return;
  [80, 220, 420, 700].forEach((delay) => {
    window.setTimeout(() => {
      settleDealViewportAfterKeyboard();
      if (delay === 220 && !activeChatInput()) {
        renderPreservingChatDrafts();
        settleDealViewportAfterKeyboard();
      }
    }, delay);
  });
});

document.addEventListener('input', (event) => {
  if (event.target.id === 'adminTeamSearch') {
    filterAdminTeams(event.target.value);
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  if (!$('#gtmlSlotOverlay')?.hidden) toggleGtmlSlotInfo(false);
  else if (!$('#authGate')?.hidden) closeAuthGate();
});

document.addEventListener('focusin', (event) => {
  if (!event.target.matches('.chat-form input[name="message"]')) return;
  syncKeyboardViewport();
});

document.addEventListener('pointerdown', (event) => {
  if (!document.body.classList.contains('chat-keyboard-open')) return;
  if (!event.target.closest('.deal-chat-full-log')) return;
  activeChatInput()?.blur();
}, {passive:true});

document.addEventListener('touchmove', (event) => {
  if (!document.body.classList.contains('chat-keyboard-open')) return;
  if (!event.target.closest('.deal-chat-full-log')) return;
  activeChatInput()?.blur();
}, {passive:true});

document.addEventListener('click', async (event) => {
  const button = event.target.closest('button, a');
  if (!button) return;

  if (button.dataset.authTab) {
    const authMode = button.dataset.authTab;
    setAuthFormMessage();
    $$('.tab').forEach((tab) => tab.classList.toggle('is-active', tab.dataset.authTab === authMode));
    $('#authAction').value = authMode === 'admin' ? 'admin_login' : authMode;
    $('#authSubmit').textContent = authMode === 'register' ? 'Register Team' : (authMode === 'admin' ? 'Login Admin' : 'Login Team');
    $$('.auth-register-field').forEach((field) => {
      field.hidden = authMode !== 'register';
      $$('input', field).forEach((input) => {
        input.required = authMode === 'register';
      });
    });
    const gateTitle = $('#authGateTitle');
    if (gateTitle) {
      gateTitle.textContent = authMode === 'register' ? 'Create Account' : (authMode === 'admin' ? 'Admin Login' : 'Login Team');
    }
    const teamNameLabel = document.querySelector('label[for="teamName"]');
    const teamNameInput = $('#teamName');
    const passwordInput = $('#teamPassword');
    if (teamNameLabel) teamNameLabel.textContent = authMode === 'admin' ? 'Username admin' : 'Nama team';
    if (teamNameInput) teamNameInput.placeholder = authMode === 'admin' ? 'Contoh: gnexadmin' : 'Contoh: GNEX Alpha';
    if (passwordInput) {
      passwordInput.autocomplete = authMode === 'register' ? 'new-password' : 'current-password';
      if (authMode === 'register') {
        passwordInput.title = 'Mesti ada 1 huruf besar, 1 nombor dan 1 simbol';
      } else {
        passwordInput.removeAttribute('title');
      }
    }
    const helper = $('.auth-helper');
    if (helper) {
      helper.textContent = authMode === 'admin'
        ? 'Login khas admin untuk manage scrim dan team.'
        : (authMode === 'register' ? 'Player ID hanya boleh digunakan oleh satu team. Password wajib ada huruf besar, nombor dan simbol.' : '');
    }
    return;
  }

  if (button.id === 'closeAuthGateBtn') {
    closeAuthGate();
    return;
  }

  if (button.dataset.filter) {
    activeFilter = button.dataset.filter;
    $$('.filter').forEach((filter) => filter.classList.toggle('is-active', filter === button));
    renderScrims();
    return;
  }

  if (button.id === 'toggleInfoBtn') {
    const infoDrawer = $('#infoDrawer');
    const isVisible = infoDrawer.classList.toggle('is-visible');
    infoDrawer.setAttribute('aria-hidden', String(!isVisible));
    button.setAttribute('aria-expanded', String(isVisible));
    return;
  }

  if (button.id === 'adminLogoutBtn') {
    const data = new FormData();
    data.set('action', 'logout');
    await postForm(data);
    window.location.reload();
    return;
  }

  if (button.id === 'adminBackScrimBtn') {
    setAppView('home');
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  if (button.id === 'primaryNavLink' && isAdmin()) {
    event.preventDefault();
    setAppView('admin');
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  if (button.dataset.adminOpenDeal) {
    activeDealId = Number(button.dataset.adminOpenDeal);
    pendingDealScrollToBottom = true;
    setAppView('deal');
    return;
  }

  if (button.dataset.nav === 'deal') {
    event.preventDefault();
    setAppView('deal');
    return;
  }

  if (button.dataset.nav === 'support') {
    event.preventDefault();
    setAppView('support');
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  if (button.dataset.nav === 'all-scrim') {
    event.preventDefault();
    setAppView('all');
    return;
  }

  if (button.dataset.nav === 'review') {
    event.preventDefault();
    setAppView('review');
    return;
  }

  if (button.dataset.nav === 'scrim-home') {
    event.preventDefault();
    activeDealId = 0;
    setAppView('home');
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  if (button.dataset.action === 'open_deal_chat') {
    activeDealId = Number(button.dataset.id || 0);
    delete unreadChatByScrim[activeDealId];
    unreadChatCount = Object.values(unreadChatByScrim).reduce((total, count) => total + Number(count || 0), 0);
    pendingDealScrollToBottom = true;
    renderDealApp();
    return;
  }

  if (button.dataset.action === 'open_group_chat') {
    activeDealId = -1;
    renderDealApp();
    return;
  }

  if (button.dataset.action === 'admin_delete_scrim') {
    const confirmed = window.confirm('Confirm delete scrim ini? Chat dan request berkaitan akan dipadam.');
    if (!confirmed) return;
    const data = new FormData();
    data.set('action', 'admin_delete_scrim');
    data.set('scrim_id', button.dataset.id);
    await postForm(data);
    return;
  }

  if (button.dataset.action === 'admin_delete_team') {
    const teamName = button.dataset.name || 'team ini';
    const confirmed = window.confirm(`Remove ${teamName}? Semua scrim, request, chat dan rekod berkaitan team ini turut dipadam.`);
    if (!confirmed) return;
    const data = new FormData();
    data.set('action', 'admin_delete_team');
    data.set('team_id', button.dataset.id);
    await postForm(data);
    return;
  }

  if (button.id === 'dealBackListBtn' || button.dataset.action === 'back_deal_list') {
    activeDealId = 0;
    renderDealApp();
    return;
  }

  if (button.id === 'toggleCreateBtn' || button.id === 'navCreateBtn') {
    toggleCreatePanel();
    return;
  }

  if (button.id === 'updateResultBtn') {
    toggleResultReview(true);
    return;
  }

  if (button.id === 'openProfileResultBtn') {
    toggleProfile(false);
    toggleResultReview(true);
    return;
  }

  if (button.id === 'profilePhoneRequiredBtn') {
    openPhoneRequiredPanel();
    return;
  }

  if (button.id === 'toggleHistoryBtn') {
    toggleCollapsible('#historyPanel', button);
    return;
  }

  if (button.id === 'rosterPrevBtn' || button.id === 'rosterNextBtn') {
    scrollRoster(button.id === 'rosterNextBtn' ? 1 : -1);
    return;
  }

  if (button.id === 'profileBtn') {
    if (isAdmin()) {
      setAppView('admin');
      window.scrollTo({top:0, behavior:'smooth'});
      return;
    }
    if (!state.team && !state.admin) {
      openAuthGate('login');
      return;
    }
    toggleProfile();
    return;
  }

  if (button.id === 'editProfileBtn') {
    toggleCollapsible('#profileEditPanel', button);
    return;
  }

  if (button.id === 'enableNotifyBtn') {
    try {
      await enableWebPushNotifications();
    } catch (error) {
      showToast(error.message || 'Push notification gagal setup.');
    }
    return;
  }

  if (button.id === 'closeProfileBtn' || button.id === 'profileBackdrop') {
    toggleProfile(false);
    return;
  }

  if (button.id === 'viewAllScrimBtn' || button.id === 'heroAllScrimBtn') {
    setAppView('all');
    return;
  }

  if (button.id === 'allScrimBackBtn') {
    activeDealId = 0;
    setAppView('home');
    window.scrollTo({top:0, behavior:'smooth'});
    return;
  }

  if (button.id === 'heroJoinScrimBtn') {
    setAppView('all');
    return;
  }

  if (button.id === 'heroOpenDealBtn') {
    setAppView('deal');
    return;
  }

  if (button.id === 'viewRankingBtn') {
    toggleRanking(true);
    return;
  }

  if (button.id === 'viewGtmlSlotBtn') {
    toggleGtmlSlotInfo(true);
    return;
  }

  if (button.id === 'closeGtmlSlotBtn' || button.id === 'gtmlSlotBackdrop') {
    toggleGtmlSlotInfo(false);
    return;
  }

  if (button.id === 'closeRankingBtn' || button.id === 'rankingBackdrop') {
    toggleRanking(false);
    return;
  }

  if (button.id === 'closeResultBtn' || button.id === 'resultBackdrop') {
    toggleResultReview(false);
    return;
  }

  if (button.id === 'resultJoinScrimBtn') {
    toggleResultReview(false);
    setAppView('all');
    return;
  }

  if (button.id === 'toggleReportResultBtn') {
    const form = $('#reportResultForm');
    if (form) form.hidden = !form.hidden;
    return;
  }

  if (button.dataset.togglePanel) {
    const panel = document.getElementById(button.dataset.togglePanel);
    if (panel) {
      panel.hidden = !panel.hidden;
      if (panel.hidden) {
        openScrimDetailIds.delete(button.dataset.togglePanel);
      } else {
        openScrimDetailIds.add(button.dataset.togglePanel);
      }
      button.setAttribute('aria-expanded', String(!panel.hidden));
      if (button.dataset.openLabel && button.dataset.closedLabel) {
        button.textContent = panel.hidden ? button.dataset.closedLabel : button.dataset.openLabel;
      }
    }
    return;
  }

  try {
    if (button.id === 'logoutBtn') {
      const data = new FormData();
      data.set('action', 'logout');
      await postForm(data);
      state = await loadState();
      render();
      return;
    }

    if (button.dataset.action === 'login_to_join') {
      openAuthGate('login');
      return;
    }

    if (button.dataset.action === 'request') {
      if (!requireTeamLogin('Login atau register untuk join scrim.')) return;
      if (!isScrimReady()) {
        openPhoneRequiredPanel();
        return;
      }
      const message = button.dataset.direct === '1' ? '' : (prompt('Message untuk host scrim?') || '');
      const data = new FormData();
      data.set('action', 'request_join');
      data.set('scrim_id', button.dataset.id);
      data.set('message', message);
      await postForm(data);
      return;
    }

    if (button.dataset.action === 'respond') {
      const data = new FormData();
      data.set('action', 'respond_request');
      data.set('request_id', button.dataset.id);
      data.set('decision', button.dataset.decision);
      await postForm(data);
      return;
    }

    if (button.dataset.action === 'phone_required') {
      openPhoneRequiredPanel();
      return;
    }

    if (button.dataset.action === 'quick_chat') {
      const data = new FormData();
      data.set('action', 'send_message');
      data.set('scrim_id', button.dataset.id);
      data.set('message', button.dataset.message || 'Room ID berapa ya?');
      await postForm(data, {preserveChatScroll:true, scrollChatToBottom:button.dataset.id});
      return;
    }

    if (button.dataset.action === 'report_no_show') {
      const confirmed = window.confirm('Confirm report no-show untuk scrim ini? Lawan akan ada 15 minit untuk dispute.');
      if (!confirmed) return;
      const data = new FormData();
      data.set('action', 'report_no_show');
      data.set('scrim_id', button.dataset.id);
      await postForm(data);
      return;
    }

    if (button.dataset.action === 'respond_no_show') {
      const data = new FormData();
      data.set('action', 'respond_no_show');
      data.set('scrim_id', button.dataset.id);
      data.set('decision', button.dataset.decision);
      await postForm(data);
      return;
    }

    if (button.dataset.action === 'send_chat') {
      const form = button.closest('.chat-form');
      if (form) {
        await sendChatForm(form);
      }
      return;
    }

    if (button.dataset.action === 'confirm_result') {
      const data = new FormData();
      data.set('action', 'confirm_result');
      data.set('scrim_id', button.dataset.id);
      data.set('decision', button.dataset.decision);
      await postForm(data);
      toggleResultReview(false);
    }
  } catch (error) {
    if (error.needsPhone) {
      openPhoneRequiredPanel();
      return;
    }
    showToast(error.message);
  }
});

async function loadState(){
  const response = await fetch(API_URL + '?state=1');
  const payload = await readApiResponse(response);
  if (!payload.ok) {
    throw new Error(payload.message || 'Gagal load scrim data.');
  }
  return payload;
}

async function pollState(){
  const canPoll = Boolean(state.team) || activeView === 'support' || (Boolean(state.admin) && activeView === 'deal');
  if (isPollingState || !canPoll) return;
  isPollingState = true;
  try {
    const payload = await loadState();
    syncIncomingMessages(payload);
    const shouldHoldChatFocus = Boolean(activeChatInput());
    state = payload;
    if (!shouldHoldChatFocus) {
      renderPreservingChatDrafts();
    }
  } catch (error) {
    console.warn(error);
  } finally {
    isPollingState = false;
  }
}

function startStatePolling(){
  window.clearInterval(statePollTimer);
  statePollTimer = window.setInterval(pollState, STATE_POLL_MS);
}

async function boot(){
  try {
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
      navigator.serviceWorker.register('scrim-sw.js?v=23').catch(console.warn);
    }
    syncTopNavHeight();
    syncKeyboardViewport();
    state = await loadState();
    syncIncomingMessages(state, false);
    render();
    startStatePolling();
  } catch (error) {
    showToast(error.message);
    render();
  }
}

boot();

window.addEventListener('resize', syncTopNavHeight);
window.addEventListener('load', syncTopNavHeight);
window.addEventListener('orientationchange', () => window.setTimeout(syncTopNavHeight, 160));

const playerRoster = $('#playerRoster');
if (playerRoster) {
  playerRoster.addEventListener('scroll', updateRosterRail, {passive:true});
  window.addEventListener('resize', updateRosterRail);
  requestAnimationFrame(updateRosterRail);
}


