<?php
session_start();
require '../db.php';
require 'customer_auth.php';

$product_id = $_GET['id'] ?? 0;

if(!$product_id){
    header("Location: product_list.php");
    exit;
}

$stmt = $conn->prepare("
SELECT 
    p.id,
    p.name,
    p.category,
    p.current_price,
    p.stock,
    p.image_url,
    p.description,

    AVG(cp.price) as market_avg_price,
    MIN(cp.price) as market_lowest_price,
    MAX(cp.price) as market_highest_price,
    COUNT(DISTINCT cp.competitor_name) as competitor_count,
    MAX(cp.demand_level) as demand_level,
    MAX(cp.price_trend) as price_trend,

    (SELECT COUNT(*) FROM purchase_orders WHERE product_id = p.id) as total_sales,
    (SELECT COUNT(*) FROM product_analytics WHERE product_id = p.id AND event_type = 'view') as total_views

FROM products p
LEFT JOIN competitor_prices cp ON p.id = cp.product_id
WHERE p.id = ?
GROUP BY p.id
");

$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$product){
    header("Location: product_list.php");
    exit;
}

/* GET COMPETITOR PRICES */
$competitors = $conn->prepare("
    SELECT * FROM competitor_prices 
    WHERE product_id = ? 
    ORDER BY price ASC
");
$competitors->execute([$product_id]);
$competitor_list = $competitors->fetchAll(PDO::FETCH_ASSOC);

/* GET PRICE HISTORY */
$price_history = $conn->prepare("
    SELECT price, created_at 
    FROM price_history 
    WHERE product_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$price_history->execute([$product_id]);
$history = $price_history->fetchAll(PDO::FETCH_ASSOC);

/* GET RELATED PRODUCTS */
$related = $conn->prepare("
    SELECT id, name, current_price, image_url
FROM products
WHERE category = ? AND id != ?
LIMIT 4
");
$related->execute([$product['category'], $product_id]);
$related_products = $related->fetchAll(PDO::FETCH_ASSOC);

/* LOG PRODUCT VIEW */

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$device = $_SERVER['HTTP_USER_AGENT'] ?? '';

$conn->prepare("
    INSERT INTO product_views
    (product_id, user_id, seller_id, viewed_at, ip_address, device)
    VALUES (?, ?, ?, NOW(), ?, ?)
")->execute([
    $product_id,
    $_SESSION['user_id'] ?? null,
    0,
    $ip,
    $device
]);



/* CALCULATE PRICE POSITION */
$price_difference = $product['market_lowest_price'] ? $product['current_price'] - $product['market_lowest_price'] : 0;
$price_difference_percent = $product['market_lowest_price'] ? round(($price_difference / $product['market_lowest_price']) * 100, 1) : 0;
$savings = $price_difference > 0 ? "Pay $".number_format($price_difference, 2)." more than lowest" : "Best price in market";

$stock_class = $product['stock'] > 50 ? 'stock-high' : ($product['stock'] > 10 ? 'stock-medium' : 'stock-low');
$trend_icon = $product['price_trend'] === 'increasing' ? 'fa-arrow-up' : 
              ($product['price_trend'] === 'decreasing' ? 'fa-arrow-down' : 
              ($product['price_trend'] === 'volatile' ? 'fa-random' : 'fa-minus'));
$trend_class = $product['price_trend'] === 'increasing' ? 'price-trend-up' : 
               ($product['price_trend'] === 'decreasing' ? 'price-trend-down' : 
               ($product['price_trend'] === 'volatile' ? 'price-trend-volatile' : 'price-trend-stable'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> - Game Theory Pricing</title>
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
        .product-img img{
width:100%;
height:100%;
object-fit:cover;
border-radius:10px;
}
        .product-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s;
            height: 100%;
            background: white;
        }
        .product-img {
            height: 400px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 80px;
            position: relative;
        }
        .price-tag {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: bold;
            font-size: 24px;
        }
        .demand-badge {
            position: absolute;
            top: 20px;
            left: 20px;
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 30px;
        }
        .price-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .price-best { background: #28a745; color: white; }
        .price-good { background: #20c997; color: white; }
        .price-market { background: #6c757d; color: white; }
        .demand-low { background: #dc3545; color: white; }
        .demand-medium { background: #ffc107; color: #000; }
        .demand-high { background: #28a745; color: white; }
        .demand-critical { background: #007bff; color: white; }
        .price-trend-up { color: #dc3545; }
        .price-trend-down { color: #28a745; }
        .price-trend-stable { color: #6c757d; }
        .price-trend-volatile { color: #fd7e14; }
        .competition-badge {
            background: #e9ecef;
            color: #495057;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .stock-indicator {
            height: 8px;
            border-radius: 4px;
            margin-top: 5px;
            width: 100%;
        }
        .stock-high { background: #28a745; }
        .stock-medium { background: #ffc107; }
        .stock-low { background: #dc3545; }
        .game-theory-badge {
            background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
        }
        .feature-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            background: white;
            height: 100%;
        }
        .competitor-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .competitor-item:last-child {
            border-bottom: none;
        }
        .price-history-chart {
            height: 200px;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
        }
        .rating-stars {
            color: #ffc107;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-success {
            background: #28a745;
            border: none;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        .back-link {
            margin-bottom: 20px;
            display: inline-block;
        }
        .info-label {
            color: #6c757d;
            font-size: 14px;
        }
        .info-value {
            font-weight: 600;
            font-size: 16px;
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
                        <a class="nav-link active" href="product_list.php">
                            <i class="fas fa-store"></i> Shop
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my_orders.php">
                            <i class="fas fa-receipt"></i> My Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php#live-market">
                            <i class="fas fa-chart-line"></i> Live Market
                        </a>
                    </li>
                </ul>
                <form class="d-flex" method="GET" action="product_list.php">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search products...">
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
                <ul class="navbar-nav ms-3">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="customer_dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                            <li><a class="dropdown-item" href="wallet.php">Wallet</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <a href="product_list.php" class="text-white back-link">
                <i class="fas fa-arrow-left me-2"></i> Back to Products
            </a>
            <h1 class="display-6 mb-3">Product Details</h1>
            <p class="lead mb-0">
                Live pricing and market analysis
                <span class="badge bg-warning ms-2">
                    <i class="fas fa-bolt"></i> Real-time Data
                </span>
            </p>
        </div>
    </div>

    <div class="container">
        <!-- Product Main Section -->
        <div class="row">
            <!-- Product Image -->
            <div class="col-md-6 mb-4">
<div class="product-img position-relative p-0">

<?php if(!empty($product['image_url'])){ ?>

<img loading="lazy"  src="../<?= htmlspecialchars($product['image_url']) ?>"
     style="width:100%; height:400px; object-fit:cover;">

<?php } else { ?>

<i class="fas fa-box-open"></i>

<?php } ?>
                    <span class="price-tag">
                        $<?= number_format($product['current_price'], 2) ?>
                    </span>
                    <span class="demand-badge badge bg-<?= $product['demand_level'] ?>">
                        <i class="fas fa-chart-line me-1"></i>
                        <?= strtoupper($product['demand_level']) ?> DEMAND
                    </span>
                </div>
            </div>

            <!-- Product Info -->
            <div class="col-md-6 mb-4">
                <div class="feature-card">
                    <h2 class="mb-3"><?= htmlspecialchars($product['name']) ?></h2>
                    
                    <!-- Price Position -->
                    <div class="mb-4">
                        <span class="game-theory-badge mb-2">
                            <i class="fas fa-chess-board"></i> Game Theory Analysis
                        </span>
                        <div class="mt-2">
                            <span class="price-badge price-best me-2">Best Price Available</span>
                            <span class="<?= $trend_class ?> ms-2">
                                <i class="fas <?= $trend_icon ?>"></i> 
                                <?= ucfirst($product['price_trend']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Key Metrics -->
                    <div class="row mb-4">
                        <div class="col-6">
                            <div class="info-label">Market Average</div>
                            <div class="info-value">$<?= number_format($product['market_avg_price'] ?? $product['current_price'], 2) ?></div>
                        </div>
                        <div class="col-6">
                            <div class="info-label">Lowest Price</div>
                            <div class="info-value text-success">$<?= number_format($product['market_lowest_price'] ?? $product['current_price'], 2) ?></div>
                        </div>
                        <div class="col-6 mt-3">
                            <div class="info-label">Competitors</div>
                            <div class="info-value"><?= $product['competitor_count'] ?> sellers</div>
                        </div>
                        <div class="col-6 mt-3">
                            <div class="info-label">Total Sales</div>
                            <div class="info-value"><?= number_format($product['total_sales'] ?? 0) ?></div>
                        </div>
                    </div>

                    <!-- Stock Status -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="info-label">Stock Status</span>
                            <span class="info-value"><?= $product['stock'] ?> units left</span>
                        </div>
                        <div class="stock-indicator <?= $stock_class ?>" style="width: <?= min(100, ($product['stock'] / 100) * 100) ?>%;"></div>
                    </div>

                    <!-- Price Difference Alert -->
                    <?php if($price_difference > 0): ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Price Alert:</strong> You're paying $<?= number_format($price_difference, 2) ?> (<?= $price_difference_percent ?>%) more than the lowest price
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mb-4">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Best Price!</strong> This is the lowest price in the market
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <button class="btn btn-success btn-sm add-to-cart" 
                                                    data-product-id="<?= $product['id'] ?>"
                                                    data-product-name="<?= htmlspecialchars($product['name']) ?>">
                                                <i class="fas fa-cart-plus"></i> Add to Cart
                                            </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs Section -->
        <div class="row mt-4">
            <div class="col-12">
                <ul class="nav nav-tabs" id="productTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="competitors-tab" data-bs-toggle="tab" data-bs-target="#competitors" type="button" role="tab">
                            <i class="fas fa-store"></i> Competitor Prices
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                            <i class="fas fa-chart-line"></i> Price History
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="insights-tab" data-bs-toggle="tab" data-bs-target="#insights" type="button" role="tab">
                            <i class="fas fa-lightbulb"></i> Market Insights
                        </button>
                    </li>
                </ul>

                <div class="tab-content p-4 bg-white border border-top-0 rounded-bottom" id="productTabsContent">
                    <!-- Competitor Prices Tab -->
                    <div class="tab-pane fade show active" id="competitors" role="tabpanel">
                        <h5 class="mb-4">Competitor Pricing Analysis</h5>
                        <?php if(count($competitor_list) > 0): ?>
                            <?php foreach($competitor_list as $comp): ?>
                                <div class="competitor-item">
                                    <div>
                                        <strong><?= htmlspecialchars($comp['competitor_name']) ?></strong>
                                        <span class="badge bg-<?= $comp['demand_level'] ?> ms-2">
                                            <?= ucfirst($comp['demand_level']) ?> Demand
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold">$<?= number_format($comp['price'], 2) ?></span>
                                        <?php if($comp['price'] < $product['current_price']): ?>
                                            <span class="badge bg-success ms-2">Lower</span>
                                        <?php elseif($comp['price'] > $product['current_price']): ?>
                                            <span class="badge bg-danger ms-2">Higher</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary ms-2">Equal</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No competitor data available</p>
                        <?php endif; ?>
                    </div>

                    <!-- Price History Tab -->
                    <div class="tab-pane fade" id="history" role="tabpanel">
                        <h5 class="mb-4">Price History (Last 10 Updates)</h5>
                        <?php if(count($history) > 0): ?>
                            <div class="price-history-chart">
                                <!-- Simple bar chart representation -->
                                <?php 
                                $max_price = max(array_column($history, 'price'));
                                $min_price = min(array_column($history, 'price'));
                                ?>
                                <?php foreach($history as $h): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <div style="width: 100px;"><?= date('d M H:i', strtotime($h['created_at'])) ?></div>
                                        <div style="flex:1; margin:0 15px;">
                                            <div style="height:30px; width:<?= ($h['price'] / $max_price) * 100 ?>%; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius:5px;"></div>
                                        </div>
                                        <div class="fw-bold">$<?= number_format($h['price'], 2) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No price history available</p>
                        <?php endif; ?>
                    </div>

                    <!-- Market Insights Tab -->
                    <div class="tab-pane fade" id="insights" role="tabpanel">
                        <h5 class="mb-4">Game Theory Market Insights</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="feature-card h-100">
                                    <h6><i class="fas fa-fire text-warning"></i> Demand Analysis</h6>
                                    <p>Current demand level: <strong class="text-<?= $product['demand_level'] === 'high' ? 'success' : ($product['demand_level'] === 'medium' ? 'warning' : 'danger') ?>"><?= strtoupper($product['demand_level']) ?></strong></p>
                                    <p>Based on <?= $product['total_views'] ?? 0 ?> views and competitor activity</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card h-100">
                                    <h6><i class="fas fa-chart-pie text-primary"></i> Price Strategy</h6>
                                    <p>Optimal price: <strong>$<?= number_format($product['market_lowest_price'] ?? $product['current_price'] * 0.95, 2) ?></strong></p>
                                    <p>Potential savings: <span class="text-success">$<?= number_format($product['market_lowest_price'] ? $product['current_price'] - $product['market_lowest_price'] : 0, 2) ?></span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Related Products -->
        <?php if(count($related_products) > 0): ?>
            <div class="row mt-5">
                <div class="col-12">
                    <h4 class="mb-4"><i class="fas fa-tags me-2"></i> Related Products</h4>
                </div>
                <?php foreach($related_products as $rel): ?>
                    <div class="col-md-3">
                        <div class="product-card">
                            <div class="product-img position-relative" style="height:150px;">
                                <?php if(!empty($rel['image_url'])){ ?>

<img loading="lazy"  src="../<?= htmlspecialchars($rel['image_url']) ?>"
     style="width:100%; height:150px; object-fit:cover;">

<?php } else { ?>

<i class="fas fa-box"></i>

<?php } ?>
                                <span class="price-tag" style="font-size:16px; padding:5px 10px;">
                                    $<?= number_format($rel['current_price'], 2) ?>
                                </span>
                            </div>
                            <div class="p-3">
                                <h6><?= htmlspecialchars($rel['name']) ?></h6>
                                <a href="product_view.php?id=<?= $rel['id'] ?>" class="btn btn-outline-primary btn-sm w-100 mt-2">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add to Cart Modal -->
    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Added to Cart</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                   <p id="cartMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                    <a href="cart.php" class="btn btn-primary">View Cart</a>
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
                    <p class="text-muted">Real-time market competition simulation</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <small>Prices update every 5 minutes</small><br>
                        <small><?= date('Y-m-d H:i:s') ?></small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
// ADD TO CART
  // Add to cart functionality
        document.querySelectorAll('.add-to-cart').forEach(button => {
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
                        document.getElementById('cartMessage').textContent = 
                            `"${productName}" added to cart!`;
                        const cartModal = new bootstrap.Modal(document.getElementById('cartModal'));
                        cartModal.show();
                        
                        // Update cart count in navbar
                        const cartBadge = document.querySelector('.navbar .fa-shopping-cart').parentElement;
                        if (!cartBadge.querySelector('.badge')) {
                            cartBadge.innerHTML += '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">1</span>';
                        } else {
                            const badge = cartBadge.querySelector('.badge');
                            badge.textContent = parseInt(badge.textContent) + 1;
                        }
                    } else {
                        alert('Error adding to cart: ' + data.message);
                    }
                })
                
            });
        });
        

    </script>
</body>
</html>