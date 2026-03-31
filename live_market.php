<?php
require '../db.php';

header('Content-Type: application/json');


$priceChanges = $db->fetchAll("
SELECT ph.old_price,ph.new_price,
p.name as product_name,
c.name as company_name,
DATE_FORMAT(ph.changed_at,'%H:%i:%s') as time
FROM price_history ph
JOIN products p ON ph.product_id=p.id
LEFT JOIN companies c ON ph.company_id=c.id
ORDER BY ph.changed_at DESC
LIMIT 10
");


$stats = $db->fetchOne("
SELECT 
COUNT(DISTINCT product_id) as products,
COUNT(DISTINCT competitor_name) as companies,
AVG(price) as avg_changes,
SUM(CASE WHEN price_trend='decreasing' THEN 1 ELSE 0 END) as price_wars
FROM competitor_prices
");


$products = $db->fetchAll("
SELECT p.id,p.name,p.current_price,
AVG(cp.price) avg_market_price,
COUNT(cp.id) competitor_count,
MAX(cp.demand_level) demand
FROM products p
LEFT JOIN competitor_prices cp ON p.id=cp.product_id
GROUP BY p.id
ORDER BY p.total_sales DESC
LIMIT 6
");


echo json_encode([
"price_changes"=>$priceChanges,
"market_stats"=>$stats,
"products"=>$products
]);