<?php
session_start();
require_once '../auth_roles.php';
require_once '../i18n.php';
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

function edudisplej_dashboard_has_module_license(string $module_key): bool {
    $module_key = strtolower(trim($module_key));
    if ($module_key === '') {
        return false;
    }

    if (!empty($_SESSION['isadmin']) && empty($_SESSION['admin_acting_company_id'])) {
        return true;
    }

    $company_id = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    if ($company_id <= 0) {
        $company_id = (int)($_SESSION['company_id'] ?? 0);
    }
    if ($company_id <= 0) {
        return false;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT m.id
                                FROM modules m
                                INNER JOIN module_licenses ml ON ml.module_id = m.id
                                WHERE m.module_key = ? AND m.is_active = 1
                                  AND ml.company_id = ? AND ml.quantity > 0
                                LIMIT 1");
        $stmt->bind_param('si', $module_key, $company_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
        return !empty($row);
    } catch (Throwable $e) {
        error_log('dashboard/modules.php license check failed: ' . $e->getMessage());
        return false;
    }
}

$can_manage_text_collections = edudisplej_can_edit_module_content() && edudisplej_dashboard_has_module_license('text');
$can_manage_meal_plan_config = edudisplej_can_edit_module_content() && edudisplej_dashboard_has_module_license('meal-menu');
$can_manage_room_occupancy = edudisplej_can_edit_module_content() && edudisplej_dashboard_has_module_license('room-occupancy');

$module_links = [];
if ($can_manage_text_collections) {
    $module_links[] = [
        'label' => '📝 Slide-ok',
        'description' => 'Előre elkészített slide tartalmak kezelése.',
        'href' => 'text_collections.php',
    ];
}
if ($can_manage_meal_plan_config) {
    $module_links[] = [
        'label' => '🍽️ Étrend',
        'description' => 'Étrend modul beállítások és manuális étrend naptár kezelése.',
        'href' => 'text_collection_meal_calendar.php',
    ];
}
if ($can_manage_room_occupancy) {
    $module_links[] = [
        'label' => '🏫 Terem foglaltság',
        'description' => 'Termek és idősávos foglaltság kezelése; szerver-integráció admin oldalon.',
        'href' => 'room_occupancy_config.php',
    ];
}

$breadcrumb_items = [
    ['label' => '🧩 Modulok', 'current' => true],
];
$logout_url = '../login.php?logout=1';

include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Modulok</div>
    <div class="muted">Itt éred el azokat a modul kezelő oldalakat, amelyekhez jogosultságod és modul licenced van.</div>
</div>

<div class="panel">
    <div class="panel-title">Elérhető modul funkciók</div>

    <?php if (empty($module_links)): ?>
        <div class="muted">Jelenleg nincs elérhető modul funkció ennél a felhasználónál/intézménynél.</div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:10px;">
            <?php foreach ($module_links as $item): ?>
                <div class="panel" style="margin:0;">
                    <div style="font-weight:700; margin-bottom:6px;"><?php echo htmlspecialchars($item['label']); ?></div>
                    <div class="muted" style="margin-bottom:10px;"><?php echo htmlspecialchars($item['description']); ?></div>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($item['href']); ?>">Megnyitás</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../admin/footer.php'; ?>
