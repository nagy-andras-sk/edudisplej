<?php
/**
 * Version Check API
 * Returns current versions of all services for auto-update functionality
 */

header('Content-Type: application/json');
require_once 'auth.php';

validate_api_token();

$response = [
    'success' => false,
    'message' => '',
    'versions' => []
];

try {
    // Path to versions file
    $versions_file = '../../install/init/versions.json';
    
    if (!file_exists($versions_file)) {
        $response['message'] = 'Versions file not found';
        echo json_encode($response);
        exit;
    }
    
    $versions_content = file_get_contents($versions_file);
    $versions_data = json_decode($versions_content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Failed to parse versions file: ' . json_last_error_msg();
        echo json_encode($response);
        exit;
    }
    
    $response['success'] = true;
    $response['versions'] = $versions_data['services'] ?? [];
    $response['last_updated'] = $versions_data['last_updated'] ?? '';
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($response);
}
