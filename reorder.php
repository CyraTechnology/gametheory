<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$order_number = $data['order_number'] ?? '';

if (empty($order_number)) {
    echo json_encode(['success' => false, 'error' => 'Order number required']);
    exit;
}

// Get order items
$items_sql = "SELECT product_id, quantity FROM purchase_orders 
              WHERE order_number = ? AND user_id = ?";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("si", $order_number, $user_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

if (empty($items)) {
    echo json_encode(['success' => false, 'error' => 'No items found']);
    exit;
}

// Add items to cart
$added_count = 0;
foreach ($items as $item) {
    // Check if product exists and is active
    $product_sql = "SELECT id FROM products WHERE id = ? AND is_active = 1 AND stock > 0";
    $product_stmt = $conn->prepare($product_sql);
    $product_stmt->bind_param("i", $item['product_id']);
    $product_stmt->execute();
    $product_result = $product_stmt->get_result();
    
    if ($product_result->num_rows > 0) {
        // Add to cart
        $cart_sql = "INSERT INTO user_cart_items (user_id, product_id, quantity) 
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)";
        $cart_stmt = $conn->prepare($cart_sql);
        $cart_stmt->bind_param("iii", $user_id, $item['product_id'], $item['quantity']);
        $cart_stmt->execute();
        $cart_stmt->close();
        $added_count++;
    }
    $product_stmt->close();
}

echo json_encode([
    'success' => true,
    'message' => "Added $added_count items to cart",
    'count' => $added_count
]);
?>