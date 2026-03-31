<?php
include 'admin_auth.php';
include '../db.php';

$data = $conn->query(
    "SELECT product_id, SUM(views) v, SUM(purchases) p
     FROM demand_logs GROUP BY product_id"
)->fetchAll();
?>

<h2>Demand Analytics</h2>
<table border="1">
<tr><th>Product</th><th>Views</th><th>Purchases</th><th>Score</th></tr>
<?php foreach ($data as $d): ?>
<tr>
<td><?= $d['product_id'] ?></td>
<td><?= $d['v'] ?></td>
<td><?= $d['p'] ?></td>
<td><?= round($d['p']/max(1,$d['v']),2) ?></td>
</tr>
<?php endforeach; ?>
</table>
