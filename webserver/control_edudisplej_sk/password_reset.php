<?php
/**
 * Public Password Reset Page
 * Step 1: Request reset (email form)
 * Step 2: ?token=... new password form
 */

session_start();
require_once 'dbkonfiguracia.php';
require_once 'security_config.php';
require_once 'logging.php';
require_once 'email_helper.php';

$error   = '';
$success = '';
$step    = 'request'; // request | reset | done

$token_param = trim($_GET['token'] ?? '');

if (!empty($token_param)) {
    $step = 'reset';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? 'request';

    if ($form_action === 'request') {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Érvényes email cím szükséges.';
        } else {
            try {
                $conn = getDbConnection();

                // Find user
                $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user) {
                    // Rate limiting: max 3 requests per email per hour
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM password_reset_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND used_at IS NULL");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();

                    if ($cnt >= 3) {
                        $error = 'Túl sok jelszó-visszaállítási kérés. Próbálja újra egy óra múlva.';
                    } else {
                        // Generate token
                        $plain_token = bin2hex(random_bytes(32));
                        $token_hash  = hash('sha256', $plain_token);
                        $expires_at  = date('Y-m-d H:i:s', time() + 3600);
                        $ip          = $_SERVER['REMOTE_ADDR'] ?? '';

                        $stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address) VALUES (?,?,?,?)");
                        $stmt->bind_param("isss", $user['id'], $token_hash, $expires_at, $ip);
                        $stmt->execute();
                        $stmt->close();

                        // Send email
                        $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                            . '/password_reset.php?token=' . urlencode($plain_token);

                        send_email_from_template('password_reset', $email, $user['username'], [
                            'name'       => $user['username'],
                            'reset_link' => $reset_link,
                            'site_name'  => 'EduDisplej',
                        ], 'hu');

                        log_security_event('password_reset_request', $user['id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['email' => $email]);
                    }
                }
                // Always show success to avoid email enumeration
                if (empty($error)) {
                    $success = 'Ha az email cím regisztrált, hamarosan kap egy visszaállítási linket.';
                    $step = 'done';
                }

                closeDbConnection($conn);
            } catch (Exception $e) {
                error_log('password_reset request error: ' . $e->getMessage());
                $error = 'Szerverhiba. Kérjük, próbálja újra.';
            }
        }

    } elseif ($form_action === 'reset') {
        $token_val   = trim($_POST['token'] ?? '');
        $new_pass    = $_POST['new_password']     ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($token_val)) {
            $error = 'Érvénytelen token.';
        } elseif (strlen($new_pass) < 8) {
            $error = 'A jelszónak legalább 8 karakter hosszúnak kell lennie.';
        } elseif ($new_pass !== $confirm_pass) {
            $error = 'A jelszavak nem egyeznek.';
        } else {
            try {
                $conn = getDbConnection();
                $token_hash = hash('sha256', $token_val);

                $stmt = $conn->prepare("SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.username FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE prt.token_hash = ?");
                $stmt->bind_param("s", $token_hash);
                $stmt->execute();
                $token_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$token_row) {
                    $error = 'Érvénytelen vagy lejárt token.';
                } elseif ($token_row['used_at'] !== null) {
                    $error = 'Ez a link már fel lett használva.';
                } elseif (strtotime($token_row['expires_at']) < time()) {
                    $error = 'A link lejárt. Kérjen újat.';
                } else {
                    $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT);
                    $uid = (int)$token_row['user_id'];

                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed_pass, $uid);
                    $stmt->execute();
                    $stmt->close();

                    $used_now = date('Y-m-d H:i:s');
                    $tid = (int)$token_row['id'];
                    $stmt = $conn->prepare("UPDATE password_reset_tokens SET used_at = ? WHERE id = ?");
                    $stmt->bind_param("si", $used_now, $tid);
                    $stmt->execute();
                    $stmt->close();

                    log_security_event('password_reset_success', $uid, $token_row['username'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', []);

                    $success = 'Jelszava sikeresen megváltozott. Most bejelentkezhet.';
                    $step = 'done';
                }

                closeDbConnection($conn);
            } catch (Exception $e) {
                error_log('password_reset apply error: ' . $e->getMessage());
                $error = 'Szerverhiba. Kérjük, próbálja újra.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jelszó visszaállítása – EduDisplej</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 420px;
            width: 100%;
        }
        h1 { font-size: 22px; color: #1e40af; margin-bottom: 8px; }
        p.sub { color: #666; font-size: 13px; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%; padding: 10px 12px;
            border: 1px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        .btn {
            width: 100%; padding: 11px;
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: #fff; border: none; border-radius: 5px;
            font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px;
        }
        .btn:hover { opacity: 0.9; }
        .error { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:10px 14px; border-radius:5px; margin-bottom:16px; font-size:13px; }
        .success { background:#d4edda; border:1px solid #c3e6cb; color:#155724; padding:10px 14px; border-radius:5px; margin-bottom:16px; font-size:13px; }
        .back-link { display:block; text-align:center; margin-top:18px; font-size:13px; color:#1e40af; text-decoration:none; }
        .back-link:hover { text-decoration:underline; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔑 Jelszó visszaállítása</h1>

        <?php if ($step === 'done'): ?>
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <a href="login.php" class="back-link">← Vissza a bejelentkezéshez</a>

        <?php elseif ($step === 'reset'): ?>
            <p class="sub">Adja meg az új jelszavát.</p>
            <?php if ($error): ?>
                <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="password_reset.php">
                <input type="hidden" name="form_action" value="reset">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token_param); ?>">
                <div class="form-group">
                    <label>Új jelszó</label>
                    <input type="password" name="new_password" minlength="8" required>
                </div>
                <div class="form-group">
                    <label>Jelszó megerősítése</label>
                    <input type="password" name="confirm_password" minlength="8" required>
                </div>
                <button type="submit" class="btn">Jelszó megváltoztatása</button>
            </form>
            <a href="login.php" class="back-link">← Vissza a bejelentkezéshez</a>

        <?php else: ?>
            <p class="sub">Adja meg az email címét, és küldünk egy visszaállítási linket.</p>
            <?php if ($error): ?>
                <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <form method="POST" action="password_reset.php">
                <input type="hidden" name="form_action" value="request">
                <div class="form-group">
                    <label>Email cím</label>
                    <input type="email" name="email" required autofocus>
                </div>
                <button type="submit" class="btn">Visszaállítási link küldése</button>
            </form>
            <a href="login.php" class="back-link">← Vissza a bejelentkezéshez</a>
        <?php endif; ?>
    </div>
</body>
</html>
