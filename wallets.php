<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['admin'])){
header("Location: ../auth/admin_login.php");
exit;
}

$success="";


/* ======================
MANUAL WALLET UPDATE
====================== */

if(isset($_POST['credit'])){

$user_id=$_POST['user_id'];
$amount=$_POST['amount'];

$conn->prepare("
UPDATE wallets
SET balance = balance + ?
WHERE user_id=?
")->execute([$amount,$user_id]);


$conn->prepare("
INSERT INTO wallet_transactions
(user_id,type,amount,description)
VALUES(?,?,?,?)
")->execute([
$user_id,
'admin_credit',
$amount,
'Admin credited wallet'
]);

$success="Wallet credited";

}


/* ======================
CUSTOMER LIST
====================== */

$users=$conn->query("
SELECT u.id,u.name,w.balance
FROM users u
LEFT JOIN wallets w ON w.user_id=u.id
WHERE u.role='customer'
");

include "../layout/header.php";
include "../layout/admin_sidebar.php";
?>

<div class="main">

<h3>Customer Wallet Control</h3>

<?php if($success){ ?>

<div class="alert alert-success"><?= $success ?></div>

<?php } ?>


<table class="table">

<tr>
<th>Customer</th>
<th>Balance</th>
<th>Credit Wallet</th>
</tr>

<?php foreach($users as $u){ ?>

<tr>

<td><?= $u['name'] ?></td>

<td>₹<?= number_format($u['balance']) ?></td>

<td>

<form method="POST">

<input type="hidden" name="user_id" value="<?= $u['id'] ?>">

<input type="number" name="amount" class="form-control mb-2" required>

<button class="btn btn-success btn-sm" name="credit">

Credit

</button>

</form>

</td>

</tr>

<?php } ?>

</table>

</div>