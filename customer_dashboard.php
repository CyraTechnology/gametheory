<?php
require_once '../db.php';
require_once 'customer_auth.php';

// --------------------
// Basic auth safety
// --------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// --------------------
// User data
// --------------------
$user_id = (int) $_SESSION['user_id'];

$user = [
    'id'         => $_SESSION['user_id'],
    'name'       => $_SESSION['user_name'],
    'email'      => $_SESSION['user_email'],
    'role'       => $_SESSION['user_role'],
    'created_at' => $_SESSION['created_at'] ?? date('Y-m-d')
];

// --------------------
// User statistics
// --------------------
$stats_sql = "
    SELECT 
        COUNT(DISTINCT po.id) AS total_orders,
        COALESCE(SUM(po.total_price), 0) AS total_spent,
        AVG(po.customer_satisfaction) AS avg_satisfaction,
        COUNT(DISTINCT po.product_id) AS unique_products,
        (
            SELECT COUNT(*) 
            FROM purchase_orders 
            WHERE user_id = ? AND status = 'delivered'
        ) AS delivered_orders
    FROM purchase_orders po
    WHERE po.user_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute([$user_id, $user_id]);
$user_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --------------------
// Recent orders
// --------------------
$orders_sql = "
    SELECT 
        po.*,
        p.name AS product_name,
        p.category,
        c.name AS company_name,
        cp.price AS competitor_avg
    FROM purchase_orders po
    JOIN products p ON po.product_id = p.id
    LEFT JOIN companies c ON po.company_id = c.id
    LEFT JOIN (
        SELECT product_id, AVG(price) AS price
        FROM competitor_prices
        GROUP BY product_id
    ) cp ON po.product_id = cp.product_id
    WHERE po.user_id = ?
    ORDER BY po.ordered_at DESC
    LIMIT 5
";

$orders_stmt = $conn->prepare($orders_sql);
$orders_stmt->execute([$user_id]);
$recent_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------
// Price alerts (safe fallback)
// --------------------
$price_alerts = [];

try {
    $alerts_sql = "
        SELECT 
            pa.*,
            p.name AS product_name,
            p.current_price,
            cp.competitor_name,
            cp.price AS competitor_price,
            ROUND(((cp.price - pa.tracked_price) / pa.tracked_price * 100), 2) AS price_change_percent
        FROM price_alerts pa
        JOIN products p ON pa.product_id = p.id
        JOIN competitor_prices cp 
            ON pa.product_id = cp.product_id 
           AND pa.competitor_id = cp.company_id
        WHERE pa.user_id = ?
          AND pa.is_active = 1
          AND cp.price < pa.tracked_price * 0.95
        ORDER BY price_change_percent DESC
        LIMIT 5
    ";

    $alerts_stmt = $conn->prepare($alerts_sql);
    $alerts_stmt->execute([$user_id]);
    $price_alerts = $alerts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $price_alerts = [];
}

// --------------------
// Demand patterns
// --------------------
$demand_patterns = [];

try {
    $patterns_sql = "
        SELECT 
            dl.product_id,
            p.name AS product_name,
            COUNT(*) AS view_count,
            SUM(CASE WHEN dl.purchases > 0 THEN 1 ELSE 0 END) AS purchase_count,
            DAYNAME(dl.log_date) AS day_of_week,
            HOUR(dl.created_at) AS hour_of_day
        FROM demand_logs dl
        JOIN products p ON dl.product_id = p.id
        WHERE dl.log_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY dl.product_id, DAYNAME(dl.log_date), HOUR(dl.created_at)
        ORDER BY view_count DESC
        LIMIT 10
    ";

    $patterns_stmt = $conn->prepare($patterns_sql);
    $patterns_stmt->execute();
    $demand_patterns = $patterns_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $demand_patterns = [];
}

// --------------------
// Market events
// --------------------
$events_sql = "
    SELECT 
        me.*,
        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS affected_products_list
    FROM market_events me
    LEFT JOIN products p 
        ON me.affected_products LIKE CONCAT('%\"', p.id, '\"%')
    WHERE me.is_active = 1
      AND me.start_date <= CURDATE()
      AND (me.end_date IS NULL OR me.end_date >= CURDATE())
    GROUP BY me.id
    ORDER BY 
        CASE me.severity
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            ELSE 4
        END
    LIMIT 3
";

$events_stmt = $conn->prepare($events_sql);
$events_stmt->execute();
$market_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------
// Recommendations
// --------------------
$recommendations_sql = "
    SELECT 
        p.*,
        cp.competitor_name,
        cp.price AS competitor_price,
        cp.demand_level,
        ROUND((p.current_price - cp.price) / cp.price * 100, 2) AS price_difference_percent,
        (
            SELECT COUNT(*) 
            FROM purchase_orders 
            WHERE product_id = p.id AND user_id = ?
        ) AS previously_purchased
    FROM products p
    JOIN competitor_prices cp ON p.id = cp.product_id
    WHERE p.is_active = 1
      AND p.stock > 0
      AND cp.price < p.current_price * 0.95
      AND cp.demand_level IN ('high', 'critical')
    ORDER BY cp.demand_level DESC, price_difference_percent DESC
    LIMIT 6
";

$rec_stmt = $conn->prepare($recommendations_sql);
$rec_stmt->execute([$user_id]);
$recommendations = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------
// Price sensitivity
// --------------------
$sensitivity_sql = "
    SELECT 
        CASE 
            WHEN GROUP_CONCAT(price_perception) LIKE '%cheap%' THEN 'High - Price Sensitive'
            WHEN GROUP_CONCAT(price_perception) LIKE '%expensive%' THEN 'Low - Premium Focused'
            ELSE 'Medium - Balanced'
        END
    FROM purchase_orders
    WHERE user_id = ?
      AND price_perception IS NOT NULL
";

$sens_stmt = $conn->prepare($sensitivity_sql);
$sens_stmt->execute([$user_id]);
$price_sensitivity = $sens_stmt->fetchColumn() ?: 'Medium - Balanced';

// --------------------
// Cart count
// --------------------
$cart_sql = "SELECT COUNT(*) FROM user_cart_items WHERE user_id = ?";
$cart_stmt = $conn->prepare($cart_sql);
$cart_stmt->execute([$user_id]);
$cart_count = (int) $cart_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Customer Dashboard — GameTheory Pricing</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
    /* ═══════════════════════════════════
       DESIGN SYSTEM — MATCHING LOGIN PAGE
    ═══════════════════════════════════ */
    :root {
        --bg:        #07090f;
        --bg-2:      #0d1018;
        --bg-3:      #131720;
        --bg-4:      #1a2030;
        --bg-5:      #1f2638;

        --border:    rgba(255,255,255,0.07);
        --border-2:  rgba(255,255,255,0.12);
        --border-c:  rgba(0,229,255,0.3);

        --cyan:      #00e5ff;
        --cyan-dim:  rgba(0,229,255,0.1);
        --cyan-glow: rgba(0,229,255,0.22);
        --green:     #00e676;
        --green-dim: rgba(0,230,118,0.1);
        --red:       #ff4f4f;
        --red-dim:   rgba(255,79,79,0.1);
        --amber:     #ffb300;
        --amber-dim: rgba(255,179,0,0.1);
        --purple:    #9b6dff;
        --purple-dim: rgba(155,109,255,0.1);

        --text-1:    #eef1f7;
        --text-2:    #8b90a0;
        --text-3:    #4a5068;
        --text-mono: #b0e0ff;

        --radius:    13px;
        --radius-lg: 20px;
        --ease:      cubic-bezier(0.23,1,0.32,1);

        --font-d: 'Syne', sans-serif;
        --font-b: 'Outfit', sans-serif;
        --font-m: 'IBM Plex Mono', monospace;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: var(--font-b);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        overflow-x: hidden;
        position: relative;
    }

    /* ── Scanline effect ── */
    body::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        background: repeating-linear-gradient(
            0deg, transparent, transparent 2px,
            rgba(0,0,0,0.06) 2px, rgba(0,0,0,0.06) 4px
        );
    }

    /* ── Ambient glows ── */
    .amb {
        position: fixed;
        border-radius: 50%;
        filter: blur(90px);
        pointer-events: none;
        z-index: 0;
    }
    .amb-1 {
        width: 550px;
        height: 550px;
        background: radial-gradient(circle, rgba(0,229,255,0.07) 0%, transparent 70%);
        top: -180px;
        left: -180px;
        animation: adrift 22s ease-in-out infinite alternate;
    }
    .amb-2 {
        width: 420px;
        height: 420px;
        background: radial-gradient(circle, rgba(155,109,255,0.05) 0%, transparent 70%);
        bottom: -120px;
        right: -120px;
        animation: adrift 28s ease-in-out infinite alternate-reverse;
    }
    @keyframes adrift {
        from { transform: translate(0,0) scale(1); }
        to   { transform: translate(60px,50px) scale(1.12); }
    }

    /* ── Grid overlay ── */
    .grid-bg {
        position: fixed;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        background-image:
            linear-gradient(rgba(0,229,255,0.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,229,255,0.025) 1px, transparent 1px);
        background-size: 56px 56px;
        mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 30%, transparent 100%);
    }

    /* ══════════════════════════════════
       MAIN CONTAINER
    ══════════════════════════════════ */
    .dashboard-container {
        position: relative;
        z-index: 1;
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px 24px;
    }

    /* ══════════════════════════════════
       NAVIGATION
    ══════════════════════════════════ */
    .glass-nav {
        background: rgba(13, 16, 24, 0.85);
        backdrop-filter: blur(12px);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 12px 24px;
        margin-bottom: 28px;
        position: sticky;
        top: 16px;
        z-index: 100;
        transition: all 0.3s ease;
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
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: var(--cyan-dim);
        border: 1px solid var(--border-c);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--cyan);
        font-size: 18px;
    }

    .brand-name {
        font-family: var(--font-d);
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--text-1);
    }
    .brand-name span { color: var(--cyan); }

    .nav-links {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .nav-link-custom {
        color: var(--text-2);
        text-decoration: none;
        padding: 8px 16px;
        border-radius: var(--radius);
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .nav-link-custom:hover, .nav-link-custom.active {
        color: var(--cyan);
        background: var(--cyan-dim);
    }

    .cart-badge {
        position: relative;
    }

    .cart-count {
        position: absolute;
        top: -6px;
        right: -6px;
        background: var(--red);
        color: white;
        font-size: 10px;
        font-weight: bold;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .user-menu {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-left: 16px;
        border-left: 1px solid var(--border);
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--cyan), var(--purple));
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 16px;
    }

    .dropdown-custom {
        position: relative;
        display: inline-block;
    }

    .dropdown-trigger {
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: var(--radius);
        transition: background 0.2s;
    }

    .dropdown-trigger:hover {
        background: var(--bg-4);
    }

    .dropdown-menu-custom {
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 8px;
        background: var(--bg-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        min-width: 200px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.2s ease;
        z-index: 1000;
    }

    .dropdown-custom:hover .dropdown-menu-custom {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .dropdown-item-custom {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        color: var(--text-2);
        text-decoration: none;
        font-size: 13px;
        transition: all 0.2s;
    }

    .dropdown-item-custom:hover {
        background: var(--bg-4);
        color: var(--cyan);
    }

    /* ══════════════════════════════════
       WELCOME SECTION
    ══════════════════════════════════ */
    .welcome-section {
        background: var(--bg-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 32px 40px;
        margin-bottom: 32px;
        position: relative;
        overflow: hidden;
    }

    .welcome-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, var(--cyan), transparent 60%);
    }

    .welcome-content {
        position: relative;
        z-index: 1;
    }

    .welcome-title {
        font-family: var(--font-d);
        font-size: 28px;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin-bottom: 12px;
    }

    .sensitivity-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--purple-dim);
        border: 1px solid rgba(155,109,255,0.3);
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 13px;
        margin-top: 12px;
    }

    /* ══════════════════════════════════
       STATS GRID
    ══════════════════════════════════ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .stat-card {
        background: var(--bg-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 24px;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        border-color: var(--border-c);
        box-shadow: 0 10px 30px rgba(0,229,255,0.05);
    }

    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 18px;
    }

    .stat-icon.orders { background: rgba(0,229,255,0.1); color: var(--cyan); }
    .stat-icon.spent { background: rgba(0,230,118,0.1); color: var(--green); }
    .stat-icon.satisfaction { background: rgba(255,179,0,0.1); color: var(--amber); }
    .stat-icon.products { background: rgba(155,109,255,0.1); color: var(--purple); }

    .stat-value {
        font-family: var(--font-d);
        font-size: 32px;
        font-weight: 800;
        margin-bottom: 6px;
    }

    .stat-label {
        color: var(--text-2);
        font-size: 13px;
        margin-bottom: 8px;
    }

    .stat-sub {
        font-size: 11px;
        color: var(--text-3);
        font-family: var(--font-m);
    }

    /* ══════════════════════════════════
       QUICK ACTIONS
    ══════════════════════════════════ */
    .section-title {
        font-family: var(--font-d);
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 32px;
    }

    .action-card {
        background: var(--bg-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        text-align: center;
        text-decoration: none;
        transition: all 0.3s ease;
        display: block;
    }

    .action-card:hover {
        transform: translateY(-3px);
        border-color: var(--border-c);
        background: var(--bg-4);
    }

    .action-icon {
        font-size: 32px;
        margin-bottom: 12px;
        display: inline-block;
    }

    .action-title {
        color: var(--text-1);
        font-weight: 600;
        margin-bottom: 6px;
        font-size: 14px;
    }

    .action-desc {
        color: var(--text-3);
        font-size: 11px;
    }

    /* ══════════════════════════════════
       MAIN LAYOUT (2-COLUMN)
    ══════════════════════════════════ */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 360px;
        gap: 28px;
        margin-bottom: 32px;
    }

    @media (max-width: 900px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Cards */
    .info-card {
        background: var(--bg-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        margin-bottom: 28px;
    }

    .card-header-custom {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .card-header-custom h5 {
        font-family: var(--font-d);
        font-size: 18px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-body-custom {
        padding: 24px;
    }

    /* Orders Table */
    .orders-table {
        width: 100%;
        border-collapse: collapse;
    }

    .orders-table th {
        text-align: left;
        padding: 12px 8px;
        color: var(--text-3);
        font-family: var(--font-m);
        font-size: 11px;
        font-weight: 500;
        letter-spacing: 0.05em;
        border-bottom: 1px solid var(--border);
    }

    .orders-table td {
        padding: 14px 8px;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
    }

    .order-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    .status-pending { background: rgba(255,179,0,0.15); color: var(--amber); }
    .status-processing { background: rgba(0,229,255,0.15); color: var(--cyan); }
    .status-shipped { background: rgba(0,230,118,0.15); color: var(--green); }
    .status-delivered { background: rgba(155,109,255,0.15); color: var(--purple); }

    /* Products Grid */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
    }

    .product-card {
        background: var(--bg-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 16px;
        transition: all 0.3s ease;
    }

    .product-card:hover {
        border-color: var(--border-c);
        transform: translateY(-2px);
    }

    .product-name {
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 8px;
    }

    .product-price {
        font-size: 18px;
        font-weight: 700;
        color: var(--cyan);
        margin-bottom: 6px;
    }

    .product-save {
        font-size: 11px;
        color: var(--green);
        margin-bottom: 8px;
    }

    .demand-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        background: var(--red-dim);
        color: var(--red);
    }

    /* Alert Items */
    .alert-item {
        padding: 14px 0;
        border-bottom: 1px solid var(--border);
    }
    .alert-item:last-child { border-bottom: none; }

    .alert-product {
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 6px;
    }

    .alert-price {
        font-size: 12px;
        color: var(--green);
    }

    .event-item {
        padding: 14px;
        background: var(--bg-3);
        border-radius: var(--radius);
        margin-bottom: 12px;
        border-left: 3px solid;
    }
    .event-critical { border-left-color: var(--red); }
    .event-high { border-left-color: #fd7e14; }
    .event-medium { border-left-color: var(--amber); }
    .event-low { border-left-color: var(--green); }

    /* Activity List */
    .activity-list {
        list-style: none;
    }
    .activity-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid var(--border);
    }
    .activity-name {
        font-size: 13px;
    }
    .activity-count {
        background: var(--bg-4);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
        font-family: var(--font-m);
    }

    /* Insights Card */
    .insights-card {
        background: linear-gradient(135deg, var(--bg-2), var(--bg-3));
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 28px;
        margin-bottom: 32px;
    }

    .insights-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-top: 20px;
    }

    @media (max-width: 600px) {
        .insights-grid {
            grid-template-columns: 1fr;
        }
    }

    .progress-bar-custom {
        height: 8px;
        background: var(--bg-4);
        border-radius: 4px;
        overflow: hidden;
        margin: 8px 0;
    }
    .progress-fill {
        height: 100%;
        border-radius: 4px;
        background: var(--cyan);
        transition: width 0.5s ease;
    }

    /* Footer */
    .footer {
        background: var(--bg-2);
        border-top: 1px solid var(--border);
        padding: 24px 0;
        margin-top: 40px;
    }

    .footer-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }

    /* Responsive */
    @media (max-width: 700px) {
        .dashboard-container {
            padding: 16px;
        }
        .welcome-section {
            padding: 24px;
        }
        .welcome-title {
            font-size: 24px;
        }
        .stat-value {
            font-size: 24px;
        }
        .card-header-custom {
            flex-direction: column;
            align-items: flex-start;
        }
        .nav-content {
            flex-direction: column;
        }
        .nav-links {
            width: 100%;
            justify-content: center;
        }
        .user-menu {
            border-left: none;
            padding-left: 0;
        }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-in {
        animation: fadeInUp 0.5s var(--ease) both;
    }
    </style>
</head>
<body>

<div class="amb amb-1"></div>
<div class="amb amb-2"></div>
<div class="grid-bg"></div>

<div class="dashboard-container">
    
    <!-- Navigation -->
    <nav class="glass-nav animate-in">
        <div class="nav-content">
            <a href="../index.php" class="nav-brand">
                <div class="brand-icon"><i class="fas fa-chess-board"></i></div>
                <div class="brand-name">Game<span>Theory</span></div>
            </a>
            
            <div class="nav-links">
                <a href="customer_dashboard.php" class="nav-link-custom active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="product_list.php" class="nav-link-custom cart-badge">
                    <i class="fas fa-shopping-cart"></i> Shop
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-count"><?= $cart_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="my_orders.php" class="nav-link-custom">
                    <i class="fas fa-receipt"></i> Orders
                </a>
                <a href="price_alerts.php" class="nav-link-custom">
                    <i class="fas fa-bell"></i> Alerts
                </a>
            </div>
            
            <div class="user-menu">
                <div class="user-avatar">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <div class="dropdown-custom">
                    <div class="dropdown-trigger">
                        <span><?= htmlspecialchars($user['name']) ?></span>
                        <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
                    </div>
                    <div class="dropdown-menu-custom">
                        <a href="profile.php" class="dropdown-item-custom">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="wallet.php" class="dropdown-item-custom">
                            <i class="fas fa-wallet"></i> Wallet
                        </a>
                        <div class="dropdown-divider" style="height:1px; background:var(--border); margin:6px 0;"></div>
                        <a href="logout.php" class="dropdown-item-custom">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Welcome Section -->
    <div class="welcome-section animate-in">
        <div class="welcome-content">
            <h1 class="welcome-title">Welcome back, <?= htmlspecialchars($user['name']) ?>!</h1>
            <div class="sensitivity-badge">
                <i class="fas fa-chart-line"></i>
                <span>Price Sensitivity: <strong><?= $price_sensitivity ?></strong></span>
                <i class="fas fa-info-circle" style="font-size: 11px;"></i>
            </div>
            <p style="color: var(--text-2); margin-top: 16px; font-size: 14px;">
                <i class="fas fa-calendar-alt"></i> Member since <?= date('F Y', strtotime($user['created_at'])) ?>
            </p>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card animate-in">
            <div class="stat-icon orders"><i class="fas fa-shopping-bag"></i></div>
            <div class="stat-value"><?= $user_stats['total_orders'] ?? 0 ?></div>
            <div class="stat-label">Total Orders</div>
            <div class="stat-sub"><?= $user_stats['delivered_orders'] ?? 0 ?> delivered</div>
        </div>
        <div class="stat-card animate-in">
            <div class="stat-icon spent"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-value">$<?= number_format($user_stats['total_spent'] ?? 0, 0) ?></div>
            <div class="stat-label">Total Spent</div>
            <div class="stat-sub">Avg: $<?= $user_stats['total_orders'] > 0 ? number_format($user_stats['total_spent'] / $user_stats['total_orders'], 2) : '0.00' ?></div>
        </div>
        <div class="stat-card animate-in">
            <div class="stat-icon satisfaction"><i class="fas fa-smile"></i></div>
            <div class="stat-value"><?= $user_stats['avg_satisfaction'] ? number_format($user_stats['avg_satisfaction'], 1) . '/5' : 'N/A' ?></div>
            <div class="stat-label">Avg Satisfaction</div>
            <div class="stat-sub"><?= $user_stats['avg_satisfaction'] >= 4 ? '⭐ Happy Customer' : '📊 Needs Improvement' ?></div>
        </div>
        <div class="stat-card animate-in">
            <div class="stat-icon products"><i class="fas fa-box-open"></i></div>
            <div class="stat-value"><?= $user_stats['unique_products'] ?? 0 ?></div>
            <div class="stat-label">Unique Products</div>
            <div class="stat-sub">Last purchase: <?= count($recent_orders) > 0 ? date('M d', strtotime($recent_orders[0]['ordered_at'])) : 'Never' ?></div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="section-title">
        <i class="fas fa-bolt" style="color: var(--cyan);"></i>
        <span>Quick Actions</span>
    </div>
    <div class="actions-grid">
        <a href="product_list.php" class="action-card">
            <div class="action-icon"><i class="fas fa-search"></i></div>
            <div class="action-title">Browse Products</div>
            <div class="action-desc">View real-time prices</div>
        </a>
        <a href="my_orders.php" class="action-card">
            <div class="action-icon"><i class="fas fa-receipt"></i></div>
            <div class="action-title">My Orders</div>
            <div class="action-desc">Track your purchases</div>
        </a>
        <a href="../index.php#live-market" class="action-card">
            <div class="action-icon"><i class="fas fa-chart-line"></i></div>
            <div class="action-title">Live Market</div>
            <div class="action-desc">Watch price wars</div>
        </a>
        <a href="wallet.php" class="action-card">
            <div class="action-icon"><i class="fas fa-wallet"></i></div>
            <div class="action-title">Wallet</div>
            <div class="action-desc">Check your balance</div>
        </a>
        <a href="price_alerts.php" class="action-card">
            <div class="action-icon"><i class="fas fa-bell"></i></div>
            <div class="action-title">Price Alerts</div>
            <div class="action-desc">Set up notifications</div>
        </a>
    </div>

    <!-- Main Grid -->
    <div class="dashboard-grid">
        <!-- Left Column -->
        <div>
            <!-- Recent Orders -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5><i class="fas fa-history" style="color: var(--cyan);"></i> Recent Orders</h5>
                    <a href="my_orders.php" style="color: var(--cyan); text-decoration: none; font-size: 13px;">View All →</a>
                </div>
                <div class="card-body-custom">
                    <?php if (count($recent_orders) > 0): ?>
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td>
                                        <a href="order_details.php?id=<?= $order['id'] ?>" style="color: var(--cyan); text-decoration: none;">
                                            <?= $order['order_number'] ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($order['product_name']) ?></td>
                                    <td>$<?= number_format($order['unit_price'], 2) ?></td>
                                    <td>
                                        <span class="order-status status-<?= $order['status'] ?>">
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px;">
                            <i class="fas fa-shopping-cart" style="font-size: 48px; color: var(--text-3); margin-bottom: 16px; display: inline-block;"></i>
                            <p style="color: var(--text-2);">No orders yet</p>
                            <a href="product_list.php" style="display: inline-block; margin-top: 16px; background: var(--cyan); color: #000; padding: 10px 24px; border-radius: var(--radius); text-decoration: none; font-weight: 600;">Start Shopping</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Personalized Recommendations -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5><i class="fas fa-star" style="color: var(--amber);"></i> Recommended For You</h5>
                </div>
                <div class="card-body-custom">
                    <?php if (count($recommendations) > 0): ?>
                        <div class="products-grid">
                            <?php foreach ($recommendations as $product): ?>
                                <div class="product-card">
                                    <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                    <div class="product-price">$<?= number_format($product['competitor_price'], 2) ?></div>
                                    <div class="product-save">
                                        <i class="fas fa-tag"></i> Save <?= number_format(abs($product['price_difference_percent']), 1) ?>%
                                    </div>
                                    <div class="demand-badge">
                                        <i class="fas fa-fire"></i> <?= ucfirst($product['demand_level']) ?> Demand
                                    </div>
                                    <a href="product_view.php?id=<?= $product['id'] ?>" style="display: block; margin-top: 12px; background: var(--cyan-dim); color: var(--cyan); text-align: center; padding: 8px; border-radius: var(--radius); text-decoration: none; font-size: 12px;">View Details</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px;">
                            <p style="color: var(--text-2);">Complete your profile for better recommendations</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Price Alerts -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5><i class="fas fa-bell" style="color: var(--amber);"></i> Price Drop Alerts</h5>
                </div>
                <div class="card-body-custom">
                    <?php if (count($price_alerts) > 0): ?>
                        <?php foreach ($price_alerts as $alert): ?>
                            <div class="alert-item">
                                <div class="alert-product"><?= htmlspecialchars($alert['product_name']) ?></div>
                                <div class="alert-price">
                                    <strong><?= $alert['competitor_name'] ?></strong>: 
                                    $<?= number_format($alert['competitor_price'], 2) ?>
                                    <span style="color: var(--green); margin-left: 8px;">
                                        <i class="fas fa-arrow-down"></i> <?= number_format(abs($alert['price_change_percent']), 1) ?>%
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--text-2); text-align: center; padding: 20px;">No price drops detected</p>
                    <?php endif; ?>
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="price_alerts.php" style="color: var(--cyan); text-decoration: none; font-size: 13px;">Manage Alerts →</a>
                    </div>
                </div>
            </div>

            <!-- Market Events -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5><i class="fas fa-newspaper" style="color: var(--cyan);"></i> Market Events</h5>
                </div>
                <div class="card-body-custom">
                    <?php if (count($market_events) > 0): ?>
                        <?php foreach ($market_events as $event): ?>
                            <div class="event-item event-<?= $event['severity'] ?>">
                                <div style="font-weight: 600; font-size: 13px; margin-bottom: 6px;">
                                    <?= ucfirst(str_replace('_', ' ', $event['event_type'])) ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-2); margin-bottom: 6px;">
                                    <?= htmlspecialchars($event['description']) ?>
                                </div>
                                <?php if ($event['affected_products_list']): ?>
                                    <div style="font-size: 10px; color: var(--text-3);">
                                        <i class="fas fa-tags"></i> Affects: <?= $event['affected_products_list'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: var(--text-2); text-align: center; padding: 20px;">No active market events</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Your Activity -->
            <div class="info-card">
                <div class="card-header-custom">
                    <h5><i class="fas fa-chart-bar" style="color: var(--green);"></i> Your Activity</h5>
                </div>
                <div class="card-body-custom">
                    <?php if (count($demand_patterns) > 0): ?>
                        <?php 
                        $pattern_summary = [];
                        foreach ($demand_patterns as $pattern) {
                            $pattern_summary[$pattern['product_name']] = ($pattern_summary[$pattern['product_name']] ?? 0) + $pattern['view_count'];
                        }
                        arsort($pattern_summary);
                        $top_patterns = array_slice($pattern_summary, 0, 5);
                        ?>
                        <ul class="activity-list">
                            <?php foreach ($top_patterns as $product => $views): ?>
                                <li class="activity-item">
                                    <span class="activity-name"><?= htmlspecialchars($product) ?></span>
                                    <span class="activity-count"><?= $views ?> views</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: var(--text-2); text-align: center; padding: 20px;">No activity data yet</p>
                    <?php endif; ?>
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="product_list.php" style="color: var(--cyan); text-decoration: none; font-size: 13px;">Continue Browsing →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Game Theory Insights -->
    <div class="insights-card animate-in">
        <h5 style="font-family: var(--font-d); margin-bottom: 20px;"><i class="fas fa-chess-board" style="color: var(--purple);"></i> Game Theory Insights</h5>
        <div class="insights-grid">
            <div>
                <h6 style="margin-bottom: 12px;"><i class="fas fa-lightbulb" style="color: var(--amber);"></i> Your Strategy Impact</h6>
                <p style="color: var(--text-2); font-size: 13px; line-height: 1.6;">
                    Based on your purchase history, you've influenced prices for 
                    <strong style="color: var(--cyan);"><?= $user_stats['unique_products'] ?? 0 ?></strong> products.
                </p>
                <ul style="color: var(--text-2); font-size: 12px; margin-top: 12px; list-style: none;">
                    <li style="margin-bottom: 8px;"><i class="fas fa-check-circle" style="color: var(--green);"></i> Your purchases signal demand to competitors</li>
                    <li style="margin-bottom: 8px;"><i class="fas fa-chart-line" style="color: var(--cyan);"></i> Price perceptions help optimize algorithms</li>
                    <li style="margin-bottom: 8px;"><i class="fas fa-eye" style="color: var(--amber);"></i> Browsing patterns affect demand forecasting</li>
                </ul>
            </div>
            <div>
                <h6 style="margin-bottom: 12px;"><i class="fas fa-chart-pie" style="color: var(--cyan);"></i> Market Position</h6>
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span>Price Sensitive Buyers</span>
                        <span>65%</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: 65%; background: var(--green);"></div>
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span>Premium Buyers</span>
                        <span>20%</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: 20%; background: var(--amber);"></div>
                    </div>
                </div>
                <div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px;">
                        <span>Balanced Buyers</span>
                        <span>15%</span>
                    </div>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: 15%; background: var(--purple);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div>
                <h6 style="font-family: var(--font-d); font-size: 14px;">GameTheory Pricing Platform</h6>
                <p style="color: var(--text-3); font-size: 12px;">Experience real-time market competition</p>
            </div>
            <div style="text-align: right;">
                <p style="color: var(--text-3); font-size: 11px;">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($user['email']) ?><br>
                    <i class="fas fa-clock"></i> Session: <?= date('H:i:s', $_SESSION['login_time']) ?>
                </p>
            </div>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-refresh dashboard every 60 seconds
    setTimeout(function() {
        window.location.reload();
    }, 60000);

    // Check for notifications
    setInterval(function() {
        fetch("check_notifications.php")
            .then(res => res.json())
            .then(data => {
                if (data.count > 0) {
                    // Create a subtle notification
                    const notif = document.createElement('div');
                    notif.style.cssText = `
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        background: var(--cyan-dim);
                        border: 1px solid var(--cyan);
                        border-radius: var(--radius);
                        padding: 12px 20px;
                        color: var(--cyan);
                        font-size: 13px;
                        z-index: 1000;
                        animation: fadeInUp 0.3s ease;
                    `;
                    notif.innerHTML = '<i class="fas fa-gift"></i> Price drop refund credited to wallet!';
                    document.body.appendChild(notif);
                    setTimeout(() => notif.remove(), 5000);
                }
            })
            .catch(err => console.log('Notification check failed'));
    }, 5000);
</script>
</body>
</html>