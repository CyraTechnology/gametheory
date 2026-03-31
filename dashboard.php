<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
header("Location: ../auth/admin_login.php");
exit;
}


/* ======================
SYSTEM STATS
====================== */

$total_users=$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

$total_sellers=$conn->query("
SELECT COUNT(*) FROM users WHERE role='seller'
")->fetchColumn();

$total_wallet=$conn->query("
SELECT SUM(balance) FROM wallets
")->fetchColumn();

$total_orders=$conn->query("
SELECT COUNT(*) FROM purchase_orders
")->fetchColumn();

$total_products=$conn->query("
SELECT COUNT(*) FROM products
")->fetchColumn();

$total_competitors=$conn->query("
SELECT COUNT(DISTINCT competitor_name) FROM competitor_prices
")->fetchColumn();


/* ======================
PRICE POLICY VIOLATIONS
====================== */

$violations=$conn->query("
SELECT COUNT(*) 
FROM products
WHERE current_price < min_allowed_price
")->fetchColumn();


/* ======================
RECENT ORDERS
====================== */

$recent_orders=$conn->query("
SELECT po.order_number,
u.name as customer,
p.name as product,
po.unit_price,
po.status
FROM purchase_orders po
LEFT JOIN users u ON u.id = po.user_id
LEFT JOIN products p ON p.id = po.product_id
ORDER BY po.ordered_at DESC
LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);



include "../layout/header.php";
include "../layout/admin_sidebar.php";
?>

<div class="main">

<h2>Admin Dashboard</h2>

<div class="row">

<div class="col-md-3">
<div class="card p-3">
Total Users
<div class="stat"><?= $total_users ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Total Sellers
<div class="stat"><?= $total_sellers ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Orders
<div class="stat"><?= $total_orders ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Wallet Holdings
<div class="stat">₹<?= number_format($total_wallet) ?></div>
</div>
</div>

</div>


<br>


<div class="row">

<div class="col-md-3">
<div class="card p-3">
Products
<div class="stat"><?= $total_products ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Competitors
<div class="stat"><?= $total_competitors ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Policy Violations
<div class="stat text-danger"><?= $violations ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Price Policy
<div class="stat text-success">AUTO LOCK</div>
</div>
</div>

</div>


<br>


<div class="row">

<div class="col-md-6">
<div class="card p-4">

<h5>Market Demand</h5>

<canvas id="demandChart"></canvas>

</div>
</div>

<div class="col-md-6">
<div class="card p-4">

<h5>Price Competition</h5>

<canvas id="priceChart"></canvas>

</div>
</div>

</div>


<br>


<div class="card p-4">

<h5>Recent Orders</h5>

<table class="table">

<tr>
<th>Order</th>
<th>Customer</th>
<th>Product</th>
<th>Price</th>
<th>Status</th>
</tr>

<?php foreach($recent_orders as $o){ ?>

<tr>

<td><?= $o['order_number'] ?></td>

<td><?= $o['customer'] ?></td>

<td><?= $o['product'] ?></td>

<td>₹<?= number_format($o['unit_price']) ?></td>

<td>

<span class="badge
<?= $o['status']=='pending'?'bg-warning':'' ?>
<?= $o['status']=='processing'?'bg-info':'' ?>
<?= $o['status']=='delivered'?'bg-success':'' ?>
">

<?= $o['status'] ?>

</span>

</td>

</tr>

<?php } ?>

</table>

</div>


<br>


<div class="card p-4">

<h5>Admin Quick Controls</h5>

<div class="row">

<div class="col-md-3">
<a href="orders.php" class="btn btn-dark w-100">Manage Orders</a>
</div>

<div class="col-md-3">
<a href="wallet.php" class="btn btn-primary w-100">Wallet Control</a>
</div>

<div class="col-md-3">
<a href="market_monitor.php" class="btn btn-success w-100">Market Monitor</a>
</div>

<div class="col-md-3">
<a href="equilibrium.php" class="btn btn-warning w-100">Equilibrium Policy</a>
</div>

</div>

</div>

</div>



<script>

new Chart(document.getElementById('demandChart'),{

type:'line',

data:{
labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
datasets:[{
label:'Demand',
data:[120,190,220,260,300,280,340],
borderWidth:2
}]
}

});


new Chart(document.getElementById('priceChart'),{

type:'bar',

data:{
labels:['Competitor A','Competitor B','Competitor C'],
datasets:[{
label:'Avg Price',
data:[1100,1080,1050]
}]
}

});

</script>

</body>
</html>