<?php
/**
 * Manage Users API
 * Create, update, delete company users
 */

session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id'];

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    $conn = getDbConnection();
    
    // Get user's company_id from database (not session)
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user_row) {
        $response['message'] = 'User not found';
        echo json_encode($response);
        exit();
    }
    
    $company_id = $user_row['company_id'];
    
    if (!$company_id) {
        $response['message'] = 'No company assigned';
        echo json_encode($response);
        exit();
    }
    
    switch ($action) {
        case 'create_user':
        case 'create_company_user':
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $is_admin = intval($_POST['is_admin'] ?? 0);
            
            if (!$username || !$password) {
                $response['message'] = 'Username and password are required';
                break;
            }
            
            // Check if username already exists
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $check_stmt->close();
                $response['message'] = 'Username already exists';
                break;
            }
            $check_stmt->close();
            
            // Hash password
            $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
            
            // Create user
            $insert_stmt = $conn->prepare("INSERT INTO users (username, email, password, company_id, isadmin) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("sssii", $username, $email, $hashed_pwd, $company_id, $is_admin);
            
            if ($insert_stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'User created successfully';
                $response['user_id'] = $conn->insert_id;
            } else {
                $response['message'] = 'Failed to create user';
            }
            $insert_stmt->close();
            break;
            
        case 'update_user':
            $target_user_id = intval($_POST['user_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            
            if (!$target_user_id) {
                $response['message'] = 'User ID is required';
                break;
            }
            
            // Verify user belongs to company
            $verify_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
            $verify_stmt->bind_param("ii", $target_user_id, $company_id);
            $verify_stmt->execute();
            if ($verify_stmt->get_result()->num_rows === 0) {
                $verify_stmt->close();
                $response['message'] = 'User not found or access denied';
                break;
            }
            $verify_stmt->close();
            
            if ($password) {
                $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_pwd, $target_user_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $target_user_id);
            }
            
            if ($update_stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'User updated successfully';
            } else {
                $response['message'] = 'Failed to update user';
            }
            $update_stmt->close();
            break;
            
        case 'delete_user':
            $target_user_id = intval($_GET['user_id'] ?? 0);
            
            if (!$target_user_id) {
                $response['message'] = 'User ID is required';
                break;
            }
            
            // Cannot delete self
            if ($target_user_id === $user_id) {
                $response['message'] = 'Cannot delete own user';
                break;
            }
            
            // Verify user belongs to company
            $verify_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND company_id = ?");
            $verify_stmt->bind_param("ii", $target_user_id, $company_id);
            $verify_stmt->execute();
            if ($verify_stmt->get_result()->num_rows === 0) {
                $verify_stmt->close();
                $response['message'] = 'User not found or access denied';
                break;
            }
            $verify_stmt->close();
            
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $target_user_id);
            
            if ($delete_stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'User deleted successfully';
            } else {
                $response['message'] = 'Failed to delete user';
            }
            $delete_stmt->close();
            break;
            
        default:
            $response['message'] = 'Invalid action';
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

