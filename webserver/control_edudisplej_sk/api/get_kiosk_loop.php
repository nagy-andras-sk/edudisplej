<?php
/**
 * API - Get Kiosk Loop Configuration
 * Returns the loop configuration for a specific kiosk
 * Based on the group(s) the kiosk belongs to
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

$kiosk_id = intval($_GET['kiosk_id'] ?? 0);

try {
    $conn = getDbConnection();
    
    // Check permissions - verify kiosk belongs to user's company
    $stmt = $conn->prepare("SELECT company_id FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    $stmt->close();
    
    if (!$kiosk || (!$is_admin && $kiosk['company_id'] != $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
        exit();
    }
    
    // Get kiosk's group(s) and their loop configurations
    // Priority: specific kiosk_modules > group modules
    
    // First check if kiosk has specific modules assigned
    $stmt = $conn->prepare("SELECT km.*, m.name as module_name, m.module_key, m.description
                            FROM kiosk_modules km
                            JOIN modules m ON km.module_id = m.id
                            WHERE km.kiosk_id = ? AND km.is_active = 1
                            ORDER BY km.display_order");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loops = [];
    while ($row = $result->fetch_assoc()) {
        $loops[] = [
            'module_id' => $row['module_id'],
            'module_name' => $row['module_name'],
            'module_key' => $row['module_key'],
            'description' => $row['description'],
            'duration_seconds' => $row['duration_seconds'],
            'display_order' => $row['display_order'],
            'source' => 'kiosk'
        ];
    }
    $stmt->close();
    
    // If no specific modules, get from group(s)
    if (empty($loops)) {
        $stmt = $conn->prepare("SELECT kgm.*, m.name as module_name, m.module_key, m.description,
                                MAX(kgm.updated_at) as group_updated_at
                                FROM kiosk_group_assignments kga
                                JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
                                JOIN modules m ON kgm.module_id = m.id
                                WHERE kga.kiosk_id = ? AND kgm.is_active = 1
                                GROUP BY kgm.id, kgm.group_id, kgm.module_id, kgm.duration_seconds, kgm.display_order, kgm.is_active
                                ORDER BY kgm.display_order");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $group_updated_at = null;
        while ($row = $result->fetch_assoc()) {
            $loops[] = [
                'module_id' => $row['module_id'],
                'module_name' => $row['module_name'],
                'module_key' => $row['module_key'],
                'description' => $row['description'],
                'duration_seconds' => $row['duration_seconds'],
                'display_order' => $row['display_order'],
                'source' => 'group'
            ];
            // Get latest updated_at timestamp
            if ($row['group_updated_at'] && (!$group_updated_at || $row['group_updated_at'] > $group_updated_at)) {
                $group_updated_at = $row['group_updated_at'];
            }
        }
        $stmt->close();
    }
    
    closeDbConnection($conn);
    
    $response = ['success' => true, 'loops' => $loops];
    
    // Add loop version info (latest updated_at timestamp)
    if (!empty($loops)) {
        $response['loop_updated_at'] = $group_updated_at ?? date('Y-m-d H:i:s');
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbázis hiba']);
}
?>
