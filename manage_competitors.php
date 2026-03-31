<?php
include 'admin_auth.php';
include '../db.php';

if ($_POST) {
    $conn->prepare(
        "INSERT INTO competitor_prices (product_id, competitor_name, price)
         VALUES (?, ?, ?)"
    )->execute([
        $_POST['product_id'], $_POST['name'], $_POST['price']
    ]);
}

$data = $conn->query("SELECT * FROM competitor_prices")->fetchAll();
?>

<h2>Competitor Prices</h2>

<form method="post">
    Product ID <input name="product_id" value="1">
    Name <input name="name">
    Price <input name="price">
    <button>Add</button>
</form>

<table border="1">
<?php foreach ($data as $d): ?>
<tr>
<td><?= $d['competitor_name'] ?></td>
<td><?= $d['price'] ?></td>
</tr>
<?php endforeach; ?>
</table>
