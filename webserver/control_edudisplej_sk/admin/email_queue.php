<?php
/**
 * Admin - Email Queue and Archive
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../email_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'process_queue') {
            $result = process_email_queue(100);
            $success = 'Queue processed. Sent: ' . (int)$result['sent'] . ', Failed: ' . (int)$result['failed'] . '.';
        } elseif ($action === 'retry') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $ok = process_email_queue_item($id);
                $success = $ok ? 'Email sent successfully.' : 'Retry failed. Check last error.';
            }
        } elseif ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $ok = archive_email_queue_item($id);
                $success = $ok ? 'Email archived.' : 'Archive not possible for this item.';
            }
        } elseif ($action === 'archive_all_sent') {
            $conn = getDbConnection();
            $archived = 'archived';
            $sent = 'sent';
            $stmt = $conn->prepare("UPDATE email_queue SET status = ?, archived_at = NOW(), updated_at = NOW() WHERE status = ?");
            $stmt->bind_param('ss', $archived, $sent);
            $stmt->execute();
            $affected = (int)$stmt->affected_rows;
            $stmt->close();
            closeDbConnection($conn);
            $success = 'Archived sent emails: ' . $affected;
        }
    } catch (Exception $e) {
        $error = 'Action failed: ' . htmlspecialchars($e->getMessage());
    }
}

$queue_items = [];
$archive_items = [];
$log_items = [];
$stats = ['queued' => 0, 'processing' => 0, 'failed' => 0, 'sent' => 0, 'archived' => 0];

try {
    $conn = getDbConnection();

    $res = $conn->query("SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $stats[$row['status']] = (int)$row['cnt'];
        }
    }

    $queue_res = $conn->query("SELECT id, template_key, to_email, to_name, subject, status, attempts, last_error, created_at, sent_at, updated_at FROM email_queue WHERE status IN ('queued','processing','failed','sent') ORDER BY created_at DESC LIMIT 300");
    if ($queue_res) {
        while ($row = $queue_res->fetch_assoc()) {
            $queue_items[] = $row;
        }
    }

    $archive_res = $conn->query("SELECT id, template_key, to_email, subject, status, attempts, last_error, created_at, sent_at, archived_at FROM email_queue WHERE status = 'archived' ORDER BY archived_at DESC, id DESC LIMIT 300");
    if ($archive_res) {
        while ($row = $archive_res->fetch_assoc()) {
            $archive_items[] = $row;
        }
    }

    $logs_res = $conn->query("SELECT id, template_key, to_email, subject, result, error_message, created_at FROM email_logs ORDER BY id DESC LIMIT 300");
    if ($logs_res) {
        while ($row = $logs_res->fetch_assoc()) {
            $log_items[] = $row;
        }
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed loading queue data: ' . htmlspecialchars($e->getMessage());
}

$title = 'Email Queue';
require_once 'header.php';
?>

<h2 class="page-title">Email Queue & Archive</h2>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Queue status</div>
    <div class="kpi-grid" style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;">
        <div class="kpi">Queued: <strong><?php echo (int)$stats['queued']; ?></strong></div>
        <div class="kpi">Processing: <strong><?php echo (int)$stats['processing']; ?></strong></div>
        <div class="kpi">Failed: <strong><?php echo (int)$stats['failed']; ?></strong></div>
        <div class="kpi">Sent: <strong><?php echo (int)$stats['sent']; ?></strong></div>
        <div class="kpi">Archived: <strong><?php echo (int)$stats['archived']; ?></strong></div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <form method="POST" action="email_queue.php" style="display:inline;">
            <input type="hidden" name="action" value="process_queue">
            <button type="submit" class="btn btn-primary">Process queue now</button>
        </form>
        <form method="POST" action="email_queue.php" style="display:inline;" onsubmit="return confirm('Archive all sent emails?');">
            <input type="hidden" name="action" value="archive_all_sent">
            <button type="submit" class="btn btn-secondary">Archive all sent</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <div class="panel-title">Queue items (active + sent)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Template</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Attempts</th>
                    <th>Error</th>
                    <th>Created</th>
                    <th>Sent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queue_items)): ?>
                    <tr><td colspan="10" class="muted">No queue items.</td></tr>
                <?php else: ?>
                    <?php foreach ($queue_items as $row): ?>
                        <tr>
                            <td class="mono"><?php echo (int)$row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($row['template_key'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['to_email']); ?></td>
                            <td><?php echo htmlspecialchars($row['subject']); ?></td>
                            <td><?php echo (int)$row['attempts']; ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($row['last_error'] ?? '')); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['created_at'] ?? '-'); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['sent_at'] ?? '-'); ?></td>
                            <td class="nowrap">
                                <?php if ($row['status'] === 'failed' || $row['status'] === 'queued'): ?>
                                    <form method="POST" action="email_queue.php" style="display:inline;">
                                        <input type="hidden" name="action" value="retry">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-small">Retry</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($row['status'] === 'failed' || $row['status'] === 'sent'): ?>
                                    <form method="POST" action="email_queue.php" style="display:inline;" onsubmit="return confirm('Archive this item?');">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-secondary">Archive</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <div class="panel-title">Archive</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Template</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Attempts</th>
                    <th>Error</th>
                    <th>Created</th>
                    <th>Sent</th>
                    <th>Archived</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($archive_items)): ?>
                    <tr><td colspan="9" class="muted">No archived emails.</td></tr>
                <?php else: ?>
                    <?php foreach ($archive_items as $row): ?>
                        <tr>
                            <td class="mono"><?php echo (int)$row['id']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($row['template_key'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['to_email']); ?></td>
                            <td><?php echo htmlspecialchars($row['subject']); ?></td>
                            <td><?php echo (int)$row['attempts']; ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($row['last_error'] ?? '')); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['created_at'] ?? '-'); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['sent_at'] ?? '-'); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['archived_at'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <div class="panel-title">Delivery log archive</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Template</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Result</th>
                    <th>Error</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log_items)): ?>
                    <tr><td colspan="7" class="muted">No delivery logs.</td></tr>
                <?php else: ?>
                    <?php foreach ($log_items as $row): ?>
                        <tr>
                            <td class="mono"><?php echo (int)$row['id']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($row['template_key'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['to_email']); ?></td>
                            <td><?php echo htmlspecialchars($row['subject'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['result'] ?? ''); ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($row['error_message'] ?? '')); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($row['created_at'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'footer.php'; ?>
