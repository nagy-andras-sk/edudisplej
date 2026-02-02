<?php
/**
 * Database Migration - Add screenshot_enabled column
 * Run this once to add screenshot feature
 */

require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    $conn = getDbConnection();
    
    // Check if column already exists
    $result = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'screenshot_enabled'");
    
    if ($result->num_rows > 0) {
        $response['message'] = 'Column screenshot_enabled already exists';
        $response['success'] = true;
    } else {
        // Add screenshot_enabled column (default 0 = disabled)
        $sql = "ALTER TABLE kiosks ADD COLUMN screenshot_enabled TINYINT(1) DEFAULT 0 AFTER screenshot_url";
        
        if ($conn->query($sql)) {
            $response['success'] = true;
            $response['message'] = 'Column screenshot_enabled added successfully';
            
            // Log the migration
            error_log('Database migration: screenshot_enabled column added to kiosks table');
        } else {
            $response['message'] = 'Failed to add column: ' . $conn->error;
        }
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('DB Migration error: ' . $e->getMessage());
}

echo json_encode($response);
?>
