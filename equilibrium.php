<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
header("Location: ../auth/admin_login.php");
exit;
}

$success="";


/* ======================
AUTO POLICY UPDATE
====================== */

$conn->query("
UPDATE products p
SET min_allowed_price = (
    SELECT ROUND((AVG(price)+MIN(price))/2)
    FROM competitor_prices
    WHERE product_id = p.id
)
WHERE EXISTS (
    SELECT 1 FROM competitor_prices 
    WHERE product_id = p.id
)
");



/* ======================
MARKET DATA
====================== */

$market = $conn->query("
SELECT 
p.id,
p.name,
p.category,
p.current_price,
p.min_allowed_price,

(SELECT AVG(price) 
 FROM competitor_prices 
 WHERE product_id=p.id) as market_avg,

(SELECT MIN(price) 
 FROM competitor_prices 
 WHERE product_id=p.id) as lowest_competitor

FROM products p

ORDER BY p.name ASC
");

$products = $market->fetchAll(PDO::FETCH_ASSOC);


include "../layout/header.php";
include "../layout/admin_sidebar.php";
?>

<div class="main">

<h3 class="mb-4">
<i class="fa fa-scale-balanced"></i> Nash Equilibrium Control
</h3>

<div class="alert alert-info">
Automatic price policy is enabled. Minimum price is locked based on market equilibrium.
</div>

<div class="card p-4">

<table class="table table-hover">

<tr>

<th>Product</th>
<th>Category</th>
<th>Our Price</th>
<th>Market Avg</th>
<th>Lowest Competitor</th>
<th>Equilibrium Price</th>
<th>Locked Policy Price</th>
<th>Status</th>

</tr>

<?php foreach($products as $p){

$avg = $p['market_avg'];
$lowest = $p['lowest_competitor'];
$our = $p['current_price'];

$equilibrium = round(($avg + $lowest) / 2);

$status="Compliant";
$color="success";

if($our < $equilibrium){

$status="Below equilibrium";
$color="danger";

}

?>

<tr>

<td><?= $p['name'] ?></td>

<td><?= $p['category'] ?></td>

<td>
<span class="badge bg-primary">
₹<?= number_format($our) ?>
</span>
</td>

<td>
₹<?= number_format($avg) ?>
</td>

<td>
₹<?= number_format($lowest) ?>
</td>

<td>
<span class="badge bg-info">
₹<?= number_format($equilibrium) ?>
</span>
</td>

<td>
<span class="badge bg-dark">
₹<?= number_format($p['min_allowed_price']) ?>
</span>
</td>

<td>
<span class="badge bg-<?= $color ?>">
<?= $status ?>
</span>
</td>

</tr>

<?php } ?>

</table>

</div>

</div>