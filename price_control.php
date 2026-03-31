<?php

if(isset($_POST['update_price'])){

$product_id = $_POST['product_id'];
$new_price = $_POST['price'];
$company_id = $_SESSION['company_id'];

$stmt = $conn->prepare("
UPDATE competitor_prices
SET price = ?, last_updated = NOW()
WHERE product_id = ? AND company_id = ?
");

$stmt->execute([$new_price,$product_id,$company_id]);

echo "Price updated";

}
?>