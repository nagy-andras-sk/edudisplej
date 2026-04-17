<?php
/**
 * API - Get/Save Group Calendar Events
 */
session_start();
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../../auth_roles.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

function edudisplej_ensure_group_calendar_events_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_calendar_events (
        group_id INT PRIMARY KEY,
        event_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    $conn = getDbConnection();
    edudisplej_ensure_group_calendar_events_schema($conn);

    $group_id = (int)($_REQUEST['group_id'] ?? 0);
    if ($group_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing group_id']);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT event_json, updated_at FROM kiosk_group_calendar_events WHERE group_id = ? LIMIT 1");
        $stmt->bind_param('i', $group_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $decoded = [];
        if ($row && !empty($row['event_json'])) {
            $maybe = json_decode((string)$row['event_json'], true);
            if (is_array($maybe)) {
                $decoded = $maybe;
            }
        }

        echo json_encode([
            'success' => true,
            'group_id' => $group_id,
            'specialStyles' => array_values(is_array($decoded['specialStyles'] ?? null) ? $decoded['specialStyles'] : []),
            'specialBlocks' => array_values(is_array($decoded['specialBlocks'] ?? null) ? $decoded['specialBlocks'] : []),
            'updated_at' => $row['updated_at'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Invalid payload']);
            exit();
        }

        $specialStyles = is_array($payload['specialStyles'] ?? null) ? $payload['specialStyles'] : [];
        $specialBlocks = is_array($payload['specialBlocks'] ?? null) ? $payload['specialBlocks'] : [];
        $eventJson = json_encode([
            'specialStyles' => $specialStyles,
            'specialBlocks' => $specialBlocks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($eventJson === false) {
            echo json_encode(['success' => false, 'message' => 'Failed to encode calendar events']);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO kiosk_group_calendar_events (group_id, event_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE event_json = VALUES(event_json), updated_at = CURRENT_TIMESTAMP");
        $stmt->bind_param('is', $group_id, $eventJson);
        $stmt->execute();
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'message' => 'Calendar events saved']);
        exit();
    }

    closeDbConnection($conn);
    echo json_encode(['success' => false, 'message' => 'Unsupported method']);
} catch (Throwable $e) {
    error_log('group_calendar/events.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
