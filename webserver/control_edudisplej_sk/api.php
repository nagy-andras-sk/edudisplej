<?php
/**
 * API - Redirect/Router to new API structure
 * This file routes old API calls to the new endpoints
 */

header('Content-Type: application/json');

// Get the action parameter
$action = $_GET['action'] ?? '';

// Route to appropriate new endpoint
switch ($action) {
    case 'register':
        // Redirect to registration endpoint
        include 'api/registration.php';
        break;
        
    case 'sync':
        // Redirect to hw_data_sync endpoint
        include 'api/hw_data_sync.php';
        break;
        
    case 'screenshot':
        // Redirect to screenshot sync endpoint
        include 'api/screenshot_sync.php';
        break;
        
    case 'heartbeat':
        // Heartbeat is essentially hw_data_sync
        include 'api/hw_data_sync.php';
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Please use the new API endpoints: /api/registration.php, /api/hw_data_sync.php, /api/screenshot_sync.php, /api/modules_sync.php'
        ]);
        break;
}
?>
