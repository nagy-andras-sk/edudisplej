<?php
/**
 * Centralized Login Portal
 * EduDisplej Control Panel
 * Routes users to admin or dashboard based on role
 */

session_start();
require_once 'dbkonfiguracia.php';

$error = '';
$success = '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    // Redirect based on role
    if (isset($_SESSION['isadmin']) && $_SESSION['isadmin']) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: dashboard/index.php');
    }
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $otp_code = trim($_POST['otp_code'] ?? '');
    $remember = isset($_POST['remember']) ? true : false;
    
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
    } else {
        try {
            $conn = getDbConnection();
            
            // Get user with OTP settings
            $stmt = $conn->prepare("SELECT id, username, password, isadmin, company_id, otp_enabled, otp_secret, otp_verified FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Check if OTP is enabled
                    if ($user['otp_enabled'] && $user['otp_verified']) {
                        if (empty($otp_code)) {
                            // OTP required but not provided
                            // Store encrypted temporary token instead of user_id
                            require_once 'security_config.php';
                            $temp_token = encrypt_data(json_encode([
                                'user_id' => $user['id'],
                                'timestamp' => time(),
                                'username' => $username
                            ]));
                            $_SESSION['otp_pending_token'] = $temp_token;
                            $_SESSION['otp_pending'] = true;
                            $error = 'Two-factor authentication code required';
                        } else {
                            // Verify we have a pending token
                            if (!isset($_SESSION['otp_pending_token'])) {
                                $error = 'Invalid authentication state. Please try again.';
                            } else {
                                // Decrypt and verify pending token
                                require_once 'security_config.php';
                                $token_data = json_decode(decrypt_data($_SESSION['otp_pending_token']), true);
                                
                                // Verify token is recent (within 5 minutes) and matches username
                                if (!$token_data || 
                                    $token_data['user_id'] != $user['id'] || 
                                    $token_data['username'] !== $username ||
                                    (time() - $token_data['timestamp']) > 300) {
                                    $error = 'Authentication session expired. Please try again.';
                                    unset($_SESSION['otp_pending_token']);
                                    unset($_SESSION['otp_pending']);
                                } else {
                                    // Verify OTP code
                                    require_once 'api/auth.php';
                                    if (verify_otp_code($user['otp_secret'], $otp_code)) {
                                        // OTP verified, complete login
                                        unset($_SESSION['otp_pending']);
                                        unset($_SESSION['otp_pending_token']);
                                        
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['username'] = $user['username'];
                                        $_SESSION['isadmin'] = (bool)$user['isadmin'];
                                        $_SESSION['company_id'] = $user['company_id'];
                                
                                        // Remember me functionality (optional)
                                        if ($remember) {
                                            setcookie('edudisplej_user', $user['username'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                                        }
                                        
                                        // Update last login
                                        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                                        $update_stmt->bind_param("i", $user['id']);
                                        $update_stmt->execute();
                                        $update_stmt->close();
                                        
                                        // Redirect based on role
                                        if ($user['isadmin']) {
                                            header('Location: admin/dashboard.php');
                                        } else {
                                            header('Location: dashboard/index.php');
                                        }
                                        exit();
                                    } else {
                                        $error = 'Invalid two-factor authentication code';
                                    }
                                }
                            }
                        }
                        }
                    } else {
                        // No OTP required, complete login
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['isadmin'] = (bool)$user['isadmin'];
                        $_SESSION['company_id'] = $user['company_id'];
                        
                        // Remember me functionality (optional)
                        if ($remember) {
                            setcookie('edudisplej_user', $user['username'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                        }
                        
                        // Update last login
                        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $update_stmt->bind_param("i", $user['id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        // Redirect based on role
                        if ($user['isadmin']) {
                            header('Location: admin/dashboard.php');
                        } else {
                            header('Location: dashboard/index.php');
                        }
                        exit();
                    }
                } else {
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Invalid username or password';
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Login failed. Please try again later.';
            error_log('Login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EduDisplej Control</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 50px 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 450px;
            width: 100%;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .login-header .logo {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group.checkbox {
            display: flex;
            align-items: center;
            margin: 15px 0;
        }
        
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin-right: 10px;
            cursor: pointer;
        }
        
        .form-group.checkbox label {
            margin: 0;
            font-weight: 400;
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-submit:hover {
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
            transform: translateY(-2px);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .form-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .form-footer p {
            color: #666;
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        .form-footer a {
            color: #1e40af;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .form-footer a:hover {
            color: #0369a1;
        }
        
        .info-box {
            background: #f0f4ff;
            border: 1px solid #1e40af;
            padding: 15px;
            border-radius: 5px;
            margin-top: 30px;
            font-size: 12px;
            color: #555;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🖥️</div>
            <h1>EduDisplej Control</h1>
            <p>Central Login Portal</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['registered'])): ?>
            <div class="success">
                ✓ Registration successful! Please log in with your credentials.
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" autocomplete="off">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    placeholder="Enter your username"
                    required 
                    autofocus
                    autocomplete="username"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                >
            </div>
            
            <?php if (isset($_SESSION['otp_pending']) && $_SESSION['otp_pending']): ?>
            <div class="form-group" style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <label for="otp_code">Two-Factor Authentication Code</label>
                <input 
                    type="text" 
                    id="otp_code" 
                    name="otp_code" 
                    placeholder="Enter 6-digit code"
                    pattern="\d{6}"
                    maxlength="6"
                    required
                    autocomplete="one-time-code"
                    style="text-align: center; letter-spacing: 5px; font-size: 18px;"
                >
                <p style="font-size: 12px; color: #856404; margin-top: 8px;">
                    Enter the 6-digit code from your authenticator app
                </p>
            </div>
            <?php endif; ?>
            
            <div class="form-group checkbox">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>
            
            <button type="submit" name="login" class="btn-submit">Sign In</button>
        </form>
        
        <div class="form-footer">
            <p>Don't have an account?</p>
            <a href="userregistration.php">Create new account</a>
        </div>
        
        <div class="info-box">
            <strong>Login Information:</strong><br>
            • Admins will be redirected to the Admin Portal<br>
            • Regular users will access the Dashboard<br>
            • Your last login will be recorded
        </div>
    </div>
</body>
</html>

