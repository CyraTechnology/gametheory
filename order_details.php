<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

$order_number = $_GET['order_number'] ?? '';
$user_id = $_SESSION['user_id'];

// Get order details
$order_sql = "SELECT 
                po.*,
                p.name as product_name,
                p.category,
                p.description,
                p.image_url,
                c.name as company_name,
                c.strategy as company_strategy,
                c.market_share,
                u.name as customer_name,
                u.email as customer_email,
                (SELECT AVG(price) FROM competitor_prices 
                 WHERE product_id = po.product_id 
                 AND last_updated >= po.ordered_at) as market_avg_at_purchase,
                (SELECT MIN(price) FROM competitor_prices 
                 WHERE product_id = po.product_id 
                 AND last_updated >= po.ordered_at) as market_min_at_purchase
              FROM purchase_orders po
              LEFT JOIN products p ON po.product_id = p.id
              LEFT JOIN companies c ON po.company_id = c.id
              LEFT JOIN users u ON po.user_id = u.id
              WHERE po.order_number = ? AND po.user_id = ?
              ORDER BY po.ordered_at DESC";

$order_stmt = $conn->prepare($order_sql);
$order_stmt->bind_param("si", $order_number, $user_id);
$order_stmt->execute();
$order_items = $order_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$order_stmt->close();

if (empty($order_items)) {
    header("Location: my_orders.php");
    exit();
}

// Calculate order totals
$order_total = array_sum(array_column($order_items, 'total_price'));
$item_count = count($order_items);
$first_item = $order_items[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Order Details: <?= htmlspecialchars($order_number) ?></h2>
            <a href="my_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Orders
            </a>
        </div>
        
        <!-- Display order details here -->
        <div class="card">
            <div class="card-body">
                <!-- Order summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Order Information</h5>
                        <p><strong>Order Date:</strong> <?= date('F j, Y, g:i A', strtotime($first_item['ordered_at'])) ?></p>
                        <p><strong>Status:</strong> 
                            <span class="badge bg-<?= 
                                $first_item['status'] === 'delivered' ? 'success' : 
                                ($first_item['status'] === 'pending' ? 'warning' : 
                                ($first_item['status'] === 'processing' ? 'info' : 'secondary'))
                            ?>">
                                <?= ucfirst($first_item['status']) ?>
                            </span>
                        </p>
                        <p><strong>Payment Method:</strong> <?= ucfirst(str_replace('_', ' ', $first_item['payment_method'])) ?></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Order Summary</h5>
                        <p><strong>Items:</strong> <?= $item_count ?></p>
                        <p><strong>Order Total:</strong> $<?= number_format($order_total, 2) ?></p>
                        <?php if ($first_item['delivered_at']): ?>
                            <p><strong>Delivered On:</strong> <?= date('F j, Y', strtotime($first_item['delivered_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order items -->
                <h5 class="mb-3">Order Items</h5>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Company</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td><?= htmlspecialchars($item['company_name'] ?? 'N/A') ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td>$<?= number_format($item['unit_price'], 2) ?></td>
                                    <td>$<?= number_format($item['total_price'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>