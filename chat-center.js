(() => {
  const api = "api/topup.php";

  const state = {
    csrf: "",
    department: "",
    conversationId: 0,
    lastMessageId: 0,
    pollTimer: null,
    homeTimer: null,
    groupId:0,
    groupMuted:false,
    groupPoll:null
    ,groupSeen:{},replyTo:null,sending:false
  };

  const departmentMeta = {
    topup:  {
      title:"Admin Topup",
      subtitle:"Pembelian, harga & order topup",
      avatar:"images/admin-topup-logo.png",
      cls:"topup"
    },
    tour:   {
      title:"Admin Tour",
      subtitle:"Tournament, jadual & pendaftaran",
      avatar:"images/admin-tour-logo.png",
      cls:"tour"
    },
    report: {
      title:"Admin Report",
      subtitle:"Masalah, aduan & bantuan akaun",
      avatar:"images/admin-report-logo.png",
      cls:"report"
    }
  };

  const $ = (s) => document.querySelector(s);
  let deferredInstallPrompt=null;

  function isStandaloneApp(){
    return window.matchMedia("(display-mode: standalone)").matches||window.navigator.standalone===true;
  }

  function hidePwaNote(){
    const note=$("#gc-pwa-note");
    if(note)note.hidden=true;
  }

  function refreshPwaNote(){
    const note=$("#gc-pwa-note");
    const panel=$("#chat-center");
    if(!note||!panel?.classList.contains("is-open"))return hidePwaNote();
    const title=$("#gc-pwa-note-title"),copy=$("#gc-pwa-note-copy"),action=$("#gc-pwa-note-action");
    if("Notification" in window&&Notification.permission!=="granted"){
      title.textContent="Hidupkan notifikasi";
      copy.textContent=Notification.permission==="denied"?"Benarkan notifikasi dalam setting browser.":"Supaya mesej chat tak terlepas.";
      action.textContent="ON";
      action.onclick=async()=>{
        await window.enableUserNotifications?.();
        refreshPwaNote();
      };
      note.hidden=false;
      return;
    }
    if(!deferredInstallPrompt)return hidePwaNote();
    title.textContent="Tambah GNEX ke Home Screen";
    copy.textContent="Buka chat terus seperti app di telefon.";
    action.textContent="ADD";
    action.onclick=async()=>{
      const prompt=deferredInstallPrompt;
      if(!prompt)return;
      prompt.prompt();
      const choice=await prompt.userChoice.catch(()=>({outcome:"dismissed"}));
      deferredInstallPrompt=null;
      if(choice.outcome==="accepted")hidePwaNote();
    };
    note.hidden=false;
  }

  window.addEventListener("beforeinstallprompt",event=>{
    event.preventDefault();
    deferredInstallPrompt=event;
    refreshPwaNote();
  });
  window.addEventListener("appinstalled",()=>{deferredInstallPrompt=null;hidePwaNote();});
  window.addEventListener("focus",refreshPwaNote);
  document.addEventListener("visibilitychange",()=>{if(!document.hidden)refreshPwaNote();});

  function applyGnexTheme(theme){
    const light=theme==="light";
    const panel=$("#chat-center");
    panel?.classList.toggle("gnex-light-theme",light);
    document.body.classList.toggle("gnex-chat-light-active",light&&Boolean(panel?.classList.contains("is-open")));
  }

  window.toggleGnexTheme=function(){
    const next=$("#chat-center")?.classList.contains("gnex-light-theme")?"dark":"light";
    localStorage.setItem("gnex_theme",next);
    applyGnexTheme(next);
  };
  applyGnexTheme(localStorage.getItem("gnex_theme")||"dark");

  function setupPullToRefresh(){
    [$(".gc-home-scroll"),$("#gc-messages"),$("#gc-group-messages")].filter(Boolean).forEach(scroller=>{
      let startY=0,pulling=false;
      scroller.addEventListener("touchstart",event=>{if(scroller.scrollTop<=0){startY=event.touches[0].clientY;pulling=true;}},{passive:true});
      scroller.addEventListener("touchmove",event=>{
        if(!pulling)return;
        document.body.classList.toggle("gc-is-pulling",event.touches[0].clientY-startY>24);
      },{passive:true});
      scroller.addEventListener("touchend",async event=>{
        if(!pulling)return;
        pulling=false;
        const distance=event.changedTouches[0].clientY-startY;
        document.body.classList.remove("gc-is-pulling");
        if(distance<70)return;
        document.body.classList.add("gc-is-refreshing");
        try{
          if($("#gc-chat-view")?.classList.contains("is-active"))await loadMessages(true);
          else if(state.groupId)await loadGroupMessages(true);
          else await loadChatHome();
        }
        finally{setTimeout(()=>document.body.classList.remove("gc-is-refreshing"),350);}
      },{passive:true});
    });
  }
  window.addEventListener("DOMContentLoaded",setupPullToRefresh,{once:true});

  function esc(v){
    return String(v ?? "").replace(/[&<>"']/g, c => ({
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#039;"
    }[c]));
  }

  async function parseResponse(response){
    const raw = await response.text();
    let data;

    try{
      data = raw ? JSON.parse(raw) : {};
    }catch(e){
      console.error("API raw response:", raw);
      throw new Error(
        raw || `API response tidak sah (${response.status}).`
      );
    }

    if(data.csrf){
      state.csrf = data.csrf;
    }

    if(!response.ok || !data.ok){
      throw new Error(
        data.message || "Permintaan gagal."
      );
    }

    return data;
  }

  async function get(action, query = ""){
    const response = await fetch(
      `${api}?action=${encodeURIComponent(action)}${query}`,
      {
        credentials:"same-origin",
        cache:"no-store"
      }
    );

    return parseResponse(response);
  }

  async function post(action, payload = {}){
    if(!state.csrf){
      const st = await get("state");
      state.csrf = st.csrf || "";
    }

    const response = await fetch(
      `${api}?action=${encodeURIComponent(action)}`,
      {
        method:"POST",
        credentials:"same-origin",
        headers:{
          "Content-Type":"application/json"
        },
        body:JSON.stringify({
          ...payload,
          csrf:state.csrf
        })
      }
    );

    return parseResponse(response);
  }

  function pushKeyBytes(value){
    const padding="=".repeat((4-value.length%4)%4);
    const raw=atob((value+padding).replace(/-/g,"+").replace(/_/g,"/"));
    return Uint8Array.from([...raw].map(char=>char.charCodeAt(0)));
  }

  window.subscribeGnexUserPush=async function(){
    if(!("serviceWorker" in navigator)||!("PushManager" in window)||Notification.permission!=="granted")return false;
    const appState=await get("state");
    if(!appState.push_public_key)throw new Error("Kunci push server belum tersedia.");
    const registration=await navigator.serviceWorker.register("scrim-sw.js?v=16",{updateViaCache:"none"});
    let subscription=await registration.pushManager.getSubscription();
    if(subscription&&localStorage.getItem("gnex_push_vapid_key")!==appState.push_public_key){await subscription.unsubscribe();subscription=null;}
    if(!subscription)subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:pushKeyBytes(appState.push_public_key)});
    await post("subscribePush",subscription.toJSON());
    localStorage.setItem("gnex_push_vapid_key",appState.push_public_key);
    return true;
  };

  function updateAdminPresence(presence){
    const online=Boolean(presence?.online);
    let label="Admin offline";
    if(online) label="Admin online";
    else if(presence?.last_seen_at){
      const seen=new Date(String(presence.last_seen_at).replace(" ","T"));
      if(!Number.isNaN(seen.getTime())) label=`Last seen ${seen.toLocaleTimeString("ms-MY",{hour:"2-digit",minute:"2-digit"})}`;
    }
    document.querySelectorAll(".gc-admin-presence").forEach(element=>{
      element.classList.toggle("is-offline",!online);
      const text=element.querySelector("b");if(text)text.textContent=label;
    });
  }

  async function compressChatImage(file){
    if(!file?.type?.startsWith("image/") || file.size <= 1200 * 1024) return file;
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext("2d").drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    let quality = .78;
    let blob;
    do {
      blob = await new Promise(resolve => canvas.toBlob(resolve, "image/jpeg", quality));
      quality -= .12;
    } while(blob && blob.size > 4.5 * 1024 * 1024 && quality >= .3);
    if(!blob) throw new Error("Gambar tidak dapat diproses.");
    return new File([blob], `${file.name.replace(/\.[^.]+$/, "") || "gambar"}.jpg`, {type:"image/jpeg"});
  }

  async function uploadImage(file){
    file=await compressChatImage(file);
    if(!state.csrf){const st=await get("state");state.csrf=st.csrf||"";}
    const form=new FormData();form.append("csrf",state.csrf);form.append("image",file);
    const response=await fetch(`${api}?action=uploadImage`,{method:"POST",credentials:"same-origin",body:form});
    return parseResponse(response);
  }

  function formatClock(value){
    if(!value) return "";

    const d = new Date(
      String(value).replace(" ","T")
    );

    return Number.isNaN(d.getTime())
      ? ""
      : d.toLocaleTimeString("en-MY",{
          hour:"numeric",
          minute:"2-digit"
        });
  }

  function renderHome(items){
    const box = $("#gc-department-cards");

    if(!box) return;

    box.innerHTML = items.map(item => {
      const meta =
        departmentMeta[item.key] ||
        departmentMeta.topup;

      const preview =
        item.last_message ||
        meta.subtitle;

      return `
        <button
          type="button"
          class="gc-card"
          onclick="openDepartmentChat('${item.key}')"
        >
          <div class="gc-avatar ${meta.cls}">
            <img src="${meta.avatar}" alt="${esc(meta.title)}">
          </div>

          <div class="gc-card-copy">
            <strong>${esc(meta.title)}</strong>
            <p>${esc(preview)}</p>
          </div>

          <div class="gc-card-meta">
            <time>
              ${esc(formatClock(item.last_message_at))}
            </time>

            ${Number(item.unread_count||0)>0?`<span class="gc-badge" aria-label="${Number(item.unread_count)} mesej belum dibaca">${Number(item.unread_count)>99?"99+":Number(item.unread_count)}</span>`:""}

            <span class="gc-chevron">
              ›
            </span>
          </div>
        </button>
      `;
    }).join("");
  }

  async function loadChatHome(){
    try{
      const data = await get("chatHome");

      updateAdminPresence(data.admin_presence);
      updateGnexAppBadge(data.unread_count);

      renderHome(
        data.departments || []
      );
      checkGroupNotifications();
    }catch(error){
      console.error(error);
    }
  }

  window.refreshChatHome =
    loadChatHome;

  window.switchChatHomeTab =
    function(tab){

      $("#gc-community-channel")?.classList.remove("is-active");
      if(tab !== "group") closeGroupRoom();

      document.body.classList.toggle(
        "chat-focus-mode",
        false
      );

      document
        .querySelectorAll("[data-gc-tab]")
        .forEach(btn => {
          btn.classList.toggle(
            "is-active",
            btn.dataset.gcTab === tab
          );
        });

      $("#gc-admin-list")
        ?.classList.toggle(
          "is-active",
          tab === "admin"
        );

      $("#gc-community-list")
        ?.classList.toggle(
          "is-active",
          tab === "community"
        );

      $("#gc-group-list")
        ?.classList.toggle(
          "is-active",
          tab === "group"
        );
      if(tab === "group") loadGroups();
      if(tab === "community") loadCommunities();
    };

  window.openChatNavTab =
    function(tab){
      if(window.closeTopupProfile) closeTopupProfile(false);
      if(window.closeTopupRanking) closeTopupRanking(false);
      showView("home");
      switchChatHomeTab(tab === "chat" ? "admin" : tab);
      document.body.classList.toggle("community-list-open", tab === "community");

      document
        .querySelectorAll(".chat-bottom-nav [data-chat-nav]")
        .forEach(item => {
          item.classList.toggle("is-active", item.dataset.chatNav === tab);
        });
    };

  window.openChatProfile =
    function(){
      openTopupProfile();
      document
        .querySelectorAll(".chat-bottom-nav [data-chat-nav]")
        .forEach(item => {
          item.classList.toggle("is-active", item.dataset.chatNav === "profile");
        });
    };

  const communityNames = {ff:"FREE FIRE",ml:"MOBILE LEGENDS",pubg:"PUBG MOBILE"};
  const chatInitials=name=>String(name||"G").trim().split(/\s+/).slice(0,2).map(part=>part[0]||"").join("").toUpperCase();

  window.openCommunityChannel =
    async function(channel){
      const community=(window.__gnexCommunities||[]).find(item=>item.channel===channel);
      $("#gc-community-list")?.classList.remove("is-active");
      $("#gc-community-channel")?.classList.add("is-active");
      $("#gc-channel-title").textContent = community?.name || communityNames[channel] || "Komuniti";
      if($("#gc-community-badge")) $("#gc-community-badge").innerHTML = community?.image_url ? `<img src="${esc(community.image_url)}" alt="${esc(community.name)}">` : (channel === "pubg" ? "P" : channel.toUpperCase());
      $("#gc-community-channel").dataset.channel = channel;
      document.body.classList.add("community-room-open","chat-focus-mode");
      await loadCommunityChannel(channel);
    };

  window.closeCommunityChannel =
    function(){
      $("#gc-community-channel")?.classList.remove("is-active");
      $("#gc-community-list")?.classList.add("is-active");
      document.body.classList.remove("community-room-open","chat-focus-mode");
    };

  async function loadCommunityChannel(channel){
    const box = $("#gc-channel-posts");
    if(!box) return;
    box.innerHTML = '<div class="gc-channel-empty">Memuatkan update...</div>';
    try{
      const data = await get("communityPosts",`&channel=${encodeURIComponent(channel)}`);
      const communityAvatar=data.community?.image_url||"images/logo baru gnex .webp";box.innerHTML = (data.posts || []).slice().reverse().map(postItem => `
        <article class="gc-community-message">
          <img class="gc-community-avatar" src="${esc(communityAvatar)}" alt="${esc(data.community?.name||"GNEX")}">
          <div><header><strong>${esc(postItem.admin_name||"GNEX ADMIN")}</strong><time>${esc(formatClock(postItem.created_at))}</time></header>
          <section><p>${esc(postItem.body).replace(/\n/g,"<br>")}</p>
          ${postItem.media_url ? `<img class="gc-chat-image" src="${esc(postItem.media_url)}" alt="Gambar komuniti" loading="lazy">` : ""}</section>
          <div class="gc-reactions">
            <button type="button" class="${Number(postItem.liked) ? "is-active" : ""}" onclick="reactCommunityPost(${Number(postItem.id)},'👍')">👍 <span>${Number(postItem.likes || 0)}</span></button>
            <button type="button" class="${Number(postItem.hearted) ? "is-active" : ""}" onclick="reactCommunityPost(${Number(postItem.id)},'❤️')">❤️ <span>${Number(postItem.hearts || 0)}</span></button>
          </div></div>
        </article>`).join("") || '<div class="gc-channel-empty">Belum ada update daripada admin.</div>';
      box.scrollTop=box.scrollHeight;
    }catch(error){ box.innerHTML = `<div class="gc-channel-empty">${esc(error.message)}</div>`; }
  }

  async function loadCommunities(){const box=$("#gc-communities");if(!box)return;try{const data=await get("communities");window.__gnexCommunities=data.communities||[];box.innerHTML=window.__gnexCommunities.map(item=>`<button type="button" class="gc-card community" onclick="openCommunityChannel('${esc(item.channel)}')"><div class="gc-avatar">${item.image_url?`<img src="${esc(item.image_url)}" alt="${esc(item.name)}">`:esc(chatInitials(item.name))}</div><div class="gc-card-copy"><strong>${esc(item.name)}</strong><p>${esc(item.description||"Komuniti GNEX")} · ${Number(item.post_count||0)} update</p></div><span class="gc-chevron">›</span></button>`).join("")||'<div class="gc-channel-empty">Belum ada komuniti.</div>';}catch(error){box.innerHTML=`<div class="gc-channel-empty">${esc(error.message)}</div>`;}}

  window.reactCommunityPost =
    async function(postId,emoji){
      await post("reactCommunityPost",{post_id:postId,emoji});
      const channel = $("#gc-community-channel")?.dataset.channel || "ff";
      await loadCommunityChannel(channel);
    };

  async function loadGroups(){
    const box=$("#gc-groups");if(!box)return;
    try{const data=await get("groups");window.__gnexGroups=data.groups||[];box.innerHTML=window.__gnexGroups.map(g=>`<button type="button" class="gc-card" onclick="openGroupRoom(${Number(g.id)})"><div class="gc-avatar tour">${g.image_url?`<img src="${esc(g.image_url)}" alt="${esc(g.name)}">`:"G"}</div><div class="gc-card-copy"><strong>${esc(g.name)}</strong><p>${esc(g.description||"")} · ${Number(g.members||0)} ahli</p></div><span class="gc-chevron">›</span></button>`).join("")||'<div class="gc-channel-empty">Belum ada group.</div>';}catch(error){box.innerHTML=`<div class="gc-channel-empty">${esc(error.message)}</div>`;}
  }

  async function checkGroupNotifications(){
    try{
      const data=await get("groups");
      for(const group of data.groups||[]){
        const id=Number(group.last_message_id||0),old=Number(state.groupSeen[group.id]||0);state.groupSeen[group.id]=id;
        if(!old||id<=old||!Number(group.joined)||Number(group.muted)||state.groupId===Number(group.id))continue;
        if(localStorage.getItem("gnex_user_notifications")!=="on"||!("Notification" in window)||Notification.permission!=="granted")continue;
        navigator.serviceWorker?.ready.then(reg=>reg.showNotification(`Group · ${group.name}`,{body:group.last_message||"Gambar baharu",icon:"images/logo baru gnex .webp",tag:`group-${group.id}`,data:{url:"index.html?chat=guest"}})).catch(()=>{});
      }
    }catch(error){}
  }

  window.openGroupRoom=async function(id){const group=(window.__gnexGroups||[]).find(g=>Number(g.id)===Number(id));if(!group)return;if(!Number(group.joined)){if(!confirm(`Join group ${group.name}?`))return;await post("joinGroup",{group_id:id});group.joined=1;}state.groupId=Number(id);state.groupMuted=Boolean(Number(group.muted));$("#gc-group-title").textContent=group.name;const badge=$("#gc-group-room .gc-room-badge");if(badge)badge.innerHTML=group.image_url?`<img src="${esc(group.image_url)}" alt="${esc(group.name)}">`:"G";updateGroupMuteButton();$("#gc-group-list")?.classList.remove("is-active");$("#gc-group-room")?.classList.add("is-active");document.body.classList.add("group-room-open","chat-focus-mode");await loadGroupMessages(true);clearInterval(state.groupPoll);state.groupPoll=setInterval(()=>loadGroupMessages(false),3000);};
  window.closeGroupRoom=function(){clearInterval(state.groupPoll);state.groupId=0;$("#gc-group-room")?.classList.remove("is-active");$("#gc-group-list")?.classList.add("is-active");document.body.classList.remove("group-room-open","chat-focus-mode");};
  function updateGroupMuteButton(){const b=$("#gc-group-mute");if(b)b.textContent=state.groupMuted?"🔕":"🔔";}
  window.toggleCurrentGroupMute=async function(){if(!state.groupId)return;state.groupMuted=!state.groupMuted;await post("muteGroup",{group_id:state.groupId,muted:state.groupMuted});updateGroupMuteButton();};
  async function loadGroupMessages(reset){if(!state.groupId)return;const box=$("#gc-group-messages");const after=reset?0:Number(box?.dataset.last||0);const data=await get("groupMessages",`&group_id=${state.groupId}&after=${after}`);if(reset){box.innerHTML="";box.dataset.last="0";}for(const m of data.messages||[]){const mine=Boolean(Number(m.is_mine));const avatar=`<span class="gc-group-user-avatar">${esc(chatInitials(m.sender_name))}</span>`;const content=`<div><header><strong>${esc(m.sender_name)}</strong><time>${esc(formatClock(m.created_at))}</time></header><section>${m.body?`<p>${esc(m.body).replace(/\n/g,"<br>")}</p>`:""}${m.media_url?`<img class="gc-chat-image" src="${esc(m.media_url)}" alt="Gambar group" loading="lazy">`:""}</section></div>`;const row=document.createElement("article");row.className=`gc-group-message ${mine?"is-mine":""}`;row.innerHTML=mine?content+avatar:avatar+content;box.appendChild(row);box.dataset.last=String(m.id);}if((data.messages||[]).length)box.scrollTop=box.scrollHeight;}
  window.sendGroupMessage=async function(event){event.preventDefault();const input=$("#gc-group-input");const body=input.value.trim();if(!body)return;await post("sendGroupMessage",{group_id:state.groupId,body});input.value="";await loadGroupMessages(false);};
  window.sendGroupImage=async function(input){if(!input.files?.[0])return;try{const up=await uploadImage(input.files[0]);await post("sendGroupMessage",{group_id:state.groupId,media_url:up.url});await loadGroupMessages(false);}catch(error){alert(error.message);}input.value="";};
  window.sendDepartmentImage=async function(input){if(!input.files?.[0]||!state.department)return;try{const up=await uploadImage(input.files[0]);await post("sendMessage",{department:state.department,conversation_id:state.conversationId||0,body:"",media_url:up.url});await loadMessages(false);}catch(error){alert(error.message);}input.value="";};

  function showView(name){
    $("#gc-home-view")
      ?.classList.toggle(
        "is-active",
        name === "home"
      );

    $("#gc-chat-view")
      ?.classList.toggle(
        "is-active",
        name === "chat"
      );
  }

async function clearGnexBadge(){
  if("clearAppBadge" in navigator){
    try{
      await navigator.clearAppBadge();
    }catch(error){
      console.error(error);
    }
  }
}

async function updateGnexAppBadge(count){
  const total=Math.max(0,Number(count)||0);
  const previous=Math.max(0,Number(localStorage.getItem("gnex_user_badge_count"))||0);
  [$("#mainChatCount"),$("#insideChatCount")].filter(Boolean).forEach(badge=>{badge.textContent=total>99?"99+":String(total);badge.hidden=total===0;});
  try{
    if(total>0&&"setAppBadge" in navigator)await navigator.setAppBadge(total);
    else if(total===0&&"clearAppBadge" in navigator)await navigator.clearAppBadge();

    if("serviceWorker" in navigator&&localStorage.getItem("gnex_user_notifications")==="on"&&"Notification" in window&&Notification.permission==="granted"){
      const registration=await navigator.serviceWorker.ready;
      if(total>previous){
        await registration.showNotification("GNEX · Chat belum dibaca",{
          body:`Anda mempunyai ${total} mesej chat belum dibaca.`,
          icon:"images/gnex-home-192.png",
          badge:"images/gnex-home-192.png",
          tag:"gnex-user-unread",
          renotify:true,
          data:{url:"index.html?chat=guest"}
        });
      }else if(total===0){
        const notifications=await registration.getNotifications({tag:"gnex-user-unread"});
        notifications.forEach(notification=>notification.close());
      }
    }
  }catch(error){console.debug("App badge tidak disokong",error);}
  localStorage.setItem("gnex_user_badge_count",String(total));
}


window.openDepartmentChat = async function(department){

  // code lama sambung...
        const meta =
        departmentMeta[department];

      if(!meta) return;

      state.department =
        department;

      state.conversationId = 0;
      state.lastMessageId = 0;

      $("#gc-chat-title")
        .textContent =
        meta.title;

      const avatar =
        $("#gc-chat-avatar");

      if(avatar){
        avatar.className =
          `gc-avatar ${meta.cls}`;

        avatar.innerHTML =
          `<img src="${meta.avatar}" alt="${esc(meta.title)}">`;
      }

      showView("chat");
      document.body.classList.add("chat-focus-mode");

      await loadMessages(true);

      clearInterval(
        state.pollTimer
      );

      state.pollTimer =
        setInterval(() => {

          if(
            $("#gc-chat-view")
              ?.classList
              .contains("is-active")
          ){
            loadMessages(false);
          }

        },3000);
    };




  window.backToChatHome =
    function(){

      clearInterval(
        state.pollTimer
      );

      state.pollTimer = null;
      state.department = "";
      state.conversationId = 0;
      state.lastMessageId = 0;

      showView("home");
      document.body.classList.remove("chat-focus-mode");

      loadChatHome();

      setTimeout(refreshPwaNote,180);
    };

  function renderMessages(
    items,
    reset
  ){
    const box =
      $("#gc-messages");

    if(!box) return;

    if(reset){
      box.innerHTML =
        '<div class="gc-date">HARI INI</div>';

      state.lastMessageId = 0;
    }

    items.forEach(message => {
      const id =
        Number(message.id);

      const orderStatus=message.message_kind==="order_status"?String(message.order_status||""):"";
      const statusHtml=orderStatus==="processing"
        ? '<span class="gc-order-spinner" aria-hidden="true"></span>Order sedang diproses'
        : '<span class="gc-order-check" aria-hidden="true">✓</span>Order complete';
      const existingRow=box.querySelector(`[data-message-id="${id}"]`);
      if(existingRow&&orderStatus){
        const bubble=existingRow.querySelector(".gc-bubble");
        if(bubble){
          bubble.className=`gc-bubble gc-order-status ${orderStatus}`;
          bubble.innerHTML=statusHtml;
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

      if(!reset && isAdmin && localStorage.getItem("gnex_user_notifications") === "on" && "Notification" in window && Notification.permission === "granted"){
        navigator.serviceWorker?.ready
          .then(registration => registration.showNotification("Mesej baharu daripada GNEX", {
            body:message.body || "Admin membalas chat anda.",
            icon:"images/logo baru gnex .webp",
            badge:"images/logo baru gnex .webp",
            tag:`user-chat-${id}`,
            data:{url:"index.html#chat-center"}
          }))
          .catch(()=>{});
      }

      const row =
        document.createElement("div");

      row.className =
        `gc-row ${
          isAdmin
            ? "is-admin"
            : "is-user"
        }`;
      row.dataset.messageId=String(id);
      row.dataset.messageBody=message.body||"Gambar";

      const time =
        formatClock(
          message.created_at
        );

      row.innerHTML = `
        ${
          isAdmin
            ? `
              <img
                src="images/logo baru gnex .webp"
                class="gc-msg-avatar"
                alt=""
              >
            `
            : ""
        }

        <div>
          ${message.reply_to_message_id ? `<div class="gc-reply-quote"><strong>${["admin","system"].includes(message.reply_sender_type)?"GNEX Admin":"Customer"}</strong>${esc(message.reply_body||"Mesej")}</div>` : ""}
          ${message.body ? (orderStatus ? `<div class="gc-bubble gc-order-status ${orderStatus}">${statusHtml}</div>` : `<div class="gc-bubble">${esc(message.body)}</div>`) : ""}
          ${message.media_url ? `<img class="gc-chat-image" src="${esc(message.media_url)}" alt="Gambar chat" loading="lazy">` : ""}

          <time>
            ${esc(time)}
          </time>
        </div>
      `;

      box.appendChild(row);

      state.lastMessageId =
        Math.max(
          state.lastMessageId,
          id
        );
    });

    if(items.length || reset){
      box.scrollTop =
        box.scrollHeight;
    }
  }

  async function loadMessages(
    reset = false
  ){
    if(!state.department){
      return;
    }

    try{
      const after =
        reset
          ? 0
          : state.lastMessageId;

      const data =
        await get(
          "messages",
          `&department=${encodeURIComponent(
            state.department
          )}&after=${after}`
        );

      state.conversationId =
        Number(
          data.conversation_id ||
          state.conversationId ||
          0
        );

      updateAdminPresence(data.admin_presence);
      updateGnexAppBadge(data.unread_count);

      renderMessages(
        data.messages || [],
        reset
      );

    }catch(error){
      console.error(error);
    }
  }

  window.refreshActiveChat =
    () => loadMessages(true);

  window.cancelDepartmentReply=function(){state.replyTo=null;const preview=$("#gc-reply-preview");if(preview)preview.hidden=true;};
  function chooseDepartmentReply(row){
    const id=Number(row?.dataset.messageId||0);if(!id)return;
    state.replyTo={id,body:row.dataset.messageBody||"Mesej"};
    $("#gc-reply-copy").textContent=state.replyTo.body;
    $("#gc-reply-preview").hidden=false;
    $("#gc-input")?.focus({preventScroll:true});
  }

  let gcSwipe=null;
  $("#gc-messages")?.addEventListener("touchstart",event=>{const row=event.target.closest(".gc-row[data-message-id]");if(!row)return;const touch=event.touches[0];gcSwipe={row,x:touch.clientX,y:touch.clientY,dx:0};},{passive:true});
  $("#gc-messages")?.addEventListener("touchmove",event=>{if(!gcSwipe)return;const touch=event.touches[0],dx=touch.clientX-gcSwipe.x,dy=touch.clientY-gcSwipe.y;if(Math.abs(dy)>Math.abs(dx)+10)return;gcSwipe.dx=Math.max(-75,Math.min(75,dx));gcSwipe.row.classList.add("is-swiping");gcSwipe.row.classList.toggle("is-swipe-left",gcSwipe.dx<0);gcSwipe.row.style.transform=`translateX(${gcSwipe.dx}px)`;},{passive:true});
  $("#gc-messages")?.addEventListener("touchend",()=>{if(!gcSwipe)return;const {row,dx}=gcSwipe;row.style.transform="";row.classList.remove("is-swiping","is-swipe-left");if(Math.abs(dx)>=48)chooseDepartmentReply(row);gcSwipe=null;});
  $("#gc-messages")?.addEventListener("mousedown",event=>{if(event.button!==0)return;const row=event.target.closest(".gc-row[data-message-id]");if(row)gcSwipe={row,x:event.clientX,y:event.clientY,dx:0};});
  window.addEventListener("mousemove",event=>{if(!gcSwipe||event.buttons!==1)return;const dx=event.clientX-gcSwipe.x,dy=event.clientY-gcSwipe.y;if(Math.abs(dy)>Math.abs(dx)+10)return;gcSwipe.dx=Math.max(-75,Math.min(75,dx));gcSwipe.row.classList.add("is-swiping");gcSwipe.row.classList.toggle("is-swipe-left",gcSwipe.dx<0);gcSwipe.row.style.transform=`translateX(${gcSwipe.dx}px)`;});
  window.addEventListener("mouseup",()=>{if(!gcSwipe)return;const {row,dx}=gcSwipe;row.style.transform="";row.classList.remove("is-swiping","is-swipe-left");if(Math.abs(dx)>=48)chooseDepartmentReply(row);gcSwipe=null;});

  window.sendDepartmentMessage =
    async function(event){

      event.preventDefault();

      const input =
        $("#gc-input");

      const body =
        input?.value.trim() || "";

      if(
        !body ||
        !state.department || state.sending
      ) return;

      state.sending=true;
      const submit=event.currentTarget.querySelector('[type="submit"]');if(submit)submit.disabled=true;
      const replyTo=state.replyTo;
      const box=$("#gc-messages"),temp=document.createElement("div");
      temp.className="gc-row is-user is-sending";temp.innerHTML=`<div>${replyTo?`<div class="gc-reply-quote"><strong>Reply mesej</strong>${esc(replyTo.body)}</div>`:""}<div class="gc-bubble">${esc(body)}</div><time>${esc(formatClock(new Date().toISOString()))}</time></div>`;box?.appendChild(temp);if(box)box.scrollTop=box.scrollHeight;
      input.value="";cancelDepartmentReply();

      try{
        await post(
          "sendMessage",
          {
            department:
              state.department,

            conversation_id:
              state.conversationId || 0,

            body,
            reply_to_message_id:replyTo?.id||0
          }
        );

        temp.remove();

        input.focus({
          preventScroll:true
        });

        await loadMessages(false);

      }catch(error){
        temp.remove();if(!input.value)input.value=body;

        input.focus({
          preventScroll:true
        });

        alert(
          error.message
        );
      }finally{
        state.sending=false;if(submit)submit.disabled=false;
      }
    };

  window.openChatCenter =
    function(){

      const panel =
        $("#chat-center");

      if(!panel) return;

      panel.classList.add(
        "is-open"
      );

      panel.setAttribute(
        "aria-hidden",
        "false"
      );

      applyGnexTheme(localStorage.getItem("gnex_theme")||"dark");

      document.body.classList.add(
        "modal-open",
        "topup-chat-open"
      );

      if(window.setBottomNavActive){
        window.setBottomNavActive("chat");
      }

      openChatNavTab("chat");

      refreshPwaNote();

      loadChatHome();

      clearInterval(
        state.homeTimer
      );

      state.homeTimer =
        setInterval(() => {

          if(
            $("#gc-home-view")
              ?.classList
              .contains("is-active")
          ){
            loadChatHome();
          }

        },7000);
    };

  window.closeChatCenter =
    function(){

      clearInterval(
        state.pollTimer
      );

      clearInterval(
        state.homeTimer
      );

      state.pollTimer = null;
      state.homeTimer = null;

      const panel =
        $("#chat-center");

      if(panel){
        panel.classList.remove(
          "is-open"
        );

        panel.setAttribute(
          "aria-hidden",
          "true"
        );
      }

      document.body.classList.remove(
        "modal-open",
        "topup-chat-open",
        "chat-focus-mode",
        "gnex-chat-light-active",
        "group-room-open"
        ,"community-room-open"
        ,"community-list-open"
      );

      state.department = "";
      state.lastMessageId = 0;
      state.conversationId = 0;
    };





  function updateKeyboard(){
    if(
      !window.visualViewport
    ) return;

    const vv =
      window.visualViewport;

    const height =
      Math.max(
        0,
        window.innerHeight -
        vv.height -
        vv.offsetTop
      );

    document.documentElement
      .style
      .setProperty(
        "--keyboard-height",
        `${height}px`
      );

    document.body
      .classList
      .toggle(
        "keyboard-open",
        height > 100
      );

    const composer =
      $("#gc-composer");

    if(
      composer &&
      document.body.classList
        .contains(
          "topup-chat-open"
        )
    ){
      composer.style.transform =
        height > 100
          ? `translateY(-${height}px)`
          : "";
    }
  }

  if(
    window.visualViewport
  ){
    window.visualViewport
      .addEventListener(
        "resize",
        updateKeyboard
      );

    window.visualViewport
      .addEventListener(
        "scroll",
        updateKeyboard
      );
  }

  setInterval(checkGroupNotifications,10000);
  loadChatHome();
  setInterval(()=>{
    if(!$("#chat-center")?.classList.contains("is-open"))loadChatHome();
  },10000);
})();



