(function(){
  registerGnexAssetCache();

  const page = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();

  if (document.querySelector(".bottom-app-nav")) {
    return;
  }

  const cssHref = "mobile-nav.css?v=5";
  const hasCss = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .some((link) => link.getAttribute("href") === cssHref);

  if (!hasCss) {
    const css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = cssHref;
    document.head.appendChild(css);
  }

  const topupPages = new Set(["freefire.html", "mlbb.html", "pubg.html"]);
  const tournamentPages = new Set([
    "tournament.html",
    "gtmls7.html",
    "kecs5.html",
    "gnexlaga.html",
    "gml.html",
    "warzone.html",
    "scrim.html",
    "scrim-mlbb.php"
  ]);
  const isScrimPage = page === "scrim.html" || page === "scrim-mlbb.php";

  const activeNav = topupPages.has(page)
    ? "topup"
    : tournamentPages.has(page)
      ? "tournament"
      : "";

  function activeClass(nav){
    return activeNav === nav ? " is-active" : "";
  }

  const nav = document.createElement("nav");
  nav.className = "bottom-app-nav";
  nav.setAttribute("aria-label", "Mobile app navigation");
  nav.innerHTML = `
${isScrimPage ? '' : `
<a href="https://gnexcenter.com/" class="bottom-nav-item${activeClass("home")}" data-nav="home" aria-label="Home">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="m3 10.8 9-7 9 7"></path>
<path d="M5 10v10h14V10"></path>
<path d="M9 20v-6h6v6"></path>
</svg>
<span>Home</span>
</a>
`}

<a href="${isScrimPage ? '#deal' : 'https://gnexcenter.com/#price-list'}" class="bottom-nav-item${activeClass("topup")}" data-nav="${isScrimPage ? 'deal' : 'topup'}" aria-label="${isScrimPage ? 'Deal Chat' : 'GNEX Topup'}">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M4 5.5h16v10.5H7.5L4 19.5V5.5Z"></path>
<path d="M8 9h8"></path>
<path d="M8 12.5h5"></path>
</svg>
${isScrimPage ? '<strong class="bottom-nav-badge hidden" id="dealNavBadge">0</strong>' : ''}
<span>${isScrimPage ? 'Deal' : 'Topup'}</span>
</a>

<a href="${isScrimPage ? '#scrim-home' : 'https://gnexcenter.com/tournament.html'}" class="bottom-nav-item${activeClass("tournament")}" data-nav="${isScrimPage ? 'scrim-home' : 'tournament'}" aria-label="${isScrimPage ? 'Scrim Home' : 'Tournament'}">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M8 21h8"></path>
<path d="M12 17v4"></path>
<path d="M7 4h10v5a5 5 0 0 1-10 0V4Z"></path>
<path d="M17 6h3v2a4 4 0 0 1-4 4"></path>
<path d="M7 6H4v2a4 4 0 0 0 4 4"></path>
</svg>
<span>${isScrimPage ? 'Home' : 'Tour'}</span>
</a>

<a href="${isScrimPage ? '#all-scrim' : 'https://wa.me/601115421017'}" ${isScrimPage ? '' : 'target="_blank" rel="noopener"'} class="bottom-nav-item" data-nav="${isScrimPage ? 'all-scrim' : 'support'}" aria-label="${isScrimPage ? 'All Scrim' : 'Support'}">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M5 6h14"></path>
<path d="M5 12h14"></path>
<path d="M5 18h14"></path>
<path d="M8 3v18"></path>
</svg>
<span>${isScrimPage ? 'All Scrim' : 'Support'}</span>
</a>

${isScrimPage ? `
<a href="#support" class="bottom-nav-item guest-support-nav" data-nav="support" aria-label="Support">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5h16v11H8l-4 3v-14Z"></path><path d="M8 9h8"></path><path d="M8 13h5"></path></svg>
<span>Support</span>
</a>
<a href="#review" class="bottom-nav-item" data-nav="review" aria-label="Request Review">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M8 6h13"></path>
<path d="M8 12h13"></path>
<path d="M8 18h13"></path>
<path d="M3 6h.01"></path>
<path d="M3 12h.01"></path>
<path d="M3 18h.01"></path>
</svg>
<strong class="bottom-nav-badge hidden" id="reviewNavBadge">0</strong>
<span>Review</span>
</a>
` : ''}
`;

  document.body.classList.add("has-bottom-app-nav");
  document.body.appendChild(nav);

  function registerGnexAssetCache(){
    if (!("serviceWorker" in navigator)) return;
    if (!window.isSecureContext && !["localhost", "127.0.0.1"].includes(window.location.hostname)) return;

    window.addEventListener("load", () => {
      navigator.serviceWorker.register("scrim-sw.js?v=16",{updateViaCache:"none"})
        .then((registration) => {
          if (registration.waiting) {
            registration.waiting.postMessage("GNEX_SKIP_WAITING");
          }
          warmGnexAssetCache(registration);
        })
        .catch(() => {});
    });
  }

  function warmGnexAssetCache(registration){
    navigator.serviceWorker.ready
      .then((readyRegistration) => {
        const worker = readyRegistration.active || registration.active || registration.waiting;
        if (!worker) return;

        const urls = collectGnexImageUrls();
        if (urls.length) {
          worker.postMessage({type:"GNEX_WARM_ASSETS", urls});
        }
      })
      .catch(() => {});
  }

  function collectGnexImageUrls(){
    const urls = new Set();

    document.querySelectorAll("img").forEach((image) => {
      if (image.currentSrc) urls.add(image.currentSrc);
      if (image.src) urls.add(image.src);
    });

    document.querySelectorAll("link[rel~='icon'], link[rel='apple-touch-icon']").forEach((link) => {
      if (link.href) urls.add(link.href);
    });

    document.querySelectorAll("*").forEach((element) => {
      const background = window.getComputedStyle(element).backgroundImage;
      if (!background || background === "none") return;

      background.replace(/url\(["']?([^"')]+)["']?\)/g, (match, url) => {
        urls.add(new URL(url, window.location.href).href);
        return match;
      });
    });

    return Array.from(urls);
  }
})();
