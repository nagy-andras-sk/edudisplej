<?php
/**
 * API - Get Group Kiosks
 */
session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

$group_id = intval($_GET['group_id'] ?? 0);

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
    
    // Get kiosks in this group
    $stmt = $conn->prepare("SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, k.last_seen, k.screen_resolution 
                            FROM kiosks k
                            JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                            WHERE kga.group_id = ?
                            ORDER BY k.hostname");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $kiosks = [];
    while ($row = $result->fetch_assoc()) {
        kiosk_apply_effective_status($row);
        $kiosks[] = $row;
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
    echo json_encode(['success' => true, 'kiosks' => $kiosks]);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbázis hiba']);
}
?>
