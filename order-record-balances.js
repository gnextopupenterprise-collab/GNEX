(()=>{
'use strict';
const form=document.querySelector('[data-balance-form]');
if(!form)return;
const note=form.querySelector('[data-balance-note]');
const save=form.querySelector('.balance-save');
const wallets=['tng','digi','celcom'];
const api='api/clash-league.php';
const timeNodes=Object.fromEntries(wallets.map(wallet=>[wallet,form.querySelector(`[data-balance-time="${wallet}"]`)]));

const setNote=(text,state='')=>{
  note.textContent=text;
  form.classList.toggle('saved',state==='saved');
  form.classList.toggle('failed',state==='failed');
};
const format=value=>(Number(value)||0).toFixed(2);
const formatUpdatedAt=value=>{
  const match=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if(!match)return'Belum dikemas kini';
  const hour=Number(match[4]),hour12=hour%12||12,period=hour>=12?'PM':'AM';
  return `${match[3]}/${match[2]}/${match[1]} · ${hour12}:${match[5]} ${period}`;
};
const renderTimes=data=>wallets.forEach(wallet=>{
  if(timeNodes[wallet])timeNodes[wallet].textContent=formatUpdatedAt(data.updated_at?.[wallet]);
});

async function request(action,body){
  const response=await fetch(`${api}?action=${action}&_=${Date.now()}`,body?{method:'POST',body,cache:'no-store'}:{cache:'no-store'});
  const data=await response.json();
  if(!response.ok||!data.ok)throw Error(data.message||'Baki gagal dimuatkan.');
  return data;
}

async function loadBalances(){
  if(wallets.some(wallet=>document.activeElement===form.elements[wallet]))return;
  try{
    const data=await request('orderBalances');
    wallets.forEach(wallet=>{form.elements[wallet].value=format(data.balances?.[wallet]);});
    renderTimes(data);
    setNote('BAKI SEMASA');
  }catch(error){
    setNote(error.message||'Baki gagal dimuatkan.','failed');
  }
}

wallets.forEach(wallet=>{
  const input=form.elements[wallet];
  input.addEventListener('focus',()=>input.select());
  input.addEventListener('input',()=>setNote('TEKAN SIMPAN UNTUK KEMAS KINI'));
  input.addEventListener('blur',()=>{if(input.value!=='')input.value=format(input.value);});
});

form.addEventListener('submit',async event=>{
  event.preventDefault();
  const body=new FormData();
  body.set('action','saveOrderBalances');
  wallets.forEach(wallet=>body.set(wallet,format(form.elements[wallet].value)));
  save.disabled=true;
  save.textContent='...';
  setNote('MENYIMPAN BAKI...');
  try{
    const data=await request('saveOrderBalances',body);
    wallets.forEach(wallet=>{form.elements[wallet].value=format(data.balances?.[wallet]);});
    renderTimes(data);
    setNote('✓ BAKI BERJAYA DISIMPAN','saved');
  }catch(error){
    setNote(error.message||'Baki gagal disimpan.','failed');
  }finally{
    save.disabled=false;
    save.textContent='SIMPAN';
  }
});

loadBalances();
setInterval(loadBalances,30000);
})();
