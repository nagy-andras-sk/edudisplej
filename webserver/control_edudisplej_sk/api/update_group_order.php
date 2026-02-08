<?php
/**
 * API - Update Group Order
 */
session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

if (!$company_id) {
    echo json_encode(['success' => false, 'message' => 'No company assigned']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
$ordered_ids = $payload['ordered_ids'] ?? [];

if (!is_array($ordered_ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit();
}

try {
    $conn = getDbConnection();

    $default_check = $conn->query("SHOW COLUMNS FROM kiosk_groups LIKE 'is_default'");
    if ($default_check && $default_check->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
    }

    $priority_check = $conn->query("SHOW COLUMNS FROM kiosk_groups LIKE 'priority'");
    if ($priority_check && $priority_check->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN priority INT(11) NOT NULL DEFAULT 0");
    }

    $stmt = $conn->prepare("SELECT id, is_default FROM kiosk_groups WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $all_groups = [];
    $default_group_id = null;
    while ($row = $result->fetch_assoc()) {
        $all_groups[] = (int)$row['id'];
        if (!empty($row['is_default'])) {
            $default_group_id = (int)$row['id'];
        }
    }
    $stmt->close();

    if (!$is_admin && empty($all_groups)) {
        echo json_encode(['success' => false, 'message' => 'Hozzaferes megtagadva']);
        exit();
    }

    $non_default_ids = array_filter($all_groups, function ($id) use ($default_group_id) {
        return $default_group_id ? $id !== $default_group_id : true;
    });

    $ordered_ids = array_values(array_map('intval', $ordered_ids));

    sort($ordered_ids);
    $sorted_non_default = array_values($non_default_ids);
    sort($sorted_non_default);

    if ($ordered_ids !== $sorted_non_default) {
        echo json_encode(['success' => false, 'message' => 'Hibas csoportsorrend']);
        exit();
    }

    $total_count = count($all_groups);
    if ($total_count === 0) {
        echo json_encode(['success' => false, 'message' => 'Nincs csoport']);
        exit();
    }

    $conn->begin_transaction();

    // Reapply order from payload (top -> bottom)
    $payload_order = $payload['ordered_ids'] ?? [];
    $payload_order = array_values(array_map('intval', $payload_order));

    $priority = $total_count;
    foreach ($payload_order as $group_id) {
        $stmt = $conn->prepare("UPDATE kiosk_groups SET priority = ? WHERE id = ? AND company_id = ?");
        $stmt->bind_param("iii", $priority, $group_id, $company_id);
        $stmt->execute();
        $stmt->close();
        $priority--;
        if ($priority === 1 && $default_group_id) {
            $priority--;
        }
    }

    if ($default_group_id) {
        $stmt = $conn->prepare("UPDATE kiosk_groups SET priority = 1 WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $default_group_id, $company_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    closeDbConnection($conn);

    echo json_encode(['success' => true, 'message' => 'Csoportsorrend frissitve']);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbazis hiba']);
}
?>
