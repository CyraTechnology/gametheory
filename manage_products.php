<?php
include 'admin_auth.php';
include '../db.php';

if ($_POST) {
    $conn->prepare(
        "INSERT INTO products (name, base_price, current_price, stock)
         VALUES (?, ?, ?, ?)"
    )->execute([
        $_POST['name'], $_POST['price'], $_POST['price'], $_POST['stock']
    ]);
}

$products = $conn->query("SELECT * FROM products")->fetchAll();
?>

<h2>Products</h2>

<form method="post">
    Name <input name="name">
    Price <input name="price">
    Stock <input name="stock">
    <button>Add</button>
</form>

<table border="1">
<?php foreach ($products as $p): ?>
<tr>
<td><?= $p['name'] ?></td>
<td><?= $p['current_price'] ?></td>
<td><?= $p['stock'] ?></td>
</tr>
<?php endforeach; ?>
</table>
