<?php
/**
 * Archived kiosk lifecycle helpers.
 */

require_once __DIR__ . '/dbkonfiguracia.php';

function edudisplej_ensure_archived_kiosks_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS archived_kiosks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_kiosk_id INT NULL,
        company_id INT NULL,
        hostname VARCHAR(255) NULL,
        friendly_name VARCHAR(255) NULL,
        device_id VARCHAR(255) NULL,
        mac VARCHAR(32) NULL,
        public_ip VARCHAR(64) NULL,
        status VARCHAR(32) NULL,
        location VARCHAR(255) NULL,
        comment TEXT NULL,
        version VARCHAR(64) NULL,
        screen_resolution VARCHAR(64) NULL,
        sync_interval INT NULL,
        license_active TINYINT(1) NOT NULL DEFAULT 0,
        snapshot_json LONGTEXT NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_by_user_id INT NULL,
        archive_reason VARCHAR(64) NOT NULL DEFAULT 'manual_delete',
        archive_note TEXT NULL,
        INDEX idx_archived_at (archived_at),
        INDEX idx_original_kiosk (original_kiosk_id),
        INDEX idx_company (company_id),
        INDEX idx_hostname (hostname),
        CONSTRAINT archived_kiosks_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
        CONSTRAINT archived_kiosks_archived_by_fk FOREIGN KEY (archived_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_archive_kiosk(
    mysqli $conn,
    array $kiosk,
    int $archived_by_user_id,
    string $reason = 'manual_delete',
    ?string $note = null
): array {
    edudisplej_ensure_archived_kiosks_table($conn);

    $original_kiosk_id = (int)($kiosk['id'] ?? 0);
    if ($original_kiosk_id <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid kiosk data'
        ];
    }

    $company_id = !empty($kiosk['company_id']) ? (int)$kiosk['company_id'] : null;
    $hostname = isset($kiosk['hostname']) ? (string)$kiosk['hostname'] : null;
    $friendly_name = isset($kiosk['friendly_name']) ? (string)$kiosk['friendly_name'] : null;
    $device_id = isset($kiosk['device_id']) ? (string)$kiosk['device_id'] : null;
    $mac = isset($kiosk['mac']) ? (string)$kiosk['mac'] : null;
    $public_ip = isset($kiosk['public_ip']) ? (string)$kiosk['public_ip'] : null;
    $status = isset($kiosk['status']) ? (string)$kiosk['status'] : null;
    $location = isset($kiosk['location']) ? (string)$kiosk['location'] : null;
    $comment = isset($kiosk['comment']) ? (string)$kiosk['comment'] : null;
    $version = isset($kiosk['version']) ? (string)$kiosk['version'] : null;
    $screen_resolution = isset($kiosk['screen_resolution']) ? (string)$kiosk['screen_resolution'] : null;
    $sync_interval = isset($kiosk['sync_interval']) ? (int)$kiosk['sync_interval'] : null;
    $license_active = !empty($kiosk['license_active']) ? 1 : 0;
    $snapshot_json = json_encode($kiosk, JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("INSERT INTO archived_kiosks (
            original_kiosk_id,
            company_id,
            hostname,
            friendly_name,
            device_id,
            mac,
            public_ip,
            status,
            location,
            comment,
            version,
            screen_resolution,
            sync_interval,
            license_active,
            snapshot_json,
            archived_by_user_id,
            archive_reason,
            archive_note
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        'iissssssssssiisiss',
        $original_kiosk_id,
        $company_id,
        $hostname,
        $friendly_name,
        $device_id,
        $mac,
        $public_ip,
        $status,
        $location,
        $comment,
        $version,
        $screen_resolution,
        $sync_interval,
        $license_active,
        $snapshot_json,
        $archived_by_user_id,
        $reason,
        $note
    );

    $stmt->execute();
    $archive_id = (int)$conn->insert_id;
    $stmt->close();

    return [
        'success' => true,
        'archive_id' => $archive_id,
        'original_kiosk_id' => $original_kiosk_id,
        'hostname' => $hostname,
        'message' => 'Kiosk archived successfully'
    ];
}
