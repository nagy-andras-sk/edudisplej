<?php
/**
 * Device Hardware Sync API  –  /api/v1/device/sync.php
 *
 * A kiosk rendszeres HW-adatokat küld erre az endpointra.
 * POST paraméterek (JSON body):
 *   mac         – MAC-cím
 *   hostname    – gépnév
 *   hw_info     – hardver-infó JSON objektum (cpu, mem, disk, uptime, …)
 *   version     – kiosk szoftver verziója (opcionális)
 *   last_update – kiosk loop utolsó frissítési időbélyege (opcionális)
 *
 * Válasz:
 *   {"success":true,"sync_interval":<másodperc>}
 */

require_once __DIR__ . '/../../../error_handler_api.php';
require_once __DIR__ . '/../../../api/auth.php';
require_once __DIR__ . '/../../../dbkonfiguracia.php';

header('Content-Type: application/json; charset=utf-8');

$company = validate_api_token();

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true) ?: [];

$mac         = strtolower(trim((string)($body['mac'] ?? $_POST['mac'] ?? '')));
$hostname    = trim((string)($body['hostname'] ?? $_POST['hostname'] ?? ''));
$hw_info     = isset($body['hw_info']) ? json_encode($body['hw_info']) : null;
$version     = trim((string)($body['version'] ?? ''));
$last_update = trim((string)($body['last_update'] ?? ''));

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'mac address required']);
    exit;
}

try {
    $conn = getDbConnection();

    // Ensure columns exist
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS mac_address VARCHAR(17) DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS hw_info TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS version VARCHAR(64) DEFAULT NULL");
    $conn->query("ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS sync_interval INT DEFAULT 300");
    $conn->query("CREATE INDEX IF NOT EXISTS idx_kiosks_mac ON kiosks (mac_address)");

    $now = date('Y-m-d H:i:s');

    // Build SET clause dynamically
    $sets  = ["last_seen = ?", "status = 'online'"];
    $types = "s";
    $vals  = [$now];

    if ($hostname !== '') {
        $sets[]  = "hostname = ?";
        $types  .= "s";
        $vals[]  = $hostname;
    }
    if ($hw_info !== null) {
        $sets[]  = "hw_info = ?";
        $types  .= "s";
        $vals[]  = $hw_info;
    }
    if ($version !== '') {
        $sets[]  = "version = ?";
        $types  .= "s";
        $vals[]  = $version;
    }
    if ($last_update !== '') {
        $sets[]  = "loop_last_update = ?";
        $types  .= "s";
        $vals[]  = $last_update;
    }

    $types .= "s";
    $vals[] = $mac;

    $sql  = "UPDATE kiosks SET " . implode(", ", $sets) . " WHERE mac_address = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();

    // Read back sync_interval for this kiosk
    $si_stmt = $conn->prepare("SELECT COALESCE(sync_interval, 300) AS sync_interval FROM kiosks WHERE mac_address = ? LIMIT 1");
    $si_stmt->bind_param("s", $mac);
    $si_stmt->execute();
    $si_result = $si_stmt->get_result()->fetch_assoc();
    $si_stmt->close();

    closeDbConnection($conn);

    echo json_encode([
        'success'       => true,
        'sync_interval' => (int)($si_result['sync_interval'] ?? 300),
    ]);

} catch (Exception $e) {
    error_log('api/v1/device/sync.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
