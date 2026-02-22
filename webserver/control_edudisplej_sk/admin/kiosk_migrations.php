<?php
/**
 * Bulk kiosk migration (token/company switch + reboot).
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../logging.php';
require_once '../kiosk_status.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_migrate'])) {
    $target_company_id = (int)($_POST['target_company_id'] ?? 0);
    $selected_kiosk_ids = isset($_POST['kiosk_ids']) && is_array($_POST['kiosk_ids'])
        ? array_values(array_unique(array_map('intval', $_POST['kiosk_ids'])))
        : [];

    if ($target_company_id <= 0) {
        $error = 'Select target institution.';
    } elseif (empty($selected_kiosk_ids)) {
        $error = 'Select at least one kiosk.';
    } else {
        try {
            $conn = getDbConnection();

            $target_stmt = $conn->prepare("SELECT id, name, api_token FROM companies WHERE id = ? LIMIT 1");
            $target_stmt->bind_param('i', $target_company_id);
            $target_stmt->execute();
            $target_company = $target_stmt->get_result()->fetch_assoc();
            $target_stmt->close();

            if (!$target_company) {
                $error = 'Target institution not found.';
            } elseif (empty($target_company['api_token'])) {
                $error = 'Target institution has no API token. Generate one first.';
            } else {
                $token = (string)$target_company['api_token'];
                $escaped_token = str_replace("'", "'\"'\"'", $token);
                $migration_command = "printf '%s\\n' '" . $escaped_token . "' > /opt/edudisplej/lic/token && sync && sudo shutdown -r now";

                $queued_count = 0;
                $skipped_count = 0;
                $conn->begin_transaction();

                try {
                    $lookup_stmt = $conn->prepare("SELECT k.id, k.company_id, COALESCE(NULLIF(k.friendly_name,''), k.hostname) AS kiosk_name FROM kiosks k WHERE k.id = ? LIMIT 1");
                    $queue_stmt = $conn->prepare("INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at) VALUES (?, 'custom', ?, 'pending', NOW())");
                    $migration_stmt = $conn->prepare("INSERT INTO kiosk_migrations (kiosk_id, source_company_id, target_company_id, target_api_token, status, requested_by_user_id, command_queue_id, note) VALUES (?, ?, ?, ?, 'queued', ?, ?, ?) ON DUPLICATE KEY UPDATE source_company_id = VALUES(source_company_id), target_company_id = VALUES(target_company_id), target_api_token = VALUES(target_api_token), status = 'queued', requested_by_user_id = VALUES(requested_by_user_id), command_queue_id = VALUES(command_queue_id), note = VALUES(note), updated_at = CURRENT_TIMESTAMP, completed_at = NULL");
                    $log_stmt = $conn->prepare("INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details) VALUES (?, ?, 'migration_queued', ?)");

                    foreach ($selected_kiosk_ids as $kiosk_id) {
                        if ($kiosk_id <= 0) {
                            continue;
                        }

                        $lookup_stmt->bind_param('i', $kiosk_id);
                        $lookup_stmt->execute();
                        $kiosk_row = $lookup_stmt->get_result()->fetch_assoc();
                        if (!$kiosk_row) {
                            $skipped_count++;
                            continue;
                        }

                        $source_company_id = !empty($kiosk_row['company_id']) ? (int)$kiosk_row['company_id'] : null;
                        if ($source_company_id === $target_company_id) {
                            $skipped_count++;
                            continue;
                        }

                        $queue_stmt->bind_param('is', $kiosk_id, $migration_command);
                        $queue_stmt->execute();
                        $command_queue_id = (int)$conn->insert_id;

                        $note = 'Bulk migration to company #' . $target_company_id;
                        $requested_by = (int)$_SESSION['user_id'];
                        $migration_stmt->bind_param(
                            'iiisiis',
                            $kiosk_id,
                            $source_company_id,
                            $target_company_id,
                            $token,
                            $requested_by,
                            $command_queue_id,
                            $note
                        );
                        $migration_stmt->execute();

                        $details = json_encode([
                            'target_company_id' => $target_company_id,
                            'target_company_name' => (string)($target_company['name'] ?? ''),
                            'requested_by_user_id' => $requested_by,
                            'command_queue_id' => $command_queue_id,
                            'action' => 'token_swap_and_reboot',
                        ]);
                        $log_stmt->bind_param('iis', $kiosk_id, $command_queue_id, $details);
                        $log_stmt->execute();

                        $queued_count++;
                    }

                    $lookup_stmt->close();
                    $queue_stmt->close();
                    $migration_stmt->close();
                    $log_stmt->close();

                    $conn->commit();

                    $success = "Migration queued. Queued: {$queued_count}, Skipped: {$skipped_count}.";
                    log_security_event(
                        'kiosk_bulk_migration_queued',
                        (int)$_SESSION['user_id'],
                        (string)($_SESSION['username'] ?? 'admin'),
                        get_client_ip(),
                        get_user_agent(),
                        [
                            'target_company_id' => $target_company_id,
                            'target_company_name' => (string)($target_company['name'] ?? ''),
                            'queued_count' => $queued_count,
                            'skipped_count' => $skipped_count,
                            'kiosk_ids' => $selected_kiosk_ids,
                        ]
                    );
                } catch (Throwable $txe) {
                    $conn->rollback();
                    throw $txe;
                }
            }

            closeDbConnection($conn);
        } catch (Throwable $e) {
            $error = 'Failed to queue migration: ' . $e->getMessage();
            error_log('kiosk_migrations: ' . $e->getMessage());
        }
    }
}

$companies = [];
$kiosks = [];
$migrations = [];

try {
    $conn = getDbConnection();

    $companies_result = $conn->query("SELECT id, name, api_token, is_active FROM companies ORDER BY name");
    while ($row = $companies_result->fetch_assoc()) {
        $companies[] = $row;
    }

    $kiosk_result = $conn->query("SELECT k.id, k.hostname, k.friendly_name, k.status, k.last_sync, k.last_seen, k.last_heartbeat, k.upgrade_started_at, c.id AS company_id, c.name AS company_name FROM kiosks k LEFT JOIN companies c ON c.id = k.company_id ORDER BY c.name, k.hostname");
    while ($row = $kiosk_result->fetch_assoc()) {
        kiosk_apply_effective_status($row);
        $kiosks[] = $row;
    }

    $migration_result = $conn->query("SELECT km.id, km.kiosk_id, km.source_company_id, km.target_company_id, km.status, km.created_at, km.updated_at, km.completed_at, k.hostname, COALESCE(NULLIF(k.friendly_name,''), k.hostname) AS kiosk_name, cs.name AS source_company_name, ct.name AS target_company_name, u.username AS requested_by_username FROM kiosk_migrations km LEFT JOIN kiosks k ON k.id = km.kiosk_id LEFT JOIN companies cs ON cs.id = km.source_company_id LEFT JOIN companies ct ON ct.id = km.target_company_id LEFT JOIN users u ON u.id = km.requested_by_user_id ORDER BY km.updated_at DESC LIMIT 200");
    while ($row = $migration_result->fetch_assoc()) {
        $migrations[] = $row;
    }

    closeDbConnection($conn);
} catch (Throwable $e) {
    $error = 'Failed to load migration data: ' . $e->getMessage();
    error_log('kiosk_migrations load: ' . $e->getMessage());
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Bulk kiosk migration</div>
    <form method="post">
        <input type="hidden" name="bulk_migrate" value="1">

        <div class="form-row" style="align-items:flex-end; margin-bottom:12px;">
            <div class="form-field" style="min-width: 280px;">
                <label for="target_company_id">Target institution</label>
                <select id="target_company_id" name="target_company_id" required>
                    <option value="">-- select --</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo (int)$company['id']; ?>">
                            <?php echo htmlspecialchars($company['name']); ?>
                            <?php if (empty($company['api_token'])): ?>
                                (no API token)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <button class="btn btn-primary" type="submit" onclick="return confirm('Queue migration for selected kiosks? Token will be replaced and kiosk will reboot.')">Queue migration</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all"></th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Hostname</th>
                        <th>Status</th>
                        <th>Current institution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kiosks)): ?>
                        <tr><td colspan="6" class="muted">No kiosks.</td></tr>
                    <?php else: ?>
                        <?php foreach ($kiosks as $kiosk): ?>
                            <tr>
                                <td><input type="checkbox" name="kiosk_ids[]" value="<?php echo (int)$kiosk['id']; ?>" class="kiosk-check"></td>
                                <td><?php echo (int)$kiosk['id']; ?></td>
                                <td><?php echo htmlspecialchars($kiosk['friendly_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($kiosk['hostname'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($kiosk['status'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($kiosk['company_name'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Recent migration jobs</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Kiosk</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Requested by</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Completed</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($migrations)): ?>
                    <tr><td colspan="9" class="muted">No migration records.</td></tr>
                <?php else: ?>
                    <?php foreach ($migrations as $migration): ?>
                        <tr>
                            <td><?php echo (int)$migration['id']; ?></td>
                            <td><?php echo htmlspecialchars($migration['kiosk_name'] ?: ($migration['hostname'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars($migration['source_company_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($migration['target_company_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($migration['status'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($migration['requested_by_username'] ?: '-'); ?></td>
                            <td class="nowrap"><?php echo !empty($migration['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$migration['created_at'])) : '-'; ?></td>
                            <td class="nowrap"><?php echo !empty($migration['updated_at']) ? date('Y-m-d H:i:s', strtotime((string)$migration['updated_at'])) : '-'; ?></td>
                            <td class="nowrap"><?php echo !empty($migration['completed_at']) ? date('Y-m-d H:i:s', strtotime((string)$migration['completed_at'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var checkAll = document.getElementById('check-all');
    if (!checkAll) return;
    checkAll.addEventListener('change', function () {
        document.querySelectorAll('.kiosk-check').forEach(function (el) {
            el.checked = checkAll.checked;
        });
    });
})();
</script>

<?php include 'footer.php'; ?>
