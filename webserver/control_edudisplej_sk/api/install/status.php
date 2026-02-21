<?php
/**
 * Get install progress for one kiosk
 * GET /api/install/status.php?kiosk_id=1
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

$api_company = validate_api_token();

try {
    $conn = getDbConnection();

    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);
    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }

    $stmt = $conn->prepare('SELECT id, company_id, device_id, hostname, status FROM kiosks WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $kiosk_id);
    $stmt->execute();
    $kiosk = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$kiosk) {
        throw new Exception('Kiosk not found');
    }

    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');

    $stmt = $conn->prepare('
        SELECT kiosk_id, phase, step, total, percent, state, message, eta_seconds, payload_json, reported_at
        FROM kiosk_install_progress
        WHERE kiosk_id = ?
        LIMIT 1
    ');
    $stmt->bind_param('i', $kiosk_id);
    $stmt->execute();
    $progress = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$progress) {
        echo json_encode([
            'success' => false,
            'message' => 'No install progress available for this kiosk',
            'kiosk_id' => $kiosk_id
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'kiosk' => [
            'id' => intval($kiosk['id']),
            'company_id' => intval($kiosk['company_id']),
            'device_id' => $kiosk['device_id'],
            'hostname' => $kiosk['hostname'],
            'status' => $kiosk['status']
        ],
        'progress' => [
            'phase' => $progress['phase'],
            'step' => intval($progress['step']),
            'total' => intval($progress['total']),
            'percent' => intval($progress['percent']),
            'state' => $progress['state'],
            'message' => $progress['message'],
            'eta_seconds' => $progress['eta_seconds'] !== null ? intval($progress['eta_seconds']) : null,
            'reported_at' => $progress['reported_at'],
            'payload' => json_decode($progress['payload_json'] ?? '{}', true)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
