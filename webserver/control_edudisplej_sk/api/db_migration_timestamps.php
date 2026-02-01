<?php
/**
 * Database Migration - Add timestamps to kiosk_group_modules
 * Run this once to add created_at and updated_at columns
 */

require_once '../dbkonfiguracia.php';

try {
    $conn = getDbConnection();
    
    // Check if columns already exist
    $result = $conn->query("SHOW COLUMNS FROM kiosk_group_modules LIKE 'updated_at'");
    
    if ($result->num_rows == 0) {
        // Add timestamps
        $conn->query("ALTER TABLE kiosk_group_modules 
                      ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                      ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        
        echo "Timestamps added to kiosk_group_modules table successfully.\n";
    } else {
        echo "Timestamps already exist in kiosk_group_modules table.\n";
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log($e->getMessage());
}
?>
