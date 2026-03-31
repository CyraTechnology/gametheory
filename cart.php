<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

$customer_id = $_SESSION['user_id'];

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id = $_POST['product_id'] ?? 0;
    $quantity = $_POST['quantity'] ?? 1;
    
    switch ($action) {
        case 'update':
            if ($quantity > 0) {
                $stmt = $conn->prepare("UPDATE user_cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$quantity, $customer_id, $product_id]);
            } else {
                $stmt = $conn->prepare("DELETE FROM user_cart_items WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$customer_id, $product_id]);
            }
            break;
            
        case 'remove':
            $stmt = $conn->prepare("DELETE FROM user_cart_items WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$customer_id, $product_id]);
            break;
            
        case 'clear':
            $stmt = $conn->prepare("DELETE FROM user_cart_items WHERE user_id = ?");
            $stmt->execute([$customer_id]);
            break;
            
        case 'apply_coupon':
            $coupon_code = $_POST['coupon_code'] ?? '';
            // Coupon validation logic here
            break;
    }
    
    header("Location: cart.php");
    exit;
}

// Get cart items with detailed product information
$cart_sql = "SELECT 
                ci.*,
                p.name,
                p.category,
                p.current_price,
                p.base_cost,
                p.stock,
                p.demand_elasticity,
                p.competition_sensitivity,
                p.image_url,
                p.min_price,
                p.max_price,
                (SELECT AVG(price) FROM competitor_prices WHERE product_id = p.id) as market_avg_price,
                (SELECT MIN(price) FROM competitor_prices WHERE product_id = p.id) as market_min_price,
                (SELECT MAX(price) FROM competitor_prices WHERE product_id = p.id) as market_max_price,
                (SELECT COUNT(*) FROM competitor_prices WHERE product_id = p.id) as competitor_count,
                (SELECT demand_level FROM competitor_prices WHERE product_id = p.id ORDER BY last_updated DESC LIMIT 1) as demand_level,
                (SELECT price_trend FROM competitor_prices WHERE product_id = p.id ORDER BY last_updated DESC LIMIT 1) as price_trend,
                (p.current_price * ci.quantity) as item_total,
                (p.current_price - (SELECT MIN(price) FROM competitor_prices WHERE product_id = p.id)) as price_difference,
                ROUND(((p.current_price - (SELECT MIN(price) FROM competitor_prices WHERE product_id = p.id)) / p.current_price * 100), 2) as savings_percent
            FROM user_cart_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.user_id = ?
            ORDER BY ci.added_at DESC";

$cart_stmt = $conn->prepare($cart_sql);
$cart_stmt->execute([$customer_id]);
$cart_items = $cart_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate cart totals
$subtotal = 0;
$total_savings = 0;
$total_items = 0;
$shipping_cost = 0;
$tax_rate = 0.08; // 8% tax

foreach ($cart_items as $item) {
    $subtotal += $item['item_total'];
    $total_savings += $item['price_difference'] > 0 ? $item['price_difference'] * $item['quantity'] : 0;
    $total_items += $item['quantity'];
}

// Calculate shipping (free over $100, otherwise $9.99)
if ($subtotal >= 100) {
    $shipping_cost = 0;
} else {
    $shipping_cost = 9.99;
}

$tax = $subtotal * $tax_rate;
$total = $subtotal + $shipping_cost + $tax;

// Get price drop alerts for cart items
$price_alerts_sql = "SELECT 
                        pa.*,
                        p.name as product_name,
                        cp.competitor_name,
                        cp.price as current_price,
                        pa.tracked_price,
                        ROUND(((cp.price - pa.tracked_price) / pa.tracked_price * 100), 2) as price_change_percent
                    FROM price_alerts pa
                    JOIN products p ON pa.product_id = p.id
                    JOIN competitor_prices cp ON pa.product_id = cp.product_id 
                        AND pa.competitor_id = cp.company_id
                    WHERE pa.user_id = ? 
                        AND pa.product_id IN (SELECT product_id FROM user_cart_items WHERE user_id = ?)
                        AND cp.price < pa.tracked_price * 0.95";

$alerts_stmt = $conn->prepare($price_alerts_sql);
$alerts_stmt->execute([$customer_id, $customer_id]);
$price_alerts = $alerts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recommended items based on cart contents
if (count($cart_items) > 0) {
    $categories = array_column($cart_items, 'category');
    $category_list = "'" . implode("','", array_unique($categories)) . "'";
    
    $recommendations_sql = "SELECT 
                                p.*,
                                AVG(cp.price) as market_avg_price,
                                MIN(cp.price) as market_min_price,
                                COUNT(DISTINCT cp.id) as competitor_count,
                                (SELECT demand_level FROM competitor_prices 
                                 WHERE product_id = p.id ORDER BY last_updated DESC LIMIT 1) as demand_level,
                                ROUND(((p.current_price - MIN(cp.price)) / p.current_price * 100), 2) as savings_percent
                            FROM products p
                            LEFT JOIN competitor_prices cp ON p.id = cp.product_id
                            WHERE p.category IN ($category_list)
                                AND p.id NOT IN (SELECT product_id FROM user_cart_items WHERE user_id = ?)
                                AND p.is_active = 1
                                AND p.stock > 0
                            GROUP BY p.id
                            HAVING savings_percent > 5
                            ORDER BY savings_percent DESC
                            LIMIT 4";
    
    $rec_stmt = $conn->prepare($recommendations_sql);
    $rec_stmt->execute([$customer_id]);
    $recommendations = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recommendations = [];
}

// Get cart abandonment statistics
$abandonment_sql = "SELECT 
                        COUNT(*) as total_carts,
                        AVG(total_value) as avg_cart_value,
                        MAX(total_value) as max_cart_value
                    FROM (
                        SELECT 
                            ci.user_id,
                            SUM(p.current_price * ci.quantity) as total_value
                        FROM user_cart_items ci
                        JOIN products p ON ci.product_id = p.id
                        WHERE ci.added_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                        GROUP BY ci.user_id
                    ) as cart_stats";

$abandonment_stmt = $conn->query($abandonment_sql);
$abandonment_stats = $abandonment_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: 700;
            color: #4e54c8 !important;
        }
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
        }
        .cart-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        .price-tag {
            font-size: 24px;
            font-weight: 700;
            color: #4e54c8;
        }
        .savings-badge {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .demand-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 5px;
        }
        .demand-low { background: #dc3545; color: white; }
        .demand-medium { background: #ffc107; color: black; }
        .demand-high { background: #28a745; color: white; }
        .demand-critical { background: #007bff; color: white; }
        .trend-icon {
            font-size: 14px;
        }
        .trend-up { color: #dc3545; }
        .trend-down { color: #28a745; }
        .trend-stable { color: #6c757d; }
        .trend-volatile { color: #fd7e14; }
        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            position: sticky;
            top: 20px;
        }
        .quantity-input {
            width: 70px;
            text-align: center;
        }
        .product-image {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .market-comparison {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #4e54c8;
        }
        .price-alert-card {
            border-left: 4px solid #28a745;
            padding-left: 15px;
            margin-bottom: 10px;
        }
        .price-alert-card.warning { border-left-color: #ffc107; }
        .price-alert-card.danger { border-left-color: #dc3545; }
        .recommendation-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            height: 100%;
        }
        .recommendation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .empty-cart {
            text-align: center;
            padding: 50px 20px;
        }
        .game-theory-badge {
            background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
        .cart-progress {
            height: 10px;
            border-radius: 5px;
            background: #e9ecef;
            margin: 15px 0;
        }
        .cart-progress-bar {
            height: 100%;
            border-radius: 5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-chess-board"></i> GameTheory
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
                        <a class="nav-link active" href="cart.php">
                            <i class="fas fa-shopping-cart"></i> Cart
                            <?php if ($total_items > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $total_items ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my_orders.php">
                            <i class="fas fa-receipt"></i> Orders
                        </a>
                    </li>
                </ul>
                <div class="navbar-text">
                    <small class="text-muted">Live Market | </small>
                    <a href="../index.php#live-market" class="btn btn-sm btn-outline-primary ms-2">
                        <i class="fas fa-chart-line"></i> View
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
                    <h1 class="display-6 mb-3">Shopping Cart</h1>
                    <p class="lead mb-0">
                        Real-time price optimization based on market competition
                        <span class="game-theory-badge">
                            <i class="fas fa-chess-board"></i> Dynamic Pricing
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white rounded-pill px-4 py-2 d-inline-block">
                        <small class="text-muted">Cart Value:</small>
                        <strong class="text-primary">$<?= number_format($subtotal, 2) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Cart Progress -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h6 class="mb-0">Cart Progress</h6>
                                <small class="text-muted">
                                    <?php if ($subtotal < 100): ?>
                                        Add $<?= number_format(100 - $subtotal, 2) ?> more for free shipping!
                                    <?php else: ?>
                                        Congratulations! You've unlocked free shipping!
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= $subtotal >= 100 ? 'success' : 'warning' ?>">
                                    <?= $subtotal >= 100 ? 'Free Shipping' : '$' . number_format($shipping_cost, 2) . ' Shipping' ?>
                                </span>
                            </div>
                        </div>
                        <div class="cart-progress">
                            <div class="cart-progress-bar" style="width: <?= min(100, ($subtotal / 100) * 100) ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small>$0</small>
                            <small>$100</small>
                            <small>$200+</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Cart Items -->
            <div class="col-lg-8">
                <?php if (count($cart_items) > 0): ?>
                    <!-- Price Drop Alerts -->
                    <?php if (count($price_alerts) > 0): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-white">
                                <h5 class="mb-0"><i class="fas fa-bell"></i> Price Drop Alerts!</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($price_alerts as $alert): ?>
                                    <div class="price-alert-card <?= abs($alert['price_change_percent']) > 15 ? 'danger' : 'warning' ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?= htmlspecialchars($alert['product_name']) ?></h6>
                                                <p class="mb-1 small">
                                                    <strong><?= $alert['competitor_name'] ?></strong>: 
                                                    Now $<?= number_format($alert['current_price'], 2) ?>
                                                    (was $<?= number_format($alert['tracked_price'], 2) ?>)
                                                </p>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-success">
                                                    <i class="fas fa-arrow-down"></i> 
                                                    <?= number_format(abs($alert['price_change_percent']), 1) ?>% drop
                                                </span>
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Consider buying from <?= $alert['competitor_name'] ?> to save
                                            $<?= number_format($alert['tracked_price'] - $alert['current_price'], 2) ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Cart Items List -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Your Cart (<?= $total_items ?> items)</h5>
                                <form method="POST" onsubmit="return confirm('Clear your entire cart?')">
                                    <input type="hidden" name="action" value="clear">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i> Clear Cart
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php foreach ($cart_items as $item): 
                                $trend_icon = $item['price_trend'] === 'increasing' ? 'fa-arrow-up trend-up' : 
                                            ($item['price_trend'] === 'decreasing' ? 'fa-arrow-down trend-down' : 
                                            ($item['price_trend'] === 'volatile' ? 'fa-random trend-volatile' : 'fa-minus trend-stable'));
                                $stock_class = $item['stock'] > 20 ? 'text-success' : 
                                             ($item['stock'] > 5 ? 'text-warning' : 'text-danger');
                            ?>
                                <div class="cart-item">
                                    <div class="row">
                                        <!-- Product Image -->
                                        <div class="col-md-2">
                                            <div class="product-image">
                                                <i class="fas fa-box"></i>
                                            </div>
                                        </div>
                                        
                                        <!-- Product Details -->
                                        <div class="col-md-5">
                                            <h5 class="mb-2"><?= htmlspecialchars($item['name']) ?></h5>
                                            <div class="mb-2">
                                                <span class="demand-badge demand-<?= $item['demand_level'] ?>">
                                                    <?= strtoupper($item['demand_level']) ?> DEMAND
                                                </span>
                                                <i class="fas <?= $trend_icon ?> trend-icon" 
                                                   title="<?= ucfirst($item['price_trend']) ?> trend"></i>
                                            </div>
                                            <p class="mb-1 small text-muted">
                                                Category: <?= ucfirst($item['category']) ?>
                                            </p>
                                            <p class="mb-0 small <?= $stock_class ?>">
                                                <i class="fas fa-boxes"></i> 
                                                <?= $item['stock'] ?> in stock
                                                <?php if ($item['stock'] < $item['quantity']): ?>
                                                    <span class="badge bg-danger">Insufficient stock</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Quantity Control -->
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label class="form-label small">Quantity</label>
                                                <form method="POST" class="d-flex align-items-center">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                                    <div class="input-group input-group-sm">
                                                        <button class="btn btn-outline-secondary" type="button" 
                                                                onclick="updateQuantity(<?= $item['product_id'] ?>, -1)">
                                                            <i class="fas fa-minus"></i>
                                                        </button>
                                                        <input type="number" 
                                                               class="form-control quantity-input" 
                                                               name="quantity" 
                                                               value="<?= $item['quantity'] ?>" 
                                                               min="1" 
                                                               max="<?= min($item['stock'], 10) ?>"
                                                               onchange="this.form.submit()">
                                                        <button class="btn btn-outline-secondary" type="button" 
                                                                onclick="updateQuantity(<?= $item['product_id'] ?>, 1)">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i> Remove
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <!-- Price Information -->
                                        <div class="col-md-2 text-end">
                                            <div class="price-tag">
                                                $<?= number_format($item['current_price'], 2) ?>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted">
                                                    Each
                                                </small>
                                            </div>
                                            <div class="h6">
                                                $<?= number_format($item['item_total'], 2) ?>
                                                <small class="text-muted d-block">Total</small>
                                            </div>
                                            <?php if ($item['savings_percent'] > 0): ?>
                                                <span class="savings-badge">
                                                    Save <?= number_format($item['savings_percent'], 1) ?>%
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Market Comparison -->
                                    <div class="market-comparison mt-3">
                                        <h6 class="small mb-2">
                                            <i class="fas fa-chart-bar"></i> Market Comparison
                                        </h6>
                                        <div class="row">
                                            <div class="col-4">
                                                <small class="text-muted d-block">Our Price</small>
                                                <strong>$<?= number_format($item['current_price'], 2) ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Market Avg</small>
                                                <strong>$<?= number_format($item['market_avg_price'] ?? $item['current_price'], 2) ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted d-block">Best Price</small>
                                                <strong class="text-success">
                                                    $<?= number_format($item['market_min_price'] ?? $item['current_price'], 2) ?>
                                                </strong>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-12">
                                                <small class="text-muted">
                                                    <i class="fas fa-store"></i> 
                                                    <?= $item['competitor_count'] ?> competitors selling this item
                                                    <?php if ($item['price_difference'] > 0): ?>
                                                        • You're saving $<?= number_format($item['price_difference'], 2) ?> per unit
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Game Theory Insights -->
                                    <div class="alert alert-info mt-3 p-2 small">
                                        <div class="d-flex">
                                            <i class="fas fa-chess-board me-2 mt-1"></i>
                                            <div>
                                                <strong>Game Theory Insight:</strong>
                                                <?php 
                                                $insight = '';
                                                if ($item['demand_elasticity'] < -2) {
                                                    $insight = 'Highly price sensitive - small price changes affect demand significantly.';
                                                } elseif ($item['competition_sensitivity'] === 'high') {
                                                    $insight = 'High competition sensitivity - prices change frequently based on competitor moves.';
                                                } elseif ($item['demand_level'] === 'critical') {
                                                    $insight = 'Critical demand - consider purchasing soon as prices may increase.';
                                                } else {
                                                    $insight = 'Market stable - good time to purchase with current savings.';
                                                }
                                                echo $insight;
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Recommendations -->
                    <?php if (count($recommendations) > 0): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-lightbulb text-warning"></i> Frequently Bought Together</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($recommendations as $rec): ?>
                                        <div class="col-md-3 mb-3">
                                            <div class="recommendation-card">
                                                <h6 class="mb-2" style="height: 40px; overflow: hidden;">
                                                    <?= htmlspecialchars($rec['name']) ?>
                                                </h6>
                                                <div class="mb-2">
                                                    <span class="demand-badge demand-<?= $rec['demand_level'] ?>">
                                                        <?= strtoupper($rec['demand_level']) ?>
                                                    </span>
                                                </div>
                                                <div class="mb-2">
                                                    <span class="h5">$<?= number_format($rec['current_price'], 2) ?></span>
                                                    <?php if ($rec['savings_percent'] > 0): ?>
                                                        <span class="badge bg-success ms-1">
                                                            Save <?= number_format($rec['savings_percent'], 1) ?>%
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-grid gap-2">
                                                    <button class="btn btn-sm btn-outline-primary add-to-cart-btn"
                                                            data-product-id="<?= $rec['id'] ?>"
                                                            data-product-name="<?= htmlspecialchars($rec['name']) ?>">
                                                        <i class="fas fa-cart-plus"></i> Add to Cart
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Empty Cart -->
                    <div class="empty-cart">
                        <i class="fas fa-shopping-cart fa-4x text-muted mb-4"></i>
                        <h3>Your cart is empty</h3>
                        <p class="text-muted mb-4">
                            Add some products to see real-time price competition in action
                        </p>
                        <a href="product_list.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-store"></i> Start Shopping
                        </a>
                        
                        <!-- Cart Statistics -->
                        <div class="row mt-5">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h2 text-primary"><?= $abandonment_stats['total_carts'] ?? 0 ?></div>
                                    <small class="text-muted">Active Carts</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h2 text-success">$<?= number_format($abandonment_stats['avg_cart_value'] ?? 0, 0) ?></div>
                                    <small class="text-muted">Average Cart Value</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h2 text-warning">$<?= number_format($abandonment_stats['max_cart_value'] ?? 0, 0) ?></div>
                                    <small class="text-muted">Max Cart Value</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Order Summary -->
            <div class="col-lg-4">
                <div class="summary-card">
                    <h5 class="mb-4">Order Summary</h5>
                    
                    <!-- Price Breakdown -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal (<?= $total_items ?> items)</span>
                            <span>$<?= number_format($subtotal, 2) ?></span>
                        </div>
                        
                        <?php if ($total_savings > 0): ?>
                            <div class="d-flex justify-content-between mb-2 text-success">
                                <span>
                                    <i class="fas fa-piggy-bank"></i> Total Savings
                                </span>
                                <span>-$<?= number_format($total_savings, 2) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Shipping</span>
                            <span class="<?= $shipping_cost === 0 ? 'text-success' : '' ?>">
                                <?= $shipping_cost === 0 ? 'FREE' : '$' . number_format($shipping_cost, 2) ?>
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span>Tax (<?= ($tax_rate * 100) ?>%)</span>
                            <span>$<?= number_format($tax, 2) ?></span>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-between mb-4">
                            <strong>Total</strong>
                            <strong class="h4 text-primary">$<?= number_format($total, 2) ?></strong>
                        </div>
                    </div>
                    
                    <!-- Game Theory Optimization -->
                    <div class="alert alert-warning mb-4">
                        <h6><i class="fas fa-chess-board"></i> Smart Cart Optimization</h6>
                        <p class="small mb-2">
                            Our algorithm analyzes market competition to ensure you get the best prices.
                        </p>
                        <div class="progress mb-2" style="height: 8px;">
                            <div class="progress-bar bg-success" 
                                 style="width: <?= min(100, ($total_savings / max(1, $subtotal)) * 100) ?>%">
                            </div>
                        </div>
                        <small class="text-muted">
                            Price optimization: <?= number_format(($total_savings / max(1, $subtotal)) * 100, 1) ?>%
                        </small>
                    </div>
                    
                    <!-- Coupon Code -->
                    <div class="mb-4">
                        <h6 class="mb-3">Coupon Code</h6>
                        <form method="POST" class="d-flex">
                            <input type="hidden" name="action" value="apply_coupon">
                            <input type="text" 
                                   class="form-control me-2" 
                                   name="coupon_code" 
                                   placeholder="Enter coupon code">
                            <button type="submit" class="btn btn-outline-primary">Apply</button>
                        </form>
                        <small class="text-muted mt-2 d-block">
                            <i class="fas fa-info-circle"></i> Available: GAME10 (10% off), THEORY15 (15% off orders over $200)
                        </small>
                    </div>
                    
                    <!-- Checkout Button -->
                    <div class="d-grid gap-2 mb-4">
                        <a href="checkout.php" 
                           class="btn btn-primary btn-lg <?= count($cart_items) === 0 ? 'disabled' : '' ?>">
                            <i class="fas fa-lock"></i> Proceed to Secure Checkout
                        </a>
                        <a href="product_list.php" class="btn btn-outline-primary">
                            <i class="fas fa-shopping-cart"></i> Continue Shopping
                        </a>
                    </div>
                    
                    <!-- Security & Trust -->
                    <div class="text-center">
                        <small class="text-muted d-block mb-2">
                            <i class="fas fa-shield-alt text-success"></i>
                            256-bit SSL Secure Checkout
                        </small>
                        <small class="text-muted d-block mb-2">
                            <i class="fas fa-sync-alt text-info"></i>
                            Prices update in real-time
                        </small>
                        <small class="text-muted d-block">
                            <i class="fas fa-clock text-warning"></i>
                            Cart expires in 24 hours
                        </small>
                    </div>
                    
                    <!-- Market Stats -->
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-chart-line"></i> Live Market Stats</h6>
                        <div class="row small">
                            <div class="col-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Active Price Wars:</span>
                                    <span class="badge bg-danger">
                                        <?= $conn->query("SELECT COUNT(DISTINCT product_id) FROM competitor_prices WHERE price_trend = 'decreasing'")->fetchColumn() ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Products in Cart:</span>
                                    <span><?= count($cart_items) ?></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Cart Abandonment:</span>
                                    <span><?= number_format(($abandonment_stats['total_carts'] ?? 0) / max(1, $total_items) * 100, 0) ?>%</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Best Savings:</span>
                                    <span class="text-success">
                                        <?= count($cart_items) > 0 ? number_format(max(array_column($cart_items, 'savings_percent')), 1) : '0' ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
               
    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h5>Game Theory Pricing Platform</h5>
                    <p class="text-muted">Optimize your purchases with real-time market analysis</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <small>Cart last updated: <?= date('H:i:s') ?></small><br>
                        <small>Market data refreshes every 5 minutes</small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Add to Cart Modal -->
    <div class="modal fade" id="addToCartModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Added to Cart</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="addToCartMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                    <a href="cart.php" class="btn btn-primary">View Cart</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update quantity
        function updateQuantity(productId, change) {
            const input = document.querySelector(`input[name="quantity"][value="${productId}"]`);
            if (input) {
                let current = parseInt(input.value);
                const max = parseInt(input.max);
                const min = parseInt(input.min);
                
                current += change;
                if (current >= min && current <= max) {
                    input.value = current;
                    
                    // Submit form
                    const form = input.closest('form');
                    form.submit();
                }
            }
        }
        
        // Add to cart from recommendations
        document.querySelectorAll('.add-to-cart-btn').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.dataset.productId;
                const productName = this.dataset.productName;
                
                fetch('add_to_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        quantity: 1
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('addToCartMessage').textContent = 
                            `"${productName}" added to cart!`;
                        const modal = new bootstrap.Modal(document.getElementById('addToCartModal'));
                        modal.show();
                        
                        // Reload page to update cart totals
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        alert('Error adding to cart: ' + data.message);
                    }
                });
            });
        });
        
        // Setup price alerts
        function setupPriceAlerts() {
            const cartItems = <?= json_encode(array_column($cart_items, 'product_id')) ?>;
            
            fetch('setup_price_alerts.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_ids: cartItems
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Price alerts set up for ' + data.count + ' items in your cart!');
                } else {
                    alert('Error setting up alerts: ' + data.message);
                }
            });
        }
        
        // View market trends
        function viewMarketTrends() {
            window.open('../index.php#live-market', '_blank');
        }
        
        // Save cart
        function saveCart() {
            const cartName = prompt('Enter a name for your saved cart:');
            if (cartName) {
                fetch('save_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        cart_name: cartName
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Cart saved successfully!');
                    } else {
                        alert('Error saving cart: ' + data.message);
                    }
                });
            }
        }
        
        // Share cart
        function shareCart() {
            alert('Share functionality would generate a unique cart link here.\nThis feature requires additional implementation.');
        }
        
        // Auto-refresh cart prices every 2 minutes
        setInterval(() => {
            const refreshBtn = document.querySelector('button[onclick*="refresh"]');
            if (!refreshBtn) {
                // Check if prices need updating
                fetch('check_price_updates.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.updates_available) {
                            if (confirm('New price updates available. Refresh cart?')) {
                                location.reload();
                            }
                        }
                    });
            }
        }, 120000); // 2 minutes
        
        // Validate checkout
        document.querySelector('a[href="checkout.php"]').addEventListener('click', function(e) {
            <?php if (count($cart_items) === 0): ?>
                e.preventDefault();
                alert('Your cart is empty. Add some items before checkout.');
            <?php else: ?>
                // Check stock availability
                const outOfStock = <?= 
                    json_encode(array_filter($cart_items, fn($item) => $item['stock'] < $item['quantity']))
                ?>;
                
                if (outOfStock.length > 0) {
                    e.preventDefault();
                    alert('Some items in your cart have insufficient stock. Please update quantities.');
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>