<?php
/**
 * API - Redirect/Router to new API structure
 * This file routes old API calls to the new endpoints.
 *
 * Clients should migrate to the unified v1 endpoint:
 *   POST /api/v1/device/sync.php
 */

header('Content-Type: application/json');
header('X-EDU-Deprecated: true');
header('X-EDU-Successor: /api/v1/device/sync.php');

// Get the action parameter
$action = $_GET['action'] ?? '';

// Route to appropriate new endpoint
switch ($action) {
    case 'register':
        // Redirect to registration endpoint
        include 'api/registration.php';
        break;
        
    case 'sync':
    case 'heartbeat':
        // Route to unified v1 sync endpoint
        include 'api/v1/device/sync.php';
        break;
        
    case 'screenshot':
        // Legacy screenshot – include in the new sync payload when possible
        include 'api/screenshot_sync.php';
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action. Migrate to the new unified endpoint: /api/v1/device/sync.php'
        ]);
        break;
}
?>

