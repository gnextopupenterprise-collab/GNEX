<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('session.gc_maxlifetime', (string) (365 * 86400));
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 365 * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['cl_admin_id']) && !empty($_COOKIE['clash_league_remember']) && empty($_GET['resume'])) {
    header('Location: ../api/clash-league.php?action=resumeOrderRecord');
    exit;
}
if (empty($_SESSION['cl_admin_id'])) {
    ob_start(static function (string $loginHtml): string {
        $loginHtml = str_replace('width=device-width,initial-scale=1,viewport-fit=cover', 'width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover', $loginHtml);
        $loginHtml = str_replace('.login input{', 'html{-webkit-text-size-adjust:100%}.login input{font-size:16px!important;', $loginHtml);
        $loginHtml = str_replace('location.reload()', 'document.activeElement?.blur();window.scrollTo(0,0);setTimeout(()=>location.replace(location.pathname),80)', $loginHtml);
        return $loginHtml;
    });
    echo '<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f7fafc"><title>Order Record Login</title><style>*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:22px;background:#eef3f8;color:#17233c;font-family:Arial,sans-serif}.login{width:min(420px,100%);padding:26px;border:1px solid #d9e2ed;border-radius:24px;background:#fff;box-shadow:0 18px 55px #70819822}.mark{display:grid;place-items:center;width:54px;height:54px;border-radius:18px;background:#e7f0ff;color:#2563eb;font-size:24px;font-weight:900}.login h1{margin:18px 0 5px;font-size:25px}.login p{margin:0 0 22px;color:#718096;font-size:13px}.login label{display:grid;gap:7px;color:#66758a;font-size:10px;font-weight:900;text-transform:uppercase}.login input{width:100%;height:50px;border:1px solid #ccd8e6;border-radius:14px;background:#f8fafc;padding:0 14px;font:inherit;outline:none}.login input:focus{border-color:#60a5fa;box-shadow:0 0 0 3px #dbeafe}.login button{width:100%;height:50px;margin-top:12px;border:0;border-radius:14px;background:#2563eb;color:#fff;font-weight:1000}.status{min-height:18px;margin-top:12px!important;color:#dd3f50!important}</style></head><body><form class="login"><div class="mark">OR</div><h1>Order Record</h1><p>Masukkan password untuk teruskan.</p><label>Password<input name="secret" type="password" autocomplete="current-password" required autofocus></label><button>LOGIN</button><p class="status"></p></form><script>document.querySelector("form").onsubmit=async e=>{e.preventDefault();const b=e.currentTarget.querySelector("button"),s=e.currentTarget.querySelector(".status"),d=new FormData();d.set("action","orderRecordLogin");d.set("secret",e.currentTarget.secret.value);b.disabled=true;b.textContent="LOGGING IN...";s.textContent="";try{const r=await fetch("../api/clash-league.php",{method:"POST",body:d});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||"Login gagal");location.reload()}catch(x){s.textContent=x.message;b.disabled=false;b.textContent="LOGIN"}}</script></body></html>';
    exit;
}
$html = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record.html');
$buildFiles = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record.html',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-sheet.css',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-stock.css',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-money.css',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-accounts.css',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-sheet.js',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-balances.js',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-stock.js',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-sim.js',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-money.js',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-todo.css',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-todo.js',
    __FILE__,
];
$buildTime = 0;
foreach ($buildFiles as $buildFile) {
    if (is_file($buildFile)) $buildTime = max($buildTime, (int) filemtime($buildFile));
}
$buildId = 'or-' . $buildTime;
$html = str_replace('</title>', '</title><meta name="order-record-build" content="' . htmlspecialchars($buildId, ENT_QUOTES, 'UTF-8') . '">', $html);
$html = str_replace('order-record.webmanifest?v=20260810-1', 'order-record.webmanifest?v=20260813-2', $html);
$serviceWorkerRefresh = '<script>if("serviceWorker" in navigator){window.addEventListener("pageshow",function(){navigator.serviceWorker.getRegistration("/").then(function(r){if(!r)return;r.update().catch(function(){});if(r.waiting)r.waiting.postMessage({type:"CLASH_SKIP_WAITING"})}).catch(function(){})})}</script>';
$html = str_replace('</body>', $serviceWorkerRefresh . '</body>', $html);
$html = str_replace('width=device-width,initial-scale=1,viewport-fit=cover', 'width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover', $html);
$html = str_replace('</head>', '<style id="mobile-no-input-zoom">html{-webkit-text-size-adjust:100%}@media(max-width:900px){input,textarea,select{font-size:16px!important}}</style></head>', $html);
$fixedNavigationCss = '<style id="fixed-order-navigation">html,body{width:100%;height:100%;overflow:hidden!important;overscroll-behavior:none}body{position:fixed!important;inset:0}.app{height:100dvh!important;max-height:100dvh!important;min-height:0!important;overflow:hidden!important;grid-template-rows:auto minmax(0,1fr) auto!important}.top,.bottom{position:relative!important;z-index:50!important;flex:0 0 auto!important}.view{min-width:0!important;min-height:0!important;overflow-x:hidden!important;overflow-y:auto!important;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}[data-view="chat"].active{overflow:hidden!important}.chat-log{min-height:0!important;padding-bottom:8px!important;scroll-padding-bottom:8px!important;overflow-anchor:none!important;scroll-behavior:auto!important}.chat-log .bubble{overflow-anchor:none!important}.composer{position:relative!important;z-index:60!important;flex:0 0 auto!important;box-shadow:0 -5px 14px #17233c12}body.chat-keyboard-open .app{height:var(--chat-visible-height,100dvh)!important;max-height:var(--chat-visible-height,100dvh)!important;grid-template-rows:auto minmax(0,1fr)!important}body.chat-keyboard-open .bottom{display:none!important}body.chat-keyboard-open [data-view="chat"].active{overflow:hidden!important}body.chat-keyboard-open .chat-log{min-height:0!important;padding-bottom:8px!important;scroll-padding-bottom:8px!important;overflow-anchor:none!important}body.chat-keyboard-open .composer{position:relative!important;z-index:60!important;flex:0 0 auto!important}@media(min-width:651px){.sim-row{grid-template-columns:minmax(160px,1.65fr) 102px 67px 67px 77px 88px 88px 58px!important;padding-right:18px!important}.sim-row-edit{width:100%!important;margin:0!important;white-space:nowrap}}</style>';
$html = str_replace('</head>', $fixedNavigationCss . '</head>', $html);
$appRefreshCss = '<style id="order-app-refresh-style">.app-refresh{height:29px;padding:0 9px;border:1px solid #b9cbe0;border-radius:999px;background:#f3f7fc;color:#405978;font-size:8px;font-weight:1000;white-space:nowrap;cursor:pointer}.app-refresh:disabled{opacity:.6}.app-refresh.update-ready{border-color:#efb13f;background:#fff5df;color:#9a5c00;animation:refresh-pulse 1.2s ease-in-out infinite alternate}@keyframes refresh-pulse{to{box-shadow:0 0 0 4px #f7c96933}}@media(max-width:430px){.app-refresh{width:31px;padding:0;font-size:0}.app-refresh:before{content:"↻";font-size:16px}.app-refresh.update-ready:before{content:"!"}}</style>';
$html = str_replace('</head>', $appRefreshCss . '</head>', $html);
$html = str_replace('<button class="logout" type="button" data-logout>', '<button class="app-refresh" type="button" data-app-refresh aria-label="Reload app">↻ RELOAD</button><button class="logout" type="button" data-logout>', $html);
$isStockWorker = (string) ($_SESSION['cl_admin_access_scope'] ?? '') === 'stock';
$isAllocationUser = (string) ($_SESSION['cl_admin_access_scope'] ?? '') === 'allocation';
if ($isStockWorker) {
    $workerCss = '<style id="stock-worker-access">body.stock-worker [data-nav="all"],body.stock-worker [data-nav="daily"],body.stock-worker [data-nav="stock"],body.stock-worker [data-nav="money"]{display:none!important}body.stock-worker [data-balance-form]{display:none!important}body.stock-worker .bottom{grid-template-columns:repeat(2,minmax(0,1fr))!important;width:100%!important;max-width:none!important;margin:0!important;border-left:0!important;border-right:0!important;border-radius:0!important}body.stock-worker .app{height:100dvh;overflow:hidden}body.stock-worker [data-view="sim"].active{min-height:0!important;overflow-y:auto!important;-webkit-overflow-scrolling:touch;padding-bottom:16px!important}body.stock-worker .sim-list{overflow:visible!important;max-height:none!important}</style>';
    $html = str_replace('</head>', $workerCss . '</head>', $html);
    $html = str_replace('<body>', '<body class="stock-worker">', $html);
}
$html = str_replace('<body>', '<body class="' . ($isAllocationUser ? 'allocation-user' : 'no-allocation') . '">', $html);
$html = str_replace('<head>', '<head><base href="../"><link rel="stylesheet" href="order-record-sheet.css?v=20260811-1"><link rel="stylesheet" href="order-record-stock.css?v=20260813-4"><link rel="stylesheet" href="order-record-money.css?v=20260811-2"><link rel="stylesheet" href="order-record-accounts.css?v=20260813-4"><link rel="stylesheet" href="order-record-todo.css?v=20260814-20">', $html);
$appRefreshScript = '<script>(()=>{const current=document.querySelector("meta[name=order-record-build]")?.content||"";const button=document.querySelector("[data-app-refresh]");let refreshing=false;async function reloadApp(){if(refreshing)return;refreshing=true;if(button){button.disabled=true;button.textContent="..."}try{if("serviceWorker" in navigator){const registrations=await navigator.serviceWorker.getRegistrations();await Promise.all(registrations.map(async registration=>{await registration.update().catch(()=>{});if(registration.waiting)registration.waiting.postMessage({type:"CLASH_SKIP_WAITING"})}))}}catch(_){}const url=new URL(location.href);url.searchParams.set("app_refresh",Date.now());location.replace(url.href)}if(button)button.addEventListener("click",reloadApp);async function checkBuild(){if(refreshing||document.visibilityState!=="visible")return;try{const url=new URL(location.href);url.searchParams.set("version_check",Date.now());const response=await fetch(url.href,{cache:"no-store",credentials:"same-origin",headers:{"X-Order-Version-Check":"1"}});if(!response.ok)return;const html=await response.text();const match=html.match(/<meta name="order-record-build" content="([^"]+)"/i);if(!match||match[1]===current)return;const input=document.querySelector("[data-form] textarea");if(input&&(document.activeElement===input||input.value.trim())){if(button){button.classList.add("update-ready");button.textContent="UPDATE"}return}reloadApp()}catch(_){}}setTimeout(checkBuild,5000);setInterval(checkBuild,30000);document.addEventListener("visibilitychange",()=>{if(document.visibilityState==="visible")checkBuild()})})();</script>';
$html = str_replace('</body>', '<script src="order-record-sheet.js?v=20260813-3"></script><script src="order-record-balances.js?v=20260810-2"></script><script src="order-record-stock.js?v=20260813-9"></script><script src="order-record-sim.js?v=20260812-4"></script><script src="order-record-money.js?v=20260813-2"></script><script src="order-record-todo.js?v=20260814-24"></script>' . $appRefreshScript . '</body>', $html);
echo $html;
