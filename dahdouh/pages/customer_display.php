<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
if (!isLoggedIn()) { header('Location: /dahdouh/login.php'); exit; }
$rate      = EXCHANGE_RATE;
$storeName = STORE_NAME;
$logoExists = file_exists(__DIR__ . '/../assets/img/logo.png');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($storeName) ?> — Customer Display</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: #0d1117;
    color: #e6edf3;
    font-family: 'Segoe UI', sans-serif;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
#store-header {
    position: absolute;
    top: 0; left: 0; right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 18px 24px;
    background: rgba(255,255,255,.04);
    border-bottom: 1px solid rgba(255,255,255,.08);
}
#store-header img { height: 48px; width: 48px; object-fit: contain; border-radius: 50%; }
#store-name { font-size: 1.3rem; font-weight: 700; letter-spacing: .5px; }
#main {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 100px 40px 40px;
    gap: 24px;
}
#welcome-msg {
    font-size: 2rem;
    color: rgba(255,255,255,.35);
    font-weight: 300;
    letter-spacing: 2px;
    text-align: center;
}
#items-wrap {
    width: 100%;
    max-width: 760px;
    display: none;
}
#items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 1.15rem;
}
#items-table th {
    color: rgba(255,255,255,.45);
    font-size: .8rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 6px 10px;
    border-bottom: 1px solid rgba(255,255,255,.1);
    text-align: left;
}
#items-table td {
    padding: 8px 10px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    color: #c9d1d9;
}
#items-table td:last-child { text-align: right; color: #58a6ff; font-weight: 600; }
#items-table tbody tr:last-child td { border-bottom: none; }
#totals-wrap {
    width: 100%;
    max-width: 760px;
    display: none;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}
#total-usd {
    font-size: 5rem;
    font-weight: 800;
    color: #3fb950;
    letter-spacing: -2px;
    line-height: 1;
}
#total-lbp {
    font-size: 1.6rem;
    color: rgba(255,255,255,.5);
    font-weight: 400;
}
#thank-msg {
    position: absolute;
    bottom: 28px;
    font-size: 1rem;
    color: rgba(255,255,255,.25);
    letter-spacing: 1px;
}
</style>
</head>
<body>
<div id="store-header">
    <?php if ($logoExists): ?>
    <img src="/dahdouh/assets/img/logo.png" alt="">
    <?php endif; ?>
    <span id="store-name"><?= htmlspecialchars($storeName) ?></span>
</div>

<div id="main">
    <div id="welcome-msg">Welcome!</div>

    <div id="items-wrap">
        <table id="items-table">
            <thead><tr><th>Item</th><th style="text-align:right">Qty</th><th style="text-align:right">Price</th></tr></thead>
            <tbody id="items-body"></tbody>
        </table>
    </div>

    <div id="totals-wrap">
        <div id="total-usd">$0.00</div>
        <div id="total-lbp">0 LBP</div>
    </div>
</div>
<div id="thank-msg">Thank you for shopping with us</div>

<script>
const RATE = <?= $rate ?>;

function fmt(n) {
    return '$' + parseFloat(n).toFixed(2);
}
function fmtLBP(n) {
    return Math.round(n).toLocaleString() + ' LL';
}

function applyState(data) {
    const items = data.items || [];
    const total = parseFloat(data.total) || 0;
    const welcome = document.getElementById('welcome-msg');
    const itemsWrap = document.getElementById('items-wrap');
    const totalsWrap = document.getElementById('totals-wrap');

    if (!items.length) {
        welcome.style.display = '';
        itemsWrap.style.display = 'none';
        totalsWrap.style.display = 'none';
        document.getElementById('total-usd').textContent = '$0.00';
        document.getElementById('total-lbp').textContent = '0 LBP';
        return;
    }

    welcome.style.display = 'none';
    itemsWrap.style.display = '';
    totalsWrap.style.display = 'flex';

    const tbody = document.getElementById('items-body');
    tbody.innerHTML = items.map(it =>
        `<tr><td>${escHtml(it.name)}</td>
             <td style="text-align:right">${parseFloat(it.qty).toFixed(0)}</td>
             <td style="text-align:right">${fmt(parseFloat(it.price) * parseFloat(it.qty))}</td></tr>`
    ).join('');

    document.getElementById('total-usd').textContent = fmt(total);
    document.getElementById('total-lbp').textContent  = fmtLBP(total * RATE);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Listen for updates from the POS page via localStorage
window.addEventListener('storage', function(e) {
    if (e.key !== 'posDisplay') return;
    try { applyState(JSON.parse(e.newValue || '{}')); } catch(err) {}
});

// Also poll every 2s in case storage event doesn't fire (different browser tabs)
setInterval(function() {
    try {
        const raw = localStorage.getItem('posDisplay');
        if (raw) applyState(JSON.parse(raw));
    } catch(err) {}
}, 2000);

// Init on load
try {
    const raw = localStorage.getItem('posDisplay');
    if (raw) applyState(JSON.parse(raw));
} catch(err) {}
</script>
</body>
</html>
