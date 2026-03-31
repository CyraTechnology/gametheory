<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user=$_SESSION['user_id'];

/* ======================
USER PURCHASES
====================== */

$stmt=$conn->prepare("
SELECT 
    po.id,
    po.product_id,
    po.unit_price as purchased_price,
    po.quantity,
    po.ordered_at,
    p.name,
    p.current_price
FROM purchase_orders po
JOIN products p ON p.id=po.product_id
WHERE po.user_id=?
ORDER BY po.ordered_at DESC
");

$stmt->execute([$user]);
$purchases=$stmt->fetchAll(PDO::FETCH_ASSOC);

// Include sidebar and header
include("../layout/customer_sidebar.php");
include "../layout/header.php";
?>

<!-- Add Mobile Optimized Meta Tags and Styles -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#4e73df">

<style>
    /* ======================
    GLOBAL STYLES
    ====================== */
    :root {
        --primary-color: #4e73df;
        --success-color: #1cc88a;
        --warning-color: #f6c23e;
        --danger-color: #e74a3b;
        --dark-color: #5a5c69;
        --sidebar-width: 250px;
        --sidebar-collapsed-width: 70px;
        --header-height: 60px;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f8f9fc;
        color: #333;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        overflow-x: hidden;
    }

    /* ======================
    SIDEBAR ADAPTATION STYLES
    ====================== */
    .main-content {
        margin-left: var(--sidebar-width);
        padding: 20px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
        background: #f8f9fc;
    }

    /* Sidebar collapsed state for desktop */
    body.sidebar-collapsed .main-content {
        margin-left: var(--sidebar-collapsed-width);
    }

    /* ======================
    MOBILE HEADER WITH MENU BUTTON
    ====================== */
    .mobile-header {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        background: white;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        border-bottom: 1px solid #edf2f7;
    }

    .menu-toggle {
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        border: none;
        border-radius: 8px;
        color: white;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .menu-toggle:active {
        transform: scale(0.95);
        background: #3a5fc7;
    }

    .mobile-header h1 {
        font-size: 20px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }

    .mobile-header-actions {
        display: flex;
        gap: 12px;
    }

    .mobile-header-actions i {
        font-size: 20px;
        color: #95a5a6;
    }

    /* ======================
    MAIN CONTENT STYLES
    ====================== */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-header h2 {
        font-size: 28px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h2 i {
        color: var(--primary-color);
    }

    .header-actions {
        display: flex;
        gap: 12px;
    }

    .header-actions button {
        padding: 10px 20px;
        border: 1px solid #e0e0e0;
        background: white;
        border-radius: 8px;
        color: #4a5568;
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .header-actions button:hover {
        background: #f7fafc;
        border-color: var(--primary-color);
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.03);
    }

    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 14px;
        color: #7f8c8d;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Cards */
    .content-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.03);
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid #edf2f7;
    }

    .card-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h3 i {
        color: var(--primary-color);
    }

    .badge-count {
        background: #e3f2fd;
        color: #1976d2;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    /* Product Grid/List */
    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 16px;
    }

    .product-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        border: 1px solid #edf2f7;
        transition: all 0.2s ease;
    }

    .product-item:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transform: translateY(-2px);
    }

    .product-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .product-name {
        font-weight: 600;
        font-size: 16px;
        color: #2c3e50;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .price-tag {
        background: #f8f9fc;
        padding: 8px 12px;
        border-radius: 20px;
        font-size: 14px;
    }

    .purchased-price {
        color: #7f8c8d;
        text-decoration: line-through;
        margin-right: 8px;
    }

    .current-price {
        color: var(--primary-color);
        font-weight: 700;
        font-size: 18px;
    }

    /* Badges */
    .badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .badge-success {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .badge-warning {
        background: #fff3e0;
        color: #f57c00;
    }

    .badge-danger {
        background: #ffebee;
        color: #c62828;
    }

    .badge-secondary {
        background: #eceff1;
        color: #546e7a;
    }

    .badge-primary {
        background: #e3f2fd;
        color: #1976d2;
    }

    .badge-drop {
        background: #e8f5e9;
        color: #2e7d32;
        font-weight: 600;
    }

    /* Timer */
    .timer {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: #7f8c8d;
        margin-top: 8px;
    }

    .timer i {
        color: var(--warning-color);
    }

    /* Competitor Items */
    .competitor-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .competitor-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        border-radius: 8px;
        transition: background 0.2s ease;
    }

    .competitor-item:hover {
        background: #f8f9fc;
    }

    .competitor-info {
        flex: 2;
    }

    .competitor-name {
        font-weight: 600;
        font-size: 15px;
        color: #2c3e50;
        margin-bottom: 4px;
    }

    .product-name-small {
        font-size: 13px;
        color: #7f8c8d;
    }

    .competitor-price {
        font-weight: 700;
        color: var(--primary-color);
        font-size: 18px;
    }

    .demand-indicator {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 12px;
        margin-left: 8px;
    }

    /* Category Filters */
    .category-filters {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding: 8px 0;
        margin-bottom: 16px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .category-filters::-webkit-scrollbar {
        height: 3px;
    }

    .category-filters::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 3px;
    }

    .filter-chip {
        padding: 8px 16px;
        background: #f1f5f9;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        color: #4a5568;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .filter-chip.active {
        background: var(--primary-color);
        color: white;
    }

    .filter-chip:active {
        transform: scale(0.95);
    }

    /* Desktop Bottom Navigation (hidden on mobile) */
    .desktop-bottom-nav {
        display: none;
    }

    /* ======================
    MOBILE SPECIFIC STYLES
    ====================== */
    @media screen and (max-width: 768px) {
        .mobile-header {
            display: flex;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 16px;
            padding-bottom: 80px;
        }

        .page-header {
            margin-top: 0;
        }

        .page-header h2 {
            font-size: 24px;
        }

        .header-actions {
            display: none;
        }

        .stats-grid {
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            padding: 15px;
        }

        .stat-value {
            font-size: 22px;
        }

        .product-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .product-item {
            padding: 14px;
        }

        .competitor-item {
            padding: 10px 0;
        }

        .competitor-price {
            font-size: 16px;
        }

        /* Show bottom navigation only on mobile */
        .bottom-nav {
            display: flex !important;
        }
    }

    /* Bottom Navigation (Android App Style) - Hidden on Desktop */
    .bottom-nav {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        justify-content: space-around;
        padding: 8px 16px;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        border-top: 1px solid #edf2f7;
        z-index: 1000;
    }

    .nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        color: #95a5a6;
        font-size: 11px;
        transition: color 0.2s ease;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        flex: 1;
        text-decoration: none;
    }

    .nav-item.active {
        color: var(--primary-color);
    }

    .nav-item i {
        font-size: 20px;
    }

    .nav-item:active {
        background: #f7fafc;
    }

    /* Tablet Styles */
    @media screen and (min-width: 769px) and (max-width: 1024px) {
        .main-content {
            margin-left: var(--sidebar-width);
        }

        .product-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    /* Pull to Refresh */
    .refresh-indicator {
        text-align: center;
        padding: 10px;
        color: var(--primary-color);
        display: none;
        font-size: 14px;
    }

    /* Loading States */
    .loading-skeleton {
        animation: pulse 1.5s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
</style>

<!-- Mobile Header with Menu Toggle -->
<div class="mobile-header">
    <button class="menu-toggle" id="mobileMenuToggle">
        <i class="fa fa-bars"></i>
    </button>
    <h1>Price Alerts</h1>
    <div class="mobile-header-actions">
        <i class="fa fa-search"></i>
        <i class="fa fa-filter"></i>
    </div>
</div>

<!-- Main Content Area -->
<div class="main-content" id="mainContent">
    <!-- Desktop Page Header -->
    <div class="page-header">
        <h2>
            <i class="fa fa-bell"></i> 
            Price Drop Alerts
        </h2>
        <div class="header-actions">
            <button>
                <i class="fa fa-calendar"></i>
                Last 30 days
            </button>
            <button>
                <i class="fa fa-download"></i>
                Export
            </button>
        </div>
    </div>

    <!-- Quick Stats -->
    <?php
    $totalSavings = 0;
    $eligibleRefunds = 0;
    foreach($purchases as $p) {
        if($p['current_price'] < $p['purchased_price']) {
            $totalSavings += ($p['purchased_price'] - $p['current_price']) * $p['quantity'];
        }
        $purchaseTime = strtotime($p['ordered_at']);
        if(time() - $purchaseTime <= 86400 && $p['current_price'] < $p['purchased_price']) {
            $eligibleRefunds++;
        }
    }
    ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">₹<?= number_format($totalSavings) ?></div>
            <div class="stat-label">
                <i class="fa fa-tag"></i>
                Total Savings
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $eligibleRefunds ?></div>
            <div class="stat-label">
                <i class="fa fa-gift"></i>
                Eligible Refunds
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count($purchases) ?></div>
            <div class="stat-label">
                <i class="fa fa-box"></i>
                Tracked Items
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php
                $activeDrops = 0;
                foreach($purchases as $p) {
                    if($p['current_price'] < $p['purchased_price']) $activeDrops++;
                }
                echo $activeDrops;
                ?>
            </div>
            <div class="stat-label">
                <i class="fa fa-arrow-down"></i>
                Active Drops
            </div>
        </div>
    </div>

    <!-- Price Drop Alerts Section -->
    <div class="content-card">
        <div class="card-header">
            <h3>
                <i class="fa fa-bell"></i>
                Your Price Drop Alerts
            </h3>
            <span class="badge-count"><?= count($purchases) ?> items</span>
        </div>

        <!-- Category Filters (Mobile Scrollable) -->
        <div class="category-filters">
            <span class="filter-chip active">All Items</span>
            <span class="filter-chip">Price Dropped</span>
            <span class="filter-chip">Refund Eligible</span>
            <span class="filter-chip">Expiring Soon</span>
            <span class="filter-chip">Stable</span>
        </div>

        <div class="product-grid">
            <?php if(empty($purchases)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px 20px; color: #95a5a6;">
                    <i class="fa fa-inbox" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                    <h4 style="color: #4a5568; margin-bottom: 8px;">No purchases yet</h4>
                    <p style="font-size: 14px;">Start shopping to track price drops</p>
                    <button style="margin-top: 20px; background: var(--primary-color); color: white; border: none; padding: 12px 30px; border-radius: 25px; font-weight: 500;">
                        <i class="fa fa-store"></i> Browse Products
                    </button>
                </div>
            <?php else: ?>
                <?php foreach($purchases as $p):
                    $purchased = $p['purchased_price'];
                    $current = $p['current_price'];
                    $diff = $purchased - $current;
                    $purchaseTime = strtotime($p['ordered_at']);
                    $refundEligible = (time() - $purchaseTime <= 86400);
                    $remaining = 86400 - (time() - $purchaseTime);
                    $savingsPercent = $diff > 0 ? round(($diff/$purchased)*100) : 0;
                ?>
                <div class="product-item">
                    <div class="product-header">
                        <span class="product-name"><?= htmlspecialchars($p['name']) ?></span>
                        <?php if($diff > 0): ?>
                            <span class="badge badge-drop">
                                <i class="fa fa-arrow-down"></i> <?= $savingsPercent ?>% OFF
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="price-row">
                        <div class="price-tag">
                            <span class="purchased-price">₹<?= number_format($purchased) ?></span>
                            <span class="current-price">₹<?= number_format($current) ?></span>
                        </div>
                        
                        <?php
                        if($diff > 0 && $refundEligible) {
                            echo "<span class='badge badge-success'><i class='fa fa-check-circle'></i> Refund Eligible</span>";
                        } elseif($diff > 0) {
                            echo "<span class='badge badge-warning'><i class='fa fa-clock-o'></i> Expired</span>";
                        } else {
                            echo "<span class='badge badge-secondary'><i class='fa fa-minus-circle'></i> Stable</span>";
                        }
                        ?>
                    </div>

                    <?php if($diff > 0): ?>
                    <div style="margin-bottom: 8px;">
                        <span style="font-weight: 600; color: var(--success-color);">
                            You save ₹<?= number_format($diff) ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if($remaining > 0 && $diff > 0): ?>
                    <div class="timer">
                        <i class="fa fa-hourglass-half"></i>
                        <span>Refund window: <?= gmdate("H:i:s", $remaining) ?></span>
                    </div>
                    <?php endif; ?>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px; color: #95a5a6;">
                        <span>
                            <i class="fa fa-calendar"></i> 
                            <?= date("d M Y", strtotime($p['ordered_at'])) ?>
                        </span>
                        <span>
                            <i class="fa fa-cube"></i> 
                            Qty: <?= $p['quantity'] ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Competitor Price Monitor -->
    <div class="content-card">
        <div class="card-header">
            <h3>
                <i class="fa fa-chart-line"></i>
                Market Monitor
            </h3>
            <span class="badge-count">Live</span>
        </div>

        <!-- Competitor Filters -->
        <div class="category-filters">
            <span class="filter-chip active">All</span>
            <span class="filter-chip">Amazon</span>
            <span class="filter-chip">Flipkart</span>
            <span class="filter-chip">Croma</span>
            <span class="filter-chip">Reliance</span>
        </div>

        <?php
        $market = $conn->query("
            SELECT 
                cp.competitor_name,
                p.name as product,
                cp.price,
                cp.demand_level,
                cp.price_trend
            FROM competitor_prices cp
            LEFT JOIN products p ON p.id=cp.product_id
            ORDER BY cp.last_updated DESC
            LIMIT 10
        ");
        ?>

        <div class="competitor-list">
            <?php foreach($market as $m): ?>
            <div class="competitor-item">
                <div class="competitor-info">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span class="competitor-name"><?= htmlspecialchars($m['competitor_name']) ?></span>
                        <?php
                        $trendClass = 'badge-secondary';
                        $trendIcon = 'fa-minus';
                        if($m['price_trend'] == 'up') {
                            $trendClass = 'badge-danger';
                            $trendIcon = 'fa-arrow-up';
                        } elseif($m['price_trend'] == 'down') {
                            $trendClass = 'badge-success';
                            $trendIcon = 'fa-arrow-down';
                        }
                        ?>
                        <span class="demand-indicator <?= $trendClass ?>">
                            <i class="fa <?= $trendIcon ?>"></i> <?= ucfirst($m['demand_level']) ?>
                        </span>
                    </div>
                    <div class="product-name-small"><?= htmlspecialchars($m['product']) ?></div>
                </div>
                <div class="competitor-price">
                    ₹<?= number_format($m['price']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 20px; text-align: center;">
            <button style="background: var(--primary-color); color: white; border: none; padding: 12px 30px; border-radius: 25px; font-weight: 500; width: 100%; cursor: pointer;">
                <i class="fa fa-refresh"></i> Refresh Prices
            </button>
        </div>
    </div>

    <!-- Pull to Refresh Indicator -->
    <div class="refresh-indicator" id="refreshIndicator">
        <i class="fa fa-spinner fa-spin"></i> Refreshing...
    </div>
</div>

<!-- Bottom Navigation (Android App Style) - Only visible on mobile -->
<div class="bottom-nav">
    <a href="customer_dashboard.php" class="nav-item">
        <i class="fa fa-home"></i>
        <span>Home</span>
    </a>
    <a href="product_list.php" class="nav-item">
        <i class="fa fa-store"></i>
        <span>Shop</span>
    </a>
    <a href="price_alerts.php" class="nav-item active">
        <i class="fa fa-bell"></i>
        <span>Alerts</span>
    </a>
    <a href="cart.php" class="nav-item">
        <i class="fa fa-shopping-cart"></i>
        <span>Cart</span>
    </a>
    <a href="profile.php" class="nav-item">
        <i class="fa fa-user"></i>
        <span>Profile</span>
    </a>
</div>

<!-- Mobile Touch Optimizations -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.getElementById('mainContent');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Toggle sidebar active class
            sidebar.classList.toggle('active');
            
            // Create overlay if not exists
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    setTimeout(() => overlay.remove(), 300);
                });
            }
            
            if (sidebar.classList.contains('active')) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            } else {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                setTimeout(() => overlay.remove(), 300);
            }
        });
    }

    // Touch feedback for interactive elements
    const interactiveElements = document.querySelectorAll('.product-item, .filter-chip, button, .nav-item');
    interactiveElements.forEach(el => {
        el.addEventListener('touchstart', function() {
            this.style.opacity = '0.7';
        });
        el.addEventListener('touchend', function() {
            this.style.opacity = '1';
        });
        el.addEventListener('touchcancel', function() {
            this.style.opacity = '1';
        });
    });

    // Pull to refresh (mobile only)
    if (window.innerWidth <= 768) {
        let startY = 0;
        const main = document.querySelector('.main-content');
        const refreshIndicator = document.getElementById('refreshIndicator');
        
        main.addEventListener('touchstart', (e) => {
            startY = e.touches[0].pageY;
        }, { passive: true });

        main.addEventListener('touchmove', (e) => {
            if (window.scrollY === 0 && e.touches[0].pageY > startY + 50) {
                refreshIndicator.style.display = 'block';
            }
        }, { passive: true });

        main.addEventListener('touchend', () => {
            if (refreshIndicator.style.display === 'block') {
                refreshIndicator.style.display = 'none';
                // Simulate refresh
                window.location.reload();
            }
        });
    }

    // Category filter click handling
    const filterChips = document.querySelectorAll('.filter-chip');
    filterChips.forEach(chip => {
        chip.addEventListener('click', function() {
            filterChips.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            
            // Here you can add filter logic
            const filterType = this.textContent.trim().toLowerCase();
            filterProducts(filterType);
        });
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                // Desktop view
                if (sidebar) sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
                document.body.style.overflow = '';
            }
        }, 250);
    });
});

// Filter products function
function filterProducts(filterType) {
    const products = document.querySelectorAll('.product-item');
    
    products.forEach(product => {
        const statusBadge = product.querySelector('.badge-success, .badge-warning, .badge-secondary');
        const priceDrop = product.querySelector('.badge-drop');
        
        switch(filterType) {
            case 'all items':
                product.style.display = 'block';
                break;
            case 'price dropped':
                if (priceDrop) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
                break;
            case 'refund eligible':
                if (statusBadge && statusBadge.classList.contains('badge-success')) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
                break;
            default:
                product.style.display = 'block';
        }
    });
}
</script>

<?php include "../layout/footer.php"; ?>