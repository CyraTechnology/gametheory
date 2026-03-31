<?php

session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../auth/seller_login.php");
exit;
}

$seller=$_SESSION['user_id'];


/* ======================
SELLER WALLET
====================== */

$wallet=$conn->prepare("
SELECT balance 
FROM wallets 
WHERE user_id=?
");

$wallet->execute([$seller]);
$balance=$wallet->fetchColumn();


/* ======================
SELLER PRODUCTS COUNT
====================== */

$product_count=$conn->prepare("
SELECT COUNT(*) 
FROM products 
WHERE seller_id=?
");

$product_count->execute([$seller]);
$total_products=$product_count->fetchColumn();


/* ======================
TODAY SALES
====================== */

$today_sales=$conn->prepare("
SELECT SUM(unit_price*quantity) 
FROM purchase_orders po
JOIN products p ON p.id=po.product_id
WHERE p.seller_id=?
AND DATE(po.ordered_at)=CURDATE()
");

$today_sales->execute([$seller]);
$sales=$today_sales->fetchColumn();


/* ======================
MARKET RANK (BASED ON SALES)
====================== */

$rank=$conn->query("
SELECT seller_id,
SUM(po.unit_price*po.quantity) as revenue
FROM purchase_orders po
JOIN products p ON p.id=po.product_id
GROUP BY seller_id
ORDER BY revenue DESC
")->fetchAll(PDO::FETCH_ASSOC);

$market_rank="-";

$i=1;
foreach($rank as $r){

if($r['seller_id']==$seller){
$market_rank="#".$i;
break;
}

$i++;
}


include "../layout/header.php";
include "../layout/seller_sidebar.php";

?>

<div class="main">

<h2>Seller Dashboard</h2>

<div class="row">

<div class="col-md-3">
<div class="card p-3">
Wallet
<div class="stat">₹<?= number_format($balance) ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Products
<div class="stat"><?= $total_products ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Today's Sales
<div class="stat">₹<?= number_format($sales) ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
Market Rank
<div class="stat"><?= $market_rank ?></div>
</div>
</div>

</div>

<br>

<div class="card p-4">

<h5>Competitor Price Monitor</h5>

<table class="table">

<tr>
<th>Company</th>
<th>Price</th>
<th>Demand</th>
<th>Trend</th>
</tr>

<?php

$prices=$conn->query("
SELECT competitor_name,price,demand_level,price_trend 
FROM competitor_prices
ORDER BY price ASC
");

foreach($prices as $p){

echo "<tr>

<td>".$p['competitor_name']."</td>
<td>₹".number_format($p['price'])."</td>
<td>".$p['demand_level']."</td>
<td>".$p['price_trend']."</td>

</tr>";

}

?>

</table>

</div>

</div>

</body>
</html>