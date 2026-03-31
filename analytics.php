<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
header("Location: ../auth/admin_login.php");
exit;
}

/* ======================
PRODUCT ANALYTICS
====================== */

$analytics = $conn->query("
SELECT 
p.id,
p.name,
p.seller_id,

COUNT(DISTINCT pv.id) as views,

SUM(pi.interaction_type='add_to_cart') as cart_clicks,

SUM(pi.interaction_type='purchase') as purchases,

ROUND(
(COUNT(DISTINCT pv.id)*0.4) +
(SUM(pi.interaction_type='add_to_cart')*0.3) +
(SUM(pi.interaction_type='purchase')*0.3)
,2) as demand_score

FROM products p

LEFT JOIN product_views pv 
ON pv.product_id=p.id

LEFT JOIN product_interactions pi 
ON pi.product_id=p.id

GROUP BY p.id
ORDER BY demand_score DESC
");

$products=$analytics->fetchAll(PDO::FETCH_ASSOC);

include "../layout/header.php";
include "../layout/admin_sidebar.php";
?>

<div class="main">

<h3>
<i class="fa fa-chart-line"></i> Market Analytics
</h3>

<div class="card p-4 mb-4">

<canvas id="demandChart"></canvas>

</div>


<div class="card p-4">

<h5>Product Demand Ranking</h5>

<table class="table table-hover">

<tr>

<th>Product</th>
<th>Seller</th>
<th>Views</th>
<th>Cart</th>
<th>Purchases</th>
<th>Demand Score</th>

</tr>

<?php foreach($products as $p){

$color="secondary";

if($p['demand_score'] > 200) $color="success";
elseif($p['demand_score'] > 100) $color="warning";
else $color="danger";

?>

<tr>

<td><?= $p['name'] ?></td>

<td><?= $p['seller_id'] ?></td>

<td><?= $p['views'] ?></td>

<td><?= $p['cart_clicks'] ?></td>

<td><?= $p['purchases'] ?></td>

<td>

<span class="badge bg-<?= $color ?>">

<?= $p['demand_score'] ?>

</span>

</td>

</tr>

<?php } ?>

</table>

</div>

</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

const labels = [
<?php foreach($products as $p){
echo "'".$p['name']."',";
} ?>
];

const views = [
<?php foreach($products as $p){
echo $p['views'].",";
} ?>
];

const purchases = [
<?php foreach($products as $p){
echo $p['purchases'].",";
} ?>
];


new Chart(document.getElementById('demandChart'),{

type:'bar',

data:{
labels:labels,

datasets:[
{
label:'Views',
data:views
},

{
label:'Purchases',
data:purchases
}
]

}

});

</script>