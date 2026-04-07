<?php
/**
 * User Registration
 * EduDisplej Control Panel
 */

session_start();
require_once 'dbkonfiguracia.php';
require_once 'email_helper.php';

$error = '';
$success = '';
$view = 'register';

function is_strong_password(string $password): bool {
    if (strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return false;
    }
    return true;
}

function ensure_user_activation_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_email_activation (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_activation_user (user_id),
        INDEX idx_user_activation_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = trim((string)($_POST['form_action'] ?? 'register'));

    if ($form_action === 'activate') {
        $view = 'activate';
        $email = trim((string)($_POST['email'] ?? ''));
        $code = trim((string)($_POST['activation_code'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            $error = 'Zadajte platny email a 6-miestny aktivacny kod.';
        } else {
            try {
                $conn = getDbConnection();
                ensure_user_activation_schema($conn);

                $stmt = $conn->prepare("SELECT a.id, a.user_id, a.code_hash, a.expires_at, a.verified_at
                                        FROM user_email_activation a
                                        WHERE a.email = ?
                                        ORDER BY a.created_at DESC, a.id DESC
                                        LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    $error = 'Aktivacny kod sa nenasiel.';
                } elseif (!empty($row['verified_at'])) {
                    $success = 'Ucet je uz aktivovany. Mozete sa prihlasit.';
                    $view = 'register';
                } elseif (strtotime((string)$row['expires_at']) < time()) {
                    $error = 'Aktivacny kod vyprsal. Zaregistrujte sa znova pre novy kod.';
                } elseif (!password_verify($code, (string)$row['code_hash'])) {
                    $error = 'Neplatny aktivacny kod.';
                } else {
                    $verifiedAt = date('Y-m-d H:i:s');
                    $aid = (int)$row['id'];
                    $uid = (int)$row['user_id'];

                    $updateActivation = $conn->prepare("UPDATE user_email_activation SET verified_at = ? WHERE id = ?");
                    $updateActivation->bind_param('si', $verifiedAt, $aid);
                    $updateActivation->execute();
                    $updateActivation->close();

                    $cleanupOthers = $conn->prepare("UPDATE user_email_activation SET verified_at = ? WHERE user_id = ? AND verified_at IS NULL");
                    $cleanupOthers->bind_param('si', $verifiedAt, $uid);
                    $cleanupOthers->execute();
                    $cleanupOthers->close();

                    $success = 'Aktivacia uspesna. Teraz sa mozete prihlasit.';
                    $view = 'register';
                }

                closeDbConnection($conn);
            } catch (Exception $e) {
                $error = 'Nastala chyba servera pri aktivacii.';
                error_log($e->getMessage());
            }
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Vsetky polia su povinne.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadajte platnu emailovu adresu.';
        } elseif ($password !== $confirm_password) {
            $error = 'Hesla sa nezhoduju.';
        } elseif (!is_strong_password($password)) {
            $error = 'Heslo musi mat min. 8 znakov, 1 male pismeno, 1 velke pismeno a 1 specialny znak.';
        } else {
        try {
            $conn = getDbConnection();
            ensure_user_activation_schema($conn);
            
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Pouzivatelske meno alebo email uz existuje.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin) VALUES (?, ?, ?, 0)");
                $stmt->bind_param("sss", $username, $email, $hashed_password);
                
                if ($stmt->execute()) {
                    $newUserId = (int)$conn->insert_id;
                    $activationCode = (string)random_int(100000, 999999);
                    $activationHash = password_hash($activationCode, PASSWORD_DEFAULT);
                    $expiresAt = date('Y-m-d H:i:s', time() + 1800);

                    $insertActivation = $conn->prepare("INSERT INTO user_email_activation (user_id, email, code_hash, expires_at) VALUES (?, ?, ?, ?)");
                    $insertActivation->bind_param('isss', $newUserId, $email, $activationHash, $expiresAt);
                    $insertActivation->execute();
                    $insertActivation->close();

                    $subject = 'Aktivacia uctu EduDisplej';
                    $bodyHtml = '<p>Dobrý deň ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>Váš aktivacny kod je: <strong style="font-size:20px;letter-spacing:2px;">' . htmlspecialchars($activationCode, ENT_QUOTES, 'UTF-8') . '</strong></p>'
                        . '<p>Kod je platny 30 minut.</p>';
                    $bodyText = "Dobry den {$username},\n\nVas aktivacny kod je: {$activationCode}\nKod je platny 30 minut.";
                    send_raw_email(['email' => $email, 'name' => $username], $subject, $bodyHtml, $bodyText, 'account_activation');

                    $success = 'Registracia prebehla uspesne. Na email sme poslali aktivacny kod.';
                    $view = 'activate';
                } else {
                    $error = 'Registracia sa nepodarila. Skuste to znova.';
                }
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Nastala chyba databazy.';
            error_log($e->getMessage());
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registracia pouzivatela - EduDisplej</title>
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
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #1e40af;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        
        button:hover {
            opacity: 0.9;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        
        .success {
            background: #efe;
            color: #3c3;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #cfc;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        
        .login-link a {
            color: #1e40af;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Registracia EduDisplej</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($view === 'activate'): ?>
        <form method="POST" action="">
            <input type="hidden" name="form_action" value="activate">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="activation_code">Aktivacny kod (6 cifier)</label>
                <input type="text" id="activation_code" name="activation_code" required pattern="\d{6}" maxlength="6">
            </div>

            <button type="submit">Aktivovat ucet</button>
        </form>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="form_action" value="register">
            <div class="form-group">
                <label for="username">Pouzivatelske meno</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Potvrdenie hesla</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
            </div>
            
            <button type="submit">Registrovat</button>
        </form>
        <?php endif; ?>
        
        <div class="login-link">
            Mate uz ucet? <a href="admin/index.php">Prihlaste sa</a>
        </div>
    </div>
</body>
</html>

