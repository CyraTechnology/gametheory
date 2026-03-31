<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../auth/seller_login.php");
exit;
}

include "../layout/header.php";
include "../layout/seller_sidebar.php";
?>

<div class="main">

<h3 class="mb-4">
<i class="fa fa-chart-line"></i> Market Competitors
</h3>

<div class="card p-4">

<table class="table table-hover">

<thead>

<tr>
<th>Company</th>
<th>Product</th>
<th>Price</th>
<th>Demand</th>
<th>Trend</th>
</tr>

</thead>

<tbody>

<?php

$stmt=$conn->query("
SELECT competitor_name,product_id,price,demand_level,price_trend
FROM competitor_prices
ORDER BY price ASC
");

foreach($stmt as $c){

echo "<tr>

<td>{$c['competitor_name']}</td>
<td>{$c['product_id']}</td>
<td>₹{$c['price']}</td>
<td>{$c['demand_level']}</td>
<td>{$c['price_trend']}</td>

</tr>";

}

?>

</tbody>

</table>

</div>

</div>