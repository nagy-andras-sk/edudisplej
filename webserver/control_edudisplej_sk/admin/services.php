<?php
/**
 * Admin - Service Version Management
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

$structure_file = realpath(__DIR__ . '/../../install/init/structure.json');
$default_versions = [];

if ($structure_file && is_readable($structure_file)) {
    $content = file_get_contents($structure_file);
    $json = json_decode((string)$content, true);
    if (is_array($json) && isset($json['service_versions']) && is_array($json['service_versions'])) {
        $default_versions = $json['service_versions'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $service_name = trim((string)($_POST['service_name'] ?? ''));

    if ($action === 'bump_service' && $service_name === '') {
        $error = 'Service name is required.';
    }

    if ($error === '') {
        try {
            $conn = getDbConnection();

            $conn->query("CREATE TABLE IF NOT EXISTS service_versions (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                service_name VARCHAR(255) NOT NULL,
                version_token VARCHAR(64) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by_user_id INT(11) DEFAULT NULL,
                UNIQUE KEY uniq_service_name (service_name),
                INDEX idx_updated_by_user (updated_by_user_id),
                CONSTRAINT service_versions_user_fk FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $version_token = gmdate('Y-m-d\TH:i:s\Z');
            $updated_by_user_id = (int)($_SESSION['user_id'] ?? 0);

            if ($action === 'bump_all') {
                $services = array_keys($default_versions);
                foreach ($services as $service) {
                    $service = trim((string)$service);
                    if ($service === '') {
                        continue;
                    }
                    $stmt = $conn->prepare("INSERT INTO service_versions (service_name, version_token, updated_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE version_token = VALUES(version_token), updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP");
                    $stmt->bind_param('ssi', $service, $version_token, $updated_by_user_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $success = 'All services were marked for upgrade.';
            } elseif ($action === 'bump_service') {
                $stmt = $conn->prepare("INSERT INTO service_versions (service_name, version_token, updated_by_user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE version_token = VALUES(version_token), updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP");
                $stmt->bind_param('ssi', $service_name, $version_token, $updated_by_user_id);
                $stmt->execute();
                $stmt->close();
                $success = 'Service marked for upgrade: ' . $service_name;
            }

            // Set kiosks to upgrading state; actual sync will switch them back online
            $conn->query("UPDATE kiosks SET status = 'upgrading', upgrade_started_at = NOW() WHERE status <> 'unconfigured'");

            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Failed to update service versions: ' . $e->getMessage();
        }
    }
}

$overrides = [];
try {
    $conn = getDbConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS service_versions (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(255) NOT NULL,
        version_token VARCHAR(64) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by_user_id INT(11) DEFAULT NULL,
        UNIQUE KEY uniq_service_name (service_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $result = $conn->query("SELECT service_name, version_token, updated_at FROM service_versions ORDER BY service_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $overrides[(string)$row['service_name']] = $row;
        }
    }
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = $error !== '' ? $error : ('Failed to load service overrides: ' . $e->getMessage());
}

$all_services = array_values(array_unique(array_merge(array_keys($default_versions), array_keys($overrides))));
sort($all_services, SORT_NATURAL | SORT_FLAG_CASE);

require_once 'header.php';
?>

<h2 class="page-title">Service Versions</h2>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Manual upgrade trigger</div>
    <p class="muted" style="margin-bottom:12px;">
        Bumping a service version marks all displays as <strong>upgrading</strong>. Endpoints detect newer server token and start reinstall automatically.
    </p>
    <form method="post" style="margin-bottom:12px;">
        <input type="hidden" name="action" value="bump_all">
        <button type="submit" class="btn btn-danger" onclick="return confirm('Mark all services for upgrade and set kiosks to upgrading?');">Force upgrade all services</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Default version</th>
                    <th>DB override</th>
                    <th>Effective version</th>
                    <th>Updated at</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_services)): ?>
                    <tr><td colspan="6" class="muted">No services found.</td></tr>
                <?php else: ?>
                    <?php foreach ($all_services as $service): ?>
                        <?php
                            $default_version = (string)($default_versions[$service] ?? '');
                            $override_version = (string)($overrides[$service]['version_token'] ?? '');
                            $effective_version = $override_version !== '' ? $override_version : $default_version;
                            $updated_at = (string)($overrides[$service]['updated_at'] ?? '');
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($service); ?></code></td>
                            <td><?php echo htmlspecialchars($default_version !== '' ? $default_version : '-'); ?></td>
                            <td><?php echo htmlspecialchars($override_version !== '' ? $override_version : '-'); ?></td>
                            <td><strong><?php echo htmlspecialchars($effective_version !== '' ? $effective_version : '-'); ?></strong></td>
                            <td><?php echo htmlspecialchars($updated_at !== '' ? $updated_at : '-'); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="bump_service">
                                    <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($service); ?>">
                                    <button type="submit" class="btn btn-primary" onclick="return confirm('Trigger upgrade for <?php echo htmlspecialchars($service); ?> ?');">Trigger upgrade</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'footer.php'; ?>
