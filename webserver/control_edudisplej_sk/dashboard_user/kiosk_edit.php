<?php
/**
 * Kiosk Edit Page
 * For users to edit their assigned kiosks
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$kiosk_id = intval($_GET['id'] ?? 0);
$error = '';
$success = '';

// Get kiosk data
$kiosk = null;
try {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT k.* FROM kiosks k WHERE k.id = ? AND k.company_id = ?");
    $stmt->bind_param("ii", $kiosk_id, $company_id);
    $stmt->execute();
    $kiosk = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$kiosk) {
        header('Location: index.php');
        exit();
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load kiosk data';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kijelző Szerkesztése - EDUDISPLEJ</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .header {
            background: #1a1a1a;
            color: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .header a {
            color: #1e40af;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid #1e40af;
            border-radius: 3px;
        }
        
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-family: inherit;
            font-size: 14px;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #0369a1;
        }
        
        .btn-secondary {
            background: #666;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            background: #444;
        }
        
        .hw-info {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 3px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>🖥️ Kijelző Szerkesztése</h1>
            <a href="index.php">← Vissza</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($kiosk): ?>
            <div class="card">
                <h2><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></h2>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                    <div>
                        <div style="margin-bottom: 15px;">
                            <strong>Státusz:</strong><br>
                            <span style="padding: 4px 8px; border-radius: 3px; background: <?php echo $kiosk['status'] == 'online' ? '#d4edda; color: #28a745;' : '#f8d7da; color: #dc3545;'; ?>">
                                ● <?php echo ucfirst($kiosk['status']); ?>
                            </span>
                        </div>
                        <div>
                            <strong>Hely:</strong><br>
                            <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?>
                        </div>
                    </div>
                    <div>
                        <div style="margin-bottom: 15px;">
                            <strong>Utolsó szinkronizálás:</strong><br>
                            <?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Soha'; ?>
                        </div>
                        <div>
                            <strong>MAC cím:</strong><br>
                            <code><?php echo htmlspecialchars($kiosk['mac']); ?></code>
                        </div>
                    </div>
                </div>
                
                <!-- Hardware Info -->
                <?php if ($kiosk['hw_info']): ?>
                    <div style="margin-bottom: 30px;">
                        <h3>Hardver információk</h3>
                        <div class="hw-info"><?php echo htmlspecialchars(json_encode(json_decode($kiosk['hw_info']), JSON_PRETTY_PRINT)); ?></div>
                    </div>
                <?php endif; ?>
                
                <!-- Edit Form (expandable for future use) -->
                <div style="background: #f9f9f9; padding: 20px; border-radius: 3px;">
                    <h3 style="margin-bottom: 15px;">Megjegyzések</h3>
                    <p style="color: #666; margin-bottom: 15px; font-size: 13px;">
                        Az adminisztrátor által beállított megjegyzések és leírások itt jelennek meg.
                    </p>
                    <div style="background: white; padding: 15px; border-radius: 3px; border-left: 3px solid #1e40af;">
                        <strong>Megjegyzés:</strong><br>
                        <p style="margin-top: 8px; color: #666;">
                            <?php echo htmlspecialchars($kiosk['comment'] ?? 'Nincsenek megjegyzések'); ?>
                        </p>
                    </div>
                </div>
                
                <div style="margin-top: 30px; text-align: center; color: #666; font-size: 13px;">
                    <p>Az alapvető adatok módosításához fordulj az adminisztrátorhoz.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

