<?php
/**
 * Database Migration - Add Technical Info Fields
 * Adds version, screen_resolution, screen_status columns to kiosks table
 */

require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => '',
    'changes' => []
];

try {
    $conn = getDbConnection();
    
    // Check and add version column
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'version'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE kiosks ADD COLUMN version VARCHAR(50) DEFAULT NULL AFTER hw_info";
        if ($conn->query($sql)) {
            $response['changes'][] = "Added 'version' column";
        } else {
            throw new Exception("Failed to add version column: " . $conn->error);
        }
    } else {
        $response['changes'][] = "'version' column already exists";
    }
    
    // Check and add screen_resolution column
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'screen_resolution'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE kiosks ADD COLUMN screen_resolution VARCHAR(50) DEFAULT NULL AFTER version";
        if ($conn->query($sql)) {
            $response['changes'][] = "Added 'screen_resolution' column";
        } else {
            throw new Exception("Failed to add screen_resolution column: " . $conn->error);
        }
    } else {
        $response['changes'][] = "'screen_resolution' column already exists";
    }
    
    // Check and add screen_status column
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'screen_status'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE kiosks ADD COLUMN screen_status VARCHAR(20) DEFAULT NULL AFTER screen_resolution";
        if ($conn->query($sql)) {
            $response['changes'][] = "Added 'screen_status' column";
        } else {
            throw new Exception("Failed to add screen_status column: " . $conn->error);
        }
    } else {
        $response['changes'][] = "'screen_status' column already exists";
    }
    
    // Check and add loop_last_update column
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'loop_last_update'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE kiosks ADD COLUMN loop_last_update DATETIME DEFAULT NULL AFTER screen_status";
        if ($conn->query($sql)) {
            $response['changes'][] = "Added 'loop_last_update' column";
        } else {
            throw new Exception("Failed to add loop_last_update column: " . $conn->error);
        }
    } else {
        $response['changes'][] = "'loop_last_update' column already exists";
    }
    
    // Check and add last_sync column
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'last_sync'");
    if ($check->num_rows == 0) {
        $sql = "ALTER TABLE kiosks ADD COLUMN last_sync DATETIME DEFAULT NULL AFTER loop_last_update";
        if ($conn->query($sql)) {
            $response['changes'][] = "Added 'last_sync' column";
        } else {
            throw new Exception("Failed to add last_sync column: " . $conn->error);
        }
    } else {
        $response['changes'][] = "'last_sync' column already exists";
    }
    
    $response['success'] = true;
    $response['message'] = 'Database migration completed successfully';
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Migration error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
