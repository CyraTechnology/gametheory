<?php
include 'admin_auth.php';
include '../db.php';

$data = $conn->query(
    "SELECT SUM(purchases * current_price) revenue
     FROM demand_logs d
     JOIN products p ON d.product_id = p.id"
)->fetch();
?>

<h2>Revenue Analysis</h2>
<h3>Total Revenue: ₹<?= round($data['revenue'],2) ?></h3>
