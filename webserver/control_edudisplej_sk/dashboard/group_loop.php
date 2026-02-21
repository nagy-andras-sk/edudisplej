<?php
/**
 * Group Loop Configuration
 * Advanced loop editor with drag-and-drop
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
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
    ['label' => '📁 ' . t('nav.groups'), 'href' => 'groups.php'],
    ['label' => '👥 ' . $breadcrumb_group_name, 'href' => 'group_kiosks.php?id=' . (int)$group_id],
    ['label' => '⚙️ Loop config', 'current' => true],
];

$logout_url = '../login.php?logout=1';

// Get available modules for this company
$available_modules = [];
$unconfigured_module = null;
try {
    $conn = getDbConnection();
    
    // Get modules - only those with licenses or default modules
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

        // Include module if it has license OR if it's a default/system module
        if ($row['license_quantity'] > 0 || in_array($row['module_key'], ['clock', 'default-logo'])) {
            $available_modules[] = $row;
        }
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
}
?>
<?php include '../admin/header.php'; ?>
    <style>
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

        .loop-layout-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 0;
            overflow: visible;
            box-shadow: none;
            border: 1px solid #cfd6dd;
        }

        .loop-layout-table th,
        .loop-layout-table td {
            border: 1px solid #cfd6dd;
            vertical-align: top;
        }

        .loop-layout-table thead th {
            background: #f8f9fb;
            text-align: left;
            padding: 12px 16px;
            font-size: 14px;
            color: #1f2d3d;
            font-weight: 600;
        }

        .layout-col-modules {
            width: 24%;
            padding: 14px;
        }

        .layout-col-config {
            width: 41%;
            padding: 14px;
        }

        .layout-col-preview {
            width: 35%;
            padding: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 280px 1fr 380px;
            gap: 20px;
        }
        
        @media (max-width: 1200px) {
            .loop-layout-table,
            .loop-layout-table thead,
            .loop-layout-table tbody,
            .loop-layout-table tr,
            .loop-layout-table th,
            .loop-layout-table td {
                display: block;
                width: 100%;
            }

            .loop-layout-table td,
            .loop-layout-table th {
                border-width: 0 0 1px 0;
            }

            .layout-col-modules,
            .layout-col-config,
            .layout-col-preview {
                padding: 12px;
            }

            .preview-panel {
                position: static;
                top: auto;
                max-height: none;
                overflow: visible;
            }
        }
        
        .modules-panel {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
            max-height: 720px;
            overflow-y: auto;
        }
        
        .modules-panel h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .module-item {
            background: #f4f6f8;
            padding: 12px;
            border-radius: 0;
            margin-bottom: 10px;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            border: 1px solid #c7ced6;
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
        
        .loop-builder {
            background: #fff;
            padding: 14px;
            border-radius: 0;
            border: 1px solid #cfd6dd;
            box-shadow: none;
        }
        
        .loop-builder h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        #loop-container {
            min-height: 300px;
            border: 1px dashed #9aa6b2;
            border-radius: 0;
            padding: 15px;
            background: #f4f6f8;
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
            margin-top: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .btn {
            padding: 12px 24px;
            border: 1px solid transparent;
            border-radius: 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
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
            padding: 12px 20px;
            border-radius: 0;
            color: #0066cc;
            font-weight: bold;
            margin-left: auto;
            border: 1px solid #c2d8f2;
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
            max-height: calc(100vh - 20px);
            overflow: auto;
        }
        
        .preview-panel h2 {
            margin-bottom: 8px;
            color: #333;
            font-size: 16px;
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
    </style>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <table class="loop-layout-table">
        <thead>
            <tr>
                <th colspan="3">
                    ⚙️ Loop Testreszabás • Csoport:
                    <strong id="group-name-display"><?php echo htmlspecialchars($group['name']); ?></strong>
                    <?php if (!$is_default_group): ?>
                        <button type="button" id="group-name-edit-btn" onclick="toggleGroupNameEdit(true)" style="margin-left:8px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Átnevezés">✏️</button>
                        <span id="group-name-edit-wrap" style="display:none;margin-left:8px;">
                            <input type="text" id="rename-group-inline-input" value="<?php echo htmlspecialchars($group['name'] ?? '', ENT_QUOTES); ?>" style="min-width:220px;">
                            <button type="button" onclick="renameCurrentGroup()" style="margin-left:6px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Jóváhagyás">✅</button>
                            <button type="button" onclick="toggleGroupNameEdit(false)" style="margin-left:2px;border:none;background:transparent;cursor:pointer;font-size:14px;" title="Mégse">✖️</button>
                        </span>
                    <?php endif; ?>
                </th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="layout-col-modules">
                    <div class="modules-panel">
                <h2>📦 Elérhető Modulok</h2>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Kattints a modulra a hozzáadáshoz</p>
                
                <?php if (!$is_default_group): ?>
                    <?php foreach ($available_modules as $module): ?>
                        <div class="module-item" onclick="addModuleToLoop(<?php echo $module['id']; ?>, '<?php echo htmlspecialchars($module['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($module['description'] ?? '', ENT_QUOTES); ?>')">
                            <div class="module-name"><?php echo htmlspecialchars($module['name']); ?></div>
                            <div class="module-desc"><?php echo htmlspecialchars($module['description'] ?? ''); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($unconfigured_module): ?>
                    <div id="unconfiguredModuleItem" class="module-item" style="display: <?php echo $is_default_group ? 'block' : 'none'; ?>;" <?php if (!$is_default_group): ?>onclick="addModuleToLoop(<?php echo (int)$unconfigured_module['id']; ?>, '<?php echo htmlspecialchars($unconfigured_module['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($unconfigured_module['description'] ?? '', ENT_QUOTES); ?>')"<?php endif; ?>>
                        <div class="module-name"><?php echo htmlspecialchars($unconfigured_module['name']); ?></div>
                        <div class="module-desc"><?php echo htmlspecialchars($unconfigured_module['description'] ?? 'Technikai modul – csak üres loop esetén.'); ?></div>
                    </div>
                <?php endif; ?>
                
                <p id="noModulesMessage" style="text-align: center; color: #999; padding: 20px; display: <?php echo ($is_default_group || empty($available_modules)) ? 'block' : 'none'; ?>;"><?php echo $is_default_group ? 'A default csoportnál csak az unconfigured modul engedélyezett.' : 'Nincsenek elérhető modulok'; ?></p>
                    </div>
                </td>

                <td class="layout-col-config">
                    <div class="loop-builder">
                <h2>🔄 Loop Konfiguráció</h2>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;"><?php echo $is_default_group ? 'A default csoport loopja rögzített, nem szerkeszthető.' : 'Húzd és ejtsd az elemeket a sorrend megváltoztatásához'; ?></p>
                
                <div id="loop-container" class="empty">
                    <p>Nincs elem a loop-ban. Kattints egy modulra a hozzáadáshoz.</p>
                </div>
                
                <div class="control-panel">
                    <button class="btn btn-success" onclick="saveLoop()" <?php echo $is_default_group ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>💾 Mentés</button>
                    <button class="btn btn-danger" onclick="clearLoop()" <?php echo $is_default_group ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>🗑️ Összes törlése</button>
                    <div class="total-duration" id="total-duration">Össz: 0 mp</div>
                </div>
                    </div>
                </td>

                <td class="layout-col-preview">
                    <div class="preview-panel">
                <h2>📺 Élő Előnézet</h2>
                
                <!-- Resolution Selector -->
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
                </td>
            </tr>
        </tbody>
    </table>
    
    <script>
        let loopItems = [];
        const groupId = <?php echo $group_id; ?>;
        const isDefaultGroup = <?php echo $is_default_group ? 'true' : 'false'; ?>;
        const technicalModule = <?php echo json_encode($unconfigured_module ? [
            'id' => (int)$unconfigured_module['id'],
            'name' => $unconfigured_module['name'],
            'description' => $unconfigured_module['description'] ?? ''
        ] : null); ?>;

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
                loopItems = defaultItem ? [defaultItem] : [];
                renderLoop();
                if (loopItems.length > 0) {
                    setTimeout(() => startPreview(), 500);
                }
                return;
            }

            fetch(`../api/group_loop_config.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loopItems = Array.isArray(data.loops) ? data.loops : [];
                        normalizeLoopItems();
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
            if (isDefaultGroup) {
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
        }
        
        function removeFromLoop(index) {
            if (isDefaultGroup) {
                return;
            }

            loopItems.splice(index, 1);
            normalizeLoopItems();
            renderLoop();
        }
        
        function updateDuration(index, value) {
            if (isDefaultGroup) {
                return;
            }

            if (isTechnicalLoopItem(loopItems[index])) {
                loopItems[index].duration_seconds = 60;
                updateTotalDuration();
                if (loopItems.length > 0) {
                    startPreview();
                }
                return;
            }

            loopItems[index].duration_seconds = parseInt(value) || 10;
            updateTotalDuration();
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
            if (isDefaultGroup) {
                return;
            }

            if (confirm('Biztosan törölni szeretnéd az összes elemet?')) {
                loopItems = [];
                normalizeLoopItems();
                renderLoop();
            }
        }
        
        function saveLoop() {
            if (isDefaultGroup) {
                alert('⚠️ A default csoport loopja nem szerkeszthető.');
                return;
            }

            if (loopItems.length === 0) {
                alert('⚠️ A loop üres! Adj hozzá legalább egy modult.');
                return;
            }
            
            fetch(`../api/group_loop_config.php?group_id=${groupId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(loopItems)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ ' + data.message);
                    loadLoop(); // Reload to get IDs from database
                } else {
                    alert('⚠️ ' + data.message);
                }
            })
            .catch(error => {
                alert('⚠️ Hiba történt: ' + error);
            });
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
                'datetime': {
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
                'dateclock': {
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
            if (['clock', 'datetime', 'dateclock'].includes(moduleKey)) {
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
                                <option value="12h" ${settings.format === '12h' ? 'selected' : ''}>12 órás (AM/PM)</option>
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
            if (['clock', 'datetime', 'dateclock'].includes(moduleKey)) {
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
            }
            
            loopItems[index].settings = newSettings;
            
            // Close modal
            document.querySelectorAll('body > div').forEach(el => {
                if (el.style.position === 'fixed' && el.style.zIndex === '2000') {
                    el.remove();
                }
            });
            
            alert('✓ Beállítások mentve! Ne felejtsd el menteni a loop konfigurációt!');
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
                case 'datetime':
                case 'dateclock':
                    baseUrl = '../modules/datetime/m_datetime.html';
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

            if (['clock', 'datetime', 'dateclock'].includes(moduleKey)) {
                const type = settings.type === 'analog' ? 'Analóg' : 'Digitális';
                const details = [type];

                if ((settings.type || 'digital') !== 'analog') {
                    details.push(settings.format === '12h' ? '12h' : '24h');
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
                container.innerHTML = '<p>Nincs elem a loop-ban. Kattints egy modulra a hozzáadáshoz.</p>';
                updateTotalDuration();
                stopPreview(); // Stop preview if loop is empty
                return;
            }
            
            container.className = '';
            container.innerHTML = '';
            
            loopItems.forEach((item, index) => {
                const loopItem = document.createElement('div');
                loopItem.className = 'loop-item';
                loopItem.draggable = !isDefaultGroup;
                loopItem.dataset.index = index;

                const isTechnicalItem = isTechnicalLoopItem(item);
                const durationValue = isTechnicalItem ? 60 : parseInt(item.duration_seconds || 10);
                const durationInputHtml = (isDefaultGroup || isTechnicalItem)
                    ? `<input type="number" value="${durationValue}" min="1" max="300" disabled>`
                    : `<input type="number" value="${durationValue}" min="1" max="300" onchange="updateDuration(${index}, this.value)" onclick="event.stopPropagation()">`;

                const actionButtonsHtml = isDefaultGroup
                    ? `<button class="loop-btn" disabled title="A default csoport nem szerkeszthető">🔒</button>`
                    : `<button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>
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
                if (!isDefaultGroup) {
                    loopItem.addEventListener('dragstart', handleDragStart);
                    loopItem.addEventListener('dragover', handleDragOver);
                    loopItem.addEventListener('drop', handleDrop);
                    loopItem.addEventListener('dragend', handleDragEnd);
                }
                
                container.appendChild(loopItem);
            });
            
            updateTotalDuration();
            if (loopItems.length > 0) {
                startPreview();
            }
        }
        
        // Load loop on page load
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

        function renameCurrentGroup() {
            if (isDefaultGroup) {
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
            const previewCol = document.querySelector('.layout-col-preview');

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
                
                console.log(`Preview resolution updated to ${resolution} (${width}:${height})`);
            }
        }

        window.addEventListener('resize', () => {
            updatePreviewResolution();
        });
        
        // Load resolutions on page load
        loadGroupResolutions();
    </script>

<?php include '../admin/footer.php'; ?>
