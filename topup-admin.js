async function clearGnexBadge(){
  if("clearAppBadge" in navigator){
    try{
      await navigator.clearAppBadge();
    }catch(error){
      console.error(error);
    }
  }
}

async function updateAdminAppBadge(){
  const total=Object.values(state.counts||{}).reduce((sum,value)=>sum+(Number(value)||0),0);
  try{
    if(total>0&&"setAppBadge" in navigator)await navigator.setAppBadge(total);
    else if(total===0&&"clearAppBadge" in navigator)await navigator.clearAppBadge();
  }catch(error){console.debug("App badge tidak disokong",error);}
}

function syncAdminViewport(){
  const height=Math.round(window.visualViewport?.height||window.innerHeight);
  document.documentElement.style.setProperty("--admin-viewport-height",`${height}px`);
}

syncAdminViewport();
window.visualViewport?.addEventListener("resize",syncAdminViewport);
window.visualViewport?.addEventListener("scroll",syncAdminViewport);
window.addEventListener("orientationchange",()=>setTimeout(syncAdminViewport,250));
document.addEventListener("focusout",event=>{
  if(!event.target.matches?.("input,textarea,select"))return;
  [80,300,600].forEach(delay=>setTimeout(()=>{
    syncAdminViewport();
    window.scrollTo(0,0);
    document.documentElement.scrollTop=0;
    document.body.scrollTop=0;
  },delay));
});


const api =
  "api/topup.php";

const state = {
  csrf:"",
  admin:null,
  department:"topup",
  inbox:[],
  pending:[],
  labels:[],
  counts:{
    topup:0,
    tour:0,
    report:0
  },
  conversationId:0,
  lastMessageId:0,
  poll:null,
  chatPoll:null,
  notificationsEnabled:false,
  seenMessageIds:{}
  ,replyTo:null
  ,sending:false
  ,groupId:0
  ,groupLastId:0
  ,groupPoll:null
  ,communityChannel:""
};

const meta = {
  topup:{
    title:"Topup Inbox",
    kicker:"ADMIN TOPUP"
  },

  tour:{
    title:"Tournament Inbox",
    kicker:"ADMIN TOUR"
  },

  report:{
    title:"Report Inbox",
    kicker:"ADMIN REPORT"
  }
};

const $ =
  (s) => document.querySelector(s);

function esc(v){
  return String(v ?? "")
    .replace(
      /[&<>"']/g,
      c => ({
        "&":"&amp;",
        "<":"&lt;",
        ">":"&gt;",
        '"':"&quot;",
        "'":"&#039;"
      }[c])
    );
}

async function parseResponse(
  response
){
  const raw =
    await response.text();

  let data;

  try{
    data =
      raw
        ? JSON.parse(raw)
        : {};
  }catch(e){
    console.error(
      "API raw response:",
      raw
    );

    throw new Error(
      raw ||
      `API response tidak sah (${response.status}).`
    );
  }

  if(data.csrf){
    state.csrf =
      data.csrf;
  }

  if(
    !response.ok ||
    !data.ok
  ){
    throw new Error(
      data.message ||
      "Permintaan gagal."
    );
  }

  return data;
}

async function request(
  action,
  payload = null,
  query = "",
  retried = false
){
  const options = {
    credentials:"same-origin",
    cache:"no-store"
  };

  if(payload !== null){

    if(!state.csrf){
      const st =
        await request("state");

      state.csrf =
        st.csrf || "";
    }

    options.method =
      "POST";

    options.headers = {
      "Content-Type":
        "application/json"
    };

    options.body =
      JSON.stringify({
        ...payload,
        csrf:state.csrf
      });
  }

  const response =
    await fetch(
      `${api}?action=${encodeURIComponent(
        action
      )}${query}`,
      options
    );

  if(response.status===419 && payload!==null && !retried){
    state.csrf="";
    const fresh=await request("state");
    state.csrf=fresh.csrf||"";
    return request(action,payload,query,true);
  }

  return parseResponse(response);
}

async function compressAdminChatImage(file){
  if(!file?.type?.startsWith("image/") || file.size <= 1200 * 1024) return file;
  const bitmap=await createImageBitmap(file);
  const scale=Math.min(1,1600/Math.max(bitmap.width,bitmap.height));
  const canvas=document.createElement("canvas");
  canvas.width=Math.max(1,Math.round(bitmap.width*scale));
  canvas.height=Math.max(1,Math.round(bitmap.height*scale));
  canvas.getContext("2d").drawImage(bitmap,0,0,canvas.width,canvas.height);
  bitmap.close?.();
  let quality=.78,blob;
  do{blob=await new Promise(resolve=>canvas.toBlob(resolve,"image/jpeg",quality));quality-=.12;}
  while(blob&&blob.size>4.5*1024*1024&&quality>=.3);
  if(!blob)throw new Error("Gambar tidak dapat diproses.");
  return new File([blob],`${file.name.replace(/\.[^.]+$/,"")||"gambar"}.jpg`,{type:"image/jpeg"});
}

async function uploadAdminImage(file){
  file=await compressAdminChatImage(file);
  if(!state.csrf){const st=await request("state");state.csrf=st.csrf||"";}
  const form=new FormData();form.append("csrf",state.csrf);form.append("image",file);
  const response=await fetch(`${api}?action=uploadImage`,{method:"POST",credentials:"same-origin",body:form});
  return parseResponse(response);
}

function adminPushKeyBytes(value){const padding="=".repeat((4-value.length%4)%4);const raw=atob((value+padding).replace(/-/g,"+").replace(/_/g,"/"));return Uint8Array.from([...raw].map(char=>char.charCodeAt(0)));}
async function subscribeAdminPush(){
  if(!("serviceWorker" in navigator)||!("PushManager" in window)||Notification.permission!=="granted")return false;
  const appState=await request("state");
  if(!appState.push_public_key)throw new Error("Kunci push server belum tersedia.");
  const registration=await navigator.serviceWorker.register("admin-push-sw.js?v=1",{scope:new URL("topup-admin.html",location.href).pathname});
  let subscription=await registration.pushManager.getSubscription();
  if(subscription&&localStorage.getItem("gnex_admin_push_vapid_key")!==appState.push_public_key){await subscription.unsubscribe();subscription=null;}
  if(!subscription)subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:adminPushKeyBytes(appState.push_public_key)});
  await request("subscribePush",subscription.toJSON());
  localStorage.setItem("gnex_admin_push_vapid_key",appState.push_public_key);
  return true;
}

async function boot(){
  try{
    const data =
      await request("state");

    state.admin =
      data.admin || null;

    state.notificationsEnabled = localStorage.getItem("gnex_admin_notifications") === "on";

    state.csrf =
      data.csrf ||
      state.csrf;

    if(state.admin){
      showApp();

      if(state.notificationsEnabled&&"Notification" in window&&Notification.permission==="granted")subscribeAdminPush().catch(error=>console.error("Admin push subscription:",error));

      if(localStorage.getItem("gnex_admin_unread_reset_20260819")!=="done"){
        try{await request("markAllAdminRead",{});localStorage.setItem("gnex_admin_unread_reset_20260819","done");if("clearAppBadge" in navigator)await navigator.clearAppBadge();}catch(error){console.error("Unread reset:",error);}
      }

      await Promise.all([
        loadInbox(true),
        loadBotStatus()
      ]);

      startPolling();
    }

  }catch(error){
    console.error(error);
  }
}

function showApp(){
  $("#loginScreen")
    ?.classList.add(
      "is-hidden"
    );

  $("#adminApp")
    ?.classList.remove(
      "is-hidden"
    );

  if($("#adminName")){
    $("#adminName")
      .textContent =
      state.admin?.username ||
      "Admin";
  }

  if($("#profileAdminName")) $("#profileAdminName").textContent = state.admin?.username || "Admin";
  updateAdminNotificationButton();
}

$("#adminLoginForm")
  ?.addEventListener(
    "submit",
    async event => {

      event.preventDefault();

      const form =
        event.currentTarget;

      const status =
        $("#loginStatus");

      const button =
        form.querySelector(
          "[type=submit]"
        );

      button.disabled =
        true;

      if(status){
        status.textContent = "";
      }

      try{
        const data =
          await request(
            "adminLogin",
            Object.fromEntries(
              new FormData(form)
            )
          );

        state.admin =
          data.admin;

        showApp();

        await Promise.all([
          loadInbox(true),
          loadBotStatus()
        ]);

        startPolling();

      }catch(error){
        if(status){
          status.textContent =
            error.message;
        }
      }

      button.disabled =
        false;
    }
  );

async function logoutAdmin(){
  try{
    await request(
      "logout",
      {}
    );

    window.location.href = "index.html?chat=guest";

  }catch(error){
    alert(
      error.message ||
      "Logout gagal."
    );
  }
}

async function switchDepartment(
  department
){
  if(
    !meta[department]
  ) return;

  state.department =
    department;

  state.conversationId = 0;
  state.lastMessageId = 0;

  document
    .querySelectorAll(
      "[data-department]"
    )
    .forEach(btn => {

      btn.classList.toggle(
        "is-active",
        btn.dataset.department ===
          department
      );

    });

  $("#departmentTitle")
    .textContent =
    meta[department].title;

  $("#departmentKicker")
    .textContent =
    meta[department].kicker;

  $("#botToggleBtn")
    ?.classList.toggle(
      "is-hidden",
      department !== "topup"
    );

  $("#adminSendActions")
    ?.classList.toggle(
      "is-hidden",
      department !== "topup"
    );

  closeChatMobile();

  $("#chatView")
    ?.classList.add(
      "is-hidden"
    );

  $("#emptyChat")
    ?.classList.remove(
      "is-hidden"
    );

  await loadInbox(true);
}

async function loadInbox(
  render = true
){
  try{
    const data =
      await request(
        "adminInbox",
        null,
        `&department=${encodeURIComponent(
          state.department
        )}`
      );

    state.inbox =
      data.conversations || [];

    state.pending =
      data.pending_registrations || [];

    state.labels=data.labels||state.labels||[];
    renderLabelControls();

    notifyNewAdminMessages(state.inbox);

    state.counts =
      data.counts ||
      state.counts;

    updateAdminAppBadge();
    const totalUnread=Object.values(state.counts).reduce((sum,value)=>sum+(Number(value)||0),0);
    const chatBadge=$("#adminChatBadge");
    if(chatBadge){chatBadge.textContent=totalUnread>99?"99+":String(totalUnread);chatBadge.hidden=totalUnread===0;}
    $("#countTopup")
      .textContent =
      state.counts.topup || 0;

    $("#countTour")
      .textContent =
      state.counts.tour || 0;

    $("#countReport")
      .textContent =
      state.counts.report || 0;

    if(render){
      renderInbox();
      renderPending();
    }

  }catch(error){
    console.error(
      "Inbox error",
      error
    );
  }
}

function initials(name){
  const parts =
    String(
      name || "G"
    )
    .trim()
    .split(/\s+/);

  return parts
    .slice(0,2)
    .map(
      x => x[0] || ""
    )
    .join("")
    .toUpperCase();
}

function formatClock(value){
  if(!value) return "";

  const d =
    new Date(
      String(value)
        .replace(" ","T")
    );

  return Number.isNaN(
    d.getTime()
  )
    ? ""
    : d.toLocaleTimeString(
        "en-MY",
        {
          hour:"numeric",
          minute:"2-digit"
        }
      );
}

function renderInbox(){
  const box =
    $("#inboxList");

  if(!box) return;

  const q =
    (
      $("#inboxSearch")
        ?.value || ""
    )
    .trim()
    .toLowerCase();
  const labelFilter=$("#inboxLabelFilter")?.value||"";

  const items =
    state.inbox.filter(
      item => {

        const hay =
          `${
            item.display_name || ""
          } ${
            item.phone || ""
          } ${
            item.last_message || ""
          }`
          .toLowerCase();

        return (!labelFilter||item.admin_label===labelFilter)&&(!q||hay.includes(q));
      }
    );

  box.innerHTML =
    items.map(
      item => `
        <button
          class="inbox-item ${
            Number(item.id) ===
            state.conversationId
              ? "is-active"
              : ""
          } ${Number(item.unread_count || 0)>0 ? "is-unread" : ""}"
          onclick="openChat(${Number(
            item.id
          )})"
        >

          <div class="inbox-avatar">
            ${
              esc(
                initials(
                  item.display_name
                )
              )
            }
          </div>

          <div class="inbox-copy">

            <strong>
              ${
                esc(
                  item.display_name ||
                  "Guest"
                )
              }
            </strong>

            <p>
              ${
                esc(
                  item.last_message ||
                  "Belum ada mesej"
                )
              }
            </p>

            ${item.admin_label ? `<span class="inbox-label">${esc(item.admin_label)}</span>` : ""}

          </div>

          <time>
            ${
              esc(
                formatClock(
                  item.last_message_at
                )
              )
            }
          </time>

          ${Number(item.unread_count||0)>0?`<b class="inbox-unread-count" aria-label="${Number(item.unread_count)} mesej belum dibaca">${Number(item.unread_count)>99?"99+":Number(item.unread_count)}</b>`:""}

        </button>
      `
    ).join("")
    ||
    `
      <div
        style="
          padding:24px;
          color:#52525b;
          font-size:10px;
          text-align:center
        "
      >
        Belum ada chat dalam bahagian ini.
      </div>
    `;
}

function renderPending(){
  $("#pendingCount")
    .textContent =
      state.pending.length;

  const navBadge = $("#adminPendingBadge");
  if(navBadge){
    navBadge.textContent = state.pending.length;
    navBadge.hidden = state.pending.length === 0;
  }

  const box =
    $("#pendingList");

  if(!box) return;

  box.innerHTML =
    state.pending.map(
      item => `
        <div class="pending-item">

          <div>
            <strong>
              ${
                esc(
                  item.name ||
                  "Customer"
                )
              }
            </strong>

            <small>
              ${
                esc(
                  item.login_id ||
                  ""
                )
              }
            </small>
          </div>

          <button
            class="approve"
            onclick="
              reviewRegistration(
                ${Number(item.id)},
                'approve'
              )
            "
          >
            OK
          </button>

          <button
            class="reject"
            onclick="
              reviewRegistration(
                ${Number(item.id)},
                'reject'
              )
            "
          >
            X
          </button>

        </div>
      `
    ).join("")
    ||
    `
      <div
        style="
          padding:8px;
          color:#52525b;
          font-size:9px
        "
      >
        Tiada pending account.
      </div>
    `;
}

function showAdminView(view){
  const registration = $("#registrationView");
  const inbox = document.querySelector(".inbox-panel");
  const chat = document.querySelector(".chat-panel");
  const pages = {
    register:registration,
    group:$("#groupAdminView"),
    community:$("#communityAdminView"),
    profile:$("#adminProfileView")
  };
  const showPage = view !== "inbox";

  Object.entries(pages).forEach(([name,page]) => page?.classList.toggle("is-hidden", name !== view));
  inbox?.classList.toggle("admin-view-hidden", showPage);
  chat?.classList.toggle("admin-view-hidden", showPage);

  document.querySelectorAll("[data-admin-view]").forEach(button => {
    button.classList.toggle("is-active", button.dataset.adminView === view);
  });

  if(view === "register"){
    closeChatMobile();
    loadInbox(true);
  }
  if(view === "community") loadAdminCommunities();
  if(view === "group") loadAdminGroups();
}

function toggleAdminGroupCreate(){$("#adminGroupForm")?.classList.toggle("is-hidden");}
async function createAdminGroup(event){
  event.preventDefault();
  const form=event.currentTarget;
  const button=form.querySelector('[type="submit"]');
  const name=$("#adminGroupName").value.trim();
  if(!name)return;
  button.disabled=true;
  try{
    await request("createGroup",{name,description:$("#adminGroupDescription").value.trim()});
    form.reset();form.classList.add("is-hidden");
    await loadAdminGroups();
  }catch(error){
    await loadAdminGroups();
    const created=(window.__adminGroups||[]).some(group=>String(group.name).trim().toLowerCase()===name.toLowerCase());
    if(created){form.reset();form.classList.add("is-hidden");}
    else alert(error.message);
  }finally{button.disabled=false;}
}
function adminGroupAvatarMarkup(group){return group?.image_url?`<img src="${esc(group.image_url)}" alt="${esc(group.name)}">`:esc(initials(group?.name||"G"));}
async function loadAdminGroups(){const box=$("#adminGroupList");if(!box)return;try{const data=await request("groups");window.__adminGroups=data.groups||[];box.innerHTML=window.__adminGroups.map(g=>`<button type="button" class="admin-group-card" onclick="openAdminGroup(${Number(g.id)})"><span class="admin-group-card-avatar">${adminGroupAvatarMarkup(g)}</span><span><strong>${esc(g.name)}</strong><small>${esc(g.last_message||g.description||"Belum ada mesej")}</small><em>${Number(g.members||0)} ahli</em></span><b>›</b></button>`).join("")||'<div class="admin-community-empty">Belum ada group.</div>';}catch(error){box.innerHTML=esc(error.message);}}
async function openAdminGroup(id){const group=(window.__adminGroups||[]).find(g=>Number(g.id)===Number(id));state.groupId=Number(id);state.groupLastId=0;$("#adminGroupTitle").textContent=group?.name||"Group";$("#adminGroupAvatar").innerHTML=adminGroupAvatarMarkup(group);$("#adminGroupRoom").classList.remove("is-hidden");await loadAdminGroupMessages(true);clearInterval(state.groupPoll);state.groupPoll=setInterval(()=>loadAdminGroupMessages(false),3000);}
function closeAdminGroup(){clearInterval(state.groupPoll);state.groupId=0;$("#adminGroupRoom")?.classList.add("is-hidden");}
const adminGroupCrop={image:null,input:null,zoom:1,x:0,y:0,dragging:false,lastX:0,lastY:0,targetType:"group",targetId:null};
function drawAdminGroupCrop(canvas=$("#adminGroupCropCanvas"),outputScale=1){
  if(!canvas||!adminGroupCrop.image)return;
  const ctx=canvas.getContext("2d");const size=canvas.width;const image=adminGroupCrop.image;
  const base=Math.max(size/image.naturalWidth,size/image.naturalHeight);const scale=base*adminGroupCrop.zoom;
  const width=image.naturalWidth*scale,height=image.naturalHeight*scale;
  const factor=outputScale;const maxX=Math.max(0,(width-size)/2),maxY=Math.max(0,(height-size)/2);
  adminGroupCrop.x=Math.max(-maxX,Math.min(maxX,adminGroupCrop.x));adminGroupCrop.y=Math.max(-maxY,Math.min(maxY,adminGroupCrop.y));
  ctx.clearRect(0,0,size,size);ctx.drawImage(image,(size-width)/2+adminGroupCrop.x*factor,(size-height)/2+adminGroupCrop.y*factor,width,height);
}
function openAdminGroupCrop(input){
  if(!state.groupId)return;openAdminProfileCrop(input,"group",state.groupId);
}
function openAdminProfileCrop(input,targetType,targetId){
  const file=input.files?.[0];if(!file||!targetId)return;adminGroupCrop.targetType=targetType;adminGroupCrop.targetId=targetId;
  if(!file.type.startsWith("image/")){alert("Pilih fail gambar yang sah.");input.value="";return;}
  const image=new Image();const url=URL.createObjectURL(file);
  image.onload=()=>{URL.revokeObjectURL(url);adminGroupCrop.image=image;adminGroupCrop.input=input;resetAdminGroupCrop();$("#adminGroupCropModal").classList.add("is-open");$("#adminGroupCropModal").setAttribute("aria-hidden","false");};
  image.onerror=()=>{URL.revokeObjectURL(url);input.value="";alert("Gambar tidak dapat dibuka.");};image.src=url;
}
function closeAdminGroupCrop(){const modal=$("#adminGroupCropModal");modal?.classList.remove("is-open");modal?.setAttribute("aria-hidden","true");if(adminGroupCrop.input)adminGroupCrop.input.value="";adminGroupCrop.image=null;adminGroupCrop.input=null;adminGroupCrop.targetId=null;}
function resetAdminGroupCrop(){adminGroupCrop.zoom=1;adminGroupCrop.x=0;adminGroupCrop.y=0;if($("#adminGroupCropZoom"))$("#adminGroupCropZoom").value="1";drawAdminGroupCrop();}
function changeAdminGroupCropZoom(value){adminGroupCrop.zoom=Math.max(1,Math.min(3,Number(value)||1));drawAdminGroupCrop();}
async function saveAdminGroupCrop(){
  if(!adminGroupCrop.image||!adminGroupCrop.targetId)return;const button=$("#adminGroupCropSave");button.disabled=true;
  try{const output=document.createElement("canvas");output.width=512;output.height=512;const ctx=output.getContext("2d");const image=adminGroupCrop.image;const base=Math.max(512/image.naturalWidth,512/image.naturalHeight);const scale=base*adminGroupCrop.zoom;const width=image.naturalWidth*scale,height=image.naturalHeight*scale;const ratio=512/320;ctx.drawImage(image,(512-width)/2+adminGroupCrop.x*ratio,(512-height)/2+adminGroupCrop.y*ratio,width,height);const blob=await new Promise((resolve,reject)=>output.toBlob(value=>value?resolve(value):reject(new Error("Crop gambar gagal.")),"image/jpeg",.9));const file=new File([blob],"profile.jpg",{type:"image/jpeg"});const upload=await uploadAdminImage(file);const type=adminGroupCrop.targetType,target=adminGroupCrop.targetId;if(type==="community")await request("updateCommunityImage",{channel:target,image_url:upload.url});else await request("updateGroupImage",{group_id:target,image_url:upload.url});closeAdminGroupCrop();if(type==="community"){await loadAdminCommunities();const item=(window.__adminCommunities||[]).find(value=>value.channel===target);$("#adminCommunityAvatar").innerHTML=adminCommunityAvatarMarkup(item);}else{await loadAdminGroups();const group=(window.__adminGroups||[]).find(g=>Number(g.id)===Number(target));$("#adminGroupAvatar").innerHTML=adminGroupAvatarMarkup(group);}}catch(error){alert(error.message);}finally{button.disabled=false;}
}
const adminCropCanvas=$("#adminGroupCropCanvas");
adminCropCanvas?.addEventListener("pointerdown",event=>{if(!adminGroupCrop.image)return;adminGroupCrop.dragging=true;adminGroupCrop.lastX=event.clientX;adminGroupCrop.lastY=event.clientY;adminCropCanvas.setPointerCapture?.(event.pointerId);});
adminCropCanvas?.addEventListener("pointermove",event=>{if(!adminGroupCrop.dragging)return;const rect=adminCropCanvas.getBoundingClientRect();const factor=320/rect.width;adminGroupCrop.x+=(event.clientX-adminGroupCrop.lastX)*factor;adminGroupCrop.y+=(event.clientY-adminGroupCrop.lastY)*factor;adminGroupCrop.lastX=event.clientX;adminGroupCrop.lastY=event.clientY;drawAdminGroupCrop();});
adminCropCanvas?.addEventListener("pointerup",()=>{adminGroupCrop.dragging=false;});adminCropCanvas?.addEventListener("pointercancel",()=>{adminGroupCrop.dragging=false;});
async function deleteAdminGroup(){if(!state.groupId)return;const group=(window.__adminGroups||[]).find(g=>Number(g.id)===Number(state.groupId));if(!confirm(`Delete group ${group?.name||"ini"}? Semua chat dan ahli group akan dipadam.`))return;try{await request("deleteGroup",{group_id:state.groupId});closeAdminGroup();await loadAdminGroups();}catch(error){alert(error.message);}}
async function loadAdminGroupMessages(reset){if(!state.groupId)return;const box=$("#adminGroupMessages");const data=await request("groupMessages",null,`&group_id=${state.groupId}&after=${reset?0:state.groupLastId}`);if(reset){box.innerHTML="";state.groupLastId=0;}for(const m of data.messages||[]){const mine=Boolean(Number(m.is_mine));const avatar=mine?`<img class="admin-room-user-avatar" src="images/logo baru gnex .webp" alt="Admin">`:`<span class="admin-room-user-avatar">${esc(initials(m.sender_name))}</span>`;const content=`<div><header><strong>${esc(m.sender_name)}</strong><time>${esc(formatClock(m.created_at))}</time></header><section>${m.body?`<p>${esc(m.body)}</p>`:""}${m.media_url?`<img class="admin-chat-image" src="${esc(m.media_url)}" alt="Gambar group">`:""}</section></div>`;const row=document.createElement("article");row.className=`admin-room-message ${mine?"is-mine":""}`;row.innerHTML=mine?content+avatar:avatar+content;box.appendChild(row);state.groupLastId=Math.max(state.groupLastId,Number(m.id));}box.scrollTop=box.scrollHeight;}
async function sendAdminGroupMessage(event){event.preventDefault();const input=$("#adminGroupInput");if(!input.value.trim())return;await request("sendGroupMessage",{group_id:state.groupId,body:input.value.trim()});input.value="";await loadAdminGroupMessages(false);}
async function sendAdminGroupImage(input){if(!input.files?.[0]||!state.groupId)return;try{const up=await uploadAdminImage(input.files[0]);await request("sendGroupMessage",{group_id:state.groupId,media_url:up.url});await loadAdminGroupMessages(false);}catch(error){alert(error.message);}input.value="";}

async function publishCommunityPost(event){
  event.preventDefault();
  const channel = state.communityChannel;
  const body = $("#adminCommunityBody").value.trim();
  const file=$("#adminCommunityImage")?.files?.[0];
  if(!body&&!file) return;
  try{
    let media_url="";if(file){const upload=await uploadAdminImage(file);media_url=upload.url;}
    await request("createCommunityPost",{channel,body,media_url});
    $("#adminCommunityBody").value = "";
    if($("#adminCommunityImage")) $("#adminCommunityImage").value="";
    await loadAdminCommunityPosts();
  }catch(error){ alert(error.message); }
}

function toggleAdminCommunityCreate(){$("#adminCommunityCreateForm")?.classList.toggle("is-hidden");}
async function createAdminCommunity(event){event.preventDefault();const form=event.currentTarget;const button=form.querySelector('[type="submit"]');button.disabled=true;try{await request("createCommunity",{name:$("#adminCommunityName").value.trim(),description:$("#adminCommunityDescription").value.trim()});form.reset();form.classList.add("is-hidden");await loadAdminCommunities();}catch(error){alert(error.message);}finally{button.disabled=false;}}
function adminCommunityAvatarMarkup(item){return item?.image_url?`<img src="${esc(item.image_url)}" alt="${esc(item.name)}">`:esc(initials(item?.name||"C"));}
async function loadAdminCommunities(){const box=$("#adminCommunityList");if(!box)return;try{const data=await request("communities");window.__adminCommunities=data.communities||[];box.innerHTML=window.__adminCommunities.map(item=>`<button type="button" class="admin-group-card" onclick="openAdminCommunityRoom('${esc(item.channel)}')"><span class="admin-group-card-avatar">${adminCommunityAvatarMarkup(item)}</span><span><strong>${esc(item.name)}</strong><small>${esc(item.last_post||item.description||"Belum ada update")}</small><em>${Number(item.post_count||0)} update</em></span><b>›</b></button>`).join("")||'<div class="admin-community-empty">Belum ada komuniti.</div>';}catch(error){box.innerHTML=`<div class="admin-community-empty">${esc(error.message)}</div>`;}}
async function openAdminCommunityRoom(channel){const item=(window.__adminCommunities||[]).find(value=>value.channel===channel);if(!item)return;state.communityChannel=channel;$("#adminCommunityTitle").textContent=item.name;$("#adminCommunityAvatar").innerHTML=adminCommunityAvatarMarkup(item);$("#adminCommunityRoom").classList.remove("is-hidden");await loadAdminCommunityPosts();}
function closeAdminCommunityRoom(){state.communityChannel="";$("#adminCommunityRoom")?.classList.add("is-hidden");loadAdminCommunities();}
function openAdminCommunityCrop(input){if(!state.communityChannel)return;openAdminProfileCrop(input,"community",state.communityChannel);}

async function loadAdminCommunityPosts(){
  const channel = state.communityChannel;
  const box = $("#adminCommunityPosts");
  if(!box||!channel) return;
  try{
    const data = await request("communityPosts",null,`&channel=${encodeURIComponent(channel)}`);
    const avatar=data.community?.image_url||"images/logo baru gnex .webp";box.innerHTML = (data.posts || []).slice().reverse().map(item => `<article class="admin-community-message"><img src="${esc(avatar)}" alt=""><div><header><strong>${esc(item.admin_name || "Admin")}</strong><time>${esc(formatClock(item.created_at))}</time></header><section>${item.body?`<p>${esc(item.body).replace(/\n/g,"<br>")}</p>`:""}${item.media_url?`<img class="admin-chat-image" src="${esc(item.media_url)}" alt="Gambar komuniti">`:""}</section><small>👍 ${Number(item.likes || 0)} · ❤️ ${Number(item.hearts || 0)}</small></div></article>`).join("") || '<div class="admin-community-empty">Belum ada update.</div>';
    box.scrollTop=box.scrollHeight;
  }catch(error){ box.innerHTML = `<div class="admin-community-empty">${esc(error.message)}</div>`; }
}

async function reviewRegistration(
  customerId,
  decision
){
  try{
    await request(
      "reviewRegistration",
      {
        customer_id:
          customerId,

        decision
      }
    );

    await loadInbox(true);

  }catch(error){
    alert(
      error.message
    );
  }
}

async function openChat(id){
  clearGnexBadge();

  const item = state.inbox.find(
      x => Number(x.id) === Number(id)
    );

  if(!item) return;

  state.conversationId =
    Number(id);

  state.lastMessageId = 0;

  try{
    await request("markConversationRead", {conversation_id:state.conversationId});
    item.admin_last_read_message_id = item.last_message_id || item.admin_last_read_message_id;
    await loadInbox(false);
  }catch(error){}

  updateChatLabelButton(item.admin_label || "");
  if($("#chatLabelSelect")) $("#chatLabelSelect").value=item.admin_label||"";
  updateChatBanButton(Boolean(item.banned_at));

  $("#chatName")
    .textContent =
    item.display_name ||
    "Customer";

  $("#chatMeta")
    .textContent =
    item.phone ||
    item.account_status ||
    "Guest";

  $("#chatDepartmentBadge")
    .textContent =
    state.department
      .toUpperCase();

  $("#emptyChat")
    ?.classList.add(
      "is-hidden"
    );

  $("#chatView")
    ?.classList.remove(
      "is-hidden"
    );

  $(".chat-panel")
    ?.classList.add(
      "is-mobile-open"
    );

  renderInbox();

  await loadMessages(true);

  clearInterval(
    state.chatPoll
  );

  state.chatPoll =
    setInterval(() => {

      if(
        state.conversationId
      ){
        loadMessages(false);
      }

    },2500);
}

function closeChatMobile(){
  $(".chat-panel")
    ?.classList.remove(
      "is-mobile-open"
    );
}


function closeAdminChat(){

  clearInterval(
    state.chatPoll
  );

  state.chatPoll = null;

  state.conversationId = 0;
  state.lastMessageId = 0;

  $("#chatView")
    ?.classList.add(
      "is-hidden"
    );

  $("#emptyChat")
    ?.classList.remove(
      "is-hidden"
    );

  $(".chat-panel")
    ?.classList.remove(
      "is-mobile-open"
    );

  renderInbox();
}

function renderMessages(
  items,
  reset
){
  const box =
    $("#adminMessages");

  if(!box) return;

  if(reset){
    box.innerHTML = "";
    state.lastMessageId = 0;
  }

  items.forEach(
    message => {

      const id =
        Number(
          message.id
        );

      const orderStatus = message.message_kind === "order_status" ? String(message.order_status || "") : "";
      const statusHtml = orderStatus === "processing"
        ? '<span class="admin-order-spinner" aria-hidden="true"></span>Order sedang diproses'
        : '<span class="admin-order-check" aria-hidden="true">✓</span>Order complete';
      const existingRow = box.querySelector(`[data-message-id="${id}"]`);
      if(existingRow && orderStatus){
        const bubble = existingRow.querySelector(".admin-bubble");
        if(bubble){
          bubble.className = `admin-bubble admin-order-status ${orderStatus}`;
          bubble.innerHTML = statusHtml;
        }
        return;
      }

      if(
        id <=
        state.lastMessageId
      ) return;

      const isAdmin =
        ["admin","system"]
          .includes(
            message.sender_type
          );

      const row =
        document.createElement(
          "div"
        );

      row.className =
        `admin-row ${
          isAdmin
            ? "is-admin"
            : "is-customer"
        }`;
      row.dataset.messageId = String(id);
      row.dataset.messageBody = message.body || "Gambar";

      row.innerHTML = `
        <div>

          ${message.reply_to_message_id ? `<div class="admin-reply-quote"><strong>${["admin","system"].includes(message.reply_sender_type)?"GNEX Admin":"Customer"}</strong>${esc(message.reply_body||"Mesej")}</div>` : ""}
          ${message.body ? (orderStatus ? `<div class="admin-bubble admin-order-status ${orderStatus}">${statusHtml}</div>` : `<div class="admin-bubble" data-copy-message="${esc(message.body)}" onclick="showAdminMessageCopy(this,event)">${esc(message.body)}<button type="button" class="admin-copy-btn" onclick="copyAdminMessage(this,event)">COPY</button></div>`) : ""}
          ${message.media_url ? `<img class="admin-chat-image" src="${esc(message.media_url)}" alt="Gambar chat" loading="lazy">` : ""}

          <time>
            ${
              esc(
                formatClock(
                  message.created_at
                )
              )
            }
          </time>

        </div>
      `;

      box.appendChild(row);

      state.lastMessageId =
        Math.max(
          state.lastMessageId,
          id
        );
    }
  );

  if(
    items.length ||
    reset
  ){
    box.scrollTop =
      box.scrollHeight;
  }
}

function cancelAdminReply(){state.replyTo=null;$("#adminReplyPreview")?.classList.add("is-hidden");}
function chooseAdminReply(row){const id=Number(row?.dataset.messageId||0);if(!id)return;state.replyTo={id,body:row.dataset.messageBody||"Mesej"};$("#adminReplyCopy").textContent=state.replyTo.body;$("#adminReplyPreview").classList.remove("is-hidden");$("#adminReply")?.focus({preventScroll:true});}
let adminSwipe=null;
$("#adminMessages")?.addEventListener("touchstart",event=>{const row=event.target.closest(".admin-row[data-message-id]");if(!row)return;const touch=event.touches[0];adminSwipe={row,x:touch.clientX,y:touch.clientY,dx:0};},{passive:true});
$("#adminMessages")?.addEventListener("touchmove",event=>{if(!adminSwipe)return;const touch=event.touches[0],dx=touch.clientX-adminSwipe.x,dy=touch.clientY-adminSwipe.y;if(Math.abs(dy)>Math.abs(dx)+10)return;adminSwipe.dx=Math.max(-75,Math.min(75,dx));adminSwipe.row.classList.add("is-swiping");adminSwipe.row.classList.toggle("is-swipe-left",adminSwipe.dx<0);adminSwipe.row.style.transform=`translateX(${adminSwipe.dx}px)`;},{passive:true});
$("#adminMessages")?.addEventListener("touchend",()=>{if(!adminSwipe)return;const {row,dx}=adminSwipe;row.style.transform="";row.classList.remove("is-swiping","is-swipe-left");if(Math.abs(dx)>=48)chooseAdminReply(row);adminSwipe=null;});
$("#adminMessages")?.addEventListener("mousedown",event=>{if(event.button!==0)return;const row=event.target.closest(".admin-row[data-message-id]");if(row)adminSwipe={row,x:event.clientX,y:event.clientY,dx:0};});
window.addEventListener("mousemove",event=>{if(!adminSwipe||event.buttons!==1)return;const dx=event.clientX-adminSwipe.x,dy=event.clientY-adminSwipe.y;if(Math.abs(dy)>Math.abs(dx)+10)return;adminSwipe.dx=Math.max(-75,Math.min(75,dx));adminSwipe.row.classList.add("is-swiping");adminSwipe.row.classList.toggle("is-swipe-left",adminSwipe.dx<0);adminSwipe.row.style.transform=`translateX(${adminSwipe.dx}px)`;});
window.addEventListener("mouseup",()=>{if(!adminSwipe)return;const {row,dx}=adminSwipe;row.style.transform="";row.classList.remove("is-swiping","is-swipe-left");if(Math.abs(dx)>=48)chooseAdminReply(row);adminSwipe=null;});

async function setChatOrderStatus(orderStatus,button){
  if(!state.conversationId)return;
  const buttons=document.querySelectorAll(".admin-send-actions button");
  buttons.forEach(item=>item.disabled=true);
  try{
    await request("setOrderStatus",{conversation_id:state.conversationId,order_status:orderStatus});
    await loadMessages(orderStatus === "completed");
    await loadInbox(true);
  }catch(error){
    alert(error.message||"Status order gagal dikemas kini.");
  }finally{
    buttons.forEach(item=>item.disabled=false);
  }
}

async function loadMessages(
  reset = false
){
  if(
    !state.conversationId
  ) return;

  try{
    const after =
      reset
        ? 0
        : state.lastMessageId;

    const data =
      await request(
        "messages",
        null,
        `&conversation_id=${
          state.conversationId
        }&after=${after}`
      );

    renderMessages(
      data.messages || [],
      reset
    );

  }catch(error){
    console.error(
      "Messages error",
      error
    );
  }
}

$("#adminReplyForm")
  ?.addEventListener(
    "submit",
    async event => {

      event.preventDefault();

      const input =
        $("#adminReply");

      const body =
        input?.value.trim() ||
        "";

      if(
        !body ||
        !state.conversationId || state.sending
      ) return;

      state.sending=true;
      const sendButton=event.currentTarget.querySelector('[type="submit"]');if(sendButton)sendButton.disabled=true;
      const replyTo=state.replyTo,box=$("#adminMessages"),temp=document.createElement("div");
      temp.className="admin-row is-admin is-sending";temp.innerHTML=`<div>${replyTo?`<div class="admin-reply-quote"><strong>Reply mesej</strong>${esc(replyTo.body)}</div>`:""}<div class="admin-bubble">${esc(body)}</div><time>${esc(formatClock(new Date().toISOString()))}</time></div>`;box?.appendChild(temp);if(box)box.scrollTop=box.scrollHeight;
      input.value="";cancelAdminReply();

      try{
        await request(
          "sendMessage",
          {
            conversation_id:
              state.conversationId,

            department:
              state.department,

            body,
            reply_to_message_id:replyTo?.id||0
          }
        );

        temp.remove();

        input.focus({
          preventScroll:true
        });

        await loadMessages(false);
        await loadInbox(true);

      }catch(error){
        temp.remove();if(!input.value)input.value=body;

        alert(
          error.message
        );
      }finally{
        state.sending=false;if(sendButton)sendButton.disabled=false;
      }
    }
  );

let botEnabled = true;

async function loadBotStatus(){
  try{
    const data =
      await request(
        "botStatus"
      );

    botEnabled =
      !!data.enabled;

    updateBotButton();

  }catch(error){
    console.error(error);
  }
}

function updateBotButton(){
  const btn =
    $("#botToggleBtn");

  if(!btn) return;

  btn.textContent =
    botEnabled
      ? "BOT ON"
      : "BOT OFF";

  btn.classList.toggle(
    "is-off",
    !botEnabled
  );
}

async function toggleBotStatus(){
  try{
    const data =
      await request(
        "setBotStatus",
        {
          enabled:
            !botEnabled
        }
      );

    botEnabled =
      !!data.enabled;

    updateBotButton();

  }catch(error){
    alert(
      error.message ||
      "Gagal ubah status bot."
    );
  }
}

function renderLabelControls(){
  const selectedFilter=$("#inboxLabelFilter")?.value||"";
  const options=(state.labels||[]).map(item=>`<option value="${esc(item.name)}">${esc(item.name)}</option>`).join("");
  const filter=$("#inboxLabelFilter");if(filter){filter.innerHTML=`<option value="">SEMUA LABEL</option>${options}`;filter.value=selectedFilter;}
  const select=$("#chatLabelSelect");if(select){const item=state.inbox.find(x=>Number(x.id)===state.conversationId);select.innerHTML=`<option value="">TIADA LABEL</option>${options}`;select.value=item?.admin_label||"";}
}

function toggleChatLabelMenu(){$("#chatLabelMenu")?.classList.toggle("is-hidden");}

async function applyChatLabel(value){
  if(!state.conversationId)return;
  const item=state.inbox.find(x=>Number(x.id)===state.conversationId);
  try{const data=await request("setConversationLabel",{conversation_id:state.conversationId,label:value});if(item)item.admin_label=data.label||"";updateChatLabelButton(data.label||"");$("#chatLabelMenu")?.classList.add("is-hidden");renderInbox();}catch(error){alert(error.message);}
}

async function createReusableLabel(){
  const value=(prompt("Nama label baru:")||"").trim();if(!value)return;
  try{const data=await request("createChatLabel",{label:value});state.labels=data.labels||state.labels;renderLabelControls();await applyChatLabel(value);}catch(error){alert(error.message);}
}

function updateChatBanButton(banned){const button=$("#chatBanBtn");if(!button)return;button.textContent=banned?"UNBAN":"BAN";button.classList.toggle("is-banned",banned);}
async function toggleChatBan(){const item=state.inbox.find(x=>Number(x.id)===state.conversationId);if(!item)return;const banned=!Boolean(item.banned_at);let reason="";if(banned){reason=prompt("Sebab ban:","Spam mesej")||"Spam mesej";}try{await request("banConversationDevice",{conversation_id:state.conversationId,banned,reason});item.banned_at=banned?new Date().toISOString():null;updateChatBanButton(banned);}catch(error){alert(error.message);}}

async function sendAdminChatImage(input){if(!input.files?.[0]||!state.conversationId)return;try{const up=await uploadAdminImage(input.files[0]);await request("sendMessage",{conversation_id:state.conversationId,department:state.department,body:"",media_url:up.url});await loadMessages(false);}catch(error){alert(error.message);}input.value="";}

function updateChatLabelButton(label){
  const button = $("#chatLabelBtn");
  if(button) button.textContent = label ? `# ${label}` : "+ LABEL";
}

function showAdminMessageCopy(bubble,event){
  if(event.target.closest(".admin-copy-btn"))return;
  document.querySelectorAll(".admin-bubble.is-copy-open").forEach(item=>{if(item!==bubble)item.classList.remove("is-copy-open");});
  bubble.classList.toggle("is-copy-open");
}

async function copyAdminMessage(button,event){
  event.stopPropagation();
  const bubble=button.closest(".admin-bubble");
  const text=bubble?.dataset.copyMessage||"";
  try{
    if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(text);
    else{const area=document.createElement("textarea");area.value=text;area.style.position="fixed";area.style.opacity="0";document.body.appendChild(area);area.select();document.execCommand("copy");area.remove();}
    button.textContent="COPIED";
    setTimeout(()=>{button.textContent="COPY";bubble?.classList.remove("is-copy-open");},900);
  }catch(error){alert("Mesej gagal disalin.");}
}

function updateAdminNotificationButton(){
  const button = $("#adminNotificationBtn");
  if(!button) return;
  const on = state.notificationsEnabled && "Notification" in window && Notification.permission === "granted";
  button.textContent = on ? "NOTIFIKASI ON" : "ON NOTIFIKASI";
  button.classList.toggle("is-on", on);
}

async function enableAdminNotifications(){
  if(!("Notification" in window) || !("serviceWorker" in navigator)) return alert("Browser ini tidak menyokong notifikasi.");
  const permission = await Notification.requestPermission();
  state.notificationsEnabled = permission === "granted";
  localStorage.setItem("gnex_admin_notifications", state.notificationsEnabled ? "on" : "off");
  if(state.notificationsEnabled){
    await navigator.serviceWorker.register("admin-push-sw.js?v=1",{scope:new URL("topup-admin.html",location.href).pathname});
    try{await subscribeAdminPush();}catch(error){alert(error.message||"Push notification gagal diaktifkan.");}
  }
  updateAdminNotificationButton();
}

async function notifyNewAdminMessages(items){
  if(!state.notificationsEnabled || !("Notification" in window) || Notification.permission !== "granted") return;
  for(const item of items){
    const id = Number(item.last_message_id || 0);
    const old = Number(state.seenMessageIds[item.id] || 0);
    state.seenMessageIds[item.id] = id;
    if(!old || id <= old || !["guest","customer"].includes(item.last_sender)) continue;
    try{
      const registration = await navigator.serviceWorker.ready;
      await registration.showNotification(`Mesej baharu · ${item.display_name || "Customer"}`, {body:item.last_message || "Chat baharu",icon:"images/logo baru gnex .webp",badge:"images/logo baru gnex .webp",tag:`admin-chat-${item.id}`,data:{url:"topup-admin.html"}});
    }catch(error){}
  }
}

function startPolling(){
  clearInterval(
    state.poll
  );

  state.poll =
    setInterval(
      () =>
        loadInbox(true),
      5000
    );
}

boot();
