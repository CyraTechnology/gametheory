<?php

$orders = $conn->query("
SELECT po.id, po.user_id, po.product_id, po.unit_price
FROM purchase_orders po
WHERE ordered_at >= NOW() - INTERVAL 24 HOUR
");

foreach($orders as $order){

$product = $order['product_id'];

$new_price = $conn->query("
SELECT MIN(price)
FROM competitor_prices
WHERE product_id = $product
")->fetchColumn();

if($new_price < $order['unit_price']){

$refund = $order['unit_price'] - $new_price;

$conn->prepare("
UPDATE wallets
SET balance = balance + ?
WHERE user_id = ?
")->execute([$refund,$order['user_id']]);


$conn->prepare("
INSERT INTO wallet_transactions
(user_id,type,amount,description)
VALUES(?,?,?,?)
")->execute([
$order['user_id'],
'price_drop',
$refund,
'Automatic price drop refund'
]);

}
}