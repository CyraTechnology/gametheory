<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

$customer_id = $_SESSION['user_id'];

// Initialize filters
$category    = $_GET['category']  ?? 'all';
$sort        = $_GET['sort']      ?? 'price_low';
$min_price   = $_GET['min_price'] ?? '';
$max_price   = $_GET['max_price'] ?? '';
$demand_level = $_GET['demand']   ?? 'all';
$search      = $_GET['search']    ?? '';
$page        = $_GET['page']      ?? 1;
$limit       = 12;
$offset      = ($page - 1) * $limit;

// Build SQL query with filters
$where  = "WHERE p.is_active = 1";
$params = [];

if ($category !== 'all') {
    $where .= " AND p.category = ?";
    $params[] = $category;
}
if ($min_price !== '') {
    $where .= " AND p.current_price >= ?";
    $params[] = $min_price;
}
if ($max_price !== '') {
    $where .= " AND p.current_price <= ?";
    $params[] = $max_price;
}
if ($demand_level !== 'all') {
    $where .= " AND cp.demand_level = ?";
    $params[] = $demand_level;
}
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.category LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$order_by = "ORDER BY ";
switch ($sort) {
    case 'price_low':   $order_by .= "p.current_price ASC"; break;
    case 'price_high':  $order_by .= "p.current_price DESC"; break;
    case 'name':        $order_by .= "p.name ASC"; break;
    case 'demand':      $order_by .= "cp.demand_level DESC"; break;
    case 'savings':     $order_by .= "price_difference_percent ASC"; break;
    case 'newest':      $order_by .= "p.created_at DESC"; break;
    default:            $order_by .= "p.total_sales DESC";
}

$count_sql = "SELECT COUNT(DISTINCT p.id) as total
              FROM products p
              LEFT JOIN competitor_prices cp ON p.id = cp.product_id
              $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_products = $count_stmt->fetchColumn();
$total_pages    = ceil($total_products / $limit);

$products_sql = "SELECT 
    p.*,
    AVG(cp.price) as market_avg_price,
    MIN(cp.price) as market_lowest_price,
    MAX(cp.price) as market_highest_price,
    COUNT(DISTINCT cp.competitor_name) as competitor_count,
    cp.demand_level,
    cp.price_trend,
    (p.current_price - MIN(cp.price)) as price_difference,
    ROUND(((p.current_price - MIN(cp.price)) / p.current_price * 100), 2) as price_difference_percent,
    (SELECT COUNT(*) FROM purchase_orders WHERE product_id = p.id) as total_sales_count,
    (SELECT COUNT(*) FROM demand_logs WHERE product_id = p.id AND DATE(created_at) = CURDATE()) as today_views,
    (SELECT AVG(customer_satisfaction) FROM purchase_orders WHERE product_id = p.id) as avg_rating,
    (CASE 
        WHEN p.current_price < MIN(cp.price) * 0.95 THEN 'best_price'
        WHEN p.current_price < AVG(cp.price) * 0.95 THEN 'good_price'
        ELSE 'market_price'
    END) as price_position
FROM products p
LEFT JOIN competitor_prices cp ON p.id = cp.product_id
$where
GROUP BY p.id
$order_by
LIMIT $limit OFFSET $offset";

$products_stmt = $conn->prepare($products_sql);
$products_stmt->execute($params);
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

$product_id = $_GET['id'] ?? 0;
if ($product_id) {
    $conn->prepare("INSERT INTO product_analytics (product_id, user_id, event_type, event_value) VALUES (?, ?, 'view', 1)")
         ->execute([$product_id, $_SESSION['user_id']]);
}

$categories_sql = "SELECT DISTINCT category FROM products WHERE is_active = 1 ORDER BY category";
$categories     = $conn->query($categories_sql)->fetchAll(PDO::FETCH_COLUMN);

$price_ranges = $conn->query("
    SELECT MIN(current_price) as min_price, MAX(current_price) as max_price, AVG(current_price) as avg_price
    FROM products WHERE is_active = 1
")->fetch(PDO::FETCH_ASSOC);

$cart_stmt = $conn->prepare("SELECT COUNT(*) FROM user_cart_items WHERE user_id = ?");
$cart_stmt->execute([$customer_id]);
$cart_count = (int) $cart_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#07090f">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Marketplace — GameTheory</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ═══════════════════════════════════════════════════
   DESIGN SYSTEM
═══════════════════════════════════════════════════ */
:root {
    --bg:         #07090f;
    --bg-2:       #0d1018;
    --bg-3:       #131720;
    --bg-4:       #1a2030;
    --bg-5:       #1f2638;
    --border:     rgba(255,255,255,0.07);
    --border-2:   rgba(255,255,255,0.12);
    --border-c:   rgba(0,229,255,0.30);
    --cyan:       #00e5ff;
    --cyan-dim:   rgba(0,229,255,0.10);
    --cyan-glow:  rgba(0,229,255,0.22);
    --green:      #00e676;
    --green-dim:  rgba(0,230,118,0.10);
    --red:        #ff4f4f;
    --red-dim:    rgba(255,79,79,0.10);
    --amber:      #ffb300;
    --amber-dim:  rgba(255,179,0,0.10);
    --purple:     #9b6dff;
    --purple-dim: rgba(155,109,255,0.10);
    --text-1:     #eef1f7;
    --text-2:     #8b90a0;
    --text-3:     #4a5068;
    --radius:     13px;
    --radius-lg:  20px;
    --radius-xl:  28px;
    --ease:       cubic-bezier(0.23,1,0.32,1);
    --font-d:     'Syne', sans-serif;
    --font-b:     'Outfit', sans-serif;
    --font-m:     'IBM Plex Mono', monospace;
    /* Mobile-specific */
    --nav-h:       64px;
    --bottom-bar:  70px;
    --safe-bottom: env(safe-area-inset-bottom, 0px);
}

/* ── Reset ──────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

html {
    scroll-behavior: smooth;
    -webkit-text-size-adjust: 100%;
}

body {
    font-family: var(--font-b);
    background: var(--bg);
    color: var(--text-1);
    min-height: 100vh;
    overflow-x: hidden;
    overscroll-behavior: none;
    -webkit-font-smoothing: antialiased;
    padding-bottom: calc(var(--bottom-bar) + var(--safe-bottom));
}

/* Scanlines */
body::before {
    content:'';
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background: repeating-linear-gradient(0deg, transparent, transparent 2px,
        rgba(0,0,0,0.05) 2px, rgba(0,0,0,0.05) 4px);
}

/* Ambient */
.amb { position:fixed; border-radius:50%; filter:blur(90px); pointer-events:none; z-index:0; }
.amb-1 {
    width:550px; height:550px;
    background: radial-gradient(circle, rgba(0,229,255,0.07) 0%, transparent 70%);
    top:-180px; left:-180px;
    animation: adrift 22s ease-in-out infinite alternate;
}
.amb-2 {
    width:420px; height:420px;
    background: radial-gradient(circle, rgba(155,109,255,0.05) 0%, transparent 70%);
    bottom:-120px; right:-120px;
    animation: adrift 28s ease-in-out infinite alternate-reverse;
}
@keyframes adrift {
    from { transform: translate(0,0) scale(1); }
    to   { transform: translate(60px,50px) scale(1.12); }
}

/* Grid bg */
.grid-bg {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(0,229,255,0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,229,255,0.025) 1px, transparent 1px);
    background-size: 56px 56px;
    mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 30%, transparent 100%);
}

/* ═══════════════════════════════════════════════════
   DESKTOP NAVIGATION (hidden on mobile)
═══════════════════════════════════════════════════ */
.glass-nav {
    background: rgba(13,16,24,0.9);
    backdrop-filter: blur(16px) saturate(180%);
    -webkit-backdrop-filter: blur(16px) saturate(180%);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 12px 24px;
    margin-bottom: 28px;
    position: sticky;
    top: 16px;
    z-index: 200;
}

.nav-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}

.brand-icon {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: var(--cyan-dim);
    border: 1px solid var(--border-c);
    display: flex; align-items: center; justify-content: center;
    color: var(--cyan); font-size: 18px;
}

.brand-name {
    font-family: var(--font-d);
    font-size: 22px; font-weight: 800;
    letter-spacing: -0.03em; color: var(--text-1);
}
.brand-name span { color: var(--cyan); }

.nav-links {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

.nav-link-custom {
    color: var(--text-2);
    text-decoration: none;
    padding: 8px 16px;
    border-radius: var(--radius);
    font-size: 14px; font-weight: 500;
    transition: all 0.2s ease;
    display: inline-flex; align-items: center; gap: 8px;
    position: relative;
}
.nav-link-custom:hover, .nav-link-custom.active {
    color: var(--cyan); background: var(--cyan-dim);
}

.cart-count-badge {
    position: absolute;
    top: 2px; right: 2px;
    background: var(--red);
    color: #fff;
    font-size: 9px; font-weight: 700;
    min-width: 16px; height: 16px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
    line-height: 1;
}

.search-form {
    display: flex; gap: 8px;
}

.search-input {
    background: var(--bg-4);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 16px;
    color: var(--text-1);
    font-family: var(--font-b);
    font-size: 13px;
    width: 220px;
    transition: all 0.3s ease;
}
.search-input:focus {
    outline: none;
    border-color: var(--border-c);
    box-shadow: 0 0 0 3px rgba(0,229,255,0.07);
}
.search-input::placeholder { color: var(--text-3); }

.search-btn {
    background: var(--cyan-dim);
    border: 1px solid var(--border-c);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--cyan);
    cursor: pointer;
    transition: all 0.2s;
}
.search-btn:hover { background: var(--cyan); color: #000; }

.user-menu {
    display: flex; align-items: center; gap: 12px;
    padding-left: 16px;
    border-left: 1px solid var(--border);
}

.user-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, var(--cyan), var(--purple));
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px;
    flex-shrink: 0;
}

.dropdown-trigger {
    cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    font-size: 14px;
}

.dropdown-menu-custom {
    position: absolute;
    top: calc(100% + 8px); right: 0;
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    min-width: 180px;
    opacity: 0; visibility: hidden;
    transform: translateY(-8px);
    transition: all 0.2s var(--ease);
    z-index: 300;
    overflow: hidden;
}
.dropdown-menu-custom.open {
    opacity: 1; visibility: visible; transform: translateY(0);
}

.dropdown-item-custom {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px;
    color: var(--text-2);
    text-decoration: none;
    font-size: 13px;
    transition: all 0.15s;
}
.dropdown-item-custom:hover { background: var(--bg-4); color: var(--cyan); }
.dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }

/* ═══════════════════════════════════════════════════
   MOBILE TOP BAR
═══════════════════════════════════════════════════ */
.mobile-topbar {
    display: none;
    position: sticky;
    top: 0;
    z-index: 200;
    background: rgba(7,9,15,0.92);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid var(--border);
    padding: 12px 16px;
    padding-top: calc(12px + env(safe-area-inset-top, 0px));
}

.mobile-topbar-inner {
    display: flex;
    align-items: center;
    gap: 12px;
}

.mobile-brand {
    font-family: var(--font-d);
    font-size: 20px; font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--text-1);
    flex: 1;
}
.mobile-brand span { color: var(--cyan); }

.mobile-icon-btn {
    width: 40px; height: 40px;
    border-radius: 12px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    color: var(--text-2);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    position: relative;
    flex-shrink: 0;
}
.mobile-icon-btn:hover, .mobile-icon-btn.active {
    color: var(--cyan); border-color: var(--border-c); background: var(--cyan-dim);
}
.mobile-icon-btn .badge {
    position: absolute;
    top: -4px; right: -4px;
    background: var(--red);
    color: #fff;
    font-size: 9px; font-weight: 700;
    min-width: 16px; height: 16px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
}

/* ═══════════════════════════════════════════════════
   MOBILE SEARCH BAR
═══════════════════════════════════════════════════ */
.mobile-search-bar {
    display: none;
    padding: 10px 16px 12px;
    background: rgba(7,9,15,0.85);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: calc(var(--nav-h) + env(safe-area-inset-top, 0px));
    z-index: 190;
}

.mobile-search-inner {
    display: flex;
    gap: 8px;
    align-items: center;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 0 16px;
    transition: all 0.3s;
}
.mobile-search-inner:focus-within {
    border-color: var(--border-c);
    box-shadow: 0 0 0 3px rgba(0,229,255,0.08);
}

.mobile-search-input {
    flex: 1;
    background: transparent;
    border: none;
    padding: 12px 0;
    color: var(--text-1);
    font-family: var(--font-b);
    font-size: 14px;
    outline: none;
}
.mobile-search-input::placeholder { color: var(--text-3); }

.mobile-search-icon { color: var(--text-3); font-size: 14px; }

/* ═══════════════════════════════════════════════════
   MOBILE FILTER CHIPS ROW
═══════════════════════════════════════════════════ */
.filter-chips-row {
    display: none;
    padding: 10px 16px;
    gap: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    border-bottom: 1px solid var(--border);
    background: var(--bg);
}
.filter-chips-row::-webkit-scrollbar { display: none; }

.filter-chip {
    flex-shrink: 0;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12px; font-weight: 500;
    background: var(--bg-3);
    border: 1px solid var(--border);
    color: var(--text-2);
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.2s;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer;
}
.filter-chip:hover { border-color: var(--border-c); color: var(--cyan); }
.filter-chip.active { background: var(--cyan); color: #000; border-color: var(--cyan); font-weight: 600; }
.filter-chip.filter-trigger { gap: 6px; }
.filter-chip i { font-size: 11px; }

/* Sort chips */
.sort-chip {
    flex-shrink: 0;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12px; font-weight: 500;
    background: var(--bg-3);
    border: 1px solid var(--border);
    color: var(--text-2);
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex; align-items: center; gap: 5px;
}
.sort-chip.active { background: var(--purple-dim); color: var(--purple); border-color: rgba(155,109,255,0.4); }

/* ═══════════════════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════════════════ */
.marketplace-container {
    position: relative;
    z-index: 1;
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px 24px;
}

.layout-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 28px;
}

/* ═══════════════════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════════════════ */
.page-header {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 32px 40px;
    margin-bottom: 32px;
    position: relative;
    overflow: hidden;
}
.page-header::before {
    content:'';
    position:absolute; top:0; left:0; width:100%; height:3px;
    background: linear-gradient(90deg, var(--cyan), transparent 60%);
}
.page-header h1 {
    font-family: var(--font-d);
    font-size: 28px; font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 8px;
}

/* ═══════════════════════════════════════════════════
   FILTER SIDEBAR
═══════════════════════════════════════════════════ */
.filter-sidebar {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    position: sticky;
    top: 100px;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--bg-5) transparent;
}
.filter-sidebar::-webkit-scrollbar { width: 4px; }
.filter-sidebar::-webkit-scrollbar-track { background: transparent; }
.filter-sidebar::-webkit-scrollbar-thumb { background: var(--bg-5); border-radius: 2px; }

.filter-title {
    font-family: var(--font-d);
    font-size: 18px; font-weight: 700;
    margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}

.filter-section {
    margin-bottom: 24px;
}

.filter-section h6 {
    font-size: 11px;
    font-family: var(--font-m);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}

.category-list { list-style: none; }
.category-item { margin-bottom: 4px; }
.category-link {
    display: block;
    padding: 9px 12px;
    color: var(--text-2);
    text-decoration: none;
    font-size: 13px;
    border-radius: var(--radius);
    transition: all 0.2s;
    border: 1px solid transparent;
}
.category-link:hover { background: var(--bg-3); color: var(--text-1); }
.category-link.active {
    background: var(--cyan-dim);
    color: var(--cyan);
    border-color: rgba(0,229,255,0.2);
}

.price-input-group { display: flex; gap: 8px; }
.price-input {
    flex: 1;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 9px 12px;
    color: var(--text-1);
    font-size: 13px;
    font-family: var(--font-b);
    transition: all 0.2s;
}
.price-input:focus {
    outline: none;
    border-color: var(--border-c);
    box-shadow: 0 0 0 3px rgba(0,229,255,0.07);
}

.demand-buttons { display: flex; flex-wrap: wrap; gap: 6px; }
.demand-btn {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px; font-weight: 500;
    text-decoration: none;
    background: var(--bg-3);
    color: var(--text-2);
    border: 1px solid var(--border);
    transition: all 0.2s;
    cursor: pointer;
}
.demand-btn:hover { border-color: var(--border-c); color: var(--cyan); }
.demand-btn.active { background: var(--cyan); color: #000; border-color: var(--cyan); }

.sort-select {
    width: 100%;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 12px;
    color: var(--text-1);
    font-family: var(--font-b);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}
.sort-select:focus { outline: none; border-color: var(--border-c); }

/* ═══════════════════════════════════════════════════
   PRODUCTS GRID
═══════════════════════════════════════════════════ */
.products-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.products-count h4 {
    font-family: var(--font-d);
    font-size: 20px; font-weight: 700; margin-bottom: 2px;
}
.products-count p { color: var(--text-2); font-size: 13px; }

.view-options { display: flex; gap: 8px; }
.view-btn {
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px 12px;
    color: var(--text-2);
    cursor: pointer;
    transition: all 0.2s;
    font-size: 14px;
}
.view-btn:hover, .view-btn.active {
    background: var(--cyan-dim);
    color: var(--cyan);
    border-color: var(--border-c);
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.products-grid.compact {
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 16px;
}

/* ═══════════════════════════════════════════════════
   PRODUCT CARD
═══════════════════════════════════════════════════ */
.product-card {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: all 0.35s var(--ease);
    position: relative;
    will-change: transform;
}
.product-card:hover {
    transform: translateY(-5px);
    border-color: var(--border-c);
    box-shadow: 0 16px 40px rgba(0,229,255,0.06), 0 4px 12px rgba(0,0,0,0.3);
}

.product-image {
    position: relative;
    height: 190px;
    background: var(--bg-3);
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.product-image img {
    width: 100%; height: 100%; object-fit: cover;
    transition: transform 0.4s var(--ease);
}
.product-card:hover .product-image img { transform: scale(1.06); }
.product-image i { font-size: 48px; color: var(--text-3); }

.price-tag {
    position: absolute;
    top: 12px; right: 12px;
    background: rgba(0,0,0,0.82);
    backdrop-filter: blur(8px);
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 700; font-size: 14px;
    color: var(--cyan);
    font-family: var(--font-m);
}

.demand-badge {
    position: absolute;
    top: 12px; left: 12px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 9px; font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.demand-low      { background: var(--red);   color: #fff; }
.demand-medium   { background: var(--amber); color: #000; }
.demand-high     { background: var(--green); color: #000; }
.demand-critical { background: var(--cyan);  color: #000; }

.product-body { padding: 16px; }

.product-title {
    font-family: var(--font-d);
    font-size: 15px; font-weight: 700;
    margin-bottom: 10px;
    line-height: 1.3;
}
.product-title a {
    color: var(--text-1);
    text-decoration: none;
    transition: color 0.2s;
}
.product-title a:hover { color: var(--cyan); }

.price-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 9px; font-weight: 700;
    margin-right: 6px;
    letter-spacing: 0.04em;
}
.price-best   { background: var(--green); color: #000; }
.price-good   { background: var(--cyan);  color: #000; }
.price-market { background: var(--bg-4);  color: var(--text-2); border: 1px solid var(--border); }

.price-trend {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 11px; font-weight: 500;
}
.trend-up     { color: var(--red); }
.trend-down   { color: var(--green); }
.trend-stable { color: var(--text-3); }

.game-theory-section {
    background: var(--bg-3);
    border-radius: 10px;
    padding: 11px;
    margin: 10px 0;
    border: 1px solid var(--border);
}
.insight-label {
    font-family: var(--font-m);
    font-size: 8px; letter-spacing: 0.1em;
    color: var(--cyan);
    margin-bottom: 8px;
    display: flex; align-items: center; gap: 5px;
    opacity: 0.7;
}
.price-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    font-size: 11px;
}
.stat-row {
    display: flex; justify-content: space-between;
    color: var(--text-2); gap: 4px;
}
.savings { color: var(--green); font-weight: 600; }

.stock-indicator {
    height: 3px;
    background: var(--bg-4);
    border-radius: 2px;
    margin: 8px 0;
    overflow: hidden;
}
.stock-fill { height: 100%; border-radius: 2px; transition: width 0.5s var(--ease); }
.stock-high   { background: var(--green); }
.stock-medium { background: var(--amber); }
.stock-low    { background: var(--red); }

.action-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 12px;
}

.btn-custom {
    padding: 9px 12px;
    border-radius: 10px;
    font-size: 12px; font-weight: 500;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    font-family: var(--font-b);
    display: flex; align-items: center; justify-content: center; gap: 5px;
    -webkit-tap-highlight-color: transparent;
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--border-c);
    color: var(--cyan);
}
.btn-outline:hover { background: var(--cyan-dim); transform: translateY(-1px); }

.btn-primary-custom { background: var(--cyan); color: #000; font-weight: 600; }
.btn-primary-custom:hover { background: #1aecff; transform: translateY(-1px); }

.btn-primary-custom:active,
.btn-outline:active { transform: scale(0.97); }

/* ═══════════════════════════════════════════════════
   PAGINATION
═══════════════════════════════════════════════════ */
.pagination-custom {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin: 32px 0;
    flex-wrap: wrap;
}
.page-link-custom {
    padding: 8px 14px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-2);
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}
.page-link-custom:hover { background: var(--bg-4); color: var(--text-1); }
.page-link-custom.active { background: var(--cyan); color: #000; border-color: var(--cyan); font-weight: 600; }
.page-link-custom.disabled { opacity: 0.3; pointer-events: none; }

/* ═══════════════════════════════════════════════════
   INSIGHTS CARD
═══════════════════════════════════════════════════ */
.insights-card {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-top: 28px;
}
.insights-card h5 {
    font-family: var(--font-d);
    font-size: 17px;
    margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}
.insights-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.insight-box {
    background: var(--bg-3);
    border-radius: var(--radius);
    padding: 16px;
    border: 1px solid var(--border);
}
.insight-box h6 {
    font-size: 12px; font-weight: 600;
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.insight-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    gap: 8px;
}
.insight-row span:first-child {
    font-size: 12px; color: var(--text-2);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1;
}
.hot-badge {
    background: var(--red-dim); color: var(--red);
    padding: 2px 8px; border-radius: 10px;
    font-size: 9px; font-weight: 700;
    flex-shrink: 0;
}
.deal-badge {
    background: var(--green-dim); color: var(--green);
    padding: 2px 8px; border-radius: 10px;
    font-size: 9px; font-weight: 700;
    flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════════ */
.footer {
    background: var(--bg-2);
    border-top: 1px solid var(--border);
    padding: 24px 0;
    margin-top: 48px;
}
.footer-content {
    max-width: 1600px;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.footer-content h6 { font-family: var(--font-d); font-size: 14px; margin-bottom: 4px; }
.footer-content p { color: var(--text-3); font-size: 12px; }

/* ═══════════════════════════════════════════════════
   MOBILE BOTTOM NAV (App-like sticky)
═══════════════════════════════════════════════════ */
.mobile-bottom-nav {
    display: none;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 300;
    background: rgba(13,16,24,0.97);
    backdrop-filter: blur(24px) saturate(200%);
    -webkit-backdrop-filter: blur(24px) saturate(200%);
    border-top: 1px solid var(--border-2);
    padding-bottom: var(--safe-bottom);
}

.bottom-nav-inner {
    display: flex;
    justify-content: space-around;
    align-items: center;
    height: var(--bottom-bar);
}

.bottom-nav-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    text-decoration: none;
    padding: 8px 0;
    color: var(--text-3);
    font-size: 10px;
    font-weight: 500;
    transition: all 0.2s;
    position: relative;
    -webkit-tap-highlight-color: transparent;
}
.bottom-nav-item i { font-size: 19px; transition: all 0.2s; }
.bottom-nav-item span { font-family: var(--font-b); letter-spacing: 0.02em; }
.bottom-nav-item.active { color: var(--cyan); }
.bottom-nav-item.active i { transform: translateY(-2px); }
.bottom-nav-item.active::after {
    content: '';
    position: absolute;
    top: 0; left: 50%; transform: translateX(-50%);
    width: 28px; height: 2px;
    background: var(--cyan);
    border-radius: 0 0 2px 2px;
}
.bottom-nav-item .nav-badge {
    position: absolute;
    top: 6px; left: calc(50% + 6px);
    background: var(--red);
    color: #fff;
    font-size: 8px; font-weight: 700;
    min-width: 14px; height: 14px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px;
}

/* ═══════════════════════════════════════════════════
   FILTER DRAWER (mobile slide-up)
═══════════════════════════════════════════════════ */
.filter-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    z-index: 400;
    opacity: 0;
    transition: opacity 0.3s;
}
.filter-overlay.visible { opacity: 1; }

.filter-drawer {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 401;
    background: var(--bg-2);
    border-radius: 24px 24px 0 0;
    border-top: 1px solid var(--border-2);
    max-height: 88vh;
    overflow-y: auto;
    transform: translateY(100%);
    transition: transform 0.4s var(--ease);
    padding-bottom: calc(24px + var(--safe-bottom));
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}
.filter-drawer.open { transform: translateY(0); }

.drawer-handle {
    width: 40px; height: 4px;
    background: var(--bg-5);
    border-radius: 2px;
    margin: 12px auto 20px;
}

.drawer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px 16px;
    border-bottom: 1px solid var(--border);
}
.drawer-header h4 {
    font-family: var(--font-d);
    font-size: 18px; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
}
.drawer-close {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--bg-3);
    border: none;
    color: var(--text-2);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.2s;
}
.drawer-close:hover { background: var(--bg-4); color: var(--text-1); }

.drawer-body { padding: 20px; }
.drawer-filter-section { margin-bottom: 24px; }
.drawer-filter-section h6 {
    font-family: var(--font-m);
    font-size: 10px; letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.drawer-category-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.drawer-cat-btn {
    padding: 10px 12px;
    border-radius: var(--radius);
    background: var(--bg-3);
    border: 1px solid var(--border);
    color: var(--text-2);
    font-family: var(--font-b);
    font-size: 13px;
    text-align: center;
    text-decoration: none;
    transition: all 0.2s;
    cursor: pointer;
}
.drawer-cat-btn:hover, .drawer-cat-btn.active {
    background: var(--cyan-dim);
    color: var(--cyan);
    border-color: rgba(0,229,255,0.25);
}
.drawer-demand-grid {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.drawer-demand-btn {
    padding: 8px 16px;
    border-radius: 20px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    color: var(--text-2);
    font-family: var(--font-b);
    font-size: 12px; font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}
.drawer-demand-btn.active { background: var(--cyan); color: #000; border-color: var(--cyan); }

.drawer-footer {
    display: flex; gap: 12px;
    padding: 16px 20px 0;
    border-top: 1px solid var(--border);
}
.drawer-apply-btn {
    flex: 1;
    background: var(--cyan);
    color: #000;
    border: none;
    border-radius: var(--radius);
    padding: 14px;
    font-family: var(--font-d);
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.drawer-apply-btn:hover { background: #1aecff; }
.drawer-reset-btn {
    padding: 14px 20px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-2);
    font-family: var(--font-b);
    font-size: 14px; font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: flex; align-items: center; gap: 6px;
    transition: all 0.2s;
}
.drawer-reset-btn:hover { border-color: var(--border-c); color: var(--red); }

/* ═══════════════════════════════════════════════════
   CART MODAL
═══════════════════════════════════════════════════ */
.modal-custom {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(8px);
    z-index: 500;
    align-items: flex-end;
    justify-content: center;
}
.modal-inner {
    background: var(--bg-2);
    border: 1px solid var(--border-2);
    border-radius: 24px 24px 0 0;
    width: 100%;
    max-width: 480px;
    padding: 28px 24px;
    padding-bottom: calc(24px + var(--safe-bottom));
    text-align: center;
    transform: translateY(100%);
    transition: transform 0.35s var(--ease);
}
.modal-custom.visible { display: flex; }
.modal-custom.visible .modal-inner { transform: translateY(0); }

.modal-icon { font-size: 48px; color: var(--green); margin-bottom: 12px; }
.modal-title { font-family: var(--font-d); font-size: 18px; font-weight: 700; margin-bottom: 6px; }
.modal-sub { color: var(--text-2); font-size: 13px; margin-bottom: 20px; }
.modal-btns { display: flex; gap: 12px; }
.modal-btns .btn-custom { flex: 1; padding: 12px; font-size: 13px; }

/* ═══════════════════════════════════════════════════
   TOAST NOTIFICATION
═══════════════════════════════════════════════════ */
.toast-container {
    position: fixed;
    top: 16px; right: 16px;
    z-index: 600;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.toast {
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    padding: 12px 16px;
    font-size: 13px;
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    transform: translateX(120%);
    transition: transform 0.35s var(--ease);
    pointer-events: all;
    max-width: 300px;
}
.toast.show { transform: translateX(0); }
.toast.success { border-left: 3px solid var(--green); }
.toast.error   { border-left: 3px solid var(--red); }

/* ═══════════════════════════════════════════════════
   SKELETON LOADER
═══════════════════════════════════════════════════ */
@keyframes skeleton-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 0.8; }
}
.skeleton {
    background: var(--bg-4);
    border-radius: 6px;
    animation: skeleton-pulse 1.5s ease-in-out infinite;
}

/* ═══════════════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════════════ */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.animate-in {
    animation: fadeInUp 0.45s var(--ease) both;
}
.animate-in:nth-child(1)  { animation-delay: 0.02s; }
.animate-in:nth-child(2)  { animation-delay: 0.04s; }
.animate-in:nth-child(3)  { animation-delay: 0.06s; }
.animate-in:nth-child(4)  { animation-delay: 0.08s; }
.animate-in:nth-child(5)  { animation-delay: 0.10s; }
.animate-in:nth-child(6)  { animation-delay: 0.12s; }
.animate-in:nth-child(7)  { animation-delay: 0.14s; }
.animate-in:nth-child(8)  { animation-delay: 0.16s; }
.animate-in:nth-child(9)  { animation-delay: 0.18s; }
.animate-in:nth-child(10) { animation-delay: 0.20s; }
.animate-in:nth-child(11) { animation-delay: 0.22s; }
.animate-in:nth-child(12) { animation-delay: 0.24s; }

/* ═══════════════════════════════════════════════════
   RESPONSIVE — TABLET
═══════════════════════════════════════════════════ */
@media (max-width: 1024px) {
    .marketplace-container { padding: 16px; }
    .layout-grid { grid-template-columns: 240px 1fr; gap: 20px; }
    .products-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
    .insights-grid { grid-template-columns: 1fr; }
}

/* ═══════════════════════════════════════════════════
   RESPONSIVE — MOBILE (≤ 768px)
═══════════════════════════════════════════════════ */
@media (max-width: 768px) {
    /* Hide desktop elements */
    .glass-nav, .filter-sidebar, .page-header { display: none; }

    /* Show mobile elements */
    .mobile-topbar, .mobile-search-bar, .filter-chips-row, .mobile-bottom-nav { display: flex; }

    /* Reset body */
    body {
        padding-bottom: calc(var(--bottom-bar) + var(--safe-bottom) + 12px);
    }

    /* Full width layout */
    .marketplace-container {
        padding: 12px;
        padding-bottom: 0;
    }
    .layout-grid { grid-template-columns: 1fr; }

    /* Products grid mobile */
    .products-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .products-grid.compact { grid-template-columns: 1fr; }

    /* Product card compact on mobile */
    .product-image { height: 160px; }
    .product-body { padding: 12px; }
    .product-title { font-size: 13px; margin-bottom: 6px; }
    .game-theory-section { padding: 9px; }
    .price-stats { grid-template-columns: 1fr; gap: 4px; }
    .action-buttons { gap: 6px; }
    .btn-custom { padding: 8px 8px; font-size: 11px; gap: 4px; }
    .price-tag { font-size: 12px; padding: 4px 10px; }

    /* Products header */
    .products-header { margin-bottom: 12px; }
    .products-count h4 { font-size: 16px; }

    /* Footer */
    .footer { margin-top: 24px; }
    .footer-content { flex-direction: column; text-align: center; padding: 16px; }

    /* Insights card */
    .insights-card { padding: 16px; margin-top: 20px; }
    .insights-grid { grid-template-columns: 1fr; gap: 12px; }

    /* Pagination */
    .pagination-custom { gap: 4px; margin: 20px 0; }
    .page-link-custom { padding: 8px 10px; font-size: 12px; }

    /* Toast position on mobile */
    .toast-container { top: auto; bottom: calc(var(--bottom-bar) + var(--safe-bottom) + 12px); left: 16px; right: 16px; }
    .toast { max-width: 100%; transform: translateY(30px); opacity: 0; }
    .toast.show { transform: translateY(0); opacity: 1; }

    /* Modal full bottom sheet */
    .modal-custom { align-items: flex-end; }
    .modal-inner { border-radius: 24px 24px 0 0; max-width: 100%; }
}

@media (max-width: 400px) {
    .products-grid { grid-template-columns: 1fr; }
}

/* ═══════════════════════════════════════════════════
   LIVE PULSE ANIMATION
═══════════════════════════════════════════════════ */
.live-dot {
    display: inline-block;
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--cyan);
    margin-right: 5px;
    animation: live-pulse 2s ease-in-out infinite;
}
@keyframes live-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(0,229,255,0.4); }
    50% { box-shadow: 0 0 0 5px rgba(0,229,255,0); }
}

/* Price update flash */
.price-updated {
    animation: price-flash 0.6s var(--ease);
}
@keyframes price-flash {
    0% { color: var(--amber); }
    100% { color: inherit; }
}

/* Quick add pulse on mobile */
.add-to-cart:active { transform: scale(0.94); }
</style>
</head>
<body>

<!-- Ambient decorations -->
<div class="amb amb-1"></div>
<div class="amb amb-2"></div>
<div class="grid-bg"></div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- ═══════════════════════════════════════════════════
     MOBILE TOP BAR
═══════════════════════════════════════════════════ -->
<div class="mobile-topbar">
    <div class="mobile-topbar-inner">
        <div class="mobile-brand">Game<span>Theory</span></div>

        <a href="../index.php#live-market" class="mobile-icon-btn">
            <i class="fas fa-chart-line"></i>
        </a>

        <a href="cart.php" class="mobile-icon-btn" title="Cart">
            <i class="fas fa-shopping-bag"></i>
            <?php if ($cart_count > 0): ?>
                <span class="badge"><?= $cart_count ?></span>
            <?php endif; ?>
        </a>

        <div class="mobile-icon-btn" id="mobileUserBtn">
            <div style="width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--purple));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     MOBILE SEARCH BAR
═══════════════════════════════════════════════════ -->
<div class="mobile-search-bar">
    <form class="mobile-search-inner" method="GET" action="" style="flex:1">
        <i class="fas fa-search mobile-search-icon"></i>
        <input type="text" class="mobile-search-input" name="search"
               placeholder="Search products, categories..."
               value="<?= htmlspecialchars($search) ?>">
        <?php if ($search): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['search' => '', 'page' => 1])) ?>"
               style="color:var(--text-3);font-size:12px;text-decoration:none;padding:4px;">
                <i class="fas fa-times"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════
     MOBILE FILTER CHIPS
═══════════════════════════════════════════════════ -->
<div class="filter-chips-row" id="filterChipsRow">
    <!-- Filter trigger -->
    <span class="filter-chip filter-trigger" id="openFilterDrawer">
        <i class="fas fa-sliders-h"></i> Filters
        <?php if ($category !== 'all' || $min_price !== '' || $max_price !== '' || $demand_level !== 'all'): ?>
            <span style="background:var(--cyan);color:#000;border-radius:50%;width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;margin-left:2px;">!</span>
        <?php endif; ?>
    </span>

    <!-- Sort chips -->
    <span class="sort-chip <?= $sort === 'price_low' ? 'active' : '' ?>"
          onclick="setMobileSort('price_low', this)">
        <i class="fas fa-arrow-up"></i> Cheapest
    </span>
    <span class="sort-chip <?= $sort === 'price_high' ? 'active' : '' ?>"
          onclick="setMobileSort('price_high', this)">
        <i class="fas fa-arrow-down"></i> Priciest
    </span>
    <span class="sort-chip <?= $sort === 'savings' ? 'active' : '' ?>"
          onclick="setMobileSort('savings', this)">
        <i class="fas fa-tag"></i> Best Deal
    </span>
    <span class="sort-chip <?= $sort === 'demand' ? 'active' : '' ?>"
          onclick="setMobileSort('demand', this)">
        <i class="fas fa-fire"></i> Trending
    </span>
    <span class="sort-chip <?= $sort === 'newest' ? 'active' : '' ?>"
          onclick="setMobileSort('newest', this)">
        <i class="fas fa-sparkles"></i> New
    </span>

    <!-- Active category chip -->
    <?php if ($category !== 'all'): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['category' => 'all', 'page' => 1])) ?>"
           class="filter-chip active">
            <?= ucfirst($category) ?> <i class="fas fa-times"></i>
        </a>
    <?php endif; ?>

    <!-- Active demand chip -->
    <?php if ($demand_level !== 'all'): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['demand' => 'all', 'page' => 1])) ?>"
           class="filter-chip active">
            <?= ucfirst($demand_level) ?> demand <i class="fas fa-times"></i>
        </a>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════
     DESKTOP NAVIGATION
═══════════════════════════════════════════════════ -->
<div class="marketplace-container">
<nav class="glass-nav animate-in">
    <div class="nav-content">
        <a href="../index.php" class="nav-brand">
            <div class="brand-icon"><i class="fas fa-chess-board"></i></div>
            <div class="brand-name">Game<span>Theory</span></div>
        </a>

        <div class="nav-links">
            <a href="customer_dashboard.php" class="nav-link-custom">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="product_list.php" class="nav-link-custom active" style="position:relative;">
                <i class="fas fa-store"></i> Shop
                <?php if ($cart_count > 0): ?>
                    <span class="cart-count-badge"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
            <a href="my_orders.php" class="nav-link-custom">
                <i class="fas fa-receipt"></i> Orders
            </a>
            <a href="../index.php#live-market" class="nav-link-custom">
                <i class="fas fa-chart-line"></i> Live Market
            </a>
        </div>

        <form class="search-form" method="GET" action="">
            <input type="text" class="search-input" name="search"
                   placeholder="Search products..."
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
        </form>

        <div class="user-menu">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <div style="position:relative;">
                <div class="dropdown-trigger" id="desktopUserDropdown">
                    <span style="font-size:14px;"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
                    <i class="fas fa-chevron-down" style="font-size:10px;"></i>
                </div>
                <div class="dropdown-menu-custom" id="desktopDropdownMenu">
                    <a href="profile.php" class="dropdown-item-custom"><i class="fas fa-user"></i> Profile</a>
                    <a href="wallet.php" class="dropdown-item-custom"><i class="fas fa-wallet"></i> Wallet</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item-custom"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Page Header -->
<div class="page-header animate-in">
    <h1>Marketplace</h1>
    <p style="color:var(--text-2);font-size:14px;">
        Real-time price competition across multiple retailers
        <span style="display:inline-flex;align-items:center;gap:6px;background:var(--cyan-dim);padding:4px 12px;border-radius:20px;margin-left:12px;font-size:11px;color:var(--cyan);">
            <span class="live-dot"></span> Live Updates
        </span>
    </p>
</div>

<!-- ═══════════════════════════════════════════════════
     MAIN LAYOUT GRID
═══════════════════════════════════════════════════ -->
<div class="layout-grid">

    <!-- FILTER SIDEBAR (desktop) -->
    <aside class="filter-sidebar animate-in">
        <div class="filter-title">
            <i class="fas fa-filter" style="color:var(--cyan);font-size:16px;"></i> Filters
        </div>

        <!-- Category -->
        <div class="filter-section">
            <h6><i class="fas fa-tag"></i> Category</h6>
            <ul class="category-list">
                <li class="category-item">
                    <a href="?<?= http_build_query(array_merge($_GET, ['category' => 'all', 'page' => 1])) ?>"
                       class="category-link <?= $category === 'all' ? 'active' : '' ?>">
                        All Categories
                    </a>
                </li>
                <?php foreach ($categories as $cat): ?>
                <li class="category-item">
                    <a href="?<?= http_build_query(array_merge($_GET, ['category' => $cat, 'page' => 1])) ?>"
                       class="category-link <?= $category === $cat ? 'active' : '' ?>">
                        <?= ucfirst($cat) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Price Range -->
        <div class="filter-section">
            <h6><i class="fas fa-dollar-sign"></i> Price Range</h6>
            <div class="price-input-group">
                <input type="number" class="price-input" id="minPrice" placeholder="Min"
                       value="<?= htmlspecialchars($min_price) ?>">
                <input type="number" class="price-input" id="maxPrice" placeholder="Max"
                       value="<?= htmlspecialchars($max_price) ?>">
            </div>
            <div style="margin-top:8px;">
                <small style="color:var(--text-3);font-size:11px;">
                    $<?= number_format($price_ranges['min_price'] ?? 0, 2) ?> –
                    $<?= number_format($price_ranges['max_price'] ?? 0, 2) ?>
                </small>
            </div>
        </div>

        <!-- Demand Level -->
        <div class="filter-section">
            <h6><i class="fas fa-fire"></i> Demand Level</h6>
            <div class="demand-buttons">
                <?php foreach (['all'=>'All','low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $key=>$label): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['demand' => $key, 'page' => 1])) ?>"
                   class="demand-btn <?= $demand_level === $key ? 'active' : '' ?>">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sorting -->
        <div class="filter-section">
            <h6><i class="fas fa-sort"></i> Sort By</h6>
            <select class="sort-select" id="sortSelect">
                <option value="popular"    <?= $sort==='popular'    ? 'selected':'' ?>>Most Popular</option>
                <option value="price_low"  <?= $sort==='price_low'  ? 'selected':'' ?>>Price: Low → High</option>
                <option value="price_high" <?= $sort==='price_high' ? 'selected':'' ?>>Price: High → Low</option>
                <option value="name"       <?= $sort==='name'       ? 'selected':'' ?>>Name A–Z</option>
                <option value="demand"     <?= $sort==='demand'     ? 'selected':'' ?>>High Demand</option>
                <option value="savings"    <?= $sort==='savings'    ? 'selected':'' ?>>Highest Savings</option>
                <option value="newest"     <?= $sort==='newest'     ? 'selected':'' ?>>Newest</option>
            </select>
        </div>

        <!-- Active Filters -->
        <?php if ($category !== 'all' || $min_price !== '' || $max_price !== '' || $demand_level !== 'all' || $search !== ''): ?>
        <div class="filter-section">
            <h6><i class="fas fa-check-circle"></i> Active Filters</h6>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php if ($category !== 'all'): ?>
                <span style="background:var(--cyan-dim);border:1px solid rgba(0,229,255,0.2);padding:4px 10px;border-radius:20px;font-size:11px;">
                    <?= ucfirst($category) ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['category'=>'all','page'=>1])) ?>"
                       style="color:var(--cyan);margin-left:5px;"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
                <?php if ($demand_level !== 'all'): ?>
                <span style="background:var(--cyan-dim);border:1px solid rgba(0,229,255,0.2);padding:4px 10px;border-radius:20px;font-size:11px;">
                    <?= ucfirst($demand_level) ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['demand'=>'all','page'=>1])) ?>"
                       style="color:var(--cyan);margin-left:5px;"><i class="fas fa-times"></i></a>
                </span>
                <?php endif; ?>
                <a href="product_list.php"
                   style="background:var(--red-dim);color:var(--red);border:1px solid rgba(255,79,79,0.2);padding:4px 10px;border-radius:20px;font-size:11px;text-decoration:none;">
                    <i class="fas fa-times"></i> Clear All
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Market Stats -->
        <div class="filter-section" style="border-top:1px solid var(--border);padding-top:20px;">
            <h6><i class="fas fa-chart-bar"></i> Market Stats</h6>
            <div style="font-size:12px;display:flex;flex-direction:column;gap:8px;">
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--text-2);">Total Products</span>
                    <span style="color:var(--cyan);font-weight:600;"><?= number_format($total_products) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--text-2);">Avg Price</span>
                    <span>$<?= number_format($price_ranges['avg_price'] ?? 0, 2) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:var(--text-2);">Price Wars</span>
                    <span style="color:var(--amber);">
                        <?= $conn->query("SELECT COUNT(DISTINCT product_id) FROM competitor_prices WHERE price_trend='decreasing'")->fetchColumn() ?>
                    </span>
                </div>
            </div>
        </div>
    </aside>

    <!-- PRODUCTS SECTION -->
    <div>
        <!-- Products Header -->
        <div class="products-header animate-in">
            <div class="products-count">
                <h4>Products</h4>
                <p>Showing <?= count($products) ?> of <?= number_format($total_products) ?></p>
            </div>
            <div class="view-options">
                <button class="view-btn" id="gridView" data-view="grid" title="Grid view">
                    <i class="fas fa-th-large"></i>
                </button>
                <button class="view-btn" id="compactView" data-view="compact" title="List view">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Products Grid -->
        <?php if (count($products) > 0): ?>
        <div class="products-grid" id="productsGrid">
            <?php foreach ($products as $i => $product):
                $stock_class  = $product['stock'] > 50 ? 'stock-high' : ($product['stock'] > 10 ? 'stock-medium' : 'stock-low');
                $price_class  = $product['price_position'] === 'best_price' ? 'price-best' :
                               ($product['price_position'] === 'good_price'  ? 'price-good'  : 'price-market');
                $trend_icon   = $product['price_trend'] === 'increasing' ? 'fa-arrow-up' :
                               ($product['price_trend'] === 'decreasing'  ? 'fa-arrow-down' : 'fa-minus');
                $trend_class  = $product['price_trend'] === 'increasing' ? 'trend-up' :
                               ($product['price_trend'] === 'decreasing'  ? 'trend-down' : 'trend-stable');
                $stock_width  = min(100, ($product['stock'] / 100) * 100);
            ?>
            <div class="product-card animate-in">
                <div class="product-image">
                    <?php if (!empty($product['image_url'])): ?>
                      <a href="product_view.php?id=<?= $product['id'] ?>">   <img src="../<?= htmlspecialchars($product['image_url']) ?>"
                             alt="<?= htmlspecialchars($product['name']) ?>"
                             loading="lazy">
                    <?php else: ?>
                        <i class="fas fa-box"></i>
                    <?php endif; ?>

                    <span class="price-tag">
                        $<?= number_format($product['current_price'], 2) ?>
                    </span>
                    <?php if ($product['demand_level']): ?>
                    <span class="demand-badge demand-<?= htmlspecialchars($product['demand_level']) ?>">
                        <?= strtoupper($product['demand_level']) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="product-body">
                    <h5 class="product-title">
                        <a href="product_view.php?id=<?= $product['id'] ?>">
                            <?= htmlspecialchars($product['name']) ?>
                        </a>
                    </h5>

                    <div style="margin-bottom:10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span class="price-badge <?= $price_class ?>">
                            <?= strtoupper(str_replace('_',' ',$product['price_position'])) ?>
                        </span>
                        <span class="price-trend <?= $trend_class ?>">
                            <i class="fas <?= $trend_icon ?>"></i>
                            <span style="font-size:10px;"><?= ucfirst($product['price_trend'] ?? 'stable') ?></span>
                        </span>
                    </div>

                    <div class="game-theory-section">
                        <div class="insight-label">
                            <i class="fas fa-chess-board"></i> GAME THEORY INSIGHTS
                        </div>
                        <div class="price-stats">
                            <div class="stat-row">
                                <span>Mkt Avg:</span>
                                <span>$<?= number_format($product['market_avg_price'] ?? $product['current_price'], 2) ?></span>
                            </div>
                            <div class="stat-row">
                                <span>Lowest:</span>
                                <span style="color:var(--green);">$<?= number_format($product['market_lowest_price'] ?? $product['current_price'], 2) ?></span>
                            </div>
                            <div class="stat-row">
                                <span>Savings:</span>
                                <span class="savings"><?= number_format(abs($product['price_difference_percent']), 1) ?>%</span>
                            </div>
                            <div class="stat-row">
                                <span>Sellers:</span>
                                <span><?= $product['competitor_count'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div style="margin:8px 0;">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-3);margin-bottom:4px;">
                            <span>Stock: <?= $product['stock'] ?></span>
                            <span><i class="fas fa-eye"></i> <?= $product['today_views'] ?? 0 ?></span>
                        </div>
                        <div class="stock-indicator">
                            <div class="stock-fill <?= $stock_class ?>"
                                 style="width:<?= $stock_width ?>%;"></div>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <a href="product_view.php?id=<?= $product['id'] ?>" class="btn-custom btn-outline">
                            <i class="fas fa-chart-line"></i> Analyze
                        </a>
                        <button class="btn-custom btn-primary-custom add-to-cart"
                                data-product-id="<?= $product['id'] ?>"
                                data-product-name="<?= htmlspecialchars($product['name']) ?>">
                            <i class="fas fa-cart-plus"></i> Add
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-custom">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>"
               class="page-link-custom <?= $page<=1 ? 'disabled':'' ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php
            $start = max(1, $page-2);
            $end   = min($total_pages, $page+2);
            if ($start > 1) { ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page'=>1])) ?>" class="page-link-custom">1</a>
                <?php if ($start > 2): ?><span class="page-link-custom" style="pointer-events:none;">…</span><?php endif; ?>
            <?php }
            for ($i=$start; $i<=$end; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"
                   class="page-link-custom <?= $i==$page ? 'active':'' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $total_pages): ?>
                <?php if ($end < $total_pages-1): ?><span class="page-link-custom" style="pointer-events:none;">…</span><?php endif; ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$total_pages])) ?>" class="page-link-custom"><?= $total_pages ?></a>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>"
               class="page-link-custom <?= $page>=$total_pages ? 'disabled':'' ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div style="text-align:center;padding:60px 20px;background:var(--bg-2);border-radius:var(--radius-lg);">
            <i class="fas fa-search" style="font-size:48px;color:var(--text-3);margin-bottom:20px;display:block;"></i>
            <h3 style="margin-bottom:10px;">No products found</h3>
            <p style="color:var(--text-2);margin-bottom:24px;font-size:14px;">Try adjusting your filters or search terms</p>
            <a href="product_list.php" class="btn-custom btn-primary-custom" style="padding:12px 24px;display:inline-flex;">
                <i class="fas fa-redo"></i> Reset Filters
            </a>
        </div>
        <?php endif; ?>

        <!-- Market Insights -->
        <div class="insights-card animate-in">
            <h5>
                <i class="fas fa-lightbulb" style="color:var(--amber);"></i> Market Insights
            </h5>
            <div class="insights-grid">
                <div class="insight-box">
                    <h6><i class="fas fa-fire" style="color:var(--red);"></i> Hot Products</h6>
                    <?php
                    $shown = 0;
                    foreach ($products as $hot):
                        if ($shown >= 3) break;
                        if ($hot['demand_level'] === 'critical' || $hot['demand_level'] === 'high'):
                            $shown++;
                    ?>
                    <div class="insight-row">
                        <span><?= htmlspecialchars($hot['name']) ?></span>
                        <span class="hot-badge">HOT</span>
                    </div>
                    <?php endif; endforeach;
                    if ($shown === 0): ?>
                        <p style="color:var(--text-3);font-size:12px;">No hot products right now</p>
                    <?php endif; ?>
                </div>
                <div class="insight-box">
                    <h6><i class="fas fa-piggy-bank" style="color:var(--green);"></i> Best Deals</h6>
                    <?php
                    $sorted_deals = $products;
                    usort($sorted_deals, fn($a,$b) => $b['price_difference_percent'] <=> $a['price_difference_percent']);
                    $deal_shown = 0;
                    foreach ($sorted_deals as $deal):
                        if ($deal_shown >= 3) break;
                        if ($deal['price_difference_percent'] > 0):
                            $deal_shown++;
                    ?>
                    <div class="insight-row">
                        <span><?= htmlspecialchars($deal['name']) ?></span>
                        <span class="deal-badge">-<?= number_format($deal['price_difference_percent'],1) ?>%</span>
                    </div>
                    <?php endif; endforeach;
                    if ($deal_shown === 0): ?>
                        <p style="color:var(--text-3);font-size:12px;">No discounts found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /products section -->
</div><!-- /layout-grid -->

<!-- Footer -->
<footer class="footer">
    <div class="footer-content">
        <div>
            <h6>GameTheory Pricing Platform</h6>
            <p>Real-time market competition simulation</p>
        </div>
        <div style="text-align:right;">
            <p><span class="live-dot"></span> Prices refresh every 5 min</p>
            <p style="margin-top:4px;"><?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
</footer>
</div><!-- /marketplace-container -->

<!-- ═══════════════════════════════════════════════════
     MOBILE BOTTOM NAV
═══════════════════════════════════════════════════ -->


<!-- ═══════════════════════════════════════════════════
     MOBILE FILTER DRAWER
═══════════════════════════════════════════════════ -->
<div class="filter-overlay" id="filterOverlay"></div>
<div class="filter-drawer" id="filterDrawer">
    <div class="drawer-handle"></div>
    <div class="drawer-header">
        <h4><i class="fas fa-sliders-h" style="color:var(--cyan);"></i> Filters & Sort</h4>
        <button class="drawer-close" id="closeFilterDrawer"><i class="fas fa-times"></i></button>
    </div>
    <div class="drawer-body">

        <!-- Category -->
        <div class="drawer-filter-section">
            <h6><i class="fas fa-tag"></i> Category</h6>
            <div class="drawer-category-grid">
                <a href="?<?= http_build_query(array_merge($_GET, ['category'=>'all','page'=>1])) ?>"
                   class="drawer-cat-btn <?= $category==='all' ? 'active':'' ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['category'=>$cat,'page'=>1])) ?>"
                   class="drawer-cat-btn <?= $category===$cat ? 'active':'' ?>">
                    <?= ucfirst($cat) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Price Range -->
        <div class="drawer-filter-section">
            <h6><i class="fas fa-dollar-sign"></i> Price Range</h6>
            <div style="display:flex;gap:10px;">
                <div style="flex:1;">
                    <label style="font-size:11px;color:var(--text-3);display:block;margin-bottom:5px;">Min Price</label>
                    <input type="number" class="price-input" id="drawerMinPrice"
                           placeholder="$0" value="<?= htmlspecialchars($min_price) ?>"
                           style="width:100%;">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px;color:var(--text-3);display:block;margin-bottom:5px;">Max Price</label>
                    <input type="number" class="price-input" id="drawerMaxPrice"
                           placeholder="Any" value="<?= htmlspecialchars($max_price) ?>"
                           style="width:100%;">
                </div>
            </div>
        </div>

        <!-- Demand Level -->
        <div class="drawer-filter-section">
            <h6><i class="fas fa-fire"></i> Demand Level</h6>
            <div class="drawer-demand-grid">
                <?php foreach (['all'=>'All','low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $key=>$label): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['demand'=>$key,'page'=>1])) ?>"
                   class="drawer-demand-btn <?= $demand_level===$key ? 'active':'' ?>">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sort -->
        <div class="drawer-filter-section">
            <h6><i class="fas fa-sort"></i> Sort By</h6>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ([
                    'popular'    => 'Most Popular',
                    'price_low'  => 'Price: Low → High',
                    'price_high' => 'Price: High → Low',
                    'demand'     => 'High Demand First',
                    'savings'    => 'Best Savings',
                    'newest'     => 'Newest First'
                ] as $val => $label): ?>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 12px;border-radius:var(--radius);background:var(--bg-3);border:1px solid var(--border);">
                    <input type="radio" name="drawerSort" value="<?= $val ?>"
                           <?= $sort===$val ? 'checked':'' ?>
                           style="accent-color:var(--cyan);width:16px;height:16px;">
                    <span style="font-size:13px;color:var(--text-2);"><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /drawer-body -->
    <div class="drawer-footer">
        <a href="product_list.php" class="drawer-reset-btn">
            <i class="fas fa-redo"></i> Reset
        </a>
        <button class="drawer-apply-btn" id="applyDrawerFilters">
            Apply Filters
        </button>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     CART MODAL
═══════════════════════════════════════════════════ -->
<div id="cartModal" class="modal-custom">
    <div class="modal-inner">
        <div class="modal-icon"><i class="fas fa-check-circle"></i></div>
        <div class="modal-title">Added to Cart!</div>
        <p class="modal-sub" id="cartMessage">Item has been added to your cart</p>
        <div class="modal-btns">
            <button onclick="closeModal()" class="btn-custom btn-outline">Keep Shopping</button>
            <a href="cart.php" class="btn-custom btn-primary-custom">View Cart</a>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════ */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

/* Toast */
function showToast(message, type = 'success') {
    const container = $('#toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"
           style="color:${type === 'success' ? 'var(--green)' : 'var(--red)'};font-size:16px;"></i>
        <span>${message}</span>
    `;
    container.appendChild(toast);
    requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add('show'));
    });
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 2800);
}

/* ═══════════════════════════════════════════════════
   DESKTOP DROPDOWN
═══════════════════════════════════════════════════ */
const desktopTrigger = $('#desktopUserDropdown');
const desktopMenu    = $('#desktopDropdownMenu');

if (desktopTrigger && desktopMenu) {
    desktopTrigger.addEventListener('click', e => {
        desktopMenu.classList.toggle('open');
        e.stopPropagation();
    });
    document.addEventListener('click', () => desktopMenu.classList.remove('open'));
}

/* ═══════════════════════════════════════════════════
   DESKTOP FILTERS
═══════════════════════════════════════════════════ */
function applyFilters() {
    const min  = $('#minPrice')?.value || '';
    const max  = $('#maxPrice')?.value || '';
    const sort = $('#sortSelect')?.value || '';
    const url  = new URL(window.location.href);
    min  ? url.searchParams.set('min_price', min) : url.searchParams.delete('min_price');
    max  ? url.searchParams.set('max_price', max) : url.searchParams.delete('max_price');
    url.searchParams.set('sort', sort);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

$('#minPrice')?.addEventListener('change', applyFilters);
$('#maxPrice')?.addEventListener('change', applyFilters);
$('#sortSelect')?.addEventListener('change', applyFilters);

/* ═══════════════════════════════════════════════════
   MOBILE SORT CHIPS
═══════════════════════════════════════════════════ */
function setMobileSort(value, el) {
    $$('.sort-chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    const url = new URL(window.location.href);
    url.searchParams.set('sort', value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

/* ═══════════════════════════════════════════════════
   FILTER DRAWER (mobile)
═══════════════════════════════════════════════════ */
const filterOverlay = $('#filterOverlay');
const filterDrawer  = $('#filterDrawer');

function openFilterDrawer() {
    filterOverlay.style.display = 'block';
    filterDrawer.style.display  = 'block';
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            filterOverlay.classList.add('visible');
            filterDrawer.classList.add('open');
        });
    });
    document.body.style.overflow = 'hidden';
}

function closeFilterDrawer() {
    filterOverlay.classList.remove('visible');
    filterDrawer.classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => {
        filterOverlay.style.display = 'none';
        filterDrawer.style.display  = 'none';
    }, 400);
}

$('#openFilterDrawer')?.addEventListener('click', openFilterDrawer);
$('#closeFilterDrawer')?.addEventListener('click', closeFilterDrawer);
filterOverlay?.addEventListener('click', closeFilterDrawer);

// Swipe down to close
let drawerStartY = 0;
filterDrawer?.addEventListener('touchstart', e => {
    drawerStartY = e.touches[0].clientY;
}, { passive: true });
filterDrawer?.addEventListener('touchend', e => {
    const delta = e.changedTouches[0].clientY - drawerStartY;
    if (delta > 80) closeFilterDrawer();
}, { passive: true });

/* Apply drawer filters */
$('#applyDrawerFilters')?.addEventListener('click', () => {
    const minP = $('#drawerMinPrice')?.value || '';
    const maxP = $('#drawerMaxPrice')?.value || '';
    const sortVal = $('input[name="drawerSort"]:checked')?.value || '';
    const url = new URL(window.location.href);
    minP  ? url.searchParams.set('min_price', minP) : url.searchParams.delete('min_price');
    maxP  ? url.searchParams.set('max_price', maxP) : url.searchParams.delete('max_price');
    if (sortVal) url.searchParams.set('sort', sortVal);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
});

/* ═══════════════════════════════════════════════════
   VIEW TOGGLE
═══════════════════════════════════════════════════ */
const productsGrid  = $('#productsGrid');
const gridBtn       = $('#gridView');
const compactBtn    = $('#compactView');
let currentView     = localStorage.getItem('productView') || 'grid';

function setView(view) {
    if (view === 'compact') {
        productsGrid?.classList.add('compact');
        gridBtn?.classList.remove('active');
        compactBtn?.classList.add('active');
    } else {
        productsGrid?.classList.remove('compact');
        gridBtn?.classList.add('active');
        compactBtn?.classList.remove('active');
    }
    localStorage.setItem('productView', view);
}

setView(currentView);
gridBtn?.addEventListener('click',    () => setView('grid'));
compactBtn?.addEventListener('click', () => setView('compact'));

/* ═══════════════════════════════════════════════════
   ADD TO CART
═══════════════════════════════════════════════════ */
const cartModal = $('#cartModal');

function closeModal() {
    cartModal.classList.remove('visible');
    setTimeout(() => cartModal.style.display = 'none', 350);
}
window.closeModal = closeModal;

cartModal?.addEventListener('click', e => {
    if (e.target === cartModal) closeModal();
});

$$('.add-to-cart').forEach(button => {
    button.addEventListener('click', function () {
        const productId   = this.dataset.productId;
        const productName = this.dataset.productName;

        // Optimistic UI: button animation
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding';
        this.disabled  = true;
        const btn = this;

        fetch('add_to_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ product_id: productId, quantity: 1 })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Update cart counts across nav elements
                $$('.cart-count-badge, .badge').forEach(b => {
                    b.textContent = parseInt(b.textContent || '0') + 1;
                });
                $$('.nav-badge').forEach(b => {
                    b.textContent = parseInt(b.textContent || '0') + 1;
                });

                // Show modal
                $('#cartMessage').textContent = `"${productName}" added to your cart!`;
                cartModal.style.display = 'flex';
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => cartModal.classList.add('visible'));
                });

                showToast(`${productName} added!`, 'success');
            } else {
                showToast(data.message || 'Error adding to cart', 'error');
            }
        })
        .catch(() => showToast('Network error, try again', 'error'))
        .finally(() => {
            btn.innerHTML = '<i class="fas fa-cart-plus"></i> Add';
            btn.disabled  = false;
        });
    });
});

/* ═══════════════════════════════════════════════════
   SMOOTH SCROLL & INTERSECTION OBSERVER
═══════════════════════════════════════════════════ */
if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.style.opacity = '1';
                e.target.style.transform = 'translateY(0)';
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    $$('.product-card').forEach((card, i) => {
        card.style.opacity   = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = `opacity 0.4s var(--ease), transform 0.4s var(--ease)`;
        card.style.transitionDelay = `${Math.min(i * 0.04, 0.3)}s`;
        io.observe(card);
    });
}

/* ═══════════════════════════════════════════════════
   PRICE TICK — visually flash updated prices
═══════════════════════════════════════════════════ */
function tickPrices() {
    $$('.price-tag').forEach(tag => {
        tag.classList.add('price-updated');
        setTimeout(() => tag.classList.remove('price-updated'), 700);
    });
}

/* Auto-refresh every 5 min with visual feedback */
let refreshTimer = setTimeout(() => {
    tickPrices();
    setTimeout(() => location.reload(), 1000);
}, 300000);

/* ═══════════════════════════════════════════════════
   HAPTIC FEEDBACK (mobile)
═══════════════════════════════════════════════════ */
function haptic(type = 'light') {
    if ('vibrate' in navigator) {
        const patterns = { light: 10, medium: 20, heavy: 40 };
        navigator.vibrate(patterns[type] || 10);
    }
}

$$('.add-to-cart, .bottom-nav-item').forEach(el => {
    el.addEventListener('click', () => haptic('light'));
});

/* ═══════════════════════════════════════════════════
   MOBILE USER MENU (sheet)
═══════════════════════════════════════════════════ */
$('#mobileUserBtn')?.addEventListener('click', () => {
    // Simple user menu - you can extend this to a full drawer
    const items = [
        { href: 'profile.php',  icon: 'user',          label: 'Profile' },
        { href: 'wallet.php',   icon: 'wallet',        label: 'Wallet' },
        { href: 'logout.php',   icon: 'sign-out-alt',  label: 'Logout' },
    ];
    const overlay = document.createElement('div');
    overlay.className = 'filter-overlay';
    overlay.style.display = 'block';
    overlay.style.zIndex = '400';

    const sheet = document.createElement('div');
    sheet.className = 'filter-drawer';
    sheet.style.display = 'block';
    sheet.innerHTML = `
        <div class="drawer-handle"></div>
        <div class="drawer-header">
            <h4 style="font-size:16px;">
                <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--purple));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;"><?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?></div>
                <?= htmlspecialchars($_SESSION['user_name']) ?>
            </h4>
            <button class="drawer-close" id="closeUserSheet"><i class="fas fa-times"></i></button>
        </div>
        <div class="drawer-body">
            ${items.map(i => `
                <a href="${i.href}" style="display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--border);color:var(--text-1);text-decoration:none;font-size:15px;">
                    <div style="width:36px;height:36px;border-radius:10px;background:var(--bg-3);display:flex;align-items:center;justify-content:center;color:var(--cyan);">
                        <i class="fas fa-${i.icon}"></i>
                    </div>
                    ${i.label}
                    <i class="fas fa-chevron-right" style="margin-left:auto;color:var(--text-3);font-size:12px;"></i>
                </a>
            `).join('')}
        </div>
    `;

    document.body.appendChild(overlay);
    document.body.appendChild(sheet);
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => requestAnimationFrame(() => {
        overlay.classList.add('visible');
        sheet.classList.add('open');
    }));

    function closeUserSheet() {
        overlay.classList.remove('visible');
        sheet.classList.remove('open');
        document.body.style.overflow = '';
        setTimeout(() => { overlay.remove(); sheet.remove(); }, 400);
    }

    overlay.addEventListener('click', closeUserSheet);
    sheet.querySelector('#closeUserSheet')?.addEventListener('click', closeUserSheet);
});

/* ═══════════════════════════════════════════════════
   PULL-TO-REFRESH INDICATOR (mobile)
═══════════════════════════════════════════════════ */
let ptrStart = 0;
let ptrActive = false;

document.addEventListener('touchstart', e => {
    if (window.scrollY === 0) ptrStart = e.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', e => {
    if (ptrActive) {
        ptrActive = false;
        if ((e.changedTouches[0].clientY - ptrStart) > 70 && window.scrollY === 0) {
            showToast('Refreshing...', 'success');
            setTimeout(() => location.reload(), 400);
        }
    }
}, { passive: true });

document.addEventListener('touchmove', e => {
    if (window.scrollY === 0 && (e.touches[0].clientY - ptrStart) > 20) {
        ptrActive = true;
    }
}, { passive: true });
</script>
</body>
</html>