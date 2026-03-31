<?php
session_start();
require_once '../db.php';

$errors   = [];
$success  = '';
$formData = [];
$registration_open = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $errors[] = "Security token invalid. Please refresh and try again.";
    }

    $name             = trim(filter_var($_POST['name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
    $email            = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role             = ($_POST['role'] === 'admin' && isset($_POST['admin_key'])) ? 'admin' : ($_POST['role'] ?? 'customer');
    $company_code     = $_POST['company_code'] ?? '';
    $captcha_answer   = $_POST['captcha'] ?? '';

    $formData = compact('name', 'email', 'role');

    if (strlen($name) < 2 || strlen($name) > 100) $errors[] = "Name must be 2–100 characters.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = "Please enter a valid email address.";
    if (strlen($password) < 8)                       $errors[] = "Password must be at least 8 characters.";
    if (!preg_match('/[A-Z]/', $password))            $errors[] = "Password needs at least one uppercase letter.";
    if (!preg_match('/[a-z]/', $password))            $errors[] = "Password needs at least one lowercase letter.";
    if (!preg_match('/[0-9]/', $password))            $errors[] = "Password needs at least one number.";
    if ($password !== $confirm_password)              $errors[] = "Passwords do not match.";

    if ($role === 'admin' && ($_POST['admin_key'] ?? '') !== 'GAME_THEORY_2026_ADMIN')
        $errors[] = "Invalid admin registration key.";

    if (!isset($_SESSION['captcha_sum']))
        $errors[] = "CAPTCHA expired. Please try again.";
    elseif ((int)$captcha_answer !== (int)$_SESSION['captcha_sum'])
        $errors[] = "Incorrect CAPTCHA answer.";

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) $errors[] = "This email is already registered.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = "System error. Please try again later.";
        }
    }

    $company_id = null;
    if (!empty($company_code) && empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM companies WHERE SUBSTRING(MD5(id),1,6)=?");
        $stmt->execute([$company_code]);
        if ($company = $stmt->fetch()) {
            $company_id = $company['id'];
            $role = 'company_manager';
        } else {
            $errors[] = "Invalid company registration code.";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            $hashed    = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $vtoken    = bin2hex(random_bytes(32));
            $prefs     = json_encode(['email_notifications'=>true,'price_alerts'=>false,'theme'=>'dark','registered_ip'=>$_SERVER['REMOTE_ADDR']]);

            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, company_id, preferences, verification_token) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$name, $email, $hashed, $role, $company_id, $prefs, $vtoken]);
            $uid = $conn->lastInsertId();

            $conn->prepare("INSERT INTO user_carts (user_id) VALUES (?)")->execute([$uid]);
            $conn->prepare("INSERT INTO wallets (user_id,balance) VALUES (?,0)")->execute([$uid]);
            $conn->prepare("INSERT INTO user_logs (user_id,activity_type,ip_address,user_agent,details) VALUES (?,'registration',?,?,?)")
                 ->execute([$uid, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], json_encode(['method'=>'form'])]);

            $conn->commit();
            $_SESSION['registration_success'] = true;
            $_SESSION['registered_email'] = $email;
            header("Location: registration_success.php");
            exit;
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log($e->getMessage());
            $errors[] = "Registration failed. Please try again.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['captcha_num1'] = rand(1,10);
        $_SESSION['captcha_num2'] = rand(1,10);
        $_SESSION['captcha_sum']  = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['captcha_num1'] = rand(1,10);
    $_SESSION['captcha_num2'] = rand(1,10);
    $_SESSION['captcha_sum']  = $_SESSION['captcha_num1'] + $_SESSION['captcha_num2'];
}

if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$c1 = $_SESSION['captcha_num1'];
$c2 = $_SESSION['captcha_num2'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Create Account — GameTheory Pricing</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@300;400;500&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
    :root {
        --bg:        #07090f;
        --bg-2:      #0d1018;
        --bg-3:      #131720;
        --bg-4:      #1a2030;
        --bg-5:      #1f2638;
        --bg-6:      #242b3d;

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
        --violet:    #7c4dff;
        --violet-dim:rgba(124,77,255,0.1);

        --text-1:    #eef1f7;
        --text-2:    #8b90a0;
        --text-3:    #4a5068;

        --radius:    12px;
        --radius-lg: 20px;
        --ease:      cubic-bezier(0.23,1,0.32,1);

        --font-d: 'Syne', sans-serif;
        --font-b: 'Outfit', sans-serif;
        --font-m: 'IBM Plex Mono', monospace;
    }

    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    html { font-size:16px; scroll-behavior:smooth; }

    body {
        font-family: var(--font-b);
        background: var(--bg);
        color: var(--text-1);
        min-height: 100vh;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 32px 16px 48px;
        overflow-x: hidden;
        position: relative;
    }

    body::before {
        content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
        background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.06) 2px, rgba(0,0,0,0.06) 4px);
    }

    .amb { position:fixed; border-radius:50%; filter:blur(90px); pointer-events:none; z-index:0; }
    .amb-1 {
        width:600px; height:600px;
        background: radial-gradient(circle, rgba(0,229,255,0.06) 0%, transparent 70%);
        top:-200px; left:-200px;
        animation: adrift 22s ease-in-out infinite alternate;
    }
    .amb-2 {
        width:450px; height:450px;
        background: radial-gradient(circle, rgba(124,77,255,0.05) 0%, transparent 70%);
        bottom:-100px; right:-100px;
        animation: adrift 28s ease-in-out infinite alternate-reverse;
    }
    @keyframes adrift {
        from { transform:translate(0,0) scale(1); }
        to   { transform:translate(60px,50px) scale(1.1); }
    }

    .grid-bg {
        position:fixed; inset:0; z-index:0; pointer-events:none;
        background-image:
            linear-gradient(rgba(0,229,255,0.023) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0,229,255,0.023) 1px, transparent 1px);
        background-size: 56px 56px;
        mask-image: radial-gradient(ellipse 80% 80% at 50% 40%, black 20%, transparent 100%);
    }

    /* ═══════════════════════
       SHELL
    ═══════════════════════ */
    .shell {
        position:relative; z-index:1;
        width:100%; max-width:720px;
        background: var(--bg-2);
        border:1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow:hidden;
        box-shadow: 0 0 0 1px rgba(255,255,255,0.025) inset, 0 50px 120px rgba(0,0,0,0.75), 0 0 80px rgba(0,229,255,0.03);
        animation: shellIn 0.65s var(--ease) both;
    }
    @keyframes shellIn {
        from { opacity:0; transform:translateY(24px) scale(0.97); }
        to   { opacity:1; transform:translateY(0) scale(1); }
    }

    /* Top accent bar */
    .shell::before {
        content:''; display:block;
        height:2px;
        background: linear-gradient(90deg, transparent 5%, var(--cyan) 40%, var(--violet) 70%, transparent 95%);
    }

    /* ═══════════════════════
       HEADER
    ═══════════════════════ */
    .card-head {
        padding: 36px 44px 28px;
        border-bottom:1px solid var(--border);
        display:flex; align-items:flex-start; justify-content:space-between;
        gap:20px; flex-wrap:wrap;
    }

    .brand-row { display:flex; align-items:center; gap:14px; }
    .brand-icon {
        width:46px; height:46px; border-radius:13px;
        background:var(--cyan-dim); border:1px solid var(--border-c);
        display:flex; align-items:center; justify-content:center;
        color:var(--cyan); font-size:19px;
        box-shadow:0 0 18px rgba(0,229,255,0.1);
    }
    .brand-text-name {
        font-family:var(--font-d); font-size:20px; font-weight:800;
        letter-spacing:-0.02em; color:var(--text-1); line-height:1;
    }
    .brand-text-name span { color:var(--cyan); }
    .brand-text-sub {
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        letter-spacing:0.1em; text-transform:uppercase; margin-top:4px;
    }

    .head-right { text-align:right; }
    .head-title {
        font-family:var(--font-d); font-size:22px; font-weight:800;
        letter-spacing:-0.02em; color:var(--text-1);
    }
    .head-sub { font-size:13px; color:var(--text-2); font-weight:300; margin-top:3px; }

    /* ═══════════════════════
       STEPPER
    ═══════════════════════ */
    .stepper {
        display:flex; align-items:center;
        padding:20px 44px;
        border-bottom:1px solid var(--border);
        gap:0;
    }
    .step-item {
        display:flex; align-items:center; gap:10px; flex:1;
        position:relative;
    }
    .step-item:not(:last-child)::after {
        content:'';
        flex:1; height:1px;
        background: var(--border-2);
        margin:0 12px;
    }
    .step-item.done::after { background:var(--cyan); opacity:0.4; }

    .step-num {
        width:28px; height:28px; border-radius:50%; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        font-family:var(--font-m); font-size:11px; font-weight:500;
        border:1px solid var(--border-2);
        background:var(--bg-4); color:var(--text-3);
        transition:all 0.3s;
    }
    .step-item.active .step-num {
        background:var(--cyan-dim); border-color:var(--border-c);
        color:var(--cyan); box-shadow:0 0 12px rgba(0,229,255,0.15);
    }
    .step-item.done .step-num {
        background:var(--green-dim); border-color:rgba(0,230,118,0.3);
        color:var(--green);
    }
    .step-label {
        font-family:var(--font-m); font-size:10px; font-weight:500;
        letter-spacing:0.08em; text-transform:uppercase;
        color:var(--text-3);
        white-space:nowrap;
    }
    .step-item.active .step-label { color:var(--cyan); }
    .step-item.done .step-label   { color:var(--green); }

    @media(max-width:560px) {
        .step-label { display:none; }
        .stepper { padding:14px 20px; }
    }

    /* ═══════════════════════
       BODY
    ═══════════════════════ */
    .card-body { padding:36px 44px 44px; }

    @media(max-width:560px) {
        .card-head { padding:24px 22px 20px; }
        .card-body { padding:24px 22px 32px; }
        .head-right { display:none; }
    }

    /* ═══════════════════════
       ALERTS
    ═══════════════════════ */
    .alert {
        display:flex; align-items:flex-start; gap:11px;
        padding:13px 15px; border-radius:10px;
        font-size:13px; margin-bottom:26px;
        animation: aIn 0.4s var(--ease);
    }
    @keyframes aIn {
        from { opacity:0; transform:translateY(-6px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .alert i { font-size:14px; flex-shrink:0; margin-top:1px; }
    .alert ul { margin:6px 0 0 14px; padding:0; }
    .alert ul li { margin-bottom:3px; font-size:12px; }

    .alert-error {
        background:var(--red-dim); border:1px solid rgba(255,79,79,0.22); color:#f09090;
    }
    .alert-success {
        background:var(--green-dim); border:1px solid rgba(0,230,118,0.22); color:#7dcf9f;
    }

    /* ═══════════════════════
       SECTION LABELS
    ═══════════════════════ */
    .section-label {
        display:flex; align-items:center; gap:10px;
        font-family:var(--font-m); font-size:10px; font-weight:500;
        letter-spacing:0.12em; text-transform:uppercase;
        color:var(--text-3);
        margin-bottom:18px; margin-top:28px;
        padding-bottom:10px;
        border-bottom:1px solid var(--border);
    }
    .section-label:first-of-type { margin-top:0; }
    .section-label i { color:var(--cyan); font-size:12px; }

    /* ═══════════════════════
       GRID
    ═══════════════════════ */
    .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media(max-width:540px) { .row-2 { grid-template-columns:1fr; } }

    /* ═══════════════════════
       FIELDS
    ═══════════════════════ */
    .field { margin-bottom:16px; }
    .field-label {
        display:flex; align-items:center; gap:6px;
        font-family:var(--font-m); font-size:10px; font-weight:500;
        letter-spacing:0.1em; text-transform:uppercase;
        color:var(--text-3); margin-bottom:8px;
    }
    .field-label .req { color:var(--red); font-size:12px; line-height:1; }

    .input-wrap { position:relative; }
    .input-icon {
        position:absolute; left:14px; top:50%; transform:translateY(-50%);
        font-size:13px; color:var(--text-3); pointer-events:none;
        transition:color 0.3s;
    }
    .input-wrap:focus-within .input-icon { color:var(--cyan); }

    .field-input {
        width:100%;
        padding:13px 14px 13px 40px;
        background:var(--bg-4); border:1px solid var(--border);
        border-radius:var(--radius);
        color:var(--text-1); font-family:var(--font-b);
        font-size:14px; font-weight:300; outline:none;
        transition:all 0.3s ease;
    }
    .field-input::placeholder { color:var(--text-3); }
    .field-input:focus {
        border-color:var(--border-c); background:var(--bg-5);
        box-shadow:0 0 0 3px rgba(0,229,255,0.07);
    }
    .field-input.valid   { border-color:rgba(0,230,118,0.4); }
    .field-input.invalid { border-color:rgba(255,79,79,0.4); }

    .eye-btn {
        position:absolute; right:12px; top:50%; transform:translateY(-50%);
        background:none; border:none; color:var(--text-3); font-size:13px;
        cursor:pointer; padding:4px; transition:color 0.3s; display:flex;
    }
    .eye-btn:hover { color:var(--cyan); }

    .field-hint {
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        margin-top:6px;
    }

    /* ═══════════════════════
       PASSWORD STRENGTH
    ═══════════════════════ */
    .strength-wrap { margin-top:8px; }
    .strength-segs {
        display:flex; gap:4px; margin-bottom:5px;
    }
    .seg {
        flex:1; height:3px; border-radius:2px;
        background:var(--bg-5);
        transition:background 0.35s ease;
    }
    .seg.weak   { background:var(--red); }
    .seg.fair   { background:var(--amber); }
    .seg.good   { background:#80d080; }
    .seg.strong { background:var(--green); }

    .strength-text {
        font-family:var(--font-m); font-size:10px; min-height:14px;
        transition:color 0.3s;
    }
    .strength-text.weak   { color:var(--red); }
    .strength-text.fair   { color:var(--amber); }
    .strength-text.good   { color:#80d080; }
    .strength-text.strong { color:var(--green); }

    /* ═══════════════════════
       MATCH MSG
    ═══════════════════════ */
    .match-msg {
        font-family:var(--font-m); font-size:10px;
        margin-top:6px; min-height:14px;
        display:flex; align-items:center; gap:5px;
    }
    .match-msg.match   { color:var(--green); }
    .match-msg.nomatch { color:var(--red); }

    /* ═══════════════════════
       ROLE SELECTOR
    ═══════════════════════ */
    .role-grid {
        display:grid; grid-template-columns:repeat(3,1fr); gap:10px;
        margin-bottom:4px;
    }
    @media(max-width:420px) { .role-grid { grid-template-columns:1fr; } }

    .role-card {
        background:var(--bg-4); border:1px solid var(--border);
        border-radius:var(--radius); padding:14px 12px;
        cursor:pointer; transition:all 0.25s ease;
        display:flex; flex-direction:column; align-items:center; gap:8px;
        text-align:center;
        position:relative; overflow:hidden;
    }
    .role-card:hover { border-color:var(--border-2); background:var(--bg-5); }
    .role-card.selected {
        background:var(--cyan-dim); border-color:var(--border-c);
        box-shadow:0 0 16px rgba(0,229,255,0.08);
    }
    .role-card input[type="radio"] {
        position:absolute; opacity:0; pointer-events:none;
    }
    .role-icon {
        width:32px; height:32px; border-radius:9px;
        display:flex; align-items:center; justify-content:center;
        font-size:13px; transition:all 0.25s;
    }
    .ri-cust  { background:var(--cyan-dim);   color:var(--cyan);  border:1px solid rgba(0,229,255,0.2); }
    .ri-mgr   { background:var(--amber-dim);  color:var(--amber); border:1px solid rgba(255,179,0,0.2); }
    .ri-admin { background:var(--violet-dim); color:var(--violet);border:1px solid rgba(124,77,255,0.2); }

    .role-name {
        font-family:var(--font-m); font-size:10px; font-weight:500;
        letter-spacing:0.08em; text-transform:uppercase;
        color:var(--text-2); transition:color 0.25s;
    }
    .role-card.selected .role-name { color:var(--cyan); }

    /* extra role fields */
    .extra-field {
        overflow:hidden; max-height:0; opacity:0;
        transition:max-height 0.4s var(--ease), opacity 0.4s ease, margin 0.4s ease;
        margin-top:0;
    }
    .extra-field.visible {
        max-height:120px; opacity:1; margin-top:16px;
    }

    /* ═══════════════════════
       CAPTCHA
    ═══════════════════════ */
    .captcha-wrap {
        display:flex; align-items:center; gap:12px;
    }
    .captcha-box {
        background:var(--bg-4); border:1px solid var(--border);
        border-radius:var(--radius); padding:12px 20px;
        font-family:var(--font-m); font-size:16px; font-weight:500;
        color:var(--cyan); white-space:nowrap; flex-shrink:0;
        letter-spacing:0.08em;
        box-shadow:inset 0 0 0 1px rgba(0,229,255,0.05);
    }
    .captcha-wrap .field-input { max-width:100px; text-align:center; }

    /* ═══════════════════════
       TERMS CHECKBOX
    ═══════════════════════ */
    .terms-row {
        display:flex; align-items:flex-start; gap:11px;
        padding:14px 16px; border-radius:var(--radius);
        background:var(--bg-4); border:1px solid var(--border);
        margin-top:6px;
    }
    .custom-check {
        width:17px; height:17px; flex-shrink:0; margin-top:1px;
        border:1px solid var(--border-2); border-radius:5px;
        background:var(--bg-5); appearance:none; cursor:pointer;
        transition:all 0.2s; position:relative;
    }
    .custom-check:checked {
        background:var(--cyan); border-color:var(--cyan);
    }
    .custom-check:checked::after {
        content:'\f00c'; font-family:'Font Awesome 6 Free'; font-weight:900;
        font-size:9px; color:#020a0c;
        position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    }
    .terms-text {
        font-size:13px; color:var(--text-2); line-height:1.55; font-weight:300;
    }
    .terms-text a { color:var(--cyan); text-decoration:none; }
    .terms-text a:hover { text-decoration:underline; }

    /* ═══════════════════════
       SECURITY STRIP
    ═══════════════════════ */
    .security-strip {
        display:grid; grid-template-columns:repeat(2,1fr); gap:8px;
        margin-top:22px;
    }
    .sec-item {
        display:flex; align-items:center; gap:8px;
        font-family:var(--font-m); font-size:10px; color:var(--text-3);
        padding:9px 12px; border-radius:9px;
        background:var(--bg-4); border:1px solid var(--border);
    }
    .sec-item i { color:var(--green); font-size:11px; }

    /* ═══════════════════════
       SUBMIT
    ═══════════════════════ */
    .btn-submit {
        width:100%; padding:15px 0;
        background:var(--cyan); color:#020a0c;
        border:none; border-radius:var(--radius);
        font-family:var(--font-d); font-size:13px; font-weight:700;
        letter-spacing:0.07em; text-transform:uppercase;
        cursor:pointer; position:relative; overflow:hidden;
        display:flex; align-items:center; justify-content:center; gap:9px;
        transition:all 0.3s ease; margin-top:22px;
    }
    .btn-submit::before {
        content:''; position:absolute; inset:0;
        background:linear-gradient(135deg,rgba(255,255,255,0.14),transparent 50%);
        opacity:0; transition:opacity 0.3s;
    }
    .btn-submit:hover::before { opacity:1; }
    .btn-submit:hover {
        transform:translateY(-2px);
        box-shadow:0 10px 30px rgba(0,229,255,0.28);
        background:#1aecff;
    }
    .btn-submit:active { transform:translateY(0); box-shadow:none; }
    .btn-submit:disabled { opacity:0.4; cursor:not-allowed; transform:none; box-shadow:none; }

    .ripple {
        position:absolute; border-radius:50%;
        background:rgba(0,0,0,0.15);
        transform:scale(0); animation:ripple 0.55s linear;
        pointer-events:none;
    }
    @keyframes ripple { to { transform:scale(4); opacity:0; } }

    .spin {
        width:14px; height:14px; border-radius:50%;
        border:2px solid rgba(0,0,0,0.2); border-top-color:#020a0c;
        animation:spin 0.7s linear infinite; display:none;
    }
    @keyframes spin { to { transform:rotate(360deg); } }
    .btn-submit.loading .spin  { display:block; }
    .btn-submit.loading .blbl  { display:none; }

    /* login link */
    .login-link-row {
        text-align:center; margin-top:20px;
        font-size:13px; color:var(--text-2);
    }
    .login-link-row a {
        color:var(--cyan); text-decoration:none;
        font-weight:500; transition:opacity 0.2s;
    }
    .login-link-row a:hover { opacity:0.8; }

    /* field stagger */
    .field, .section-label, .role-grid, .captcha-wrap, .terms-row, .security-strip {
        animation:fIn 0.5s var(--ease) both;
    }
    .section-label:nth-of-type(1)  { animation-delay:0.05s; }
    .section-label:nth-of-type(2)  { animation-delay:0.1s; }
    .section-label:nth-of-type(3)  { animation-delay:0.15s; }

    @keyframes fIn {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }

    /* modal-like overlay for terms */
    .modal-bg {
        position:fixed; inset:0; z-index:900;
        background:rgba(0,0,0,0.75); backdrop-filter:blur(6px);
        display:flex; align-items:center; justify-content:center;
        padding:20px; opacity:0; pointer-events:none;
        transition:opacity 0.3s ease;
    }
    .modal-bg.open { opacity:1; pointer-events:all; }

    .modal-box {
        background:var(--bg-3); border:1px solid var(--border-2);
        border-radius:var(--radius-lg); padding:32px;
        max-width:480px; width:100%;
        box-shadow:0 40px 80px rgba(0,0,0,0.7);
        transform:translateY(14px) scale(0.97);
        transition:transform 0.3s var(--ease);
    }
    .modal-bg.open .modal-box { transform:translateY(0) scale(1); }
    .modal-title {
        font-family:var(--font-d); font-size:18px; font-weight:800;
        color:var(--text-1); margin-bottom:12px; letter-spacing:-0.01em;
    }
    .modal-content {
        font-size:13px; color:var(--text-2); line-height:1.65;
        max-height:260px; overflow-y:auto;
    }
    .modal-close {
        margin-top:20px; width:100%; padding:11px;
        background:var(--bg-4); border:1px solid var(--border-2);
        border-radius:var(--radius); color:var(--text-1);
        font-family:var(--font-m); font-size:12px; letter-spacing:0.08em;
        text-transform:uppercase; cursor:pointer; transition:all 0.2s;
    }
    .modal-close:hover { background:var(--bg-5); border-color:var(--border-c); color:var(--cyan); }
    </style>
</head>
<body>

<div class="amb amb-1"></div>
<div class="amb amb-2"></div>
<div class="grid-bg"></div>

<div class="shell">

    <!-- Header -->
    <div class="card-head">
        <div class="brand-row">
            <div class="brand-icon"><i class="fas fa-chess-board"></i></div>
            <div>
                <div class="brand-text-name">Game<span>Theory</span></div>
                <div class="brand-text-sub">Pricing Platform</div>
            </div>
        </div>
        <div class="head-right">
            <div class="head-title">Create Account</div>
            <div class="head-sub">Join the market competition</div>
        </div>
    </div>

    <!-- Stepper -->
    <div class="stepper">
        <div class="step-item active" id="step1">
            <div class="step-num">1</div>
            <span class="step-label">Account Info</span>
        </div>
        <div class="step-item" id="step2">
            <div class="step-num">2</div>
            <span class="step-label">Security</span>
        </div>
        <div class="step-item" id="step3">
            <div class="step-num">3</div>
            <span class="step-label">Verify</span>
        </div>
    </div>

    <!-- Body -->
    <div class="card-body">

        <?php if (!$registration_open): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <span>Registration is currently closed. Please check back later.</span>
        </div>
        <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <div>
                <strong style="font-family:var(--font-m);font-size:11px;letter-spacing:0.08em;text-transform:uppercase;">
                    Please fix the following issues:
                </strong>
                <ul>
                    <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="regForm" onsubmit="return beforeSubmit(this)">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <!-- ── Personal Info ── -->
            <div class="section-label">
                <i class="fas fa-user-circle"></i> Personal Information
            </div>

            <div class="row-2">
                <div class="field">
                    <label class="field-label">Full Name <span class="req">*</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="name" class="field-input"
                               placeholder="Your full name"
                               value="<?= htmlspecialchars($formData['name'] ?? '') ?>"
                               minlength="2" maxlength="100" required autofocus>
                    </div>
                    <div class="field-hint">As it appears on your ID</div>
                </div>
                <div class="field">
                    <label class="field-label">Email Address <span class="req">*</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" class="field-input"
                               placeholder="you@example.com"
                               value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                               autocomplete="email" required>
                    </div>
                </div>
            </div>

            <!-- ── Security ── -->
            <div class="section-label">
                <i class="fas fa-lock"></i> Security Details
            </div>

            <div class="row-2">
                <div class="field">
                    <label class="field-label">Password <span class="req">*</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="pw" name="password" class="field-input"
                               placeholder="Min. 8 characters"
                               oninput="checkStrength()" autocomplete="new-password" required>
                        <button type="button" class="eye-btn" onclick="toggleEye('pw','eyePw')" tabindex="-1">
                            <i class="fas fa-eye-slash" id="eyePw"></i>
                        </button>
                    </div>
                    <div class="strength-wrap">
                        <div class="strength-segs">
                            <div class="seg" id="s1"></div>
                            <div class="seg" id="s2"></div>
                            <div class="seg" id="s3"></div>
                            <div class="seg" id="s4"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Confirm Password <span class="req">*</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="pwc" name="confirm_password" class="field-input"
                               placeholder="Repeat password"
                               oninput="checkMatch()" autocomplete="new-password" required>
                        <button type="button" class="eye-btn" onclick="toggleEye('pwc','eyyCf')" tabindex="-1">
                            <i class="fas fa-eye-slash" id="eyyCf"></i>
                        </button>
                    </div>
                    <div class="match-msg" id="matchMsg"></div>
                </div>
            </div>

            <!-- ── Account Type ── -->
            <div class="section-label">
                <i class="fas fa-id-badge"></i> Account Type
            </div>

            <div class="role-grid" id="roleGrid">
                <label class="role-card selected" data-role="customer">
                    <input type="radio" name="role" value="customer"
                           <?= ($formData['role'] ?? 'customer') === 'customer' ? 'checked' : '' ?>>
                    <div class="role-icon ri-cust"><i class="fas fa-shopping-bag"></i></div>
                    <div class="role-name">Customer</div>
                </label>
                <label class="role-card" data-role="manager">
                    <input type="radio" name="role" value="manager"
                           <?= ($formData['role'] ?? '') === 'manager' ? 'checked' : '' ?>>
                    <div class="role-icon ri-mgr"><i class="fas fa-building"></i></div>
                    <div class="role-name">Company Mgr</div>
                </label>
                <label class="role-card" data-role="admin">
                    <input type="radio" name="role" value="admin"
                           <?= ($formData['role'] ?? '') === 'admin' ? 'checked' : '' ?>>
                    <div class="role-icon ri-admin"><i class="fas fa-user-shield"></i></div>
                    <div class="role-name">Admin</div>
                </label>
            </div>

            <!-- Manager code -->
            <div class="extra-field <?= ($formData['role'] ?? '') === 'manager' ? 'visible' : '' ?>" id="managerField">
                <div class="field">
                    <label class="field-label"><i class="fas fa-key" style="color:var(--amber)"></i> &nbsp;Company Registration Code</label>
                    <div class="input-wrap">
                        <i class="fas fa-building input-icon"></i>
                        <input type="text" name="company_code" class="field-input"
                               placeholder="6-character code" maxlength="6">
                    </div>
                    <div class="field-hint">Provided by your company administrator</div>
                </div>
            </div>

            <!-- Admin key -->
            <div class="extra-field <?= ($formData['role'] ?? '') === 'admin' ? 'visible' : '' ?>" id="adminField">
                <div class="field">
                    <label class="field-label"><i class="fas fa-user-secret" style="color:var(--violet)"></i> &nbsp;Admin Registration Key</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="admin_key" class="field-input"
                               placeholder="Enter admin registration key">
                    </div>
                </div>
            </div>

            <!-- ── Verify ── -->
            <div class="section-label">
                <i class="fas fa-robot"></i> Human Verification
            </div>

            <div class="field">
                <label class="field-label">Solve to continue <span class="req">*</span></label>
                <div class="captcha-wrap">
                    <div class="captcha-box"><?= $c1 ?> + <?= $c2 ?> = ?</div>
                    <div class="input-wrap" style="flex:1">
                        <i class="fas fa-calculator input-icon"></i>
                        <input type="number" name="captcha" class="field-input"
                               placeholder="Answer" min="0" max="20" required>
                    </div>
                </div>
            </div>

            <!-- Terms -->
            <div class="section-label">
                <i class="fas fa-file-contract"></i> Agreement
            </div>

            <div class="terms-row">
                <input type="checkbox" class="custom-check" name="terms" id="terms" required>
                <label class="terms-text" for="terms">
                    I agree to the
                    <a href="#" onclick="openModal('termsModal'); return false;">Terms &amp; Conditions</a>
                    and the
                    <a href="#" onclick="openModal('privacyModal'); return false;">Privacy Policy</a>
                    of the GameTheory Pricing Platform.
                </label>
            </div>

            <!-- Security strip -->
            <div class="security-strip">
                <div class="sec-item"><i class="fas fa-shield-halved"></i> 256-bit SSL Encryption</div>
                <div class="sec-item"><i class="fas fa-lock"></i> Bcrypt Password Hashing</div>
                <div class="sec-item"><i class="fas fa-user-shield"></i> Brute Force Protection</div>
                <div class="sec-item"><i class="fas fa-list-check"></i> Activity Logging</div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <div class="spin"></div>
                <span class="blbl"><i class="fas fa-user-plus"></i> &nbsp;Create Account</span>
            </button>

            <div class="login-link-row">
                Already have an account? <a href="login.php">Sign in here</a>
            </div>

        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Terms Modal -->
<div class="modal-bg" id="termsModal">
    <div class="modal-box">
        <div class="modal-title">Terms &amp; Conditions</div>
        <div class="modal-content">
            <strong>GameTheory Pricing Platform Agreement</strong><br><br>
            This platform simulates real market competition using dynamic pricing algorithms. By creating an account you agree to participate in competitive market simulations, not to manipulate pricing data in bad faith, and to use insights solely for educational or professional purposes within the platform.<br><br>
            All data generated on the platform remains property of the platform operator. Access may be revoked for violations of fair-use policies.
        </div>
        <button class="modal-close" onclick="closeModal('termsModal')">Close</button>
    </div>
</div>

<!-- Privacy Modal -->
<div class="modal-bg" id="privacyModal">
    <div class="modal-box">
        <div class="modal-title">Privacy Policy</div>
        <div class="modal-content">
            <strong>Data Protection &amp; Privacy</strong><br><br>
            We collect only the data necessary to provide the service: your name, email, role, and activity logs. Passwords are hashed with bcrypt (cost 12) and never stored in plain text. IP addresses are logged for security purposes only and are not shared with third parties.<br><br>
            You may request deletion of your account and associated data at any time by contacting support.
        </div>
        <button class="modal-close" onclick="closeModal('privacyModal')">Close</button>
    </div>
</div>

<script>
/* ── Eye toggle ── */
function toggleEye(id, iconId) {
    const inp  = document.getElementById(id);
    const icon = document.getElementById(iconId);
    if(inp.type === 'password') { inp.type='text'; icon.className='fas fa-eye'; }
    else { inp.type='password'; icon.className='fas fa-eye-slash'; }
}

/* ── Password strength ── */
function checkStrength() {
    const v    = document.getElementById('pw').value;
    const segs = ['s1','s2','s3','s4'].map(i => document.getElementById(i));
    const txt  = document.getElementById('strengthText');
    segs.forEach(s => { s.className='seg'; });
    txt.className='strength-text'; txt.textContent='';

    if(!v) return;
    let score=0;
    if(v.length>=8) score++;
    if(v.length>=12) score++;
    if(/[A-Z]/.test(v)&&/[a-z]/.test(v)) score++;
    if(/[0-9]/.test(v)) score++;
    if(/[^A-Za-z0-9]/.test(v)) score++;

    const map = [[1,'weak','Weak'],[2,'fair','Fair'],[3,'good','Good'],[5,'strong','Very Strong']];
    let level='weak', label='Weak';
    for(const [min,lv,lb] of map) { if(score>=min){ level=lv; label=lb; } }

    const fill = level==='weak'?1:level==='fair'?2:level==='good'?3:4;
    for(let i=0;i<fill;i++) segs[i].classList.add(level);
    txt.classList.add(level); txt.textContent=label;

    checkMatch();
}

/* ── Match check ── */
function checkMatch() {
    const pw=document.getElementById('pw').value;
    const cf=document.getElementById('pwc').value;
    const msg=document.getElementById('matchMsg');
    const btn=document.getElementById('submitBtn');

    if(!cf){ msg.textContent=''; btn.disabled=false; return; }
    if(pw===cf){
        msg.className='match-msg match';
        msg.innerHTML='<i class="fas fa-check-circle"></i> Passwords match';
        btn.disabled=false;
    } else {
        msg.className='match-msg nomatch';
        msg.innerHTML='<i class="fas fa-times-circle"></i> Passwords do not match';
        btn.disabled=true;
    }
}

/* ── Role card selection ── */
document.querySelectorAll('.role-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        const role = this.dataset.role;
        document.getElementById('managerField').classList.toggle('visible', role==='manager');
        document.getElementById('adminField').classList.toggle('visible', role==='admin');
    });
});

/* ── Stepper progress ── */
const pw = document.getElementById('pw');
const em = document.querySelector('input[name="email"]');
function updateStepper() {
    const s1 = document.getElementById('step1');
    const s2 = document.getElementById('step2');
    const s3 = document.getElementById('step3');
    if(em.value && em.checkValidity()) s1.classList.add('done');
    else s1.classList.remove('done');
    if(pw.value.length>=8) { s2.classList.add('done'); s2.classList.add('active'); }
    else { s2.classList.remove('done'); }
    if(document.querySelector('input[name="captcha"]').value) s3.classList.add('active');
}
document.querySelectorAll('.field-input').forEach(i => i.addEventListener('input', updateStepper));

/* ── Submit guard ── */
function beforeSubmit(form) {
    const p=document.getElementById('pw').value;
    const c=document.getElementById('pwc').value;
    if(p!==c){ return false; }
    if(p.length<8){ return false; }
    if(!document.getElementById('terms').checked){ return false; }
    if(form.dataset.busy) return false;
    form.dataset.busy='1';
    const btn=document.getElementById('submitBtn');
    btn.classList.add('loading'); btn.disabled=true;
    return true;
}

/* ── Ripple ── */
document.getElementById('submitBtn').addEventListener('click', function(e) {
    const r=document.createElement('span');
    const rect=this.getBoundingClientRect();
    const sz=Math.max(rect.width,rect.height);
    r.className='ripple';
    r.style.cssText=`width:${sz}px;height:${sz}px;left:${e.clientX-rect.left-sz/2}px;top:${e.clientY-rect.top-sz/2}px`;
    this.appendChild(r);
    r.addEventListener('animationend',()=>r.remove());
});

/* ── Modals ── */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow='hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow='';
}
document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', function(e) {
        if(e.target===this) closeModal(this.id);
    });
});
document.addEventListener('keydown', e => {
    if(e.key==='Escape') document.querySelectorAll('.modal-bg.open').forEach(m=>closeModal(m.id));
});

/* Restore role selection on error reload */
const currentRole = document.querySelector('input[name="role"]:checked');
if(currentRole) {
    document.querySelector(`.role-card[data-role="${currentRole.value}"]`)?.classList.add('selected');
    document.querySelector(`.role-card:not([data-role="${currentRole.value}"])`)?.classList.remove('selected');
}
</script>
</body>
</html>