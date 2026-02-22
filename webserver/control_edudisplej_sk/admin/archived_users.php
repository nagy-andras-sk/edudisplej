<?php
/**
 * Archived Users management page.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../user_archive.php';
require_once '../logging.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;
$filter_username = trim((string)($_GET['username'] ?? ''));
$filter_company = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$filter_state = (string)($_GET['state'] ?? 'all');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_archive_id'])) {
    $archive_id = (int)($_POST['restore_archive_id'] ?? 0);

    if ($archive_id <= 0) {
        $error = 'Invalid archive id';
    } else {
        try {
            $conn = getDbConnection();
            edudisplej_ensure_archived_users_table($conn);

            $restore_result = edudisplej_restore_archived_user($conn, $archive_id, (int)$_SESSION['user_id']);
            if (!empty($restore_result['success'])) {
                $success = 'User restored successfully';
                log_security_event(
                    'user_restored',
                    (int)$_SESSION['user_id'],
                    (string)($_SESSION['username'] ?? 'admin'),
                    get_client_ip(),
                    get_user_agent(),
                    [
                        'archive_id' => $archive_id,
                        'restored_user_id' => $restore_result['restored_user_id'] ?? null,
                        'restored_username' => $restore_result['username'] ?? null,
                    ]
                );
            } else {
                $error = $restore_result['message'] ?? 'Failed to restore user';
            }

            closeDbConnection($conn);
        } catch (Throwable $e) {
            $error = 'Restore failed: ' . $e->getMessage();
            error_log('archived_users restore: ' . $e->getMessage());
        }
    }
}

$archived_users = [];
$total_archived = 0;
$companies = [];
$archive_logs = [];

try {
    $conn = getDbConnection();
    edudisplej_ensure_archived_users_table($conn);

    $company_result = $conn->query("SELECT id, name FROM companies ORDER BY name");
    while ($row = $company_result->fetch_assoc()) {
        $companies[] = $row;
    }

    $where = [];
    $params = [];
    $types = '';

    if ($filter_username !== '') {
        $where[] = 'au.username LIKE ?';
        $params[] = '%' . $filter_username . '%';
        $types .= 's';
    }

    if ($filter_company > 0) {
        $where[] = 'au.company_id = ?';
        $params[] = $filter_company;
        $types .= 'i';
    }

    if ($filter_state === 'active') {
        $where[] = 'au.restored_at IS NULL';
    } elseif ($filter_state === 'restored') {
        $where[] = 'au.restored_at IS NOT NULL';
    }

    $where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

    $count_sql = "SELECT COUNT(*) AS total FROM archived_users au $where_sql";
    if (!empty($params)) {
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param($types, ...$params);
        $count_stmt->execute();
        $total_archived = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $count_stmt->close();
    } else {
        $total_archived = (int)($conn->query($count_sql)->fetch_assoc()['total'] ?? 0);
    }

    $list_sql = "
        SELECT
            au.*,
            c.name AS company_name,
            archiver.username AS archived_by_username,
            restorer.username AS restored_by_username
        FROM archived_users au
        LEFT JOIN companies c ON c.id = au.company_id
        LEFT JOIN users archiver ON archiver.id = au.archived_by_user_id
        LEFT JOIN users restorer ON restorer.id = au.restored_by_user_id
        $where_sql
        ORDER BY au.archived_at DESC
        LIMIT ? OFFSET ?
    ";

    $list_stmt = $conn->prepare($list_sql);
    if (!empty($params)) {
        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;
        $list_types = $types . 'ii';
        $list_stmt->bind_param($list_types, ...$list_params);
    } else {
        $list_stmt->bind_param('ii', $per_page, $offset);
    }

    $list_stmt->execute();
    $list_result = $list_stmt->get_result();
    while ($row = $list_result->fetch_assoc()) {
        $archived_users[] = $row;
    }
    $list_stmt->close();

    $logs_sql = "
        SELECT id, event_type, username, ip_address, details, timestamp
        FROM security_logs
        WHERE event_type IN ('user_archived', 'user_restored')
        ORDER BY timestamp DESC
        LIMIT 100
    ";
    $logs_result = $conn->query($logs_sql);
    if ($logs_result) {
        while ($log_row = $logs_result->fetch_assoc()) {
            $archive_logs[] = $log_row;
        }
    }

    closeDbConnection($conn);
} catch (Throwable $e) {
    $error = 'Failed to load archived users: ' . $e->getMessage();
    error_log('archived_users page: ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($total_archived / $per_page));

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Archived users filters</div>
    <form method="get" class="toolbar">
        <div class="form-field">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" value="<?php echo htmlspecialchars($filter_username); ?>">
        </div>
        <div class="form-field">
            <label for="company_id">Institution</label>
            <select id="company_id" name="company_id">
                <option value="0">All</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>" <?php echo $filter_company === (int)$company['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="state">State</label>
            <select id="state" name="state">
                <option value="all" <?php echo $filter_state === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $filter_state === 'active' ? 'selected' : ''; ?>>Archived only</option>
                <option value="restored" <?php echo $filter_state === 'restored' ? 'selected' : ''; ?>>Restored only</option>
            </select>
        </div>
        <div class="form-field">
            <button class="btn btn-primary" type="submit">Filter</button>
        </div>
        <div class="form-field">
            <a class="btn btn-secondary" href="archived_users.php">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Archived users (<?php echo (int)$total_archived; ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Institution</th>
                    <th>Archived at</th>
                    <th>Archived by</th>
                    <th>Reason</th>
                    <th>Restored at</th>
                    <th>Restored by</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($archived_users)): ?>
                    <tr>
                        <td colspan="10" class="muted">No archived users.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($archived_users as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                            <td><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['company_name'] ?? '-'); ?></td>
                            <td class="nowrap"><?php echo !empty($row['archived_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['archived_at'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($row['archived_by_username'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['archive_reason'] ?? '-'); ?></td>
                            <td class="nowrap"><?php echo !empty($row['restored_at']) ? date('Y-m-d H:i:s', strtotime((string)$row['restored_at'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($row['restored_by_username'] ?? '-'); ?></td>
                            <td class="nowrap">
                                <?php if (empty($row['restored_at'])): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Restore this archived user?')">
                                        <input type="hidden" name="restore_archive_id" value="<?php echo (int)$row['id']; ?>">
                                        <button class="btn btn-small" type="submit">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Restored</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 10px;">
        <?php if ($total_pages > 1): ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php
                    $params = $_GET;
                    $params['page'] = $i;
                    $url = 'archived_users.php?' . http_build_query($params);
                ?>
                <a class="btn btn-small <?php echo $i === $page ? 'btn-primary' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Archive/Restore logs (latest 100)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Event</th>
                    <th>Actor</th>
                    <th>IP</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($archive_logs)): ?>
                    <tr>
                        <td colspan="5" class="muted">No archive/restore logs.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($archive_logs as $log): ?>
                        <tr>
                            <td class="nowrap"><?php echo !empty($log['timestamp']) ? date('Y-m-d H:i:s', strtotime((string)$log['timestamp'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars((string)$log['event_type']); ?></td>
                            <td><?php echo htmlspecialchars((string)$log['username']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars((string)$log['ip_address']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars((string)substr((string)($log['details'] ?? ''), 0, 260)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
