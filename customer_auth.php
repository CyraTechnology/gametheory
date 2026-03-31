<?php
// Start session safely (ONLY ONCE)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Validate customer session
if (
    !isset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['login_time']) ||
    $_SESSION['user_role'] !== 'customer' ||
    (time() - $_SESSION['login_time']) > 3600 // 1 hour session expiry
) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
