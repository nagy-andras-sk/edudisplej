<?php
/**
 * Centralized Login Portal
 * EduDisplej Control Panel
 * Routes users to admin or dashboard based on role
 */

session_start();
require_once 'dbkonfiguracia.php';
require_once 'logging.php';
require_once 'i18n.php';
require_once 'auth_roles.php';

$current_lang = edudisplej_apply_language_preferences();

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
    } elseif (edudisplej_get_session_role() === 'easy_user') {
        header('Location: dashboard/easy_user.php');
    } else {
        header('Location: dashboard/index.php');
    }
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $otp_code = trim($_POST['otp_code'] ?? '');
    $remember = isset($_POST['remember']) ? true : false;
    
    if (empty($email) || empty($password)) {
        $error = t('login.error.required');
    } else {
        try {
            $conn = getDbConnection();
            edudisplej_ensure_user_role_column($conn);
            
            // Get user with OTP settings
            $stmt = $conn->prepare("SELECT id, username, email, password, isadmin, user_role, company_id, otp_enabled, otp_secret, otp_verified, lang FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
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
                                'email' => $email
                            ]));
                            $_SESSION['otp_pending_token'] = $temp_token;
                            $_SESSION['otp_pending'] = true;
                            $error = t('login.otp_required');
                        } else {
                            // Verify we have a pending token
                            if (!isset($_SESSION['otp_pending_token'])) {
                                $error = t('login.error.auth_state');
                            } else {
                                // Decrypt and verify pending token
                                require_once 'security_config.php';
                                $token_data = json_decode(decrypt_data($_SESSION['otp_pending_token']), true);
                                
                                // Verify token is recent (within 5 minutes) and matches email
                                if (!$token_data || 
                                    $token_data['user_id'] != $user['id'] || 
                                    $token_data['email'] !== $email ||
                                    (time() - $token_data['timestamp']) > 300) {
                                    $error = t('login.error.session_expired');
                                    unset($_SESSION['otp_pending_token']);
                                    unset($_SESSION['otp_pending']);
                                } else {
                                    // Verify OTP code
                                    require_once 'api/auth.php';
                                    $otp_ok = verify_otp_code($user['otp_secret'], $otp_code);

                                    // Backup code fallback if TOTP fails
                                    if (!$otp_ok && !empty($user['backup_codes'])) {
                                        $stored_hashes = json_decode($user['backup_codes'], true) ?? [];
                                        if (verify_backup_code($otp_code, $stored_hashes)) {
                                            // Remove used backup code
                                            $used_hash = hash_backup_code($otp_code);
                                            $remaining = array_values(array_filter($stored_hashes, fn($h) => !hash_equals($h, $used_hash)));
                                            $new_json  = json_encode($remaining);
                                            $upd = $conn->prepare("UPDATE users SET backup_codes = ? WHERE id = ?");
                                            $upd->bind_param("si", $new_json, $user['id']);
                                            $upd->execute();
                                            $upd->close();
                                            $otp_ok = true;
                                        }
                                    }

                                    if ($otp_ok) {
                                        // OTP verified, complete login
                                        unset($_SESSION['otp_pending']);
                                        unset($_SESSION['otp_pending_token']);
                                        
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['username'] = $user['username'];
                                        $_SESSION['isadmin'] = (bool)$user['isadmin'];
                                        $_SESSION['user_role'] = edudisplej_normalize_user_role($user['user_role'] ?? null, (bool)$user['isadmin']);
                                        $_SESSION['company_id'] = $user['company_id'];
                                        edudisplej_set_lang($user['lang'] ?? EDUDISPLEJ_DEFAULT_LANG, false);
                                
                                        // Remember me functionality (optional)
                                        if ($remember) {
                                            setcookie('edudisplej_user', $user['email'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                                        }
                                        
                                        // Update last login
                                        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                                        $update_stmt->bind_param("i", $user['id']);
                                        $update_stmt->execute();
                                        $update_stmt->close();
                                        
                                        // Log successful login with OTP
                                        $log_username = $user['username'] ?: ($user['email'] ?? $email);
                                        log_security_event('successful_login', $user['id'], $log_username, get_client_ip(), get_user_agent(), ['method' => 'otp']);
                                        
                                        // Redirect based on role
                                        if ($user['isadmin']) {
                                            header('Location: admin/dashboard.php');
                                        } elseif (edudisplej_normalize_user_role($user['user_role'] ?? null, false) === 'easy_user') {
                                            header('Location: dashboard/easy_user.php');
                                        } else {
                                            header('Location: dashboard/index.php');
                                        }
                                        exit();
                                    } else {
                                        $error = t('login.error.invalid_otp');
                                        $log_username = $user['username'] ?: ($user['email'] ?? $email);
                                        log_security_event('failed_otp', $user['id'], $log_username, get_client_ip(), get_user_agent(), ['reason' => 'invalid_otp_code']);
                                    }
                                }
                            }
                        }
                    } else {
                        // No OTP required, complete login
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['isadmin'] = (bool)$user['isadmin'];
                        $_SESSION['user_role'] = edudisplej_normalize_user_role($user['user_role'] ?? null, (bool)$user['isadmin']);
                        $_SESSION['company_id'] = $user['company_id'];
                        edudisplej_set_lang($user['lang'] ?? EDUDISPLEJ_DEFAULT_LANG, false);
                        
                        // Remember me functionality (optional)
                        if ($remember) {
                            setcookie('edudisplej_user', $user['email'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                        }
                        
                        // Update last login
                        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $update_stmt->bind_param("i", $user['id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        // Log successful login
                        $log_username = $user['username'] ?: ($user['email'] ?? $email);
                        log_security_event('successful_login', $user['id'], $log_username, get_client_ip(), get_user_agent(), ['method' => 'password']);
                        
                        // Redirect based on role
                        if ($user['isadmin']) {
                            header('Location: admin/dashboard.php');
                        } elseif (edudisplej_normalize_user_role($user['user_role'] ?? null, false) === 'easy_user') {
                            header('Location: dashboard/easy_user.php');
                        } else {
                            header('Location: dashboard/index.php');
                        }
                        exit();
                    }
                } else {
                    $error = t('login.invalid');
                    // Log failed login attempt
                    $log_username = $user['username'] ?: ($user['email'] ?? $email);
                    log_security_event('failed_login', null, $log_username, get_client_ip(), get_user_agent(), ['reason' => 'invalid_password']);
                }
            } else {
                $error = t('login.invalid');
                // Log failed login attempt
                log_security_event('failed_login', null, $email, get_client_ip(), get_user_agent(), ['reason' => 'user_not_found']);
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = t('login.error.failed');
            error_log('Login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('login.title')); ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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
                    .lang-selector {
                        display: flex;
                        justify-content: flex-end;
                        margin-bottom: 20px;
                        font-size: 12px;
                    }

                    .lang-selector select {
                        padding: 6px 8px;
                        border: 1px solid #d9dde2;
                        border-radius: 4px;
                        font-size: 12px;
                    }
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
        
        .login-header h1 {
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
        
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        input[type="email"]:focus,
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
        <div class="lang-selector">
            <label for="langSelect" style="margin-right: 8px;"><?php echo htmlspecialchars(t('lang.label')); ?></label>
            <select id="langSelect" onchange="changeLanguage(this.value)">
                <option value="hu" <?php echo $current_lang === 'hu' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.hu')); ?></option>
                <option value="en" <?php echo $current_lang === 'en' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.en')); ?></option>
                <option value="sk" <?php echo $current_lang === 'sk' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.sk')); ?></option>
            </select>
        </div>
        <div class="login-header">
            <div class="logo">🖥️</div>
            <h1><?php echo htmlspecialchars(t('app.title')); ?></h1>
            <p><?php echo htmlspecialchars(t('login.subheading')); ?></p>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['registered'])): ?>
            <div class="success">
                ✓ <?php echo htmlspecialchars(t('login.registered')); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" autocomplete="off">
            <div class="form-group">
                <label for="email"><?php echo htmlspecialchars(t('login.email')); ?></label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="<?php echo htmlspecialchars(t('login.email_placeholder')); ?>"
                    required 
                    autofocus
                    autocomplete="email"
                >
            </div>
            
            <div class="form-group">
                <label for="password"><?php echo htmlspecialchars(t('login.password')); ?></label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="<?php echo htmlspecialchars(t('login.password_placeholder')); ?>"
                    required
                    autocomplete="current-password"
                >
            </div>
            
            <?php if (isset($_SESSION['otp_pending']) && $_SESSION['otp_pending']): ?>
            <div class="form-group" style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <label for="otp_code"><?php echo htmlspecialchars(t('login.otp')); ?></label>
                <input 
                    type="text" 
                    id="otp_code" 
                    name="otp_code" 
                    placeholder="<?php echo htmlspecialchars(t('login.otp_placeholder')); ?>"
                    pattern="\d{6}"
                    maxlength="6"
                    required
                    autocomplete="one-time-code"
                    style="text-align: center; letter-spacing: 5px; font-size: 18px;"
                >
                <p style="font-size: 12px; color: #856404; margin-top: 8px;">
                    <?php echo htmlspecialchars(t('login.otp_help')); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="form-group checkbox">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember"><?php echo htmlspecialchars(t('login.remember')); ?></label>
            </div>
            
            <button type="submit" name="login" class="btn-submit"><?php echo htmlspecialchars(t('login.submit')); ?></button>
        </form>
        
        <div class="form-footer">
            <p><a href="password_reset.php" style="color:#1e40af;font-size:13px;">Elfelejtette jelszavát?</a></p>
            <p><?php echo htmlspecialchars(t('login.no_account')); ?></p>
            <a href="userregistration.php"><?php echo htmlspecialchars(t('login.create_account')); ?></a>
        </div>
        
        <div class="info-box">
            <strong><?php echo htmlspecialchars(t('login.info_title')); ?></strong><br>
            • <?php echo htmlspecialchars(t('login.info_admin')); ?><br>
            • <?php echo htmlspecialchars(t('login.info_user')); ?><br>
            • <?php echo htmlspecialchars(t('login.info_last_login')); ?>
        </div>
    </div>
    <script>
        function changeLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>

