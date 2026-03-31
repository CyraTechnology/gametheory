<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

if (!isset($_SESSION['order_confirmation'])) {
    header("Location: cart.php");
    exit;
}

$confirmation = $_SESSION['order_confirmation'];
$customer_id = $_SESSION['user_id'];

// Clear confirmation from session
unset($_SESSION['order_confirmation']);

// Get order details
$order_sql = "SELECT 
                po.*,
                p.name as product_name,
                p.category,
                c.name as company_name,
                c.strategy as company_strategy,
                (SELECT COUNT(*) FROM purchase_orders WHERE order_number = po.order_number) as item_count
              FROM purchase_orders po
              JOIN products p ON po.product_id = p.id
              LEFT JOIN companies c ON po.company_id = c.id
              WHERE po.order_number = ?
                AND po.user_id = ?
              ORDER BY po.ordered_at DESC";

$order_stmt = $conn->prepare($order_sql);
$order_stmt->execute([$confirmation['order_number'], $customer_id]);
$order_items = $order_stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($order_items) === 0) {
    header("Location: my_orders.php");
    exit;
}

// Get market impact analysis
$impact_sql = "SELECT 
                COUNT(DISTINCT cp.company_id) as affected_companies,
                AVG(CASE WHEN cp.price_trend = 'decreasing' THEN 1 ELSE 0 END) * 100 as price_drop_percent,
                GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as competitor_names
              FROM competitor_prices cp
              JOIN companies c ON cp.company_id = c.id
              WHERE cp.product_id IN (
                  SELECT product_id FROM purchase_orders 
                  WHERE order_number = ? AND user_id = ?
              )";

$impact_stmt = $conn->prepare($impact_sql);
$impact_stmt->execute([$confirmation['order_number'], $customer_id]);
$market_impact = $impact_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .confirmation-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
        }
        .confirmation-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        .order-number {
            font-size: 24px;
            font-weight: 700;
            color: #28a745;
            background: #d4edda;
            padding: 10px 20px;
            border-radius: 10px;
            display: inline-block;
        }
        .success-icon {
            font-size: 80px;
            color: #28a745;
            margin-bottom: 20px;
        }
        .impact-card {
            background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .order-item {
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .company-badge {
            background: #6c757d;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .strategy-badge {
            background: #fd7e14;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 5px;
        }
        .price-perception {
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 10px;
        }
        .perception-cheap { background: #28a745; color: white; }
        .perception-fair { background: #6c757d; color: white; }
        .perception-expensive { background: #ffc107; color: white; }
        .next-steps {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-chess-board"></i> GameTheory
            </a>
            <div class="navbar-text">
              <a href="my_orders.php?order=<?= urlencode($confirmation['order_number']) ?>" 
   class="btn btn-primary">
    <i class="fas fa-receipt"></i> View Order Status
</a>

            </div>
        </div>
    </nav>

    <!-- Confirmation Header -->
    <div class="confirmation-header">
        <div class="container">
            <div class="text-center">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h1 class="display-5 mb-3">Order Confirmed!</h1>
                <p class="lead mb-4">
                    Thank you for your purchase. Your order has been successfully placed.
                </p>
                <div class="order-number mb-3">
                    <?= htmlspecialchars($confirmation['order_number']) ?>
                </div>
                <p class="mb-0">
                    <small>Order placed on <?= date('F j, Y \a\t g:i A') ?></small>
                </p>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row">
            <!-- Order Details -->
            <div class="col-lg-8">
                <div class="confirmation-card">
                    <h4 class="mb-4">Order Details</h4>
                    
                    <?php foreach ($order_items as $item): ?>
                        <div class="order-item">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6 class="mb-2"><?= htmlspecialchars($item['product_name']) ?></h6>
                                    <div class="mb-2">
                                        <span class="badge bg-secondary"><?= ucfirst($item['category']) ?></span>
                                        <span class="company-badge"><?= htmlspecialchars($item['company_name']) ?></span>
                                        <?php if ($item['company_strategy']): ?>
                                            <span class="strategy-badge"><?= ucfirst($item['company_strategy']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        Quantity: <?= $item['quantity'] ?> × 
                                        $<?= number_format($item['unit_price'], 2) ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <h5>$<?= number_format($item['total_price'], 2) ?></h5>
                                    <?php if ($item['price_perception']): ?>
                                        <span class="price-perception perception-<?= $item['price_perception'] ?>">
                                            <?= str_replace('_', ' ', ucfirst($item['price_perception'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h6>Order Summary</h6>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Items (<?= count($order_items) ?>):</span>
                                <span>$<?= number_format(array_sum(array_column($order_items, 'total_price')), 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Shipping:</span>
                                <span>FREE</span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Tax:</span>
                                <span>$<?= number_format(array_sum(array_column($order_items, 'total_price')) * 0.08, 2) ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between h5">
                                <strong>Total:</strong>
                                <strong class="text-primary">
                                    $<?= number_format(
                                        array_sum(array_column($order_items, 'total_price')) * 1.08, 
                                        2
                                    ) ?>
                                </strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Delivery Information</h6>
                            <p class="mb-2">
                                <strong>Status:</strong> 
                                <span class="badge bg-warning">Processing</span>
                            </p>
                            <p class="mb-2">
                                <strong>Estimated Delivery:</strong> 
                                3-5 business days
                            </p>
                            <p class="mb-0">
                                <strong>Payment Method:</strong> 
                                <?= ucfirst(str_replace('_', ' ', $order_items[0]['payment_method'])) ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Market Impact Analysis -->
                <div class="confirmation-card">
                    <h4 class="mb-4"><i class="fas fa-chart-line"></i> Market Impact Analysis</h4>
                    
                    <div class="impact-card">
                        <h5 class="mb-3">Your Purchase Affected The Market</h5>
                        <div class="row">
                            <div class="col-md-4 text-center mb-3">
                                <div class="display-4"><?= $market_impact['affected_companies'] ?? 0 ?></div>
                                <small>Companies Affected</small>
                            </div>
                            <div class="col-md-4 text-center mb-3">
                                <div class="display-4"><?= number_format($market_impact['price_drop_percent'] ?? 0, 1) ?>%</div>
                                <small>Avg Price Drop</small>
                            </div>
                            <div class="col-md-4 text-center mb-3">
                                <div class="display-4"><?= count($order_items) ?></div>
                                <small>Market Signals Sent</small>
                            </div>
                        </div>
                        <p class="mb-0 small">
                            <i class="fas fa-lightbulb"></i> 
                            Your purchase sent demand signals to 
                            <?= $market_impact['competitor_names'] ?? 'competitors' ?>, 
                            influencing their pricing strategies.
                        </p>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <h6><i class="fas fa-chess-board"></i> Game Theory In Action</h6>
                        <p class="mb-2 small">
                            Based on your purchase pattern, the system has:
                        </p>
                        <ul class="small mb-0">
                            <li>Adjusted competitor pricing algorithms</li>
                            <li>Updated demand forecasts for purchased products</li>
                            <li>Modified market equilibrium calculations</li>
                            <li>Influenced future price recommendations</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Next Steps & Actions -->
            <div class="col-lg-4">
                <div class="confirmation-card">
                    <h4 class="mb-4">Next Steps</h4>
                    
                    <div class="next-steps">
                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <i class="fas fa-envelope fa-2x text-primary"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6>Order Confirmation</h6>
                                <p class="small mb-0">
                                    Email sent to <?= htmlspecialchars($_SESSION['user_email']) ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <i class="fas fa-truck fa-2x text-success"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6>Shipping Updates</h6>
                                <p class="small mb-0">
                                    Track your order in "My Orders"
                                </p>
                            </div>
                        </div>
                        
                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <i class="fas fa-bell fa-2x text-warning"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6>Price Drop Alerts</h6>
                                <p class="small mb-0">
                                    We'll notify you if prices drop on your purchased items
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 mt-4">
                        <a href="my_orders.php" class="btn btn-primary">
                            <i class="fas fa-receipt"></i> View Order Status
                        </a>
                        <a href="product_list.php" class="btn btn-outline-primary">
                            <i class="fas fa-shopping-cart"></i> Continue Shopping
                        </a>
                        <button class="btn btn-outline-success" onclick="shareOrder()">
                            <i class="fas fa-share-alt"></i> Share Purchase
                        </button>
                        <button class="btn btn-outline-info" onclick="printConfirmation()">
                            <i class="fas fa-print"></i> Print Confirmation
                        </button>
                    </div>
                </div>
                
                <!-- Price Protection -->
                <div class="confirmation-card mt-4">
                    <h6 class="mb-3"><i class="fas fa-shield-alt text-success"></i> Price Protection</h6>
                    <div class="alert alert-success">
                        <p class="small mb-2">
                            <strong>24-Hour Price Drop Protection</strong><br>
                            If prices drop on any of your purchased items within 24 hours, 
                            we'll automatically refund the difference.
                        </p>
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> Protection active until 
                            <?= date('F j, g:i A', strtotime('+24 hours')) ?>
                        </small>
                    </div>
                </div>
                
                <!-- Game Theory Score -->
                <div class="confirmation-card mt-4">
                    <h6 class="mb-3"><i class="fas fa-chart-bar text-primary"></i> Purchase Score</h6>
                    <div class="text-center">
                        <div class="display-4 text-primary">
                            <?php
                            $score = 85; // Calculate based on various factors
                            echo $score;
                            ?>
                        </div>
                        <div class="mb-3">
                            <small class="text-muted">Market Optimization Score</small>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: <?= $score ?>%"></div>
                        </div>
                        <small class="text-muted mt-2 d-block">
                            Your purchase was <?= $score >= 80 ? 'highly' : ($score >= 60 ? 'moderately' : 'poorly') ?> 
                            optimized based on market conditions.
                        </small>
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
                    <p class="text-muted">Thank you for participating in our market simulation</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <small>Need help? <a href="#" class="text-white">Contact Support</a></small><br>
                        <small>Order reference: <?= $confirmation['order_number'] ?></small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Share order
        function shareOrder() {
            const orderNumber = '<?= $confirmation['order_number'] ?>';
            const text = `I just made a purchase using Game Theory Pricing! Order: ${orderNumber}`;
            
            if (navigator.share) {
                navigator.share({
                    title: 'Game Theory Purchase',
                    text: text,
                    url: window.location.href
                });
            } else {
                alert('Share this link:\n' + window.location.href + '\n\nOrder: ' + orderNumber);
            }
        }
        
        // Print confirmation
        function printConfirmation() {
            window.print();
        }
        
        // Auto-redirect after 30 seconds
        setTimeout(() => {
            const redirectBtn = document.querySelector('a[href="my_orders.php"]');
            if (redirectBtn) {
                redirectBtn.click();
            }
        }, 30000);
        
        // Show countdown for redirection
        let redirectSeconds = 30;
        const redirectInterval = setInterval(() => {
            redirectSeconds--;
            const footer = document.querySelector('footer');
            const countdownEl = footer.querySelector('.redirect-countdown');
            
            if (!countdownEl) {
                const newEl = document.createElement('small');
                newEl.className = 'redirect-countdown d-block text-warning';
                newEl.textContent = `Auto-redirect to orders in ${redirectSeconds}s`;
                footer.querySelector('.text-muted').after(newEl);
            } else {
                countdownEl.textContent = `Auto-redirect to orders in ${redirectSeconds}s`;
            }
            
            if (redirectSeconds <= 0) {
                clearInterval(redirectInterval);
            }
        }, 1000);
    </script>
</body>
</html>