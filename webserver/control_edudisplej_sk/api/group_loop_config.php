<?php
/**
 * API - Get/Save Group Loop Configuration
 */
session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

$group_id = intval($_REQUEST['group_id'] ?? 0);

try {
    $conn = getDbConnection();

    $default_check = $conn->query("SHOW COLUMNS FROM kiosk_groups LIKE 'is_default'");
    if ($default_check && $default_check->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
    }
    
    // Check permissions
    $stmt = $conn->prepare("SELECT company_id, is_default, name FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
        exit();
    }

    $is_default_group = (!empty($group['is_default']) || strtolower($group['name']) === 'default');

    $unconfigured_stmt = $conn->prepare("SELECT id, name, description, module_key FROM modules WHERE module_key = 'unconfigured' LIMIT 1");
    $unconfigured_stmt->execute();
    $unconfigured_result = $unconfigured_stmt->get_result();
    $unconfigured_module = $unconfigured_result->fetch_assoc();
    $unconfigured_stmt->close();
    
    // GET - Retrieve loop configuration
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($is_default_group) {
            if (!$unconfigured_module) {
                echo json_encode(['success' => false, 'message' => 'Az unconfigured modul nem elerheto']);
                exit();
            }

            echo json_encode([
                'success' => true,
                'loops' => [[
                    'id' => null,
                    'module_id' => (int)$unconfigured_module['id'],
                    'module_name' => $unconfigured_module['name'],
                    'module_key' => 'unconfigured',
                    'description' => $unconfigured_module['description'] ?? '',
                    'duration_seconds' => 60,
                    'display_order' => 0,
                    'settings' => new stdClass(),
                    'is_active' => 1
                ]]
            ]);
            exit();
        }

        $stmt = $conn->prepare("SELECT kgm.*, m.name as module_name, m.module_key, m.description
                                FROM kiosk_group_modules kgm
                                JOIN modules m ON kgm.module_id = m.id
                                WHERE kgm.group_id = ?
                                ORDER BY kgm.display_order");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $loops = [];
        while ($row = $result->fetch_assoc()) {
            $duration_seconds = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration_seconds = 60;
            }
            $loops[] = [
                'id' => $row['id'],
                'module_id' => $row['module_id'],
                'module_name' => $row['module_name'],
                'module_key' => $row['module_key'],
                'description' => $row['description'],
                'duration_seconds' => $duration_seconds,
                'display_order' => $row['display_order'],
                'settings' => $row['settings'] ? json_decode($row['settings'], true) : null,
                'is_active' => $row['is_active']
            ];
        }
        
        $stmt->close();
        echo json_encode(['success' => true, 'loops' => $loops]);
    }
    
    // POST - Save loop configuration
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $loops = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($loops)) {
            echo json_encode(['success' => false, 'message' => 'Hibás loop konfiguráció']);
            exit();
        }

        if ($is_default_group) {
            echo json_encode(['success' => false, 'message' => 'A default csoport loopja nem szerkesztheto']);
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete existing loops
            $stmt = $conn->prepare("DELETE FROM kiosk_group_modules WHERE group_id = ?");
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            $stmt->close();
            
            // Insert new loops
            foreach ($loops as $index => $loop) {
                $module_id = intval($loop['module_id']);
                $duration = intval($loop['duration_seconds'] ?? 10);
                $module_key = strtolower(trim((string)($loop['module_key'] ?? '')));
                if ($module_key === 'unconfigured') {
                    $duration = 60;
                }
                $settings = isset($loop['settings']) ? json_encode($loop['settings']) : null;
                $display_order = $index;
                
                $stmt = $conn->prepare("INSERT INTO kiosk_group_modules 
                                        (group_id, module_id, module_key, display_order, duration_seconds, settings, is_active) 
                                        VALUES (?, ?, (SELECT module_key FROM modules WHERE id = ?), ?, ?, ?, 1)");
                $stmt->bind_param("iiiiss", $group_id, $module_id, $module_id, $display_order, $duration, $settings);
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Loop konfiguráció sikeresen mentve']);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbázis hiba: ' . $e->getMessage()]);
}
?>
