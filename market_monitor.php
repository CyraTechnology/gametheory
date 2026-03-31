<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
header("Location: ../auth/admin_login.php");
exit;
}

include "../layout/header.php";
include "../layout/admin_sidebar.php";


/* ======================
MARKET DATA
====================== */

$market = $conn->query("
SELECT 
cp.competitor_name,
cp.product_id,
cp.price,
cp.demand_level,
cp.price_trend,

p.name AS product_name,
p.category,
p.current_price,

(SELECT AVG(price) 
 FROM competitor_prices 
 WHERE product_id = cp.product_id) AS market_avg

FROM competitor_prices cp

LEFT JOIN products p 
ON p.id = cp.product_id

ORDER BY cp.price ASC
");

$market_data = $market->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="main">

<h3 class="mb-4">
<i class="fa fa-chart-line"></i> Market Monitor
</h3>


<!-- SEARCH + FILTER -->

<div class="row mb-3">

<div class="col-md-4">
<input type="text" id="marketSearch" class="form-control" placeholder="Search product">
</div>

<div class="col-md-3">

<select id="categoryFilter" class="form-control">

<option value="">All Categories</option>

<?php
$cats = $conn->query("SELECT DISTINCT category FROM products");
foreach($cats as $cat){
echo "<option value='{$cat['category']}'>{$cat['category']}</option>";
}
?>

</select>

</div>

</div>


<div class="card p-4">

<table class="table table-hover">

<thead>

<tr>

<th>Competitor</th>
<th>Product</th>
<th>Category</th>
<th>Demand</th>
<th>Competitor Price</th>
<th>Our Price</th>
<th>Market Avg</th>
<th>Recommendation</th>

</tr>

</thead>

<tbody>

<?php foreach($market_data as $m){ 

$avg = $m['market_avg'];
$competitor_price = $m['price'];
$our_price = $m['current_price'];

/* price recommendation */

$color="green";

if($our_price > $avg*1.10) $color="red";
elseif($our_price > $avg*1.03) $color="orange";

/* demand color */

$demandColor="secondary";

if($m['demand_level']=="high") $demandColor="success";
if($m['demand_level']=="medium") $demandColor="warning";
if($m['demand_level']=="low") $demandColor="danger";

?>

<tr class="marketRow" data-category="<?= $m['category'] ?>">

<td><?= $m['competitor_name'] ?></td>

<td class="productName"><?= $m['product_name'] ?></td>

<td><?= $m['category'] ?></td>

<td>

<span class="badge bg-<?= $demandColor ?>">

<?= $m['demand_level'] ?>

</span>

</td>

<td>

<span class="text-danger">

₹<?= number_format($competitor_price) ?>

</span>

</td>

<td>

<span class="text-primary">

₹<?= number_format($our_price) ?>

</span>

</td>

<td>

₹<?= number_format($avg) ?>

</td>

<td style="color:<?= $color ?>">

<?php

if($color=="green") echo "Competitive price";
elseif($color=="orange") echo "Monitor market";
else echo "Reduce price";

?>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>



<script>

/* SEARCH */

document.getElementById("marketSearch").addEventListener("keyup",function(){

let value=this.value.toLowerCase();

document.querySelectorAll(".marketRow").forEach(function(row){

let name=row.querySelector(".productName").innerText.toLowerCase();

row.style.display=name.includes(value) ? "" : "none";

});

});


/* CATEGORY FILTER */

document.getElementById("categoryFilter").addEventListener("change",function(){

let category=this.value;

document.querySelectorAll(".marketRow").forEach(function(row){

if(category=="" || row.dataset.category==category){

row.style.display="";

}else{

row.style.display="none";

}

});

});

</script>