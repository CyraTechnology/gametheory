<?php
session_start();
require_once '../db.php';

$error   = '';
$success = '';

// Hard lockout check
if (
    isset($_SESSION['login_attempts'], $_SESSION['lockout_time']) &&
    $_SESSION['login_attempts'] >= 5 &&
    (time() - $_SESSION['lockout_time']) < 900
) {
    $remaining = 900 - (time() - $_SESSION['lockout_time']);
    $error = "Too many failed attempts. Try again in " . ceil($remaining / 60) . " min.";
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    $email        = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $password     = $_POST['password'] ?? '';
    $remember     = isset($_POST['remember']);
    $company_code = trim($_POST['company_code'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT u.*, c.name AS company_name, c.id AS company_id
                FROM users u
                LEFT JOIN companies c ON u.company_id = c.id
                WHERE u.email = ? AND u.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $error = "Invalid email or password.";
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                if ($_SESSION['login_attempts'] >= 5) $_SESSION['lockout_time'] = time();

            } else {
                if ($user['role'] === 'company_manager') {
                    if (empty($company_code)) {
                        $error = "Company access code is required.";
                    } else {
                        $cs = $conn->prepare("SELECT id FROM companies WHERE id = ? AND SUBSTRING(MD5(id),1,6) = ?");
                        $cs->execute([$user['company_id'], $company_code]);
                        if (!$cs->fetch()) $error = "Invalid company access code.";
                    }
                }

                if (empty($error)) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['company_id'] = $user['company_id'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    if ($remember) {
                        $token   = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60);
                        setcookie('remember_token', $token, $expires, '/', '', true, true);
                        $conn->prepare("INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
                             ->execute([$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', $expires)]);
                    }

                    $conn->prepare("UPDATE users SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?")
                         ->execute([$user['id']]);
                    $conn->prepare("INSERT INTO user_logs (user_id, activity_type, ip_address, user_agent) VALUES (?, 'login', ?, ?)")
                         ->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);

                    unset($_SESSION['login_attempts'], $_SESSION['lockout_time']);

                    switch ($user['role']) {
                        case 'admin':           header("Location: ../admin/admin_dashboard.php");     break;
                        case 'company_manager': header("Location: ../admin/company_dashboard.php");   break;
                        case 'analyst':         header("Location: ../admin/analytics_dashboard.php"); break;
                        default:                header("Location: customer_dashboard.php");
                    }
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}

if (isset($_GET['registered']) && $_GET['registered'] === 'true') {
    $success = "Registration successful! You can now sign in.";
}

$attempts      = $_SESSION['login_attempts'] ?? 0;
$isLocked      = $attempts >= 5 && isset($_SESSION['lockout_time']) && (time() - $_SESSION['lockout_time'] < 900);
$showAttemptWarn = $attempts >= 3 && !$isLocked;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Sign In — GameTheory Pricing</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
    /* ═══════════════════════════════════
       TOKENS
    ═══════════════════════════════════ */
    :root {
        --bg:        #07090f;
        --bg-2:      #0d1018;
        --bg-3:      #131720;
        --bg-4:      #1a2030;
        --bg-5:      #1f2638;

        --border:    rgba(255,255,255,0.07);
        --border-2:  rgba(255,255,255,0.12);
        --border-c:  rgba(0,229,255,0.3);

        --cyan:      #00e5ff;
        --cyan-dim:  rgba(0,229,255,0.1);
        --cyan-glow: rgba(0,229,255,0.22);
        --green:     #00e676;
        --green-dim: rgba(0,230,118,0.1);
        --red:       #ff4f4f;
        --red-dim:   rgba(255,79,79,0.1);
        --amber:     #ffb300;
        --amber-dim: rgba(255,179,0,0.1);

        --text-1:    #eef1f7;
        --text-2:    #8b90a0;
        --text-3:    #4a5068;
        --text-mono: #b0e0ff;

        --radius:    13px;
        --radius-lg: 20px;
        --ease:      cubic-bezier(0.23,1,0.32,1);

        --font-d: 'Syne', sans-serif;
        --font-b: 'Outfit', sans-serif;
        --font-m: 'IBM Plex Mono', monospace;
    }

    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    html { font-size:16px; }

    body {
        font-family: var(--font-b);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow-x: hidden;
        padding: 20px 16px;
        position: relative;
    }

    /* ── Scanline ── */
    body::before {
        content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
        background: repeating-linear-gradient(
            0deg, transparent, transparent 2px,
            rgba(0,0,0,0.06) 2px, rgba(0,0,0,0.06) 4px
        );
    }

    /* ── Ambient glows ── */
    .amb {
        position:fixed; border-radius:50%;
        filter:blur(90px); pointer-events:none; z-index:0;
    }
    .amb-1 {
        width:550px; height:550px;
        background: radial-gradient(circle, rgba(0,229,255,0.07) 0%, transparent 70%);
        top:-180px; left:-180px;
        animation: adrift 22s ease-in-out infinite alternate;
    }
    .amb-2 {
        width:420px; height:420px;
        background: radial-gradient(circle, rgba(124,77,255,0.05) 0%, transparent 70%);
        bottom:-120px; right:-120px;
        animation: adrift 28s ease-in-out infinite alternate-reverse;
    }
    @keyframes adrift {
        from { transform:translate(0,0) scale(1); }
        to   { transform:translate(60px,50px) scale(1.12); }
    }

    /* ── Grid overlay ── */
    .grid-bg {
        position:fixed; inset:0; z-index:0; pointer-events:none;
        background-image:
            linear-gradient(rgba(0,229,255,0.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,229,255,0.025) 1px, transparent 1px);
        background-size: 56px 56px;
        mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 30%, transparent 100%);
    }

    /* ══════════════════════════════════
       LAYOUT — two-panel on desktop
    ══════════════════════════════════ */
    .shell {
        position:relative; z-index:1;
        width:100%; max-width:900px;
        display:grid;
        grid-template-columns: 1fr 1fr;
        border-radius: var(--radius-lg);
        overflow:hidden;
        border:1px solid var(--border);
        box-shadow:
            0 0 0 1px rgba(255,255,255,0.03) inset,
            0 50px 100px rgba(0,0,0,0.7),
            0 0 80px rgba(0,229,255,0.04);
        animation: shellIn 0.7s var(--ease) both;
    }

    @keyframes shellIn {
        from { opacity:0; transform:translateY(28px) scale(0.97); }
        to   { opacity:1; transform:translateY(0) scale(1); }
    }

    /* ── Left panel — brand/info ── */
    .panel-left {
        background: var(--bg-2);
        padding: 52px 40px;
        display:flex; flex-direction:column; justify-content:space-between;
        border-right:1px solid var(--border);
        position:relative; overflow:hidden;
    }

    /* Decorative corner lines */
    .panel-left::before {
        content:'';
        position:absolute; top:0; left:0;
        width:100%; height:3px;
        background:linear-gradient(90deg, var(--cyan), transparent 60%);
    }

    .brand-block { position:relative; }

    .brand-icon {
        width:48px; height:48px; border-radius:13px;
        background:var(--cyan-dim);
        border:1px solid var(--border-c);
        display:flex; align-items:center; justify-content:center;
        color:var(--cyan); font-size:20px;
        margin-bottom:22px;
        box-shadow:0 0 20px rgba(0,229,255,0.1);
    }

    .brand-name {
        font-family: var(--font-d);
        font-size:26px; font-weight:800;
        letter-spacing:-0.03em;
        color:var(--text-1); line-height:1;
        margin-bottom:6px;
    }
    .brand-name span { color:var(--cyan); }

    .brand-tag {
        font-family:var(--font-m);
        font-size:11px; color:var(--text-3);
        letter-spacing:0.1em; text-transform:uppercase;
        margin-bottom:36px;
    }

    .brand-desc {
        font-size:14px; color:var(--text-2);
        line-height:1.65; font-weight:300;
        margin-bottom:40px;
    }

    /* Feature list */
    .feature-list { list-style:none; display:flex; flex-direction:column; gap:14px; }
    .feature-item {
        display:flex; align-items:center; gap:12px;
        font-size:13px; color:var(--text-2);
    }
    .feature-dot {
        width:28px; height:28px; border-radius:8px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-size:11px;
    }
    .fd-cyan   { background:var(--cyan-dim);  color:var(--cyan);  border:1px solid rgba(0,229,255,0.2); }
    .fd-green  { background:var(--green-dim); color:var(--green); border:1px solid rgba(0,230,118,0.2); }
    .fd-amber  { background:var(--amber-dim); color:var(--amber); border:1px solid rgba(255,179,0,0.2); }

    /* Status bar bottom-left */
    .status-bar {
        display:flex; align-items:center; gap:8px;
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        padding-top:32px;
        border-top:1px solid var(--border);
    }
    .status-dot {
        width:6px; height:6px; border-radius:50%;
        background:var(--green);
        box-shadow:0 0 6px var(--green);
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%,100% { opacity:1; } 50% { opacity:0.4; }
    }

    /* ── Right panel — form ── */
    .panel-right {
        background: var(--bg-3);
        padding: 48px 40px;
        display:flex; flex-direction:column; justify-content:center;
    }

    .form-eyebrow {
        font-family:var(--font-m);
        font-size:10px; font-weight:500;
        letter-spacing:0.15em; text-transform:uppercase;
        color:var(--cyan); margin-bottom:10px;
    }

    .form-title {
        font-family:var(--font-d);
        font-size:24px; font-weight:800;
        letter-spacing:-0.02em;
        color:var(--text-1); margin-bottom:4px;
    }

    .form-sub {
        font-size:13px; color:var(--text-2);
        font-weight:300; margin-bottom:32px;
    }

    /* ── Alerts ── */
    .alert {
        display:flex; align-items:flex-start; gap:10px;
        padding:12px 14px; border-radius:10px;
        font-size:13px; margin-bottom:22px;
        animation: alertIn 0.4s var(--ease);
    }
    @keyframes alertIn {
        from { opacity:0; transform:translateY(-6px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .alert i { font-size:13px; flex-shrink:0; margin-top:1px; }

    .alert-error {
        background:var(--red-dim);
        border:1px solid rgba(255,79,79,0.22);
        color:#f08080;
    }
    .alert-success {
        background:var(--green-dim);
        border:1px solid rgba(0,230,118,0.22);
        color:#7dcf9f;
    }
    .alert-warn {
        background:var(--amber-dim);
        border:1px solid rgba(255,179,0,0.22);
        color:var(--amber);
    }

    /* ── Fields ── */
    .field { margin-bottom:18px; }

    .field-label {
        display:block;
        font-family:var(--font-m);
        font-size:10px; font-weight:500;
        letter-spacing:0.12em; text-transform:uppercase;
        color:var(--text-3); margin-bottom:8px;
    }

    .input-wrap { position:relative; }

    .input-icon {
        position:absolute; left:14px; top:50%;
        transform:translateY(-50%);
        font-size:13px; color:var(--text-3);
        pointer-events:none;
        transition:color 0.3s ease;
    }

    .input-wrap:focus-within .input-icon { color:var(--cyan); }

    .field-input {
        width:100%;
        padding:13px 14px 13px 40px;
        background:var(--bg-4);
        border:1px solid var(--border);
        border-radius:var(--radius);
        color:var(--text-1);
        font-family:var(--font-b);
        font-size:14px; font-weight:300;
        outline:none;
        transition:all 0.3s ease;
    }
    .field-input::placeholder { color:var(--text-3); }
    .field-input:focus {
        border-color:var(--border-c);
        background:var(--bg-5);
        box-shadow:0 0 0 3px rgba(0,229,255,0.07);
    }
    .field-input:disabled {
        opacity:0.4; cursor:not-allowed;
    }

    /* eye button */
    .eye-btn {
        position:absolute; right:12px; top:50%;
        transform:translateY(-50%);
        background:none; border:none;
        color:var(--text-3); font-size:13px;
        cursor:pointer; padding:4px;
        transition:color 0.3s; display:flex;
    }
    .eye-btn:hover { color:var(--cyan); }

    /* company code reveal */
    .company-field {
        overflow:hidden;
        max-height:0;
        opacity:0;
        transition:max-height 0.4s var(--ease), opacity 0.4s ease, margin 0.4s ease;
        margin-bottom:0;
    }
    .company-field.visible {
        max-height:100px;
        opacity:1;
        margin-bottom:18px;
    }

    .company-hint {
        font-family:var(--font-m);
        font-size:10px; color:var(--text-3);
        margin-top:6px;
        padding:8px 12px; border-radius:8px;
        background:var(--bg-4);
        border:1px solid var(--border);
        display:flex; align-items:center; gap:7px;
    }
    .company-hint i { color:var(--cyan); font-size:10px; }

    /* attempt bar */
    .attempt-bar {
        height:3px; border-radius:2px;
        background:var(--bg-4);
        margin-bottom:22px; overflow:hidden;
    }
    .attempt-fill {
        height:100%; border-radius:2px;
        background:var(--red);
        transition:width 0.5s var(--ease);
    }

    /* remember me */
    .remember-row {
        display:flex; align-items:center; gap:10px;
        margin-bottom:22px;
    }
    .custom-check {
        width:16px; height:16px;
        border:1px solid var(--border-2);
        border-radius:5px;
        background:var(--bg-4);
        appearance:none; cursor:pointer;
        flex-shrink:0;
        transition:all 0.2s ease;
        position:relative;
    }
    .custom-check:checked {
        background:var(--cyan);
        border-color:var(--cyan);
    }
    .custom-check:checked::after {
        content:'\f00c';
        font-family:'Font Awesome 6 Free';
        font-weight:900;
        font-size:9px; color:#000;
        position:absolute; top:50%; left:50%;
        transform:translate(-50%,-50%);
    }
    .remember-label {
        font-size:13px; color:var(--text-2);
        cursor:pointer; user-select:none;
    }

    /* submit button */
    .btn-submit {
        width:100%; padding:14px 0;
        background:var(--cyan);
        color:#020a0c;
        border:none; border-radius:var(--radius);
        font-family:var(--font-d);
        font-size:13px; font-weight:700;
        letter-spacing:0.06em; text-transform:uppercase;
        cursor:pointer; position:relative; overflow:hidden;
        display:flex; align-items:center; justify-content:center; gap:9px;
        transition:all 0.3s ease;
    }
    .btn-submit::before {
        content:'';
        position:absolute; inset:0;
        background:linear-gradient(135deg,rgba(255,255,255,0.15),transparent 50%);
        opacity:0; transition:opacity 0.3s;
    }
    .btn-submit:hover::before { opacity:1; }
    .btn-submit:hover {
        transform:translateY(-2px);
        box-shadow:0 10px 30px rgba(0,229,255,0.3);
        background:#1aecff;
    }
    .btn-submit:active { transform:translateY(0); box-shadow:none; }
    .btn-submit:disabled {
        opacity:0.4; cursor:not-allowed;
        transform:none; box-shadow:none;
    }

    /* ripple */
    .ripple {
        position:absolute; border-radius:50%;
        background:rgba(0,0,0,0.15);
        transform:scale(0);
        animation:ripple 0.55s linear;
        pointer-events:none;
    }
    @keyframes ripple { to { transform:scale(4); opacity:0; } }

    /* spinner */
    .spin {
        width:14px; height:14px; border-radius:50%;
        border:2px solid rgba(0,0,0,0.2);
        border-top-color:#020a0c;
        animation:spin 0.7s linear infinite;
        display:none;
    }
    @keyframes spin { to { transform:rotate(360deg); } }
    .btn-submit.loading .spin  { display:block; }
    .btn-submit.loading .btn-label { display:none; }

    /* divider */
    .or-divider {
        display:flex; align-items:center; gap:12px;
        margin:20px 0;
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        letter-spacing:0.1em;
    }
    .or-divider::before, .or-divider::after {
        content:''; flex:1; height:1px; background:var(--border);
    }

    /* links */
    .form-links {
        display:flex; flex-direction:column; gap:10px;
        text-align:center;
    }
    .form-link {
        font-size:13px; color:var(--text-2);
        text-decoration:none;
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        transition:color 0.2s;
    }
    .form-link:hover { color:var(--cyan); }
    .form-link i { font-size:12px; }

    /* SSL note */
    .ssl-note {
        margin-top:20px; padding-top:16px;
        border-top:1px solid var(--border);
        display:flex; align-items:center; justify-content:center; gap:7px;
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        letter-spacing:0.06em;
    }
    .ssl-note i { color:var(--green); }

    /* demo strip */
    .demo-strip {
        margin-top:18px; padding:12px 14px;
        background:var(--bg-4); border-radius:10px;
        border:1px solid var(--border);
    }
    .demo-strip-title {
        font-family:var(--font-m); font-size:9px; font-weight:500;
        letter-spacing:0.12em; text-transform:uppercase;
        color:var(--text-3); margin-bottom:8px;
    }
    .demo-accounts {
        display:grid; grid-template-columns:1fr 1fr; gap:6px;
    }
    .demo-account {
        background:var(--bg-5); border-radius:7px;
        border:1px solid var(--border);
        padding:8px 10px; cursor:pointer;
        transition:border-color 0.2s;
    }
    .demo-account:hover { border-color:var(--border-c); }
    .demo-role {
        font-family:var(--font-m); font-size:9px; font-weight:500;
        letter-spacing:0.08em; text-transform:uppercase;
        color:var(--cyan); margin-bottom:2px;
    }
    .demo-creds {
        font-family:var(--font-m); font-size:9px; color:var(--text-3);
        line-height:1.5;
    }

    /* ══════════════════════════════════
       RESPONSIVE
    ══════════════════════════════════ */
    @media(max-width:700px) {
        .shell { grid-template-columns:1fr; max-width:440px; }
        .panel-left { display:none; }
        .panel-right { padding:36px 28px; }
    }
    @media(max-width:400px) {
        .panel-right { padding:28px 20px; }
        .demo-accounts { grid-template-columns:1fr; }
    }

    /* stagger field animation */
    .field, .remember-row, .btn-submit, .or-divider, .form-links, .ssl-note, .demo-strip {
        animation: fIn 0.5s var(--ease) both;
    }
    .field:nth-child(1) { animation-delay:0.08s; }
    .field:nth-child(2) { animation-delay:0.13s; }
    .field:nth-child(3) { animation-delay:0.18s; }
    .company-field      { animation-delay:0s; }
    .remember-row       { animation-delay:0.22s; }
    .btn-submit         { animation-delay:0.26s; }

    @keyframes fIn {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }
    </style>
</head>
<body>

<div class="amb amb-1"></div>
<div class="amb amb-2"></div>
<div class="grid-bg"></div>

<div class="shell">

    <!-- ══ LEFT PANEL ══ -->
    <div class="panel-left">
        <div class="brand-block">
            <div class="brand-icon"><i class="fas fa-chess-board"></i></div>
            <div class="brand-name">Game<span>Theory</span></div>
            <div class="brand-tag">Dynamic Pricing Platform</div>
            <p class="brand-desc">
                Real-time market competition engine powered by Nash equilibria, dominant strategy detection, and live price signal analytics.
            </p>

            <ul class="feature-list">
                <li class="feature-item">
                    <span class="feature-dot fd-cyan"><i class="fas fa-chart-line"></i></span>
                    Live price feed &amp; market pulse
                </li>
                <li class="feature-item">
                    <span class="feature-dot fd-green"><i class="fas fa-chess"></i></span>
                    Game theory strategy engine
                </li>
                <li class="feature-item">
                    <span class="feature-dot fd-amber"><i class="fas fa-bolt"></i></span>
                    Instant competitor response alerts
                </li>
            </ul>
        </div>

        <div class="status-bar">
            <div class="status-dot"></div>
            MARKET ACTIVE · ALL SYSTEMS OPERATIONAL
        </div>
    </div>

    <!-- ══ RIGHT PANEL ══ -->
    <div class="panel-right">

        <div class="form-eyebrow">Secure Access</div>
        <h1 class="form-title">Sign In</h1>
        <p class="form-sub">Access your pricing dashboard</p>

        <?php if ($error): ?>
        <div class="alert alert-error" id="alertBox">
            <i class="fas fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success" id="alertBox">
            <i class="fas fa-circle-check"></i>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($showAttemptWarn): ?>
        <div class="alert alert-warn">
            <i class="fas fa-triangle-exclamation"></i>
            <span><?= $attempts ?>/5 failed attempts — account will lock after 5.</span>
        </div>
        <div class="attempt-bar">
            <div class="attempt-fill" style="width:<?= min(($attempts/5)*100,100) ?>%"></div>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" onsubmit="return beforeSubmit(this)">

            <div class="field">
                <label class="field-label" for="email">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" class="field-input"
                           placeholder="you@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           autocomplete="email"
                           <?= $isLocked ? 'disabled' : '' ?> required autofocus>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="field-input"
                           placeholder="Enter your password"
                           autocomplete="current-password"
                           <?= $isLocked ? 'disabled' : '' ?> required>
                    <button type="button" class="eye-btn" onclick="toggleEye()" tabindex="-1">
                        <i class="fas fa-eye-slash" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Company code (revealed for managers) -->
            <div class="company-field" id="companyField">
                <div class="field">
                    <label class="field-label" for="company_code">Company Access Code</label>
                    <div class="input-wrap">
                        <i class="fas fa-building input-icon"></i>
                        <input type="text" id="company_code" name="company_code" class="field-input"
                               placeholder="6-character code"
                               maxlength="6" autocomplete="off">
                    </div>
                </div>
                <div class="company-hint">
                    <i class="fas fa-circle-info"></i>
                    Contact your admin for your company access code.
                </div>
            </div>

            <div class="remember-row">
                <input type="checkbox" class="custom-check" name="remember" id="remember"
                       <?= $isLocked ? 'disabled' : '' ?>>
                <label class="remember-label" for="remember">Keep me signed in for 30 days</label>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn" <?= $isLocked ? 'disabled' : '' ?>>
                <div class="spin"></div>
                <span class="btn-label">
                    <i class="fas fa-arrow-right-to-bracket"></i> &nbsp;Sign In
                </span>
            </button>

        </form>

        <div class="or-divider">or</div>

        <div class="form-links">
            <a href="register.php" class="form-link">
                <i class="fas fa-user-plus"></i> Create a new account
            </a>
            <a href="forgot_password.php" class="form-link">
                <i class="fas fa-key"></i> Forgot your password?
            </a>
        </div>

        <div class="ssl-note">
            <i class="fas fa-shield-halved"></i>
            256-BIT SSL · BCRYPT ENCRYPTED · BRUTE-FORCE PROTECTED
        </div>

        <div class="demo-strip">
            <div class="demo-strip-title"><i class="fas fa-vial"></i> &nbsp;Demo Accounts</div>
            <div class="demo-accounts">
                <div class="demo-account" onclick="fillDemo('demo@customer.com','Demodemo@1')">
                    <div class="demo-role">Customer</div>
                    <div class="demo-creds">demo@customer.com<br>Demodemo@1</div>
                </div>
                <div class="demo-account" onclick="fillDemo('demo@manager.com','demodemo')">
                    <div class="demo-role">Manager</div>
                    <div class="demo-creds">demo@manager.com<br>demodemo</div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
/* ── Eye toggle ── */
function toggleEye() {
    const inp  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if(inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye-slash';
    }
}

/* ── Company code reveal ── */
document.getElementById('email').addEventListener('blur', function() {
    const val   = this.value.toLowerCase();
    const field = document.getElementById('companyField');
    const isManager = val.includes('manager') || val.includes('@company.');
    field.classList.toggle('visible', isManager);
    document.getElementById('company_code').required = isManager;
});

/* ── Demo fill ── */
function fillDemo(email, pw) {
    document.getElementById('email').value    = email;
    document.getElementById('password').value = pw;
    document.getElementById('email').dispatchEvent(new Event('blur'));
}

/* ── Submit guard ── */
function beforeSubmit(form) {
    <?php if ($isLocked): ?>
        return false;
    <?php endif; ?>

    if(form.dataset.busy) return false;
    form.dataset.busy = '1';

    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    return true;
}

/* ── Ripple ── */
document.getElementById('submitBtn').addEventListener('click', function(e) {
    const r    = document.createElement('span');
    const rect = this.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    r.className = 'ripple';
    r.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-rect.left-size/2}px;top:${e.clientY-rect.top-size/2}px`;
    this.appendChild(r);
    r.addEventListener('animationend', () => r.remove());
});

/* ── Auto-dismiss alert ── */
const alertBox = document.getElementById('alertBox');
if(alertBox) {
    setTimeout(() => {
        alertBox.style.transition = 'opacity 0.6s ease';
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.style.display = 'none', 600);
    }, 6000);
}
</script>
</body>
</html>