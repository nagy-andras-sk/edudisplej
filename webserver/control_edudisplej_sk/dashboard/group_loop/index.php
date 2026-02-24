<?php
/**
 * Group Loop Configuration
 * Advanced loop editor with drag-and-drop
 */

session_start();
require_once '../../dbkonfiguracia.php';
require_once '../../i18n.php';
require_once '../../auth_roles.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$session_role = edudisplej_get_session_role();
$is_content_only_mode = ($session_role === 'content_editor');

if (!edudisplej_can_edit_module_content()) {
    http_response_code(403);
    echo 'Access denied';
    exit();
}

$error = '';
$success = '';
$company_name = '';
$is_default_group = false;

$group = null;

try {
    $conn = getDbConnection();
    
    // Get user and company info
    $stmt = $conn->prepare("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user) {
        $company_id = $user['company_id'];
        $company_name = $user['company_name'] ?? '';
    }
    
    // Get group info
    $stmt = $conn->prepare("SELECT * FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Check permissions: only groups of the current company are accessible
    if (!$group || (int)($group['company_id'] ?? 0) !== (int)$company_id) {
        http_response_code(403);
        echo 'Access denied';
        exit();
    }

    $is_default_group = (!empty($group['is_default']) || strtolower($group['name']) === 'default');
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $error = 'Adatbázis hiba: ' . $e->getMessage();
    error_log($e->getMessage());
}

$breadcrumb_group_name = trim((string)($group['name'] ?? ''));
if ($breadcrumb_group_name === '') {
    $breadcrumb_group_name = 'Csoport #' . (int)$group_id;
}

$breadcrumb_items = [
    ['label' => '📁 ' . t('nav.groups'), 'href' => '../groups.php'],
    ['label' => '👥 ' . $breadcrumb_group_name, 'href' => '../group_kiosks.php?id=' . (int)$group_id],
    ['label' => '⚙️ Loop config', 'current' => true],
];

$logout_url = '../../login.php?logout=1';

// Get available modules for this company
$available_modules = [];
$unconfigured_module = null;
$turned_off_loop_action = null;
try {
    $conn = getDbConnection();
    
    // Get modules - only those explicitly enabled (licensed) for this company
    $stmt = $conn->prepare("SELECT m.*, COALESCE(ml.quantity, 0) as license_quantity
                            FROM modules m 
                            LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
                            WHERE m.is_active = 1 
                            ORDER BY m.name");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (($row['module_key'] ?? '') === 'unconfigured') {
            $unconfigured_module = $row;
            continue;
        }

        // Include module only if enabled for this company
        if ($row['license_quantity'] > 0) {
            $available_modules[] = $row;
        }
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
}

if ($unconfigured_module) {
    $turned_off_loop_action = [
        'id' => (int)$unconfigured_module['id'],
        'name' => 'Turned Off',
        'description' => 'Kijelző kikapcsolása: tartalomszolgáltatás leáll, HDMI kimenet kikapcsol.',
        'module_key' => 'turned-off',
    ];
}

$group_loop_modules_catalog = array_values(array_merge($available_modules, $unconfigured_module ? [$unconfigured_module] : []));
$group_loop_css_version = (string)(@filemtime(__DIR__ . '/assets/css/app.css') ?: time());
$group_loop_js_version_app = (string)(@filemtime(__DIR__ . '/assets/js/app.js') ?: time());
$group_loop_js_version_pdf = (string)(@filemtime(__DIR__ . '/assets/js/modules/pdf.js') ?: $group_loop_js_version_app);
$group_loop_js_version_gallery = (string)(@filemtime(__DIR__ . '/assets/js/modules/gallery.js') ?: $group_loop_js_version_app);
$group_loop_js_version_video = (string)(@filemtime(__DIR__ . '/assets/js/modules/video.js') ?: $group_loop_js_version_app);

function edudisplej_group_loop_module_emoji(string $moduleKey): string {
    $key = strtolower(trim($moduleKey));
    $map = [
        'clock' => '🕒',
        'datetime' => '🕒',
        'default-logo' => '🏷️',
        'text' => '📝',
        'pdf' => '📄',
        'image-gallery' => '🖼️',
        'gallery' => '🖼️',
        'video' => '🎬',
        'weather' => '🌤️',
        'rss' => '📰',
        'turned-off' => '⏻',
        'unconfigured' => '⚙️',
    ];

    return $map[$key] ?? '🧩';
}
?>
<?php include '../../admin/header.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo rawurlencode($group_loop_css_version); ?>">
    <?php if (false): ?>
    <style>
        .container {
            max-width: 100% !important;
            width: 100%;
            padding: 20px 14px;
        }

        .loop-page-header {
            background: white;
            padding: 10px 14px;
            border-radius: 0;
            margin-bottom: 20px;
            box-shadow: none;
            border: 1px solid #d4d9df;
        }
        
        .loop-page-header h1 {
            color: #333;
            margin: 0;
            font-size: 15px;
            display: inline;
        }
        
        .loop-page-header p {
            color: #666;
            font-size: 13px;
            margin: 0;
            display: inline;
        }

        .loop-main-header {
            background: #f8f9fb;
            text-align: left;
            padding: 12px 16px;
            font-size: 14px;
            color: #1f2d3d;
            font-weight: 600;
            border: 1px solid #cfd6dd;
            margin-bottom: 10px;
        }

        .loop-main-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) clamp(280px, 20vw, 420px);
            gap: 10px;
            align-items: start;
        }

        .loop-main-left,
        .loop-main-right {
            min-width: 0;
        }

        .loop-main-left {
            overflow: hidden;
        }

        .builder-top-layout {
            display: grid;
            grid-template-columns: minmax(240px, 28%) minmax(0, 72%);
            gap: 12px;
            align-items: start;
        }

        .builder-top-layout > div {
            min-width: 0;
        }

        .builder-left-column {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            align-items: start;
        }

        .planner-panel {
            border: 1px solid #cfd6dd;
            background: #fff;
            padding: 10px;
            margin-bottom: 12px;
        }

        .planner-title {
            font-size: 14px;
            font-weight: 700;
            color: #1f2d3d;
            margin-bottom: 6px;
        }

        .planner-legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #425466;
            margin-bottom: 8px;
        }

        .planner-legend .dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1px solid #cfd6dd;
            margin-right: 4px;
            vertical-align: middle;
        }

        .planner-legend .weekly {
            background: #d9ebff;
        }

        .planner-legend .special {
            background: #ffe9b5;
        }

        .planner-legend .active {
            background: #fff;
            outline: 2px solid #1f3e56;
            outline-offset: -2px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 280px 1fr 380px;
            gap: 20px;
        }
        
        @media (max-width: 1100px) {
            .loop-main-layout,
            .builder-top-layout {
                grid-template-columns: 1fr;
            }

            .preview-panel {
                position: static;
                top: auto;
                max-height: none;
                overflow: visible;
            }
        }

        @media (min-width: 1101px) and (max-width: 1500px) {
            .builder-top-layout {
                grid-template-columns: minmax(220px, 30%) minmax(0, 70%);
            }
        }
        
        .modules-panel {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
            max-height: 540px;
            min-height: 320px;
            overflow-y: auto;
        }
        
        .modules-panel h2 {
            margin-bottom: 10px;
            color: #1f2d3d;
            font-size: 16px;
            border-bottom: 1px solid #e3e8ee;
            padding-bottom: 6px;
        }
        
        .module-item {
            background: #f4f6f8;
            padding: 12px;
            border-radius: 0;
            margin-bottom: 10px;
            cursor: grab;
            transition: border-color 0.2s, background 0.2s;
            border: 1px solid #c7ced6;
        }

        .module-item:active {
            cursor: grabbing;
        }
        
        .module-item:hover {
            background: #e9edf1;
            border-color: #2c3e50;
        }
        
        .module-item.selected {
            border-color: #2c3e50;
            background: #dde3ea;
        }
        
        .module-name {
            font-weight: bold;
            color: #333;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .module-desc {
            font-size: 12px;
            color: #666;
        }

        .loop-styles-panel {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
            max-height: 420px;
            overflow-y: auto;
        }

        .loop-styles-panel h2 {
            margin-bottom: 10px;
            color: #1f2d3d;
            font-size: 16px;
            border-bottom: 1px solid #e3e8ee;
            padding-bottom: 6px;
        }

        .loop-style-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-top: 8px;
        }

        .loop-style-card {
            background: #f4f6f8;
            border: 1px solid #c7ced6;
            padding: 10px;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }

        .loop-style-card:hover {
            background: #e9edf1;
            border-color: #2c3e50;
        }

        .loop-style-card.active {
            border-color: #1f3e56;
            background: #dcecfb;
        }

        .loop-style-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .loop-style-card-name {
            font-size: 13px;
            font-weight: 700;
            color: #1f2d3d;
        }

        .loop-style-card-meta {
            font-size: 12px;
            color: #5a6673;
        }

        .loop-style-card-actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .loop-style-card-actions .btn {
            padding: 4px 8px;
            font-size: 11px;
        }

        .loop-detail-placeholder {
            border: 1px dashed #9aa6b2;
            background: #f4f6f8;
            min-height: 320px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #607080;
            font-size: 13px;
            text-align: center;
            padding: 12px;
        }
        
        .loop-builder {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
            min-height: calc(100vh - 290px);
        }
        
        .loop-builder h2 {
            margin-bottom: 10px;
            color: #1f2d3d;
            font-size: 16px;
            border-bottom: 1px solid #e3e8ee;
            padding-bottom: 6px;
        }
        
        #loop-container {
            min-height: 300px;
            max-height: calc(100vh - 500px);
            border: 1px dashed #9aa6b2;
            border-radius: 0;
            padding: 15px;
            background: #f4f6f8;
            overflow-y: auto;
        }

        #loop-container.catalog-drop-active {
            border-color: #1f3e56;
            background: #eaf2fb;
        }
        
        #loop-container.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 14px;
        }
        
        .loop-item {
            background: #1f2f3f;
            color: #fff;
            padding: 10px 12px;
            border-radius: 0;
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: move;
            box-shadow: none;
            transition: background 0.2s;
            border: 1px solid #172230;
        }
        
        .loop-item:hover {
            transform: none;
            box-shadow: none;
            background: #22384f;
        }
        
        .loop-item.dragging {
            opacity: 0.5;
        }
        
        .loop-order {
            background: rgba(255,255,255,0.2);
            padding: 8px 12px;
            border-radius: 0;
            font-weight: bold;
            min-width: 40px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.25);
        }
        
        .loop-details {
            flex: 1;
        }
        
        .loop-module-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .loop-module-desc {
            font-size: 12px;
            opacity: 0.9;
            line-height: 1.35;
            white-space: normal;
        }
        
        .loop-duration {
            background: rgba(255,255,255,0.2);
            padding: 8px;
            border-radius: 0;
            min-width: 100px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .loop-duration input {
            width: 60px;
            padding: 5px;
            border: 1px solid #d0d7df;
            border-radius: 0;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
        }
        
        .loop-duration label {
            font-size: 11px;
            opacity: 0.9;
            display: block;
            margin-bottom: 4px;
        }
        
        .loop-actions {
            display: flex;
            gap: 8px;
        }
        
        .loop-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 8px 12px;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .loop-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .control-panel {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 9px 14px;
            border: 1px solid transparent;
            border-radius: 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            white-space: nowrap;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: none;
            box-shadow: none;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .total-duration {
            background: #e7f3ff;
            padding: 9px 12px;
            border-radius: 0;
            color: #0066cc;
            font-weight: bold;
            margin-left: auto;
            border: 1px solid #c2d8f2;
            font-size: 13px;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 0;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Live Preview Panel */
        .preview-panel {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 10px;
            max-height: calc(100vh - 24px);
            overflow: auto;
        }
        
        .preview-panel h2 {
            margin-bottom: 8px;
            color: #1f2d3d;
            font-size: 16px;
            border-bottom: 1px solid #e3e8ee;
            padding-bottom: 6px;
        }
        
        .preview-screen {
            background: #1a1a1a;
            border-radius: 0;
            overflow: hidden;
            position: relative;
            min-height: 0;
            aspect-ratio: 16 / 9;
            width: 320px;
            height: 180px;
            margin: 0 auto;
            border: 1px solid #2a2a2a;
        }
        
        .preview-iframe {
            width: 100%;
            height: 100%;
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: center center;
        }
        
        .preview-empty {
            color: #666;
            text-align: center;
            padding: 16px;
            font-size: 12px;
        }
        
        .preview-controls {
            margin-top: 10px;
            display: flex;
            gap: 6px;
        }
        
        .preview-btn {
            flex: 1;
            padding: 6px;
            border: 1px solid rgba(0,0,0,0.2);
            border-radius: 0;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
            font-size: 12px;
        }
        
        .preview-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .preview-btn-play {
            background: #28a745;
            color: white;
        }
        
        .preview-btn-play:hover:not(:disabled) {
            background: #218838;
        }
        
        .preview-btn-pause {
            background: #ffc107;
            color: #000;
        }
        
        .preview-btn-pause:hover:not(:disabled) {
            background: #e0a800;
        }
        
        .preview-btn-stop {
            background: #dc3545;
            color: white;
        }
        
        .preview-btn-stop:hover:not(:disabled) {
            background: #c82333;
        }
        
        .preview-navigation {
            margin-top: 10px;
            display: flex;
            gap: 6px;
            align-items: center;
        }
        
        .preview-nav-btn {
            flex: 1;
            padding: 7px;
            border: 1px solid #112638;
            border-radius: 0;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.2s;
            background: #1a3a52;
            color: white;
        }
        
        .preview-nav-btn:hover:not(:disabled) {
            background: #0f2537;
            transform: none;
        }
        
        .preview-nav-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .preview-nav-info {
            padding: 6px 8px;
            background: #f8f9fa;
            border-radius: 0;
            font-size: 12px;
            font-weight: bold;
            color: #333;
            min-width: 56px;
            text-align: center;
            border: 1px solid #cfd6dd;
        }
        
        .preview-progress {
            margin-top: 10px;
            background: #e9ecef;
            border-radius: 0;
            overflow: hidden;
            height: 22px;
            position: relative;
            border: 1px solid #cfd6dd;
        }
        
        .preview-progress-bar {
            height: 100%;
            background: #1f3e56;
            transition: width 0.1s linear;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: bold;
        }
        
        .preview-info {
            margin-top: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 0;
            font-size: 12px;
            border: 1px solid #cfd6dd;
        }
        
        .preview-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        
        .preview-info-row:last-child {
            margin-bottom: 0;
        }
        
        .preview-info-label {
            font-weight: bold;
            color: #666;
        }
        
        .preview-info-value {
            color: #333;
        }

        .time-block-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
            padding: 8px;
            border: 1px solid #cfd6dd;
            background: #f8f9fb;
        }

        .time-block-toolbar label {
            font-size: 12px;
            color: #425466;
            font-weight: 600;
        }

        .time-block-toolbar select,
        .time-block-toolbar input {
            min-height: 32px;
            border: 1px solid #c7ced6;
            background: #fff;
            padding: 4px 8px;
        }

        .schedule-workspace {
            display: grid;
            grid-template-columns: 30% 70%;
            gap: 12px;
            align-items: start;
        }

        .schedule-loop-column,
        .schedule-calendar-column {
            min-width: 0;
        }

        .schedule-loop-column .time-block-toolbar {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            align-items: stretch;
        }

        .schedule-loop-column .time-block-toolbar .btn {
            width: 100%;
            text-align: left;
        }

        .schedule-grid-wrap {
            margin-bottom: 12px;
            border: 1px solid #cfd6dd;
            overflow: auto;
            max-height: 280px;
            background: #fff;
        }

        .schedule-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .schedule-grid th,
        .schedule-grid td {
            border: 1px solid #e3e7eb;
            text-align: center;
            padding: 3px;
            min-width: 38px;
        }

        .schedule-grid thead th {
            position: sticky;
            top: 0;
            background: #f4f7fb;
            z-index: 1;
        }

        .schedule-grid thead th.schedule-day-today {
            background: #d8eafc;
            color: #1f2d3d;
            box-shadow: inset 0 -2px 0 #1f3e56;
        }

        .schedule-grid .hour-col {
            min-width: 48px;
            font-weight: 700;
            color: #4a5568;
            background: #fafbfd;
        }

        .schedule-grid.step-30 .hour-col,
        .schedule-grid.step-15 .hour-col {
            font-size: 10px;
            line-height: 1.1;
        }

        .schedule-cell {
            cursor: pointer;
            user-select: none;
            background: #fff;
            vertical-align: middle;
        }

        .schedule-grid.step-30 .schedule-cell,
        .schedule-grid.step-15 .schedule-cell {
            padding: 2px 1px;
            min-width: 40px;
        }

        .schedule-grid.selecting .schedule-cell {
            cursor: ns-resize;
        }

        .schedule-cell.has-weekly {
            background: #d9ebff;
        }

        .schedule-cell.has-special {
            background: #ffe9b5;
        }

        .schedule-cell.today {
            box-shadow: inset 0 0 0 1px #8eb6da;
        }

        .schedule-cell.active-scope {
            outline: 2px solid #1f3e56;
            outline-offset: -2px;
        }

        .schedule-cell.range-select {
            background: #b9daf8;
            box-shadow: inset 0 0 0 1px #1f3e56;
        }

        .schedule-cell.locked {
            background: #d5e6f8;
            box-shadow: inset 0 0 0 1px #2b587d;
        }

        .schedule-cell-label {
            display: block;
            font-size: 10px;
            line-height: 1.1;
            color: #1f2d3d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .schedule-grid.step-15 .schedule-cell-label {
            font-size: 9px;
        }

        .special-blocks-list {
            margin-bottom: 12px;
            border: 1px solid #cfd6dd;
            background: #fff;
            padding: 8px;
            max-height: 130px;
            overflow: auto;
            font-size: 12px;
        }

        .special-blocks-list .item {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px dashed #e4e8ec;
        }

        .special-blocks-list .item:last-child {
            border-bottom: none;
        }

        .special-search-input {
            min-width: 220px;
            width: min(380px, 100%);
            border: 1px solid #c7ced6;
            background: #fff;
            padding: 6px 8px;
            min-height: 32px;
        }

        @media (max-width: 1100px) {
            .schedule-workspace {
                grid-template-columns: 1fr;
            }
        }

        .autosave-toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 3000;
            background: #1f7a3f;
            color: #fff;
            border: 1px solid rgba(0, 0, 0, 0.12);
            padding: 10px 14px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
            font-size: 13px;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .autosave-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .autosave-toast.error {
            background: #a12622;
        }
    </style>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="loop-main-header">
        ⚙️ Loop Testreszabás • Csoport:
        <strong id="group-name-display"><?php echo htmlspecialchars($group['name']); ?></strong>
        <?php if (!$is_default_group && !$is_content_only_mode): ?>
            <button type="button" id="group-name-edit-btn" onclick="toggleGroupNameEdit(true)" style="margin-left:8px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Átnevezés">✏️</button>
            <span id="group-name-edit-wrap" style="display:none;margin-left:8px;">
                <input type="text" id="rename-group-inline-input" value="<?php echo htmlspecialchars($group['name'] ?? '', ENT_QUOTES); ?>" style="min-width:220px;">
                <button type="button" onclick="renameCurrentGroup()" style="margin-left:6px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Jóváhagyás">✅</button>
                <button type="button" onclick="toggleGroupNameEdit(false)" style="margin-left:2px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Mégse">✖️</button>
            </span>
        <?php endif; ?>
    </div>

    <div class="loop-main-layout">
        <div class="loop-main-left">
            <div class="loop-builder" id="loop-builder">
                <h2 id="loop-config-title">🔄 Csoport loopok</h2>

                <div class="loop-style-selector-panel">
                    <div class="loop-style-selector-row">
                        <label for="loop-style-select">Kiválasztott loop lista:</label>
                        <select id="loop-style-select" onchange="openLoopStyleDetail(this.value)"></select>
                        <?php if (!$is_default_group && !$is_content_only_mode): ?>
                            <button class="btn" type="button" onclick="createLoopStyle()">+ Új loop</button>
                            <button class="btn btn-danger" id="loop-style-delete-btn" type="button" onclick="deleteActiveLoopStyle()">🗑️ Loop törlése</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="loop-workspace-placeholder" class="loop-detail-placeholder" style="display:none; min-height:180px; margin-top:10px;">
                    Először válassz egy loop listát a legördülő mezőből. Utána megjelenik a modul katalógus, az aktív loop szerkesztő és az előnézet.
                </div>

                <div id="loop-edit-workspace" class="loop-edit-workspace" style="display:grid;">
                    <div class="loop-workspace-col loop-workspace-modules">
                        <div class="planner-column-header">Modul katalógus</div>
                        <div id="modules-panel-placeholder" class="loop-detail-placeholder" style="display:none; min-height:120px; margin:10px;">
                            Elérhető modulok csak akkor jelennek meg, ha kiválasztasz egy loopot.
                        </div>

                        <div id="modules-panel-wrapper" style="display:block; padding:10px;">
                            <div class="modules-panel">
                                <h2>📦 Elérhető Modulok</h2>

                                <?php if (!$is_default_group): ?>
                                    <?php foreach ($available_modules as $module): ?>
                                        <?php $module_key = (string)($module['module_key'] ?? ''); ?>
                                        <div class="module-item is-collapsed"
                                            data-module-id="<?php echo (int)$module['id']; ?>"
                                            data-module-key="<?php echo htmlspecialchars($module_key, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-module-name="<?php echo htmlspecialchars((string)$module['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-module-desc="<?php echo htmlspecialchars((string)($module['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php if (!$is_content_only_mode): ?>
                                                draggable="true"
                                            <?php endif; ?>
                                        >
                                            <div class="module-item-head">
                                                <div class="module-name"><span class="module-icon"><?php echo htmlspecialchars(edudisplej_group_loop_module_emoji($module_key)); ?></span><?php echo htmlspecialchars($module['name']); ?></div>
                                                <button type="button" class="module-toggle-btn" aria-expanded="false" title="Leírás nyitása/zárása">▾</button>
                                            </div>
                                            <div class="module-desc"><?php echo htmlspecialchars($module['description'] ?? ''); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($unconfigured_module): ?>
                                    <div id="unconfiguredModuleItem" class="module-item is-collapsed"
                                        style="display: <?php echo $is_default_group ? 'block' : 'none'; ?>;"
                                        data-module-id="<?php echo (int)$unconfigured_module['id']; ?>"
                                        data-module-key="<?php echo htmlspecialchars((string)($unconfigured_module['module_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-module-name="<?php echo htmlspecialchars((string)$unconfigured_module['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-module-desc="<?php echo htmlspecialchars((string)($unconfigured_module['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php if (!$is_default_group && !$is_content_only_mode): ?>
                                            draggable="true"
                                        <?php endif; ?>
                                    >
                                        <div class="module-item-head">
                                            <div class="module-name"><span class="module-icon"><?php echo htmlspecialchars(edudisplej_group_loop_module_emoji((string)($unconfigured_module['module_key'] ?? 'unconfigured'))); ?></span><?php echo htmlspecialchars($unconfigured_module['name']); ?></div>
                                            <button type="button" class="module-toggle-btn" aria-expanded="false" title="Leírás nyitása/zárása">▾</button>
                                        </div>
                                        <div class="module-desc"><?php echo htmlspecialchars($unconfigured_module['description'] ?? 'Technikai modul – csak üres loop esetén.'); ?></div>
                                    </div>
                                <?php endif; ?>

                                <p id="noModulesMessage" style="text-align: center; color: #999; padding: 16px 8px; display: <?php echo ($is_default_group || empty($available_modules)) ? 'block' : 'none'; ?>;"><?php echo $is_default_group ? 'A default csoportnál csak az unconfigured modul engedélyezett.' : 'Nincsenek elérhető modulok'; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="loop-workspace-col loop-workspace-editor">
                        <div class="planner-column-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px; text-transform:none; letter-spacing:0; font-size:11px;">
                            <span>Szerkesztett loop: <strong id="active-loop-inline-name">—</strong><span id="active-loop-inline-schedule" style="margin-left:6px; font-weight:400; color:#425466;"></span></span>
                            <?php if (!$is_default_group && !$is_content_only_mode): ?>
                                <span style="display:flex; gap:6px; align-items:center;">
                                    <button class="btn" type="button" onclick="renameActiveLoopStyle()" style="padding:4px 8px; font-size:11px;">✏️ Átnevezés</button>
                                    <button class="btn" type="button" onclick="duplicateActiveLoopStyle()" style="padding:4px 8px; font-size:11px;">📄 Duplikálás</button>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="padding:10px;">
                            <div id="loop-detail-placeholder" class="loop-detail-placeholder" style="display:none;">
                                Válassz egy loopot a legördülő listából a szerkesztéshez.
                            </div>

                            <div id="loop-detail-panel" style="display:block;">
                                <div id="loop-container" class="empty" ondragover="allowModuleCatalogDrop(event)" ondragenter="allowModuleCatalogDrop(event)" ondragleave="handleModuleCatalogDragLeave(event)" ondrop="dropCatalogModuleToLoop(event)">
                                    <p>Nincs elem a loop-ban. Húzz ide modult az „Elérhető Modulok” panelről.</p>
                                </div>

                                <div class="control-panel">
                                    <button class="btn btn-danger" onclick="clearLoop()" <?php echo ($is_default_group || $is_content_only_mode) ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>🗑️ Összes törlése</button>
                                    <div class="total-duration" id="total-duration">Össz: 0 mp</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="loop-workspace-col loop-workspace-preview" id="preview-panel-wrapper">
                        <div class="planner-column-header">Előnézet</div>
                        <div style="padding:10px;">
                            <div class="preview-panel">
                                <h2 id="preview-title">📺 Loop előnézete</h2>
                                
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; font-weight: bold; margin-bottom: 5px; color: #333; font-size: 13px;">
                                        Felbontás szerinti előnézet:
                                    </label>
                                    <select id="resolutionSelector" onchange="updatePreviewResolution()" style="
                                        width: 100%;
                                        padding: 8px 12px;
                                        border: 2px solid #1a3a52;
                                        border-radius: 5px;
                                        font-size: 13px;
                                        background: white;
                                        cursor: pointer;
                                        font-weight: 500;
                                    ">
                                        <option value="1920x1080">1920x1080 (16:9 Full HD)</option>
                                        <option value="1280x720">1280x720 (16:9 HD)</option>
                                        <option value="1024x768">1024x768 (4:3 XGA)</option>
                                        <option value="1600x900">1600x900 (16:9 HD+)</option>
                                        <option value="1366x768">1366x768 (16:9 WXGA)</option>
                                    </select>
                                </div>
                                
                                <div class="preview-screen" id="previewScreen">
                                    <div class="preview-empty" id="previewEmpty">
                                        <p>🎬 Nincs loop</p>
                                        <p style="font-size: 12px; color: #999; margin-top: 10px;">Adj hozzá modulokat a loop előnézetéhez</p>
                                    </div>
                                    <iframe id="previewIframe" class="preview-iframe" style="display: none;"></iframe>
                                </div>
                                
                                <div class="preview-controls">
                                    <button class="preview-btn preview-btn-play" id="btnPlay" onclick="startPreview()">▶️ Lejátszás</button>
                                    <button class="preview-btn preview-btn-pause" id="btnPause" onclick="pausePreview()" style="display: none;">⏸️ Szünet</button>
                                    <button class="preview-btn preview-btn-stop" id="btnStop" onclick="stopPreview()">⏹️ Stop</button>
                                </div>
                                
                                <div class="preview-progress">
                                    <div class="preview-progress-bar" id="progressBar" style="width: 0%;">
                                        <span id="progressText">0s / 0s</span>
                                    </div>
                                </div>
                                
                                <div class="preview-navigation">
                                    <button class="preview-nav-btn" id="btnPrev" onclick="previousModule()" title="Előző modul">◄</button>
                                    <div class="preview-nav-info" id="navInfo">—</div>
                                    <button class="preview-nav-btn" id="btnNext" onclick="nextModule()" title="Következő modul">►</button>
                                </div>
                                
                                <div class="preview-info" id="previewInfo">
                                    <div class="preview-info-row">
                                        <span class="preview-info-label">Aktuális modul:</span>
                                        <span class="preview-info-value" id="currentModule">—</span>
                                    </div>
                                    <div class="preview-info-row">
                                        <span class="preview-info-label">Loop állapot:</span>
                                        <span class="preview-info-value" id="loopStatus">Leállítva</span>
                                    </div>
                                    <div class="preview-info-row">
                                        <span class="preview-info-label">Ciklusok:</span>
                                        <span class="preview-info-value" id="loopCount">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$is_default_group): ?>
    <div class="planner-panel">
        <div class="planner-title">Ütemezés</div>
        <div class="planner-legend">
            <span><span class="dot weekly"></span>Heti blokk</span>
            <span><span class="dot turned-off"></span>Kikapcsolási terv</span>
            <span><span class="dot active"></span>Aktív tartomány</span>
        </div>

        <div class="schedule-workspace">
            <div class="schedule-loop-column">
                <div class="planner-column-header">Heti loop terv</div>
                <div id="loop-style-drag-list" class="time-block-toolbar" style="margin-bottom:0;"></div>
                <div id="fixed-weekly-planner" class="fixed-weekly-planner-modal" style="display:none;">
                    <div class="fixed-weekly-planner-backdrop" onclick="closeFixedWeeklyPlannerModal()"></div>
                    <div class="fixed-weekly-planner-dialog time-block-toolbar" role="dialog" aria-modal="true" aria-labelledby="fixed-weekly-planner-title" style="display:grid; grid-template-columns:1fr; gap:8px; align-items:stretch;">
                        <input type="hidden" id="fixed-plan-block-id" value="">
                        <input type="hidden" id="fixed-plan-loop-style" value="">
                        <div class="fixed-weekly-planner-head">
                            <label id="fixed-weekly-planner-title" style="font-size:12px; font-weight:700; color:#1f2d3d; margin:0;">Kijelölt heti blokk szerkesztése</label>
                            <button type="button" class="fixed-weekly-close-btn" onclick="closeFixedWeeklyPlannerModal()" aria-label="Bezárás" title="Bezárás">✕</button>
                        </div>
                        <div id="fixed-plan-loop-label" style="font-size:12px; color:#425466; font-weight:600;">Kiválasztott loop: —</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="1">H</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="2">K</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="3">Sze</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="4">Cs</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="5">P</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="6">Szo</label>
                            <label style="display:flex; align-items:center; gap:4px; font-size:12px;"><input type="checkbox" class="fixed-plan-day-checkbox" value="7">V</label>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <select id="fixed-plan-start" aria-label="Heti terv kezdete (óra-perc)"></select>
                            <select id="fixed-plan-end" aria-label="Heti terv vége (óra-perc)"></select>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <button id="fixed-plan-add-btn" type="button" class="btn" onclick="createFixedWeeklyBlockFromInputs()">Frissítés</button>
                            <button type="button" class="btn btn-danger" onclick="deleteSelectedWeeklyPlanBlock()" title="Kijelölt törlése">🗑️</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="schedule-calendar-column">
                <div class="planner-column-header">Heti nézet</div>
                <div class="time-block-toolbar">
                    <button type="button" class="btn" onclick="changeScheduleWeek(-1)" title="Előző hét">◀</button>
                    <span id="schedule-week-label" style="font-size:12px; color:#425466; font-weight:700;"></span>
                    <button type="button" class="btn" onclick="changeScheduleWeek(1)" title="Következő hét">▶</button>
                    <button type="button" class="btn" onclick="openScheduleDatePicker()" title="Dátum választása">📅</button>
                    <input type="date" id="schedule-date-picker" onchange="setScheduleDateFromPicker(this.value)" style="position:absolute; opacity:0; width:1px; height:1px; pointer-events:none;" aria-hidden="true" tabindex="-1">
                    <label for="schedule-grid-step" style="margin-left:8px;">Felbontás:</label>
                    <select id="schedule-grid-step" style="min-width:90px;" onchange="setScheduleGridStep(this.value)">
                        <option value="60">60 perc</option>
                        <option value="30">30 perc</option>
                        <option value="15">15 perc</option>
                    </select>
                </div>
                <div class="schedule-grid-wrap">
                    <table class="schedule-grid" id="weekly-schedule-grid"></table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="pending-save-bar" class="pending-save-bar" style="display:none;">
        <span id="pending-save-text">Nem mentett változtatások</span>
        <div class="pending-save-actions">
            <button type="button" class="btn pending-save-btn" onclick="publishLoopPlan()">💾 Mentés</button>
            <button type="button" class="btn pending-discard-btn" onclick="discardLocalDraft()" title="Elvetés">✕</button>
        </div>
    </div>

    <div id="autosave-toast" class="autosave-toast" aria-live="polite"></div>
    <div id="time-block-modal-host"></div>
    <script>
        window.GroupLoopBootstrap = <?php echo json_encode([
            'groupId' => (int)$group_id,
            'companyId' => (int)$company_id,
            'isDefaultGroup' => (bool)$is_default_group,
            'isContentOnlyMode' => (bool)$is_content_only_mode,
            'technicalModule' => $unconfigured_module ? [
                'id' => (int)$unconfigured_module['id'],
                'name' => $unconfigured_module['name'],
                'description' => $unconfigured_module['description'] ?? ''
            ] : null,
            'turnedOffLoopAction' => $turned_off_loop_action,
            'modulesCatalog' => $group_loop_modules_catalog,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script src="assets/js/modules/pdf.js?v=<?php echo rawurlencode($group_loop_js_version_pdf); ?>"></script>
    <script src="assets/js/modules/gallery.js?v=<?php echo rawurlencode($group_loop_js_version_gallery); ?>"></script>
    <script src="assets/js/modules/video.js?v=<?php echo rawurlencode($group_loop_js_version_video); ?>"></script>
    <script src="assets/js/app.js?v=<?php echo rawurlencode($group_loop_js_version_app); ?>"></script>
    <?php if (false): ?>
    
    <script>
        let loopItems = [];
        let loopStyles = [];
        let timeBlocks = [];
        let activeLoopStyleId = null;
        let defaultLoopStyleId = null;
        let activeScope = 'base';
        let scheduleWeekOffset = 0;
        let nextTempTimeBlockId = -1;
        let hasOpenedLoopDetail = false;
        const groupId = <?php echo $group_id; ?>;
        const isDefaultGroup = <?php echo $is_default_group ? 'true' : 'false'; ?>;
        const isContentOnlyMode = <?php echo $is_content_only_mode ? 'true' : 'false'; ?>;
        const technicalModule = <?php echo json_encode($unconfigured_module ? [
            'id' => (int)$unconfigured_module['id'],
            'name' => $unconfigured_module['name'],
            'description' => $unconfigured_module['description'] ?? ''
        ] : null); ?>;
        hasOpenedLoopDetail = isDefaultGroup;

        function getDefaultUnconfiguredItem() {
            if (!technicalModule) {
                return null;
            }

            return {
                module_id: parseInt(technicalModule.id),
                module_name: technicalModule.name,
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            };
        }
        
        // Preview variables
        let previewInterval = null;
        let previewTimeout = null;
        let currentPreviewIndex = 0;
        let currentModuleStartTime = 0;
        let totalLoopStartTime = 0;
        let isPaused = false;
        let loopCycleCount = 0;
        let autoSaveTimer = null;
        let autoSaveInFlight = false;
        let autoSaveQueued = false;
        let autoSaveToastTimer = null;
        let hasLoadedInitialLoop = false;
        let lastSavedSnapshot = '';
        let scheduleRangeSelection = null;
        let scheduleBlockResize = null;
        let scheduleGridStepMinutes = 60;

        function showAutosaveToast(message, isError = false) {
            const toast = document.getElementById('autosave-toast');
            if (!toast) {
                return;
            }

            toast.textContent = message;
            toast.classList.toggle('error', !!isError);
            toast.classList.add('show');

            if (autoSaveToastTimer) {
                clearTimeout(autoSaveToastTimer);
            }

            autoSaveToastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, isError ? 2800 : 1400);
        }

        function deepClone(value) {
            return JSON.parse(JSON.stringify(value));
        }

        function normalizeDaysMask(daysMask) {
            if (Array.isArray(daysMask)) {
                return daysMask.map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)).join(',');
            }

            const raw = String(daysMask || '').trim();
            if (!raw) {
                return '1,2,3,4,5,6,7';
            }

            const unique = new Set();
            raw.split(',').forEach((part) => {
                const value = String(parseInt(part, 10));
                if (/^[1-7]$/.test(value)) {
                    unique.add(value);
                }
            });
            return unique.size ? Array.from(unique).sort().join(',') : '1,2,3,4,5,6,7';
        }

        function normalizeTimeBlocks(rawBlocks) {
            if (!Array.isArray(rawBlocks)) {
                return [];
            }

            return rawBlocks.map((block) => ({
                id: block.id != null ? parseInt(block.id, 10) : nextTempTimeBlockId--,
                block_name: String(block.block_name || 'Időblokk'),
                block_type: String(block.block_type || 'weekly') === 'date' ? 'date' : 'weekly',
                specific_date: block.specific_date ? String(block.specific_date).slice(0, 10) : null,
                start_time: String(block.start_time || '08:00:00').slice(0, 8),
                end_time: String(block.end_time || '12:00:00').slice(0, 8),
                days_mask: normalizeDaysMask(block.days_mask),
                priority: Number.isFinite(parseInt(block.priority, 10)) ? parseInt(block.priority, 10) : 100,
                loop_style_id: parseInt(block.loop_style_id || 0, 10) || null,
                is_active: block.is_active === false ? 0 : 1,
                is_locked: parseInt(block.is_locked || block.is_fixed_plan || 0, 10) ? 1 : 0,
                loops: Array.isArray(block.loops) ? block.loops : []
            }));
        }

        function createFallbackLoopStyle(name, items) {
            const styleId = nextTempTimeBlockId--;
            return {
                id: styleId,
                name: name || `Loop ${Math.abs(styleId)}`,
                items: Array.isArray(items) ? deepClone(items) : []
            };
        }

        function getLoopStyleById(styleId) {
            const normalized = parseInt(styleId, 10);
            return loopStyles.find((style) => parseInt(style.id, 10) === normalized) || null;
        }

        function persistActiveLoopStyleItems() {
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }
            style.items = deepClone(loopItems || []);
        }

        function updateLoopStyleMeta() {
            const meta = document.getElementById('loop-style-meta');
            if (!meta) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            const defaultStyle = getLoopStyleById(defaultLoopStyleId);
            const activeName = style ? style.name : '—';
            const defaultName = defaultStyle ? defaultStyle.name : '—';
            meta.textContent = `Szerkesztett loop: ${activeName} • Alap fallback loop (üres idő): ${defaultName}`;
        }

        function updateActiveLoopVisualState() {
            const style = getLoopStyleById(activeLoopStyleId);
            const styleName = style ? String(style.name || 'Loop') : '—';

            const configTitle = document.getElementById('loop-config-title');
            if (configTitle) {
                configTitle.textContent = `🔄 Csoport loopok — ${styleName}`;
            }

            const previewTitle = document.getElementById('preview-title');
            if (previewTitle) {
                previewTitle.textContent = `📺 ${styleName} loop előnézete`;
            }

            const banner = document.getElementById('active-loop-banner');
            if (banner) {
                banner.textContent = `Szerkesztett loop: ${styleName}`;
            }

            const inlineName = document.getElementById('active-loop-inline-name');
            if (inlineName) {
                inlineName.textContent = styleName;
            }
        }

        function toggleLoopDetailVisibility() {
            const detailPanel = document.getElementById('loop-detail-panel');
            const placeholder = document.getElementById('loop-detail-placeholder');
            if (!detailPanel) {
                return;
            }
            if (isDefaultGroup) {
                detailPanel.style.display = 'block';
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                return;
            }

            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showDetail = hasOpenedLoopDetail && hasActive;
            detailPanel.style.display = showDetail ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showDetail ? 'none' : 'flex';
            }
        }

        function toggleModulesCatalogVisibility() {
            const wrapper = document.getElementById('modules-panel-wrapper');
            const placeholder = document.getElementById('modules-panel-placeholder');
            if (!wrapper) {
                return;
            }

            if (isDefaultGroup) {
                wrapper.style.display = 'block';
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                return;
            }

            const hasActive = !!getLoopStyleById(activeLoopStyleId);
            const showModules = hasOpenedLoopDetail && hasActive;
            wrapper.style.display = showModules ? 'block' : 'none';
            if (placeholder) {
                placeholder.style.display = showModules ? 'none' : 'flex';
            }

            syncLoopContainerHeightToModules();
        }

        let loopContainerHeightObserver = null;

        function syncLoopContainerHeightToModules() {
            const wrapper = document.getElementById('modules-panel-wrapper');
            const container = document.getElementById('loop-container');
            const editorColumn = document.querySelector('.loop-workspace-editor');
            if (!wrapper || !container) {
                return;
            }

            if (wrapper.offsetParent === null) {
                container.style.minHeight = '';
                if (editorColumn) {
                    editorColumn.style.minHeight = '';
                }
                return;
            }

            const wrapperHeight = Math.ceil(wrapper.getBoundingClientRect().height);
            if (wrapperHeight > 0) {
                container.style.minHeight = `${wrapperHeight}px`;
                if (editorColumn) {
                    editorColumn.style.minHeight = `${wrapperHeight}px`;
                }
            }
        }

        function initLoopContainerHeightSync() {
            syncLoopContainerHeightToModules();

            if (typeof ResizeObserver === 'undefined') {
                return;
            }

            const wrapper = document.getElementById('modules-panel-wrapper');
            if (!wrapper) {
                return;
            }

            if (loopContainerHeightObserver) {
                loopContainerHeightObserver.disconnect();
            }

            loopContainerHeightObserver = new ResizeObserver(() => {
                syncLoopContainerHeightToModules();
            });
            loopContainerHeightObserver.observe(wrapper);
        }

        function ensureSingleDefaultLoopStyle() {
            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                defaultLoopStyleId = null;
                return;
            }

            const currentDefault = getLoopStyleById(defaultLoopStyleId);
            if (currentDefault) {
                defaultLoopStyleId = parseInt(currentDefault.id, 10);
                return;
            }

            const namedBase = loopStyles.find((style) => /^alap\b/i.test(String(style.name || '').trim()));
            defaultLoopStyleId = parseInt((namedBase || loopStyles[0]).id, 10);
        }

        function openLoopStyleDetail(styleId) {
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(styleId);
        }

        function renderLoopStyleCards() {
            const list = document.getElementById('loop-style-list');
            if (!list) {
                return;
            }

            list.innerHTML = '';
            if (!Array.isArray(loopStyles) || loopStyles.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'loop-style-card-meta';
                empty.textContent = 'Még nincs loop létrehozva.';
                list.appendChild(empty);
                return;
            }

            const defaultId = parseInt(defaultLoopStyleId || 0, 10);
            const activeId = parseInt(activeLoopStyleId || 0, 10);

            loopStyles.forEach((style) => {
                const styleId = parseInt(style.id, 10);
                const isDefaultStyle = styleId === defaultId;
                const isActiveStyle = styleId === activeId;
                const realModuleCount = Array.isArray(style.items)
                    ? style.items.filter((item) => !isTechnicalLoopItem(item)).length
                    : 0;

                const card = document.createElement('div');
                card.className = `loop-style-card${isActiveStyle ? ' active' : ''}`;
                card.dataset.loopStyleId = String(style.id);
                card.addEventListener('click', () => openLoopStyleDetail(style.id));

                const header = document.createElement('div');
                header.className = 'loop-style-card-header';

                const nameEl = document.createElement('div');
                nameEl.className = 'loop-style-card-name';
                nameEl.textContent = isDefaultStyle ? `${style.name} (Alap)` : style.name;
                header.appendChild(nameEl);
                card.appendChild(header);

                const metaEl = document.createElement('div');
                metaEl.className = 'loop-style-card-meta';
                metaEl.textContent = `${realModuleCount} modul`;
                card.appendChild(metaEl);

                if (!isContentOnlyMode) {
                    const actions = document.createElement('div');
                    actions.className = 'loop-style-card-actions';

                    const duplicateBtn = document.createElement('button');
                    duplicateBtn.type = 'button';
                    duplicateBtn.className = 'btn';
                    duplicateBtn.textContent = '📄 Duplikálás';
                    duplicateBtn.addEventListener('click', (event) => {
                        event.stopPropagation();
                        duplicateLoopStyleById(style.id);
                    });
                    actions.appendChild(duplicateBtn);

                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'btn btn-danger';
                    deleteBtn.textContent = '🗑️ Törlés';
                    if (isDefaultStyle) {
                        deleteBtn.disabled = true;
                        deleteBtn.style.opacity = '0.5';
                        deleteBtn.style.cursor = 'not-allowed';
                    }
                    deleteBtn.addEventListener('click', (event) => {
                        event.stopPropagation();
                        deleteLoopStyleById(style.id);
                    });
                    actions.appendChild(deleteBtn);

                    card.appendChild(actions);
                }

                list.appendChild(card);
            });
        }

        function renderLoopStyleSelector() {
            const dragList = document.getElementById('loop-style-drag-list');
            const fixedStyleInput = document.getElementById('fixed-plan-loop-style');
            const fixedStyleLabel = document.getElementById('fixed-plan-loop-label');
            const schedulableStyles = loopStyles.filter((style) => parseInt(style.id, 10) !== parseInt(defaultLoopStyleId || 0, 10));

            renderLoopStyleCards();

            if (dragList) {
                const selectedSchedulableId = parseInt(fixedStyleInput?.value || activeLoopStyleId || 0, 10);
                dragList.innerHTML = '<label style="font-size:12px; font-weight:600; color:#425466;">Időzíthető loopok (alap loop nélkül):</label>';
                if (schedulableStyles.length === 0) {
                    const info = document.createElement('div');
                    info.style.fontSize = '12px';
                    info.style.color = '#8a97a6';
                    info.textContent = 'Nincs időzíthető loop.';
                    dragList.appendChild(info);
                }
                schedulableStyles.forEach((style) => {
                    const chip = document.createElement('div');
                    chip.className = 'btn';
                    chip.style.padding = '6px 10px';
                    chip.style.background = '#eef3f8';
                    chip.style.border = '1px solid #cfd6dd';
                    chip.style.color = '#1f2d3d';
                    chip.style.cursor = 'pointer';
                    chip.dataset.loopStyleId = String(style.id);
                    chip.textContent = style.name;
                    if (parseInt(style.id, 10) === selectedSchedulableId) {
                        chip.style.background = '#cfe4fb';
                        chip.style.border = '1px solid #1f3e56';
                        chip.style.fontWeight = '700';
                    }
                    chip.addEventListener('click', () => {
                        if (fixedStyleInput) {
                            fixedStyleInput.value = String(style.id);
                        }
                        if (fixedStyleLabel) {
                            fixedStyleLabel.textContent = `Kiválasztott loop: ${style.name}`;
                        }
                        openLoopStyleDetail(style.id);
                        renderLoopStyleSelector();
                    });
                    dragList.appendChild(chip);
                });
            }

            if (fixedStyleInput) {
                const previousValue = String(fixedStyleInput.value || '');
                const hasPrevious = previousValue && schedulableStyles.some((style) => String(style.id) === previousValue);
                let resolvedValue = hasPrevious
                    ? previousValue
                    : String((schedulableStyles.find((style) => parseInt(style.id, 10) === parseInt(activeLoopStyleId || 0, 10))?.id) || (schedulableStyles[0]?.id || ''));
                fixedStyleInput.value = resolvedValue;
                if (fixedStyleLabel) {
                    const selectedStyle = schedulableStyles.find((style) => String(style.id) === resolvedValue) || null;
                    fixedStyleLabel.textContent = selectedStyle
                        ? `Kiválasztott loop: ${selectedStyle.name}`
                        : 'Kiválasztott loop: —';
                }
            }

            syncWeeklyPlannerFromScope();

            updateLoopStyleMeta();
            updateActiveLoopVisualState();
            toggleLoopDetailVisibility();
            toggleModulesCatalogVisibility();
        }

        function clearWeeklyPlanSelection(keepDayTime = true) {
            const idInput = document.getElementById('fixed-plan-block-id');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            if (idInput) {
                idInput.value = '';
            }
            if (addBtn) {
                addBtn.textContent = '+ Idősáv hozzáadása';
            }
            if (!keepDayTime) {
                document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                    el.checked = false;
                });
                const startInput = document.getElementById('fixed-plan-start');
                const endInput = document.getElementById('fixed-plan-end');
                if (startInput) startInput.value = '08:00';
                if (endInput) endInput.value = '10:00';
            }
        }

        function fillWeeklyPlanFormFromBlock(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }

            const idInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');
            const addBtn = document.getElementById('fixed-plan-add-btn');
            const days = new Set(String(block.days_mask || '').split(',').map((v) => String(parseInt(v, 10))).filter((v) => /^[1-7]$/.test(v)));

            if (idInput) idInput.value = String(block.id);
            if (styleInput) styleInput.value = String(block.loop_style_id || '');
            if (startInput) startInput.value = String(block.start_time || '08:00:00').slice(0, 5);
            if (endInput) endInput.value = String(block.end_time || '10:00:00').slice(0, 5);
            document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                el.checked = days.has(String(el.value));
            });
            if (addBtn) {
                addBtn.textContent = '💾 Idősáv frissítése';
            }
        }

        function syncWeeklyPlannerFromScope() {
            if (activeScope === 'base') {
                clearWeeklyPlanSelection(true);
                return;
            }
            const block = getActiveTimeBlock();
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                clearWeeklyPlanSelection(true);
                return;
            }
            fillWeeklyPlanFormFromBlock(block);
        }

        function createFixedWeeklyBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const blockIdInput = document.getElementById('fixed-plan-block-id');
            const styleInput = document.getElementById('fixed-plan-loop-style');
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');

            const loopStyleId = parseInt(styleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const editBlockId = parseInt(blockIdInput?.value || '0', 10);
            const selectedDays = Array.from(document.querySelectorAll('.fixed-plan-day-checkbox:checked')).map((el) => String(parseInt(el.value, 10))).filter((v) => /^[1-7]$/.test(v));
            const startRaw = String(startInput?.value || '').trim();
            const endRaw = String(endInput?.value || '').trim();

            if (!loopStyleId || selectedDays.length === 0 || !startRaw || !endRaw) {
                showAutosaveToast('⚠️ Add meg a napot, időt és loop stílust', true);
                return;
            }

            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const startMinute = parseMinuteFromTime(`${startRaw}:00`, 0);
            const endMinute = parseMinuteFromTime(`${endRaw}:00`, 0);
            if (startMinute === endMinute) {
                showAutosaveToast('⚠️ A kezdés és befejezés nem lehet azonos', true);
                return;
            }

            const payload = {
                id: editBlockId > 0 ? editBlockId : nextTempTimeBlockId--,
                block_type: 'weekly',
                days_mask: normalizeDaysMask(selectedDays),
                start_time: minutesToTimeString(startMinute),
                end_time: minutesToTimeString(endMinute),
                block_name: `Heti ${startRaw}-${endRaw}`,
                priority: 200,
                loop_style_id: loopStyleId,
                is_active: 1,
                is_locked: 0,
                loops: []
            };

            if (hasBlockOverlap(payload, editBlockId > 0 ? editBlockId : null)) {
                showAutosaveToast('⚠️ Ütköző idősáv, válassz másik tartományt', true);
                return;
            }

            if (editBlockId > 0) {
                timeBlocks = timeBlocks.map((entry) => parseInt(entry.id, 10) === editBlockId ? { ...entry, ...payload } : entry);
            } else {
                timeBlocks.push(payload);
            }
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast(editBlockId > 0 ? '✓ Heti idősáv frissítve' : '✓ Heti idősáv létrehozva');
        }

        function deleteSelectedWeeklyPlanBlock() {
            const idInput = document.getElementById('fixed-plan-block-id');
            const blockId = parseInt(idInput?.value || '0', 10);
            if (!blockId) {
                showAutosaveToast('ℹ️ Törléshez válassz ki egy heti idősávot', true);
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                showAutosaveToast('⚠️ A kiválasztott heti idősáv nem található', true);
                clearWeeklyPlanSelection(true);
                return;
            }

            if (!confirm(`Törlöd a heti idősávot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== blockId);
            activeScope = 'base';
            clearWeeklyPlanSelection(true);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Heti idősáv törölve');
        }

        function clearEntireSchedulePlan() {
            const weeklyCount = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') === 'weekly').length;
            if (weeklyCount === 0) {
                showAutosaveToast('ℹ️ Nincs törölhető heti terv', true);
                return;
            }

            if (!confirm(`Biztosan törlöd a teljes heti tervet?\n${weeklyCount} heti idősáv lesz törölve.`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => String(entry.block_type || 'weekly') !== 'weekly');
            activeScope = 'base';
            clearWeeklyPlanSelection(false);
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Teljes heti terv törölve');
        }

        function setActiveLoopStyle(styleId) {
            persistActiveLoopStyleItems();
            const parsed = parseInt(styleId, 10);
            const style = getLoopStyleById(parsed);
            if (!style) {
                return;
            }
            activeLoopStyleId = parsed;
            loopItems = deepClone(style.items || []);
            normalizeLoopItems();
            persistActiveLoopStyleItems();
            renderLoopStyleSelector();
            renderLoop();
            updateActiveLoopVisualState();
            showAutosaveToast(`✓ Aktív loop: ${style.name}`);
        }

        function createLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            persistActiveLoopStyleItems();
            const name = prompt('Új loop neve:');
            if (!name || !String(name).trim()) {
                return;
            }
            const style = createFallbackLoopStyle(String(name).trim(), []);
            loopStyles.push(style);
            ensureSingleDefaultLoopStyle();
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(style.id);
            scheduleAutoSave(250);
        }

        function duplicateLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            persistActiveLoopStyleItems();
            const source = getLoopStyleById(styleId);
            if (!source) {
                showAutosaveToast('⚠️ A duplikálandó loop nem található', true);
                return;
            }

            const existingNames = new Set(loopStyles.map((entry) => String(entry.name || '').trim().toLowerCase()));
            const baseName = `${String(source.name || 'Loop').trim()} másolat`;
            let candidate = baseName;
            let suffix = 2;
            while (existingNames.has(candidate.toLowerCase())) {
                candidate = `${baseName} ${suffix}`;
                suffix += 1;
            }

            const duplicated = createFallbackLoopStyle(candidate, Array.isArray(source.items) ? source.items : []);
            loopStyles.push(duplicated);
            ensureSingleDefaultLoopStyle();
            hasOpenedLoopDetail = true;
            setActiveLoopStyle(duplicated.id);
            scheduleAutoSave(250);
            showAutosaveToast(`✓ Loop duplikálva: ${duplicated.name}`);
        }

        function duplicateActiveLoopStyle() {
            duplicateLoopStyleById(activeLoopStyleId);
        }

        function renameActiveLoopStyle() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const style = getLoopStyleById(activeLoopStyleId);
            if (!style) {
                return;
            }
            const name = prompt('Loop új neve:', style.name || '');
            if (!name || !String(name).trim()) {
                return;
            }
            style.name = String(name).trim();
            renderLoopStyleSelector();
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function deleteLoopStyleById(styleId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (loopStyles.length <= 1) {
                showAutosaveToast('⚠️ Legalább egy loop stílusnak maradnia kell', true);
                return;
            }
            const style = getLoopStyleById(styleId);
            if (!style) {
                return;
            }

            const deletedId = parseInt(style.id, 10);
            if (deletedId === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem törölhető', true);
                return;
            }

            if (!confirm(`Törlöd ezt a loopot?\n${style.name}`)) {
                return;
            }

            loopStyles = loopStyles.filter((entry) => parseInt(entry.id, 10) !== deletedId);
            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.loop_style_id || 0, 10) !== deletedId);
            ensureSingleDefaultLoopStyle();

            if (parseInt(activeLoopStyleId || 0, 10) === deletedId) {
                const fallbackActive = parseInt(defaultLoopStyleId || 0, 10) || parseInt(loopStyles[0]?.id || 0, 10);
                if (fallbackActive) {
                    setActiveLoopStyle(fallbackActive);
                }
            } else {
                renderLoopStyleSelector();
            }
            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
            scheduleAutoSave(250);
        }

        function deleteActiveLoopStyle() {
            deleteLoopStyleById(activeLoopStyleId);
        }

        function setActiveAsDefaultLoopStyle() {
            showAutosaveToast('ℹ️ Az alap loop fix, másik loop nem állítható alapnak', true);
        }

        function persistCurrentScopeItems() {
            persistActiveLoopStyleItems();
        }

        function getScopeLabel(block) {
            const start = String(block.start_time || '00:00:00').slice(0, 5);
            const end = String(block.end_time || '00:00:00').slice(0, 5);
            if (block.block_type === 'date') {
                return `${block.specific_date || '—'} ${start}-${end} • ${block.block_name || 'Speciális'}`;
            }
            return `${start}-${end} • ${block.block_name || 'Heti blokk'}`;
        }

        function renderScopeSelector() {
            const selector = document.getElementById('loop-scope-select');
            if (selector) {
                selector.innerHTML = '';

                const baseOption = document.createElement('option');
                baseOption.value = 'base';
                baseOption.textContent = 'Alap loop (időblokkon kívül)';
                selector.appendChild(baseOption);

                timeBlocks.forEach((block) => {
                    const option = document.createElement('option');
                    option.value = `block:${block.id}`;
                    option.textContent = getScopeLabel(block);
                    selector.appendChild(option);
                });

                selector.value = activeScope;
            }

            renderWeeklyScheduleGrid();
            renderSpecialBlocksList();
        }

        function dayName(day) {
            const names = {
                1: 'H',
                2: 'K',
                3: 'Sze',
                4: 'Cs',
                5: 'P',
                6: 'Szo',
                7: 'V'
            };
            return names[day] || '?';
        }

        function getWeekStartDate(offsetWeeks) {
            const now = new Date();
            const day = now.getDay() === 0 ? 7 : now.getDay();
            const monday = new Date(now);
            monday.setHours(0, 0, 0, 0);
            monday.setDate(now.getDate() - (day - 1) + (offsetWeeks * 7));
            return monday;
        }

        function getDateForDayInOffsetWeek(day) {
            const monday = getWeekStartDate(scheduleWeekOffset);
            const target = new Date(monday);
            target.setDate(monday.getDate() + (day - 1));
            return target;
        }

        function toDateKey(dateObj) {
            return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
        }

        function parseMinuteFromTime(timeValue, fallback = 0) {
            const raw = String(timeValue || '').trim();
            if (!raw) {
                return fallback;
            }
            const parts = raw.split(':');
            const hour = parseInt(parts[0], 10);
            const minute = parseInt(parts[1] || '0', 10);
            if (Number.isNaN(hour) || Number.isNaN(minute)) {
                return fallback;
            }
            const normalized = (hour * 60) + minute;
            return Math.max(0, Math.min(1439, normalized));
        }

        function minutesToTimeLabel(totalMinutes) {
            const safe = Math.max(0, Math.min(1439, parseInt(totalMinutes, 10) || 0));
            const hour = Math.floor(safe / 60);
            const minute = safe % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        }

        function minutesToTimeString(totalMinutes) {
            const normalized = ((parseInt(totalMinutes, 10) || 0) % 1440 + 1440) % 1440;
            const hour = Math.floor(normalized / 60);
            const minute = normalized % 60;
            return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00`;
        }

        function getScheduleSlotCount() {
            return Math.floor(1440 / scheduleGridStepMinutes);
        }

        function clampMinuteToGrid(minuteValue) {
            const raw = Math.max(0, Math.min(1439, parseInt(minuteValue, 10) || 0));
            return Math.floor(raw / scheduleGridStepMinutes) * scheduleGridStepMinutes;
        }

        function getCurrentIsoWeekValue() {
            const today = new Date();
            const date = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
            const day = date.getUTCDay() || 7;
            date.setUTCDate(date.getUTCDate() + 4 - day);
            const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
            const weekNo = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
            return `${date.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
        }

        function getDateFromIsoWeek(weekValue) {
            const match = String(weekValue || '').match(/^(\d{4})-W(\d{2})$/);
            if (!match) {
                return null;
            }
            const year = parseInt(match[1], 10);
            const week = parseInt(match[2], 10);
            if (!year || !week) {
                return null;
            }

            const jan4 = new Date(year, 0, 4);
            const jan4Day = jan4.getDay() === 0 ? 7 : jan4.getDay();
            const firstMonday = new Date(jan4);
            firstMonday.setHours(0, 0, 0, 0);
            firstMonday.setDate(jan4.getDate() - (jan4Day - 1));

            const monday = new Date(firstMonday);
            monday.setDate(firstMonday.getDate() + ((week - 1) * 7));
            return monday;
        }

        function formatScheduleWeekOffsetLabel(offset) {
            const weekStart = getWeekStartDate(offset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (offset === 0) {
                return `Aktuális hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === 1) {
                return `Jövő hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            if (offset === -1) {
                return `Előző hét (${toDateKey(weekStart)} → ${toDateKey(weekEnd)})`;
            }
            return `${toDateKey(weekStart)} → ${toDateKey(weekEnd)}`;
        }

        function renderScheduleWeekOffsetOptions() {
            const select = document.getElementById('schedule-week-offset');
            if (!select) {
                return;
            }

            select.innerHTML = '';
            for (let offset = -52; offset <= 52; offset += 1) {
                const option = document.createElement('option');
                option.value = String(offset);
                option.textContent = formatScheduleWeekOffsetLabel(offset);
                select.appendChild(option);
            }
            select.value = String(scheduleWeekOffset);

            const picker = document.getElementById('schedule-week-picker');
            if (picker) {
                const monday = getWeekStartDate(scheduleWeekOffset);
                const diffDays = Math.floor((monday - getWeekStartDate(0)) / 86400000);
                const targetDate = new Date();
                targetDate.setDate(targetDate.getDate() + diffDays);
                picker.value = getCurrentIsoWeekValue();
                const computed = getDateFromIsoWeek(picker.value);
                if (!computed || toDateKey(computed) !== toDateKey(monday)) {
                    const utcDate = new Date(Date.UTC(monday.getFullYear(), monday.getMonth(), monday.getDate()));
                    const day = utcDate.getUTCDay() || 7;
                    utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day);
                    const yearStart = new Date(Date.UTC(utcDate.getUTCFullYear(), 0, 1));
                    const weekNo = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);
                    picker.value = `${utcDate.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
                }
            }
        }

        function overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive) {
            if (!block || block.block_type !== 'weekly') {
                return false;
            }
            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10));
            if (!days.includes(day)) {
                return false;
            }
            const startMinute = parseMinuteFromTime(block.start_time, 0);
            const endMinuteRaw = parseMinuteFromTime(block.end_time, 0);
            const endMinute = endMinuteRaw === 0 && startMinute > 0 ? 1440 : endMinuteRaw;
            if (startMinute < endMinute) {
                return slotStartMinute < endMinute && startMinute < slotEndMinuteExclusive;
            }
            return slotStartMinute >= startMinute || slotEndMinuteExclusive <= endMinute;
        }

        function renderWeeklyScheduleGrid() {
            const table = document.getElementById('weekly-schedule-grid');
            if (!table) {
                return;
            }

            scheduleWeekOffset = 0;
            renderScheduleWeekOffsetOptions();

            const weekLabel = document.getElementById('schedule-week-label');
            const weekStart = getWeekStartDate(scheduleWeekOffset);
            const weekEnd = new Date(weekStart);
            weekEnd.setDate(weekStart.getDate() + 6);
            if (weekLabel) {
                weekLabel.textContent = `Megjelenített hét: ${formatScheduleWeekOffsetLabel(scheduleWeekOffset)}`;
            }

            const todayKey = toDateKey(new Date());
            const isCurrentWeek = scheduleWeekOffset === 0;
            const rows = [];
            rows.push('<thead><tr><th class="hour-col">Óra</th>' + [1,2,3,4,5,6,7].map((d) => {
                const dt = getDateForDayInOffsetWeek(d);
                const dateKey = toDateKey(dt);
                const isToday = isCurrentWeek && dateKey === todayKey;
                const thClass = isToday ? ' class="schedule-day-today"' : '';
                const marker = isToday ? '<br><span style="font-size:10px; color:#1f3e56; font-weight:700;">Ma</span>' : '';
                return `<th${thClass}>${dayName(d)}<br><span style="font-size:10px; color:#607083;">${String(dt.getDate()).padStart(2, '0')}.${String(dt.getMonth() + 1).padStart(2, '0')}</span>${marker}</th>`;
            }).join('') + '</tr></thead>');
            rows.push('<tbody>');
            const slotCount = getScheduleSlotCount();
            for (let slotIndex = 0; slotIndex < slotCount; slotIndex += 1) {
                const slotStartMinute = slotIndex * scheduleGridStepMinutes;
                const slotEndMinuteExclusive = Math.min(1440, slotStartMinute + scheduleGridStepMinutes);
                const timeLabel = minutesToTimeLabel(slotStartMinute);
                rows.push(`<tr><td class="hour-col">${timeLabel}</td>`);
                for (let day = 1; day <= 7; day += 1) {
                    const dateKey = toDateKey(getDateForDayInOffsetWeek(day));
                    const isTodayCell = isCurrentWeek && dateKey === todayKey;
                    const isRangeSelected = !!scheduleRangeSelection
                        && scheduleRangeSelection.day === day
                        && slotStartMinute >= Math.min(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute)
                        && slotStartMinute <= Math.max(scheduleRangeSelection.startMinute, scheduleRangeSelection.endMinute);
                    const isResizePreview = isHourInResizePreview(day, slotStartMinute);
                    const weeklyBlocks = timeBlocks.filter((block) => block.block_type === 'weekly' && overlapsWithSlot(block, day, slotStartMinute, slotEndMinuteExclusive));
                    const hasWeekly = weeklyBlocks.length > 0;
                    const isActive = weeklyBlocks.some((block) => activeScope === `block:${block.id}`);
                    const primaryBlock = weeklyBlocks.find((block) => activeScope === `block:${block.id}`) || weeklyBlocks[0] || null;
                    const primaryBlockId = primaryBlock ? parseInt(primaryBlock.id, 10) : 0;
                    const hasLocked = weeklyBlocks.some((block) => parseInt(block.is_locked || 0, 10) === 1);
                    const className = `schedule-cell${hasWeekly ? ' has-weekly' : ''}${isActive ? ' active-scope' : ''}${isTodayCell ? ' today' : ''}${(isRangeSelected || isResizePreview) ? ' range-select' : ''}${hasLocked ? ' locked' : ''}`;
                    const styleName = hasWeekly
                        ? (() => {
                            const styleId = parseInt(weeklyBlocks[0].loop_style_id || 0, 10);
                            const style = getLoopStyleById(styleId);
                            return style ? style.name : '';
                        })()
                        : 'Alap loop (idősávon kívül)';
                    const cellLabel = hasWeekly
                        ? `${styleName}${weeklyBlocks.length > 1 ? ` +${weeklyBlocks.length - 1}` : ''}`
                        : '';
                    rows.push(`<td class="${className}" data-day="${day}" data-minute="${slotStartMinute}" ondragover="allowScheduleDrop(event)" ondrop="dropLoopStyleToGrid(event, ${day}, ${slotStartMinute})" onmousedown="handleScheduleCellMouseDown(event, ${day}, ${slotStartMinute}, ${primaryBlockId})" onmouseenter="handleScheduleCellMouseEnter(${day}, ${slotStartMinute})" onmouseup="handleScheduleCellMouseUp(${day}, ${slotStartMinute})" title="${styleName}">${cellLabel ? `<span class='schedule-cell-label'>${cellLabel}</span>` : ''}</td>`);
                }
                rows.push('</tr>');
            }
            rows.push('</tbody>');
            table.innerHTML = rows.join('');
            table.classList.remove('step-60', 'step-30', 'step-15');
            table.classList.add(`step-${scheduleGridStepMinutes}`);
            table.classList.toggle('selecting', !!scheduleRangeSelection || !!scheduleBlockResize);
        }

        function getWeeklyBlockById(blockId) {
            const normalized = parseInt(blockId, 10);
            if (!normalized) {
                return null;
            }
            return timeBlocks.find((block) => parseInt(block.id, 10) === normalized && String(block.block_type || 'weekly') === 'weekly') || null;
        }

        function getWeeklyBlockResizeBaseRange(block) {
            if (!block || String(block.block_type || 'weekly') !== 'weekly') {
                return null;
            }
            const startMinute = clampMinuteToGrid(parseMinuteFromTime(block.start_time, 0));
            const endMinuteRaw = clampMinuteToGrid(parseMinuteFromTime(block.end_time, 0));
            const endExclusive = endMinuteRaw === 0 && startMinute > 0 ? 1440 : endMinuteRaw;
            if (endExclusive <= startMinute) {
                return null;
            }
            return { startMinute, endExclusive };
        }

        function isHourInResizePreview(day, hour) {
            if (!scheduleBlockResize || parseInt(scheduleBlockResize.day, 10) !== parseInt(day, 10)) {
                return false;
            }
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            if (!preview) {
                return false;
            }
            return hour >= preview.startMinute && hour <= preview.endMinuteInclusive;
        }

        function getScheduleBlockResizePreview(state) {
            if (!state) {
                return null;
            }

            const baseStart = parseInt(state.baseStartMinute, 10);
            const baseEndExclusive = parseInt(state.baseEndExclusive, 10);
            const currentMinute = clampMinuteToGrid(parseInt(state.currentMinute, 10));

            if (Number.isNaN(baseStart) || Number.isNaN(baseEndExclusive) || Number.isNaN(currentMinute)) {
                return null;
            }

            if (state.mode === 'start') {
                const newStart = Math.max(0, Math.min(currentMinute, baseEndExclusive - scheduleGridStepMinutes));
                return {
                    startMinute: newStart,
                    endMinuteInclusive: baseEndExclusive - scheduleGridStepMinutes
                };
            }

            const newEndInclusive = Math.min(1440 - scheduleGridStepMinutes, Math.max(currentMinute, baseStart));
            return {
                startMinute: baseStart,
                endMinuteInclusive: newEndInclusive
            };
        }

        function getSelectedLoopStyleForSchedule(forcedLoopStyleId = null) {
            const selectedStyleId = forcedLoopStyleId !== null
                ? parseInt(forcedLoopStyleId, 10)
                : parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10);
            if (!selectedStyleId) {
                showAutosaveToast('⚠️ Előbb válassz vagy hozz létre loop stílust', true);
                return null;
            }
            return selectedStyleId;
        }

        function createScheduleBlockFromRange(day, startMinute, endMinuteInclusive, loopStyleId = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const selectedStyleId = getSelectedLoopStyleForSchedule(loopStyleId);
            if (!selectedStyleId) {
                return;
            }
            if (parseInt(selectedStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                showAutosaveToast('⚠️ Az alap loop nem tervezhető, az üres időket automatikusan kitölti', true);
                return;
            }

            const minMinute = clampMinuteToGrid(Math.min(startMinute, endMinuteInclusive));
            const maxMinute = clampMinuteToGrid(Math.max(startMinute, endMinuteInclusive));
            const start = minutesToTimeString(minMinute);
            const endExclusive = Math.min(1440, maxMinute + scheduleGridStepMinutes);
            const end = minutesToTimeString(endExclusive >= 1440 ? 0 : endExclusive);
            const targetDate = getDateForDayInOffsetWeek(day);
            const dateKey = toDateKey(targetDate);

            const payload = scheduleWeekOffset === 0
                ? {
                    id: nextTempTimeBlockId--,
                    block_type: 'weekly',
                    days_mask: String(day),
                    start_time: start,
                    end_time: end,
                    block_name: `${dayName(day)} ${minutesToTimeLabel(minMinute)}-${minutesToTimeLabel(endExclusive >= 1440 ? 0 : endExclusive)}`,
                    priority: 100,
                    loop_style_id: selectedStyleId,
                    is_active: 1,
                    loops: []
                }
                : {
                    id: nextTempTimeBlockId--,
                    block_type: 'date',
                    specific_date: dateKey,
                    start_time: start,
                    end_time: end,
                    days_mask: '',
                    block_name: `Speciális ${dateKey}`,
                    priority: 300,
                    loop_style_id: selectedStyleId,
                    is_active: 1,
                    loops: []
                };

            if (hasBlockOverlap(payload, null)) {
                showAutosaveToast('⚠️ Ütköző idősáv, válassz másik időtartamot', true);
                return;
            }

            timeBlocks.push(payload);
            activeScope = `block:${payload.id}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Idősáv létrehozva');
        }

        function startScheduleRangeSelection(event, day, hour) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }
            scheduleRangeSelection = {
                day: parseInt(day, 10),
                startMinute: clampMinuteToGrid(parseInt(hour, 10)),
                endMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            renderWeeklyScheduleGrid();
        }

        function startScheduleBlockResize(blockId, day, hour, mode) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const block = getWeeklyBlockById(blockId);
            if (!block) {
                return;
            }

            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10)).filter((v) => v >= 1 && v <= 7);
            const dayInt = parseInt(day, 10);
            if (!days.includes(dayInt)) {
                return;
            }

            const baseRange = getWeeklyBlockResizeBaseRange(block);
            if (!baseRange) {
                showAutosaveToast('⚠️ Éjfélen átnyúló blokk közvetlen nyújtása itt nem támogatott', true);
                return;
            }

            scheduleBlockResize = {
                blockId: parseInt(block.id, 10),
                day: dayInt,
                mode: mode === 'start' ? 'start' : 'end',
                baseStartMinute: baseRange.startMinute,
                baseEndExclusive: baseRange.endExclusive,
                currentMinute: clampMinuteToGrid(parseInt(hour, 10))
            };
            activeScope = `block:${parseInt(block.id, 10)}`;
            renderWeeklyScheduleGrid();
        }

        function updateScheduleBlockResize(day, hour) {
            if (!scheduleBlockResize) {
                return;
            }
            const dayInt = parseInt(day, 10);
            if (scheduleBlockResize.day !== dayInt) {
                return;
            }
            scheduleBlockResize.currentMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleBlockResize(day = null, hour = null) {
            if (!scheduleBlockResize) {
                return;
            }

            const fallbackDay = scheduleBlockResize.day;
            const fallbackHour = scheduleBlockResize.currentMinute;
            const dayInt = day === null ? fallbackDay : parseInt(day, 10);
            const hourInt = hour === null ? fallbackHour : parseInt(hour, 10);
            if (dayInt !== fallbackDay || Number.isNaN(hourInt)) {
                scheduleBlockResize = null;
                renderWeeklyScheduleGrid();
                return;
            }

            scheduleBlockResize.currentMinute = clampMinuteToGrid(hourInt);
            const preview = getScheduleBlockResizePreview(scheduleBlockResize);
            const block = getWeeklyBlockById(scheduleBlockResize.blockId);
            const resizeState = { ...scheduleBlockResize };
            scheduleBlockResize = null;

            if (!preview || !block) {
                renderWeeklyScheduleGrid();
                return;
            }

            const newStart = minutesToTimeString(preview.startMinute);
            const endExclusive = preview.endMinuteInclusive + scheduleGridStepMinutes;
            const normalizedEnd = endExclusive >= 1440 ? 0 : endExclusive;
            const newEnd = minutesToTimeString(normalizedEnd);

            const candidate = {
                ...block,
                start_time: newStart,
                end_time: newEnd,
                days_mask: String(resizeState.day)
            };

            if (hasBlockOverlap(candidate, parseInt(block.id, 10))) {
                renderWeeklyScheduleGrid();
                showAutosaveToast('⚠️ Ütköző idősáv, a nyújtás nem menthető', true);
                return;
            }

            timeBlocks = timeBlocks.map((entry) => {
                if (parseInt(entry.id, 10) !== parseInt(block.id, 10)) {
                    return entry;
                }
                return {
                    ...entry,
                    start_time: newStart,
                    end_time: newEnd,
                    days_mask: String(resizeState.day)
                };
            });

            activeScope = `block:${parseInt(block.id, 10)}`;
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk frissítve');
        }

        function cancelScheduleBlockResize() {
            if (!scheduleBlockResize) {
                return;
            }
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function handleScheduleCellMouseDown(event, day, hour, primaryBlockId) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            if (event && event.button !== 0) {
                return;
            }

            const blockId = parseInt(primaryBlockId || 0, 10);
            if (blockId > 0 && event?.currentTarget) {
                setActiveScope(`block:${blockId}`, true);
                return;
            }

            const minute = clampMinuteToGrid(parseInt(hour, 10));
            clearWeeklyPlanSelection(true);
            document.querySelectorAll('.fixed-plan-day-checkbox').forEach((el) => {
                el.checked = String(el.value) === String(parseInt(day, 10));
            });
            const startInput = document.getElementById('fixed-plan-start');
            const endInput = document.getElementById('fixed-plan-end');
            if (startInput) {
                startInput.value = minutesToTimeLabel(minute);
            }
            if (endInput) {
                const endMinute = (minute + scheduleGridStepMinutes) % 1440;
                endInput.value = minutesToTimeLabel(endMinute);
            }
            showAutosaveToast('ℹ️ A heti terv szerkesztő mező kitöltve');
        }

        function handleScheduleCellMouseEnter(day, hour) {
            if (scheduleBlockResize) {
                updateScheduleBlockResize(day, hour);
                return;
            }
            updateScheduleRangeSelection(day, hour);
        }

        function handleScheduleCellMouseUp(day, hour) {
            if (scheduleBlockResize) {
                finishScheduleBlockResize(day, hour);
                return;
            }
            finishScheduleRangeSelection(day, hour);
        }

        function updateScheduleRangeSelection(day, hour) {
            if (!scheduleRangeSelection) {
                return;
            }
            const d = parseInt(day, 10);
            if (scheduleRangeSelection.day !== d) {
                return;
            }
            scheduleRangeSelection.endMinute = clampMinuteToGrid(parseInt(hour, 10));
            renderWeeklyScheduleGrid();
        }

        function finishScheduleRangeSelection(day = null, hour = null) {
            if (!scheduleRangeSelection) {
                return;
            }

            const fallbackDay = scheduleRangeSelection.day;
            const fallbackHour = scheduleRangeSelection.endMinute;
            const d = day === null ? fallbackDay : parseInt(day, 10);
            const resolvedHour = hour === null ? fallbackHour : parseInt(hour, 10);

            if (scheduleRangeSelection.day !== d || Number.isNaN(resolvedHour)) {
                scheduleRangeSelection = null;
                renderWeeklyScheduleGrid();
                return;
            }

            const startHour = scheduleRangeSelection.startMinute;
            const endHour = resolvedHour;
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
            createScheduleBlockFromRange(d, startHour, endHour, null);
        }

        function cancelScheduleRangeSelection() {
            if (!scheduleRangeSelection) {
                return;
            }
            scheduleRangeSelection = null;
            renderWeeklyScheduleGrid();
        }

        function allowScheduleDrop(event) {
            if (event) {
                event.preventDefault();
            }
        }

        function dropLoopStyleToGrid(event, day, hour) {
            if (event) {
                event.preventDefault();
            }
            showAutosaveToast('ℹ️ Drag/drop helyett használd a fix heti sáv panelt', true);
        }

        function renderSpecialBlocksList() {
            const wrap = document.getElementById('special-blocks-list');
            if (!wrap) {
                return;
            }

            const searchTerm = String(document.getElementById('special-date-search')?.value || '').trim().toLowerCase();

            const specialBlocks = timeBlocks
                .filter((block) => block.block_type === 'date')
                .filter((block) => {
                    if (!searchTerm) {
                        return true;
                    }
                    const haystack = `${String(block.specific_date || '')} ${String(block.block_name || '')} ${String(block.start_time || '')} ${String(block.end_time || '')}`.toLowerCase();
                    return haystack.includes(searchTerm);
                })
                .sort((a, b) => String(a.specific_date || '').localeCompare(String(b.specific_date || '')) || String(a.start_time).localeCompare(String(b.start_time)));

            if (specialBlocks.length === 0) {
                wrap.innerHTML = `<div class="item"><span class="muted">${searchTerm ? 'Nincs találat a keresésre.' : 'Nincs speciális dátumos idősáv.'}</span></div>`;
                return;
            }

            wrap.innerHTML = specialBlocks.map((block) => {
                const active = activeScope === `block:${block.id}` ? ' style="font-weight:700;"' : '';
                const style = getLoopStyleById(block.loop_style_id || 0);
                return `<div class="item">
                    <span${active}>${block.specific_date} ${String(block.start_time).slice(0,5)}-${String(block.end_time).slice(0,5)} • ${block.block_name} • ${style ? style.name : 'N/A'}</span>
                    <button class="btn" type="button" onclick="setActiveScope('block:${block.id}', true)">Szerkesztés</button>
                </div>`;
            }).join('');
        }

        function setActiveScope(scope, shouldRender = true) {
            activeScope = scope;
            renderScopeSelector();
            syncWeeklyPlannerFromScope();
            if (shouldRender) renderSpecialBlocksList();
        }

        function handleScopeChange(scope) {
            setActiveScope(scope, true);
        }

        function blockMatchesDateTime(block, dt) {
            if (!block || !dt) {
                return false;
            }

            const hhmmss = `${String(dt.getHours()).padStart(2, '0')}:${String(dt.getMinutes()).padStart(2, '0')}:00`;
            const start = String(block.start_time || '00:00:00');
            const end = String(block.end_time || '00:00:00');
            const timeMatch = start <= end
                ? (hhmmss >= start && hhmmss <= end)
                : (hhmmss >= start || hhmmss <= end);
            if (!timeMatch) {
                return false;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                const dateStr = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
                return dateStr === String(block.specific_date || '');
            }

            const weekday = dt.getDay() === 0 ? 7 : dt.getDay();
            const days = String(block.days_mask || '').split(',').map((v) => parseInt(v, 10));
            return days.includes(weekday);
        }

        function resolveScopeByDateTime(dt) {
            const matching = timeBlocks.filter((block) => block.is_active !== 0 && blockMatchesDateTime(block, dt));
            if (matching.length === 0) {
                return 'base';
            }

            matching.sort((a, b) => {
                const typeWeightA = String(a.block_type || 'weekly') === 'date' ? 2 : 1;
                const typeWeightB = String(b.block_type || 'weekly') === 'date' ? 2 : 1;
                if (typeWeightA !== typeWeightB) {
                    return typeWeightB - typeWeightA;
                }
                const pa = parseInt(a.priority || 0, 10);
                const pb = parseInt(b.priority || 0, 10);
                if (pa !== pb) {
                    return pb - pa;
                }
                return parseInt(a.id, 10) - parseInt(b.id, 10);
            });

            return `block:${matching[0].id}`;
        }

        function changeScheduleWeek(delta) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban nincs hétlapozás', true);
            renderWeeklyScheduleGrid();
        }

        function setScheduleWeekOffset(value) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban mindig az aktuális heti minta látszik', true);
            renderWeeklyScheduleGrid();
        }

        function openScheduleWeekPicker() {
            const picker = document.getElementById('schedule-week-picker');
            if (!picker) {
                return;
            }
            if (typeof picker.showPicker === 'function') {
                picker.showPicker();
                return;
            }
            picker.focus();
            picker.click();
        }

        function setScheduleWeekFromPicker(value) {
            scheduleWeekOffset = 0;
            showAutosaveToast('ℹ️ Fix heti terv módban nincs dátum szerinti lapozás', true);
            renderWeeklyScheduleGrid();
        }

        function setScheduleGridStep(value) {
            const parsed = parseInt(value, 10);
            if (![15, 30, 60].includes(parsed)) {
                return;
            }
            scheduleGridStepMinutes = parsed;
            scheduleRangeSelection = null;
            scheduleBlockResize = null;
            renderWeeklyScheduleGrid();
        }

        function buildLoopPayload() {
            persistActiveLoopStyleItems();
            const defaultStyle = getLoopStyleById(defaultLoopStyleId) || getLoopStyleById(activeLoopStyleId) || { items: [] };
            const expandedTimeBlocks = deepClone(timeBlocks).map((block) => {
                const style = getLoopStyleById(block.loop_style_id || 0);
                return {
                    ...block,
                    loops: deepClone(style?.items || [])
                };
            });

            return {
                base_loop: deepClone(defaultStyle.items || []),
                time_blocks: expandedTimeBlocks,
                loop_styles: deepClone(loopStyles),
                default_loop_style_id: defaultLoopStyleId,
                schedule_blocks: deepClone(timeBlocks)
            };
        }

        function getLoopSnapshot() {
            return JSON.stringify(buildLoopPayload());
        }

        function scheduleAutoSave(delayMs = 700) {
            if (isDefaultGroup || !hasLoadedInitialLoop) {
                return;
            }

            if (autoSaveTimer) {
                clearTimeout(autoSaveTimer);
            }

            autoSaveTimer = setTimeout(() => {
                saveLoop({ silent: true, source: 'autosave' });
            }, delayMs);
        }

        function isTechnicalLoopItem(item) {
            if (!item) {
                return false;
            }

            if ((item.module_key || '') === 'unconfigured') {
                return true;
            }

            if (!technicalModule) {
                return false;
            }

            return parseInt(item.module_id) === parseInt(technicalModule.id);
        }

        function hasRealModules(items = loopItems) {
            return items.some(item => !isTechnicalLoopItem(item));
        }

        function normalizeLoopItems() {
            if (!technicalModule) {
                return;
            }

            const realItems = loopItems.filter(item => !isTechnicalLoopItem(item));

            if (realItems.length > 0) {
                loopItems = realItems;
                return;
            }

            const existingTechnical = loopItems.find(item => isTechnicalLoopItem(item));

            if (existingTechnical) {
                loopItems = [{
                    ...existingTechnical,
                    module_key: 'unconfigured',
                    duration_seconds: 60
                }];
                return;
            }

            loopItems = [{
                module_id: parseInt(technicalModule.id),
                module_name: technicalModule.name,
                description: technicalModule.description || 'Technikai modul – csak üres loop esetén.',
                module_key: 'unconfigured',
                duration_seconds: 60,
                settings: {}
            }];
        }
        
        // Calculate total loop duration
        function getTotalLoopDuration() {
            return loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds || 10), 0);
        }
        
        // Get elapsed time in current loop cycle
        function getElapsedTimeInLoop() {
            let elapsed = 0;
            for (let i = 0; i < currentPreviewIndex; i++) {
                elapsed += parseInt(loopItems[i].duration_seconds || 10);
            }
            elapsed += (Date.now() - currentModuleStartTime) / 1000;
            return elapsed;
        }
        
        // Load existing loop configuration
        function loadLoop() {
            if (isDefaultGroup) {
                const defaultItem = getDefaultUnconfiguredItem();
                loopStyles = [{ id: -1, name: 'Alap loop', items: defaultItem ? [defaultItem] : [] }];
                activeLoopStyleId = -1;
                defaultLoopStyleId = -1;
                loopItems = deepClone(loopStyles[0].items || []);
                timeBlocks = [];
                setActiveScope('base', true);
                renderLoopStyleSelector();
                hasLoadedInitialLoop = true;
                lastSavedSnapshot = getLoopSnapshot();
                if (loopItems.length > 0) {
                    setTimeout(() => startPreview(), 500);
                }
                return;
            }

            fetch(`../api/group_loop_config.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const plannerStyles = Array.isArray(data.loop_styles) ? data.loop_styles : [];
                        if (plannerStyles.length > 0) {
                            loopStyles = plannerStyles.map((style, idx) => ({
                                id: parseInt(style.id ?? -(idx + 1), 10),
                                name: String(style.name || `Loop ${idx + 1}`),
                                items: Array.isArray(style.items) ? style.items : []
                            }));
                            defaultLoopStyleId = parseInt(data.default_loop_style_id ?? loopStyles[0]?.id ?? 0, 10) || loopStyles[0]?.id || null;
                            timeBlocks = normalizeTimeBlocks(data.schedule_blocks || data.time_blocks || []);
                        } else {
                            const hasStructuredPayload = Array.isArray(data.base_loop) || Array.isArray(data.time_blocks);
                            const baseItems = hasStructuredPayload
                                ? (Array.isArray(data.base_loop) ? data.base_loop : [])
                                : (Array.isArray(data.loops) ? data.loops : []);
                            loopStyles = [createFallbackLoopStyle('Alap loop', baseItems)];
                            defaultLoopStyleId = loopStyles[0].id;

                            timeBlocks = normalizeTimeBlocks(data.time_blocks || []);
                            timeBlocks = timeBlocks.map((block, index) => {
                                const style = createFallbackLoopStyle(block.block_name || `Idősáv ${index + 1}`, Array.isArray(block.loops) ? block.loops : []);
                                loopStyles.push(style);
                                return { ...block, loop_style_id: style.id };
                            });
                        }

                        ensureSingleDefaultLoopStyle();

                        activeLoopStyleId = loopStyles[0]?.id ?? null;
                        loopItems = deepClone(getLoopStyleById(activeLoopStyleId)?.items || []);
                        normalizeLoopItems();
                        persistActiveLoopStyleItems();
                        activeScope = 'base';
                        setActiveScope('base', false);
                        lastSavedSnapshot = getLoopSnapshot();
                        hasLoadedInitialLoop = true;
                        renderLoopStyleSelector();
                        renderScopeSelector();
                        renderLoop();
                        
                        // Automatikus preview indítás ha van loop
                        if (loopItems.length > 0) {
                            setTimeout(() => startPreview(), 500);
                        }
                    }
                })
                .catch(error => console.error('Error loading loop:', error));
        }
        
        function addModuleToLoop(moduleId, moduleName, moduleDesc) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (!hasOpenedLoopDetail) {
                showAutosaveToast('ℹ️ Először válassz loopot a bal oldali listából', true);
                return;
            }

            const moduleKey = getModuleKeyById(moduleId);

            if (moduleKey !== 'unconfigured') {
                loopItems = loopItems.filter(item => !isTechnicalLoopItem(item));
            } else if (loopItems.some(item => isTechnicalLoopItem(item))) {
                return;
            }

            loopItems.push({
                module_id: moduleId,
                module_name: moduleName,
                description: moduleDesc,
                module_key: moduleKey || null,
                duration_seconds: moduleKey === 'unconfigured' ? 60 : 10
            });

            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function handleModuleCatalogDragStart(event, payload) {
            if (isDefaultGroup || isContentOnlyMode || !event?.dataTransfer || !payload) {
                return;
            }

            const data = {
                id: parseInt(payload.id || 0, 10),
                name: String(payload.name || ''),
                description: String(payload.description || '')
            };

            if (!data.id || !data.name) {
                return;
            }

            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/module-catalog-item', JSON.stringify(data));
        }

        function allowModuleCatalogDrop(event) {
            if (!event) {
                return;
            }
            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.add('catalog-drop-active');
            }
        }

        function handleModuleCatalogDragLeave(event) {
            const container = document.getElementById('loop-container');
            if (!container) {
                return;
            }

            const related = event?.relatedTarget;
            if (related && container.contains(related)) {
                return;
            }
            container.classList.remove('catalog-drop-active');
        }

        function dropCatalogModuleToLoop(event) {
            if (!event) {
                return;
            }

            event.preventDefault();
            const container = document.getElementById('loop-container');
            if (container) {
                container.classList.remove('catalog-drop-active');
            }

            if (isDefaultGroup || isContentOnlyMode || !event.dataTransfer) {
                return;
            }

            if (!hasOpenedLoopDetail) {
                showAutosaveToast('ℹ️ Először válassz loopot a bal oldali listából', true);
                return;
            }

            const raw = event.dataTransfer.getData('text/module-catalog-item');
            if (!raw) {
                return;
            }

            try {
                const data = JSON.parse(raw);
                const moduleId = parseInt(data.id || 0, 10);
                const moduleName = String(data.name || '').trim();
                const moduleDesc = String(data.description || '');
                if (!moduleId || !moduleName) {
                    return;
                }
                addModuleToLoop(moduleId, moduleName, moduleDesc);
            } catch (error) {
                console.error('Invalid module drop payload', error);
            }
        }
        
        function removeFromLoop(index) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            loopItems.splice(index, 1);
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
        }

        function duplicateLoopItem(index) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const sourceItem = loopItems[index];
            if (!sourceItem || isTechnicalLoopItem(sourceItem)) {
                return;
            }

            const duplicatedItem = {
                module_id: sourceItem.module_id,
                module_name: sourceItem.module_name,
                description: sourceItem.description || '',
                module_key: sourceItem.module_key || null,
                duration_seconds: parseInt(sourceItem.duration_seconds || 10),
                settings: sourceItem.settings
                    ? JSON.parse(JSON.stringify(sourceItem.settings))
                    : {}
            };

            loopItems.splice(index + 1, 0, duplicatedItem);
            normalizeLoopItems();
            renderLoop();
            scheduleAutoSave();
            showAutosaveToast('✓ Elem duplikálva');
        }
        
        function updateDuration(index, value) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (isTechnicalLoopItem(loopItems[index])) {
                loopItems[index].duration_seconds = 60;
                updateTotalDuration();
                scheduleAutoSave();
                if (loopItems.length > 0) {
                    startPreview();
                }
                return;
            }

            loopItems[index].duration_seconds = parseInt(value) || 10;
            updateTotalDuration();
            scheduleAutoSave();
            if (loopItems.length > 0) {
                startPreview();
            }
        }
        
        let draggedElement = null;
        
        function handleDragStart(e) {
            draggedElement = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        }
        
        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }
            e.dataTransfer.dropEffect = 'move';
            return false;
        }
        
        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation();
            }
            
            if (draggedElement !== this) {
                const draggedIndex = parseInt(draggedElement.dataset.index);
                const targetIndex = parseInt(this.dataset.index);
                
                const item = loopItems.splice(draggedIndex, 1)[0];
                loopItems.splice(targetIndex, 0, item);
                
                renderLoop();
                scheduleAutoSave();
            }
            
            return false;
        }
        
        function handleDragEnd(e) {
            this.classList.remove('dragging');
        }
        
        function updateTotalDuration() {
            const total = loopItems.reduce((sum, item) => sum + parseInt(item.duration_seconds), 0);
            const minutes = Math.floor(total / 60);
            const seconds = total % 60;
            document.getElementById('total-duration').textContent = `Össz: ${total} mp (${minutes}:${seconds.toString().padStart(2, '0')})`;
        }
        
        function clearLoop() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            if (confirm('Biztosan törölni szeretnéd az összes elemet?')) {
                loopItems = [];
                normalizeLoopItems();
                renderLoop();
                scheduleAutoSave();
            }
        }
        
        function saveLoop(options = {}) {
            const opts = {
                silent: false,
                source: 'manual',
                ...options
            };

            if (isDefaultGroup) {
                if (!opts.silent) {
                    alert('⚠️ A default csoport loopja nem szerkeszthető.');
                }
                return;
            }

            const payload = buildLoopPayload();
            const totalItemCount = (payload.base_loop || []).length + (payload.time_blocks || []).reduce((sum, block) => {
                return sum + (Array.isArray(block.loops) ? block.loops.length : 0);
            }, 0);

            if (totalItemCount === 0) {
                if (!opts.silent) {
                    alert('⚠️ A loop üres! Adj hozzá legalább egy modult.');
                }
                return;
            }

            const currentSnapshot = getLoopSnapshot();
            if (currentSnapshot === lastSavedSnapshot) {
                return;
            }

            if (autoSaveInFlight) {
                autoSaveQueued = true;
                return;
            }

            autoSaveInFlight = true;
            
            fetch(`../api/group_loop_config.php?group_id=${groupId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastSavedSnapshot = currentSnapshot;
                    showAutosaveToast('✓ Mentés sikeres');
                } else {
                    showAutosaveToast('⚠️ ' + (data.message || 'Mentési hiba'), true);
                }
            })
            .catch(error => {
                showAutosaveToast('⚠️ Hiba történt: ' + error, true);
            })
            .finally(() => {
                autoSaveInFlight = false;
                if (autoSaveQueued) {
                    autoSaveQueued = false;
                    scheduleAutoSave(150);
                }
            });
        }

        function getActiveTimeBlock() {
            if (activeScope === 'base') {
                return null;
            }
            const blockId = parseInt(String(activeScope).replace('block:', ''), 10);
            return timeBlocks.find((entry) => parseInt(entry.id, 10) === blockId) || null;
        }

        function getDayShortLabel(day) {
            const map = {
                '1': 'H',
                '2': 'K',
                '3': 'Sze',
                '4': 'Cs',
                '5': 'P',
                '6': 'Szo',
                '7': 'V'
            };
            return map[String(day)] || '?';
        }

        function hasBlockOverlap(candidate, ignoredId = null) {
            const cStart = String(candidate.start_time || '00:00:00');
            const cEnd = String(candidate.end_time || '00:00:00');
            const cType = String(candidate.block_type || 'weekly');
            const cDays = new Set(String(candidate.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
            const cDate = String(candidate.specific_date || '');

            const toSegments = (startRaw, endRaw) => {
                const startMinute = parseMinuteFromTime(startRaw, 0);
                let endMinute = parseMinuteFromTime(endRaw, 0);

                if (endMinute === startMinute) {
                    return [[0, 1440]];
                }

                if (endMinute > startMinute) {
                    return [[startMinute, endMinute]];
                }

                if (endMinute === 0) {
                    return [[startMinute, 1440]];
                }

                return [
                    [startMinute, 1440],
                    [0, endMinute]
                ];
            };

            const rangesOverlap = (aStart, aEnd, bStart, bEnd) => {
                const segA = toSegments(aStart, aEnd);
                const segB = toSegments(bStart, bEnd);
                return segA.some(([a0, a1]) => segB.some(([b0, b1]) => a0 < b1 && b0 < a1));
            };

            return timeBlocks.some((existing) => {
                if (!existing || (ignoredId !== null && parseInt(existing.id, 10) === parseInt(ignoredId, 10))) {
                    return false;
                }

                if (String(existing.block_type || 'weekly') !== cType) {
                    return false;
                }

                if (cType === 'date') {
                    if (String(existing.specific_date || '') !== cDate) {
                        return false;
                    }
                } else {
                    const eDays = new Set(String(existing.days_mask || '').split(',').map(v => parseInt(v, 10)).filter(v => v >= 1 && v <= 7));
                    const commonDay = Array.from(cDays).some((d) => eDays.has(d));
                    if (!commonDay) {
                        return false;
                    }
                }

                return rangesOverlap(
                    cStart,
                    cEnd,
                    String(existing.start_time || '00:00:00'),
                    String(existing.end_time || '00:00:00')
                );
            });
        }

        function createWeeklyBlockFromGrid(day, hour, forcedLoopStyleId = null) {
            createScheduleBlockFromRange(parseInt(day, 10), parseInt(hour, 10), parseInt(hour, 10), forcedLoopStyleId);
        }

        function createSpecialDateBlockFromInputs() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }
            const dateVal = String(document.getElementById('special-date-input')?.value || '').trim();
            const startVal = String(document.getElementById('special-start-input')?.value || '').trim();
            const endVal = String(document.getElementById('special-end-input')?.value || '').trim();

            if (!dateVal || !startVal || !endVal) {
                showAutosaveToast('⚠️ Add meg a dátumot és időt', true);
                return;
            }

            openTimeBlockModal(null, {
                block_type: 'date',
                specific_date: dateVal,
                start_time: `${startVal}:00`,
                end_time: `${endVal}:00`,
                block_name: `Speciális ${dateVal}`,
                priority: 300,
                loop_style_id: parseInt(activeLoopStyleId || defaultLoopStyleId || 0, 10)
            });
        }

        function openTimeBlockModal(block = null, preset = null) {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const host = document.getElementById('time-block-modal-host');
            if (!host) {
                return;
            }

            const editing = !!block;
            const merged = { ...(preset || {}), ...(block || {}) };
            const selectedDays = new Set(String(merged.days_mask || '1,2,3,4,5,6,7').split(',').map(v => String(parseInt(v, 10))).filter(v => /^[1-7]$/.test(v)));
            const blockType = String(merged.block_type || 'weekly') === 'date' ? 'date' : 'weekly';
            const specificDate = merged.specific_date ? String(merged.specific_date).slice(0, 10) : '';
            const priority = Number.isFinite(parseInt(merged.priority, 10)) ? parseInt(merged.priority, 10) : (blockType === 'date' ? 300 : 100);
            const selectedLoopStyleId = parseInt(merged.loop_style_id || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const loopStyleOptions = loopStyles.map((style) => {
                const selected = parseInt(style.id, 10) === selectedLoopStyleId ? 'selected' : '';
                return `<option value="${style.id}" ${selected}>${String(style.name || 'Loop')}</option>`;
            }).join('');

            host.innerHTML = `
                <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200;">
                    <div style="background:#fff; width:min(560px,92vw); border:1px solid #cfd6dd; padding:16px;">
                        <h3 style="margin:0 0 12px 0;">${editing ? 'Időblokk szerkesztése' : 'Új időblokk'}</h3>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Típus</label>
                                <select id="tb-type" style="width:100%;">
                                    <option value="weekly" ${blockType === 'weekly' ? 'selected' : ''}>Heti</option>
                                    <option value="date" ${blockType === 'date' ? 'selected' : ''}>Speciális dátum</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Prioritás</label>
                                <input id="tb-priority" type="number" min="1" max="999" value="${priority}" style="width:100%;">
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Loop stílus</label>
                                <select id="tb-loop-style" style="width:100%;">${loopStyleOptions}</select>
                            </div>
                            <div style="grid-column:1 / span 2;">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Név</label>
                                <input id="tb-name" type="text" value="${(merged.block_name || '').replace(/"/g, '&quot;')}" style="width:100%;">
                            </div>
                            <div id="tb-date-wrap" style="grid-column:1 / span 2; ${blockType === 'date' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Dátum</label>
                                <input id="tb-date" type="date" value="${specificDate}" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Kezdés</label>
                                <select id="tb-start" style="width:100%;"></select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; margin-bottom:4px;">Vége</label>
                                <select id="tb-end" style="width:100%;"></select>
                            </div>
                            <div id="tb-days-wrap" style="grid-column:1 / span 2; ${blockType === 'weekly' ? '' : 'display:none;'}">
                                <label style="display:block; font-size:12px; margin-bottom:6px;">Napok</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    ${[1,2,3,4,5,6,7].map((day) => {
                                        const d = String(day);
                                        const checked = selectedDays.has(d) ? 'checked' : '';
                                        return `<label style=\"display:flex; align-items:center; gap:4px; font-size:12px;\"><input type=\"checkbox\" class=\"tb-day\" value=\"${d}\" ${checked}>${getDayShortLabel(d)}</label>`;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
                            <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                            <button type="button" class="btn btn-primary" onclick="saveTimeBlockModal(${editing ? 'true' : 'false'}, ${editing ? parseInt(block.id, 10) : 'null'})">Mentés</button>
                        </div>
                    </div>
                </div>
            `;

            const typeEl = document.getElementById('tb-type');
            if (typeEl) {
                typeEl.addEventListener('change', function () {
                    const isDate = this.value === 'date';
                    const dateWrap = document.getElementById('tb-date-wrap');
                    const daysWrap = document.getElementById('tb-days-wrap');
                    if (dateWrap) dateWrap.style.display = isDate ? '' : 'none';
                    if (daysWrap) daysWrap.style.display = isDate ? 'none' : '';
                });
            }
        }

        function closeTimeBlockModal() {
            const host = document.getElementById('time-block-modal-host');
            if (host) {
                host.innerHTML = '';
            }
        }

        function saveTimeBlockModal(isEdit, editId) {
            const nameInput = document.getElementById('tb-name');
            const typeInput = document.getElementById('tb-type');
            const dateInput = document.getElementById('tb-date');
            const priorityInput = document.getElementById('tb-priority');
            const startInput = document.getElementById('tb-start');
            const endInput = document.getElementById('tb-end');
            const dayCheckboxes = Array.from(document.querySelectorAll('.tb-day:checked'));

            const name = String(nameInput?.value || '').trim();
            const blockType = String(typeInput?.value || 'weekly') === 'date' ? 'date' : 'weekly';
            const loopStyleInput = document.getElementById('tb-loop-style');
            const specificDate = String(dateInput?.value || '').trim();
            const priority = parseInt(priorityInput?.value, 10) || (blockType === 'date' ? 300 : 100);
            const loopStyleId = parseInt(loopStyleInput?.value || activeLoopStyleId || defaultLoopStyleId || 0, 10);
            const start = String(startInput?.value || '').trim();
            const end = String(endInput?.value || '').trim();
            const days = dayCheckboxes.map((el) => String(el.value));

            if (!name) {
                alert('⚠️ Adj meg blokk nevet.');
                return;
            }
            if (!start || !end) {
                alert('⚠️ Adj meg kezdési és zárási időt.');
                return;
            }
            if (blockType === 'weekly' && days.length === 0) {
                alert('⚠️ Jelölj ki legalább egy napot.');
                return;
            }
            if (blockType === 'date' && !specificDate) {
                alert('⚠️ Válassz dátumot.');
                return;
            }
            if (!loopStyleId) {
                alert('⚠️ Válassz loop stílust az idősávhoz.');
                return;
            }
            if (parseInt(loopStyleId, 10) === parseInt(defaultLoopStyleId || 0, 10)) {
                alert('⚠️ Az alap loop nem tervezhető. Az üres sávokat automatikusan kitölti.');
                return;
            }

            const blockId = isEdit ? parseInt(editId, 10) : nextTempTimeBlockId--;
            const payload = {
                id: blockId,
                block_name: name,
                block_type: blockType,
                specific_date: blockType === 'date' ? specificDate : null,
                start_time: `${start}:00`,
                end_time: `${end}:00`,
                days_mask: blockType === 'weekly' ? normalizeDaysMask(days) : '',
                priority: priority,
                loop_style_id: loopStyleId,
                is_active: 1,
                loops: isEdit
                    ? (timeBlocks.find((block) => parseInt(block.id, 10) === parseInt(editId, 10))?.loops || [])
                    : []
            };

            if (hasBlockOverlap(payload, isEdit ? parseInt(editId, 10) : null)) {
                alert('⚠️ Ütköző idősáv: ugyanarra az időre már van blokk ugyanilyen tartományban.');
                return;
            }

            if (isEdit) {
                timeBlocks = timeBlocks.map((block) => parseInt(block.id, 10) === parseInt(editId, 10) ? payload : block);
                if (activeScope === `block:${editId}`) {
                    activeScope = `block:${payload.id}`;
                }
            } else {
                timeBlocks.push(payload);
                activeScope = `block:${payload.id}`;
            }

            closeTimeBlockModal();
            setActiveScope(activeScope, true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk mentve');
        }

        function editCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Válassz egy időblokkot szerkesztéshez', true);
                return;
            }
            openTimeBlockModal(block);
        }

        function deleteCurrentTimeBlock() {
            const block = getActiveTimeBlock();
            if (!block) {
                showAutosaveToast('ℹ️ Nincs kiválasztott időblokk', true);
                return;
            }

            if (String(block.block_type || 'weekly') === 'date') {
                showAutosaveToast('⚠️ Speciális dátum blokk itt nem törölhető', true);
                return;
            }

            if (!confirm(`Biztosan törlöd ezt az időblokkot?\n${getScopeLabel(block)}`)) {
                return;
            }

            timeBlocks = timeBlocks.filter((entry) => parseInt(entry.id, 10) !== parseInt(block.id, 10));
            activeScope = 'base';
            setActiveScope('base', true);
            scheduleAutoSave(250);
            showAutosaveToast('✓ Időblokk törölve');
        }
        
        // Module customization
        function customizeModule(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            // Initialize settings if not exists
            if (!item.settings) {
                item.settings = getDefaultSettings(moduleKey);
            }
            
            showCustomizationModal(item, index);
        }

        function updateTechnicalModuleVisibility() {
            const unconfiguredItem = document.getElementById('unconfiguredModuleItem');
            const noModulesMessage = document.getElementById('noModulesMessage');
            const normalModuleCount = document.querySelectorAll('.modules-panel .module-item:not(#unconfiguredModuleItem)').length;
            const realModulesExist = hasRealModules();

            if (isDefaultGroup) {
                if (unconfiguredItem) {
                    unconfiguredItem.style.display = 'block';
                }
                if (noModulesMessage) {
                    noModulesMessage.style.display = 'block';
                }
                return;
            }

            if (unconfiguredItem) {
                unconfiguredItem.style.display = realModulesExist ? 'none' : 'block';
            }

            if (noModulesMessage) {
                const hasVisibleTechnical = !!unconfiguredItem && !realModulesExist;
                noModulesMessage.style.display = (normalModuleCount === 0 && !hasVisibleTechnical) ? 'block' : 'none';
            }
        }
        
        function getModuleKeyById(moduleId) {
            // Try to find module key from available modules
            const modules = <?php echo json_encode(array_merge($available_modules, $unconfigured_module ? [$unconfigured_module] : [])); ?>;
            const module = modules.find(m => m.id == moduleId);
            return module ? module.module_key : null;
        }
        
        function getDefaultSettings(moduleKey) {
            const defaults = {
                'clock': {
                    type: 'digital',
                    format: '24h',
                    dateFormat: 'full',
                    timeColor: '#ffffff',
                    dateColor: '#ffffff',
                    bgColor: '#1e40af',
                    fontSize: 120,
                    clockSize: 300,
                    showSeconds: true,
                    language: 'hu'
                },
                'default-logo': {
                    text: 'EDUDISPLEJ',
                    fontSize: 120,
                    textColor: '#ffffff',
                    bgColor: '#1e40af',
                    showVersion: true,
                    version: 'v1.0'
                }
            };
            
            return defaults[moduleKey] || {};
        }
        
        function showCustomizationModal(item, index) {
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};
            
            let formHtml = '';
            
            // Generate form based on module type
            if (moduleKey === 'clock') {
                formHtml = `
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Típus:</label>
                            <select id="setting-type" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="digital" ${settings.type === 'digital' ? 'selected' : ''}>Digitális</option>
                                <option value="analog" ${settings.type === 'analog' ? 'selected' : ''}>Analóg</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Formátum:</label>
                            <select id="setting-format" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="24h" ${settings.format === '24h' ? 'selected' : ''}>24 órás</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum formátum:</label>
                            <select id="setting-dateFormat" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="full" ${settings.dateFormat === 'full' ? 'selected' : ''}>Teljes (év, hónap, nap, napnév)</option>
                                <option value="short" ${settings.dateFormat === 'short' ? 'selected' : ''}>Rövid (év, hónap, nap)</option>
                                <option value="numeric" ${settings.dateFormat === 'numeric' ? 'selected' : ''}>Numerikus (ÉÉÉÉ.HH.NN)</option>
                                <option value="none" ${settings.dateFormat === 'none' ? 'selected' : ''}>Nincs dátum</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Nyelv:</label>
                            <select id="setting-language" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                                <option value="hu" ${settings.language === 'hu' ? 'selected' : ''}>Magyar</option>
                                <option value="sk" ${settings.language === 'sk' ? 'selected' : ''}>Szlovák</option>
                            </select>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Óra szín:</label>
                                <input type="color" id="setting-timeColor" value="${settings.timeColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                            </div>
                            
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Dátum szín:</label>
                                <input type="color" id="setting-dateColor" value="${settings.dateColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                            </div>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#1e40af'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div id="digitalSettings" style="${settings.type === 'analog' ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Betűméret (px):</label>
                            <input type="number" id="setting-fontSize" value="${settings.fontSize || 120}" min="50" max="300" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div id="analogSettings" style="${settings.type === 'digital' ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Óra mérete (px):</label>
                            <input type="number" id="setting-clockSize" value="${settings.clockSize || 300}" min="200" max="600" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="setting-showSeconds" ${settings.showSeconds !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Másodpercek mutatása</span>
                            </label>
                        </div>
                    </div>
                `;
            } else if (moduleKey === 'default-logo') {
                formHtml = `
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Szöveg:</label>
                            <input type="text" id="setting-text" value="${settings.text || 'EDUDISPLEJ'}" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Betűméret (px):</label>
                            <input type="number" id="setting-fontSize" value="${settings.fontSize || 120}" min="50" max="300" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Szöveg szín:</label>
                            <input type="color" id="setting-textColor" value="${settings.textColor || '#ffffff'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                            <input type="color" id="setting-bgColor" value="${settings.bgColor || '#1e40af'}" style="width: 100%; height: 40px; border-radius: 5px;">
                        </div>
                        
                        <div>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="setting-showVersion" ${settings.showVersion !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                                <span style="font-weight: bold;">Verzió mutatása</span>
                            </label>
                        </div>
                        
                        <div id="versionSettings" style="${settings.showVersion === false ? 'display: none;' : ''}">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Verzió szöveg:</label>
                            <input type="text" id="setting-version" value="${settings.version || 'v1.0'}" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ccc;">
                        </div>
                    </div>
                `;
            } else if (moduleKey === 'pdf') {
                // PDF Viewer module - embed admin UI
                const pdfDataBase64 = settings.pdfDataBase64 || '';
                const fileSizeKB = pdfDataBase64 ? Math.round(pdfDataBase64.length / 1024) : 0;
                
                formHtml = `
                    <div style="display: grid; gap: 16px;">
                        <!-- PDF Upload Section -->
                        <div>
                            <label style="display: block; margin-bottom: 10px; font-weight: bold;">📄 PDF Feltöltés</label>
                            <div id="pdf-upload-area" style="
                                border: 2px dashed #1e40af;
                                border-radius: 8px;
                                padding: 30px;
                                text-align: center;
                                cursor: pointer;
                                transition: background-color 0.2s;
                                background-color: #f8f9fa;
                            " ondrop="handlePdfDrop(event)" ondragover="event.preventDefault(); event.currentTarget.style.backgroundColor='#e3f2fd';" ondragleave="event.currentTarget.style.backgroundColor='#f8f9fa';">
                                <input type="file" id="pdf-file-input" accept=".pdf" style="display: none;">
                                <div style="font-size: 14px; color: #425466;">
                                    Húzd ide a PDF-et vagy <span style="color: #1e40af; font-weight: bold; text-decoration: underline;">kattints a kiválasztáshoz</span>
                                </div>
                                <div style="font-size: 12px; color: #8a97a6; margin-top: 8px;">Max. 50 MB</div>
                                ${fileSizeKB > 0 ? `<div style="color: #28a745; margin-top: 8px; font-size: 13px;">✓ PDF betöltve (${fileSizeKB} KB)</div>` : ''}
                            </div>
                        </div>
                        
                        <!-- Tabs for settings -->
                        <div>
                            <div style="display: flex; gap: 8px; border-bottom: 2px solid #e0e6ed; margin-bottom: 16px;">
                                <button type="button" class="pdf-tab-btn" data-tab="basic" style="
                                    padding: 10px 16px;
                                    background: none;
                                    border: none;
                                    cursor: pointer;
                                    font-weight: bold;
                                    border-bottom: 3px solid #1e40af;
                                    color: #1e40af;
                                ">Alap</button>
                                <button type="button" class="pdf-tab-btn" data-tab="navigation" style="
                                    padding: 10px 16px;
                                    background: none;
                                    border: none;
                                    cursor: pointer;
                                    font-weight: bold;
                                    border-bottom: 3px solid transparent;
                                    color: #607083;
                                ">Navigáció</button>
                                <button type="button" class="pdf-tab-btn" data-tab="advanced" style="
                                    padding: 10px 16px;
                                    background: none;
                                    border: none;
                                    cursor: pointer;
                                    font-weight: bold;
                                    border-bottom: 3px solid transparent;
                                    color: #607083;
                                ">Haladó</button>
                            </div>
                            
                            <!-- Basic Tab -->
                            <div class="pdf-tab-content" data-tab="basic" style="display: grid; gap: 12px;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Tájolás:</label>
                                    <select id="pdf-orientation" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                        <option value="landscape" ${settings.orientation === 'landscape' ? 'selected' : ''}>Fekvő (landscape)</option>
                                        <option value="portrait" ${settings.orientation === 'portrait' ? 'selected' : ''}>Álló (portrait)</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Zoom szint (%):</label>
                                    <input type="number" id="pdf-zoomLevel" value="${settings.zoomLevel || 100}" min="50" max="400" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                </div>
                                
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Háttérszín:</label>
                                    <input type="color" id="pdf-bgColor" value="${settings.bgColor || '#ffffff'}" style="width: 100%; height: 40px; border: 1px solid #ccc; border-radius: 5px;">
                                </div>
                            </div>
                            
                            <!-- Navigation Tab -->
                            <div class="pdf-tab-content" data-tab="navigation" style="display: none; gap: 12px;">
                                <div>
                                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Navigációs mód:</label>
                                    <select id="pdf-navigationMode" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                        <option value="manual" ${settings.navigationMode === 'manual' ? 'selected' : ''}>Manuális (gombokkal)</option>
                                        <option value="auto" ${settings.navigationMode === 'auto' ? 'selected' : ''}>Automatikus (görgetés)</option>
                                    </select>
                                </div>
                                
                                <div class="auto-scroll-settings" style="display: ${settings.navigationMode === 'auto' ? 'grid' : 'none'}; gap: 12px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Görgetési sebesség (px/s):</label>
                                        <input type="number" id="pdf-scrollSpeed" value="${settings.autoScrollSpeedPxPerSec || 30}" min="5" max="200" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Kezdeti várakozás (ms):</label>
                                        <input type="number" id="pdf-startPause" value="${settings.autoScrollStartPauseMs || 2000}" min="0" max="10000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Vég várakozás (ms):</label>
                                        <input type="number" id="pdf-endPause" value="${settings.autoScrollEndPauseMs || 2000}" min="0" max="10000" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Advanced Tab -->
                            <div class="pdf-tab-content" data-tab="advanced" style="display: none; gap: 12px;">
                                <div>
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                        <input type="checkbox" id="pdf-fixedViewMode" ${settings.fixedViewMode ? 'checked' : ''} style="width: 20px; height: 20px;">
                                        <span style="font-weight: bold;">Rögzített oldal mód</span>
                                    </label>
                                </div>
                                
                                <div class="fixed-page-settings" style="display: ${settings.fixedViewMode ? 'grid' : 'none'}; gap: 12px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Megjelenítendő oldal:</label>
                                        <input type="number" id="pdf-fixedPage" value="${settings.fixedPage || 1}" min="1" max="999" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 5px;">
                                    </div>
                                </div>
                                
                                <div>
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                        <input type="checkbox" id="pdf-showPageNumbers" ${settings.showPageNumbers !== false ? 'checked' : ''} style="width: 20px; height: 20px;">
                                        <span style="font-weight: bold;">Oldal számok mutatása</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Preview Button -->
                        <button type="button" style="
                            padding: 10px 16px;
                            background: #17a2b8;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                            font-weight: bold;
                        " onclick="openPdfPreview()">
                            👁️ Előnézet
                        </button>
                    </div>
                    
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            // Tab switching
                            document.querySelectorAll('.pdf-tab-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const tab = this.dataset.tab;
                                    document.querySelectorAll('.pdf-tab-btn').forEach(b => {
                                        b.style.borderBottomColor = b.dataset.tab === tab ? '#1e40af' : 'transparent';
                                        b.style.color = b.dataset.tab === tab ? '#1e40af' : '#607083';
                                    });
                                    document.querySelectorAll('.pdf-tab-content').forEach(content => {
                                        content.style.display = content.dataset.tab === tab ? 'grid' : 'none';
                                    });
                                });
                            });
                            
                            // PDF File Upload
                            const uploadArea = document.getElementById('pdf-upload-area');
                            const fileInput = document.getElementById('pdf-file-input');
                            
                            uploadArea.addEventListener('click', () => fileInput.click());
                            fileInput.addEventListener('change', handlePdfFileSelect);
                            
                            // Navigation mode change
                            const navMode = document.getElementById('pdf-navigationMode');
                            if (navMode) {
                                navMode.addEventListener('change', function() {
                                    const autoSettings = document.querySelector('.auto-scroll-settings');
                                    if (autoSettings) {
                                        autoSettings.style.display = this.value === 'auto' ? 'grid' : 'none';
                                    }
                                });
                            }
                            
                            // Fixed view mode change
                            const fixedMode = document.getElementById('pdf-fixedViewMode');
                            if (fixedMode) {
                                fixedMode.addEventListener('change', function() {
                                    const settings = document.querySelector('.fixed-page-settings');
                                    if (settings) {
                                        settings.style.display = this.checked ? 'grid' : 'none';
                                    }
                                });
                            }
                        });
                        
                        function handlePdfDrop(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            const files = e.dataTransfer.files;
                            if (files.length > 0) {
                                handlePdfFile(files[0]);
                            }
                        }
                        
                        function handlePdfFileSelect(e) {
                            const files = e.target.files;
                            if (files.length > 0) {
                                handlePdfFile(files[0]);
                            }
                        }
                        
                        function handlePdfFile(file) {
                            if (file.type !== 'application/pdf') {
                                alert('⚠️ Csak PDF formátum támogatott');
                                return;
                            }
                            
                            if (file.size > 50 * 1024 * 1024) {
                                alert('⚠️ A fájl túl nagy (max. 50 MB)');
                                return;
                            }
                            
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                window.pdfModuleSettings = window.pdfModuleSettings || {};
                                window.pdfModuleSettings.pdfDataBase64 = String(e.target.result || '');
                                const uploadArea = document.getElementById('pdf-upload-area');
                                if (uploadArea) {
                                    const sizeKB = Math.round(window.pdfModuleSettings.pdfDataBase64.length / 1024);
                                    uploadArea.innerHTML = uploadArea.innerHTML.replace(/✓ PDF betöltve.*?<\\/div>/, '') + \`<div style="color: #28a745; margin-top: 8px; font-size: 13px;">✓ PDF betöltve (\${sizeKB} KB)</div>\`;
                                }
                            };
                            reader.readAsDataURL(file);
                        }
                        
                        function openPdfPreview() {
                            const base64 = window.pdfModuleSettings?.pdfDataBase64 || '${pdfDataBase64}';
                            if (!base64) {
                                alert('⚠️ Előbb tölts fel egy PDF-et');
                                return;
                            }
                            window.open('../../../modules/pdf/m_pdf.html?data=' + encodeURIComponent(base64.substring(0, 100000)), '_blank');
                        }
                    </script>
                `;
            } else {
                formHtml = '<p style="text-align: center; color: #999;">Ez a modul nem rendelkezik testreszabási lehetőségekkel.</p>';
            }
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 2000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">⚙️ ${item.module_name} - Testreszabás</h2>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            background: #1e40af;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                        ">✕</button>
                    </div>
                    
                    ${formHtml}
                    
                    <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            padding: 10px 20px;
                            background: #6c757d;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">Mégse</button>
                        <button onclick="saveCustomization(${index})" style="
                            padding: 10px 20px;
                            background: #28a745;
                            color: white;
                            border: none;
                            border-radius: 5px;
                            cursor: pointer;
                        ">💾 Mentés</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add event listeners for dynamic form changes
            const typeSelect = document.getElementById('setting-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', function() {
                    const digitalSettings = document.getElementById('digitalSettings');
                    const analogSettings = document.getElementById('analogSettings');
                    if (this.value === 'digital') {
                        digitalSettings.style.display = 'block';
                        analogSettings.style.display = 'none';
                    } else {
                        digitalSettings.style.display = 'none';
                        analogSettings.style.display = 'block';
                    }
                });
            }
            
            const showVersionCheckbox = document.getElementById('setting-showVersion');
            if (showVersionCheckbox) {
                showVersionCheckbox.addEventListener('change', function() {
                    const versionSettings = document.getElementById('versionSettings');
                    versionSettings.style.display = this.checked ? 'block' : 'none';
                });
            }
        }
        
        function saveCustomization(index) {
            if (isDefaultGroup) {
                return;
            }

            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            const newSettings = {};
            
            // Collect all settings from form
            if (moduleKey === 'clock') {
                newSettings.type = document.getElementById('setting-type')?.value || 'digital';
                newSettings.format = document.getElementById('setting-format')?.value || '24h';
                newSettings.dateFormat = document.getElementById('setting-dateFormat')?.value || 'full';
                newSettings.timeColor = document.getElementById('setting-timeColor')?.value || '#ffffff';
                newSettings.dateColor = document.getElementById('setting-dateColor')?.value || '#ffffff';
                newSettings.bgColor = document.getElementById('setting-bgColor')?.value || '#1e40af';
                newSettings.fontSize = parseInt(document.getElementById('setting-fontSize')?.value) || 120;
                newSettings.clockSize = parseInt(document.getElementById('setting-clockSize')?.value) || 300;
                newSettings.showSeconds = document.getElementById('setting-showSeconds')?.checked !== false;
                newSettings.language = document.getElementById('setting-language')?.value || 'hu';
            } else if (moduleKey === 'default-logo') {
                newSettings.text = document.getElementById('setting-text')?.value || 'EDUDISPLEJ';
                newSettings.fontSize = parseInt(document.getElementById('setting-fontSize')?.value) || 120;
                newSettings.textColor = document.getElementById('setting-textColor')?.value || '#ffffff';
                newSettings.bgColor = document.getElementById('setting-bgColor')?.value || '#1e40af';
                newSettings.showVersion = document.getElementById('setting-showVersion')?.checked !== false;
                newSettings.version = document.getElementById('setting-version')?.value || 'v1.0';
            } else if (moduleKey === 'pdf') {
                // PDF module settings
                const pdfBase64 = window.pdfModuleSettings?.pdfDataBase64 || (item.settings?.pdfDataBase64 || '');
                
                newSettings.pdfDataBase64 = pdfBase64;
                newSettings.orientation = document.getElementById('pdf-orientation')?.value || 'landscape';
                newSettings.zoomLevel = parseInt(document.getElementById('pdf-zoomLevel')?.value) || 100;
                newSettings.navigationMode = document.getElementById('pdf-navigationMode')?.value || 'manual';
                newSettings.displayMode = 'fit-page';
                newSettings.autoScrollSpeedPxPerSec = parseInt(document.getElementById('pdf-scrollSpeed')?.value) || 30;
                newSettings.autoScrollStartPauseMs = parseInt(document.getElementById('pdf-startPause')?.value) || 2000;
                newSettings.autoScrollEndPauseMs = parseInt(document.getElementById('pdf-endPause')?.value) || 2000;
                newSettings.pausePoints = [];
                newSettings.fixedViewMode = document.getElementById('pdf-fixedViewMode')?.checked || false;
                newSettings.fixedPage = parseInt(document.getElementById('pdf-fixedPage')?.value) || 1;
                newSettings.bgColor = document.getElementById('pdf-bgColor')?.value || '#ffffff';
                newSettings.showPageNumbers = document.getElementById('pdf-showPageNumbers')?.checked !== false;
            }
            
            loopItems[index].settings = newSettings;
            
            // Close modal
            document.querySelectorAll('body > div').forEach(el => {
                if (el.style.position === 'fixed' && el.style.zIndex === '2000') {
                    el.remove();
                }
            });
            
            showAutosaveToast('✓ Beállítások mentve');
            scheduleAutoSave(250);
            if (loopItems.length > 0) {
                startPreview();
            }
        }
        
        // ===== LIVE PREVIEW FUNCTIONS =====
        
        function startPreview() {
            if (loopItems.length === 0) {
                alert('⚠️ Nincs modul a loop-ban!');
                return;
            }
            
            stopPreview(); // Clear any existing preview
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;
            totalLoopStartTime = Date.now();
            
            document.getElementById('btnPlay').style.display = 'none';
            document.getElementById('btnPause').style.display = 'inline-block';
            document.getElementById('loopStatus').textContent = 'Lejátszás...';
            
            playCurrentModule();
        }
        
        function pausePreview() {
            if (isPaused) {
                // Resume
                isPaused = false;
                document.getElementById('btnPause').innerHTML = '⏸️ Szünet';
                document.getElementById('loopStatus').textContent = 'Lejátszás...';
                playCurrentModule();
            } else {
                // Pause
                isPaused = true;
                document.getElementById('btnPause').innerHTML = '▶️ Folytatás';
                document.getElementById('loopStatus').textContent = 'Szüneteltetve';
                clearTimeout(previewTimeout);
                clearInterval(previewInterval);
            }
        }
        
        function stopPreview() {
            isPaused = false;
            currentPreviewIndex = 0;
            loopCycleCount = 0;
            
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            document.getElementById('btnPlay').style.display = 'inline-block';
            document.getElementById('btnPause').style.display = 'none';
            document.getElementById('loopStatus').textContent = 'Leállítva';
            document.getElementById('currentModule').textContent = '—';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = '0s / 0s';
            document.getElementById('loopCount').textContent = '0';
            document.getElementById('navInfo').textContent = '—';
            
            // Hide iframe, show empty message
            document.getElementById('previewIframe').style.display = 'none';
            document.getElementById('previewEmpty').style.display = 'block';
        }
        
        function previousModule() {
            if (loopItems.length === 0) return;
            
            // Stop current playback
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            // Go to previous module
            currentPreviewIndex--;
            if (currentPreviewIndex < 0) {
                currentPreviewIndex = loopItems.length - 1;
                if (loopCycleCount > 0) loopCycleCount--;
            }
            
            // Update cycle count display
            document.getElementById('loopCount').textContent = loopCycleCount;
            
            // Play the module
            playCurrentModule();
        }
        
        function nextModule() {
            if (loopItems.length === 0) return;
            
            // Stop current playback
            clearTimeout(previewTimeout);
            clearInterval(previewInterval);
            
            // Go to next module
            currentPreviewIndex++;
            if (currentPreviewIndex >= loopItems.length) {
                currentPreviewIndex = 0;
                loopCycleCount++;
            }
            
            // Update cycle count display
            document.getElementById('loopCount').textContent = loopCycleCount;
            
            // Play the module
            playCurrentModule();
        }
        
        function playCurrentModule() {
            if (isPaused) return;
            
            // Ha nincs elem a loop-ban, ne fusson
            if (loopItems.length === 0) {
                stopPreview();
                return;
            }
            
            // Ha csak 1 elem van, akkor is loopoljon
            const module = loopItems[currentPreviewIndex];
            const duration = parseInt(module.duration_seconds) || 10;
            
            // Update info
            document.getElementById('currentModule').textContent = `${currentPreviewIndex + 1}. ${module.module_name}`;
            document.getElementById('navInfo').textContent = `${currentPreviewIndex + 1} / ${loopItems.length}`;
            
            // Build module URL with settings
            const moduleUrl = buildModuleUrl(module);
            
            // Load module in iframe
            const iframe = document.getElementById('previewIframe');
            const emptyDiv = document.getElementById('previewEmpty');
            
            iframe.src = moduleUrl;
            iframe.style.display = 'block';
            emptyDiv.style.display = 'none';
            
            // Start progress bar
            currentModuleStartTime = Date.now();
            updateProgressBar(duration);
            
            // MINDIG schedule-ölj következő modult (még 1 elem esetén is loop)
            previewTimeout = setTimeout(() => {
                currentPreviewIndex++;
                
                if (currentPreviewIndex >= loopItems.length) {
                    currentPreviewIndex = 0;
                    loopCycleCount++;
                    totalLoopStartTime = Date.now(); // Reset total loop timer
                    document.getElementById('loopCount').textContent = loopCycleCount;
                }
                
                // Rekurzív hívás - MINDIG fut tovább
                playCurrentModule();
            }, duration * 1000);
        }
        
        function updateProgressBar(duration) {
            clearInterval(previewInterval);
            
            const totalDuration = getTotalLoopDuration();
            
            previewInterval = setInterval(() => {
                if (isPaused) return;
                
                const elapsedInLoop = getElapsedTimeInLoop();
                const percentage = Math.min((elapsedInLoop / totalDuration) * 100, 100);
                
                document.getElementById('progressBar').style.width = percentage + '%';
                document.getElementById('progressText').textContent = `${Math.floor(elapsedInLoop)}s / ${totalDuration}s`;
                
                if (elapsedInLoop >= totalDuration) {
                    clearInterval(previewInterval);
                }
            }, 100);
        }
        
        function buildModuleUrl(module) {
            const moduleKey = module.module_key || getModuleKeyById(module.module_id);
            const settings = module.settings || {};
            
            let baseUrl = '';
            let params = new URLSearchParams();
            
            // Determine module path
            switch(moduleKey) {
                case 'clock':
                    baseUrl = '../modules/clock/m_clock.html';
                    // Add all clock settings as URL parameters
                    if (settings.type) params.append('type', settings.type);
                    if (settings.format) params.append('format', settings.format);
                    if (settings.dateFormat) params.append('dateFormat', settings.dateFormat);
                    if (settings.timeColor) params.append('timeColor', settings.timeColor);
                    if (settings.dateColor) params.append('dateColor', settings.dateColor);
                    if (settings.bgColor) params.append('bgColor', settings.bgColor);
                    if (settings.fontSize) params.append('fontSize', settings.fontSize);
                    if (settings.clockSize) params.append('clockSize', settings.clockSize);
                    if (settings.showSeconds !== undefined) params.append('showSeconds', settings.showSeconds);
                    if (settings.language) params.append('language', settings.language);
                    break;
                    
                case 'default-logo':
                    baseUrl = '../modules/default/m_default.html';
                    if (settings.text) params.append('text', settings.text);
                    if (settings.fontSize) params.append('fontSize', settings.fontSize);
                    if (settings.textColor) params.append('textColor', settings.textColor);
                    if (settings.bgColor) params.append('bgColor', settings.bgColor);
                    if (settings.showVersion !== undefined) params.append('showVersion', settings.showVersion);
                    if (settings.version) params.append('version', settings.version);
                    break;
                    
                default:
                    // Default fallback - show module name
                    baseUrl = '../modules/default/m_default.html';
                    params.append('text', module.module_name);
                    params.append('bgColor', '#1a3a52');
            }
            
            const queryString = params.toString();
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }

        function formatLanguageCode(language) {
            const code = String(language || 'hu').toLowerCase();
            if (code === 'hu') return 'HU';
            if (code === 'sk') return 'SK';
            if (code === 'en') return 'EN';
            return code.toUpperCase();
        }

        function getLoopItemSummary(item) {
            if (!item) {
                return '';
            }

            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            const settings = item.settings || {};

            if (moduleKey === 'clock') {
                const type = settings.type === 'analog' ? 'Analóg' : 'Digitális';
                const details = [type];

                if ((settings.type || 'digital') !== 'analog') {
                    details.push('24h');
                }

                const language = formatLanguageCode(settings.language);
                return `${details.join(' • ')}<br>Nyelv: ${language}`;
            }

            if (moduleKey === 'default-logo' && settings.text) {
                return `${String(settings.text).slice(0, 24)}`;
            }

            return '';
        }
        
        // Update preview when loop changes
        function renderLoop() {
            const container = document.getElementById('loop-container');
            updateTechnicalModuleVisibility();
            
            if (loopItems.length === 0) {
                container.className = 'empty';
                container.innerHTML = '<p>Nincs elem a loop-ban. Húzz ide modult az „Elérhető Modulok” panelről.</p>';
                syncLoopContainerHeightToModules();
                updateTotalDuration();
                stopPreview(); // Stop preview if loop is empty
                return;
            }
            
            container.className = '';
            container.innerHTML = '';
            
            loopItems.forEach((item, index) => {
                const loopItem = document.createElement('div');
                loopItem.className = 'loop-item';
                loopItem.draggable = !isDefaultGroup && !isContentOnlyMode;
                loopItem.dataset.index = index;

                const isTechnicalItem = isTechnicalLoopItem(item);
                const durationValue = isTechnicalItem ? 60 : parseInt(item.duration_seconds || 10);
                const durationInputHtml = (isDefaultGroup || isTechnicalItem || isContentOnlyMode)
                    ? `<input type="number" value="${durationValue}" min="1" max="300" disabled>`
                    : `<input type="number" value="${durationValue}" min="1" max="300" onchange="updateDuration(${index}, this.value)" onclick="event.stopPropagation()">`;

                const actionButtonsHtml = isDefaultGroup
                    ? `<button class="loop-btn" disabled title="A default csoport nem szerkeszthető">🔒</button>`
                    : isContentOnlyMode
                    ? `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>`
                    : `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>
                        <button class="loop-btn" onclick="duplicateLoopItem(${index}); event.stopPropagation();" title="Duplikálás">📄</button>
                        <button class="loop-btn" onclick="removeFromLoop(${index}); event.stopPropagation();" title="Törlés">🗑️</button>`;
                
                loopItem.innerHTML = `
                    <div class="loop-order">${index + 1}</div>
                    <div class="loop-details">
                        <div class="loop-module-name">${item.module_name}</div>
                        <div class="loop-module-desc">${getLoopItemSummary(item)}</div>
                    </div>
                    <div class="loop-duration">
                        <label>Időtartam</label>
                        ${durationInputHtml}
                        <span style="font-size: 11px; opacity: 0.9;">sec</span>
                    </div>
                    <div class="loop-actions">
                        ${actionButtonsHtml}
                    </div>
                `;
                
                // Drag and drop handlers
                if (!isDefaultGroup && !isContentOnlyMode) {
                    loopItem.addEventListener('dragstart', handleDragStart);
                    loopItem.addEventListener('dragover', handleDragOver);
                    loopItem.addEventListener('drop', handleDrop);
                    loopItem.addEventListener('dragend', handleDragEnd);
                }
                
                container.appendChild(loopItem);
            });

            syncLoopContainerHeightToModules();
            
            updateTotalDuration();
            if (loopItems.length > 0) {
                startPreview();
            }
        }

        function buildFixedPlanTimeOptions() {
            const startSelect = document.getElementById('fixed-plan-start');
            const endSelect = document.getElementById('fixed-plan-end');
            if (!startSelect || !endSelect) {
                return;
            }

            const values = [];
            for (let minute = 0; minute < 24 * 60; minute += 15) {
                const hours = String(Math.floor(minute / 60)).padStart(2, '0');
                const mins = String(minute % 60).padStart(2, '0');
                values.push(`${hours}:${mins}`);
            }

            const applyOptions = (selectEl, preferredValue) => {
                const normalized = String(preferredValue || '').slice(0, 5);
                selectEl.innerHTML = '';

                values.forEach((value) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    selectEl.appendChild(option);
                });

                if (normalized && !values.includes(normalized)) {
                    const extra = document.createElement('option');
                    extra.value = normalized;
                    extra.textContent = normalized;
                    selectEl.appendChild(extra);
                }

                selectEl.value = normalized && (values.includes(normalized) || selectEl.querySelector(`option[value="${normalized}"]`))
                    ? normalized
                    : values[0];
            };

            applyOptions(startSelect, startSelect.value || '08:00');
            applyOptions(endSelect, endSelect.value || '10:00');
        }
        
        // Load loop on page load
        buildFixedPlanTimeOptions();
        loadLoop();

        function toggleGroupNameEdit(enable) {
            const display = document.getElementById('group-name-display');
            const editBtn = document.getElementById('group-name-edit-btn');
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');

            if (!display || !editBtn || !editWrap || !input) {
                return;
            }

            if (enable) {
                display.style.display = 'none';
                editBtn.style.display = 'none';
                editWrap.style.display = 'inline';
                input.focus();
                input.select();
            } else {
                display.style.display = 'inline';
                editBtn.style.display = 'inline';
                editWrap.style.display = 'none';
                input.value = display.textContent || input.value;
            }
        }

        document.addEventListener('keydown', function (event) {
            const editWrap = document.getElementById('group-name-edit-wrap');
            const input = document.getElementById('rename-group-inline-input');
            if (!editWrap || !input || editWrap.style.display !== 'inline') {
                if (event.key === 'Escape') {
                    cancelScheduleBlockResize();
                    cancelScheduleRangeSelection();
                }
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                renameCurrentGroup();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                toggleGroupNameEdit(false);
            }
        });

        document.addEventListener('mouseup', function () {
            if (scheduleBlockResize) {
                finishScheduleBlockResize();
                return;
            }
            if (!scheduleRangeSelection) {
                return;
            }
            finishScheduleRangeSelection();
        });

        function renameCurrentGroup() {
            if (isDefaultGroup || isContentOnlyMode) {
                return;
            }

            const input = document.getElementById('rename-group-inline-input');
            if (!input) {
                return;
            }

            const newName = String(input.value || '').trim();
            if (!newName) {
                alert('⚠️ Adj meg egy csoportnevet.');
                return;
            }

            const formData = new FormData();
            formData.append('group_id', String(groupId));
            formData.append('new_name', newName);

            fetch('../api/rename_group.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('⚠️ ' + (data.message || 'Átnevezési hiba'));
                    return;
                }
                const display = document.getElementById('group-name-display');
                if (display) {
                    display.textContent = newName;
                }
                toggleGroupNameEdit(false);
            })
            .catch(() => {
                alert('⚠️ Átnevezési hiba.');
            });
        }
        
        // Load group display resolutions and populate the selector
        function loadGroupResolutions() {
            fetch(`../api/get_group_kiosks.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.kiosks && data.kiosks.length > 0) {
                        const selector = document.getElementById('resolutionSelector');
                        const resolutions = new Set();
                        
                        // Collect unique resolutions from kiosks
                        data.kiosks.forEach(kiosk => {
                            if (kiosk.screen_resolution) {
                                resolutions.add(kiosk.screen_resolution);
                            }
                        });
                        
                        // Add group-specific resolutions to the top if they exist
                        if (resolutions.size > 0) {
                            selector.innerHTML = ''; // Clear existing options
                            
                            // Add group-specific resolutions
                            Array.from(resolutions).forEach(res => {
                                const [width, height] = res.split('x').map(Number);
                                let aspectRatio = '';
                                if (width && height) {
                                    const gcd = (a, b) => b ? gcd(b, a % b) : a;
                                    const divisor = gcd(width, height);
                                    const ratioW = width / divisor;
                                    const ratioH = height / divisor;
                                    aspectRatio = ` (${ratioW}:${ratioH})`;
                                }
                                const option = document.createElement('option');
                                option.value = res;
                                option.textContent = `${res}${aspectRatio} - Csoport kijelző`;
                                selector.appendChild(option);
                            });
                            
                            // Add separator
                            const separator = document.createElement('option');
                            separator.disabled = true;
                            separator.textContent = '──────────────────';
                            selector.appendChild(separator);
                        }
                        
                        // Add standard resolutions
                        const standardResolutions = [
                            { value: '1920x1080', label: '1920x1080 (16:9 Full HD)' },
                            { value: '1280x720', label: '1280x720 (16:9 HD)' },
                            { value: '1024x768', label: '1024x768 (4:3 XGA)' },
                            { value: '1600x900', label: '1600x900 (16:9 HD+)' },
                            { value: '1366x768', label: '1366x768 (16:9 WXGA)' }
                        ];
                        
                        standardResolutions.forEach(res => {
                            const option = document.createElement('option');
                            option.value = res.value;
                            option.textContent = res.label;
                            selector.appendChild(option);
                        });
                    }

                    updatePreviewResolution();
                })
                .catch(err => {
                    console.error('Error loading group resolutions:', err);
                });
        }

        function fitPreviewScreen(width, height) {
            const previewScreen = document.getElementById('previewScreen');
            const previewPanel = document.querySelector('.preview-panel');
            const previewCol = document.querySelector('.loop-main-right');

            if (!previewScreen || !previewPanel || !previewCol || !width || !height) {
                return;
            }

            const panelStyle = window.getComputedStyle(previewPanel);
            const panelPaddingX = (parseFloat(panelStyle.paddingLeft) || 0) + (parseFloat(panelStyle.paddingRight) || 0);

            const maxWidth = Math.max(180, previewPanel.clientWidth - panelPaddingX - 4);

            let occupiedHeight = 0;
            Array.from(previewPanel.children).forEach((child) => {
                if (child === previewScreen) {
                    return;
                }
                const childStyle = window.getComputedStyle(child);
                const marginTop = parseFloat(childStyle.marginTop) || 0;
                const marginBottom = parseFloat(childStyle.marginBottom) || 0;
                occupiedHeight += child.offsetHeight + marginTop + marginBottom;
            });

            const viewportMaxHeight = Math.max(260, window.innerHeight - 30);
            const panelPaddingY = (parseFloat(panelStyle.paddingTop) || 0) + (parseFloat(panelStyle.paddingBottom) || 0);
            const availableHeight = Math.max(120, viewportMaxHeight - panelPaddingY - occupiedHeight - 12);
            const ratio = width / height;

            let boxWidth = maxWidth;
            let boxHeight = boxWidth / ratio;

            if (boxHeight > availableHeight) {
                boxHeight = availableHeight;
                boxWidth = boxHeight * ratio;
            }

            previewScreen.style.width = `${Math.round(boxWidth)}px`;
            previewScreen.style.height = `${Math.round(boxHeight)}px`;
            previewScreen.style.aspectRatio = `${width} / ${height}`;
        }
        
        // Update preview resolution
        function updatePreviewResolution() {
            const selector = document.getElementById('resolutionSelector');
            const resolution = selector.value;
            const [width, height] = resolution.split('x').map(Number);
            
            if (width && height) {
                fitPreviewScreen(width, height);
            }
        }

        window.addEventListener('resize', () => {
            syncLoopContainerHeightToModules();
            updatePreviewResolution();
        });
        
        // Load resolutions on page load
        initLoopContainerHeightSync();
        loadGroupResolutions();
    </script>
    <?php endif; ?>

<?php include '../../admin/footer.php'; ?>
