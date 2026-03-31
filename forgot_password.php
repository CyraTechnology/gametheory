<?php
session_start();
require_once '../db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    if (empty($email)) {
        $error = "Please enter your email address";
    } else {
        // Check if user exists
        $sql = "SELECT id, name, email FROM users WHERE email = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate reset token (valid for 1 hour)
            $token = bin2hex(random_bytes(32));
            $expires = time() + 3600;
            
            // Store token in database
            $token_sql = "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)";
            $conn->prepare($token_sql)->execute([$email, hash('sha256', $token), date('Y-m-d H:i:s', $expires)]);
            
            // Send reset email (in production, use PHPMailer or similar)
            $reset_link = "https://yourdomain.com/customer/reset_password.php?token=$token&email=" . urlencode($email);
            
            // For demo purposes, show the link
            $success = "Password reset link generated: <a href='$reset_link'>Reset Password</a><br><br>";
            $success .= "<small>In production, this would be sent to your email.</small>";
            
            // Log this activity
            $log_sql = "INSERT INTO user_logs (user_id, activity_type, ip_address, details) 
                       VALUES (?, 'password_reset', ?, ?)";
            $conn->prepare($log_sql)->execute([
                $user['id'],
                $_SERVER['REMOTE_ADDR'],
                json_encode(['reset_requested' => true])
            ]);
        } else {
            // Don't reveal if user exists or not (security best practice)
            $success = "If an account exists with that email, a reset link has been sent.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Game Theory Pricing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-key"></i> Reset Password</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Enter your email address:</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Send Reset Link</button>
                            <a href="login.php" class="btn btn-secondary">Back to Login</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>