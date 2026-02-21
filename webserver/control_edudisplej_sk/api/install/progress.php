<?php
/**
 * Install Progress Report Endpoint - Receives kiosk install progress
 * POST /api/install/progress.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

$api_company = validate_api_token();

try {
    $conn = getDbConnection();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!is_array($data)) {
        throw new Exception('Invalid JSON data');
    }

    $kiosk_node = is_array($data['kiosk'] ?? null) ? $data['kiosk'] : [];

    $device_id = trim((string)($kiosk_node['device_id'] ?? $data['device_id'] ?? ''));
    $hostname = trim((string)($kiosk_node['hostname'] ?? $data['hostname'] ?? ''));
    $kiosk_id = intval($kiosk_node['kiosk_id'] ?? $data['kiosk_id'] ?? 0);

    if ($kiosk_id <= 0 && $device_id === '' && $hostname === '') {
        throw new Exception('Missing kiosk identification (kiosk_id, device_id, or hostname)');
    }

    $kiosk = null;

    if ($kiosk_id > 0) {
        $stmt = $conn->prepare('SELECT id, company_id, device_id, hostname FROM kiosks WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $kiosk_id);
        $stmt->execute();
        $kiosk = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$kiosk && $device_id !== '') {
        $stmt = $conn->prepare('SELECT id, company_id, device_id, hostname FROM kiosks WHERE device_id = ? LIMIT 1');
        $stmt->bind_param('s', $device_id);
        $stmt->execute();
        $kiosk = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$kiosk && $hostname !== '') {
        $stmt = $conn->prepare('SELECT id, company_id, device_id, hostname FROM kiosks WHERE hostname = ? LIMIT 1');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        $kiosk = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$kiosk) {
        throw new Exception('Kiosk not found');
    }

    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');

    $phase = trim((string)($data['phase'] ?? 'unknown'));
    $state = trim((string)($data['state'] ?? 'running'));
    $message = trim((string)($data['message'] ?? ''));

    $step = max(0, intval($data['step'] ?? 0));
    $total = max(0, intval($data['total'] ?? 0));
    $percent = intval($data['percent'] ?? 0);
    if ($percent < 0) {
        $percent = 0;
    }
    if ($percent > 100) {
        $percent = 100;
    }

    $eta_seconds_raw = $data['eta_seconds'] ?? null;
    $eta_seconds = null;
    if ($eta_seconds_raw !== null && $eta_seconds_raw !== '') {
        $eta_seconds = max(0, intval($eta_seconds_raw));
    }

    $allowed_states = ['running', 'completed', 'failed'];
    if (!in_array($state, $allowed_states, true)) {
        $state = 'running';
    }

    $payload_json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare('
        INSERT INTO kiosk_install_progress
            (kiosk_id, company_id, phase, step, total, percent, state, message, eta_seconds, payload_json, reported_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id),
            phase = VALUES(phase),
            step = VALUES(step),
            total = VALUES(total),
            percent = VALUES(percent),
            state = VALUES(state),
            message = VALUES(message),
            eta_seconds = VALUES(eta_seconds),
            payload_json = VALUES(payload_json),
            reported_at = NOW()
    ');

    $resolved_kiosk_id = intval($kiosk['id']);
    $resolved_company_id = intval($kiosk['company_id']);

    $stmt->bind_param(
        'iisiiissis',
        $resolved_kiosk_id,
        $resolved_company_id,
        $phase,
        $step,
        $total,
        $percent,
        $state,
        $message,
        $eta_seconds,
        $payload_json
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to save install progress');
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Install progress recorded',
        'kiosk_id' => $resolved_kiosk_id,
        'state' => $state,
        'percent' => $percent
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
