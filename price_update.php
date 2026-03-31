<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../auth/seller_login.php");
exit;
}

$seller_id = $_SESSION['user_id'];
$success = "";


/* =====================
PRICE UPDATE
===================== */

if(isset($_POST['update_price'])){

$product_id = $_POST['product_id'];
$price = $_POST['price'];

$stmt = $conn->prepare("
UPDATE products
SET current_price=?
WHERE id=? AND seller_id=?
");

$stmt->execute([$price,$product_id,$seller_id]);

$success = "Price updated successfully";


/* =====================
PRICE DROP REFUND
===================== */

$orders=$conn->prepare("
SELECT *
FROM purchase_orders
WHERE product_id=?
AND ordered_at >= NOW() - INTERVAL 24 HOUR
");

$orders->execute([$product_id]);

foreach($orders as $order){

if($order['unit_price'] > $price){

$refund = $order['unit_price'] - $price;

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
'Automatic refund due to price drop'
]);

$conn->prepare("
INSERT INTO notifications
(user_id,title,message)
VALUES(?,?,?)
")->execute([
$order['user_id'],
'Price Drop Refund',
'₹'.$refund.' credited to your wallet due to price drop'
]);

}

}

}


/* =====================
GET SELLER PRODUCTS
===================== */

$stmt = $conn->prepare("
SELECT *
FROM products
WHERE seller_id=?
ORDER BY id DESC
");

$stmt->execute([$seller_id]);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =====================
COMPETITOR MARKET DATA
===================== */

$competitors=$conn->query("
SELECT 
cp.competitor_name,
p.name as product_name,
p.category,
cp.price,
cp.demand_level,
cp.price_trend,
cp.product_id,
(SELECT AVG(price) FROM competitor_prices WHERE product_id=cp.product_id) as market_avg
FROM competitor_prices cp
LEFT JOIN products p ON p.id=cp.product_id
ORDER BY cp.price ASC
");

include "../layout/header.php";
include "../layout/seller_sidebar.php";
?>

<div class="main">

<h3 class="mb-4">
<i class="fa fa-tag"></i> Update Product Prices
</h3>

<?php if($success){ ?>
<div class="alert alert-success"><?= $success ?></div>
<?php } ?>


<!-- SEARCH + FILTER -->

<div class="row mb-3">

<div class="col-md-4">
<input type="text" id="productSearch" class="form-control" placeholder="Search product">
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


<!-- PRODUCTS TABLE -->

<div class="card p-4">

<table class="table table-hover">

<thead>

<tr>

<th>ID</th>
<th>Product</th>
<th>Category</th>
<th>Stock</th>
<th>Current Price</th>
<th>Update</th>

</tr>

</thead>

<tbody>

<?php foreach($products as $p){ ?>

<tr class="productRow" data-category="<?= $p['category'] ?>">

<td><?= $p['id'] ?></td>

<td class="productName">
<b><?= $p['name'] ?></b>
</td>

<td><?= $p['category'] ?></td>

<td><?= $p['stock'] ?></td>

<td>
<span class="badge bg-primary">
₹<?= number_format($p['current_price']) ?>
</span>
</td>

<td>

<form method="POST" class="d-flex">

<input type="hidden" name="product_id" value="<?= $p['id'] ?>">

<input type="number"
name="price"
class="form-control me-2"
placeholder="New price"
required>

<button class="btn btn-success" name="update_price">
<i class="fa fa-save"></i>
</button>

</form>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>


<br>


<!-- COMPETITOR MARKET -->

<div class="card p-4">

<h5>
<i class="fa fa-chart-line"></i> Competitor Market Prices
</h5>

<select id="competitorCategory" class="form-control mb-3">

<option value="">All Categories</option>

<?php
$cats = $conn->query("SELECT DISTINCT category FROM products");
foreach($cats as $cat){
echo "<option value='{$cat['category']}'>{$cat['category']}</option>";
}
?>

</select>


<table class="table">

<tr>

<th>Competitor</th>
<th>Product</th>
<th>Demand</th>
<th>Competitor Price</th>
<th>Market Avg</th>
<th>Recommendation</th>

</tr>


<?php

foreach($competitors as $c){

$avg = $c['market_avg'];
$price = $c['price'];

$color="green";

if($price > $avg*1.10) $color="red";
elseif($price > $avg*1.03) $color="orange";

$demandColor="secondary";

if($c['demand_level']=="high") $demandColor="success";
if($c['demand_level']=="medium") $demandColor="warning";
if($c['demand_level']=="low") $demandColor="danger";

echo "

<tr class='competitorRow' data-category='{$c['category']}'>

<td>{$c['competitor_name']}</td>

<td>{$c['product_name']}</td>

<td>
<span class='badge bg-$demandColor'>
{$c['demand_level']}
</span>
</td>

<td style='color:$color;font-weight:bold'>
₹{$c['price']}
</td>

<td>
₹".number_format($avg)."
</td>

<td style='color:$color'>

".($color=="green" ? "Good price" : ($color=="orange" ? "Watch market" : "Reduce price"))."

</td>

</tr>

";

}

?>

</table>

</div>

</div>


<script>

/* PRODUCT SEARCH */

document.getElementById("productSearch").addEventListener("keyup",function(){

let value=this.value.toLowerCase();

document.querySelectorAll(".productRow").forEach(function(row){

let name=row.querySelector(".productName").innerText.toLowerCase();

row.style.display=name.includes(value) ? "" : "none";

});

});


/* PRODUCT CATEGORY FILTER */

document.getElementById("categoryFilter").addEventListener("change",function(){

let category=this.value;

document.querySelectorAll(".productRow").forEach(function(row){

if(category=="" || row.dataset.category==category){

row.style.display="";

}else{

row.style.display="none";

}

});

});


/* COMPETITOR CATEGORY FILTER */

document.getElementById("competitorCategory").addEventListener("change",function(){

let category=this.value;

document.querySelectorAll(".competitorRow").forEach(function(row){

if(category=="" || row.dataset.category==category){

row.style.display="";

}else{

row.style.display="none";

}

});

});

</script>