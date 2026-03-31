<?php

$user = $_SESSION['user_id'];

$total_spent = $conn->query("
SELECT SUM(total_price)
FROM purchase_orders
WHERE user_id = $user
")->fetchColumn();

$refund_income = $conn->query("
SELECT SUM(amount)
FROM wallet_transactions
WHERE user_id = $user
AND type='price_drop'
")->fetchColumn();

?>

<h2>User Market Stats</h2>

<div class="card">
Total Spent
<h3>₹<?= $total_spent ?></h3>
</div>

<div class="card">
Price Drop Income
<h3>₹<?= $refund_income ?></h3>
</div>