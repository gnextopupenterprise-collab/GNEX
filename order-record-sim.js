(()=>{
'use strict';
const page=document.querySelector('[data-view="sim"]');if(!page)return;
const api='api/clash-league.php',list=page.querySelector('[data-sim-list]');
const money=n=>`RM${(Number(n)||0).toFixed(2)}`,esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const rows=()=>[...list.querySelectorAll('[data-sim-row]')],clean=s=>s==='—'?'':s||'';
function readDom(){return rows().map(row=>({name:clean(row.querySelector('b')?.textContent),number:clean(row.querySelector('.number')?.textContent),baki:Number(row.dataset.baki)||0,rgg:Number(row.dataset.rgg)||0,shell:Number(row.dataset.shell)||0,last_used:clean(row.querySelector('.last')?.textContent),last_updated:clean(row.querySelector('.updated')?.textContent),stock_balance_initialized:false}))}
let accounts=readDom();
async function request(action,body){const response=await fetch(`${api}?action=${action}&_=${Date.now()}`,body?{method:'POST',body,cache:'no-store'}:{cache:'no-store'}),data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Maklumat SIM gagal dimuatkan.');return data}
function totals(){page.querySelector('[data-sim-count]').textContent=accounts.length;page.querySelector('.sim-title>button b').textContent=accounts.length;page.querySelector('[data-sim-baki]').textContent=money(accounts.reduce((n,x)=>n+Number(x.baki||0),0));page.querySelector('[data-sim-rgg]').textContent=money(accounts.reduce((n,x)=>n+Number(x.rgg||0),0));page.querySelector('[data-sim-shell]').textContent=money(accounts.reduce((n,x)=>n+Number(x.shell||0),0))}
function render(){rows().forEach(row=>row.remove());accounts.forEach((item,index)=>{const row=document.createElement('div');row.className='sim-row';row.dataset.simRow='';row.dataset.baki=String(item.baki||0);row.dataset.rgg=String(item.rgg||0);row.dataset.shell=String(item.shell||0);row.innerHTML=`<b>${esc(item.name||'—')}</b><span class="number">${esc(item.number||'—')}</span><span class="baki money">${money(item.baki)}</span><span class="rgg money">${money(item.rgg)}</span><span class="shell money">${money(item.shell)}</span><span class="last">${esc(item.last_used||'—')}</span><span class="updated">${esc(item.last_updated||'—')}</span><button class="sim-row-edit" type="button" data-edit-index="${index}">EDIT</button>`;list.insertBefore(row,page.querySelector('[data-sim-empty]'))});totals();filterCurrent()}
function openEditor(index){const x=accounts[index];if(!x)return;const modal=document.createElement('div');modal.className='sim-editor-modal sim-single-editor';modal.innerHTML=`<form><header><div><small>EDIT SIM</small><h3>${esc(x.number||'Maklumat SIM')}</h3></div><button type="button" data-close>×</button></header><div class="sim-editor-table"><div class="sim-editor-head"><span>Nama akaun</span><span>Nombor</span><span>Baki</span><span>RGG</span><span>Shell</span><span>Last guna</span><span>Tarikh last</span></div><div class="sim-editor-row"><input name="name" value="${esc(x.name)}" placeholder="Nama"><input name="number" value="${esc(x.number)}" placeholder="01x..."><input name="baki" type="number" min="0" step="0.01" value="${Number(x.baki)||0}"><input name="rgg" type="number" min="0" step="0.01" value="${Number(x.rgg)||0}"><input name="shell" type="number" min="0" step="0.01" value="${Number(x.shell)||0}"><input name="last_used" value="${esc(x.last_used)}" placeholder="dd/mm/yyyy"><input name="last_updated" value="${esc(x.last_updated)}" placeholder="dd/mm/yyyy"></div></div><footer><span data-note></span><button type="submit">SIMPAN</button></footer></form>`;document.body.appendChild(modal);modal.querySelector('[data-close]').onclick=()=>modal.remove();modal.querySelector('form').onsubmit=async event=>{event.preventDefault();const form=event.currentTarget,save=form.querySelector('[type="submit"]'),note=form.querySelector('[data-note]');const updated={...x,name:form.name.value.trim(),number:form.number.value.trim(),baki:Number(form.baki.value)||0,rgg:Number(form.rgg.value)||0,shell:Number(form.shell.value)||0,last_used:form.last_used.value.trim(),last_updated:form.last_updated.value.trim()};const next=accounts.map((item,i)=>i===index?updated:item),body=new FormData();body.set('action','saveOrderSimAccounts');body.set('accounts',JSON.stringify(next));save.disabled=true;note.textContent='Menyimpan...';try{const data=await request('saveOrderSimAccounts',body);accounts=data.accounts||next;render();modal.remove()}catch(error){note.textContent=error.message;save.disabled=false}}}
list.addEventListener('click',event=>{const button=event.target.closest('[data-edit-index]');if(button)openEditor(Number(button.dataset.editIndex))});
const search=page.querySelector('[data-sim-search]'),reset=page.querySelector('[data-sim-reset]');
function filterCurrent(){const q=search.value.trim().toLowerCase();let shown=0;rows().forEach(row=>{const visible=!q||row.textContent.toLowerCase().includes(q);row.hidden=!visible;if(visible)shown++});page.querySelector('[data-sim-empty]').style.display=shown?'none':'block'}
search.addEventListener('input',filterCurrent);reset.addEventListener('click',()=>{search.value='';filterCurrent()});
let refreshing=false,lastSnapshot='';
async function refreshAccounts(force=false){
  if(refreshing||document.querySelector('.sim-editor-modal'))return;
  if(!force&&!page.classList.contains('active'))return;
  refreshing=true;
  try{
    const data=await request('orderSimAccounts');
    if(data.accounts?.length){
      const snapshot=JSON.stringify(data.accounts);
      if(force||snapshot!==lastSnapshot){
        const oldTop=list.scrollTop;
        accounts=data.accounts;
        lastSnapshot=snapshot;
        render();
        list.scrollTop=oldTop;
      }
    }
  }catch(_){}finally{refreshing=false}
}
document.querySelector('[data-nav="sim"]')?.addEventListener('click',()=>setTimeout(()=>refreshAccounts(true),0));
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshAccounts(true)});
window.addEventListener('focus',()=>refreshAccounts(true));
refreshAccounts(true);
setInterval(()=>refreshAccounts(false),1500);
})();
