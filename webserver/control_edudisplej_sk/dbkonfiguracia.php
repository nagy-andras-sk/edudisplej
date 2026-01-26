<?php
/**
 * Database Configuration
 * EduDisplej Control Panel
 */

// Database connection parameters
define('DB_HOST', 'localhost');
define('DB_USER', 'edudisplej_sk');
define('DB_PASS', 'Pab)tB/g/PulNs)2');
define('DB_NAME', 'edudisplej_sk');
define('DB_CHARSET', 'utf8mb3');

/**
 * Get database connection
 * @return mysqli Database connection object
 * @throws Exception if connection fails
 */
function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        throw new Exception("Database connection failed");
    }
    
    $conn->set_charset(DB_CHARSET);
    
    return $conn;
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
