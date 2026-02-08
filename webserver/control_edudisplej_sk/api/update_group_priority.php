<?php
/**
 * API - Update Group Priority
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
$priority = intval($_POST['priority'] ?? 0);

if (!$group_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid group id']);
    exit();
}

try {
    $conn = getDbConnection();

    $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();

    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzaferes megtagadva']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE kiosk_groups SET priority = ? WHERE id = ?");
    $stmt->bind_param("ii", $priority, $group_id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Prioritas frissitve']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Prioritas frissitese sikertelen']);
    }

    $stmt->close();
    closeDbConnection($conn);

} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbazis hiba']);
}
?>
