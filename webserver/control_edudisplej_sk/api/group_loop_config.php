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
    
    // Check permissions
    $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
        exit();
    }
    
    // GET - Retrieve loop configuration
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
            $loops[] = [
                'id' => $row['id'],
                'module_id' => $row['module_id'],
                'module_name' => $row['module_name'],
                'module_key' => $row['module_key'],
                'description' => $row['description'],
                'duration_seconds' => $row['duration_seconds'],
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
