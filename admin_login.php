<?php
session_start();
include '../db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $password = hash('sha256', $_POST['password']);

    $stmt = $conn->prepare(
        "SELECT * FROM users WHERE email = ? AND password = ? AND role = 'admin'"
    );
    $stmt->execute([$email, $password]);
    $admin = $stmt->fetch();

    if ($admin) {
        $_SESSION['admin'] = $admin;
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $error = "Invalid admin credentials";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
</head>
<body>

<h2>Admin Login</h2>

<?php if ($error): ?>
<p style="color:red"><?= $error ?></p>
<?php endif; ?>

<form method="post">
    Email<br>
    <input type="email" name="email" required><br><br>

    Password<br>
    <input type="password" name="password" required><br><br>

    <button type="submit">Login</button>
</form>

<a href="../customer/login.php">Back to User Login</a>

</body>
</html>
