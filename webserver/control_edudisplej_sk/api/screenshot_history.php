<?php
/**
 * Screenshot History API
 * Returns paginated screenshot history for one kiosk with optional date filtering.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

$company_id = $_SESSION['company_id'] ?? null;
$kiosk_id = intval($_GET['kiosk_id'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = intval($_GET['per_page'] ?? 20);
if ($per_page <= 0 || $per_page > 50) {
    $per_page = 20;
}

$date_from_raw = trim((string)($_GET['date_from'] ?? ''));
$date_to_raw = trim((string)($_GET['date_to'] ?? ''));

$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from_raw) ? $date_from_raw : date('Y-m-d', strtotime('-3 days'));
$date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_raw) ? $date_to_raw : date('Y-m-d');

if ($kiosk_id <= 0 || !$company_id) {
    $response['message'] = 'Invalid request';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();

    $verify_stmt = $conn->prepare('SELECT id, status, last_sync, last_seen FROM kiosks WHERE id = ? AND company_id = ? LIMIT 1');
    $verify_stmt->bind_param('ii', $kiosk_id, $company_id);
    $verify_stmt->execute();
    $kiosk_ok = $verify_stmt->get_result()->fetch_assoc();
    $verify_stmt->close();

    if (!$kiosk_ok) {
        http_response_code(403);
        $response['message'] = 'Access denied';
        echo json_encode($response);
        exit();
    }

    kiosk_apply_effective_status($kiosk_ok);
    $offlineSinceRaw = $kiosk_ok['last_sync'] ?? null;
    if (!$offlineSinceRaw) {
        $offlineSinceRaw = $kiosk_ok['last_seen'] ?? null;
    }
    $offlineSinceFormatted = $offlineSinceRaw ? date('Y-m-d H:i:s', strtotime((string)$offlineSinceRaw)) : null;

    $where = ' WHERE kiosk_id = ? AND action = \'screenshot\'';
    $types = 'i';
    $params = [$kiosk_id];

    if ($date_from !== null) {
        $where .= ' AND DATE(timestamp) >= ?';
        $types .= 's';
        $params[] = $date_from;
    }

    if ($date_to !== null) {
        $where .= ' AND DATE(timestamp) <= ?';
        $types .= 's';
        $params[] = $date_to;
    }

    $count_sql = 'SELECT COUNT(*) AS total FROM sync_logs' . $where;
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();

    $offset = ($page - 1) * $per_page;
    if ($offset < 0) {
        $offset = 0;
    }

    $list_sql = 'SELECT id, timestamp, details FROM sync_logs'
        . $where
        . ' ORDER BY timestamp DESC LIMIT ? OFFSET ?';

    $list_types = $types . 'ii';
    $list_params = array_merge($params, [$per_page, $offset]);

    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param($list_types, ...$list_params);
    $list_stmt->execute();
    $result = $list_stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        $filename = '';
        if (is_array($details) && !empty($details['filename'])) {
            $filename = basename((string)$details['filename']);
        }

        $url = '../api/screenshot_file.php?kiosk_id=' . (int)$kiosk_id . '&log_id=' . (int)$row['id'];

        $items[] = [
            'id' => (int)$row['id'],
            'timestamp' => !empty($row['timestamp']) ? date('Y-m-d H:i:s', strtotime((string)$row['timestamp'])) : null,
            'screenshot_url' => $url
        ];
    }
    $list_stmt->close();

    if (($kiosk_ok['status'] ?? '') === 'offline') {
        $items = array_merge([
            [
                'id' => 0,
                'timestamp' => $offlineSinceFormatted,
                'screenshot_url' => null,
                'is_offline_marker' => true,
                'offline_since' => $offlineSinceFormatted,
                'label' => $offlineSinceFormatted
                    ? ('OFFLINE SINCE: ' . $offlineSinceFormatted)
                    : 'OFFLINE'
            ]
        ], $items);

        if (count($items) > $per_page) {
            $items = array_slice($items, 0, $per_page);
        }
    }

    closeDbConnection($conn);

    $response['success'] = true;
    $response['items'] = $items;
    $response['pagination'] = [
        'page' => $page,
        'per_page' => $per_page,
        'total' => $total,
        'total_pages' => max(1, (int)ceil($total / $per_page))
    ];
    $response['filters'] = [
        'date_from' => $date_from,
        'date_to' => $date_to
    ];

} catch (Exception $e) {
    $response['message'] = 'Database error';
    error_log($e->getMessage());
}

echo json_encode($response);
