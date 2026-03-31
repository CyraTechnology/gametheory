<?php
session_start();
require_once '../db.php';
require_once 'customer_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$product_ids = $data['product_ids'] ?? [];

if (empty($product_ids)) {
    echo json_encode(['success' => false, 'message' => 'No products specified']);
    exit;
}

try {
    $count = 0;
    
    foreach ($product_ids as $product_id) {
        // Get current competitor prices for this product
        $competitor_sql = "SELECT cp.id, cp.company_id, cp.price 
                          FROM competitor_prices cp
                          WHERE cp.product_id = ?
                          ORDER BY cp.price ASC
                          LIMIT 3";
        
        $competitor_stmt = $conn->prepare($competitor_sql);
        $competitor_stmt->execute([$product_id]);
        $competitors = $competitor_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($competitors as $competitor) {
            // Check if alert already exists
            $check_sql = "SELECT id FROM price_alerts 
                         WHERE user_id = ? AND product_id = ? AND competitor_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->execute([$user_id, $product_id, $competitor['company_id']]);
            
            if (!$check_stmt->fetch()) {
                // Create new price alert
                $insert_sql = "INSERT INTO price_alerts (user_id, product_id, competitor_id, tracked_price) 
                              VALUES (?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([
                    $user_id, 
                    $product_id, 
                    $competitor['company_id'], 
                    $competitor['price']
                ]);
                $count++;
            }
        }
    }
    
    echo json_encode(['success' => true, 'count' => $count, 'message' => "Price alerts set up for $count items"]);
    
} catch (PDOException $e) {
    error_log("Setup price alerts error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>