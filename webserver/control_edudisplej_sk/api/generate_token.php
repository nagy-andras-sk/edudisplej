<?php
/**
 * Generate API Token for Company
 * Admin endpoint to generate or regenerate API tokens
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    $response['message'] = t_def('api.generate_token.admin_required', 'Admin access required');
    http_response_code(403);
    echo json_encode($response);
    exit;
}

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $company_id = $input['company_id'] ?? null;
    $action = $input['action'] ?? 'generate'; // generate or regenerate
    
    if (empty($company_id)) {
        $response['message'] = t_def('api.generate_token.company_id_required', 'Company ID is required');
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Check if company exists
    $stmt = $conn->prepare("SELECT id, name, api_token, license_key FROM companies WHERE id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = t_def('api.generate_token.company_not_found', 'Company not found');
        echo json_encode($response);
        exit;
    }
    
    $company = $result->fetch_assoc();
    $stmt->close();
    
    // Check if token already exists and action is generate (not regenerate)
    if (!empty($company['api_token']) && $action === 'generate') {
        $response['success'] = true;
        $response['message'] = t_def('api.generate_token.already_exists', 'Token already exists');
        $response['token'] = $company['api_token'];
        $response['company_name'] = $company['name'];
        echo json_encode($response);
        exit;
    }
    
    // Generate new token
    $new_token = bin2hex(random_bytes(32)); // 64 character hex string
    
    // Generate license key if not exists
    $license_key = null;
    if (empty($company['license_key'])) {
        $license_key = strtoupper(bin2hex(random_bytes(16))); // 32 character uppercase hex string
    }
    
    // Update company with new token and license key
    if ($license_key) {
        $update_stmt = $conn->prepare("UPDATE companies SET api_token = ?, license_key = ?, token_created_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("ssi", $new_token, $license_key, $company_id);
    } else {
        $update_stmt = $conn->prepare("UPDATE companies SET api_token = ?, token_created_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("si", $new_token, $company_id);
    }
    
    if (!$update_stmt->execute()) {
        $response['message'] = t_def('api.generate_token.save_failed', 'Failed to save token') . ': ' . $update_stmt->error;
        echo json_encode($response);
        exit;
    }
    
    $update_stmt->close();
    $conn->close();
    
    $response['success'] = true;
    $response['message'] = $action === 'regenerate'
        ? t_def('api.generate_token.regenerated', 'Token regenerated successfully')
        : t_def('api.generate_token.generated', 'Token generated successfully');
    $response['token'] = $new_token;
    $response['company_name'] = $company['name'];
    $response['install_command'] = "curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token={$new_token}";
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response['message'] = t_def('api.common.error', 'Error') . ': ' . $e->getMessage();
    echo json_encode($response);
}
