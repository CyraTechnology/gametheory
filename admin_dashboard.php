<?php
require_once '../db.php';

$total_orders = $conn->query("
SELECT COUNT(*) FROM purchase_orders
")->fetchColumn();

$total_users = $conn->query("
SELECT COUNT(*) FROM users
")->fetchColumn();

$total_wallet = $conn->query("
SELECT SUM(balance) FROM wallets
")->fetchColumn();

$price_drops = $conn->query("
SELECT COUNT(*) FROM price_drop_refunds
")->fetchColumn();
?>

<h2>Admin Market Dashboard</h2>

<div class="grid">

<div class="card">
Total Orders
<h3><?= $total_orders ?></h3>
</div>

<div class="card">
Users
<h3><?= $total_users ?></h3>
</div>

<div class="card">
Wallet Holdings
<h3>₹<?= number_format($total_wallet) ?></h3>
</div>

<div class="card">
Price Drop Refunds
<h3><?= $price_drops ?></h3>
</div>

</div>