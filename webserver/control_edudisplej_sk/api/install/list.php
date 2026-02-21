<?php
/**
 * Get install progress list
 * GET /api/install/list.php?company_id=1&state=running
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

$api_company = validate_api_token();

try {
    $conn = getDbConnection();

    $company_id = intval($_GET['company_id'] ?? 0);
    $state_filter = trim((string)($_GET['state'] ?? ''));
    $limit = intval($_GET['limit'] ?? 100);
    if ($limit <= 0) {
        $limit = 100;
    }
    if ($limit > 500) {
        $limit = 500;
    }

    if (!api_is_admin_session($api_company)) {
        $company_id = intval($api_company['id']);
    }

    $query = '
        SELECT
            k.id AS kiosk_id,
            k.company_id,
            k.device_id,
            k.hostname,
            k.status AS kiosk_status,
            p.phase,
            p.step,
            p.total,
            p.percent,
            p.state,
            p.message,
            p.eta_seconds,
            p.reported_at
        FROM kiosk_install_progress p
        INNER JOIN kiosks k ON k.id = p.kiosk_id
        WHERE 1=1
    ';

    $params = [];
    $types = '';

    if ($company_id > 0) {
        $query .= ' AND k.company_id = ?';
        $params[] = $company_id;
        $types .= 'i';
    }

    if ($state_filter !== '') {
        $query .= ' AND p.state = ?';
        $params[] = $state_filter;
        $types .= 's';
    }

    $query .= ' ORDER BY p.reported_at DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'kiosk_id' => intval($row['kiosk_id']),
            'company_id' => intval($row['company_id']),
            'device_id' => $row['device_id'],
            'hostname' => $row['hostname'],
            'kiosk_status' => $row['kiosk_status'],
            'phase' => $row['phase'],
            'step' => intval($row['step']),
            'total' => intval($row['total']),
            'percent' => intval($row['percent']),
            'state' => $row['state'],
            'message' => $row['message'],
            'eta_seconds' => $row['eta_seconds'] !== null ? intval($row['eta_seconds']) : null,
            'reported_at' => $row['reported_at']
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'count' => count($items),
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
