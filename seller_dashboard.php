<?php

$product_id = $_GET['product'];

$stmt = $conn->prepare("
SELECT
c.name,
cp.price,
cp.demand_level,
cp.price_trend
FROM competitor_prices cp
JOIN companies c ON c.id = cp.company_id
WHERE cp.product_id = ?
");

$stmt->execute([$product_id]);
$competitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Competitor Prices</h2>

<table>

<tr>
<th>Company</th>
<th>Price</th>
<th>Demand</th>
<th>Trend</th>
</tr>

<?php foreach($competitors as $c): ?>

<tr>
<td><?= $c['name'] ?></td>
<td><?= $c['price'] ?></td>
<td><?= $c['demand_level'] ?></td>
<td><?= $c['price_trend'] ?></td>
</tr>

<?php endforeach; ?>

</table>