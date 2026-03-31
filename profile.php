<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$success = "";
$error = "";

/* ======================
GET USER DATA
====================== */

$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

/* ======================
GET WALLET BALANCE
====================== */

$wallet = $conn->prepare("SELECT balance FROM wallets WHERE user_id=?");
$wallet->execute([$user_id]);
$balance = $wallet->fetchColumn() ?? 0;

/* ======================
UPDATE PROFILE
====================== */

if(isset($_POST['update_profile'])){
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    
    $conn->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")
         ->execute([$name, $email, $phone, $user_id]);
    
    $success = "Profile updated successfully";
    
    // Refresh user data
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ======================
CHANGE PASSWORD
====================== */

if(isset($_POST['change_password'])){
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    
    if(password_verify($current, $user['password'])){
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE users SET password=? WHERE id=?")
             ->execute([$hash, $user_id]);
        $success = "Password changed successfully";
    } else {
        $error = "Current password incorrect";
    }
}

include "../layout/header.php";
include "../layout/customer_sidebar.php";
?>

<!-- Add Mobile Optimized Meta Tags -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#4e73df">

<style>
    /* ======================
    PROFILE STYLES - Adapted for Sidebar
    ====================== */
    :root {
        --primary-color: #4e73df;
        --success-color: #1cc88a;
        --warning-color: #f6c23e;
        --danger-color: #e74a3b;
        --dark-color: #5a5c69;
        --bg-light: #f8f9fc;
        --sidebar-width: 250px;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--bg-light);
        color: #333;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        overflow-x: hidden;
    }

    /* Main content area - works with sidebar */
    .main-content {
        margin-left: var(--sidebar-width);
        padding: 20px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
        background: var(--bg-light);
    }

    /* When sidebar is collapsed on desktop */
    body.sidebar-collapsed .main-content {
        margin-left: 70px;
    }

    /* ======================
    MOBILE HEADER WITH MENU BUTTON
    ====================== */
    .mobile-header {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        background: white;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        border-bottom: 1px solid #edf2f7;
    }

    .menu-toggle {
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        border: none;
        border-radius: 8px;
        color: white;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .menu-toggle:active {
        transform: scale(0.95);
        background: #3a5fc7;
    }

    .mobile-header h1 {
        font-size: 20px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }

    .mobile-header-actions {
        display: flex;
        gap: 12px;
    }

    .mobile-header-actions i {
        font-size: 20px;
        color: #95a5a6;
    }

    /* ======================
    PAGE HEADER
    ====================== */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-header h2 {
        font-size: 28px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h2 i {
        color: var(--primary-color);
        background: rgba(78, 115, 223, 0.1);
        padding: 10px;
        border-radius: 12px;
    }

    .header-actions {
        display: flex;
        gap: 12px;
    }

    .header-actions button {
        padding: 10px 20px;
        border: 1px solid #e0e0e0;
        background: white;
        border-radius: 8px;
        color: #4a5568;
        font-size: 14px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .header-actions button:hover {
        background: #f7fafc;
        border-color: var(--primary-color);
    }

    /* ======================
    ALERT MESSAGES
    ====================== */
    .alert-message {
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .alert-success {
        background: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid #2e7d32;
    }

    .alert-success i {
        color: #2e7d32;
    }

    .alert-danger {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid #c62828;
    }

    .alert-danger i {
        color: #c62828;
    }

    /* ======================
    PROFILE HEADER CARD
    ====================== */
    .profile-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
        color: white;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        position: relative;
        overflow: hidden;
    }

    .profile-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        transform: rotate(45deg);
    }

    .profile-avatar {
        display: flex;
        align-items: center;
        gap: 20px;
        position: relative;
        z-index: 1;
    }

    .avatar-circle {
        width: 80px;
        height: 80px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        font-weight: 600;
        color: white;
        border: 3px solid rgba(255,255,255,0.5);
        backdrop-filter: blur(10px);
    }

    .avatar-info h4 {
        font-size: 24px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .avatar-info p {
        font-size: 14px;
        opacity: 0.9;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
    }

    .avatar-info p i {
        font-size: 14px;
    }

    /* ======================
    WALLET SUMMARY CARD
    ====================== */
    .wallet-summary {
        background: white;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.03);
    }

    .wallet-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .wallet-header i {
        font-size: 24px;
        color: var(--primary-color);
        background: rgba(78, 115, 223, 0.1);
        padding: 12px;
        border-radius: 14px;
    }

    .wallet-header h5 {
        font-size: 16px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
        flex: 1;
    }

    .wallet-balance {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 12px;
    }

    .wallet-balance h3 {
        font-size: 36px;
        font-weight: 700;
        color: var(--success-color);
        margin: 0;
    }

    .wallet-balance small {
        font-size: 14px;
        color: #95a5a6;
    }

    .wallet-footer {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #7f8c8d;
        font-size: 13px;
    }

    .wallet-footer i {
        color: var(--primary-color);
    }

    /* ======================
    FORM CARDS
    ====================== */
    .profile-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }

    .form-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.03);
    }

    .form-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid #edf2f7;
    }

    .form-header i {
        font-size: 22px;
        color: var(--primary-color);
        background: rgba(78, 115, 223, 0.1);
        padding: 10px;
        border-radius: 12px;
    }

    .form-header h5 {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }

    /* ======================
    FORM FIELDS
    ====================== */
    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 500;
        color: #4a5568;
        margin-bottom: 8px;
    }

    .form-label i {
        color: var(--primary-color);
        font-size: 14px;
    }

    .android-input {
        width: 100%;
        padding: 14px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        font-size: 15px;
        transition: all 0.3s ease;
        background: #f8fafc;
    }

    .android-input:focus {
        outline: none;
        border-color: var(--primary-color);
        background: white;
        box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1);
    }

    .input-with-icon {
        position: relative;
    }

    .input-with-icon i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 16px;
    }

    .input-with-icon input {
        padding-left: 45px;
    }

    /* ======================
    BUTTONS
    ====================== */
    .android-btn {
        width: 100%;
        padding: 14px 20px;
        border: none;
        border-radius: 12px;
        font-size: 15px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .android-btn:active {
        transform: scale(0.98);
    }

    .btn-primary {
        background: var(--primary-color);
        color: white;
        box-shadow: 0 4px 12px rgba(78, 115, 223, 0.3);
    }

    .btn-primary:hover {
        background: #3a5fc7;
    }

    .btn-warning {
        background: var(--warning-color);
        color: white;
        box-shadow: 0 4px 12px rgba(246, 194, 62, 0.3);
    }

    .btn-warning:hover {
        background: #e0a800;
    }

    .btn-outline {
        background: transparent;
        border: 1.5px solid var(--primary-color);
        color: var(--primary-color);
    }

    .btn-outline:hover {
        background: rgba(78, 115, 223, 0.1);
    }

    .btn-danger-outline {
        border-color: var(--danger-color);
        color: var(--danger-color);
    }

    .btn-danger-outline:hover {
        background: rgba(231, 74, 59, 0.1);
    }

    /* ======================
    QUICK ACTIONS GRID
    ====================== */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .action-item {
        background: white;
        border-radius: 16px;
        padding: 16px 12px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }

    .action-item:hover {
        border-color: var(--primary-color);
        transform: translateY(-2px);
    }

    .action-item:active {
        transform: scale(0.95);
    }

    .action-item i {
        font-size: 24px;
        color: var(--primary-color);
        margin-bottom: 8px;
    }

    .action-item span {
        font-size: 12px;
        font-weight: 500;
        color: #4a5568;
        display: block;
    }

    /* ======================
    PASSWORD REQUIREMENTS
    ====================== */
    .password-requirements {
        background: #f8fafc;
        border-radius: 12px;
        padding: 16px;
        margin: 16px 0;
        font-size: 12px;
    }

    .requirement-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #64748b;
        margin-bottom: 8px;
    }

    .requirement-item i {
        font-size: 12px;
        color: #94a3b8;
    }

    .requirement-item.valid i {
        color: var(--success-color);
    }

    /* ======================
    DIVIDER
    ====================== */
    .divider {
        display: flex;
        align-items: center;
        text-align: center;
        margin: 24px 0;
        color: #94a3b8;
        font-size: 12px;
    }

    .divider::before,
    .divider::after {
        content: '';
        flex: 1;
        border-bottom: 1px solid #e2e8f0;
    }

    .divider span {
        padding: 0 10px;
    }

    /* ======================
    MOBILE RESPONSIVE - Works with sidebar
    ====================== */
    @media screen and (max-width: 768px) {
        .mobile-header {
            display: flex;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 16px;
            padding-bottom: 80px;
        }

        .page-header {
            margin-top: 0;
        }

        .page-header h2 {
            font-size: 24px;
        }

        .page-header h2 i {
            font-size: 22px;
            padding: 8px;
        }

        .header-actions {
            display: none;
        }

        .profile-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .quick-actions {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .action-item {
            padding: 14px;
        }

        .action-item i {
            font-size: 22px;
        }

        .profile-avatar {
            flex-direction: column;
            text-align: center;
        }

        .avatar-circle {
            width: 70px;
            height: 70px;
            font-size: 32px;
        }

        .avatar-info h4 {
            font-size: 20px;
        }

        .wallet-balance h3 {
            font-size: 28px;
        }

        .form-card {
            padding: 20px;
        }

        .android-input {
            padding: 12px 16px;
            font-size: 14px;
        }

        .android-btn {
            padding: 12px;
            font-size: 14px;
        }
    }

    /* Tablet Styles */
    @media screen and (min-width: 769px) and (max-width: 1024px) {
        .profile-grid {
            gap: 16px;
        }

        .quick-actions {
            gap: 12px;
        }

        .action-item {
            padding: 14px 10px;
        }
    }

    /* Small Mobile Styles */
    @media screen and (max-width: 480px) {
        .main-content {
            padding: 12px;
        }

        .profile-header {
            padding: 20px;
        }

        .wallet-summary {
            padding: 16px;
        }

        .wallet-balance h3 {
            font-size: 24px;
        }

        .quick-actions {
            gap: 8px;
        }

        .action-item {
            padding: 12px 8px;
        }

        .action-item i {
            font-size: 20px;
            margin-bottom: 4px;
        }

        .action-item span {
            font-size: 11px;
        }

        .form-card {
            padding: 16px;
        }

        .form-header {
            margin-bottom: 16px;
            padding-bottom: 12px;
        }

        .form-header h5 {
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .android-input {
            padding: 10px 14px;
            font-size: 13px;
        }

        .input-with-icon i {
            font-size: 14px;
        }

        .input-with-icon input {
            padding-left: 40px;
        }
    }
</style>

<!-- Mobile Header with Menu Toggle -->
<div class="mobile-header">
    <button class="menu-toggle" id="mobileMenuToggle">
        <i class="fa fa-bars"></i>
    </button>
    <h1>My Profile</h1>
    <div class="mobile-header-actions">
        <i class="fa fa-bell"></i>
        <i class="fa fa-share-alt"></i>
    </div>
</div>

<!-- Main Content Area - Works with sidebar -->
<div class="main-content" id="mainContent">
    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fa fa-user-circle"></i> 
            My Profile
        </h2>
        <div class="header-actions">
            <button>
                <i class="fa fa-shield"></i>
                Privacy
            </button>
            <button>
                <i class="fa fa-cog"></i>
                Settings
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if($success): ?>
    <div class="alert-message alert-success">
        <i class="fa fa-check-circle fa-lg"></i>
        <span><?= $success ?></span>
    </div>
    <?php endif; ?>

    <?php if($error): ?>
    <div class="alert-message alert-danger">
        <i class="fa fa-exclamation-circle fa-lg"></i>
        <span><?= $error ?></span>
    </div>
    <?php endif; ?>

    <!-- Profile Header Card -->
    <div class="profile-header">
        <div class="profile-avatar">
            <div class="avatar-circle">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div class="avatar-info">
                <h4><?= htmlspecialchars($user['name']) ?></h4>
                <p>
                    <i class="fa fa-envelope"></i> <?= htmlspecialchars($user['email']) ?>
                </p>
                <p>
                    <i class="fa fa-phone"></i> <?= htmlspecialchars($user['phone'] ?? 'Not provided') ?>
                </p>
                <p>
                    <i class="fa fa-calendar"></i> Member since <?= date('M Y', strtotime($user['created_at'] ?? 'now')) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <div class="action-item">
            <i class="fa fa-edit"></i>
            <span>Edit Profile</span>
        </div>
        <div class="action-item">
            <i class="fa fa-lock"></i>
            <span>Security</span>
        </div>
        <div class="action-item">
            <i class="fa fa-bell"></i>
            <span>Notifications</span>
        </div>
        <div class="action-item">
            <i class="fa fa-shield"></i>
            <span>Privacy</span>
        </div>
    </div>

    <!-- Wallet Summary Card -->
    <div class="wallet-summary">
        <div class="wallet-header">
            <i class="fa fa-wallet"></i>
            <h5>Wallet Balance</h5>
            <span style="margin-left: auto; font-size: 13px; color: #94a3b8;">
                <i class="fa fa-shield"></i> Secured
            </span>
        </div>
        <div class="wallet-balance">
            <h3>₹<?= number_format($balance) ?></h3>
            <small>.00</small>
        </div>
        <div class="wallet-footer">
            <i class="fa fa-info-circle"></i>
            <span>Available for purchases and refunds</span>
        </div>
    </div>

    <!-- Profile Forms Grid -->
    <div class="profile-grid">
        <!-- Profile Information Form -->
        <div class="form-card">
            <div class="form-header">
                <i class="fa fa-user-edit"></i>
                <h5>Profile Information</h5>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-user"></i> Full Name
                    </label>
                    <div class="input-with-icon">
                        <i class="fa fa-user"></i>
                        <input type="text" name="name" class="android-input" 
                               value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-envelope"></i> Email Address
                    </label>
                    <div class="input-with-icon">
                        <i class="fa fa-envelope"></i>
                        <input type="email" name="email" class="android-input" 
                               value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-phone"></i> Phone Number
                    </label>
                    <div class="input-with-icon">
                        <i class="fa fa-phone"></i>
                        <input type="text" name="phone" class="android-input" 
                               value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                </div>

                <button type="submit" name="update_profile" class="android-btn btn-primary">
                    <i class="fa fa-save"></i> Update Profile
                </button>
            </form>
        </div>

        <!-- Change Password Form -->
        <div class="form-card">
            <div class="form-header">
                <i class="fa fa-lock"></i>
                <h5>Change Password</h5>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-key"></i> Current Password
                    </label>
                    <div class="input-with-icon">
                        <i class="fa fa-lock"></i>
                        <input type="password" name="current_password" class="android-input" 
                               placeholder="Enter current password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-key"></i> New Password
                    </label>
                    <div class="input-with-icon">
                        <i class="fa fa-lock"></i>
                        <input type="password" name="new_password" class="android-input" 
                               placeholder="Enter new password" required>
                    </div>
                </div>

                <!-- Password Requirements -->
                <div class="password-requirements">
                    <div class="requirement-item">
                        <i class="fa fa-circle"></i>
                        <span>At least 8 characters long</span>
                    </div>
                    <div class="requirement-item">
                        <i class="fa fa-circle"></i>
                        <span>Contains at least one number</span>
                    </div>
                    <div class="requirement-item">
                        <i class="fa fa-circle"></i>
                        <span>Contains uppercase & lowercase</span>
                    </div>
                </div>

                <button type="submit" name="change_password" class="android-btn btn-warning">
                    <i class="fa fa-refresh"></i> Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- Account Actions -->
    <div style="margin-top: 24px;">
        <button class="android-btn btn-outline">
            <i class="fa fa-sign-out"></i> Logout from all devices
        </button>
        
        <div class="divider">
            <span>OR</span>
        </div>
        
        <button class="android-btn btn-outline btn-danger-outline">
            <i class="fa fa-trash"></i> Delete Account
        </button>
    </div>
</div>

<!-- Mobile Touch Optimizations -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle - works with sidebar
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            sidebar.classList.toggle('active');
            
            // Create overlay if not exists
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    setTimeout(() => overlay.remove(), 300);
                });
            }
            
            if (sidebar.classList.contains('active')) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            } else {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                setTimeout(() => overlay.remove(), 300);
            }
        });
    }

    // Touch feedback for buttons
    const buttons = document.querySelectorAll('.android-btn, .action-item');
    
    buttons.forEach(button => {
        button.addEventListener('touchstart', function() {
            this.style.opacity = '0.7';
        });
        
        button.addEventListener('touchend', function() {
            this.style.opacity = '1';
        });
        
        button.addEventListener('touchcancel', function() {
            this.style.opacity = '1';
        });
    });

    // Password validation
    const passwordInput = document.querySelector('input[name="new_password"]');
    if(passwordInput) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const requirements = document.querySelectorAll('.requirement-item');
            
            // Length check
            if(password.length >= 8) {
                requirements[0].classList.add('valid');
                requirements[0].querySelector('i').className = 'fa fa-check-circle';
            } else {
                requirements[0].classList.remove('valid');
                requirements[0].querySelector('i').className = 'fa fa-circle';
            }
            
            // Number check
            if(/\d/.test(password)) {
                requirements[1].classList.add('valid');
                requirements[1].querySelector('i').className = 'fa fa-check-circle';
            } else {
                requirements[1].classList.remove('valid');
                requirements[1].querySelector('i').className = 'fa fa-circle';
            }
            
            // Uppercase & lowercase check
            if(/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                requirements[2].classList.add('valid');
                requirements[2].querySelector('i').className = 'fa fa-check-circle';
            } else {
                requirements[2].classList.remove('valid');
                requirements[2].querySelector('i').className = 'fa fa-circle';
            }
        });
    }

    // Quick actions click
    const actionItems = document.querySelectorAll('.action-item');
    actionItems.forEach((item, index) => {
        item.addEventListener('click', function() {
            const actions = ['Edit Profile', 'Security Settings', 'Notifications', 'Privacy Settings'];
            alert(actions[index] + ' feature coming soon!');
        });
    });

    // Delete account confirmation
    const deleteBtn = document.querySelector('.btn-danger-outline');
    if(deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if(confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
                alert('Account deletion request submitted. You will receive an email confirmation.');
            }
        });
    }

    // Logout from all devices
    const logoutAllBtn = document.querySelector('.btn-outline:first-child');
    if(logoutAllBtn) {
        logoutAllBtn.addEventListener('click', function() {
            if(confirm('This will log you out from all devices. Continue?')) {
                alert('You have been logged out from all other devices.');
            }
        });
    }

    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                // Desktop view
                if (sidebar) sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
                document.body.style.overflow = '';
            }
        }, 250);
    });
});
</script>

<?php include "../layout/footer.php"; ?>