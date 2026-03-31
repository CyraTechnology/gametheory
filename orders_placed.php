<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../auth/seller_login.php");
exit;
}

$seller = $_SESSION['user_id'];

include "../layout/header.php";
include "../layout/seller_sidebar.php";


$stmt = $conn->prepare("
SELECT 
po.order_number,
po.quantity,
po.unit_price,
po.status,
po.ordered_at,
p.name AS product_name,
u.name AS customer

FROM purchase_orders po

LEFT JOIN products p 
ON p.id = po.product_id

LEFT JOIN users u 
ON u.id = po.user_id

WHERE p.seller_id = ?

ORDER BY po.ordered_at DESC
");

$stmt->execute([$seller]);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="main">

<h3>
<i class="fa fa-box"></i> Orders Placed
</h3>

<div class="card p-4">

<table class="table">

<tr>
<th>Order</th>
<th>Customer</th>
<th>Product</th>
<th>Qty</th>
<th>Price</th>
<th>Status</th>
</tr>

<?php if($orders){ ?>

<?php foreach($orders as $o){ ?>

<tr>

<td><?= $o['order_number'] ?></td>
<td><?= $o['customer'] ?></td>
<td><?= $o['product_name'] ?></td>
<td><?= $o['quantity'] ?></td>
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

<?php } else { ?>

<tr>
<td colspan="6" class="text-center text-muted">
No orders found
</td>
</tr>

<?php } ?>

</table>

</div>

</div>