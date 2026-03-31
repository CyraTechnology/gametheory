<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

try {
    // Check for price updates on cart items
    $check_sql = "SELECT 
                    COUNT(*) as updates_count,
                    SUM(CASE WHEN cp.price < ci.last_checked_price THEN 1 ELSE 0 END) as price_drops,
                    SUM(CASE WHEN cp.price > ci.last_checked_price THEN 1 ELSE 0 END) as price_increases
                  FROM user_cart_items ci
                  JOIN products p ON ci.product_id = p.id
                  LEFT JOIN competitor_prices cp ON ci.product_id = cp.product_id
                    AND cp.last_updated >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  WHERE ci.user_id = ?";
    
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->execute([$user_id]);
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'updates_available' => ($result['updates_count'] ?? 0) > 0,
        'price_drops' => $result['price_drops'] ?? 0,
        'price_increases' => $result['price_increases'] ?? 0
    ]);
    
} catch (PDOException $e) {
    error_log("Check price updates error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>