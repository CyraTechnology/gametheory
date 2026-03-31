<?php
session_start();
require_once '../db.php';

// Check if user came from registration
if (!isset($_SESSION['registration_success'])) {
    header("Location: register.php");
    exit();
}

$email = $_SESSION['registered_email'] ?? '';
$verification_link = $_SESSION['verification_link'] ?? '#';

// Clear session data
unset($_SESSION['registration_success']);
unset($_SESSION['registered_email']);
unset($_SESSION['verification_link']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card text-center">
                    <div class="card-header bg-success text-white">
                        <h4><i class="fas fa-check-circle"></i> Registration Successful!</h4>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <i class="fas fa-envelope-open-text fa-4x text-success"></i>
                        </div>
                        
                        <h5>Welcome to GameTheory Pricing Platform!</h5>
                        <p class="text-muted">
                            A verification email has been sent to:<br>
                            <strong><?= htmlspecialchars($email) ?></strong>
                        </p>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Demo Version Notice</h6>
                            <p>In production, you would receive an email. For demo purposes:</p>
                            <p><strong>Verification Link:</strong><br>
                            <a href="<?= $verification_link ?>" target="_blank"><?= $verification_link ?></a></p>
                        </div>
                        
                        <div class="mt-4">
                            <a href="login.php" class="btn btn-success">
                                <i class="fas fa-sign-in-alt"></i> Proceed to Login
                            </a>
                            <a href="../" class="btn btn-outline-secondary">
                                <i class="fas fa-home"></i> Return to Home
                            </a>
                        </div>
                        
                        <div class="mt-4">
                            <small class="text-muted">
                                <i class="fas fa-clock"></i> Account activated immediately for demo
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>