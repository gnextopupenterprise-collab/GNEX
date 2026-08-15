(()=>{
const API='api/clash-league.php',WEB_APP='https://script.google.com/macros/s/AKfycbxXKQ7ARoerqZvJ32lTQeR2SlDjLO9H9IZuiy3Vyq9LLNLKmoI9bzerGnNQzTPdnpWe/exec',PUB='https://docs.google.com/spreadsheets/d/e/2PACX-1vSGOLsGL_RFkbGIyYbL5ec84eik9ptz7kf07QqbdqBy2tu90HFrTfkqq0gQvlXjuYsXgxp7K6cn8IFP/pub',URLS={all:`${PUB}?gid=0&single=true&output=csv`,daily:`${PUB}?gid=357721629&single=true&output=csv`},CACHE_KEY='gnex-order-sheet-live-v1';let allRows=[],dailyRows=[];
let allDateFilter='';
function csv(text){const rows=[];let row=[],cell='',quoted=false;for(let i=0;i<text.length;i++){const c=text[i],n=text[i+1];if(c==='"'&&quoted&&n==='"'){cell+='"';i++}else if(c==='"')quoted=!quoted;else if(c===','&&!quoted){row.push(cell);cell=''}else if((c==='\n'||c==='\r')&&!quoted){if(c==='\r'&&n==='\n')i++;row.push(cell);if(row.some(v=>String(v).trim()))rows.push(row);row=[];cell=''}else cell+=c}row.push(cell);if(row.some(v=>String(v).trim()))rows.push(row);return rows}const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));function num(v){return Number(String(v||'').replace(/[^0-9.-]/g,''))||0}function money(v){return`RM${num(v).toFixed(2)}`}function dateKey(v){const p=String(v||'').trim().split(/[\/-]/);if(p.length!==3)return'';if(p[0].length===4)return`${p[0]}-${p[1].padStart(2,'0')}-${p[2].padStart(2,'0')}`;return`${p[2].length===2?'20'+p[2]:p[2]}-${p[0].padStart(2,'0')}-${p[1].padStart(2,'0')}`}
function allHtml(rows){return rows.slice().reverse().map(r=>`<div class="order-row"><span data-label="ID">${esc(r[2])}</span><span data-label="Code">${esc(r[3])}</span><span data-label="Payment">${esc(r[4])}</span><span data-label="Stok Shell">${esc(r[7]||'-')}</span><span data-label="Modal">${esc(r[8]||'-')}</span><span data-label="Keuntungan" class="ok">${esc(r[9]||'-')}</span><span data-label="Tarikh">${esc(r[11]||'-')}</span><span data-label="Masa">${esc(r[10]||'-')}</span><span data-label="Status" class="${/success/i.test(r[13]||r[12]||'')?'ok':'fail'}">${esc(r[13]||r[12]||'-')}</span><small>${esc(r[6]||'')}</small></div>`).join('')}function clockKey(v){return String(v||'').split(':').map(x=>Number(x)||0).join(':')}function dailyOrder(r){return allRows.find(a=>String(a[2])===String(r[3])&&dateKey(a[11])===dateKey(r[1])&&clockKey(a[10])===clockKey(r[2]))||allRows.find(a=>String(a[2])===String(r[3])&&dateKey(a[11])===dateKey(r[1]))||[]}function dailyPayment(r){const stated=String(r[5]||'').toLowerCase();if(stated.includes('digi'))return'digi';if(stated.includes('celcom'))return'celcom';if(stated.includes('touch')||stated.includes('tng'))return'tng';const code=String(dailyOrder(r)[3]||'').toUpperCase();if(code[1]==='D')return'digi';if(code[1]==='C')return'celcom';if(code[1]==='T')return'tng';return'other'}function dailyHtml(rows){return rows.slice().reverse().map(r=>`<div class="order-row"><span data-label="ID">${esc(r[3])}</span><span data-label="Item">${esc(r[6])}</span><span data-label="Payment">${esc(r[5]||'-')}</span><span data-label="Pembelian">${esc(r[4])}</span><span data-label="Keuntungan" class="ok">${esc(r[8])}</span><small>${esc(r[1])} · ${esc(r[2])}</small></div>`).join('')}
function renderAll(q){
  const view=document.querySelector('[data-view="all"]'),card=view?.querySelector('.table-card'),search=view?.querySelector('.search');
  if(!card)return;
  q=String(q??search?.value??'').trim().toLowerCase();
  const rows=allRows.filter(r=>(!allDateFilter||dateKey(r[11])===allDateFilter)&&(!q||r.some(v=>String(v).toLowerCase().includes(q))));
  const summary=view.querySelector('[data-all-summary]');
  if(summary){
    summary.hidden=!allDateFilter;
    view.querySelector('[data-all-count]').textContent=rows.length;
    view.querySelector('[data-all-modal]').textContent=money(rows.reduce((sum,r)=>sum+num(r[8]),0));
    view.querySelector('[data-all-profit]').textContent=money(rows.reduce((sum,r)=>sum+num(r[9]),0));
  }
  card.innerHTML='<div class="table-head"><span>ID</span><span>Code</span><span>Payment</span><span>Stok Shell</span><span>Modal</span><span>Untung</span><span>Tarikh</span><span>Masa</span><span>Status</span></div>'+(rows.length?allHtml(rows):`<div class="table-empty">${allDateFilter?'Tiada rekod pada tarikh ini.':'Tiada rekod dijumpai.'}</div>`);
}
// Order Harian must use the exact same live rows as All Order. The old
// Daily Profit tab can lag behind after a Sheet row/code is corrected.
function dailyRowsFor(key){return allRows.filter(r=>dateKey(r[11])===key&&(r[2]||r[3])&&/success/i.test(String(r[13]||r[12]||''))).map(r=>['',r[11],r[10],r[2],r[4],r[5],r[6],r[8],r[9]||'-',r[3]])}
function datedOrderHtml(rows){return rows.slice().reverse().map(r=>`<div class="order-row"><span data-label="ID">${esc(r[3])}</span><span data-label="Item">${esc(r[6])}</span><span data-label="Payment">${esc(r[5]||'-')}</span><span data-label="Pembelian">${esc(r[4])}</span><span data-label="Modal Web">${esc(r[7]||'-')}</span><span data-label="Keuntungan" class="ok">${esc(r[8])}</span><small>${esc(r[1])} · ${esc(r[2])}</small></div>`).join('')}
function renderDaily(){
  const view=document.querySelector('[data-view="daily"]'),card=view?.querySelector('.table-card'),key=document.querySelector('[data-date]')?.value||'';
  if(!card)return;
  let profitTotal=view.querySelector('[data-total-profit]');
  if(!profitTotal){
    const profitCard=document.createElement('article');
    profitCard.className='profit';
    profitCard.innerHTML='<small>Jumlah Keuntungan</small><b data-total-profit>RM0.00</b>';
    view.querySelector('.summary')?.appendChild(profitCard);
    profitTotal=profitCard.querySelector('[data-total-profit]');
  }
  let modalTotal=view.querySelector('[data-total-modal]');
  if(!modalTotal){
    const modalCard=document.createElement('article');
    modalCard.className='modal-web';
    modalCard.innerHTML='<small>Modal Web</small><b data-total-modal>RM0.00</b>';
    view.querySelector('.summary')?.insertBefore(modalCard,view.querySelector('.summary .profit'));
    modalTotal=modalCard.querySelector('[data-total-modal]');
  }
  const rows=dailyRowsFor(key),sum=type=>rows.filter(r=>dailyPayment(r)===type).reduce((s,r)=>s+num(r[4]),0);
  view.querySelector('[data-total-orders]').textContent=rows.length;
  view.querySelector('[data-total-digi]').textContent=money(sum('digi'));
  view.querySelector('[data-total-celcom]').textContent=money(sum('celcom'));
  view.querySelector('[data-total-tng]').textContent=money(sum('tng'));
  view.querySelector('[data-total-all]').textContent=money(rows.reduce((s,r)=>s+num(r[4]),0));
  modalTotal.textContent=money(rows.reduce((s,r)=>s+num(r[7]),0));
  profitTotal.textContent=money(rows.reduce((s,r)=>s+num(r[8]),0));
  card.innerHTML='<div class="table-head"><span>ID</span><span>Item</span><span>Payment</span><span>Pembelian</span><span>Modal Web</span><span>Keuntungan</span></div>'+(rows.length?datedOrderHtml(rows):'<div class="table-empty">Tiada order pada tarikh ini.</div>');
}
async function directSheetData(){try{const r=await fetch(`${WEB_APP}?action=readOrders&_=${Date.now()}`,{cache:'no-store'}),data=await r.json();if(r.ok&&data.ok)return data}catch(_){/* fall back to legacy published CSV */}const results=await Promise.allSettled([fetch(URLS.all,{cache:'no-store'}).then(r=>{if(!r.ok)throw Error(`All Order HTTP ${r.status}`);return r.text()}),fetch(URLS.daily,{cache:'no-store'}).then(r=>{if(!r.ok)throw Error(`Harian HTTP ${r.status}`);return r.text()})]),allOk=results[0].status==='fulfilled',dailyOk=results[1].status==='fulfilled';if(!allOk&&!dailyOk)throw Error('Apps Script belum dikemas kini dan URL Publish to web sudah tidak sah.');return{ok:true,all_rows:allOk?csv(results[0].value):[],daily_rows:dailyOk?csv(results[1].value):[],all_live:allOk,daily_live:dailyOk}}
function showSheetData(data,fromCache=false){allRows=(data.all_rows||[]).slice(1).filter(r=>r[2]||r[3]);dailyRows=[];window.orderSheetRows=allRows;document.querySelectorAll('.sheet-pill').forEach(x=>{const live=!!data.all_live;x.textContent=fromCache?'DATA TERAKHIR':live?'LIVE SHEET':'SHEET OFFLINE';x.style.cssText=live||fromCache?'background:#e8f8ef;color:#078b56':'background:#fff0f1;color:#d33d4d'});const sync=document.querySelector('.sync'),fullyLive=!!data.all_live;if(sync){sync.textContent=fromCache?'SYNCING...':fullyLive?'SHEET LIVE':'SHEET OFFLINE';sync.style.cssText=fullyLive||fromCache?'background:#e8f8ef;color:#078b56':'background:#fff0f1;color:#d33d4d'}const search=document.querySelector('.search');if(search){search.disabled=false;search.oninput=()=>renderAll(search.value)}renderAll();renderDaily();window.dispatchEvent(new CustomEvent('order-sheet-data',{detail:{rows:allRows,dateKey,num}}));if(!fromCache&&data.all_rows?.length){try{localStorage.setItem(CACHE_KEY,JSON.stringify({saved_at:Date.now(),all_rows:data.all_rows,all_live:true}))}catch(_){}}}
function showSheetError(message){const sync=document.querySelector('.sync');if(sync){sync.textContent='SHEET OFFLINE';sync.title=message;sync.style.cssText='background:#fff0f1;color:#d33d4d'}document.querySelectorAll('.sheet-pill').forEach(x=>{x.textContent='SHEET OFFLINE';x.style.cssText='background:#fff0f1;color:#d33d4d'});document.querySelectorAll('.table-card').forEach(card=>{card.innerHTML=`<div class="table-empty"><strong>Google Sheet gagal dimuatkan.</strong><br>${esc(message)}</div>`})}
async function load(){try{let data;try{const response=await fetch(`${API}?action=orderSheetData&_=${Date.now()}`,{cache:'no-store'}),type=response.headers.get('content-type')||'';if(!type.includes('application/json'))throw Error(`API HTTP ${response.status}`);data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'API Sheet gagal dibaca')}catch(serverError){data=await directSheetData()}showSheetData(data)}catch(e){if(!allRows.length)showSheetError(e.message||'Sambungan Google Sheet gagal.')}}function showCachedImmediately(){try{const cached=JSON.parse(localStorage.getItem(CACHE_KEY)||'null');if(cached?.all_rows?.length)showSheetData(cached,true)}catch(_){}}const checkDate=document.querySelector('[data-check-date]');if(checkDate)checkDate.onclick=null;checkDate?.addEventListener('click',load);document.querySelector('[data-date]')?.addEventListener('change',renderDaily);const allDate=document.querySelector('[data-all-date]');document.querySelector('[data-all-date-check]')?.addEventListener('click',()=>{allDateFilter=allDate?.value||'';renderAll()});allDate?.addEventListener('change',()=>{allDateFilter=allDate.value;renderAll()});document.querySelector('[data-all-date-reset]')?.addEventListener('click',()=>{allDateFilter='';if(allDate)allDate.value='';renderAll()});document.addEventListener('visibilitychange',()=>{if(!document.hidden)load()});window.addEventListener('focus',load);showCachedImmediately();load();setInterval(load,10000);
})();
