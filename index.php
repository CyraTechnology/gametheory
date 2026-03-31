<?php
require_once 'db.php';

if (!function_exists('formatPrice')) {
    function formatPrice($price) {
        return '₹' . number_format($price, 2);
    }
}

if (!function_exists('getDemandColor')) {
    function getDemandColor($demand) {
        $map = ['low'=>'demand-low','medium'=>'demand-med','high'=>'demand-high','critical'=>'demand-crit'];
        return $map[$demand] ?? 'demand-med';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>GameTheory — Dynamic Pricing Platform</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
    /* ═══════════════════════════════════════
       TOKENS
    ═══════════════════════════════════════ */
    :root {
        --bg:          #07090f;
        --bg-2:        #0d1018;
        --bg-3:        #131720;
        --bg-4:        #1a2030;
        --border:      rgba(255,255,255,0.07);
        --border-2:    rgba(255,255,255,0.12);

        --cyan:        #00e5ff;
        --cyan-dim:    rgba(0,229,255,0.12);
        --cyan-glow:   rgba(0,229,255,0.25);
        --green:       #00e676;
        --green-dim:   rgba(0,230,118,0.12);
        --red:         #ff4444;
        --red-dim:     rgba(255,68,68,0.12);
        --amber:       #ffb300;
        --amber-dim:   rgba(255,179,0,0.12);
        --violet:      #7c4dff;

        --text-1:      #eef1f7;
        --text-2:      #8b90a0;
        --text-3:      #4a5068;
        --text-mono:   #b0e0ff;

        --sidebar-w:   280px;
        --nav-h:       60px;
        --radius:      12px;
        --radius-lg:   18px;
        --transition:  0.35s cubic-bezier(0.23,1,0.32,1);

        --font-display: 'Syne', sans-serif;
        --font-body:    'Outfit', sans-serif;
        --font-mono:    'IBM Plex Mono', monospace;
    }

    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

    html { font-size:16px; scroll-behavior:smooth; }

    body {
        font-family: var(--font-body);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* ── SCANLINE texture overlay ── */
    body::before {
        content:'';
        position:fixed; inset:0; z-index:0; pointer-events:none;
        background: repeating-linear-gradient(
            0deg,
            transparent,
            transparent 2px,
            rgba(0,0,0,0.07) 2px,
            rgba(0,0,0,0.07) 4px
        );
    }

    /* ── Ambient glow spheres ── */
    .ambient {
        position:fixed; z-index:0; pointer-events:none;
        border-radius:50%;
        filter:blur(80px);
    }
    .amb-1 {
        width:500px; height:500px;
        background: radial-gradient(circle, rgba(0,229,255,0.06) 0%, transparent 70%);
        top:-100px; left:-100px;
        animation: amb-drift 20s ease-in-out infinite alternate;
    }
    .amb-2 {
        width:400px; height:400px;
        background: radial-gradient(circle, rgba(124,77,255,0.05) 0%, transparent 70%);
        bottom:0; right:-100px;
        animation: amb-drift 26s ease-in-out infinite alternate-reverse;
    }
    @keyframes amb-drift {
        from { transform:translate(0,0) scale(1); }
        to   { transform:translate(50px,40px) scale(1.1); }
    }

    /* ══════════════════════════════════════
       SIDEBAR
    ══════════════════════════════════════ */
    .sidebar-overlay {
        position:fixed; inset:0; z-index:200;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(4px);
        opacity:0; pointer-events:none;
        transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { opacity:1; pointer-events:all; }

    .sidebar {
        position:fixed; top:0; right:calc(-1 * var(--sidebar-w));
        width: var(--sidebar-w);
        height:100vh; z-index:210;
        background: var(--bg-2);
        border-left: 1px solid var(--border-2);
        display:flex; flex-direction:column;
        transition: right var(--transition);
        overflow:hidden;
    }
    .sidebar.active { right:0; }

    /* Top glow strip */
    .sidebar::before {
        content:'';
        position:absolute; top:0; left:0; right:0;
        height:2px;
        background: linear-gradient(90deg, transparent, var(--cyan), transparent);
    }

    .sidebar-head {
        padding: 28px 24px 20px;
        border-bottom: 1px solid var(--border);
        flex-shrink:0;
    }
    .sidebar-logo {
        display:flex; align-items:center; gap:12px;
        margin-bottom:6px;
    }
    .sidebar-logo-icon {
        width:38px; height:38px;
        border-radius:10px;
        background: var(--cyan-dim);
        border: 1px solid var(--cyan-glow);
        display:flex; align-items:center; justify-content:center;
        color: var(--cyan); font-size:16px;
    }
    .sidebar-logo-text {
        font-family: var(--font-display);
        font-weight:800; font-size:18px;
        color: var(--text-1);
        letter-spacing:-0.02em;
    }
    .sidebar-tagline {
        font-family: var(--font-mono);
        font-size:10px; color: var(--text-3);
        letter-spacing:0.08em; text-transform:uppercase;
    }

    .sidebar-body { flex:1; overflow-y:auto; padding:16px 0; }

    .sidebar-section-label {
        font-family: var(--font-mono);
        font-size:9px; font-weight:500;
        letter-spacing:0.15em; text-transform:uppercase;
        color: var(--text-3);
        padding: 12px 24px 6px;
    }

    .sidebar-divider {
        height:1px; background: var(--border);
        margin: 10px 20px;
    }

    .nav-item {
        display:flex; align-items:center; gap:14px;
        padding:11px 24px;
        color: var(--text-2);
        text-decoration:none;
        font-size:14px; font-weight:400;
        border-left:2px solid transparent;
        transition: all 0.25s ease;
        position:relative;
    }
    .nav-item i { width:18px; font-size:14px; }
    .nav-item:hover {
        color: var(--text-1);
        background: var(--bg-3);
        border-left-color: var(--border-2);
    }
    .nav-item.active {
        color: var(--cyan);
        background: var(--cyan-dim);
        border-left-color: var(--cyan);
    }
    .nav-item.active i { color: var(--cyan); }

    .nav-badge {
        margin-left:auto;
        font-family: var(--font-mono);
        font-size:10px; font-weight:500;
        padding:2px 7px; border-radius:20px;
        background: var(--cyan-dim);
        color: var(--cyan);
        border:1px solid var(--cyan-glow);
    }

    /* Sidebar scrollbar */
    .sidebar-body::-webkit-scrollbar { width:4px; }
    .sidebar-body::-webkit-scrollbar-track { background:transparent; }
    .sidebar-body::-webkit-scrollbar-thumb { background:var(--border-2); border-radius:4px; }

    /* ══════════════════════════════════════
       NAVBAR
    ══════════════════════════════════════ */
    .navbar {
        position:sticky; top:0; z-index:100;
        height: var(--nav-h);
        background: rgba(7,9,15,0.85);
        backdrop-filter: blur(20px);
        border-bottom:1px solid var(--border);
        display:flex; align-items:center;
        padding:0 24px;
    }

    .navbar-inner {
        width:100%; max-width:1400px; margin:0 auto;
        display:flex; align-items:center; justify-content:space-between;
    }

    .nav-brand {
        display:flex; align-items:center; gap:12px;
        text-decoration:none;
    }
    .nav-brand-icon {
        width:34px; height:34px; border-radius:9px;
        background: var(--cyan-dim);
        border:1px solid var(--cyan-glow);
        display:flex; align-items:center; justify-content:center;
        color:var(--cyan); font-size:15px;
    }
    .nav-brand-name {
        font-family: var(--font-display);
        font-weight:800; font-size:16px;
        color:var(--text-1); letter-spacing:-0.02em;
    }
    .nav-brand-name span { color:var(--cyan); }

    .nav-status {
        display:flex; align-items:center; gap:8px;
        font-family:var(--font-mono); font-size:11px;
        color:var(--text-2);
    }
    .status-dot {
        width:7px; height:7px; border-radius:50%;
        background:var(--green);
        box-shadow:0 0 8px var(--green);
        animation:pulse-dot 2s ease-in-out infinite;
    }
    @keyframes pulse-dot {
        0%,100% { opacity:1; box-shadow:0 0 8px var(--green); }
        50%      { opacity:0.5; box-shadow:0 0 3px var(--green); }
    }

    .hamburger {
        width:38px; height:38px; border-radius:10px;
        background: var(--bg-3); border:1px solid var(--border);
        color:var(--text-2); font-size:16px;
        display:flex; align-items:center; justify-content:center;
        cursor:pointer;
        transition: all 0.25s ease;
    }
    .hamburger:hover {
        background: var(--bg-4);
        border-color: var(--border-2);
        color:var(--text-1);
    }

    /* ══════════════════════════════════════
       PAGE LAYOUT
    ══════════════════════════════════════ */
    .page { position:relative; z-index:1; }

    .container {
        width:100%; max-width:1300px;
        margin:0 auto; padding:0 24px;
    }

    /* ══════════════════════════════════════
       HERO
    ══════════════════════════════════════ */
    .hero {
        padding:90px 0 80px;
        text-align:center;
        position:relative;
        overflow:hidden;
    }

    /* Chess grid pattern */
    .hero::before {
        content:'';
        position:absolute; inset:0; z-index:0;
        background-image:
            linear-gradient(rgba(0,229,255,0.04) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,229,255,0.04) 1px, transparent 1px);
        background-size: 60px 60px;
        mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
    }

    .hero-content { position:relative; z-index:1; }

    .hero-eyebrow {
        display:inline-flex; align-items:center; gap:8px;
        font-family:var(--font-mono); font-size:11px; font-weight:500;
        letter-spacing:0.12em; text-transform:uppercase;
        color:var(--cyan); padding:6px 16px;
        background:var(--cyan-dim); border:1px solid var(--cyan-glow);
        border-radius:20px; margin-bottom:28px;
    }
    .hero-eyebrow::before {
        content:'';
        width:6px; height:6px; border-radius:50%;
        background:var(--cyan);
        box-shadow:0 0 8px var(--cyan);
    }

    .hero-title {
        font-family: var(--font-display);
        font-size: clamp(2.4rem, 5vw, 4.2rem);
        font-weight:800; line-height:1.05;
        letter-spacing:-0.03em;
        color:var(--text-1);
        margin-bottom:20px;
    }
    .hero-title .accent { color:var(--cyan); }
    .hero-title .muted  { color:var(--text-2); font-weight:400; }

    .hero-sub {
        font-size:16px; font-weight:300;
        color:var(--text-2); max-width:520px;
        margin:0 auto 40px;
        line-height:1.6;
    }

    .hero-cta {
        display:inline-flex; align-items:center; gap:10px;
        padding:14px 32px; border-radius:var(--radius);
        background:var(--cyan); color:#000;
        font-family:var(--font-display); font-weight:700;
        font-size:14px; letter-spacing:0.03em; text-decoration:none;
        transition:all 0.3s ease;
        border:none; cursor:pointer;
    }
    .hero-cta:hover {
        background:#33ecff;
        box-shadow:0 8px 30px rgba(0,229,255,0.35);
        transform:translateY(-2px);
    }

    /* ══════════════════════════════════════
       SECTION UTILITIES
    ══════════════════════════════════════ */
    .section { padding:60px 0; }
    .section-alt { background:var(--bg-2); }

    .section-head {
        display:flex; align-items:baseline; gap:14px;
        margin-bottom:32px;
    }
    .section-title {
        font-family:var(--font-display);
        font-size:20px; font-weight:700;
        letter-spacing:-0.01em; color:var(--text-1);
    }
    .section-title-icon {
        font-size:14px; color:var(--cyan);
    }
    .section-count {
        font-family:var(--font-mono);
        font-size:11px; color:var(--text-3);
        margin-left:auto;
    }

    /* ══════════════════════════════════════
       ALERT EVENTS
    ══════════════════════════════════════ */
    .events-grid {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));
        gap:14px;
        margin-bottom:48px;
    }

    .event-card {
        background:var(--bg-3);
        border:1px solid var(--border);
        border-radius:var(--radius);
        padding:16px 20px;
        display:flex; align-items:flex-start; gap:14px;
        transition: border-color 0.3s ease;
        animation: fadeSlideUp 0.5s var(--transition) both;
    }
    .event-card:hover { border-color:var(--border-2); }

    .event-icon {
        width:36px; height:36px; border-radius:9px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-size:14px;
    }
    .event-icon.crit { background:var(--red-dim); color:var(--red); border:1px solid rgba(255,68,68,0.25); }
    .event-icon.warn { background:var(--amber-dim); color:var(--amber); border:1px solid rgba(255,179,0,0.25); }
    .event-icon.info { background:var(--cyan-dim); color:var(--cyan); border:1px solid var(--cyan-glow); }

    .event-type {
        font-family:var(--font-mono); font-size:10px; font-weight:500;
        letter-spacing:0.1em; text-transform:uppercase;
        color:var(--text-3); margin-bottom:4px;
    }
    .event-desc { font-size:13px; color:var(--text-2); line-height:1.5; }

    /* ══════════════════════════════════════
       PRICE TABLE
    ══════════════════════════════════════ */
    .market-layout {
        display:grid;
        grid-template-columns:1fr 320px;
        gap:24px;
        align-items:start;
    }

    @media(max-width:900px){
        .market-layout { grid-template-columns:1fr; }
    }

    .data-card {
        background:var(--bg-2);
        border:1px solid var(--border);
        border-radius:var(--radius-lg);
        overflow:hidden;
    }

    .data-card-head {
        padding:18px 22px;
        border-bottom:1px solid var(--border);
        display:flex; align-items:center; gap:12px;
    }
    .data-card-head h4 {
        font-family:var(--font-display);
        font-size:15px; font-weight:700;
        color:var(--text-1); letter-spacing:-0.01em;
    }
    .data-card-head i { color:var(--cyan); font-size:14px; }

    /* Table */
    .price-table { width:100%; border-collapse:collapse; }
    .price-table thead th {
        padding:10px 16px;
        font-family:var(--font-mono); font-size:10px; font-weight:500;
        letter-spacing:0.1em; text-transform:uppercase;
        color:var(--text-3);
        background:var(--bg-3);
        border-bottom:1px solid var(--border);
        text-align:left;
        white-space:nowrap;
    }
    .price-table tbody td {
        padding:12px 16px;
        font-size:13px; color:var(--text-2);
        border-bottom:1px solid var(--border);
        white-space:nowrap;
    }
    .price-table tbody tr:last-child td { border-bottom:none; }
    .price-table tbody tr {
        transition: background 0.2s ease;
    }
    .price-table tbody tr:hover td {
        background:var(--bg-3);
        color:var(--text-1);
    }

    .price-new {
        font-family:var(--font-mono); font-weight:500;
        color:var(--text-1);
    }
    .price-old {
        font-family:var(--font-mono); font-size:12px;
        color:var(--text-3);
    }
    .time-cell {
        font-family:var(--font-mono); font-size:11px;
        color:var(--text-3);
    }
    .company-cell { color:var(--text-1); font-weight:500; }

    .badge-change {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 8px; border-radius:6px;
        font-family:var(--font-mono); font-size:11px; font-weight:500;
    }
    .badge-change.up {
        background:var(--red-dim); color:var(--red);
        border:1px solid rgba(255,68,68,0.2);
    }
    .badge-change.down {
        background:var(--green-dim); color:var(--green);
        border:1px solid rgba(0,230,118,0.2);
    }

    /* ══════════════════════════════════════
       STATS PANEL
    ══════════════════════════════════════ */
    .stats-grid {
        display:grid; grid-template-columns:1fr 1fr;
        gap:12px; padding:20px;
    }

    .stat-tile {
        background:var(--bg-3);
        border:1px solid var(--border);
        border-radius:var(--radius);
        padding:16px;
        text-align:center;
        transition: border-color 0.3s ease;
    }
    .stat-tile:hover { border-color:var(--border-2); }
    .stat-tile.warn {
        background:var(--amber-dim);
        border-color:rgba(255,179,0,0.2);
    }

    .stat-value {
        font-family:var(--font-display);
        font-size:28px; font-weight:800;
        color:var(--text-1); line-height:1;
        margin-bottom:6px;
    }
    .stat-tile.warn .stat-value { color:var(--amber); }
    .stat-label {
        font-family:var(--font-mono);
        font-size:10px; font-weight:500;
        letter-spacing:0.1em; text-transform:uppercase;
        color:var(--text-3);
    }
    .stat-tile.warn .stat-label { color:var(--amber); opacity:0.7; }

    /* ══════════════════════════════════════
       PRODUCT GRID
    ══════════════════════════════════════ */
    .products-grid {
        display:grid;
        grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
        gap:20px;
        margin-top:32px;
    }

    .product-card {
        background:var(--bg-2);
        border:1px solid var(--border);
        border-radius:var(--radius-lg);
        overflow:hidden;
        transition: all 0.3s ease;
        display:flex; flex-direction:column;
        animation: fadeSlideUp 0.5s var(--transition) both;
    }
    .product-card:hover {
        border-color:var(--border-2);
        transform:translateY(-3px);
        box-shadow:0 20px 40px rgba(0,0,0,0.4);
    }

    .product-card-head {
        padding:14px 18px;
        display:flex; align-items:center; justify-content:space-between;
        border-bottom:1px solid var(--border);
        background:var(--bg-3);
    }

    .demand-pill {
        display:inline-flex; align-items:center; gap:5px;
        padding:4px 10px; border-radius:20px;
        font-family:var(--font-mono); font-size:9px; font-weight:500;
        letter-spacing:0.1em; text-transform:uppercase;
    }
    .demand-pill::before {
        content:''; width:5px; height:5px; border-radius:50%;
    }
    .demand-low  { background:var(--red-dim);   color:var(--red);   border:1px solid rgba(255,68,68,0.2); }
    .demand-low::before  { background:var(--red); }
    .demand-med  { background:var(--amber-dim); color:var(--amber); border:1px solid rgba(255,179,0,0.2); }
    .demand-med::before  { background:var(--amber); }
    .demand-high { background:var(--green-dim); color:var(--green); border:1px solid rgba(0,230,118,0.2); }
    .demand-high::before { background:var(--green); }
    .demand-crit { background:var(--cyan-dim);  color:var(--cyan);  border:1px solid var(--cyan-glow); }
    .demand-crit::before { background:var(--cyan); box-shadow:0 0 6px var(--cyan); animation:pulse-dot 2s infinite; }

    .competitor-badge {
        display:inline-flex; align-items:center; gap:5px;
        font-family:var(--font-mono); font-size:10px;
        color:var(--text-3); padding:3px 8px;
        background:var(--bg-4); border-radius:6px;
        border:1px solid var(--border);
    }

    .product-body { padding:20px 18px; flex:1; }

    .product-name {
        font-family:var(--font-display);
        font-size:16px; font-weight:700;
        color:var(--text-1); letter-spacing:-0.01em;
        margin-bottom:14px;
    }

    .price-display { margin-bottom:10px; }
    .price-main {
        font-family:var(--font-mono);
        font-size:26px; font-weight:500;
        color:var(--cyan); line-height:1;
    }
    .price-avg {
        font-family:var(--font-mono); font-size:12px;
        color:var(--text-3); margin-top:4px;
    }
    .price-save {
        display:inline-flex; align-items:center; gap:5px;
        font-family:var(--font-mono); font-size:11px;
        color:var(--green); margin-top:6px;
    }

    .product-foot {
        padding:12px 18px;
        border-top:1px solid var(--border);
        background:var(--bg-3);
        display:flex; align-items:center; justify-content:space-between;
    }
    .foot-meta {
        font-family:var(--font-mono); font-size:10px; color:var(--text-3);
        display:flex; gap:14px;
    }
    .foot-meta span { display:flex; align-items:center; gap:5px; }
    .foot-meta i { font-size:10px; }

    /* ══════════════════════════════════════
       EMPTY STATES
    ══════════════════════════════════════ */
    .empty-state {
        padding:40px 20px; text-align:center;
        color:var(--text-3);
        font-family:var(--font-mono); font-size:12px;
    }
    .empty-state i { font-size:28px; display:block; margin-bottom:12px; opacity:0.4; }

    /* ══════════════════════════════════════
       FOOTER
    ══════════════════════════════════════ */
    .footer {
        margin-top:80px; padding:32px 0;
        border-top:1px solid var(--border);
        background:var(--bg-2);
    }
    .footer-inner {
        display:flex; align-items:center; justify-content:space-between;
        flex-wrap:wrap; gap:12px;
    }
    .footer-copy {
        font-family:var(--font-mono); font-size:11px;
        color:var(--text-3);
    }
    .footer-tag {
        font-family:var(--font-mono); font-size:10px;
        color:var(--cyan); opacity:0.6;
        letter-spacing:0.05em;
    }

    /* ══════════════════════════════════════
       ANIMATIONS
    ══════════════════════════════════════ */
    @keyframes fadeSlideUp {
        from { opacity:0; transform:translateY(16px); }
        to   { opacity:1; transform:translateY(0); }
    }

    /* Stagger product cards */
    .product-card:nth-child(1) { animation-delay:0.05s; }
    .product-card:nth-child(2) { animation-delay:0.1s; }
    .product-card:nth-child(3) { animation-delay:0.15s; }
    .product-card:nth-child(4) { animation-delay:0.2s; }
    .product-card:nth-child(5) { animation-delay:0.25s; }
    .product-card:nth-child(6) { animation-delay:0.3s; }

    /* Row flash on price update */
    @keyframes row-flash {
        0%   { background: rgba(0,229,255,0.08); }
        100% { background: transparent; }
    }
    .price-flash td { animation: row-flash 1s ease; }

    /* ══════════════════════════════════════
       RESPONSIVE
    ══════════════════════════════════════ */
    @media(max-width:640px) {
        .hero { padding:60px 0 50px; }
        .section { padding:40px 0; }
        .container { padding:0 16px; }
        .hero-title .muted { display:none; }
        .nav-status { display:none; }
        .stats-grid { padding:14px; gap:10px; }
        .price-table thead th:nth-child(3),
        .price-table tbody td:nth-child(3) { display:none; }
    }
    </style>
</head>
<body>

<div class="ambient amb-1"></div>
<div class="ambient amb-2"></div>

<!-- Overlay -->
<div class="sidebar-overlay" id="overlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar" aria-label="Navigation sidebar">
    <div class="sidebar-head">
        <div class="sidebar-logo">
            <div class="sidebar-logo-icon"><i class="fas fa-chess-board"></i></div>
            <span class="sidebar-logo-text">GameTheory</span>
        </div>
        <div class="sidebar-tagline">Dynamic Pricing Platform</div>
    </div>

    <div class="sidebar-body">
        <p class="sidebar-section-label">Main</p>
        <a href="#" class="nav-item active">
            <i class="fas fa-grid-2"></i> Dashboard
            <span class="nav-badge">Live</span>
        </a>
        <a href="#live-market" class="nav-item">
            <i class="fas fa-chart-line"></i> Live Market
        </a>

        <div class="sidebar-divider"></div>
        <p class="sidebar-section-label">Access Portals</p>
        <a href="customer/login.php" class="nav-item">
            <i class="fas fa-user"></i> Customer Portal
        </a>
        <a href="auth/seller_login.php" class="nav-item">
            <i class="fas fa-store"></i> Seller Portal
        </a>
        <a href="auth/admin_login.php" class="nav-item">
            <i class="fas fa-user-shield"></i> Admin Portal
        </a>

        <div class="sidebar-divider"></div>
        <p class="sidebar-section-label">Intelligence</p>
        <a href="#" class="nav-item">
            <i class="fas fa-clock-rotate-left"></i> Price History
        </a>
    </div>
</aside>

<!-- Navbar -->
<nav class="navbar">
    <div class="navbar-inner">
        <a href="#" class="nav-brand">
            <div class="nav-brand-icon"><i class="fas fa-chess-board"></i></div>
            <span class="nav-brand-name">Game<span>Theory</span></span>
        </a>

        <div class="nav-status">
            <div class="status-dot"></div>
            <span>MARKET LIVE</span>
        </div>

        <button class="hamburger" id="hamburger" aria-label="Toggle navigation" aria-expanded="false">
            <i class="fas fa-bars" id="hamburgerIcon"></i>
        </button>
    </div>
</nav>

<!-- Page -->
<main class="page">

    <!-- Hero -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-eyebrow">Real-Time Market Intelligence</div>
                <h1 class="hero-title">
                    Dynamic Pricing<br>
                    <span class="accent">Powered by Game Theory</span><br>
                    <span class="muted">at scale</span>
                </h1>
                <p class="hero-sub">
                    Watch price competition unfold in real-time between companies — nash equilibria, dominant strategies, and market signals visualised live.
                </p>
                <a href="#live-market" class="hero-cta">
                    <i class="fas fa-chart-line"></i> View Live Market
                </a>
            </div>
        </div>
    </section>

    <!-- Live Market -->
    <section class="section section-alt" id="live-market">
        <div class="container">

            <!-- Active Events -->
            <div class="section-head">
                <i class="fas fa-bolt section-title-icon"></i>
                <h2 class="section-title">Active Market Events</h2>
            </div>

            <div class="events-grid">
                <?php
                $events = $db->fetchAll("
                    SELECT * FROM market_events
                    WHERE is_active = 1
                    AND start_date <= CURDATE()
                    AND (end_date IS NULL OR end_date >= CURDATE())
                    ORDER BY severity DESC
                    LIMIT 3
                ");
                if (!empty($events)):
                    foreach ($events as $event):
                        $isCrit = $event['severity'] === 'critical';
                ?>
                <div class="event-card">
                    <div class="event-icon <?= $isCrit ? 'crit' : 'warn' ?>">
                        <i class="fas fa-<?= $isCrit ? 'triangle-exclamation' : 'circle-exclamation' ?>"></i>
                    </div>
                    <div>
                        <div class="event-type"><?= htmlspecialchars($event['event_type']) ?></div>
                        <div class="event-desc"><?= htmlspecialchars($event['description']) ?></div>
                    </div>
                </div>
                <?php endforeach;
                else: ?>
                <div class="event-card">
                    <div class="event-icon info"><i class="fas fa-circle-info"></i></div>
                    <div>
                        <div class="event-type">Status</div>
                        <div class="event-desc">No active market events at this time. Market is stable.</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Market Layout -->
            <div class="section-head">
                <i class="fas fa-arrow-right-arrow-left section-title-icon"></i>
                <h2 class="section-title">Recent Price Changes</h2>
                <span class="section-count" id="changeCount">— rows</span>
            </div>

            <div class="market-layout">

                <!-- Price Table -->
                <div class="data-card">
                    <div class="data-card-head">
                        <i class="fas fa-table-list"></i>
                        <h4>Live Price Feed</h4>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="price-table" id="priceTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Company</th>
                                    <th>Old</th>
                                    <th>New</th>
                                    <th>Δ</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $priceChanges = $db->fetchAll("
                                    SELECT ph.*, p.name as product_name, c.name as company_name
                                    FROM price_history ph
                                    JOIN products p ON ph.product_id = p.id
                                    LEFT JOIN companies c ON ph.company_id = c.id
                                    ORDER BY ph.changed_at DESC
                                    LIMIT 10
                                ");

                                if (!empty($priceChanges)):
                                    foreach ($priceChanges as $row):
                                        $pct = (($row['new_price'] - $row['old_price']) / $row['old_price']) * 100;
                                        $isUp = $pct >= 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                                    <td class="company-cell"><?= htmlspecialchars($row['company_name'] ?? 'System') ?></td>
                                    <td class="price-old">₹<?= number_format($row['old_price'],2) ?></td>
                                    <td class="price-new">₹<?= number_format($row['new_price'],2) ?></td>
                                    <td>
                                        <span class="badge-change <?= $isUp ? 'up' : 'down' ?>">
                                            <i class="fas fa-arrow-<?= $isUp ? 'up' : 'down' ?>"></i>
                                            <?= ($isUp ? '+' : '') . number_format($pct,1) ?>%
                                        </span>
                                    </td>
                                    <td class="time-cell"><?= date('H:i:s', strtotime($row['changed_at'])) ?></td>
                                </tr>
                                <?php endforeach;
                                else: ?>
                                <tr><td colspan="6"><div class="empty-state"><i class="fas fa-inbox"></i>No recent price changes</div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Stats Panel -->
                <div>
                    <div class="data-card">
                        <div class="data-card-head">
                            <i class="fas fa-gauge-high"></i>
                            <h4>Market Statistics</h4>
                        </div>

                        <?php
                        $stats = $db->fetchOne("
                            SELECT
                                COUNT(DISTINCT product_id)  as active_products,
                                COUNT(DISTINCT company_id)  as active_companies,
                                AVG(price_change_per_day)   as avg_changes,
                                SUM(CASE WHEN price_trend = 'decreasing' THEN 1 ELSE 0 END) as price_wars
                            FROM (
                                SELECT product_id, company_id,
                                       COUNT(*) as price_change_per_day,
                                       price_trend
                                FROM competitor_prices
                                WHERE last_updated >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                                GROUP BY product_id, company_id
                            ) as ds
                        ");
                        ?>
                        <div class="stats-grid">
                            <div class="stat-tile">
                                <div class="stat-value" id="activeProducts"><?= $stats['active_products'] ?? 0 ?></div>
                                <div class="stat-label">Products</div>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-value" id="activeCompanies"><?= $stats['active_companies'] ?? 0 ?></div>
                                <div class="stat-label">Companies</div>
                            </div>
                            <div class="stat-tile">
                                <div class="stat-value" id="avgChanges"><?= number_format($stats['avg_changes'] ?? 0, 1) ?></div>
                                <div class="stat-label">Avg/Day</div>
                            </div>
                            <div class="stat-tile warn">
                                <div class="stat-value" id="priceWars"><?= $stats['price_wars'] ?? 0 ?></div>
                                <div class="stat-label">Price Wars</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- .market-layout -->

            <!-- Featured Products -->
            <div class="section-head" style="margin-top:56px;">
                <i class="fas fa-star section-title-icon"></i>
                <h2 class="section-title">Featured Products</h2>
            </div>

            <div class="products-grid" id="featuredProducts">
                <?php
                $products = $db->fetchAll("
                    SELECT p.*,
                           AVG(cp.price) as avg_market_price,
                           MIN(cp.price) as best_price,
                           COUNT(DISTINCT cp.competitor_name) as competitor_count,
                           (SELECT demand_level FROM competitor_prices
                            WHERE product_id = p.id
                            ORDER BY last_updated DESC LIMIT 1) as demand
                    FROM products p
                    LEFT JOIN competitor_prices cp ON p.id = cp.product_id
                    WHERE p.is_active = 1
                    GROUP BY p.id
                    ORDER BY p.total_sales DESC
                    LIMIT 6
                ");

                if (!empty($products)):
                    foreach ($products as $p):
                        $demand  = $p['demand'] ?? 'medium';
                        $dClass  = getDemandColor($demand);
                        $savings = ($p['avg_market_price'] ?? 0) - $p['current_price'];
                ?>
                <div class="product-card">
                    <div class="product-card-head">
                        <span class="demand-pill <?= $dClass ?>"><?= strtoupper($demand) ?> demand</span>
                        <span class="competitor-badge">
                            <i class="fas fa-users"></i> <?= (int)$p['competitor_count'] ?>
                        </span>
                    </div>
                    <div class="product-body">
                        <h5 class="product-name"><?= htmlspecialchars($p['name']) ?></h5>
                        <div class="price-display">
                            <div class="price-main">₹<?= number_format($p['current_price'], 2) ?></div>
                            <div class="price-avg">Market avg ₹<?= number_format($p['avg_market_price'] ?? 0, 2) ?></div>
                            <?php if ($savings > 0): ?>
                            <div class="price-save">
                                <i class="fas fa-tag"></i> Save ₹<?= number_format($savings, 2) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="product-foot">
                        <div class="foot-meta">
                            <span><i class="fas fa-box"></i> <?= (int)($p['stock'] ?? 0) ?></span>
                            <span><i class="fas fa-wave-square"></i> <?= number_format($p['demand_elasticity'] ?? 1.0, 2) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach;
                else: ?>
                <div class="empty-state" style="grid-column:1/-1">
                    <i class="fas fa-box-open"></i>No active products found
                </div>
                <?php endif; ?>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-inner">
                <span class="footer-copy">© 2026 GameTheory Dynamic Pricing Platform</span>
                <span class="footer-tag">AI-Powered · Real-Time · Game Theory</span>
            </div>
        </div>
    </footer>

</main>

<script>
/* ── Sidebar ── */
const sidebar   = document.getElementById('sidebar');
const overlay   = document.getElementById('overlay');
const hamburger = document.getElementById('hamburger');
const hIcon     = document.getElementById('hamburgerIcon');

function openSidebar() {
    sidebar.classList.add('active');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    hamburger.setAttribute('aria-expanded','true');
    hIcon.className = 'fas fa-xmark';
}
function closeSidebar() {
    sidebar.classList.remove('active');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    hamburger.setAttribute('aria-expanded','false');
    hIcon.className = 'fas fa-bars';
}

hamburger.addEventListener('click', () =>
    sidebar.classList.contains('active') ? closeSidebar() : openSidebar()
);
overlay.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeSidebar(); });

/* Smooth anchor links close sidebar */
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const t = document.querySelector(a.getAttribute('href'));
        if(t) { e.preventDefault(); t.scrollIntoView({behavior:'smooth'}); closeSidebar(); }
    });
});

/* Active link highlight */
document.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', function() {
        document.querySelectorAll('.nav-item').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
    });
});

/* ── Live data refresh ── */
function renderRows(rows) {
    const tbody = document.querySelector('#priceTable tbody');
    if(!tbody) return;
    tbody.innerHTML = '';
    rows.forEach(r => {
        const pct  = ((r.new_price - r.old_price) / r.old_price * 100).toFixed(1);
        const isUp = pct >= 0;
        const tr   = document.createElement('tr');
        tr.classList.add('price-flash');
        tr.innerHTML = `
            <td>${r.product_name}</td>
            <td class="company-cell">${r.company_name}</td>
            <td class="price-old">₹${parseFloat(r.old_price).toFixed(2)}</td>
            <td class="price-new">₹${parseFloat(r.new_price).toFixed(2)}</td>
            <td><span class="badge-change ${isUp?'up':'down'}">
                <i class="fas fa-arrow-${isUp?'up':'down'}"></i>
                ${isUp?'+':''}${pct}%
            </span></td>
            <td class="time-cell">${r.time}</td>
        `;
        tbody.appendChild(tr);
    });
    const cnt = document.getElementById('changeCount');
    if(cnt) cnt.textContent = rows.length + ' rows';
}

function renderStats(s) {
    const set = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v; };
    set('activeProducts',  s.products  || 0);
    set('activeCompanies', s.companies || 0);
    set('avgChanges', (s.avg_changes || 0).toFixed(1));
    set('priceWars',  s.price_wars  || 0);
}

function refreshMarket() {
    fetch('api/live_market.php')
        .then(r => r.json())
        .then(d => {
            if(d.price_changes) renderRows(d.price_changes);
            if(d.market_stats)  renderStats(d.market_stats);
        })
        .catch(() => {});
}

// Initialise row count from static render
const tbody = document.querySelector('#priceTable tbody');
if(tbody) {
    const n = tbody.querySelectorAll('tr').length;
    const cnt = document.getElementById('changeCount');
    if(cnt && n) cnt.textContent = n + ' rows';
}

// Poll every 10s if API available
fetch('api/live_market.php', {method:'HEAD'})
    .then(() => { refreshMarket(); setInterval(refreshMarket, 10000); })
    .catch(() => {});
</script>
</body>
</html>