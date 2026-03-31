<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Filters
$status     = $_GET['status'] ?? 'all';
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';
$search     = $_GET['search'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 10;
$offset     = ($page - 1) * $limit;

// WHERE clause
$where = ["po.user_id = ?"];
$params = [$user_id];

// Status filter
if ($status !== 'all' && in_array($status, ['pending','processing','shipped','delivered','cancelled'])) {
    $where[] = "po.status = ?";
    $params[] = $status;
}

// Date filters
if ($date_from) {
    $where[] = "DATE(po.ordered_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where[] = "DATE(po.ordered_at) <= ?";
    $params[] = $date_to;
}

// Search
if ($search) {
    $where[] = "(p.name LIKE ? OR po.order_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

//
// TOTAL ORDERS COUNT
//
$count_sql = "
    SELECT COUNT(DISTINCT po.order_number) 
    FROM purchase_orders po
    LEFT JOIN products p ON po.product_id = p.id
    $where_clause
";

$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    die("Prepare failed: " . $conn->error);
}

$count_stmt->execute($params);
$total_orders = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_orders / $limit));

//
// ORDERS LIST
//
$orders_sql = "
    SELECT 
        po.order_number,
        po.status,
        po.ordered_at,
        po.delivered_at,
        po.payment_method,
        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS product_names,
        COUNT(DISTINCT po.product_id) AS item_count,
        SUM(po.total_price) AS order_total
    FROM purchase_orders po
    LEFT JOIN products p ON po.product_id = p.id
    $where_clause
    GROUP BY po.order_number, po.status, po.ordered_at, po.delivered_at, po.payment_method
    ORDER BY po.ordered_at DESC
    LIMIT $limit OFFSET $offset
";

$orders_stmt = $conn->prepare($orders_sql);
if (!$orders_stmt) {
    die("Prepare failed: " . $conn->error);
}
$orders_stmt->execute($params);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

//
// ORDER STATS
//
$stats_sql = "
    SELECT 
        COUNT(DISTINCT order_number) AS total_orders,
        SUM(total_price) AS total_spent,
        AVG(customer_satisfaction) AS avg_satisfaction,
        COUNT(DISTINCT product_id) AS unique_products,
        SUM(status = 'delivered') AS delivered_orders,
        SUM(status IN ('pending','processing')) AS active_orders,
        MIN(ordered_at) AS first_order_date,
        MAX(ordered_at) AS last_order_date
    FROM purchase_orders
    WHERE user_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die("Prepare failed: " . $conn->error);
}
$stats_stmt->execute([$user_id]);
$order_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Initialize stats if null
$order_stats = array_merge([
    'total_orders' => 0,
    'total_spent' => 0,
    'avg_satisfaction' => 0,
    'unique_products' => 0,
    'delivered_orders' => 0,
    'active_orders' => 0,
    'first_order_date' => null,
    'last_order_date' => null
], $order_stats ?: []);

//
// MARKET EVENTS
//
$events_sql = "
    SELECT 
        me.event_type,
        me.description,
        me.severity,
        me.start_date
    FROM market_events me
    WHERE me.is_active = 1
      AND me.start_date <= CURDATE()
      AND (me.end_date IS NULL OR me.end_date >= CURDATE())
    ORDER BY me.severity DESC
    LIMIT 3
";

$events_stmt = $conn->prepare($events_sql);
if (!$events_stmt) {
    die("Prepare failed: " . $conn->error);
}
$events_stmt->execute();
$market_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e54c8;
            --secondary-color: #8f94fb;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: var(--primary-color) !important;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .order-card {
            background: white;
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .order-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .order-status {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .status-processing { 
            background: linear-gradient(135deg, #cce5ff 0%, #a8d4ff 100%);
            color: #004085;
            border: 1px solid #a8d4ff;
        }
        
        .status-shipped { 
            background: linear-gradient(135deg, #d4edda 0%, #b8e6c3 100%);
            color: #155724;
            border: 1px solid #b8e6c3;
        }
        
        .status-delivered { 
            background: linear-gradient(135deg, #d1ecf1 0%, #b3e0e8 100%);
            color: #0c5460;
            border: 1px solid #b3e0e8;
        }
        
        .status-cancelled { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5b5ba 100%);
            color: #721c24;
            border: 1px solid #f5b5ba;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 24px;
        }
        
        .filter-sidebar {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .market-event {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .event-critical { border-left-color: var(--danger-color); }
        .event-high { border-left-color: var(--warning-color); }
        .event-medium { border-left-color: var(--info-color); }
        .event-low { border-left-color: var(--success-color); }
        
        .badge-event {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .price-change {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .price-drop { background: var(--success-color); color: white; }
        .price-increase { background: var(--danger-color); color: white; }
        .price-stable { background: #6c757d; color: white; }
        
        .satisfaction-stars {
            color: #ffc107;
            font-size: 14px;
        }
        
        .game-theory-badge {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-icon {
            font-size: 80px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            border: 1px solid #dee2e6;
            margin: 0 3px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-color: var(--primary-color);
            color: white;
        }
        
        .pagination .page-link:hover {
            background: #f8f9ff;
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border: none;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(78, 84, 200, 0.3);
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
        }
        
        .progress-bar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 4px;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 20px 0;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-number {
                font-size: 24px;
            }
            
            .order-header {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-chess-board"></i> GameTheory Pricing
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="customer_dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="product_list.php">
                            <i class="fas fa-store"></i> Shop
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="my_orders.php">
                            <i class="fas fa-receipt"></i> My Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php#live-market">
                            <i class="fas fa-chart-line"></i> Live Market
                        </a>
                    </li>
                </ul>
                <div class="navbar-text">
                    <span class="text-muted me-3">Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 mb-3">
                        <i class="fas fa-history me-2"></i>My Orders
                    </h1>
                    <p class="lead mb-0">
                        Track your purchases and analyze market impact
                        <span class="badge bg-light text-primary ms-2">
                            <i class="fas fa-chess-board"></i> Game Theory Insights
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="product_list.php" class="btn btn-light btn-lg">
                        <i class="fas fa-shopping-cart"></i> Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Order Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <div class="stat-number"><?= $order_stats['total_orders'] ?? 0 ?></div>
                    <div class="text-muted">Total Orders</div>
                    <small class="text-success">
                        <i class="fas fa-check-circle"></i> <?= $order_stats['delivered_orders'] ?? 0 ?> delivered
                    </small>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-number">$<?= number_format($order_stats['total_spent'] ?? 0, 0) ?></div>
                    <div class="text-muted">Total Spent</div>
                    <small class="text-info">
                        Avg: $<?= 
                            ($order_stats['total_orders'] ?? 0) > 0 
                            ? number_format(($order_stats['total_spent'] ?? 0) / ($order_stats['total_orders'] ?? 1), 2) 
                            : '0.00' 
                        ?>
                    </small>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-smile"></i>
                    </div>
                    <div class="stat-number">
                        <?= 
                            ($order_stats['avg_satisfaction'] ?? 0) > 0 
                            ? number_format($order_stats['avg_satisfaction'], 1) . '/5' 
                            : 'N/A' 
                        ?>
                    </div>
                    <div class="text-muted">Avg Satisfaction</div>
                    <div class="satisfaction-stars">
                        <?php if (($order_stats['avg_satisfaction'] ?? 0) > 0): 
                            $rating = round($order_stats['avg_satisfaction']);
                            for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?= $i <= $rating ? '' : '-o' ?>"></i>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <div class="stat-number"><?= $order_stats['unique_products'] ?? 0 ?></div>
                    <div class="text-muted">Unique Products</div>
                    <small class="text-warning">
                        <i class="fas fa-clock"></i> 
                        <?= ($order_stats['active_orders'] ?? 0) > 0 ? $order_stats['active_orders'] . ' active' : 'No active orders' ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Filters Sidebar -->
            <div class="col-lg-3">
                <div class="filter-sidebar sticky-top" style="top: 80px;">
                    <h5 class="mb-4">
                        <i class="fas fa-filter text-primary"></i> Filter Orders
                    </h5>
                    
                    <!-- Status Filter -->
                    <div class="mb-4">
                        <h6 class="mb-3">Order Status</h6>
                        <div class="list-group list-group-flush">
                            <a href="?status=all&page=1" 
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $status === 'all' ? 'active' : '' ?>">
                                All Status
                                <span class="badge bg-secondary rounded-pill"><?= $total_orders ?></span>
                            </a>
                            <?php 
                            $status_counts = [
                                'pending' => ['Pending', 'warning'],
                                'processing' => ['Processing', 'info'],
                                'shipped' => ['Shipped', 'success'],
                                'delivered' => ['Delivered', 'primary'],
                                'cancelled' => ['Cancelled', 'danger']
                            ];
                            foreach ($status_counts as $key => [$label, $color]): 
                                // Get count for each status
                                $status_count_sql = "SELECT COUNT(DISTINCT order_number) as count 
                                                     FROM purchase_orders 
                                                     WHERE user_id = ? AND status = ?";
                                $status_count_stmt = $conn->prepare($status_count_sql);
                                $status_count_stmt->execute([$user_id, $key]);
                                $status_count_result = $status_count_stmt->fetch(PDO::FETCH_ASSOC);
                                $status_count = $status_count_result['count'] ?? 0;
                            ?>
                                <a href="?status=<?= $key ?>&page=1" 
                                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $status === $key ? 'active' : '' ?>">
                                    <span>
                                        <span class="badge bg-<?= $color ?> me-2">&nbsp;</span>
                                        <?= $label ?>
                                    </span>
                                    <span class="badge bg-<?= $color ?> rounded-pill">
                                        <?= $status_count ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Date Range Filter -->
                    <div class="mb-4">
                        <h6 class="mb-3">Date Range</h6>
                        <form method="GET" action="" id="dateFilterForm">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="page" value="1">
                            <div class="mb-3">
                                <label class="form-label small">From Date</label>
                                <input type="date" 
                                       class="form-control form-control-sm" 
                                       name="date_from" 
                                       value="<?= htmlspecialchars($date_from) ?>"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">To Date</label>
                                <input type="date" 
                                       class="form-control form-control-sm" 
                                       name="date_to" 
                                       value="<?= htmlspecialchars($date_to) ?>"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fas fa-calendar-check"></i> Apply Dates
                                </button>
                                <?php if ($date_from || $date_to): ?>
                                    <a href="?status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>&page=1" 
                                       class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-times"></i> Clear Dates
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Search -->
                    <div class="mb-4">
                        <h6 class="mb-3">Search Orders</h6>
                        <form method="GET" action="" class="d-flex">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                            <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                            <input type="hidden" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                            <input type="text" 
                                   class="form-control form-control-sm me-2" 
                                   name="search" 
                                   placeholder="Order # or product..." 
                                   value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                    
                    <!-- Active Filters -->
                    <?php if ($status !== 'all' || $date_from || $date_to || $search): ?>
                        <div class="mb-4">
                            <h6 class="mb-3">Active Filters</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($status !== 'all'): ?>
                                    <span class="badge bg-primary d-flex align-items-center">
                                        Status: <?= ucfirst($status) ?>
                                        <a href="?<?= 
                                            http_build_query(array_merge($_GET, ['status' => 'all', 'page' => 1])) 
                                        ?>" class="text-white ms-2">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($date_from): ?>
                                    <span class="badge bg-info d-flex align-items-center">
                                        From: <?= htmlspecialchars($date_from) ?>
                                        <a href="?<?= 
                                            http_build_query(array_merge($_GET, ['date_from' => '', 'page' => 1])) 
                                        ?>" class="text-white ms-2">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($date_to): ?>
                                    <span class="badge bg-info d-flex align-items-center">
                                        To: <?= htmlspecialchars($date_to) ?>
                                        <a href="?<?= 
                                            http_build_query(array_merge($_GET, ['date_to' => '', 'page' => 1])) 
                                        ?>" class="text-white ms-2">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($search): ?>
                                    <span class="badge bg-secondary d-flex align-items-center">
                                        Search: "<?= htmlspecialchars($search) ?>"
                                        <a href="?<?= 
                                            http_build_query(array_merge($_GET, ['search' => '', 'page' => 1])) 
                                        ?>" class="text-white ms-2">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <a href="my_orders.php" class="btn btn-sm btn-outline-danger mt-2">
                                    <i class="fas fa-broom"></i> Clear All Filters
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Order Timeline -->
                    <div>
                        <h6><i class="fas fa-history text-primary"></i> Order Timeline</h6>
                        <div class="small">
                            <?php if ($order_stats['first_order_date']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>First Order:</span>
                                    <span><?= date('M d, Y', strtotime($order_stats['first_order_date'])) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Last Order:</span>
                                    <span><?= date('M d, Y', strtotime($order_stats['last_order_date'])) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-3">
                                    <span>Active Orders:</span>
                                    <span class="badge bg-warning"><?= $order_stats['active_orders'] ?? 0 ?></span>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar" style="width: <?= 
                                        ($order_stats['delivered_orders'] ?? 0) / max(1, ($order_stats['total_orders'] ?? 1)) * 100 
                                    ?>%"></div>
                                </div>
                                <small class="text-muted">
                                    <?= number_format(
                                        ($order_stats['delivered_orders'] ?? 0) / max(1, ($order_stats['total_orders'] ?? 1)) * 100, 
                                        1
                                    ) ?>% orders delivered
                                </small>
                            <?php else: ?>
                                <p class="text-muted">No order history yet</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Market Events -->
                <?php if (!empty($market_events)): ?>
                    <div class="filter-sidebar">
                        <h5 class="mb-3">
                            <i class="fas fa-newspaper text-warning"></i> Market Events
                        </h5>
                        <p class="small text-muted mb-3">Affecting your purchased products</p>
                        <?php foreach ($market_events as $event): ?>
                            <div class="market-event event-<?= $event['severity'] ?> mb-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="badge-event bg-<?= $event['severity'] === 'critical' ? 'danger' : 
                                                               ($event['severity'] === 'high' ? 'warning' : 
                                                               ($event['severity'] === 'medium' ? 'info' : 'success')) ?>">
                                        <?= strtoupper($event['severity']) ?>
                                    </span>
                                    <small class="text-muted"><?= date('M d', strtotime($event['start_date'])) ?></small>
                                </div>
                                <h6 class="mb-2"><?= ucwords(str_replace('_', ' ', $event['event_type'])) ?></h6>
                                <p class="small mb-2"><?= htmlspecialchars($event['description']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Orders List -->
            <div class="col-lg-9">
                <!-- Orders Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="mb-0">
                            <i class="fas fa-receipt text-primary me-2"></i>
                            Order History
                        </h4>
                        <p class="text-muted mb-0">
                            Showing <?= count($orders) ?> of <?= number_format($total_orders) ?> orders
                            <?php if ($search): ?>
                                for "<strong><?= htmlspecialchars($search) ?></strong>"
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary" onclick="exportOrders()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <a href="my_orders.php" class="btn btn-outline-success">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </a>
                    </div>
                </div>
                
                <!-- Orders List -->
                <?php if (!empty($orders)): ?>
                    <div id="ordersList">
                        <?php foreach ($orders as $order): 
                            // Get order items details
                            $items_sql = "SELECT 
                                            po.*,
                                            p.name as product_name,
                                            p.category,
                                            c.name as company_name,
                                            c.strategy as company_strategy
                                          FROM purchase_orders po
                                          LEFT JOIN products p ON po.product_id = p.id
                                          LEFT JOIN companies c ON po.company_id = c.id
                                          WHERE po.order_number = ? AND po.user_id = ?";
                            $items_stmt = $conn->prepare($items_sql);
                            $items_stmt->execute([$order['order_number'], $user_id]);
                            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Calculate days since order
                            $ordered_date = new DateTime($order['ordered_at']);
                            $today = new DateTime();
                            $days_diff = $today->diff($ordered_date)->days;
                        ?>
                            <div class="order-card">
                                <div class="order-header">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <span class="order-status status-<?= $order['status'] ?>">
                                                        <?= ucfirst($order['status']) ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <h5 class="mb-1"><?= htmlspecialchars($order['order_number']) ?></h5>
                                                    <small class="text-muted">
                                                        <i class="far fa-calendar me-1"></i>
                                                        <?= date('F j, Y - g:i A', strtotime($order['ordered_at'])) ?>
                                                        <?php if ($order['delivered_at']): ?>
                                                            • Delivered: <?= date('M j, Y', strtotime($order['delivered_at'])) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-md-end">
                                            <h4 class="mb-1 text-primary">$<?= number_format($order['order_total'], 2) ?></h4>
                                            <small class="text-muted">
                                                <?= $order['item_count'] ?> item<?= $order['item_count'] > 1 ? 's' : '' ?>
                                                • <?= ucfirst($order['payment_method'] ?? 'N/A') ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="p-4">
                                    <!-- Order Items -->
                                    <div class="mb-4">
                                        <h6 class="mb-3">
                                            <i class="fas fa-box-open text-primary me-2"></i>
                                            Order Items
                                        </h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-borderless">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Category</th>
                                                        <th>Company</th>
                                                        <th>Qty</th>
                                                        <th>Price</th>
                                                        <th>Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($order_items as $item): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></strong>
                                                                <?php if (!empty($item['company_strategy'])): ?>
                                                                    <span class="game-theory-badge ms-2">
                                                                        <?= ucfirst($item['company_strategy']) ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-secondary">
                                                                    <?= ucfirst($item['category'] ?? 'Unknown') ?>
                                                                </span>
                                                            </td>
                                                            <td><?= htmlspecialchars($item['company_name'] ?? 'N/A') ?></td>
                                                            <td><?= $item['quantity'] ?></td>
                                                            <td>$<?= number_format($item['unit_price'], 2) ?></td>
                                                            <td><strong>$<?= number_format($item['total_price'], 2) ?></strong></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Order Actions -->
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="order_details.php?order_number=<?= urlencode($order['order_number']) ?>" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-eye me-1"></i> View Details
                                                </a>
                                                <?php if ($order['status'] === 'delivered'): ?>
                                                    <button class="btn btn-outline-success btn-sm" 
                                                            onclick="reorderItems('<?= $order['order_number'] ?>')">
                                                        <i class="fas fa-redo me-1"></i> Reorder All
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (in_array($order['status'], ['pending', 'processing'])): ?>
                                                    <button class="btn btn-outline-danger btn-sm" 
                                                            onclick="cancelOrder('<?= $order['order_number'] ?>')">
                                                        <i class="fas fa-times me-1"></i> Cancel Order
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-outline-info btn-sm" 
                                                        onclick="trackOrder('<?= $order['order_number'] ?>')">
                                                    <i class="fas fa-truck me-1"></i> Track
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-md-end">
                                            <small class="text-muted">
                                                <?php if ($days_diff == 0): ?>
                                                    <i class="fas fa-clock text-success"></i> Ordered today
                                                <?php elseif ($days_diff == 1): ?>
                                                    <i class="fas fa-clock text-info"></i> Ordered yesterday
                                                <?php else: ?>
                                                    <i class="fas fa-clock text-muted"></i> Ordered <?= $days_diff ?> days ago
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- Game Theory Insights -->
                                    <?php if (count($order_items) > 0): ?>
                                        <div class="mt-3">
                                            <div class="alert alert-info">
                                                <h6 class="mb-2">
                                                    <i class="fas fa-chess-board me-2"></i>
                                                    Game Theory Insights
                                                </h6>
                                                <div class="row small">
                                                    <div class="col-md-6">
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Companies Involved:</span>
                                                            <span>
                                                                <?= count(array_unique(array_filter(array_column($order_items, 'company_name')))) ?>
                                                            </span>
                                                        </div>
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Market Strategies:</span>
                                                            <span>
                                                                <?php 
                                                                $strategies = array_filter(array_column($order_items, 'company_strategy'));
                                                                echo !empty($strategies) ? implode(', ', array_unique($strategies)) : 'N/A';
                                                                ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <span>Categories:</span>
                                                            <span>
                                                                <?php 
                                                                $categories = array_filter(array_column($order_items, 'category'));
                                                                echo !empty($categories) ? count(array_unique($categories)) : 0;
                                                                ?>
                                                            </span>
                                                        </div>
                                                        <div class="d-flex justify-content-between">
                                                            <span>Price Perception:</span>
                                                            <span>
                                                                <?php 
                                                                $perceptions = array_filter(array_column($order_items, 'price_perception'));
                                                                echo !empty($perceptions) ? ucfirst($perceptions[0]) : 'N/A';
                                                                ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <!-- First Page -->
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= 
                                        http_build_query(array_merge($_GET, ['page' => 1])) 
                                    ?>">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Previous Page -->
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= 
                                        http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) 
                                    ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Page Numbers -->
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= 
                                            http_build_query(array_merge($_GET, ['page' => $i])) 
                                        ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <!-- Next Page -->
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= 
                                        http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])) 
                                    ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                                
                                <!-- Last Page -->
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= 
                                        http_build_query(array_merge($_GET, ['page' => $total_pages])) 
                                    ?>">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            </ul>
                            <div class="text-center mt-2">
                                <small class="text-muted">
                                    Page <?= $page ?> of <?= $total_pages ?> • 
                                    <?= $total_orders ?> total orders
                                </small>
                            </div>
                        </nav>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-shopping-bag"></i>
                        </div>
                        <h3 class="mb-3">
                            <?php if ($status !== 'all' || $date_from || $date_to || $search): ?>
                                No orders match your filters
                            <?php else: ?>
                                No Orders Yet
                            <?php endif; ?>
                        </h3>
                        <p class="text-muted mb-4">
                            <?php if ($status !== 'all' || $date_from || $date_to || $search): ?>
                                Try adjusting your filters or search terms
                            <?php else: ?>
                                Start shopping to see your orders here
                            <?php endif; ?>
                        </p>
                        <div class="d-flex justify-content-center gap-3">
                            <a href="product_list.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-shopping-cart me-2"></i> Start Shopping
                            </a>
                            <?php if ($status !== 'all' || $date_from || $date_to || $search): ?>
                                <a href="my_orders.php" class="btn btn-outline-secondary btn-lg">
                                    <i class="fas fa-times me-2"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h5>Game Theory Pricing Platform</h5>
                    <p class="text-muted mb-0">Track your market impact through orders</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <small>
                            <i class="far fa-user me-1"></i>
                            <?= htmlspecialchars($_SESSION['user_name'] ?? 'Customer') ?>
                        </small><br>
                        <small>
                            <i class="far fa-clock me-1"></i>
                            Last updated: <?= date('H:i:s') ?>
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Export orders function
        function exportOrders() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.open('export_orders.php?' + params.toString(), '_blank');
        }
        
        // Reorder items function
        function reorderItems(orderNumber) {
            if (confirm('Add all items from this order to your cart?')) {
                fetch('reorder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        order_number: orderNumber
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Items added to cart successfully!');
                        window.location.href = 'cart.php';
                    } else {
                        alert(data.error || 'Error adding items to cart');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error adding items to cart');
                });
            }
        }
        
        // Cancel order function
        function cancelOrder(orderNumber) {
            if (confirm('Are you sure you want to cancel this order?')) {
                fetch('cancel_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        order_number: orderNumber
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Order cancelled successfully');
                        location.reload();
                    } else {
                        alert(data.error || 'Error cancelling order');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error cancelling order');
                });
            }
        }
        
        // Track order function
        function trackOrder(orderNumber) {
            window.open('order_tracking.php?order_number=' + encodeURIComponent(orderNumber), '_blank');
        }
        
        // Auto-refresh for pending orders
        const hasPendingOrders = <?= 
            json_encode(!empty(array_filter($orders, fn($o) => in_array($o['status'], ['pending', 'processing'])))) 
        ?>;
        
        if (hasPendingOrders) {
            // Refresh every 30 seconds if there are pending orders
            setInterval(() => {
                console.log('Auto-refreshing orders page...');
                window.location.reload();
            }, 30000); // 30 seconds
        }
        
        // Date validation
        document.querySelector('input[name="date_from"]')?.addEventListener('change', function() {
            const dateTo = document.querySelector('input[name="date_to"]');
            if (dateTo.value && this.value > dateTo.value) {
                alert('From date cannot be after To date');
                this.value = '';
            }
        });
        
        document.querySelector('input[name="date_to"]')?.addEventListener('change', function() {
            const dateFrom = document.querySelector('input[name="date_from"]');
            if (dateFrom.value && this.value < dateFrom.value) {
                alert('To date cannot be before From date');
                this.value = '';
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F for search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            
            // F5 to refresh
            if (e.key === 'F5') {
                e.preventDefault();
                window.location.reload();
            }
        });
    </script>
</body>
</html>