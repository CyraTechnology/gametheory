<?php
session_start();

/* ======================
UNSET SESSION VARIABLES
====================== */

$_SESSION = [];

/* ======================
DESTROY SESSION
====================== */

session_destroy();

/* ======================
DELETE SESSION COOKIE
====================== */

if (ini_get("session.use_cookies")) {

$params = session_get_cookie_params();

setcookie(
session_name(),
'',
time() - 42000,
$params["path"],
$params["domain"],
$params["secure"],
$params["httponly"]
);

}

/* ======================
REDIRECT TO HOMEPAGE
====================== */

header("Location: ../index.php");
exit;
?>