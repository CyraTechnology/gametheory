<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../auth/seller_login.php");
exit;
}

$seller=$_SESSION['user_id'];


/* ======================
SELLER ANALYTICS
====================== */

$stmt=$conn->prepare("
SELECT 
p.id,
p.name,

(SELECT COUNT(*) 
 FROM product_views pv 
 WHERE pv.product_id=p.id) as views,

(SELECT COUNT(*) 
 FROM product_interactions pi 
 WHERE pi.product_id=p.id 
 AND pi.interaction_type='add_to_cart') as cart_clicks,

(SELECT COUNT(*) 
 FROM product_interactions pi 
 WHERE pi.product_id=p.id 
 AND pi.interaction_type='purchase') as purchases

FROM products p

WHERE p.seller_id=?

ORDER BY views DESC
");

$stmt->execute([$seller]);

$products=$stmt->fetchAll(PDO::FETCH_ASSOC);

include "../layout/header.php";
include "../layout/seller_sidebar.php";
?>

<div class="main">

<h3>

<i class="fa fa-chart-line"></i> Product Insights

</h3>


<div class="card p-4 mb-4">

<canvas id="sellerChart"></canvas>

</div>


<div class="card p-4">

<h5>Your Product Performance</h5>

<table class="table table-hover">

<tr>

<th>Product</th>
<th>Views</th>
<th>Cart Clicks</th>
<th>Purchases</th>
<th>Demand</th>

</tr>

<?php foreach($products as $p){

$demand="Low";
$color="danger";

if($p['views']>300){
$demand="High";
$color="success";
}
elseif($p['views']>100){
$demand="Medium";
$color="warning";
}

?>

<tr>

<td><?= $p['name'] ?></td>

<td><?= $p['views'] ?></td>

<td><?= $p['cart_clicks'] ?></td>

<td><?= $p['purchases'] ?></td>

<td>

<span class="badge bg-<?= $color ?>">

<?= $demand ?>

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

const cart = [
<?php foreach($products as $p){
echo $p['cart_clicks'].",";
} ?>
];


new Chart(document.getElementById('sellerChart'),{

type:'line',

data:{
labels:labels,

datasets:[
{
label:'Views',
data:views
},
{
label:'Cart Clicks',
data:cart
}
]

}

});

</script>