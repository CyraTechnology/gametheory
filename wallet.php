<?php
session_start();
require '../config/db.php';

$seller=$_SESSION['user_id'];

$stmt=$conn->prepare("
SELECT balance FROM wallets WHERE user_id=?
");

$stmt->execute([$seller]);

$balance=$stmt->fetchColumn();


include "../layout/header.php";
include "../layout/seller_sidebar.php";
?>

<div class="main">

<h3>
<i class="fa fa-wallet"></i> Wallet
</h3>

<div class="row">

<div class="col-md-4">

<div class="card p-4">

<h5>Wallet Balance</h5>

<h2 class="text-success">

₹<?= number_format($balance) ?>

</h2>

</div>

</div>

</div>

<br>

<div class="card p-4">

<h5>Transactions</h5>

<table class="table">

<tr>
<th>Date</th>
<th>Type</th>
<th>Amount</th>
</tr>

<?php

$tx=$conn->prepare("
SELECT * FROM wallet_transactions
WHERE user_id=?
ORDER BY created_at DESC
");

$tx->execute([$seller]);

foreach($tx as $t){

echo "<tr>

<td>{$t['created_at']}</td>
<td>{$t['type']}</td>
<td>₹{$t['amount']}</td>

</tr>";

}

?>

</table>

</div>

</div>