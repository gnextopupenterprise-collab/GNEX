const API_URL = 'api/scrim.php';
if (location.port === '5500') {
  location.replace('http://localhost/Training%20coding%203%20(website%20gnex)/scrim.html');
}
let state = {ok:false, team:null, scrims:[], requests:[], stats:[], history:[]};
let activeFilter = 'all';

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
  return ['open','pending','confirmed','completed','rejected','reported'].includes(status) ? status : '';
}

function formatDate(value){
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return escapeHtml(value);
  return date.toLocaleString('ms-MY', {
    day:'2-digit',
    month:'short',
    year:'numeric',
    hour:'2-digit',
    minute:'2-digit'
  });
}

function showToast(message){
  const toast = $('#toast');
  toast.textContent = message;
  toast.classList.add('is-visible');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
}

async function postForm(formOrData){
  const body = formOrData instanceof FormData ? formOrData : new FormData(formOrData);
  const response = await fetch(API_URL, {method:'POST', body});
  const payload = await response.json();
  if (!payload.ok) {
    throw new Error(payload.message || 'Request gagal.');
  }
  if (payload.scrims) {
    state = payload;
    render();
  }
  showToast(payload.message || 'Berjaya.');
  return payload;
}

function currentTeamId(){
  return state.team ? Number(state.team.id) : 0;
}

function requestFor(scrim){
  return state.requests.find((request) => Number(request.scrim_id) === Number(scrim.id) && Number(request.requester_team_id) === currentTeamId());
}

function canControl(scrim){
  const teamId = currentTeamId();
  return teamId && [Number(scrim.creator_team_id), Number(scrim.opponent_team_id || 0)].includes(teamId);
}

function formatScrimDate(value){
  const date = new Date(value);
  if (!value || Number.isNaN(date.getTime())) return {date:'-', time:'-'};
  return {
    date:date.toLocaleDateString('ms-MY', {day:'2-digit', month:'long', year:'numeric'}).toUpperCase(),
    time:date.toLocaleTimeString('ms-MY', {hour:'2-digit', minute:'2-digit'}).toUpperCase()
  };
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
    const canSubmit = !['pending','reported'].includes(String(scrim.result_status || ''));
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
  const profileRankBadge = $('#profileRankBadge');
  const profileResultHint = $('#profileResultHint');
  const rosterGames = $('#rosterGames');
  const profileBadge = $('#profileBadge');
  const rosterWins = $('#rosterWins');
  const rosterLosses = $('#rosterLosses');
  const rosterRank = $('#rosterRank');
  const pendingRequests = state.team
    ? state.requests.filter((request) => Number(request.creator_team_id) === currentTeamId() && request.status === 'pending')
    : [];
  const pendingResults = pendingResultDeals();
  const hostResults = hostResultDeals();
  const resultBadge = $('#resultBadge');

  if (resultBadge) {
    const resultCount = pendingResults.length + hostResults.length;
    resultBadge.textContent = resultCount;
    resultBadge.classList.toggle('hidden', resultCount === 0);
  }

  if (state.team) {
    if (profileTeamName) profileTeamName.textContent = state.team.name;
    if (profileCardName) profileCardName.textContent = state.team.name;
    if (profileCardMeta) profileCardMeta.textContent = `Logged in as team ID #${state.team.id}`;
    if (profileBadge) {
      profileBadge.textContent = pendingRequests.length;
      profileBadge.classList.toggle('hidden', pendingRequests.length === 0);
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
        : '<p class="empty">Tiada result pending untuk confirm.</p>';
    }
  } else {
    if (profileTeamName) profileTeamName.textContent = 'TEAM';
    if (profileCardName) profileCardName.textContent = 'TEAM';
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
      <p class="mini-title">PRIVATE DEAL</p>
      <div class="hero-empty-deal">
        <h3>Anda belum menyertai scrim</h3>
        <p>Join open scrim dulu untuk buka private deal room.</p>
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
    <p class="mini-title">PRIVATE DEAL</p>
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
  const isAuthed = Boolean(state.team);
  document.body.classList.toggle('is-authed', isAuthed);
  document.body.classList.toggle('is-guest', !isAuthed);
  document.body.classList.remove('is-loading');
  if (scrimApp) {
    scrimApp.hidden = !isAuthed;
  }
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
  const visible = toggleCollapsible('#createPanel', $('#toggleCreateBtn'));
  if (visible) {
    $('#createPanel')?.scrollIntoView({behavior:'smooth', block:'start'});
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
  let scrims = state.scrims;
  if (activeFilter === 'open') {
    scrims = scrims.filter((scrim) => scrim.status === 'open');
  }
  if (activeFilter === 'deal') {
    scrims = scrims.filter((scrim) => ['pending','confirmed','completed'].includes(scrim.status));
  }

  if (!scrims.length) {
    list.innerHTML = '<p class="empty">Belum ada scrim untuk filter ini.</p>';
    return;
  }

  list.innerHTML = scrims.map((scrim, index) => {
    const isCreator = teamId === Number(scrim.creator_team_id);
    const existingRequest = requestFor(scrim);
    const canRequest = state.team && scrim.status === 'open' && !isCreator && !existingRequest;
    const opponentName = scrim.opponent_name || 'Menunggu opponent';
    const requestNote = existingRequest ? `<span class="chip ${statusClass(existingRequest.status)}">Request ${escapeHtml(existingRequest.status)}</span>` : '';
    const schedule = formatScrimDate(scrim.date_time);
    const detailId = `scrimDetail${scrim.id}`;
    const actionLabel = 'REQUEST JOIN';

    return `
      <article class="scrim-card scrim-card-${statusClass(scrim.status)}">
        <div class="scrim-card-main">
          <span class="scrim-number">#${String(index + 1).padStart(2, '0')}</span>
          <div class="scrim-identity">
            <span class="scrim-format">${escapeHtml(scrim.format)}</span>
            <h3>${escapeHtml(scrim.creator_name)}</h3>
            <p>${escapeHtml(scrim.title)}</p>
          </div>
          <div class="scrim-schedule">
            <strong>${escapeHtml(schedule.date)}</strong>
            <span>${escapeHtml(schedule.time)}</span>
          </div>
          <span class="chip scrim-status ${statusClass(scrim.status)}">${escapeHtml(scrim.status)}</span>
          <div class="scrim-card-actions">
            <button class="btn scrim-detail-button" type="button" data-toggle-panel="${detailId}" aria-expanded="false">DETAIL</button>
            ${canRequest
              ? `<button class="btn primary scrim-join-button" type="button" data-action="request" data-id="${scrim.id}">${actionLabel}</button>`
              : (isCreator && scrim.status === 'open' ? '<span class="scrim-action-state open">WAITING</span>' : '')}
          </div>
        </div>
        <div class="scrim-card-detail" id="${detailId}" hidden>
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
  const deals = state.scrims.filter((scrim) => canControl(scrim) && ['pending','confirmed'].includes(scrim.status));

  if (!state.team) {
    list.innerHTML = '<p class="empty">Login untuk akses deal room.</p>';
    return;
  }

  if (!deals.length) {
    list.innerHTML = '<p class="empty">Tiada private deal aktif.</p>';
    return;
  }

  list.innerHTML = deals.map((scrim) => {
    const isCreator = currentTeamId() === Number(scrim.creator_team_id);
    const isOpponent = currentTeamId() === Number(scrim.opponent_team_id || 0);
    const roomVisible = scrim.status === 'confirmed' && scrim.room_id;
    const resultPending = scrim.result_status === 'pending';
    const resultReported = scrim.result_status === 'reported';
    const resultRejected = scrim.result_status === 'rejected';
    const roomPanelId = `roomPanel-${scrim.id}`;
    const roomFormId = `roomForm-${scrim.id}`;
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

          ${resultRejected && isCreator ? '<p class="empty" style="margin-top:12px">Opponent reject result sebelum ini. Submit semula score yang betul.</p>' : ''}

          ${isCreator && !resultPending && !resultReported ? `
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

function render(){
  renderAccess();
  renderSession();
  renderResultReview();
  renderHeroDeal();
  renderScrims();
  renderRequests();
  renderDealRooms();
  renderRanking();
}

$('#authForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await postForm(event.currentTarget);
    event.currentTarget.reset();
  } catch (error) {
    showToast(error.message);
  }
});

$('#createForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await postForm(event.currentTarget);
    event.currentTarget.reset();
    setCollapsible('#createPanel', false);
    $('#toggleCreateBtn')?.setAttribute('aria-expanded', 'false');
  } catch (error) {
    showToast(error.message);
  }
});

document.addEventListener('submit', async (event) => {
  if (!event.target.matches('.room-form,.result-form,.report-form')) return;
  event.preventDefault();
  try {
    await postForm(event.target);
    if (event.target.matches('.report-form')) {
      toggleResultReview(false);
    }
  } catch (error) {
    showToast(error.message);
  }
});

document.addEventListener('click', async (event) => {
  const button = event.target.closest('button');
  if (!button) return;

  if (button.dataset.authTab) {
    const authMode = button.dataset.authTab;
    $$('.tab').forEach((tab) => tab.classList.toggle('is-active', tab.dataset.authTab === authMode));
    $('#authAction').value = authMode;
    $('#authSubmit').textContent = authMode === 'register' ? 'Register Team' : 'Login Team';
    const gateTitle = $('#authGateTitle');
    if (gateTitle) {
      gateTitle.textContent = authMode === 'register' ? 'Create Account' : 'Login Team';
    }
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

  if (button.id === 'toggleHistoryBtn') {
    toggleCollapsible('#historyPanel', button);
    return;
  }

  if (button.id === 'rosterPrevBtn' || button.id === 'rosterNextBtn') {
    scrollRoster(button.id === 'rosterNextBtn' ? 1 : -1);
    return;
  }

  if (button.id === 'profileBtn') {
    toggleProfile();
    return;
  }

  if (button.id === 'closeProfileBtn' || button.id === 'profileBackdrop') {
    toggleProfile(false);
    return;
  }

  if (button.id === 'viewAllScrimBtn') {
    $('#scrimBoard')?.scrollIntoView({behavior:'smooth', block:'start'});
    return;
  }

  if (button.id === 'heroJoinScrimBtn') {
    $('#scrimBoard')?.scrollIntoView({behavior:'smooth', block:'start'});
    return;
  }

  if (button.id === 'heroOpenDealBtn') {
    toggleProfile(true);
    return;
  }

  if (button.id === 'viewRankingBtn') {
    toggleRanking(true);
    return;
  }

  if (button.id === 'viewGtmlSlotBtn') {
    toggleRanking(true);
    showToast('Top ranking scrim akan dapat slot GTML.');
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
    $('#scrimBoard')?.scrollIntoView({behavior:'smooth', block:'start'});
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
      button.setAttribute('aria-expanded', String(!panel.hidden));
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

    if (button.dataset.action === 'request') {
      const message = prompt('Message untuk host scrim?') || '';
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

    if (button.dataset.action === 'confirm_result') {
      const data = new FormData();
      data.set('action', 'confirm_result');
      data.set('scrim_id', button.dataset.id);
      data.set('decision', button.dataset.decision);
      await postForm(data);
      toggleResultReview(false);
    }
  } catch (error) {
    showToast(error.message);
  }
});

async function loadState(){
  const response = await fetch(API_URL + '?state=1');
  const payload = await response.json();
  if (!payload.ok) {
    throw new Error(payload.message || 'Gagal load scrim data.');
  }
  return payload;
}

async function boot(){
  try {
    state = await loadState();
    render();
  } catch (error) {
    showToast(error.message);
    render();
  }
}

boot();

const playerRoster = $('#playerRoster');
if (playerRoster) {
  playerRoster.addEventListener('scroll', updateRosterRail, {passive:true});
  window.addEventListener('resize', updateRosterRail);
  requestAnimationFrame(updateRosterRail);
}


