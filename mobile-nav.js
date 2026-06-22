(function(){
  const page = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();

  if (document.querySelector(".bottom-app-nav")) {
    return;
  }

  const cssHref = "mobile-nav.css?v=2";
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
<a href="https://gnexcenter.com/" class="bottom-nav-item${activeClass("home")}" data-nav="home" aria-label="Home">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="m3 10.8 9-7 9 7"></path>
<path d="M5 10v10h14V10"></path>
<path d="M9 20v-6h6v6"></path>
</svg>
<span>Home</span>
</a>

<a href="https://gnexcenter.com/#price-list" class="bottom-nav-item${activeClass("topup")}" data-nav="topup" aria-label="GNEX Topup">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<rect x="4" y="5" width="16" height="14" rx="3"></rect>
<path d="M8 9h8"></path>
<path d="M8 13h5"></path>
<path d="M16 16h.01"></path>
</svg>
<span>Topup</span>
</a>

<a href="https://gnexcenter.com/tournament.html" class="bottom-nav-item${activeClass("tournament")}" data-nav="tournament" aria-label="Tournament">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M8 21h8"></path>
<path d="M12 17v4"></path>
<path d="M7 4h10v5a5 5 0 0 1-10 0V4Z"></path>
<path d="M17 6h3v2a4 4 0 0 1-4 4"></path>
<path d="M7 6H4v2a4 4 0 0 0 4 4"></path>
</svg>
<span>Tour</span>
</a>

<a href="https://wa.me/601115421017" target="_blank" rel="noopener" class="bottom-nav-item" data-nav="support" aria-label="Support">
<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
<path d="M4 12a8 8 0 0 1 16 0"></path>
<path d="M4 12v4a2 2 0 0 0 2 2h1v-6H4Z"></path>
<path d="M20 12v4a2 2 0 0 1-2 2h-1v-6h3Z"></path>
<path d="M9 20h3"></path>
</svg>
<span>Support</span>
</a>
`;

  document.body.classList.add("has-bottom-app-nav");
  document.body.appendChild(nav);
})();
