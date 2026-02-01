<?php
/**
 * Kiosk Module Configuration
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

$kiosk_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// Get user and kiosk info
$kiosk = null;
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
    
    // Get kiosk info
    $stmt = $conn->prepare("SELECT * FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    $stmt->close();
    
    // Check permissions
    if (!$kiosk || (!$is_admin && $kiosk['company_id'] != $company_id)) {
        header('Location: index.php');
        exit();
    }
    
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Handle module configuration save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_modules'])) {
    $modules_config = $_POST['modules'] ?? [];
    
    try {
        // Delete existing module configurations
        $stmt = $conn->prepare("DELETE FROM kiosk_modules WHERE kiosk_id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $stmt->close();
        
        // Insert new configurations
        foreach ($modules_config as $module_id => $config) {
            if (!empty($config['enabled'])) {
                $display_order = intval($config['display_order'] ?? 0);
                $duration_seconds = intval($config['duration_seconds'] ?? 10);
                $settings = json_encode($config['settings'] ?? []);
                
                $stmt = $conn->prepare("INSERT INTO kiosk_modules (kiosk_id, module_id, display_order, duration_seconds, settings, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("iiiis", $kiosk_id, $module_id, $display_order, $duration_seconds, $settings);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $success = 'Module configuration saved successfully';
        
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

// Get current module configuration
$current_modules = [];
try {
    $query = "SELECT km.*, m.name, m.module_key, m.description 
              FROM kiosk_modules km
              JOIN modules m ON km.module_id = m.id
              WHERE km.kiosk_id = ? AND km.is_active = 1
              ORDER BY km.display_order";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $kiosk_id);
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

closeDbConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Kiosk Modules - EduDisplej</title>
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
        
        .license-warning {
            padding: 10px;
            background: #fff3cd;
            color: #856404;
            border-radius: 5px;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>⚙️ Configure Kiosk Modules</h1>
        <a href="index.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h2>Kiosk: <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></h2>
            <p style="color: #666; margin-top: 10px;">
                Configure which modules should display on this kiosk and customize their settings.
            </p>
        </div>
        
        <form method="POST">
            <?php foreach ($available_modules as $module): 
                $is_enabled = isset($current_modules[$module['id']]);
                $has_license = $module['quantity'] > 0 || $is_enabled;
                $can_enable = $has_license && ($module['used_count'] < $module['quantity'] || $is_enabled);
                $current_config = $current_modules[$module['id']] ?? [];
                $schema = $module_schemas[$module['id']] ?? null;
            ?>
                <div class="module-config-card">
                    <div class="module-toggle">
                        <div>
                            <h3 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($module['name']); ?></h3>
                            <small style="color: #999;"><?php echo htmlspecialchars($module['description'] ?? ''); ?></small>
                            <?php if (!$has_license): ?>
                                <div class="license-warning">⚠️ No license available for this module</div>
                            <?php elseif (!$can_enable && !$is_enabled): ?>
                                <div class="license-warning">⚠️ All licenses in use (<?php echo $module['used_count']; ?>/<?php echo $module['quantity']; ?>)</div>
                            <?php endif; ?>
                        </div>
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input 
                                type="checkbox" 
                                name="modules[<?php echo $module['id']; ?>][enabled]"
                                <?php echo $is_enabled ? 'checked' : ''; ?>
                                <?php echo !$can_enable && !$is_enabled ? 'disabled' : ''; ?>
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
            
            <button type="submit" name="save_modules" class="btn">💾 Save Configuration</button>
        </form>
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

