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
}

function logError($message) {
    global $errors;
    $errors[] = $message;
    logResult($message, 'error');
}

try {
    $conn = getDbConnection();
    logResult("Connected to database successfully", 'success');
    
    // Define expected schema
    $expected_tables = [
        'users' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'username' => "varchar(255) NOT NULL",
                'password' => "varchar(255) NOT NULL",
                'email' => "varchar(255) DEFAULT NULL",
                'isadmin' => "tinyint(1) NOT NULL DEFAULT 0",
                'company_id' => "int(11) DEFAULT NULL",
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
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => []
        ],
        'kiosks' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'hostname' => "text DEFAULT NULL",
                'installed' => "datetime NOT NULL DEFAULT current_timestamp()",
                'mac' => "text NOT NULL",
                'last_seen' => "timestamp NULL DEFAULT NULL",
                'hw_info' => "text DEFAULT NULL",
                'screenshot_url' => "text DEFAULT NULL",
                'screenshot_requested' => "tinyint(1) DEFAULT 0",
                'status' => "enum('online','offline','pending') DEFAULT 'pending'",
                'company_id' => "int(11) DEFAULT NULL",
                'location' => "text DEFAULT NULL",
                'comment' => "text DEFAULT NULL",
                'sync_interval' => "int(11) DEFAULT 300"
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
                'description' => "text DEFAULT NULL"
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
        ]
    ];
    
    // Check and create tables
    foreach ($expected_tables as $table_name => $table_def) {
        $table_exists = false;
        
        // Check if table exists
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($result->num_rows > 0) {
            $table_exists = true;
            logResult("Table '$table_name' exists", 'info');
            
            // Check columns
            $result = $conn->query("DESCRIBE $table_name");
            $existing_columns = [];
            while ($row = $result->fetch_assoc()) {
                $existing_columns[$row['Field']] = true;
            }
            
            // Add missing columns
            foreach ($table_def['columns'] as $col_name => $col_def) {
                if (!isset($existing_columns[$col_name])) {
                    $sql = "ALTER TABLE $table_name ADD COLUMN $col_name $col_def";
                    if ($conn->query($sql)) {
                        logResult("Added column '$col_name' to table '$table_name'", 'success');
                    } else {
                        logError("Failed to add column '$col_name' to table '$table_name': " . $conn->error);
                    }
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
            
            $sql = "CREATE TABLE $table_name (\n  " . implode(",\n  ", $columns_sql) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci";
            
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
    }
    
    closeDbConnection($conn);
    logResult("Database structure check completed", 'success');
    
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
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
            
            <a href="admin.php" class="btn">← Back to Admin Panel</a>
            <a href="dbjavito.php" class="btn" style="background: #0e6027;">↻ Run Again</a>
        </div>
    </div>
</body>
</html>
