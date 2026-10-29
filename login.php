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
        $user = verifyLogin($pdo, $username, $password);
        if ($user) {
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
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lubaga Hospital MCH System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0f3b5c;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
        }
        
        .login-header {
            background: #0f3b5c;
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .login-header i {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .hospital-badge {
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 12px;
            margin-top: 15px;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #0f3b5c;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: #0f3b5c;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-login:hover {
            background: #1e5a7d;
        }
        
        .error-message {
            background: #fee;
            color: #c00;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c00;
        }
        
        .demo-credentials {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        
        .demo-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 10px;
            font-size: 11px;
        }
    </style>
    <link rel="stylesheet" href="app.css?v=5.6">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-baby-carriage"></i>
            <h1>Lubaga Hospital</h1>
            <p>Integrated Maternal & Child Health System</p>
            <div class="hospital-badge">HMIS Compliant</div>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" name="username" placeholder="Enter your username" required autofocus>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> Login to Dashboard</button>
            </form>
            
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
    </div>
</body>
</html>