<?php
/**
 * API - Rename Group
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$group_id = intval($_POST['group_id'] ?? 0);
$new_name = trim($_POST['new_name'] ?? '');

if (empty($new_name)) {
    echo json_encode(['success' => false, 'message' => 'A csoport neve nem lehet üres']);
    exit();
}

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
    
    // Update group name
    $stmt = $conn->prepare("UPDATE kiosk_groups SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $new_name, $group_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Csoport sikeresen átnevezve']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Átnevezés sikertelen']);
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbázis hiba']);
}
?>
