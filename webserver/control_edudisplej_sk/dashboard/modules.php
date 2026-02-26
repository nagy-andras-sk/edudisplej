<?php
session_start();
require_once '../auth_roles.php';
require_once '../i18n.php';
require_once '../dbkonfiguracia.php';

$current_lang = edudisplej_apply_language_preferences();

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
        'label' => '📝 ' . t_def('modules.text_collections.label', 'Slide collections'),
        'description' => t_def('modules.text_collections.description', 'Manage pre-built slide content collections.'),
        'href' => 'text_collections.php',
    ];
}
if ($can_manage_meal_plan_config) {
    $module_links[] = [
        'label' => '🍽️ ' . t_def('modules.meal_menu.label', 'Meal plan'),
        'description' => t_def('modules.meal_menu.description', 'Manage meal module settings and manual meal calendar.'),
        'href' => 'text_collection_meal_calendar.php',
    ];
}
if ($can_manage_room_occupancy) {
    $module_links[] = [
        'label' => '🏫 ' . t_def('modules.room_occupancy.label', 'Room occupancy'),
        'description' => t_def('modules.room_occupancy.description', 'Manage rooms and slot occupancy; server integration on admin page.'),
        'href' => 'room_occupancy_config.php',
    ];
}

$breadcrumb_items = [
    ['label' => '🧩 ' . t('nav.modules'), 'current' => true],
];
$logout_url = '../login.php?logout=1';

include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title"><?php echo htmlspecialchars(t('nav.modules')); ?></div>
    <div class="muted"><?php echo htmlspecialchars(t_def('modules.page.subtitle', 'Access module management pages available for your role and module licenses.')); ?></div>
</div>

<div class="panel">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('modules.page.available_features', 'Available module features')); ?></div>

    <?php if (empty($module_links)): ?>
        <div class="muted"><?php echo htmlspecialchars(t_def('modules.page.none_available', 'No module features are currently available for this user/institution.')); ?></div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:10px;">
            <?php foreach ($module_links as $item): ?>
                <div class="panel" style="margin:0;">
                    <div style="font-weight:700; margin-bottom:6px;"><?php echo htmlspecialchars($item['label']); ?></div>
                    <div class="muted" style="margin-bottom:10px;"><?php echo htmlspecialchars($item['description']); ?></div>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($item['href']); ?>"><?php echo htmlspecialchars(t_def('common.open', 'Open')); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../admin/footer.php'; ?>
