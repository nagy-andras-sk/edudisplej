<?php
/**
 * Database Structure Auto-Fixer
 * EduDisplej Control Panel
 * 
 * This script automatically checks and fixes the database structure
 * to match the expected schema. Run this whenever you need to update
 * the database structure.
 */

require_once 'dbkonfiguracia.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$results = [];
$errors = [];

function logResult($message, $type = 'info') {
    global $results;
    $results[] = ['type' => $type, 'message' => $message];
    // Console log
    echo "[" . strtoupper($type) . "] " . $message . "\n";
}

function logError($message) {
    global $errors;
    $errors[] = $message;
    logResult($message, 'error');
}

try {
    $conn = getDbConnection();
    
    // Set charset explicitly
    if (!$conn->set_charset("utf8mb4")) {
        logError("Failed to set charset utf8mb4: " . $conn->error);
        // Try fallback to utf8
        if (!$conn->set_charset("utf8")) {
            throw new Exception("Cannot set any UTF-8 charset");
        }
        logResult("Using utf8 charset (fallback)", 'warning');
        $charset = 'utf8';
        $collation = 'utf8_unicode_ci';
    } else {
        logResult("Charset set to utf8mb4", 'success');
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';
    }
    
    logResult("Connected to database successfully", 'success');
    
    // Log MySQL version
    $version = $conn->query("SELECT VERSION() as version")->fetch_assoc();
    logResult("MySQL/MariaDB version: " . $version['version'], 'info');
    
    // Log current charset settings
    $charset_result = $conn->query("SHOW VARIABLES LIKE 'character_set%'");
    while ($row = $charset_result->fetch_assoc()) {
        logResult("DB Setting: " . $row['Variable_name'] . " = " . $row['Value'], 'info');
    }
    
    // Define expected schema
    $expected_tables = [
        'users' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'username' => "varchar(255) NOT NULL",
                'password' => "varchar(255) NOT NULL",
                'email' => "varchar(255) DEFAULT NULL",
                'lang' => "varchar(5) NOT NULL DEFAULT 'sk'",
                'isadmin' => "tinyint(1) NOT NULL DEFAULT 0",
                'is_super_admin' => "tinyint(1) NOT NULL DEFAULT 0",
                'role' => "enum('super_admin','admin','content_editor','viewer') DEFAULT 'viewer'",
                'company_id' => "int(11) DEFAULT NULL",
                'otp_enabled' => "tinyint(1) NOT NULL DEFAULT 0",
                'otp_secret' => "varchar(255) DEFAULT NULL",
                'otp_verified' => "tinyint(1) NOT NULL DEFAULT 0",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'last_login' => "timestamp NULL DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['username'],
            'foreign_keys' => [
                'users_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL"
            ]
        ],
        'companies' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'name' => "varchar(255) NOT NULL",
                'license_key' => "varchar(255) DEFAULT NULL",
                'api_token' => "varchar(255) DEFAULT NULL",
                'token_created_at' => "timestamp NULL DEFAULT NULL",
                'is_active' => "tinyint(1) NOT NULL DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['license_key', 'api_token'],
            'foreign_keys' => []
        ],
        'kiosks' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'hostname' => "text DEFAULT NULL",
                'friendly_name' => "varchar(255) DEFAULT NULL",
                'installed' => "datetime NOT NULL DEFAULT current_timestamp()",
                'mac' => "text NOT NULL",
                'device_id' => "varchar(20) DEFAULT NULL",
                'public_ip' => "varchar(45) DEFAULT NULL",
                'last_seen' => "timestamp NULL DEFAULT NULL",
                'hw_info' => "text DEFAULT NULL",
                'version' => "varchar(50) DEFAULT NULL",
                'screen_resolution' => "varchar(50) DEFAULT NULL",
                'screen_status' => "varchar(20) DEFAULT NULL",
                'loop_last_update' => "datetime DEFAULT NULL",
                'last_sync' => "datetime DEFAULT NULL",
                'screenshot_url' => "text DEFAULT NULL",
                'screenshot_enabled' => "tinyint(1) DEFAULT 0",
                'screenshot_requested' => "tinyint(1) DEFAULT 0",
                'screenshot_timestamp' => "timestamp NULL DEFAULT NULL",
                'status' => "enum('online','offline','pending','unconfigured') DEFAULT 'unconfigured'",
                'company_id' => "int(11) DEFAULT NULL",
                'location' => "text DEFAULT NULL",
                'comment' => "text DEFAULT NULL",
                'sync_interval' => "int(11) DEFAULT 300",
                'is_configured' => "tinyint(1) DEFAULT 0"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kiosks_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL"
            ]
        ],
        'kiosk_groups' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'name' => "varchar(255) NOT NULL",
                'company_id' => "int(11) DEFAULT NULL",
                'description' => "text DEFAULT NULL",
                'priority' => "int(11) NOT NULL DEFAULT 0",
                'is_default' => "tinyint(1) NOT NULL DEFAULT 0"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kiosk_groups_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_group_assignments' => [
            'columns' => [
                'kiosk_id' => "int(11) NOT NULL",
                'group_id' => "int(11) NOT NULL"
            ],
            'primary_key' => 'kiosk_id,group_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kga_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'kga_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE"
            ]
        ],
        'sync_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) DEFAULT NULL",
                'timestamp' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'action' => "varchar(255) DEFAULT NULL",
                'details' => "text DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'sync_logs_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE SET NULL"
            ]
        ],
        'modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'module_key' => "varchar(100) NOT NULL",
                'name' => "varchar(255) NOT NULL",
                'description' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['module_key'],
            'foreign_keys' => []
        ],
        'module_licenses' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'company_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'quantity' => "int(11) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'ml_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE",
                'ml_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'group_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'group_id' => "int(11) NOT NULL",
                'module_sequence' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'gm_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE",
                'gm_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_group_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'group_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'module_key' => "varchar(100) DEFAULT NULL",
                'display_order' => "int(11) DEFAULT 0",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kgm_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE",
                'kgm_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'module_key' => "varchar(100) DEFAULT NULL",
                'display_order' => "int(11) DEFAULT 0",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'km_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'km_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        // Health monitoring and command execution tables
        'kiosk_health' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'status' => "varchar(50) NOT NULL DEFAULT 'unknown'",
                'system_data' => "json DEFAULT NULL",
                'services_data' => "json DEFAULT NULL",
                'network_data' => "json DEFAULT NULL",
                'sync_data' => "json DEFAULT NULL",
                'timestamp' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kh_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_health_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'status' => "varchar(50) NOT NULL",
                'details' => "json DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'khl_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_command_queue' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'command_type' => "varchar(50) NOT NULL",
                'command' => "text NOT NULL",
                'status' => "varchar(50) NOT NULL DEFAULT 'pending'",
                'output' => "longtext DEFAULT NULL",
                'error' => "longtext DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'executed_at' => "timestamp NULL DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kcq_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_command_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'command_id' => "int(11) NOT NULL",
                'action' => "varchar(50) NOT NULL",
                'details' => "json DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kcl_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'kcl_command_fk' => "FOREIGN KEY (command_id) REFERENCES kiosk_command_queue(id) ON DELETE CASCADE"
            ]
        ]
    ];
    
    // Check and create tables
    foreach ($expected_tables as $table_name => $table_def) {
        $table_exists = false;
        
        logResult("Processing table: $table_name", 'info');
        
        // Check if table exists
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($result->num_rows > 0) {
            $table_exists = true;
            logResult("Table '$table_name' exists", 'info');
            
            // Check columns
            $result = $conn->query("DESCRIBE $table_name");
            $existing_columns = [];
            while ($row = $result->fetch_assoc()) {
                $existing_columns[$row['Field']] = $row;
            }
            
            // Add missing columns
            foreach ($table_def['columns'] as $col_name => $col_def) {
                if (!isset($existing_columns[$col_name])) {
                    $sql = "ALTER TABLE $table_name ADD COLUMN $col_name $col_def";
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Added column '$col_name' to table '$table_name'", 'success');
                    } else {
                        logError("Failed to add column '$col_name' to table '$table_name': " . $conn->error);
                    }
                }
            }

            // Fix kiosks.status column if type/enum values are outdated
            if ($table_name === 'kiosks' && isset($existing_columns['status'])) {
                $current_type = strtolower($existing_columns['status']['Type'] ?? '');
                $expected_type = strtolower($table_def['columns']['status']);

                if ($current_type !== '' && strpos($current_type, "enum('online','offline','pending','unconfigured')") === false) {
                    $sql = "ALTER TABLE $table_name MODIFY COLUMN status " . $table_def['columns']['status'];
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Updated column 'status' in table '$table_name' to include 'unconfigured'", 'success');
                    } else {
                        logError("Failed to update column 'status' in table '$table_name': " . $conn->error);
                    }
                } else {
                    logResult("Column 'status' in table '$table_name' already supports expected enum values", 'info');
                }
            }
        } else {
            // Create table
            logResult("Table '$table_name' does not exist. Creating...", 'warning');
            
            $columns_sql = [];
            foreach ($table_def['columns'] as $col_name => $col_def) {
                $columns_sql[] = "$col_name $col_def";
            }
            
            // Add primary key
            if (strpos($table_def['primary_key'], ',') !== false) {
                $columns_sql[] = "PRIMARY KEY (" . $table_def['primary_key'] . ")";
            } else {
                $columns_sql[] = "PRIMARY KEY (" . $table_def['primary_key'] . ")";
            }
            
            // Add unique keys
            foreach ($table_def['unique_keys'] as $uk) {
                $columns_sql[] = "UNIQUE KEY ($uk)";
            }
            
            $sql = "CREATE TABLE $table_name (\n  " . implode(",\n  ", $columns_sql) . "\n) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
            
            logResult("Executing SQL: $sql", 'info');
            
            if ($conn->query($sql)) {
                logResult("Created table '$table_name'", 'success');
            } else {
                logError("Failed to create table '$table_name': " . $conn->error);
            }
        }
    }
    
    // Add foreign keys (do this after all tables are created)
    logResult("Checking foreign key constraints...", 'info');
    foreach ($expected_tables as $table_name => $table_def) {
        if (!empty($table_def['foreign_keys'])) {
            // Get existing foreign keys
            $result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                                    WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
                                    AND TABLE_NAME = '$table_name' 
                                    AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
            $existing_fks = [];
            while ($row = $result->fetch_assoc()) {
                $existing_fks[$row['CONSTRAINT_NAME']] = true;
            }
            
            foreach ($table_def['foreign_keys'] as $fk_name => $fk_def) {
                if (!isset($existing_fks[$fk_name])) {
                    $sql = "ALTER TABLE $table_name ADD CONSTRAINT $fk_name $fk_def";
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Added foreign key '$fk_name' to table '$table_name'", 'success');
                    } else {
                        // Foreign key might fail if referenced table doesn't exist yet or data integrity issue
                        logError("Failed to add foreign key '$fk_name' to table '$table_name': " . $conn->error);
                    }
                }
            }
        }
    }
    
    // Create default admin user if not exists
    $result = $conn->query("SELECT id FROM users WHERE username = 'admin'");
    if ($result->num_rows == 0) {
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $username = 'admin';
        $email = 'admin@edudisplej.sk';
        $isadmin = 1;
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, isadmin) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $username, $default_password, $email, $isadmin);
        if ($stmt->execute()) {
            logResult("Created default admin user (username: admin, password: admin123)", 'success');
        } else {
            logError("Failed to create default admin user: " . $conn->error);
        }
        $stmt->close();
    } else {
        logResult("Default admin user already exists", 'info');
    }
    
    // Create default company if not exists
    $result = $conn->query("SELECT id FROM companies WHERE name = 'Default Company'");
    if ($result->num_rows == 0) {
        $company_name = 'Default Company';
        $stmt = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
        $stmt->bind_param("s", $company_name);
        if ($stmt->execute()) {
            logResult("Created default company", 'success');
        } else {
            logError("Failed to create default company: " . $conn->error);
        }
        $stmt->close();
    } else {
        logResult("Default company already exists", 'info');
    }
    
    // Create default modules if not exist
    $default_modules = [
        ['key' => 'clock', 'name' => 'Clock & Time', 'description' => 'Display date and time with customizable formats and colors'],
        ['key' => 'datetime', 'name' => 'Date & Time Module', 'description' => 'Advanced datetime module with digital/analog clock, 12h/24h formats, customizable colors and sizes'],
        ['key' => 'namedays', 'name' => 'Name Days', 'description' => 'Display Hungarian and Slovak name days with customizable style'],
        ['key' => 'split_clock_namedays', 'name' => 'Split: Clock + Name Days', 'description' => 'Combined module for 16:9 displays showing clock and name days together'],
        ['key' => 'unconfigured', 'name' => 'Unconfigured Display', 'description' => 'Default screen for unconfigured kiosks'],
        ['key' => 'default-logo', 'name' => 'Default Logo', 'description' => 'Display EduDisplej logo with version number and customizable text'],
        ['key' => 'dateclock', 'name' => 'Date & Clock Module', 'description' => 'Enhanced date and clock module with full customization options (analog/digital, formats, languages, sizes)']
    ];
    
    foreach ($default_modules as $module) {
        $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ?");
        $stmt->bind_param("s", $module['key']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO modules (module_key, name, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $module['key'], $module['name'], $module['description']);
            if ($stmt->execute()) {
                logResult("Created module: " . $module['name'], 'success');
            } else {
                logError("Failed to create module '" . $module['name'] . "': " . $conn->error);
            }
            $stmt->close();
        } else {
            logResult("Module '" . $module['name'] . "' already exists", 'info');
            $stmt->close();
        }
    }
    
    // Verify data integrity: check kiosk_modules for missing module_id values
    logResult("Verifying data integrity...", 'info');
    
    // Check for orphaned kiosk_modules entries (where module_key exists but module_id is NULL)
    $result = $conn->query("SELECT km.id, km.kiosk_id, km.module_key FROM kiosk_modules km WHERE km.module_id IS NULL OR km.module_id = 0");
    if ($result && $result->num_rows > 0) {
        logResult("Found " . $result->num_rows . " kiosk_modules entries with missing module_id", 'warning');
        
        // Fix them by finding the module_id from module_key
        while ($row = $result->fetch_assoc()) {
            if ($row['module_key']) {
                $fix_stmt = $conn->prepare("
                    UPDATE kiosk_modules km 
                    SET km.module_id = (SELECT id FROM modules WHERE module_key = ? LIMIT 1)
                    WHERE km.id = ?
                ");
                $fix_stmt->bind_param("si", $row['module_key'], $row['id']);
                if ($fix_stmt->execute()) {
                    logResult("Fixed kiosk_modules entry id=" . $row['id'] . " by module_key='" . $row['module_key'] . "'", 'success');
                } else {
                    logError("Failed to fix kiosk_modules entry id=" . $row['id'] . ": " . $conn->error);
                }
                $fix_stmt->close();
            }
        }
    } else {
        logResult("All kiosk_modules entries have valid module_id values", 'success');
    }
    
    // Check for orphaned kiosk_group_modules entries
    $result = $conn->query("SELECT kgm.id, kgm.module_key FROM kiosk_group_modules kgm WHERE kgm.module_id IS NULL OR kgm.module_id = 0");
    if ($result && $result->num_rows > 0) {
        logResult("Found " . $result->num_rows . " kiosk_group_modules entries with missing module_id", 'warning');
        
        while ($row = $result->fetch_assoc()) {
            if ($row['module_key']) {
                $fix_stmt = $conn->prepare("
                    UPDATE kiosk_group_modules kgm 
                    SET kgm.module_id = (SELECT id FROM modules WHERE module_key = ? LIMIT 1)
                    WHERE kgm.id = ?
                ");
                $fix_stmt->bind_param("si", $row['module_key'], $row['id']);
                if ($fix_stmt->execute()) {
                    logResult("Fixed kiosk_group_modules entry id=" . $row['id'] . " by module_key='" . $row['module_key'] . "'", 'success');
                } else {
                    logError("Failed to fix kiosk_group_modules entry id=" . $row['id'] . ": " . $conn->error);
                }
                $fix_stmt->close();
            }
        }
    }
    
    // Create indexes for health monitoring and command execution tables
    logResult("Creating indexes for new tables...", 'info');
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_timestamp ON kiosk_health(timestamp)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_status ON kiosk_health(status)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_kiosk ON kiosk_health(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_logs_kiosk ON kiosk_health_logs(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_logs_created ON kiosk_health_logs(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_status ON kiosk_command_queue(status)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_kiosk ON kiosk_command_queue(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_created ON kiosk_command_queue(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_kiosk ON kiosk_command_logs(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_command ON kiosk_command_logs(command_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_created ON kiosk_command_logs(created_at)"
    ];
    
    foreach ($indexes as $index_sql) {
        if ($conn->query($index_sql)) {
            logResult("Index created: " . substr($index_sql, 0, 50) . "...", 'success');
        } else {
            logError("Failed to create index: " . $conn->error);
        }
    }
    
    closeDbConnection($conn);
    logResult("Database structure check completed", 'success');
    
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Auto-Fixer - EduDisplej</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: #4ec9b0;
            margin-bottom: 20px;
            font-size: 28px;
        }
        
        .result {
            padding: 10px 15px;
            margin-bottom: 5px;
            border-radius: 3px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .result-info {
            background: #264f78;
            color: #9cdcfe;
        }
        
        .result-success {
            background: #0e6027;
            color: #4ec9b0;
        }
        
        .result-warning {
            background: #5a3e1c;
            color: #dcdcaa;
        }
        
        .result-error {
            background: #5a1d1d;
            color: #f48771;
        }
        
        .summary {
            margin-top: 30px;
            padding: 20px;
            background: #2d2d30;
            border-radius: 5px;
        }
        
        .summary h2 {
            color: #569cd6;
            margin-bottom: 15px;
        }
        
        .summary p {
            margin-bottom: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #0e639c;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #1177bb;
        }
        
        .timestamp {
            color: #858585;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Database Structure Auto-Fixer</h1>
        <p class="timestamp">Executed: <?php echo date('Y-m-d H:i:s'); ?></p>
        
        <div style="margin-top: 30px;">
            <?php foreach ($results as $result): ?>
                <div class="result result-<?php echo $result['type']; ?>">
                    <?php 
                    $icon = match($result['type']) {
                        'success' => '✓',
                        'error' => '✗',
                        'warning' => '⚠',
                        default => 'ℹ'
                    };
                    echo $icon . ' ' . htmlspecialchars($result['message']); 
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="summary">
            <h2>Summary</h2>
            <p><strong>Total operations:</strong> <?php echo count($results); ?></p>
            <p><strong>Errors:</strong> <?php echo count($errors); ?></p>
            <p><strong>Status:</strong> 
                <?php if (empty($errors)): ?>
                    <span style="color: #4ec9b0;">✓ All operations completed successfully</span>
                <?php else: ?>
                    <span style="color: #f48771;">✗ Some operations failed - please check the log above</span>
                <?php endif; ?>
            </p>
            
            <a href="admin/index.php" class="btn">← Back to Admin Panel</a>
            <a href="dbjavito.php" class="btn" style="background: #0e6027;">↻ Run Again</a>
        </div>
    </div>
</body>
</html>

