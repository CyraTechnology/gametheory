<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
    header("Location: ../auth/admin_login.php");
    exit;
}

$admin_id = $_SESSION['admin'];


/* =========================
UPDATE ORDER STATUS
========================= */

if(isset($_POST['update_status'])){

$order_id = $_POST['order_id'];
$status = $_POST['status'];

$stmt = $conn->prepare("
UPDATE purchase_orders
SET status=?
WHERE id=?
");

$stmt->execute([$status,$order_id]);

$success="Order status updated";

}


/* =========================
GET ALL ORDERS
========================= */

$stmt = $conn->query("
SELECT
po.id,
po.order_number,
po.quantity,
po.unit_price,
po.status,
po.ordered_at,

p.name as product,
p.seller_id,

u.name as customer

FROM purchase_orders po
LEFT JOIN products p ON p.id = po.product_id
LEFT JOIN users u ON u.id = po.user_id

ORDER BY po.ordered_at DESC
");

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);


include "../layout/header.php";
include "../layout/admin_sidebar.php";
?>


<div class="main">

<h3 class="mb-4">
<i class="fa fa-box"></i> Orders Dashboard
</h3>

<?php if(isset($success)){ ?>
<div class="alert alert-success"><?= $success ?></div>
<?php } ?>


<!-- SEARCH + FILTER -->

<div class="row mb-3">

<div class="col-md-4">
<input type="text" id="orderSearch" class="form-control" placeholder="Search order number">
</div>

<div class="col-md-3">
<select id="statusFilter" class="form-control">
<option value="">All Status</option>
<option value="pending">Pending</option>
<option value="processing">Processing</option>
<option value="delivered">Delivered</option>
</select>
</div>

</div>


<!-- ORDERS TABLE -->

<div class="card p-4">

<table class="table table-hover">

<thead>

<tr>
<th>Order</th>
<th>Customer</th>
<th>Product</th>
<th>Seller ID</th>
<th>Quantity</th>
<th>Price</th>
<th>Status</th>
<th>Date</th>
<th>Update</th>
</tr>

</thead>

<tbody>

<?php foreach($orders as $o){ ?>

<tr class="orderRow" data-status="<?= $o['status'] ?>">

<td class="orderNumber">
<?= $o['order_number'] ?>
</td>

<td><?= $o['customer'] ?></td>

<td><?= $o['product'] ?></td>

<td><?= $o['seller_id'] ?></td>

<td><?= $o['quantity'] ?></td>

<td>
<span class="badge bg-success">
₹<?= number_format($o['unit_price']) ?>
</span>
</td>

<td>

<span class="badge
<?= $o['status']=='pending'?'bg-warning':'' ?>
<?= $o['status']=='processing'?'bg-info':'' ?>
<?= $o['status']=='delivered'?'bg-success':'' ?>
">

<?= $o['status'] ?>

</span>

</td>

<td><?= date("d M Y",strtotime($o['ordered_at'])) ?></td>

<td>

<form method="POST" class="d-flex">

<input type="hidden" name="order_id" value="<?= $o['id'] ?>">

<select name="status" class="form-control me-2">

<option value="pending">Pending</option>
<option value="processing">Processing</option>
<option value="delivered">Delivered</option>

</select>

<button class="btn btn-primary btn-sm" name="update_status">
<i class="fa fa-save"></i>
</button>

</form>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>



<script>

/* SEARCH ORDERS */

document.getElementById("orderSearch").addEventListener("keyup",function(){

let value=this.value.toLowerCase();

document.querySelectorAll(".orderRow").forEach(function(row){

let order=row.querySelector(".orderNumber").innerText.toLowerCase();

row.style.display = order.includes(value) ? "" : "none";

});

});


/* STATUS FILTER */

document.getElementById("statusFilter").addEventListener("change",function(){

let status=this.value;

document.querySelectorAll(".orderRow").forEach(function(row){

if(status=="" || row.dataset.status==status){

row.style.display="";

}else{

row.style.display="none";

}

});

});

</script>