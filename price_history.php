<?php
include 'admin_auth.php';
include '../db.php';

$data = $conn->query("SELECT * FROM price_history")->fetchAll();
?>

<h2>Price History</h2>
<table border="1">
<?php foreach ($data as $h): ?>
<tr>
<td><?= $h['product_id'] ?></td>
<td><?= $h['old_price'] ?></td>
<td><?= $h['new_price'] ?></td>
<td><?= $h['changed_at'] ?></td>
</tr>
<?php endforeach; ?>
</table>
