<?php
/**
 * API - Room Occupancy (Terem foglaltság)
 * Public read + authenticated admin CRUD + token-based external sync.
 */
session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';

header('Content-Type: application/json; charset=utf-8');

function edudisplej_room_occ_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        room_key VARCHAR(120) NOT NULL,
        room_name VARCHAR(220) NOT NULL,
        capacity INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_room_key (company_id, room_key),
        INDEX idx_company_active (company_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        room_id INT NOT NULL,
        event_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        event_title VARCHAR(260) NOT NULL,
        event_note TEXT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        external_ref VARCHAR(160) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_room_date (company_id, room_id, event_date),
        UNIQUE KEY uq_external_event (company_id, room_id, event_date, external_ref),
        UNIQUE KEY uq_manual_slot (company_id, room_id, event_date, start_time, end_time, event_title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_servers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_key VARCHAR(100) NOT NULL,
        server_name VARCHAR(180) NOT NULL,
        endpoint_base_url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_server_key (server_key),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_server_company_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_id INT NOT NULL,
        company_id INT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_server_company (server_id, company_id),
        INDEX idx_company_active (company_id, is_active),
        CONSTRAINT fk_room_occ_link_server FOREIGN KEY (server_id) REFERENCES room_occupancy_servers(id) ON DELETE CASCADE,
        CONSTRAINT fk_room_occ_link_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_room_occ_can_admin(): bool {
    return isset($_SESSION['user_id']) && edudisplej_can_edit_module_content();
}

function edudisplej_room_occ_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function edudisplej_room_occ_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function edudisplej_room_occ_company_id_from_session(): int {
    $cid = (int)($_SESSION['company_id'] ?? 0);
    if ($cid > 0) {
        return $cid;
    }
    $acting = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    return $acting > 0 ? $acting : 0;
}

function edudisplej_room_occ_company_id_public(): int {
    return (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
}

function edudisplej_room_occ_trim($value, int $max = 1000): string {
    $text = trim((string)$value);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    if (strlen($text) > $max) {
        return substr($text, 0, $max);
    }
    return $text;
}

function edudisplej_room_occ_room_key($value): string {
    $key = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9._-]/', '', $key);
}

function edudisplej_room_occ_event_date($value): string {
    $raw = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    return date('Y-m-d');
}

function edudisplej_room_occ_time($value): string {
    $raw = trim((string)$value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $raw, $matches)) {
        $hour = str_pad((string)((int)$matches[1]), 2, '0', STR_PAD_LEFT);
        $minute = (string)$matches[2];
        $second = '00';
        if (!empty($matches[3])) {
            $second = ltrim((string)$matches[3], ':');
        }
        return $hour . ':' . $minute . ':' . $second;
    }
    return '';
}

function edudisplej_room_occ_server_key($value): string {
    $key = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9._-]/', '', $key);
}

function edudisplej_room_occ_verify_server_company_link(mysqli $conn, int $companyId, string $serverKey): bool {
    if ($companyId <= 0 || $serverKey === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT l.id
                            FROM room_occupancy_server_company_links l
                            INNER JOIN room_occupancy_servers s ON s.id = l.server_id
                            WHERE l.company_id = ? AND l.is_active = 1 AND s.is_active = 1 AND s.server_key = ?
                            LIMIT 1");
    $stmt->bind_param('is', $companyId, $serverKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row);
}

function edudisplej_room_occ_find_room(mysqli $conn, int $companyId, int $roomId): ?array {
    $stmt = $conn->prepare("SELECT id, room_key, room_name, capacity, is_active FROM room_occupancy_rooms WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param('ii', $roomId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function edudisplej_room_occ_verify_company_token(mysqli $conn, int $companyId, string $token): bool {
    if ($companyId <= 0 || $token === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT api_token FROM companies WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stored = trim((string)($row['api_token'] ?? ''));
    if ($stored === '') {
        return false;
    }

    return hash_equals($stored, $token);
}

function edudisplej_room_occ_external_token(): string {
    $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
    $token = trim((string)($headers['X-API-Token'] ?? $headers['x-api-token'] ?? $_GET['token'] ?? $_POST['token'] ?? ''));
    return $token;
}

function edudisplej_room_occ_find_or_create_room(mysqli $conn, int $companyId, string $roomKey, string $roomName): int {
    $cleanKey = edudisplej_room_occ_room_key($roomKey);
    if ($cleanKey === '') {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id FROM room_occupancy_rooms WHERE company_id = ? AND room_key = ? LIMIT 1");
    $stmt->bind_param('is', $companyId, $cleanKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $safeName = edudisplej_room_occ_trim($roomName, 220);
    if ($safeName === '') {
        $safeName = strtoupper($cleanKey);
    }

    $insert = $conn->prepare("INSERT INTO room_occupancy_rooms (company_id, room_key, room_name, capacity, is_active) VALUES (?, ?, ?, 0, 1)");
    $insert->bind_param('iss', $companyId, $cleanKey, $safeName);
    $insert->execute();
    $id = (int)$insert->insert_id;
    $insert->close();

    return $id;
}

function edudisplej_room_occ_has_overlap(mysqli $conn, int $companyId, int $roomId, string $eventDate, string $startTime, string $endTime, int $excludeId = 0): bool {
        if ($excludeId > 0) {
                $stmt = $conn->prepare("SELECT id
                                                             FROM room_occupancy_events
                                                             WHERE company_id = ? AND room_id = ? AND event_date = ?
                                                                 AND id <> ?
                                                                 AND start_time < ? AND end_time > ?
                                                             LIMIT 1");
                $stmt->bind_param('iisiss', $companyId, $roomId, $eventDate, $excludeId, $endTime, $startTime);
        } else {
                $stmt = $conn->prepare("SELECT id
                                                             FROM room_occupancy_events
                                                             WHERE company_id = ? AND room_id = ? AND event_date = ?
                                                                 AND start_time < ? AND end_time > ?
                                                             LIMIT 1");
                $stmt->bind_param('iisss', $companyId, $roomId, $eventDate, $endTime, $startTime);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'rooms')));

try {
    $conn = getDbConnection();
    edudisplej_room_occ_ensure_schema($conn);

    if ($action === 'rooms') {
        $companyId = edudisplej_room_occ_can_admin() ? edudisplej_room_occ_company_id_from_session() : edudisplej_room_occ_company_id_public();
        if ($companyId <= 0) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $stmt = $conn->prepare("SELECT id, room_key, room_name, capacity FROM room_occupancy_rooms WHERE company_id = ? AND is_active = 1 ORDER BY room_name ASC");
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'room_key' => (string)$row['room_key'],
                'room_name' => (string)$row['room_name'],
                'capacity' => (int)($row['capacity'] ?? 0),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'schedule') {
        $companyId = edudisplej_room_occ_can_admin() ? edudisplej_room_occ_company_id_from_session() : edudisplej_room_occ_company_id_public();
        $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
        $eventDate = edudisplej_room_occ_event_date($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));

        if ($companyId <= 0 || $roomId <= 0) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'data' => ['room_name' => '', 'event_date' => $eventDate, 'events' => [], 'current_event' => null]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $room = edudisplej_room_occ_find_room($conn, $companyId, $roomId);
        if (!$room || (int)$room['is_active'] !== 1) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'data' => ['room_name' => '', 'event_date' => $eventDate, 'events' => [], 'current_event' => null]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $stmt = $conn->prepare("SELECT id, start_time, end_time, event_title, event_note, source_type, updated_at
                                FROM room_occupancy_events
                                WHERE company_id = ? AND room_id = ? AND event_date = ?
                                ORDER BY start_time ASC");
        $stmt->bind_param('iis', $companyId, $roomId, $eventDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = [
                'id' => (int)$row['id'],
                'start_time' => substr((string)$row['start_time'], 0, 5),
                'end_time' => substr((string)$row['end_time'], 0, 5),
                'event_title' => (string)$row['event_title'],
                'event_note' => (string)($row['event_note'] ?? ''),
                'source_type' => (string)($row['source_type'] ?? 'manual'),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();

        $currentEvent = null;
        $today = date('Y-m-d');
        if ($eventDate === $today) {
            $now = date('H:i:s');
            foreach ($events as $event) {
                $start = (string)$event['start_time'] . ':00';
                $end = (string)$event['end_time'] . ':00';
                if ($now >= $start && $now < $end) {
                    $currentEvent = $event;
                    break;
                }
            }
        }

        closeDbConnection($conn);
        echo json_encode([
            'success' => true,
            'data' => [
                'room_name' => (string)$room['room_name'],
                'event_date' => $eventDate,
                'events' => $events,
                'current_event' => $currentEvent,
                'is_occupied_now' => $currentEvent !== null,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'external_upsert') {
        $token = edudisplej_room_occ_external_token();
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $companyIdInput = (int)($input['company_id'] ?? 0);
        $serverKey = edudisplej_room_occ_server_key($input['server_key'] ?? '');
        if ($companyIdInput <= 0 || $token === '') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó company_id vagy token', 401);
        }

        if ($serverKey === '') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó server_key', 400);
        }

        if (!edudisplej_room_occ_verify_company_token($conn, $companyIdInput, $token)) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Érvénytelen API token', 403);
        }

        if (!edudisplej_room_occ_verify_server_company_link($conn, $companyIdInput, $serverKey)) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('A megadott szerver nincs ehhez a céghez párosítva.', 403);
        }

        $events = isset($input['events']) && is_array($input['events']) ? $input['events'] : [];
        if (empty($events)) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó events tömb');
        }

        $stored = 0;
        $conn->begin_transaction();

        $upsert = $conn->prepare("INSERT INTO room_occupancy_events
            (company_id, room_id, event_date, start_time, end_time, event_title, event_note, source_type, external_ref)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'external', ?)
            ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                event_title = VALUES(event_title),
                event_note = VALUES(event_note),
                source_type = 'external',
                updated_at = CURRENT_TIMESTAMP");

        foreach ($events as $event) {
            $roomId = (int)($event['room_id'] ?? 0);
            if ($roomId <= 0) {
                $roomKey = edudisplej_room_occ_room_key($event['room_key'] ?? '');
                $roomName = edudisplej_room_occ_trim($event['room_name'] ?? '', 220);
                $roomId = edudisplej_room_occ_find_or_create_room($conn, $companyIdInput, $roomKey, $roomName);
            }

            $eventDate = edudisplej_room_occ_event_date($event['event_date'] ?? date('Y-m-d'));
            $startTime = edudisplej_room_occ_time($event['start_time'] ?? '');
            $endTime = edudisplej_room_occ_time($event['end_time'] ?? '');
            $eventTitle = edudisplej_room_occ_trim($event['event_title'] ?? '', 260);
            $eventNote = edudisplej_room_occ_trim($event['event_note'] ?? '', 4000);
            $externalRef = edudisplej_room_occ_trim($event['external_ref'] ?? '', 160);

            if ($roomId <= 0 || $startTime === '' || $endTime === '' || $eventTitle === '' || $externalRef === '') {
                continue;
            }
            if (strcmp($startTime, $endTime) >= 0) {
                continue;
            }

            $existingStmt = $conn->prepare("SELECT id FROM room_occupancy_events
                                           WHERE company_id = ? AND room_id = ? AND event_date = ? AND external_ref = ?
                                           LIMIT 1");
            $existingStmt->bind_param('iiss', $companyIdInput, $roomId, $eventDate, $externalRef);
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
            $excludeId = (int)($existingRow['id'] ?? 0);

            if (edudisplej_room_occ_has_overlap($conn, $companyIdInput, $roomId, $eventDate, $startTime, $endTime, $excludeId)) {
                continue;
            }

            $upsert->bind_param('iissssss', $companyIdInput, $roomId, $eventDate, $startTime, $endTime, $eventTitle, $eventNote, $externalRef);
            if ($upsert->execute()) {
                $stored++;
            }
        }

        $upsert->close();
        $conn->commit();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'stored' => $stored], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if (!edudisplej_room_occ_can_admin()) {
        closeDbConnection($conn);
        edudisplej_room_occ_error('Hozzáférés megtagadva', 403);
    }

    $isSystemAdmin = !empty($_SESSION['isadmin']) && empty($_SESSION['admin_acting_company_id']);

    $companyId = edudisplej_room_occ_company_id_from_session();
    if ($companyId <= 0 && !$isSystemAdmin) {
        closeDbConnection($conn);
        edudisplej_room_occ_error('Hiányzó intézmény context', 400);
    }

    if ($action === 'admin_servers') {
        if (!$isSystemAdmin) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Csak rendszeradmin érheti el.', 403);
        }

        $result = $conn->query("SELECT id, server_key, server_name, endpoint_base_url, is_active, updated_at
                                FROM room_occupancy_servers
                                ORDER BY server_name ASC");
        $items = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $items[] = [
                    'id' => (int)$row['id'],
                    'server_key' => (string)$row['server_key'],
                    'server_name' => (string)$row['server_name'],
                    'endpoint_base_url' => (string)($row['endpoint_base_url'] ?? ''),
                    'is_active' => (int)($row['is_active'] ?? 0),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                ];
            }
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_server') {
        if (!$isSystemAdmin) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Csak rendszeradmin menthet szervert.', 403);
        }

        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $serverKey = edudisplej_room_occ_server_key($input['server_key'] ?? '');
        $serverName = edudisplej_room_occ_trim($input['server_name'] ?? '', 180);
        $endpointBaseUrl = edudisplej_room_occ_trim($input['endpoint_base_url'] ?? '', 500);
        $isActive = !empty($input['is_active']) ? 1 : 0;

        if ($serverKey === '' || $serverName === '') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó szerver kulcs vagy név.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE room_occupancy_servers
                                    SET server_key = ?, server_name = ?, endpoint_base_url = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ? LIMIT 1");
            $stmt->bind_param('sssii', $serverKey, $serverName, $endpointBaseUrl, $isActive, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO room_occupancy_servers (server_key, server_name, endpoint_base_url, is_active)
                                    VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $serverKey, $serverName, $endpointBaseUrl, $isActive);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'admin_server_links') {
        if (!$isSystemAdmin) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Csak rendszeradmin érheti el.', 403);
        }

        $result = $conn->query("SELECT
                                    l.id,
                                    l.server_id,
                                    l.company_id,
                                    l.is_active,
                                    l.updated_at,
                                    s.server_key,
                                    s.server_name,
                                    c.name AS company_name
                                FROM room_occupancy_server_company_links l
                                INNER JOIN room_occupancy_servers s ON s.id = l.server_id
                                INNER JOIN companies c ON c.id = l.company_id
                                ORDER BY s.server_name ASC, c.name ASC");

        $items = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $items[] = [
                    'id' => (int)$row['id'],
                    'server_id' => (int)$row['server_id'],
                    'company_id' => (int)$row['company_id'],
                    'server_key' => (string)$row['server_key'],
                    'server_name' => (string)$row['server_name'],
                    'company_name' => (string)$row['company_name'],
                    'is_active' => (int)($row['is_active'] ?? 0),
                    'updated_at' => (string)($row['updated_at'] ?? ''),
                ];
            }
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_server_link') {
        if (!$isSystemAdmin) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Csak rendszeradmin menthet párosítást.', 403);
        }

        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $serverId = (int)($input['server_id'] ?? 0);
        $linkCompanyId = (int)($input['company_id'] ?? 0);
        $isActive = !empty($input['is_active']) ? 1 : 0;

        if ($serverId <= 0 || $linkCompanyId <= 0) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó szerver vagy cég.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE room_occupancy_server_company_links
                                    SET server_id = ?, company_id = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ? LIMIT 1");
            $stmt->bind_param('iiii', $serverId, $linkCompanyId, $isActive, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO room_occupancy_server_company_links (server_id, company_id, is_active)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param('iii', $serverId, $linkCompanyId, $isActive);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'admin_rooms') {
        $stmt = $conn->prepare("SELECT id, room_key, room_name, capacity, is_active, updated_at FROM room_occupancy_rooms WHERE company_id = ? ORDER BY room_name ASC");
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'room_key' => (string)$row['room_key'],
                'room_name' => (string)$row['room_name'],
                'capacity' => (int)($row['capacity'] ?? 0),
                'is_active' => (int)($row['is_active'] ?? 0),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'admin_events') {
        $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
        $eventDate = edudisplej_room_occ_event_date($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
        if ($roomId <= 0) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $room = edudisplej_room_occ_find_room($conn, $companyId, $roomId);
        if (!$room) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Érvénytelen terem', 404);
        }

        $stmt = $conn->prepare("SELECT id, event_date, start_time, end_time, event_title, event_note, source_type, external_ref, updated_at
                                FROM room_occupancy_events
                                WHERE company_id = ? AND room_id = ? AND event_date = ?
                                ORDER BY start_time ASC");
        $stmt->bind_param('iis', $companyId, $roomId, $eventDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'event_date' => (string)$row['event_date'],
                'start_time' => substr((string)$row['start_time'], 0, 5),
                'end_time' => substr((string)$row['end_time'], 0, 5),
                'event_title' => (string)$row['event_title'],
                'event_note' => (string)($row['event_note'] ?? ''),
                'source_type' => (string)($row['source_type'] ?? 'manual'),
                'external_ref' => (string)($row['external_ref'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_room') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $roomKey = edudisplej_room_occ_room_key($input['room_key'] ?? '');
        $roomName = edudisplej_room_occ_trim($input['room_name'] ?? '', 220);
        $capacity = max(0, min(100000, (int)($input['capacity'] ?? 0)));
        $isActive = !empty($input['is_active']) ? 1 : 0;

        if ($roomKey === '' || $roomName === '') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó terem kulcs vagy név');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE room_occupancy_rooms
                                    SET room_key = ?, room_name = ?, capacity = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->bind_param('ssiiii', $roomKey, $roomName, $capacity, $isActive, $id, $companyId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO room_occupancy_rooms (company_id, room_key, room_name, capacity, is_active)
                                    VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('issii', $companyId, $roomKey, $roomName, $capacity, $isActive);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_event') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $roomId = (int)($input['room_id'] ?? 0);
        $eventDate = edudisplej_room_occ_event_date($input['event_date'] ?? date('Y-m-d'));
        $startTime = edudisplej_room_occ_time($input['start_time'] ?? '');
        $endTime = edudisplej_room_occ_time($input['end_time'] ?? '');
        $eventTitle = edudisplej_room_occ_trim($input['event_title'] ?? '', 260);
        $eventNote = edudisplej_room_occ_trim($input['event_note'] ?? '', 4000);
        $sourceType = 'manual';

        if ($roomId <= 0 || $startTime === '' || $endTime === '' || $eventTitle === '') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó terem/időpont/cím');
        }

        if (strcmp($startTime, $endTime) >= 0) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('A kezdési időnek korábbinak kell lennie mint a befejezési idő');
        }

        $room = edudisplej_room_occ_find_room($conn, $companyId, $roomId);
        if (!$room) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Érvénytelen terem', 404);
        }

        if ($id > 0) {
            $existingStmt = $conn->prepare("SELECT source_type FROM room_occupancy_events WHERE id = ? AND company_id = ? LIMIT 1");
            $existingStmt->bind_param('ii', $id, $companyId);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            if (!$existing) {
                closeDbConnection($conn);
                edudisplej_room_occ_error('Esemény nem található', 404);
            }

            if (strtolower((string)($existing['source_type'] ?? 'manual')) !== 'manual') {
                closeDbConnection($conn);
                edudisplej_room_occ_error('Külső (external) esemény nem módosítható kézzel.');
            }
        }

        if (edudisplej_room_occ_has_overlap($conn, $companyId, $roomId, $eventDate, $startTime, $endTime, $id)) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Időpont ütközés: ebben a teremben már van foglalás ebben az idősávban.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE room_occupancy_events
                                    SET room_id = ?, event_date = ?, start_time = ?, end_time = ?, event_title = ?, event_note = ?, source_type = ?, external_ref = '', updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->bind_param('issssssii', $roomId, $eventDate, $startTime, $endTime, $eventTitle, $eventNote, $sourceType, $id, $companyId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO room_occupancy_events
                                    (company_id, room_id, event_date, start_time, end_time, event_title, event_note, source_type, external_ref)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')");
            $stmt->bind_param('iissssss', $companyId, $roomId, $eventDate, $startTime, $endTime, $eventTitle, $eventNote, $sourceType);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'delete_event') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Hiányzó esemény azonosító');
        }

        $existingStmt = $conn->prepare("SELECT source_type FROM room_occupancy_events WHERE id = ? AND company_id = ? LIMIT 1");
        $existingStmt->bind_param('ii', $id, $companyId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if (!$existing) {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Esemény nem található', 404);
        }

        if (strtolower((string)($existing['source_type'] ?? 'manual')) !== 'manual') {
            closeDbConnection($conn);
            edudisplej_room_occ_error('Külső (external) esemény nem törölhető kézzel.');
        }

        $stmt = $conn->prepare("DELETE FROM room_occupancy_events WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    closeDbConnection($conn);
    edudisplej_room_occ_error('Ismeretlen művelet');
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        closeDbConnection($conn);
    }
    error_log('api/room_occupancy.php: ' . $e->getMessage());
    edudisplej_room_occ_error('Szerver hiba', 500);
}
