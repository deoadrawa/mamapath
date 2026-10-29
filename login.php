<?php
// ============================================
// FILE: login.php
// PURPOSE: User authentication login page
// ============================================

require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid or expired form token. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both username and password';
    } else {
        $clientIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $throttled = false;
        try {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
            $attempts = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = :u AND ip_address = :ip AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
            $attempts->execute([':u' => mb_substr($username, 0, 50), ':ip' => $clientIp]);
            $throttled = (int) $attempts->fetchColumn() >= 8;
        } catch (PDOException $e) {
            error_log('login throttle unavailable: ' . $e->getMessage());
        }
        $user = $throttled ? false : verifyLogin($pdo, $username, $password);
        if ($throttled) {
            $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } elseif ($user) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_fullname'] = $user['full_name'];
            $_SESSION['user_role'] = normalizeUserRole($user['role']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
            $updateStmt->execute([':id' => $user['id']]);

            header('Location: index.php');
            exit;
        }
        if (!$throttled) {
            $error = 'Invalid username or password';
            try {
                $pdo->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (:u, :ip)")->execute([':u' => mb_substr($username, 0, 50), ':ip' => $clientIp]);
            } catch (PDOException $e) {
                error_log('login throttle unavailable: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lubaga Hospital MCH System</title>
    <link rel="stylesheet" href="assets/vendor/fa/all.min.css?v=2">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; background: #F1F5F9; color: #1E293B; min-height: 100vh; }
        .auth { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); min-height: 100vh; }
        .auth-brand { background: #173c50; color: #fff; padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; gap: 32px; }
        .auth-logo { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 18px; }
        .auth-logo span { width: 44px; height: 44px; border-radius: 8px; background: #1E40AF; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; }
        .auth-brand h1 { font-size: 34px; line-height: 1.2; margin-bottom: 12px; }
        .auth-brand p.lead { color: #CBD5E1; font-size: 16px; line-height: 1.6; max-width: 460px; }
        .auth-points { list-style: none; display: grid; gap: 12px; margin-top: 28px; }
        .auth-points li { display: flex; align-items: center; gap: 12px; font-size: 14px; color: #E2E8F0; }
        .auth-points i { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; }
        .auth-points li:nth-child(1) i { background: #1E40AF; }
        .auth-points li:nth-child(2) i { background: #10B981; }
        .auth-points li:nth-child(3) i { background: #F59E0B; }
        .auth-points li:nth-child(4) i { background: #EC4899; }
        .auth-foot { font-size: 12px; color: #94A3B8; }
        .auth-panel { display: flex; align-items: center; justify-content: center; padding: 32px 24px; }
        .auth-card { width: 100%; max-width: 400px; background: #fff; border: 1px solid #E2E8F0; border-radius: 8px; padding: 32px; }
        .auth-card h2 { font-size: 22px; margin-bottom: 4px; }
        .auth-card .sub { color: #64748B; font-size: 14px; margin-bottom: 24px; }
        .field { margin-bottom: 18px; }
        .field label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #334155; }
        .input { position: relative; }
        .input > i.lead-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: 14px; pointer-events: none; }
        .input input { width: 100%; height: 44px; padding: 0 44px 0 38px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 15px; font-family: inherit; background: #fff; color: #1E293B; }
        .input input:focus { outline: none; border-color: #1E40AF; box-shadow: 0 0 0 3px rgba(30, 64, 175, .15); }
        .toggle { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; background: transparent; color: #64748B; cursor: pointer; border-radius: 6px; font-size: 15px; }
        .toggle:hover { background: #F1F5F9; color: #1E293B; }
        .caps { display: none; margin-top: 6px; font-size: 12px; color: #B45309; }
        .caps.on { display: block; }
        .btn-login { width: 100%; height: 46px; background: #1E40AF; color: #fff; border: 0; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-login:hover { background: #1E3A8A; }
        .btn-login:disabled { opacity: .7; cursor: wait; }
        .error-message { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; padding: 10px 12px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; display: flex; gap: 8px; align-items: flex-start; }
        .secure { margin-top: 18px; text-align: center; font-size: 12px; color: #64748B; }
        .demo-credentials { margin-top: 20px; padding-top: 16px; border-top: 1px solid #E2E8F0; font-size: 12px; color: #64748B; text-align: center; }
        .demo-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-top: 8px; font-size: 11px; }
        @media (max-width: 860px) {
            .auth { grid-template-columns: 1fr; }
            .auth-brand { padding: 24px 20px; gap: 12px; }
            .auth-brand h1 { font-size: 22px; margin-bottom: 4px; }
            .auth-brand p.lead, .auth-points, .auth-foot { display: none; }
            .auth-panel { padding: 20px 16px 32px; align-items: flex-start; }
            .auth-card { padding: 24px 20px; }
        }
    </style>
</head>
<body class="login-page">
<div class="auth">
    <aside class="auth-brand">
        <div class="auth-logo"><span><i class="fas fa-baby-carriage"></i></span> Lubaga Hospital</div>
        <div>
            <h1>Integrated Maternal &amp; Child Health System</h1>
            <p class="lead">One record for every mother and child, from first antenatal visit through delivery, postnatal care and immunisation.</p>
            <ul class="auth-points">
                <li><i class="fas fa-heart-pulse"></i> Antenatal, delivery and postnatal care</li>
                <li><i class="fas fa-syringe"></i> Child health and immunisation follow-up</li>
                <li><i class="fas fa-chart-line"></i> Strategic analytics with trends and comparisons</li>
                <li><i class="fas fa-shield-halved"></i> Role-based access and audit trail</li>
            </ul>
        </div>
        <div class="auth-foot">HMIS compliant &middot; Authorised staff only</div>
    </aside>
    <main class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="sub">Sign in to continue to your dashboard.</p>
            <?php if ($error): ?>
                <div class="error-message" role="alert">
                    <i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <?php echo csrfField(); ?>
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input">
                        <i class="fas fa-user lead-icon"></i>
                        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input">
                        <i class="fas fa-lock lead-icon"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="toggle" id="togglePw" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="caps" id="caps"><i class="fas fa-triangle-exclamation"></i> Caps Lock is on</div>
                </div>
                <button type="submit" class="btn-login" id="loginBtn"><i class="fas fa-right-to-bracket"></i> Sign in</button>
            </form>
            <p class="secure"><i class="fas fa-lock"></i> Secure session. Activity is logged.</p>

            <?php if (APP_SHOW_DEMO_LOGINS): ?>
            <div class="demo-credentials">
                <strong><i class="fas fa-info-circle"></i> Demo credentials (password: Admin123)</strong>
                <div class="demo-grid">
                    <span>admin</span>
                    <span>dr_namukwaya</span>
                    <span>midwife_grace</span>
                    <span>reception_john</span>
                    <span>manager_mary</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
(function () {
    var pw = document.getElementById('password'), t = document.getElementById('togglePw'), caps = document.getElementById('caps');
    t.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        t.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        t.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
    function chk(e) { if (e.getModifierState) { caps.classList.toggle('on', e.getModifierState('CapsLock')); } }
    pw.addEventListener('keydown', chk); pw.addEventListener('keyup', chk);
    pw.addEventListener('blur', function () { caps.classList.remove('on'); });
    document.getElementById('loginForm').addEventListener('submit', function () {
        var b = document.getElementById('loginBtn'); b.disabled = true;
        b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in…';
    });
}());
</script>
</body>
</html>
