<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$product_id = $data['product_id'] ?? null;
$quantity = $data['quantity'] ?? 1;

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit;
}

try {
    // Check product availability
    $product_sql = "SELECT id, name, current_price, stock FROM products WHERE id = ? AND is_active = 1";
    $product_stmt = $conn->prepare($product_sql);
    $product_stmt->execute([$product_id]);
    $product = $product_stmt->fetch();
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    if ($product['stock'] < $quantity) {
        echo json_encode(['success' => false, 'message' => 'Insufficient stock']);
        exit;
    }
    
    // Check if product already in cart
    $check_sql = "SELECT id, quantity FROM user_cart_items WHERE user_id = ? AND product_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->execute([$_SESSION['user_id'], $product_id]);
    $existing_item = $check_stmt->fetch();
    
    if ($existing_item) {
        // Update quantity
        $update_sql = "UPDATE user_cart_items SET quantity = quantity + ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->execute([$quantity, $existing_item['id']]);
    } else {
        // Add new item
        $insert_sql = "INSERT INTO user_cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->execute([$_SESSION['user_id'], $product_id, $quantity]);
    }
    
    // Log cart activity
    $log_sql = "INSERT INTO demand_logs (product_id, user_id, abandoned_carts, log_date) 
                VALUES (?, ?, 1, CURDATE()) 
                ON DUPLICATE KEY UPDATE abandoned_carts = abandoned_carts + 1";
    $conn->prepare($log_sql)->execute([$product_id, $_SESSION['user_id']]);
    
    echo json_encode(['success' => true, 'message' => 'Product added to cart']);
    
} catch (PDOException $e) {
    error_log("Add to cart error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
$conn->prepare("
INSERT INTO product_interactions
(product_id,user_id,interaction_type)
VALUES(?,?,?)
")->execute([
$product_id,
$_SESSION['user_id'],
'add_to_cart'
]);

?>