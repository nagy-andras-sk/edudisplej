<?php
/**
 * Database Configuration
 * EduDisplej Control Panel
 */

function edudisplej_env_or_default(string $name, string $default): string {
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string)$value);
    return $trimmed === '' ? $default : $trimmed;
}

// Database connection parameters
define('DB_HOST', edudisplej_env_or_default('EDUDISPLEJ_DB_HOST', 'localhost'));
define('DB_USER', edudisplej_env_or_default('EDUDISPLEJ_DB_USER', 'edudisplej_sk'));
define('DB_PASS', edudisplej_env_or_default('EDUDISPLEJ_DB_PASS', 'Pab)tB/g/PulNs)2'));
define('DB_NAME', edudisplej_env_or_default('EDUDISPLEJ_DB_NAME', 'edudisplej'));
define('DB_PORT', (int)edudisplej_env_or_default('EDUDISPLEJ_DB_PORT', '3306'));
define('DB_CHARSET', edudisplej_env_or_default('EDUDISPLEJ_DB_CHARSET', 'utf8mb4'));

/**
 * Get database connection
 * @return mysqli Database connection object
 * @throws Exception if connection fails
 */
function getDbConnection() {
    try {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            throw new RuntimeException('PHP mysqli extension is not available in this runtime.');
        }

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        // Apply configured charset with safe fallback
        if (!$conn->set_charset(DB_CHARSET)) {
            if (!$conn->set_charset("utf8")) {
                throw new Exception("Cannot set UTF-8 charset");
            }
        }
        
        return $conn;
    } catch (Throwable $e) {
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

