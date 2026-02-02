<?php
/**
 * OTP/2FA Setup API
 * Handles OTP setup, verification and disabling
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $conn = getDbConnection();
    
    switch ($action) {
        case 'generate':
            // Generate new OTP secret
            $secret = generate_otp_secret();
            
            // Get user email for QR code
            $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit();
            }
            
            // Store temporary secret (not yet verified)
            $stmt = $conn->prepare("UPDATE users SET otp_secret = ?, otp_enabled = 0, otp_verified = 0 WHERE id = ?");
            $stmt->bind_param("si", $secret, $user_id);
            $stmt->execute();
            $stmt->close();
            
            // Generate QR code data URL for Google Authenticator
            $issuer = 'EduDisplej';
            $account = $user['email'] ?? $user['username'];
            $qr_data = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";
            
            echo json_encode([
                'success' => true,
                'secret' => $secret,
                'qr_data' => $qr_data,
                'message' => 'Scan QR code with authenticator app'
            ]);
            break;
            
        case 'verify':
            // Verify OTP code and enable 2FA
            $code = $_POST['code'] ?? '';
            
            if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
                echo json_encode(['success' => false, 'message' => 'Invalid code format']);
                exit();
            }
            
            // Get user's OTP secret
            $stmt = $conn->prepare("SELECT otp_secret FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || empty($user['otp_secret'])) {
                echo json_encode(['success' => false, 'message' => 'OTP not set up']);
                exit();
            }
            
            // Verify the code
            if (verify_otp_code($user['otp_secret'], $code)) {
                // Enable OTP
                $stmt = $conn->prepare("UPDATE users SET otp_enabled = 1, otp_verified = 1 WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Two-factor authentication enabled'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid code'
                ]);
            }
            break;
            
        case 'disable':
            // Disable OTP (requires password confirmation in production)
            $stmt = $conn->prepare("UPDATE users SET otp_enabled = 0, otp_secret = NULL, otp_verified = 0 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Two-factor authentication disabled'
            ]);
            break;
            
        case 'status':
            // Check OTP status
            $stmt = $conn->prepare("SELECT otp_enabled, otp_verified FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'otp_enabled' => (bool)($user['otp_enabled'] ?? false),
                'otp_verified' => (bool)($user['otp_verified'] ?? false)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
