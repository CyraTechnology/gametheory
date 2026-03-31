<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
    header("Location: ../auth/seller_login.php");
    exit;
}

$seller_id = $_SESSION['user_id'];
$error = "";
$success = "";

/* ========================
ADD PRODUCT
======================== */
if(isset($_POST['add_product'])){

    $name = trim($_POST['name']);
    $category = trim($_POST['category'] ?? '');
    $price = trim($_POST['price']);
    $stock = trim($_POST['stock']);

    // FORMAT CATEGORY
    if($category !== ''){
        $category = ucwords(strtolower($category));
    }

    /* VALIDATION */
    if($name === '' || $category === '' || $price === '' || $stock === ''){
        $error = "All fields are required!";
    }

    /* PREVENT DUPLICATE */
    if(empty($error)){
        $check = $conn->prepare("
            SELECT id FROM products 
            WHERE name=? AND category=? AND seller_id=? 
            LIMIT 1
        ");
        $check->execute([$name, $category, $seller_id]);

        if($check->fetch()){
            $error = "Product already exists!";
        }
    }

    /* INSERT */
    if(empty($error)){

        $image_path = NULL;

        // IMAGE UPLOAD
        if(!empty($_FILES['image']['name'])){
            $target_dir = "../uploads/products/";

            if(!is_dir($target_dir)){
                mkdir($target_dir, 0777, true);
            }

            $filename = time()."_".basename($_FILES["image"]["name"]);
            $target_file = $target_dir.$filename;

            if(move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)){
                $image_path = "uploads/products/".$filename;
            }
        }

        // ✅ SAFE MINIMAL INSERT (NO COLUMN MISMATCH)
        $stmt = $conn->prepare("
            INSERT INTO products(
                name,
                category,
                base_price,
                current_price,
                price,
                stock,
                seller_id,
                image_url,
                created_at,
                is_active
            )
            VALUES(?,?,?,?,?,?,?,?,NOW(),1)
        ");

        $result = $stmt->execute([
            $name,
            $category,
            $price,
            $price,
            $price,
            $stock,
            $seller_id,
            $image_path
        ]);

        if($result){
            $success = "Product added successfully!";
        } else {
            $error = "Insert failed!";
        }
    }
}

/* ========================
EDIT PRODUCT
======================== */
if(isset($_POST['edit_product'])){

    $id = $_POST['product_id'];
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $price = trim($_POST['price']);
    $stock = trim($_POST['stock']);

    $category = ucwords(strtolower($category));

    $stmt = $conn->prepare("
        UPDATE products
        SET name=?, category=?, current_price=?, price=?, base_price=?, stock=?
        WHERE id=? AND seller_id=?
    ");

    $stmt->execute([
        $name,
        $category,
        $price,
        $price,
        $price,
        $stock,
        $id,
        $seller_id
    ]);

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

/* ========================
DELETE PRODUCT
======================== */
if(isset($_POST['delete_product'])){

    $id = $_POST['product_id'];

    $get = $conn->prepare("SELECT image_url FROM products WHERE id=? AND seller_id=?");
    $get->execute([$id,$seller_id]);
    $img = $get->fetchColumn();

    $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND seller_id=?");
    $stmt->execute([$id,$seller_id]);

    if($img && file_exists("../".$img)){
        unlink("../".$img);
    }

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

/* ========================
GET PRODUCTS
======================== */
$stmt = $conn->prepare("
    SELECT *
    FROM products
    WHERE seller_id=?
    ORDER BY id DESC
");
$stmt->execute([$seller_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========================
GET UNIQUE CATEGORIES
======================== */
$cat_stmt = $conn->prepare("
    SELECT DISTINCT category 
    FROM products 
    WHERE seller_id=? AND category IS NOT NULL AND category != ''
    ORDER BY category ASC
");
$cat_stmt->execute([$seller_id]);
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

include "../layout/header.php";
include "../layout/seller_sidebar.php";
?>
<div class="main">

<h3 class="mb-4">
<i class="fa fa-box"></i> My Products
</h3>

<?php if($error){ ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php } ?>

<?php if($success){ ?>
<div class="alert alert-success"><?= $success ?></div>
<?php } ?>

<!-- ADD PRODUCT -->
<div class="card p-4 mb-4">

<h5 class="mb-3">Add New Product</h5>

<form method="POST" enctype="multipart/form-data">

<div class="row g-2 align-items-end">

<div class="col-md-3">
<label>Name</label>
<input class="form-control" name="name" required>
</div>

<div class="col-md-3">
<label>Category</label>
<input class="form-control" name="category" list="categoryList" required>
<datalist id="categoryList">
<?php foreach($categories as $cat){ ?>
<option value="<?= htmlspecialchars($cat['category']) ?>">
<?php } ?>
</datalist>
</div>

<div class="col-md-2">
<label>Price</label>
<input type="number" class="form-control" name="price" required>
</div>

<div class="col-md-2">
<label>Stock</label>
<input type="number" class="form-control" name="stock" required>
</div>

<div class="col-md-2">
<label>Image</label>
<input type="file" class="form-control" name="image">
</div>

<div class="col-md-12 mt-2">
<button type="submit" class="btn btn-primary" name="add_product">
Add Product
</button>
</div>

</div>
</form>
</div>

<!-- PRODUCTS TABLE -->
<div class="card p-4">

<h5>Your Product List</h5>

<table class="table table-hover">

<thead>
<tr>
<th>ID</th>
<th>Name</th>
<th>Category</th>
<th>Price</th>
<th>Stock</th>
<th>Image</th>
<th>Edit</th>
<th>Delete</th>
</tr>
</thead>

<tbody>

<?php foreach($products as $p){ ?>

<tr>
<td><?= $p['id'] ?></td>
<td><?= $p['name'] ?></td>
<td><?= $p['category'] ?></td>
<td>₹<?= number_format($p['current_price']) ?></td>
<td><?= $p['stock'] ?></td>

<td>
<?php if($p['image_url']){ ?>
<img src="../<?= $p['image_url'] ?>" width="50">
<?php } ?>
</td>

<td>
<button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?= $p['id'] ?>">
Edit
</button>
</td>

<td>
<form method="POST">
<input type="hidden" name="product_id" value="<?= $p['id'] ?>">
<button class="btn btn-danger btn-sm" name="delete_product">Delete</button>
</form>
</td>
</tr>

<!-- EDIT MODAL -->
<div class="modal fade" id="edit<?= $p['id'] ?>">
<div class="modal-dialog">
<div class="modal-content">

<div class="modal-header">
<h5>Edit Product</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<form method="POST">

<input type="hidden" name="product_id" value="<?= $p['id'] ?>">

<input class="form-control mb-2" name="name" value="<?= $p['name'] ?>">

<input class="form-control mb-2" name="category" value="<?= $p['category'] ?>">

<input class="form-control mb-2" name="price" value="<?= $p['current_price'] ?>">

<input class="form-control mb-2" name="stock" value="<?= $p['stock'] ?>">

<button class="btn btn-success w-100" name="edit_product">Update</button>

</form>
</div>

</div>
</div>
</div>

<?php } ?>

</tbody>
</table>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>