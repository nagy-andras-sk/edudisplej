<?php
/**
 * Kiosk Registration API
 *
 * A kiosk eszközök regisztrálják magukat ezen az endpointon.
 * POST paraméterek (JSON body):
 *   mac      – MAC-cím (pl. aa:bb:cc:dd:ee:ff)
 *   hostname – eszköz gépneve
 *   hw_info  – opcionális hardver-infó (JSON objektum)
 *
 * Válasz:
 *   {"success":true,"device_id":"<mac>","kiosk_id":<id>,"is_configured":<bool>}
 */

require_once __DIR__ . '/../error_handler_api.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../dbkonfiguracia.php';
require_once __DIR__ . '/../logging.php';

header('Content-Type: application/json; charset=utf-8');

$company = validate_api_token();

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true) ?: [];

$mac      = strtolower(trim((string)($body['mac'] ?? $_POST['mac'] ?? '')));
$hostname = trim((string)($body['hostname'] ?? $_POST['hostname'] ?? ''));
$hw_info  = isset($body['hw_info']) ? json_encode($body['hw_info']) : null;

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'mac address required']);
    exit;
}

$company_id = (int)($company['id'] ?? 0);

try {
    $conn = getDbConnection();

    // Ensure mac_address and hw_info columns exist (auto-migration)
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS mac_address VARCHAR(17) DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS hw_info TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS version VARCHAR(64) DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS sync_interval INT DEFAULT 300");
    $conn->query("CREATE INDEX IF NOT EXISTS idx_kiosks_mac ON kiosks (mac_address)");

    // Look up existing kiosk by MAC
    $stmt = $conn->prepare("SELECT id, company_id, friendly_name FROM kiosks WHERE mac_address = ? LIMIT 1");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk  = $result->fetch_assoc();
    $stmt->close();

    $now = date('Y-m-d H:i:s');

    if ($kiosk) {
        $kiosk_id   = (int)$kiosk['id'];
        $is_configured = !empty($kiosk['friendly_name']);

        // Update last_seen and hostname
        $upd = $conn->prepare(
            "UPDATE kiosks SET hostname = ?, last_seen = ?, hw_info = COALESCE(?, hw_info), status = 'online' WHERE id = ?"
        );
        $upd->bind_param("sssi", $hostname, $now, $hw_info, $kiosk_id);
        $upd->execute();
        $upd->close();
    } else {
        // Register new kiosk
        $ins = $conn->prepare(
            "INSERT INTO kiosks (mac_address, hostname, company_id, status, last_seen, hw_info, created_at)
             VALUES (?, ?, ?, 'online', ?, ?, NOW())"
        );
        $ins->bind_param("ssiss", $mac, $hostname, $company_id, $now, $hw_info);
        $ins->execute();
        $kiosk_id      = (int)$conn->insert_id;
        $is_configured = false;
        $ins->close();
    }

    closeDbConnection($conn);

    $log_ip = get_client_ip();
    $log_ua = get_user_agent();
    log_security_event('kiosk_registration', null, $mac, $log_ip, $log_ua, [
        'kiosk_id' => $kiosk_id,
        'is_configured' => $is_configured,
    ]);

    echo json_encode([
        'success'       => true,
        'device_id'     => $mac,
        'kiosk_id'      => $kiosk_id,
        'is_configured' => $is_configured,
    ]);

} catch (Exception $e) {
    error_log('api/registration.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
