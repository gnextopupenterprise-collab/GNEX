<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'index.html');

function gnex_fragment(string $source, string $startMarker, string $endMarker): string
{
    $start = strpos($source, $startMarker);
    $end = $start === false ? false : strpos($source, $endMarker, $start);
    if ($start === false || $end === false) return '';
    return substr($source, $start, $end - $start);
}

$navbar = gnex_fragment($source, '<!-- NAVBAR -->', '<!-- HERO -->');
$store = gnex_fragment($source, '<!-- PRICE LIST -->', '<section id="chat-center"');
$store = str_replace(
    '<section id="price-list" class="modal-view" aria-hidden="true">',
    '<main id="price-list" class="topup-store-standalone">',
    $store
);
$store = str_replace('</section>', '</main>', $store);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en" class="performance-mode">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="GNEX game topup and jersey price list.">
<link rel="icon" type="image/png" sizes="192x192" href="images/gnex-main-white-192.png?v=20260821-1">
<meta name="theme-color" content="#02040a">
<title>GNEX Topup Store</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="index.css?v=19">
</head>
<body class="min-h-screen bg-black text-white overflow-x-hidden">
<?= $navbar ?>
<?= $store ?>
<script>
function goHome(){ location.href='index.html'; }
function openPriceListPanel(){ scrollTo({top:0,behavior:'smooth'}); }
function closePriceListPanel(){ location.href='index.html'; }

let scrollTimer=0;
const storeScroller=document.scrollingElement;
const handleStoreScroll=()=>{
  document.body.classList.add('is-performance-scrolling');
  clearTimeout(scrollTimer);
  scrollTimer=setTimeout(()=>document.body.classList.remove('is-performance-scrolling'),600);
};
addEventListener('wheel',handleStoreScroll,{passive:true});
addEventListener('touchmove',handleStoreScroll,{passive:true});
addEventListener('scroll',handleStoreScroll,{passive:true});
</script>
</body>
</html>
