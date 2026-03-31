<?php
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit;
}
