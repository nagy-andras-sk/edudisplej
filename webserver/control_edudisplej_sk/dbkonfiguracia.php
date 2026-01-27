<?php
/**
 * Database Configuration
 * EduDisplej Control Panel
 */

// Database connection parameters
define('DB_HOST', 'localhost');
define('DB_USER', 'edudisplej_sk');
define('DB_PASS', 'Pab)tB/g/PulNs)2');
define('DB_NAME', 'edudisplej');
define('DB_PORT', 3306); // Add this line
define('DB_CHARSET', 'utf8mb4'); // Changed from utf8mb3

/**
 * Get database connection
 * @return mysqli Database connection object
 * @throws Exception if connection fails
 */
function getDbConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        // Try utf8mb4 first, fallback to utf8 if not supported
        if (!$conn->set_charset("utf8mb4")) {
            if (!$conn->set_charset("utf8")) {
                throw new Exception("Cannot set UTF-8 charset");
            }
        }
        
        return $conn;
    } catch (Exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Close database connection
 * @param mysqli $conn Database connection to close
 */
function closeDbConnection($conn) {
    if ($conn) {
        $conn->close();
    }
}
?>