<?php
/**
 * Kiosk Module Management API
 * Handles adding/removing/reordering modules for kiosks
 */

session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$response = ['success' => false, 'message' => ''];

try {
    $action = $_GET['action'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true);
    
    $conn = getDbConnection();
    
    switch ($action) {
        case 'get_modules':
            // Get all available modules
            $result = $conn->query("SELECT * FROM modules WHERE is_active = 1 ORDER BY name");
            $modules = [];
            while ($row = $result->fetch_assoc()) {
                $modules[] = $row;
            }
            $response['success'] = true;
            $response['modules'] = $modules;
            break;
            
        case 'get_kiosk_modules':
            // Get modules assigned to a kiosk
            $kiosk_id = intval($data['kiosk_id'] ?? 0);
            
            if ($kiosk_id <= 0) {
                $response['message'] = 'Invalid kiosk ID';
                break;
            }
            
            $stmt = $conn->prepare("SELECT km.*, m.name, m.module_key, m.description 
                                    FROM kiosk_modules km 
                                    JOIN modules m ON km.module_id = m.id 
                                    WHERE km.kiosk_id = ? 
                                    ORDER BY km.display_order ASC");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $modules = [];
            while ($row = $result->fetch_assoc()) {
                $row['settings'] = json_decode($row['settings'] ?? '{}', true);
                $modules[] = $row;
            }
            $stmt->close();
            
            $response['success'] = true;
            $response['modules'] = $modules;
            break;
            
        case 'add_module':
            // Add a module to a kiosk
            $kiosk_id = intval($data['kiosk_id'] ?? 0);
            $module_id = intval($data['module_id'] ?? 0);
            $duration = intval($data['duration_seconds'] ?? 10);
            $settings = json_encode($data['settings'] ?? []);
            
            if ($kiosk_id <= 0 || $module_id <= 0) {
                $response['message'] = 'Invalid parameters';
                break;
            }
            
            // Check if module already assigned
            $stmt = $conn->prepare("SELECT id FROM kiosk_modules WHERE kiosk_id = ? AND module_id = ?");
            $stmt->bind_param("ii", $kiosk_id, $module_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $response['message'] = 'Module already assigned to this kiosk';
                $stmt->close();
                break;
            }
            $stmt->close();
            
            // Get next display order
            $stmt = $conn->prepare("SELECT MAX(display_order) as max_order FROM kiosk_modules WHERE kiosk_id = ?");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $next_order = ($row['max_order'] ?? -1) + 1;
            $stmt->close();
            
            // Insert module
            $stmt = $conn->prepare("INSERT INTO kiosk_modules (kiosk_id, module_id, display_order, duration_seconds, settings) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiis", $kiosk_id, $module_id, $next_order, $duration, $settings);
            
            if ($stmt->execute()) {
                // Mark kiosk as configured
                $update_stmt = $conn->prepare("UPDATE kiosks SET is_configured = 1 WHERE id = ?");
                $update_stmt->bind_param("i", $kiosk_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                $response['success'] = true;
                $response['message'] = 'Module added successfully';
            } else {
                $response['message'] = 'Failed to add module';
            }
            $stmt->close();
            break;
            
        case 'remove_module':
            // Remove a module from a kiosk
            $kiosk_module_id = intval($data['kiosk_module_id'] ?? 0);
            
            if ($kiosk_module_id <= 0) {
                $response['message'] = 'Invalid module ID';
                break;
            }
            
            $stmt = $conn->prepare("DELETE FROM kiosk_modules WHERE id = ?");
            $stmt->bind_param("i", $kiosk_module_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Module removed successfully';
            } else {
                $response['message'] = 'Failed to remove module';
            }
            $stmt->close();
            break;
            
        case 'update_order':
            // Update module display order
            $kiosk_id = intval($data['kiosk_id'] ?? 0);
            $order = $data['order'] ?? [];
            
            if ($kiosk_id <= 0 || empty($order)) {
                $response['message'] = 'Invalid parameters';
                break;
            }
            
            $conn->begin_transaction();
            
            try {
                foreach ($order as $index => $module_id) {
                    $stmt = $conn->prepare("UPDATE kiosk_modules SET display_order = ? WHERE id = ? AND kiosk_id = ?");
                    $stmt->bind_param("iii", $index, $module_id, $kiosk_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $conn->commit();
                $response['success'] = true;
                $response['message'] = 'Order updated successfully';
            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Failed to update order: ' . $e->getMessage();
            }
            break;
            
        case 'update_settings':
            // Update module settings
            $kiosk_module_id = intval($data['kiosk_module_id'] ?? 0);
            $duration = intval($data['duration_seconds'] ?? 10);
            $settings = json_encode($data['settings'] ?? []);
            
            if ($kiosk_module_id <= 0) {
                $response['message'] = 'Invalid module ID';
                break;
            }
            
            $stmt = $conn->prepare("UPDATE kiosk_modules SET duration_seconds = ?, settings = ? WHERE id = ?");
            $stmt->bind_param("isi", $duration, $settings, $kiosk_module_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Settings updated successfully';
            } else {
                $response['message'] = 'Failed to update settings';
            }
            $stmt->close();
            break;
            
        default:
            $response['message'] = 'Invalid action';
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>
