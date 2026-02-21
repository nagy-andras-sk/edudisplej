<?php
/**
 * Dashboard – Company Kiosk List
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id    = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$company_name = $_SESSION['company_name'] ?? '';

if (!$company_id) {
    header('Location: ../login.php');
    exit();
}

$kiosks = [];
$groups = [];
$error  = '';

try {
    $conn = getDbConnection();

    // Load groups for this company
    $stmt = $conn->prepare(
        "SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name"
    );
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    $stmt->close();

    // Load kiosks with group assignments
    $stmt = $conn->prepare("
        SELECT k.id, k.hostname, k.status, k.location, k.last_seen,
               k.screenshot_url, k.screenshot_timestamp,
               GROUP_CONCAT(DISTINCT g.id   ORDER BY g.id SEPARATOR ',') AS group_ids,
               GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS group_names
        FROM kiosks k
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        LEFT JOIN kiosk_groups g ON kga.group_id = g.id
        WHERE k.company_id = ?
        GROUP BY k.id
        ORDER BY k.hostname
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
    }
    $stmt->close();

    closeDbConnection($conn);

} catch (Exception $e) {
    $error = 'Adatbázis hiba';
    error_log($e->getMessage());
}

$total   = count($kiosks);
$online  = count(array_filter($kiosks, fn($k) => $k['status'] === 'online'));
$offline = $total - $online;

include '../admin/header.php';
?>

<?php if ($error): ?>
    <div style="color:var(--danger);padding:12px 0;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary / group filter bar -->
<div class="minimal-summary">
    <span class="summary-item active" data-group-filter="all" onclick="filterByGroup('all')">
        <span class="summary-dot dot-total"></span>
        Összes: <strong><?php echo $total; ?></strong>
    </span>
    <span class="summary-item" data-group-filter="online" onclick="filterByGroup('online')">
        <span class="summary-dot dot-online"></span>
        Online: <strong><?php echo $online; ?></strong>
    </span>
    <span class="summary-item" data-group-filter="offline" onclick="filterByGroup('offline')">
        <span class="summary-dot dot-offline"></span>
        Offline: <strong><?php echo $offline; ?></strong>
    </span>
    <?php foreach ($groups as $g): ?>
        <span class="summary-item" data-group-filter="g<?php echo (int)$g['id']; ?>" onclick="filterByGroup('g<?php echo (int)$g['id']; ?>')">
            <span class="summary-dot dot-groups"></span>
            <?php echo htmlspecialchars($g['name']); ?>
        </span>
    <?php endforeach; ?>
</div>

<!-- Kiosk table -->
<div class="table-wrap">
    <table class="minimal-table" id="kiosk-table">
        <thead>
            <tr>
                <th>Hostname</th>
                <th>Státusz</th>
                <th>Hely</th>
                <th>Csoport</th>
                <th>Utolsó szinkron</th>
                <th>Képernyő</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($kiosks)): ?>
                <tr><td colspan="6" style="text-align:center;color:#999;padding:20px;">Nincs regisztrált kijelző.</td></tr>
            <?php else: ?>
                <?php foreach ($kiosks as $k):
                    $gids = $k['group_ids'] ? array_map('intval', explode(',', $k['group_ids'])) : [];
                    $data_groups = implode(' ', array_map(fn($id) => 'g' . $id, $gids));
                    $last_seen_str = $k['last_seen'] ? date('Y-m-d H:i', strtotime($k['last_seen'])) : 'Soha';
                ?>
                    <tr class="kiosk-row"
                        data-status="<?php echo htmlspecialchars($k['status']); ?>"
                        data-groups="<?php echo htmlspecialchars($data_groups); ?>">
                        <td>
                            <a href="#" class="kiosk-hostname-link"
                               onclick="openKioskDetail(<?php echo (int)$k['id']; ?>, <?php echo json_encode($k['hostname'] ?? 'N/A'); ?>); return false;">
                                <?php echo htmlspecialchars($k['hostname'] ?? 'N/A'); ?>
                            </a>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($k['status']); ?>">
                                <?php echo $k['status'] === 'online' ? '🟢 Online' : '🔴 Offline'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($k['location'] ?? '—'); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($k['group_names'] ?? '—'); ?></td>
                        <td class="muted nowrap"><?php echo htmlspecialchars($last_seen_str); ?></td>
                        <td>
                            <?php if ($k['screenshot_url']): ?>
                                <div class="preview-card">
                                    <img class="screenshot-img"
                                         src="<?php echo htmlspecialchars($k['screenshot_url']); ?>"
                                         alt="Screenshot"
                                         loading="lazy">
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Kiosk detail modal -->
<div id="kiosk-modal" class="kiosk-modal" onclick="handleModalBackdropClick(event)">
    <div class="kiosk-modal-box">
        <div class="kiosk-modal-header">
            <span id="kiosk-modal-title" class="kiosk-modal-title"></span>
            <button class="kiosk-modal-close" onclick="closeKioskModal()" aria-label="Bezárás">&times;</button>
        </div>
        <div class="kiosk-modal-body" id="kiosk-modal-body">
            <p class="muted">Betöltés…</p>
        </div>
    </div>
</div>

<script>
var _screenshotKeepaliveTimer = null;
var _currentModalKioskId = null;

function filterByGroup(filter) {
    document.querySelectorAll('.summary-item').forEach(function (el) {
        el.classList.toggle('active', el.dataset.groupFilter === filter);
    });
    document.querySelectorAll('.kiosk-row').forEach(function (row) {
        var status = row.dataset.status;
        var groups = row.dataset.groups ? row.dataset.groups.split(' ') : [];
        var show;
        if (filter === 'all') {
            show = true;
        } else if (filter === 'online') {
            show = status === 'online';
        } else if (filter === 'offline') {
            show = status !== 'online';
        } else {
            show = groups.indexOf(filter) !== -1;
        }
        row.style.display = show ? '' : 'none';
    });
}

function requestScreenshotTTL(kioskId) {
    fetch('../api/screenshot_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kiosk_id: kioskId, ttl_seconds: 60 })
    }).catch(function () {});
}

function stopScreenshotTTL(kioskId) {
    if (_screenshotKeepaliveTimer) {
        clearInterval(_screenshotKeepaliveTimer);
        _screenshotKeepaliveTimer = null;
    }
    fetch('../api/screenshot_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kiosk_id: kioskId, action: 'stop' })
    }).catch(function () {});
}

function closeKioskModal() {
    var modal = document.getElementById('kiosk-modal');
    modal.style.display = 'none';
    if (_currentModalKioskId) {
        stopScreenshotTTL(_currentModalKioskId);
    }
    _currentModalKioskId = null;
    document.getElementById('kiosk-modal-body').innerHTML = '<p class="muted">Betöltés…</p>';
}

function handleModalBackdropClick(event) {
    if (event.target === document.getElementById('kiosk-modal')) {
        closeKioskModal();
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function buildKioskModalHTML(data) {
    var screenshotSrc = data.screenshot_url || '';
    var screenshotTs  = data.screenshot_timestamp || '';
    var screenshotBlock = screenshotSrc
        ? '<div class="preview-card kiosk-modal-screenshot">'
            + '<img class="screenshot-img" id="modal-screenshot-img" src="' + escapeHtml(screenshotSrc) + '" alt="Screenshot">'
            + '<span class="screenshot-timestamp">' + escapeHtml(screenshotTs) + '</span>'
            + '</div>'
        : '<p class="muted">Nincs elérhető képernyőfelvétel.</p>';
    var modulesText = (data.modules && data.modules.length) ? data.modules.join(', ') : '—';

    return '<div class="kiosk-detail-grid">'
        + '<div class="kiosk-detail-info">'
        + '<table class="minimal-table"><tbody>'
        + '<tr><th>ID</th><td>' + escapeHtml(data.id || '—') + '</td></tr>'
        + '<tr><th>Státusz</th><td>' + (data.status === 'online' ? '🟢 Online' : '🔴 Offline') + '</td></tr>'
        + '<tr><th>Hely</th><td>' + escapeHtml(data.location || '—') + '</td></tr>'
        + '<tr><th>Csoport</th><td>' + escapeHtml(data.group_names || '—') + '</td></tr>'
        + '<tr><th>Utolsó szinkron</th><td>' + escapeHtml(data.last_seen || '—') + '</td></tr>'
        + '<tr><th>MAC</th><td class="mono">' + escapeHtml(data.mac || '—') + '</td></tr>'
        + '<tr><th>Verzió</th><td>' + escapeHtml(data.version || '—') + '</td></tr>'
        + '<tr><th>Modulok</th><td>' + escapeHtml(modulesText) + '</td></tr>'
        + '</tbody></table>'
        + '</div>'
        + '<div class="kiosk-detail-screenshot">' + screenshotBlock + '</div>'
        + '</div>';
}

function openKioskDetail(kioskId, hostname) {
    _currentModalKioskId = kioskId;
    var modal = document.getElementById('kiosk-modal');
    var body  = document.getElementById('kiosk-modal-body');
    document.getElementById('kiosk-modal-title').textContent = hostname || ('Kiosk #' + kioskId);
    body.innerHTML = '<p class="muted">Betöltés…</p>';
    modal.style.display = 'flex';

    requestScreenshotTTL(kioskId);
    if (_screenshotKeepaliveTimer) {
        clearInterval(_screenshotKeepaliveTimer);
    }
    _screenshotKeepaliveTimer = setInterval(function () {
        if (_currentModalKioskId) {
            requestScreenshotTTL(_currentModalKioskId);
            var img = document.getElementById('modal-screenshot-img');
            if (img) {
                var base = img.src.split('?')[0];
                img.src = base + '?t=' + Date.now();
            }
        }
    }, 45000);

    fetch('../api/kiosk_details.php?id=' + kioskId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                body.innerHTML = buildKioskModalHTML(data);
            } else {
                body.innerHTML = '<p class="muted">Hiba: ' + escapeHtml(data.message || 'Ismeretlen hiba') + '</p>';
            }
        })
        .catch(function () {
            body.innerHTML = '<p class="muted">Betöltési hiba.</p>';
        });
}

filterByGroup('all');
</script>

<?php include '../admin/footer.php'; ?>
