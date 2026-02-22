<?php
/**
 * Content Editor Dashboard
 * Minimal view: groups, kiosks, editable modules, direct content edit action.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../auth_roles.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$role = edudisplej_get_session_role();
if ($role !== 'content_editor') {
    header('Location: index.php');
    exit();
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
if ($company_id <= 0) {
    header('Location: ../login.php');
    exit();
}

$company_name = '';
$error = '';
$groups = [];

try {
    $conn = getDbConnection();

    $company_stmt = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
    $company_stmt->bind_param("i", $company_id);
    $company_stmt->execute();
    $company_row = $company_stmt->get_result()->fetch_assoc();
    $company_stmt->close();
    $company_name = (string)($company_row['name'] ?? '');

    $group_stmt = $conn->prepare("SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name");
    $group_stmt->bind_param("i", $company_id);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();

    while ($group = $group_result->fetch_assoc()) {
        $group_id = (int)$group['id'];

        $kiosk_stmt = $conn->prepare("SELECT k.id, COALESCE(NULLIF(k.friendly_name, ''), k.hostname) AS display_name
                                      FROM kiosk_group_assignments kga
                                      JOIN kiosks k ON k.id = kga.kiosk_id
                                      WHERE kga.group_id = ?
                                      ORDER BY display_name");
        $kiosk_stmt->bind_param("i", $group_id);
        $kiosk_stmt->execute();
        $kiosk_result = $kiosk_stmt->get_result();

        $kiosks = [];
        while ($kiosk = $kiosk_result->fetch_assoc()) {
            $kiosks[] = [
                'id' => (int)$kiosk['id'],
                'name' => (string)$kiosk['display_name'],
            ];
        }
        $kiosk_stmt->close();

        $module_stmt = $conn->prepare("SELECT DISTINCT m.name
                                       FROM kiosk_group_modules kgm
                                       JOIN modules m ON m.id = kgm.module_id
                                       WHERE kgm.group_id = ? AND kgm.is_active = 1
                                       ORDER BY m.name");
        $module_stmt->bind_param("i", $group_id);
        $module_stmt->execute();
        $module_result = $module_stmt->get_result();

        $modules = [];
        while ($module = $module_result->fetch_assoc()) {
            $modules[] = (string)$module['name'];
        }
        $module_stmt->close();

        $groups[] = [
            'id' => $group_id,
            'name' => (string)$group['name'],
            'kiosks' => $kiosks,
            'modules' => $modules,
        ];
    }

    $group_stmt->close();
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Adatbázis hiba történt.';
    error_log('content_editor_index.php: ' . $e->getMessage());
}

$breadcrumb_items = [
    ['label' => '🖥️ Kijelzők', 'current' => true],
];
$logout_url = '../login.php?logout=1';

include '../admin/header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Tartalom módosító nézet</div>
    <div class="muted">
        <?php if ($company_name !== ''): ?>
            Intézmény: <strong><?php echo htmlspecialchars($company_name); ?></strong>
        <?php else: ?>
            Csak csoportok, kijelzők és modul tartalom szerkesztés érhető el.
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Csoportok és szerkeszthető tartalmak</div>

    <?php if (empty($groups)): ?>
        <div class="muted">Nincs elérhető csoport.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Csoport</th>
                        <th>Kijelzők</th>
                        <th>Szerkeszthető modulok</th>
                        <th>Művelet</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($group['name']); ?></strong></td>
                            <td>
                                <?php if (empty($group['kiosks'])): ?>
                                    <span class="muted">Nincs kijelző</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars(implode(', ', array_map(fn($k) => $k['name'], $group['kiosks']))); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($group['modules'])): ?>
                                    <span class="muted">Nincs aktív modul</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars(implode(', ', $group['modules'])); ?>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <a class="btn btn-small btn-primary" href="group_loop/index.php?id=<?php echo (int)$group['id']; ?>">Tartalom szerkesztése</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include '../admin/footer.php'; ?>
