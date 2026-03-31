<?php
$wallets = $conn->query("
SELECT u.name, w.balance
FROM wallets w
JOIN users u ON u.id = w.user_id
");
?>

<h2>User Wallets</h2>

<table>
<tr>
<th>User</th>
<th>Balance</th>
</tr>

<?php foreach($wallets as $w): ?>

<tr>
<td><?= $w['name'] ?></td>
<td>₹<?= $w['balance'] ?></td>
</tr>

<?php endforeach; ?>

</table>