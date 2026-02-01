<?php
/**
 * Group Module Configuration
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

$error = '';
$success = '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/index.php');
    exit();
}

$group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// Get user and group info
$group = null;
$company_id = null;

try {
    $conn = getDbConnection();
    
    // Get user's company
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $company_id = $user['company_id'];
    $stmt->close();
    
    // Get group info
    $stmt = $conn->prepare("SELECT * FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    // Check permissions
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        header('Location: groups.php');
        exit();
    }
    
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Check if group_modules table exists, if not create it
try {
    $conn->query("CREATE TABLE IF NOT EXISTS group_modules (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id INT(11) NOT NULL,
        module_id INT(11) NOT NULL,
        display_order INT(11) DEFAULT 0,
        duration_seconds INT(11) DEFAULT 10,
        settings TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Handle module configuration save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_modules'])) {
    $modules_config = $_POST['modules'] ?? [];
    
    try {
        // Delete existing module configurations for this group
        $stmt = $conn->prepare("DELETE FROM group_modules WHERE group_id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        
        // Insert new configurations
        foreach ($modules_config as $module_id => $config) {
            if (!empty($config['enabled'])) {
                $display_order = intval($config['display_order'] ?? 0);
                $duration_seconds = intval($config['duration_seconds'] ?? 10);
                $settings = json_encode($config['settings'] ?? []);
                
                $stmt = $conn->prepare("INSERT INTO group_modules (group_id, module_id, display_order, duration_seconds, settings, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("iiiis", $group_id, $module_id, $display_order, $duration_seconds, $settings);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $success = 'Module configuration saved successfully for group';
        
    } catch (Exception $e) {
        $error = 'Failed to save configuration';
        error_log($e->getMessage());
    }
}

// Get available modules for this company
$available_modules = [];
try {
    $query = "SELECT m.*, ml.quantity,
              COALESCE((SELECT COUNT(*) FROM kiosk_modules km 
                        JOIN kiosks k ON km.kiosk_id = k.id 
                        WHERE km.module_id = m.id AND k.company_id = ? AND km.is_active = 1), 0) as used_count
              FROM modules m 
              LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
              WHERE m.is_active = 1
              ORDER BY m.name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $company_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $available_modules[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Get current module configuration for this group
$current_modules = [];
try {
    $query = "SELECT gm.*, m.name, m.module_key, m.description 
              FROM group_modules gm
              JOIN modules m ON gm.module_id = m.id
              WHERE gm.group_id = ? AND gm.is_active = 1
              ORDER BY gm.display_order";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $current_modules[$row['module_id']] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Load module configuration schemas
$module_schemas = [];
foreach ($available_modules as $module) {
    $config_file = "/home/runner/work/edudisplej/edudisplej/webserver/server_edudisplej_sk/modules/{$module['module_key']}/configure.json";
    if (file_exists($config_file)) {
        $config_content = file_get_contents($config_file);
        $module_schemas[$module['id']] = json_decode($config_content, true);
    }
}

// Get kiosks in this group
$group_kiosks = [];
try {
    $query = "SELECT k.* FROM kiosks k
              JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
              WHERE kga.group_id = ?
              ORDER BY k.hostname";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $group_kiosks[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

closeDbConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Group Modules - EduDisplej</title>
    <link rel="stylesheet" href="../admin/style.css">
    <style>
        .module-config-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .module-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }
        
        .module-settings {
            display: none;
            margin-top: 15px;
        }
        
        .module-settings.active {
            display: block;
        }
        
        .setting-row {
            margin-bottom: 15px;
        }
        
        .setting-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        
        .setting-row input,
        .setting-row select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .flow-preview {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border: 2px solid #667eea;
        }
        
        .flow-chain {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .flow-module {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            border: 2px solid #667eea;
            font-weight: 600;
            color: #667eea;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 120px;
        }
        
        .flow-duration {
            font-size: 12px;
            color: #999;
            font-weight: normal;
            margin-top: 5px;
        }
        
        .flow-arrow {
            font-size: 24px;
            color: #667eea;
        }
        
        .flow-loop {
            font-size: 24px;
            color: #11998e;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>⚙️ Configure Group Modules</h1>
        <div style="display: flex; gap: 15px;">
            <a href="groups.php">← Back to Groups</a>
            <a href="group_flow_preview.php?id=<?php echo $group_id; ?>" class="btn btn-sm">🔄 Preview Module Loop</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h2>Group: <?php echo htmlspecialchars($group['name']); ?></h2>
            <p style="color: #666; margin-top: 10px;">
                Configure which modules should display on all <?php echo count($group_kiosks); ?> kiosk(s) in this group.
                Modules will rotate in the order specified below.
            </p>
        </div>
        
        <?php 
        // Build flow preview
        $flow_modules = [];
        foreach ($current_modules as $mod) {
            $flow_modules[] = $mod;
        }
        usort($flow_modules, function($a, $b) {
            return $a['display_order'] - $b['display_order'];
        });
        
        if (!empty($flow_modules)): 
        ?>
        <div class="flow-preview">
            <h3 style="margin-bottom: 15px;">📊 Module Flow Chain</h3>
            <div class="flow-chain">
                <?php foreach ($flow_modules as $idx => $mod): ?>
                    <div class="flow-module">
                        <div><?php echo htmlspecialchars($mod['name']); ?></div>
                        <div class="flow-duration"><?php echo $mod['duration_seconds']; ?>s</div>
                    </div>
                    <?php if ($idx < count($flow_modules) - 1): ?>
                        <div class="flow-arrow">→</div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="flow-loop">↻ Loop</div>
            </div>
            <p style="margin-top: 15px; color: #666; font-size: 14px;">
                Total cycle time: <?php echo array_sum(array_column($flow_modules, 'duration_seconds')); ?> seconds
            </p>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <?php foreach ($available_modules as $module): 
                $is_enabled = isset($current_modules[$module['id']]);
                $has_license = $module['quantity'] > 0 || $is_enabled;
                $current_config = $current_modules[$module['id']] ?? [];
                $schema = $module_schemas[$module['id']] ?? null;
            ?>
                <div class="module-config-card">
                    <div class="module-toggle">
                        <div>
                            <h3 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($module['name']); ?></h3>
                            <small style="color: #999;"><?php echo htmlspecialchars($module['description'] ?? ''); ?></small>
                        </div>
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input 
                                type="checkbox" 
                                name="modules[<?php echo $module['id']; ?>][enabled]"
                                <?php echo $is_enabled ? 'checked' : ''; ?>
                                onchange="toggleModuleSettings(this, <?php echo $module['id']; ?>)"
                            >
                            <span>Enable</span>
                        </label>
                    </div>
                    
                    <div class="module-settings <?php echo $is_enabled ? 'active' : ''; ?>" id="settings-<?php echo $module['id']; ?>">
                        <div class="setting-row">
                            <label>Display Order:</label>
                            <input 
                                type="number" 
                                name="modules[<?php echo $module['id']; ?>][display_order]"
                                value="<?php echo $current_config['display_order'] ?? 0; ?>"
                                min="0"
                                max="100"
                            >
                            <small style="color: #999;">Lower numbers display first</small>
                        </div>
                        
                        <div class="setting-row">
                            <label>Duration (seconds):</label>
                            <input 
                                type="number" 
                                name="modules[<?php echo $module['id']; ?>][duration_seconds]"
                                value="<?php echo $current_config['duration_seconds'] ?? 10; ?>"
                                min="1"
                                max="3600"
                            >
                            <small style="color: #999;">How long this module displays before moving to the next</small>
                        </div>
                        
                        <?php if ($schema && !empty($schema['settings'])): 
                            $current_settings = !empty($current_config['settings']) ? json_decode($current_config['settings'], true) : [];
                        ?>
                            <h4 style="margin: 20px 0 10px 0;">Module Settings:</h4>
                            <?php foreach ($schema['settings'] as $key => $setting): 
                                $value = $current_settings[$key] ?? ($setting['default'] ?? '');
                            ?>
                                <div class="setting-row">
                                    <label><?php echo htmlspecialchars($setting['label'] ?? $key); ?>:</label>
                                    <?php if ($setting['type'] === 'select'): ?>
                                        <select name="modules[<?php echo $module['id']; ?>][settings][<?php echo $key; ?>]">
                                            <?php foreach ($setting['options'] as $option): ?>
                                                <option value="<?php echo htmlspecialchars($option['value']); ?>" <?php echo $value == $option['value'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($option['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($setting['type'] === 'boolean'): ?>
                                        <select name="modules[<?php echo $module['id']; ?>][settings][<?php echo $key; ?>]">
                                            <option value="true" <?php echo $value ? 'selected' : ''; ?>>Yes</option>
                                            <option value="false" <?php echo !$value ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    <?php elseif ($setting['type'] === 'color'): ?>
                                        <input 
                                            type="color" 
                                            name="modules[<?php echo $module['id']; ?>][settings][<?php echo $key; ?>]"
                                            value="<?php echo htmlspecialchars($value); ?>"
                                        >
                                    <?php else: ?>
                                        <input 
                                            type="text" 
                                            name="modules[<?php echo $module['id']; ?>][settings][<?php echo $key; ?>]"
                                            value="<?php echo htmlspecialchars($value); ?>"
                                        >
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <button type="submit" name="save_modules" class="btn">💾 Save Group Configuration</button>
        </form>
        
        <?php if (!empty($group_kiosks)): ?>
            <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 30px;">
                <h3>Kiosks in this Group (<?php echo count($group_kiosks); ?>)</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                    <?php foreach ($group_kiosks as $kiosk): ?>
                        <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px;">
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></div>
                            <small style="color: #999;">ID: <?php echo $kiosk['id']; ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleModuleSettings(checkbox, moduleId) {
            const settings = document.getElementById('settings-' + moduleId);
            if (checkbox.checked) {
                settings.classList.add('active');
            } else {
                settings.classList.remove('active');
            }
        }
    </script>
</body>
</html>
