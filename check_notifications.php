<?php

session_start();
require '../config/db.php';

$user=$_SESSION['user_id'];

$stmt=$conn->prepare("
SELECT COUNT(*) FROM notifications
WHERE user_id=? AND status='unread'
");

$stmt->execute([$user]);

$count=$stmt->fetchColumn();

echo json_encode(['count'=>$count]);