<?php
require_once '../db.php';
require_once 'customer_auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$customer_id = $_SESSION['user_id'] ?? null;
if (!$customer_id) {
    header("Location: login.php");
    exit;
}

/* ==========================
   CSRF TOKEN
========================== */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ==========================
   CART FETCH
========================== */
$cart_sql = "
    SELECT 
        ci.*,
        p.name,
        p.current_price,
        p.stock,
        p.category,
        p.demand_elasticity,
        p.competition_sensitivity,
        (p.current_price * ci.quantity) AS item_total,
        COALESCE(
            (SELECT AVG(price) FROM competitor_prices WHERE product_id = p.id),
            p.current_price
        ) AS market_avg_price
    FROM user_cart_items ci
    JOIN products p ON ci.product_id = p.id
    WHERE ci.user_id = ?
";

$cart_stmt = $conn->prepare($cart_sql);
$cart_stmt->execute([$customer_id]);
$cart_items = $cart_stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$cart_items) {
    header("Location: cart.php");
    exit;
}

/* ==========================
   STOCK VALIDATION
========================== */
$out_of_stock = [];
foreach ($cart_items as $item) {
    if ($item['stock'] < $item['quantity']) {
        $out_of_stock[] = $item['name'];
    }
}

if ($out_of_stock) {
    $_SESSION['checkout_error'] = "Insufficient stock for: " . implode(', ', $out_of_stock);
    header("Location: cart.php");
    exit;
}

/* ==========================
   TOTAL CALCULATION
========================== */
$subtotal = 0;
$total_items = 0;

foreach ($cart_items as $item) {
    $subtotal += $item['item_total'];
    $total_items += $item['quantity'];
}

$shipping_cost = $subtotal >= 100 ? 0 : 9.99;
$shipping_method = $subtotal >= 100 ? 'free' : 'standard';
$shipping_eta = $subtotal >= 100 ? '2-3 business days' : '3-5 business days';

$tax_rate = 0.08;
$tax = round($subtotal * $tax_rate, 2);
$total = round($subtotal + $shipping_cost + $tax, 2);
$payment_methods = [
    'wallet' => ['icon' => 'fa-wallet', 'label' => 'Pay via Wallet Balance']
];

/* ==========================
   USER INFO
========================== */
$user_stmt = $conn->prepare("
    SELECT u.*,
           (SELECT address FROM user_addresses WHERE user_id = u.id AND is_default = 1 LIMIT 1) AS default_address,
           (SELECT phone FROM user_addresses WHERE user_id = u.id AND is_default = 1 LIMIT 1) AS phone
    FROM users u
    WHERE u.id = ?
");
$user_stmt->execute([$customer_id]);
$user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);

/* ==========================
   VOLATILITY ANALYSIS
========================== */
$volatility_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT cp.product_id) AS volatile_products,
        AVG(cp.price_trend = 'volatile') * 100 AS volatility_percent,
        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS volatile_product_names
    FROM competitor_prices cp
    JOIN products p ON cp.product_id = p.id
    WHERE cp.product_id IN (
        SELECT product_id FROM user_cart_items WHERE user_id = ?
    )
    AND cp.price_trend = 'volatile'
");
$volatility_stmt->execute([$customer_id]);
$volatility = $volatility_stmt->fetch(PDO::FETCH_ASSOC);

/* ==========================
   CHECKOUT SUBMIT
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Security token invalid.";
    } else {

        try {
            /* ==== VALIDATE WALLET BEFORE START ==== */

$wallet_check = $conn->prepare("
SELECT balance FROM wallets WHERE user_id = ?
");
$wallet_check->execute([$customer_id]);
$current_balance = $wallet_check->fetchColumn();

if ($current_balance < $total) {
    throw new Exception("Insufficient wallet balance");
}
            $conn->beginTransaction();

            $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $payment_method = 'wallet';
            $shipping_address = trim($_POST['shipping_address'] ?? '');
            $billing_address = trim($_POST['billing_address'] ?? $shipping_address);
            $notes = trim($_POST['notes'] ?? '');

            foreach ($cart_items as $item) {

                /* ==== COMPANY SELECTION (GAME THEORY) ==== */
                $company_stmt = $conn->prepare("
                    SELECT cp.company_id, c.name, cp.price, c.strategy, c.reputation_score
                    FROM competitor_prices cp
                    JOIN companies c ON cp.company_id = c.id
                    WHERE cp.product_id = ?
                    ORDER BY 
                        FIELD(c.strategy, 'aggressive','defensive','predictive','collaborative'),
                        cp.price ASC,
                        c.reputation_score DESC
                    LIMIT 1
                ");
                $company_stmt->execute([$item['product_id']]);
                $company = $company_stmt->fetch(PDO::FETCH_ASSOC);

                $company_id = $company['company_id'] ?? null;
                $unit_price = $company['price'] ?? $item['current_price'];
                $total_price = $unit_price * $item['quantity'];

                /* ==== PRICE PERCEPTION ==== */
                $market_price = max($item['market_avg_price'], 1);
                $diff = (($unit_price - $market_price) / $market_price) * 100;

                $price_perception = 'fair';
                if ($diff <= -20) $price_perception = 'very_cheap';
                elseif ($diff <= -5) $price_perception = 'cheap';
                elseif ($diff >= 20) $price_perception = 'very_expensive';
                elseif ($diff >= 5) $price_perception = 'expensive';

                /* ==== INSERT ORDER ==== */
                $conn->prepare("
                    INSERT INTO purchase_orders
                    (order_number, user_id, company_id, product_id, quantity,
                     unit_price, total_price, status, payment_method,
                     shipping_address, billing_address, notes, price_perception)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
                ")->execute([
                    $order_number,
                    $customer_id,
                    $company_id,
                    $item['product_id'],
                    $item['quantity'],
                    $unit_price,
                    $total_price,
                    $payment_method,
                    $shipping_address,
                    $billing_address,
                    $notes,
                    $price_perception
                ]);

                /* ==== SAFE STOCK UPDATE ==== */
                $stock_stmt = $conn->prepare("
                    UPDATE products
                    SET stock = stock - ?
                    WHERE id = ? AND stock >= ?
                ");
                $stock_stmt->execute([
                    $item['quantity'],
                    $item['product_id'],
                    $item['quantity']
                ]);

                if ($stock_stmt->rowCount() === 0) {
                    throw new Exception("Stock conflict for {$item['name']}");
                }

                /* ==== DEMAND LOG ==== */
                $conn->prepare("
                    INSERT INTO demand_logs (product_id, company_id, purchases, log_date)
                    VALUES (?, ?, ?, CURDATE())
                    ON DUPLICATE KEY UPDATE purchases = purchases + VALUES(purchases)
                ")->execute([$item['product_id'], $company_id, $item['quantity']]);

                /* ==== PRICE HISTORY ==== */
                $conn->prepare("
                    INSERT INTO price_history
                    (product_id, company_id, old_price, new_price, change_reason)
                    VALUES (?, ?, ?, ?, 'purchase')
                ")->execute([
                    $item['product_id'],
                    $company_id,
                    $item['current_price'],
                    $unit_price
                ]);

                /* ==== MARKET REACTION ==== */
                if ($item['competition_sensitivity'] === 'high') {
                    $conn->prepare("
                        UPDATE competitor_prices
                        SET price = price * 0.99
                        WHERE product_id = ? AND company_id != ?
                    ")->execute([$item['product_id'], $company_id]);
                }
            }
/* ==== WALLET DEDUCTION (ONLY ONCE) ==== */

$wallet_stmt = $conn->prepare("
UPDATE wallets
SET balance = balance - ?
WHERE user_id = ? AND balance >= ?
");

$wallet_stmt->execute([$total, $customer_id, $total]);

if ($wallet_stmt->rowCount() === 0) {
    throw new Exception("Insufficient wallet balance");
}

/* ==== WALLET TRANSACTION ==== */

$conn->prepare("
INSERT INTO wallet_transactions
(user_id,type,amount,description)
VALUES(?,?,?,?)
")->execute([
$customer_id,
'purchase',
$total,
'Order payment using wallet'
]);
            /* ==== CLEAR CART ==== */
            $conn->prepare("DELETE FROM user_cart_items WHERE user_id = ?")
                  ->execute([$customer_id]);

            /* ==== TRANSACTION ==== */
            $conn->prepare("
                INSERT INTO transactions
                (order_number, user_id, amount, payment_method, status)
                VALUES (?, ?, ?, ?, 'completed')
            ")->execute([$order_number, $customer_id, $total, $payment_method]);

            /* ==== USER LOG ==== */
            $conn->prepare("
                INSERT INTO user_logs (user_id, activity_type, details)
                VALUES (?, 'purchase', ?)
            ")->execute([
                $customer_id,
                json_encode([
                    'order' => $order_number,
                    'total' => $total,
                    'items' => $total_items
                ])
            ]);

            $conn->commit();

            $_SESSION['order_confirmation'] = [
                'order_number' => $order_number,
                'total' => $total,
                'items' => $total_items
            ];

            header("Location: order_confirmation.php");
            exit;

        } catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $error = $e->getMessage();
}}
}
/* ==========================
   GET WALLET BALANCE
========================== */

$wallet_stmt = $conn->prepare("
SELECT balance FROM wallets WHERE user_id = ?
");

$wallet_stmt->execute([$customer_id]);

$wallet_balance = $wallet_stmt->fetchColumn() ?? 0;
if($wallet_balance < $total){

$error = "Insufficient wallet balance. Please contact admin to add funds.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Game Theory Pricing</title>
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
        .checkout-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 0;
            margin-bottom: 30px;
        }
        .checkout-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .checkout-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        .checkout-step {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }
        .step-number {
            width: 40px;
            height: 40px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            margin-right: 15px;
            color: #6c757d;
        }
        .step-number.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .step-title {
            font-weight: 600;
            font-size: 18px;
            color: #495057;
        }
        .payment-method-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .payment-method-card:hover {
            border-color: #667eea;
            background-color: #f8f9ff;
        }
        .payment-method-card.selected {
            border-color: #667eea;
            background-color: #f0f2ff;
        }
        .payment-icon {
            font-size: 24px;
            color: #667eea;
            margin-right: 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }
        .order-summary-card {
            background: #f8f9ff;
            border-radius: 10px;
            padding: 25px;
            position: sticky;
            top: 20px;
        }
        .price-breakdown {
            border-top: 1px solid #e9ecef;
            padding-top: 15px;
            margin-top: 15px;
        }
        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .price-row.total {
            font-size: 20px;
            font-weight: 700;
            color: #4e54c8;
            border-top: 2px solid #dee2e6;
            padding-top: 15px;
            margin-top: 15px;
        }
        .security-badge {
            background: #28a745;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
        }
        .game-theory-insight {
            background: linear-gradient(135deg, #4e54c8 0%, #8f94fb 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .volatility-warning {
            border-left: 4px solid #ffc107;
            padding-left: 15px;
            margin-top: 10px;
        }
        .countdown-timer {
            background: #dc3545;
            color: white;
            padding: 10px 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        .cart-item-checkout {
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .game-theory-badge {
            background: #6c757d;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 5px;
        }
        .payment-security {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
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
            <div class="navbar-text ms-auto">
                <span class="text-muted">Secure Checkout</span>
                <span class="security-badge ms-3">
                    <i class="fas fa-lock"></i> 256-bit SSL
                </span>
            </div>
        </div>
    </nav>

    <!-- Checkout Header -->
    <div class="checkout-header">
        <div class="container">
            <h1 class="display-6 mb-3">Secure Checkout</h1>
            <p class="lead mb-0">
                Complete your purchase with real-time market optimized pricing
            </p>
        </div>
    </div>

    <!-- Price Hold Timer -->
    <div class="container">
        <div class="countdown-timer">
            <h5 class="mb-2"><i class="fas fa-clock"></i> Price Hold Expires In</h5>
            <div id="countdown" class="h4">15:00</div>
            <small>Prices are locked for 15 minutes. Complete checkout before timer expires.</small>
        </div>
    </div>

    <div class="container checkout-container">
        <div class="row">
            <!-- Checkout Form -->
            <div class="col-lg-8">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Checkout Steps -->
                <div class="checkout-card">
                    <!-- Step 1: Shipping Information -->
                    <div class="checkout-step">
                        <div class="step-number active">1</div>
                        <div class="step-title">Shipping Information</div>
                    </div>
                    
                    <form id="checkoutForm" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" 
                                       class="form-control" 
                                       name="full_name" 
                                       value="<?= htmlspecialchars($user_info['name'] ?? '') ?>"
                                       required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address *</label>
                                <input type="email" 
                                       class="form-control" 
                                       name="email" 
                                       value="<?= htmlspecialchars($user_info['email'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Phone Number *</label>
                                <input type="tel" 
                                       class="form-control" 
                                       name="phone" 
                                       value="<?= htmlspecialchars($user_info['phone'] ?? '') ?>"
                                       required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Shipping Method</label>
                                <select class="form-select" name="shipping_method" id="shippingMethod">
                                    <option value="standard" <?= $shipping_method === 'standard' ? 'selected' : '' ?>>
                                        Standard Shipping (<?= $shipping_eta ?>) - $<?= number_format($shipping_cost, 2) ?>
                                    </option>
                                    <option value="express" data-cost="24.99">
                                        Express Shipping (1-2 business days) - $24.99
                                    </option>
                                    <option value="overnight" data-cost="49.99">
                                        Overnight Shipping - $49.99
                                    </option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Shipping Address *</label>
                            <textarea class="form-control" 
                                      name="shipping_address" 
                                      rows="3" 
                                      required><?= htmlspecialchars($user_info['default_address'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Billing Address</label>
                            <textarea class="form-control" 
                                      name="billing_address" 
                                      rows="3"><?= htmlspecialchars($user_info['default_address'] ?? '') ?></textarea>
                            <div class="form-check mt-2">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       id="sameAsShipping" 
                                       checked>
                                <label class="form-check-label" for="sameAsShipping">
                                    Same as shipping address
                                </label>
                            </div>
                        </div>
                        
                        <!-- Step 2: Payment Method -->
                        <div class="checkout-step mt-5">
                            <div class="step-number">2</div>
                            <div class="step-title">Payment Method</div>
                        </div>
                        
                        
                              <div class="alert alert-success mt-3">

<i class="fa fa-wallet"></i>

<strong>Wallet Balance:</strong>

₹<?= number_format($wallet_balance,2) ?>

</div>
                        </div>
                        
                        <!-- Step 3: Review & Confirm -->
                        <div class="checkout-step mt-5">
                            <div class="step-number">3</div>
                            <div class="step-title">Review & Confirm</div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Order Notes (Optional)</label>
                            <textarea class="form-control" 
                                      name="notes" 
                                      rows="3" 
                                      placeholder="Special instructions, delivery preferences, etc."></textarea>
                        </div>
                        
                        <!-- Terms & Conditions -->
                        <div class="mb-4">
                            <div class="form-check">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       id="terms" 
                                       required>
                                <label class="form-check-label" for="terms">
                                    I agree to the 
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a>
                                    and confirm that I have read the 
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Game Theory Insights -->
                        <?php if ($volatility['volatile_products'] > 0): ?>
                            <div class="volatility-warning">
                                <h6><i class="fas fa-exclamation-triangle text-warning"></i> Market Volatility Alert</h6>
                                <p class="small mb-2">
                                    <?= $volatility['volatile_products'] ?> products in your cart are experiencing 
                                    high price volatility (<?= number_format($volatility['volatility_percent'], 1) ?>%).
                                </p>
                                <small class="text-muted">
                                    Prices may change rapidly. Complete purchase now to lock in current prices.
                                </small>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Payment Security -->
                        <div class="payment-security">
                            <h6><i class="fas fa-shield-alt text-success"></i> Payment Security</h6>
                            <div class="row small">
                                <div class="col-6">
                                    <i class="fas fa-check text-success"></i> 256-bit Encryption
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-check text-success"></i> PCI DSS Compliant
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-check text-success"></i> Fraud Protection
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-check text-success"></i> Secure SSL
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-lock"></i> Complete Secure Purchase - $<?= number_format($total, 2) ?>
                            </button>
                            <a href="cart.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left"></i> Return to Cart
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="col-lg-4">
                <div class="order-summary-card">
                    <h5 class="mb-4">Order Summary</h5>
                    
                    <!-- Cart Items -->
                    <div class="mb-4">
                        <h6 class="mb-3">Items (<?= $total_items ?>)</h6>
                        <?php foreach ($cart_items as $item): ?>
                            <div class="cart-item-checkout">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                                        <small class="text-muted">
                                            Qty: <?= $item['quantity'] ?> × 
                                            $<?= number_format($item['current_price'], 2) ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <strong>$<?= number_format($item['item_total'], 2) ?></strong>
                                        <?php if ($item['demand_elasticity'] < -2): ?>
                                            <span class="game-theory-badge" title="High price sensitivity">
                                                <i class="fas fa-chart-line"></i> Sensitive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($item['competition_sensitivity'] === 'high'): ?>
                                    <small class="text-info">
                                        <i class="fas fa-chess"></i> High competition - price may change
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Price Breakdown -->
                    <div class="price-breakdown">
                        <div class="price-row">
                            <span>Subtotal</span>
                            <span>$<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="price-row">
                            <span>Shipping</span>
                            <span id="shippingCost">$<?= number_format($shipping_cost, 2) ?></span>
                        </div>
                        <div class="price-row">
                            <span>Tax (<?= ($tax_rate * 100) ?>%)</span>
                            <span id="taxAmount">$<?= number_format($tax, 2) ?></span>
                        </div>
                        <div class="price-row total">
                            <span>Total</span>
                            <span id="totalAmount">$<?= number_format($total, 2) ?></span>
                        </div>
                    </div>
                    
                    <!-- Game Theory Insight -->
                    <div class="game-theory-insight">
                        <h6><i class="fas fa-chess-board"></i> Game Theory Insight</h6>
                        <p class="small mb-2">
                            Your purchase influences market prices. The system will:
                        </p>
                        <ul class="small mb-0">
                            <li>Automatically select optimal sellers</li>
                            <li>Adjust competitor prices based on demand</li>
                            <li>Update market equilibrium calculations</li>
                        </ul>
                    </div>
                    
                    <!-- Savings Summary -->
                    <div class="mt-4">
                        <h6><i class="fas fa-piggy-bank text-success"></i> Your Savings</h6>
                        <div class="row small">
                            <div class="col-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Market Avg:</span>
                                    <span>$<?= 
                                        number_format(array_sum(array_column($cart_items, 'market_avg_price')) / count($cart_items), 2) 
                                    ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>You Pay:</span>
                                    <span>$<?= number_format($subtotal / $total_items, 2) ?></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Savings:</span>
                                    <span class="text-success">
                                        $<?= number_format(
                                            array_sum(array_column($cart_items, 'market_avg_price')) - $subtotal, 
                                            2
                                        ) ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Savings %:</span>
                                    <span class="text-success">
                                        <?= number_format(
                                            (array_sum(array_column($cart_items, 'market_avg_price')) - $subtotal) / 
                                            array_sum(array_column($cart_items, 'market_avg_price')) * 100, 
                                            1
                                        ) ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Price Lock Guarantee -->
                    <div class="alert alert-info mt-4">
                        <h6><i class="fas fa-lock"></i> Price Lock Guarantee</h6>
                        <p class="small mb-0">
                            Your prices are locked for 15 minutes. If prices drop within 24 hours of purchase, 
                            we'll refund the difference.
                        </p>
                    </div>
                    
                    <!-- Trust Badges -->
                    <div class="text-center mt-4">
                        <div class="row">
                            <div class="col-4">
                                <i class="fas fa-shield-alt fa-2x text-success mb-2"></i>
                                <small class="d-block">Secure</small>
                            </div>
                            <div class="col-4">
                                <i class="fas fa-bolt fa-2x text-warning mb-2"></i>
                                <small class="d-block">Fast</small>
                            </div>
                            <div class="col-4">
                                <i class="fas fa-chart-line fa-2x text-primary mb-2"></i>
                                <small class="d-block">Smart</small>
                            </div>
                        </div>
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
                    <p class="text-muted">Intelligent market-optimized checkout system</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <small>Transaction ID: <?= 'TXN-' . strtoupper(uniqid()) ?></small><br>
                        <small>Session secured with 256-bit SSL</small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Terms Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terms & Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Terms content here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Privacy Policy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Privacy policy content here -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/cleave.js@1.6.0/dist/cleave.min.js"></script>
    <script>
        // Countdown timer
        let timeLeft = 15 * 60; // 15 minutes in seconds
        const countdownElement = document.getElementById('countdown');
        
        function updateCountdown() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            countdownElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft === 0) {
                clearInterval(countdownTimer);
                alert('Price hold has expired. Prices will be recalculated.');
                location.reload();
            } else {
                timeLeft--;
                
                // Warning at 5 minutes
                if (timeLeft === 5 * 60) {
                    countdownElement.style.backgroundColor = '#ffc107';
                    countdownElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${countdownElement.textContent}`;
                }
                
                // Warning at 1 minute
                if (timeLeft === 60) {
                    countdownElement.style.backgroundColor = '#dc3545';
                }
            }
        }
        
        const countdownTimer = setInterval(updateCountdown, 1000);
        
        // Credit card formatting
        const cardNumber = new Cleave('.form-control[placeholder*="Card Number"]', {
            creditCard: true,
            onCreditCardTypeChanged: function(type) {
                const icon = document.querySelector('.payment-icon .fa-credit-card');
                if (type === 'visa') {
                    icon.className = 'fab fa-cc-visa';
                } else if (type === 'mastercard') {
                    icon.className = 'fab fa-cc-mastercard';
                } else if (type === 'amex') {
                    icon.className = 'fab fa-cc-amex';
                } else {
                    icon.className = 'fas fa-credit-card';
                }
            }
        });
        
        // Expiry date formatting
        new Cleave('.form-control[placeholder*="MM/YY"]', {
            date: true,
            datePattern: ['m', 'y']
        });
        
        // Payment method selection
        document.querySelectorAll('.payment-method-card').forEach(card => {
            card.addEventListener('click', function() {
                // Update radio button
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Update UI
                document.querySelectorAll('.payment-method-card').forEach(c => {
                    c.classList.remove('selected');
                });
                this.classList.add('selected');
                
                // Show/hide credit card details
                const method = this.dataset.method;
                const details = document.getElementById('creditCardDetails');
                if (details) {
                    details.style.display = method === 'credit_card' ? 'block' : 'none';
                }
            });
        });
        
        // Shipping method change
        document.getElementById('shippingMethod').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const shippingCost = selectedOption.dataset.cost || <?= $shipping_cost ?>;
            const subtotal = <?= $subtotal ?>;
            const taxRate = <?= $tax_rate ?>;
            
            // Update shipping cost
            const shippingElement = document.getElementById('shippingCost');
            shippingElement.textContent = shippingCost == 0 ? 'FREE' : '$' + parseFloat(shippingCost).toFixed(2);
            
            // Recalculate tax and total
            const tax = subtotal * taxRate;
            const total = parseFloat(subtotal) + parseFloat(shippingCost) + tax;
            
            document.getElementById('taxAmount').textContent = '$' + tax.toFixed(2);
            document.getElementById('totalAmount').textContent = '$' + total.toFixed(2);
            
            // Update button text
            const submitBtn = document.querySelector('button[type="submit"]');
            submitBtn.innerHTML = `<i class="fas fa-lock"></i> Complete Secure Purchase - $${total.toFixed(2)}`;
        });
        
        // Same as shipping address
        document.getElementById('sameAsShipping').addEventListener('change', function() {
            const billingAddress = document.querySelector('textarea[name="billing_address"]');
            if (this.checked) {
                billingAddress.value = document.querySelector('textarea[name="shipping_address"]').value;
                billingAddress.disabled = true;
            } else {
                billingAddress.disabled = false;
            }
        });
        
        // Form validation
        document.getElementById('checkoutForm').addEventListener('submit', function(e) {
            const terms = document.getElementById('terms').checked;
            
            if (!terms) {
                e.preventDefault();
                alert('You must agree to the Terms & Conditions to proceed.');
                return false;
            }
            
            // Validate credit card if selected
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            if (paymentMethod === 'credit_card') {
                const cardNumber = document.querySelector('.form-control[placeholder*="Card Number"]').value.replace(/\s/g, '');
                const expiry = document.querySelector('.form-control[placeholder*="MM/YY"]').value;
                const cvv = document.querySelector('.form-control[placeholder*="CVV"]').value;
                
                if (cardNumber.length !== 16 || !/^\d+$/.test(cardNumber)) {
                    e.preventDefault();
                    alert('Please enter a valid 16-digit card number.');
                    return false;
                }
                
                if (!/^\d{2}\/\d{2}$/.test(expiry)) {
                    e.preventDefault();
                    alert('Please enter a valid expiry date (MM/YY).');
                    return false;
                }
                
                if (cvv.length !== 3 || !/^\d+$/.test(cvv)) {
                    e.preventDefault();
                    alert('Please enter a valid 3-digit CVV.');
                    return false;
                }
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            return true;
        });
        
        // Auto-save form data
        const formInputs = document.querySelectorAll('#checkoutForm input, #checkoutForm textarea, #checkoutForm select');
        formInputs.forEach(input => {
            input.addEventListener('change', function() {
                const formData = {};
                formInputs.forEach(i => {
                    if (i.name) {
                        formData[i.name] = i.value;
                    }
                });
                localStorage.setItem('checkout_form_data', JSON.stringify(formData));
            });
        });
        
        // Load saved form data
        window.addEventListener('load', function() {
            const savedData = localStorage.getItem('checkout_form_data');
            if (savedData) {
                const formData = JSON.parse(savedData);
                formInputs.forEach(input => {
                    if (input.name && formData[input.name]) {
                        input.value = formData[input.name];
                    }
                });
            }
        });
        
        // Prevent accidental navigation
        window.addEventListener('beforeunload', function(e) {
            if (document.getElementById('checkoutForm') && !submitted) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        let submitted = false;
        document.getElementById('checkoutForm').addEventListener('submit', function() {
            submitted = true;
            localStorage.removeItem('checkout_form_data');
        });
    </script>
</body>
</html>