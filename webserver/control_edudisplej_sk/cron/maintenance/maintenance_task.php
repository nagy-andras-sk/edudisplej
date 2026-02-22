<?php
/**
 * Database Structure Auto-Fixer
 * EduDisplej Control Panel
 * 
 * This script automatically checks and fixes the database structure
 * to match the expected schema. Run this whenever you need to update
 * the database structure.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/dbkonfiguracia.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$results = [];
$errors = [];

if (!defined('EDUDISPLEJ_DBJAVITO_ECHO')) {
    define('EDUDISPLEJ_DBJAVITO_ECHO', PHP_SAPI === 'cli');
}

if (!defined('EDUDISPLEJ_DBJAVITO_NO_HTML')) {
    define('EDUDISPLEJ_DBJAVITO_NO_HTML', false);
}

function logResult($message, $type = 'info') {
    global $results;
    $results[] = ['type' => $type, 'message' => $message];
    // Console log
    if (EDUDISPLEJ_DBJAVITO_ECHO) {
        echo "[" . strtoupper($type) . "] " . $message . "\n";
    }
}

function logError($message) {
    global $errors;
    $errors[] = $message;
    logResult($message, 'error');
}

function isSafeCleanupTableName(string $tableName): bool {
    $tableName = strtolower(trim($tableName));
    if ($tableName === '') {
        return false;
    }

    $patterns = [
        '/^tmp_/',
        '/^backup_/',
        '/_backup$/',
        '/_old$/',
        '/_legacy$/',
        '/^old_/'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $tableName)) {
            return true;
        }
    }

    return false;
}

function safeDeleteByQuery(mysqli $conn, string $sql, string $label): void {
    if ($conn->query($sql)) {
        $affected = (int)$conn->affected_rows;
        if ($affected > 0) {
            logResult("Cleanup: $label ($affected rows removed)", 'success');
        } else {
            logResult("Cleanup: $label (no rows)", 'info');
        }
    } else {
        logError("Cleanup failed for '$label': " . $conn->error);
    }
}

function cleanupModuleFilesystem(string $baseDir): void {
    $modulesDir = realpath($baseDir . '/modules');
    if ($modulesDir === false || !is_dir($modulesDir)) {
        logResult('Cleanup: modules directory not found, skipping file cleanup', 'warning');
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $removedFiles = 0;
    $removedDirs = 0;

    foreach ($iterator as $pathInfo) {
        $path = $pathInfo->getPathname();

        if ($pathInfo->isFile()) {
            $name = $pathInfo->getFilename();
            if (preg_match('/\.(bak|old|tmp|orig)$/i', $name) || preg_match('/^~\$/', $name)) {
                if (@unlink($path)) {
                    $removedFiles++;
                    logResult('Cleanup: removed legacy file ' . str_replace('\\', '/', $path), 'success');
                }
            }
        } elseif ($pathInfo->isDir()) {
            $name = strtolower($pathInfo->getFilename());
            if (in_array($name, ['old', 'backup', 'legacy', 'tmp'], true)) {
                $files = @scandir($path);
                if (is_array($files) && count($files) <= 2) {
                    if (@rmdir($path)) {
                        $removedDirs++;
                        logResult('Cleanup: removed empty legacy folder ' . str_replace('\\', '/', $path), 'success');
                    }
                }
            }
        }
    }

    logResult("Cleanup: module filesystem summary - files removed: $removedFiles, folders removed: $removedDirs", 'info');
}

try {
    @set_time_limit(240);

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
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'signing_secret' => "varchar(256) DEFAULT NULL COMMENT 'HMAC-SHA256 signing secret for request signature validation'"
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
                'debug_mode' => "tinyint(1) DEFAULT 0",
                'screenshot_requested' => "tinyint(1) DEFAULT 0",
                'screenshot_timestamp' => "timestamp NULL DEFAULT NULL",
                'screenshot_requested_until' => "datetime DEFAULT NULL",
                'screenshot_interval_seconds' => "int(11) DEFAULT 3",
                'status' => "enum('online','offline','pending','unconfigured','upgrading','error') DEFAULT 'unconfigured'",
                'upgrade_started_at' => "datetime DEFAULT NULL",
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
        ],
        'company_licenses' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'company_id' => "int(11) NOT NULL",
                'valid_from' => "date NOT NULL",
                'valid_until' => "date NOT NULL",
                'device_limit' => "int(11) NOT NULL DEFAULT 10",
                'status' => "varchar(20) NOT NULL DEFAULT 'active'",
                'notes' => "text DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'company_licenses_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE"
            ]
        ],
        'system_settings' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'setting_key' => "varchar(100) NOT NULL",
                'setting_value' => "longtext DEFAULT NULL",
                'is_encrypted' => "tinyint(1) NOT NULL DEFAULT 0",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['setting_key'],
            'foreign_keys' => []
        ],
        'email_templates' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'template_key' => "varchar(100) NOT NULL",
                'lang' => "varchar(5) NOT NULL DEFAULT 'en'",
                'subject' => "varchar(255) NOT NULL",
                'body_html' => "longtext DEFAULT NULL",
                'body_text' => "longtext DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['template_key,lang'],
            'foreign_keys' => []
        ],
        'email_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'template_key' => "varchar(100) DEFAULT NULL",
                'to_email' => "varchar(255) NOT NULL",
                'subject' => "varchar(255) NOT NULL",
                'result' => "varchar(20) NOT NULL",
                'error_message' => "text DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => []
        ],
        'service_versions' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'service_name' => "varchar(255) NOT NULL",
                'version_token' => "varchar(64) NOT NULL",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()",
                'updated_by_user_id' => "int(11) DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['service_name'],
            'foreign_keys' => [
                'service_versions_user_fk' => "FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL"
            ]
        ],
        'api_nonces' => [
            'columns' => [
                'id' => "bigint(20) NOT NULL AUTO_INCREMENT",
                'nonce' => "varchar(128) NOT NULL",
                'company_id' => "int(11) NOT NULL",
                'expires_at' => "datetime NOT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['nonce'],
            'foreign_keys' => []
        ],
        'display_schedules' => [
            'columns' => [
                'schedule_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kijelzo_id' => "int(11) NOT NULL",
                'group_id' => "int(11) DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'schedule_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'display_schedules_kijelzo_fk' => "FOREIGN KEY (kijelzo_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'display_schedules_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE SET NULL"
            ]
        ],
        'schedule_time_slots' => [
            'columns' => [
                'slot_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'schedule_id' => "int(11) NOT NULL",
                'day_of_week' => "int(1) NOT NULL COMMENT '0=Sunday, 6=Saturday'",
                'start_time' => "time NOT NULL",
                'end_time' => "time NOT NULL",
                'is_enabled' => "tinyint(1) NOT NULL DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'slot_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'schedule_time_slots_schedule_fk' => "FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE"
            ]
        ],
        'schedule_special_days' => [
            'columns' => [
                'special_day_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'schedule_id' => "int(11) NOT NULL",
                'date' => "date NOT NULL",
                'start_time' => "time DEFAULT NULL",
                'end_time' => "time DEFAULT NULL",
                'is_enabled' => "tinyint(1) NOT NULL DEFAULT 1",
                'reason' => "varchar(255) DEFAULT NULL COMMENT 'Holiday, maintenance, etc.'",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'special_day_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'schedule_special_days_schedule_fk' => "FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE"
            ]
        ],
        'display_status_log' => [
            'columns' => [
                'log_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kijelzo_id' => "int(11) NOT NULL",
                'previous_status' => "varchar(50) DEFAULT NULL",
                'new_status' => "varchar(50) NOT NULL",
                'reason' => "varchar(255) DEFAULT NULL",
                'triggered_by' => "varchar(100) DEFAULT 'daemon' COMMENT 'script, daemon, admin, api, etc.'",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'log_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'display_status_log_kijelzo_fk' => "FOREIGN KEY (kijelzo_id) REFERENCES kiosks(id) ON DELETE CASCADE"
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

                if ($current_type !== '' && $current_type !== $expected_type) {
                    $sql = "ALTER TABLE $table_name MODIFY COLUMN status " . $table_def['columns']['status'];
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Updated column 'status' in table '$table_name' to expected enum set", 'success');
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
        ['key' => 'default-logo', 'name' => 'Default Logo', 'description' => 'Display EduDisplej logo with version number and customizable text'],
        ['key' => 'text', 'name' => 'Text', 'description' => 'Display richly formatted text with optional scroll mode and background image'],
        ['key' => 'unconfigured', 'name' => 'Unconfigured Display', 'description' => 'Default screen for unconfigured kiosks']
    ];
    
    foreach ($default_modules as $module) {
        $stmt = $conn->prepare("SELECT id, name, description, is_active FROM modules WHERE module_key = ?");
        $stmt->bind_param("s", $module['key']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            $stmt = $conn->prepare("INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $module['key'], $module['name'], $module['description']);
            if ($stmt->execute()) {
                logResult("Created module: " . $module['name'], 'success');
            } else {
                logError("Failed to create module '" . $module['name'] . "': " . $conn->error);
            }
            $stmt->close();
            continue;
        }

        $existing_name = (string)($existing['name'] ?? '');
        $existing_description = (string)($existing['description'] ?? '');
        $existing_active = (int)($existing['is_active'] ?? 0);

        if ($existing_name !== (string)$module['name'] || $existing_description !== (string)$module['description'] || $existing_active !== 1) {
            $module_id = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE modules SET name = ?, description = ?, is_active = 1 WHERE id = ?");
            $stmt->bind_param("ssi", $module['name'], $module['description'], $module_id);
            if ($stmt->execute()) {
                logResult("Updated module metadata: " . $module['name'], 'success');
            } else {
                logError("Failed to update module metadata '" . $module['name'] . "': " . $conn->error);
            }
            $stmt->close();
        } else {
            logResult("Module '" . $module['name'] . "' already synced", 'info');
        }
    }

    // Normalize legacy datetime aliases to canonical 'clock' module
    $legacy_aliases = ['datetime', 'dateclock'];
    $clock_id = 0;

    $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = 'clock' LIMIT 1");
    $stmt->execute();
    $clock_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $clock_id = (int)($clock_row['id'] ?? 0);

    if ($clock_id > 0) {
        foreach ($legacy_aliases as $legacy_key) {
            $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
            $stmt->bind_param("s", $legacy_key);
            $stmt->execute();
            $legacy_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $legacy_id = (int)($legacy_row['id'] ?? 0);
            if ($legacy_id <= 0) {
                continue;
            }

            safeDeleteByQuery(
                $conn,
                "UPDATE kiosk_modules SET module_id = $clock_id, module_key = 'clock' WHERE module_id = $legacy_id OR module_key = '$legacy_key'",
                "kiosk_modules migrated from '$legacy_key' to 'clock'"
            );

            safeDeleteByQuery(
                $conn,
                "UPDATE kiosk_group_modules SET module_id = $clock_id, module_key = 'clock' WHERE module_id = $legacy_id OR module_key = '$legacy_key'",
                "kiosk_group_modules migrated from '$legacy_key' to 'clock'"
            );

            safeDeleteByQuery(
                $conn,
                "UPDATE group_modules SET module_id = $clock_id WHERE module_id = $legacy_id",
                "group_modules migrated from '$legacy_key' to 'clock'"
            );

            // Merge duplicate module_licenses into canonical clock licenses per company
            $license_sum_stmt = $conn->prepare("SELECT company_id, SUM(quantity) AS qty FROM module_licenses WHERE module_id = ? GROUP BY company_id");
            $license_sum_stmt->bind_param("i", $legacy_id);
            $license_sum_stmt->execute();
            $license_sum_result = $license_sum_stmt->get_result();

            while ($license_row = $license_sum_result->fetch_assoc()) {
                $company_id = (int)($license_row['company_id'] ?? 0);
                $quantity_to_merge = (int)($license_row['qty'] ?? 0);
                if ($company_id <= 0 || $quantity_to_merge <= 0) {
                    continue;
                }

                $existing_clock_license_stmt = $conn->prepare("SELECT id, quantity FROM module_licenses WHERE company_id = ? AND module_id = ? LIMIT 1");
                $existing_clock_license_stmt->bind_param("ii", $company_id, $clock_id);
                $existing_clock_license_stmt->execute();
                $existing_clock_license = $existing_clock_license_stmt->get_result()->fetch_assoc();
                $existing_clock_license_stmt->close();

                if ($existing_clock_license) {
                    $clock_license_id = (int)$existing_clock_license['id'];
                    $new_qty = (int)$existing_clock_license['quantity'] + $quantity_to_merge;
                    $update_clock_license_stmt = $conn->prepare("UPDATE module_licenses SET quantity = ? WHERE id = ?");
                    $update_clock_license_stmt->bind_param("ii", $new_qty, $clock_license_id);
                    if (!$update_clock_license_stmt->execute()) {
                        logError("Failed to merge license qty for company $company_id from '$legacy_key' into 'clock': " . $conn->error);
                    }
                    $update_clock_license_stmt->close();
                } else {
                    $insert_clock_license_stmt = $conn->prepare("INSERT INTO module_licenses (company_id, module_id, quantity) VALUES (?, ?, ?)");
                    $insert_clock_license_stmt->bind_param("iii", $company_id, $clock_id, $quantity_to_merge);
                    if (!$insert_clock_license_stmt->execute()) {
                        logError("Failed to create merged clock license for company $company_id: " . $conn->error);
                    }
                    $insert_clock_license_stmt->close();
                }
            }
            $license_sum_stmt->close();

            safeDeleteByQuery(
                $conn,
                "DELETE FROM module_licenses WHERE module_id = $legacy_id",
                "module_licenses cleaned for legacy module '$legacy_key'"
            );

            $delete_legacy_stmt = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $delete_legacy_stmt->bind_param("i", $legacy_id);
            if ($delete_legacy_stmt->execute()) {
                logResult("Removed legacy alias module '$legacy_key'", 'success');
            } else {
                logError("Failed to remove legacy alias module '$legacy_key': " . $conn->error);
            }
            $delete_legacy_stmt->close();
        }
    }
    
    // Verify data integrity: check kiosk_modules for missing module_id values
    logResult("Verifying data integrity...", 'info');
    
    // Backfill module_key from module_id where possible (helps keep API payloads consistent)
    safeDeleteByQuery(
        $conn,
        "UPDATE kiosk_modules km JOIN modules m ON km.module_id = m.id SET km.module_key = m.module_key WHERE (km.module_key IS NULL OR km.module_key = '') AND km.module_id IS NOT NULL AND km.module_id > 0",
        "kiosk_modules module_key backfilled from module_id"
    );

    safeDeleteByQuery(
        $conn,
        "UPDATE kiosk_group_modules kgm JOIN modules m ON kgm.module_id = m.id SET kgm.module_key = m.module_key WHERE (kgm.module_key IS NULL OR kgm.module_key = '') AND kgm.module_id IS NOT NULL AND kgm.module_id > 0",
        "kiosk_group_modules module_key backfilled from module_id"
    );

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
    
    // Initialize display scheduling system - create default schedules for kiosks without schedules
    logResult("Initializing display scheduling system...", 'info');
    
    // Get all kiosks without schedules
    $result = $conn->query("
        SELECT k.id, k.friendly_name 
        FROM kiosks k
        LEFT JOIN display_schedules ds ON k.id = ds.kijelzo_id
        WHERE ds.schedule_id IS NULL
    ");
    
    if ($result && $result->num_rows > 0) {
        while ($kiosk = $result->fetch_assoc()) {
            $kijelzo_id = $kiosk['id'];
            $kiosk_name = $kiosk['friendly_name'] ?? 'Kiosk #' . $kijelzo_id;
            
            // Get the kiosk's group_id if it belongs to a group
            $group_result = $conn->query("
                SELECT group_id FROM kiosk_group_assignments 
                WHERE kiosk_id = $kijelzo_id LIMIT 1
            ");
            $group_id = null;
            if ($group_result && $group_result->num_rows > 0) {
                $group_row = $group_result->fetch_assoc();
                $group_id = $group_row['group_id'];
            }
            
            // Create default schedule (22:00-06:00 OFF, rest ON)
            $stmt = $conn->prepare("INSERT INTO display_schedules (kijelzo_id, group_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $kijelzo_id, $group_id);
            
            if ($stmt->execute()) {
                $schedule_id = $conn->insert_id;
                
                // Add time slots for each day of week (0=Sunday, 6=Saturday)
                $stmt_slot = $conn->prepare("
                    INSERT INTO schedule_time_slots 
                    (schedule_id, day_of_week, start_time, end_time, is_enabled) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                if (!$stmt_slot) {
                    logError("Failed to prepare time slot insert: " . $conn->error);
                    continue;
                }
                
                $slots_created = 0;
                for ($day = 0; $day < 7; $day++) {
                    // OFF: 22:00 - 06:00
                    $start_off = '22:00:00';
                    $end_off = '06:00:00';
                    $is_off = 0;
                    
                    $stmt_slot->bind_param("iissi", $schedule_id, $day, $start_off, $end_off, $is_off);
                    if ($stmt_slot->execute()) {
                        $slots_created++;
                    } else {
                        logError("Failed to create OFF slot for kiosk $kijelzo_id, day $day: " . $conn->error);
                    }
                }
                
                $stmt_slot->close();
                
                if ($slots_created === 7) {
                    logResult("Created default schedule for '$kiosk_name' (22:00-06:00 OFF) with $slots_created time slots", 'success');
                } else {
                    logError("Failed to create all time slots for kiosk $kijelzo_id (created: $slots_created/7)");
                }
            } else {
                logError("Failed to create default schedule for kiosk $kijelzo_id: " . $conn->error);
            }
            
            $stmt->close();
        }
    } else {
        logResult("All kiosks have display schedules configured", 'info');
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
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_created ON kiosk_command_logs(created_at)",
        // Display scheduling system indexes
        "CREATE INDEX IF NOT EXISTS idx_display_schedules_kijelzo ON display_schedules(kijelzo_id)",
        "CREATE INDEX IF NOT EXISTS idx_display_schedules_group ON display_schedules(group_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_time_slots_schedule ON schedule_time_slots(schedule_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_time_slots_day ON schedule_time_slots(day_of_week)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_special_days_schedule ON schedule_special_days(schedule_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_special_days_date ON schedule_special_days(date)",
        "CREATE INDEX IF NOT EXISTS idx_display_status_log_kijelzo ON display_status_log(kijelzo_id)",
        "CREATE INDEX IF NOT EXISTS idx_display_status_log_created ON display_status_log(created_at)"
    ];
    
    foreach ($indexes as $index_sql) {
        if ($conn->query($index_sql)) {
            logResult("Index created: " . substr($index_sql, 0, 50) . "...", 'success');
        } else {
            logError("Failed to create index: " . $conn->error);
        }
    }

    // Cleanup phase: remove unused/orphaned remnants safely
    logResult("Starting cleanup of unused remnants...", 'info');

    // Remove unresolved orphan mappings where module reference cannot be restored
    safeDeleteByQuery(
        $conn,
        "DELETE km FROM kiosk_modules km LEFT JOIN modules m ON km.module_id = m.id WHERE (km.module_id IS NULL OR km.module_id = 0 OR m.id IS NULL) AND (km.module_key IS NULL OR km.module_key = '' OR NOT EXISTS (SELECT 1 FROM modules mx WHERE mx.module_key = km.module_key))",
        "kiosk_modules unresolved orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE kgm FROM kiosk_group_modules kgm LEFT JOIN modules m ON kgm.module_id = m.id WHERE (kgm.module_id IS NULL OR kgm.module_id = 0 OR m.id IS NULL) AND (kgm.module_key IS NULL OR kgm.module_key = '' OR NOT EXISTS (SELECT 1 FROM modules mx WHERE mx.module_key = kgm.module_key))",
        "kiosk_group_modules unresolved orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE ml FROM module_licenses ml LEFT JOIN modules m ON ml.module_id = m.id LEFT JOIN companies c ON ml.company_id = c.id WHERE m.id IS NULL OR c.id IS NULL",
        "module_licenses orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE kga FROM kiosk_group_assignments kga LEFT JOIN kiosks k ON kga.kiosk_id = k.id LEFT JOIN kiosk_groups kg ON kga.group_id = kg.id WHERE k.id IS NULL OR kg.id IS NULL",
        "kiosk_group_assignments orphan entries"
    );

    // Remove expired nonces (security/maintenance)
    safeDeleteByQuery(
        $conn,
        "DELETE FROM api_nonces WHERE expires_at < NOW()",
        "expired api_nonces"
    );

    // Remove deprecated modules only if fully unused and no filesystem artifact exists
    $deprecated_module_keys = ['namedays', 'split_clock_namedays'];
    foreach ($deprecated_module_keys as $deprecated_key) {
        $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
        $stmt->bind_param("s", $deprecated_key);
        $stmt->execute();
        $module_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$module_row) {
            continue;
        }

        $module_id = (int)$module_row['id'];
        $usage_result = $conn->query("SELECT
                (SELECT COUNT(*) FROM kiosk_modules WHERE module_id = $module_id) AS kiosk_usage,
                (SELECT COUNT(*) FROM kiosk_group_modules WHERE module_id = $module_id) AS group_usage,
                (SELECT COUNT(*) FROM module_licenses WHERE module_id = $module_id) AS license_usage");
        $usage = $usage_result ? $usage_result->fetch_assoc() : null;

        $total_usage = (int)($usage['kiosk_usage'] ?? 0) + (int)($usage['group_usage'] ?? 0) + (int)($usage['license_usage'] ?? 0);
        if ($total_usage === 0) {
            $del_stmt = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $del_stmt->bind_param("i", $module_id);
            if ($del_stmt->execute()) {
                logResult("Cleanup: removed unused deprecated module '$deprecated_key'", 'success');
            } else {
                logError("Cleanup failed for deprecated module '$deprecated_key': " . $conn->error);
            }
            $del_stmt->close();
        } else {
            logResult("Cleanup: deprecated module '$deprecated_key' kept (still used)", 'warning');
        }
    }

    // Remove only clearly legacy/temporary empty tables
    $existing_tables_result = $conn->query("SHOW TABLES");
    $existing_tables = [];
    while ($row = $existing_tables_result->fetch_array()) {
        $existing_tables[] = (string)$row[0];
    }

    $expected_table_names = array_keys($expected_tables);
    foreach ($existing_tables as $table_name) {
        if (in_array($table_name, $expected_table_names, true)) {
            continue;
        }

        if (!isSafeCleanupTableName($table_name)) {
            continue;
        }

        $count_res = $conn->query("SELECT COUNT(*) AS cnt FROM `$table_name`");
        $count_row = $count_res ? $count_res->fetch_assoc() : ['cnt' => null];
        $cnt = isset($count_row['cnt']) ? (int)$count_row['cnt'] : -1;

        if ($cnt === 0) {
            if ($conn->query("DROP TABLE `$table_name`")) {
                logResult("Cleanup: dropped empty legacy table '$table_name'", 'success');
            } else {
                logError("Cleanup failed to drop table '$table_name': " . $conn->error);
            }
        } elseif ($cnt > 0) {
            logResult("Cleanup: legacy table '$table_name' kept (contains $cnt rows)", 'warning');
        }
    }

    cleanupModuleFilesystem($baseDir);
    
    // Register core modules if not already exists
    registerCoreModules($conn);
    
    closeDbConnection($conn);
    logResult("Database structure check completed", 'success');
    
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

function registerCoreModules(mysqli $conn): void {
    $coreModules = [
        [
            'module_key' => 'clock',
            'name' => 'Óra',
            'description' => 'Digitális vagy analóg óra kijelzés',
            'is_active' => 1
        ],
        [
            'module_key' => 'datetime',
            'name' => 'Dátum és Óra',
            'description' => 'Dátum és óra kombinált megjelenítés',
            'is_active' => 1
        ],
        [
            'module_key' => 'dateclock',
            'name' => 'Dátum-Óra',
            'description' => 'Dátum és óra egy modulban',
            'is_active' => 1
        ],
        [
            'module_key' => 'default-logo',
            'name' => 'Alapértelmezett logó',
            'description' => 'Egyedi logó vagy szöveg megjelenítés',
            'is_active' => 1
        ],
        [
            'module_key' => 'text',
            'name' => 'Szöveg',
            'description' => 'Formázott szöveges tartalom kijelzése',
            'is_active' => 1
        ],
        [
            'module_key' => 'pdf',
            'name' => 'PDF Megjelenítő',
            'description' => 'PDF dokumentumok kijelzése és navigációja',
            'is_active' => 1
        ],
        [
            'module_key' => 'unconfigured',
            'name' => 'Beállítás nélküli',
            'description' => 'Technikai segédmodul üres loop-hoz',
            'is_active' => 1
        ]
    ];

    foreach ($coreModules as $module) {
        $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
        if (!$stmt) {
            logError("Module registration: prepare failed for '{$module['module_key']}'");
            continue;
        }

        $stmt->bind_param("s", $module['module_key']);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $update = $conn->prepare("UPDATE modules SET name = ?, description = ?, is_active = ? WHERE module_key = ?");
            if (!$update) {
                logError("Module registration: update prepare failed for '{$module['module_key']}'");
                continue;
            }

            $update->bind_param(
                "ssis",
                $module['name'],
                $module['description'],
                $module['is_active'],
                $module['module_key']
            );
            
            if ($update->execute()) {
                logResult("Module '{$module['module_key']}' updated", 'info');
            } else {
                logError("Module registration: update failed for '{$module['module_key']}': " . $conn->error);
            }
            $update->close();
        } else {
            $insert = $conn->prepare("INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, ?)");
            if (!$insert) {
                logError("Module registration: insert prepare failed for '{$module['module_key']}'");
                continue;
            }

            $insert->bind_param(
                "sssi",
                $module['module_key'],
                $module['name'],
                $module['description'],
                $module['is_active']
            );
            
            if ($insert->execute()) {
                logResult("Module '{$module['module_key']}' registered", 'success');
            } else {
                logError("Module registration: insert failed for '{$module['module_key']}': " . $conn->error);
            }
            $insert->close();
        }
    }
}

if (EDUDISPLEJ_DBJAVITO_NO_HTML || PHP_SAPI === 'cli') {
    return;
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
            <a href="run_maintenance.php" class="btn" style="background: #0e6027;">↻ Run Again</a>
        </div>
    </div>
</body>
</html>

