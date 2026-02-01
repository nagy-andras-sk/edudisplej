<?php
/**
 * Group Loop Configuration
 * Advanced loop editor with drag-and-drop
 */

session_start();
require_once '../dbkonfiguracia.php';

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

$group = null;

try {
    $conn = getDbConnection();
    
    // Get group info
    $stmt = $conn->prepare("SELECT * FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Check permissions
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        header('Location: groups.php');
        exit();
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $error = 'Adatbázis hiba: ' . $e->getMessage();
    error_log($e->getMessage());
}

// Get available modules for this company
$available_modules = [];
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
        // Include module if it has license OR if it's a default/system module
        if ($row['license_quantity'] > 0 || in_array($row['module_key'], ['clock', 'default-logo', 'unconfigured'])) {
            $available_modules[] = $row;
        }
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loop Testreszabás - <?php echo htmlspecialchars($group['name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .header h1 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .content {
            display: grid;
            grid-template-columns: 300px 1fr 400px;
            gap: 20px;
        }
        
        .modules-panel {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            max-height: 600px;
            overflow-y: auto;
        }
        
        .modules-panel h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .module-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .module-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .module-item.selected {
            border-color: #667eea;
            background: #e7f3ff;
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
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .loop-builder h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        #loop-container {
            min-height: 300px;
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 15px;
            background: #f8f9fa;
        }
        
        #loop-container.empty {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 14px;
        }
        
        .loop-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: move;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        
        .loop-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .loop-item.dragging {
            opacity: 0.5;
        }
        
        .loop-order {
            background: rgba(255,255,255,0.2);
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            min-width: 40px;
            text-align: center;
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
        }
        
        .loop-duration {
            background: rgba(255,255,255,0.2);
            padding: 8px;
            border-radius: 5px;
            min-width: 100px;
        }
        
        .loop-duration input {
            width: 60px;
            padding: 5px;
            border: none;
            border-radius: 4px;
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
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 5px;
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
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .total-duration {
            background: #e7f3ff;
            padding: 12px 20px;
            border-radius: 8px;
            color: #0066cc;
            font-weight: bold;
            margin-left: auto;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
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
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: sticky;
            top: 20px;
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }
        
        .preview-panel h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        
        .preview-screen {
            flex: 1;
            background: #1a1a1a;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            min-height: 400px;
            aspect-ratio: 16 / 9;
            display: flex;
            align-items: center;
            justify-content: center;
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
            padding: 40px;
        }
        
        .preview-controls {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        
        .preview-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        
        .preview-btn-play {
            background: #28a745;
            color: white;
        }
        
        .preview-btn-play:hover {
            background: #218838;
        }
        
        .preview-btn-pause {
            background: #ffc107;
            color: #000;
        }
        
        .preview-btn-pause:hover {
            background: #e0a800;
        }
        
        .preview-btn-stop {
            background: #dc3545;
            color: white;
        }
        
        .preview-btn-stop:hover {
            background: #c82333;
        }
        
        .preview-progress {
            margin-top: 15px;
            background: #e9ecef;
            border-radius: 8px;
            overflow: hidden;
            height: 30px;
            position: relative;
        }
        
        .preview-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.1s linear;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        .preview-info {
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 13px;
        }
        
        .preview-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
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
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚙️ Loop Testreszabás</h1>
            <p>Csoport: <strong><?php echo htmlspecialchars($group['name']); ?></strong></p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div class="content">
            <!-- Available Modules Panel -->
            <div class="modules-panel">
                <h2>📦 Elérhető Modulok</h2>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Kattints a modulra a hozzáadáshoz</p>
                
                <?php foreach ($available_modules as $module): ?>
                    <div class="module-item" onclick="addModuleToLoop(<?php echo $module['id']; ?>, '<?php echo htmlspecialchars($module['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($module['description'] ?? '', ENT_QUOTES); ?>')">
                        <div class="module-name"><?php echo htmlspecialchars($module['name']); ?></div>
                        <div class="module-desc"><?php echo htmlspecialchars($module['description'] ?? ''); ?></div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($available_modules)): ?>
                    <p style="text-align: center; color: #999; padding: 20px;">Nincsenek elérhető modulok</p>
                <?php endif; ?>
            </div>
            
            <!-- Loop Builder -->
            <div class="loop-builder">
                <h2>🔄 Loop Konfiguráció</h2>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Húzd és ejtsd az elemeket a sorrend megváltoztatásához</p>
                
                <div id="loop-container" class="empty">
                    <p>Nincs elem a loop-ban. Kattints egy modulra a hozzáadáshoz.</p>
                </div>
                
                <div class="control-panel">
                    <button class="btn btn-success" onclick="saveLoop()">💾 Mentés</button>
                    <button class="btn btn-secondary" onclick="clearLoop()">🗑️ Összes törlése</button>
                    <a href="groups.php" class="btn btn-secondary">← Vissza</a>
                    <div class="total-duration" id="total-duration">Össz: 0 mp</div>
                </div>
            </div>
            
            <!-- Live Preview Panel -->
            <div class="preview-panel">
                <h2>📺 Élő Előnézet</h2>
                
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
    
    <script>
        let loopItems = [];
        const groupId = <?php echo $group_id; ?>;
        
        // Preview variables
        let previewInterval = null;
        let previewTimeout = null;
        let currentPreviewIndex = 0;
        let currentModuleStartTime = 0;
        let totalLoopStartTime = 0;
        let isPaused = false;
        let loopCycleCount = 0;
        
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
            fetch(`../api/group_loop_config.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loopItems = data.loops;
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
            loopItems.push({
                module_id: moduleId,
                module_name: moduleName,
                description: moduleDesc,
                duration_seconds: 10
            });
            renderLoop();
        }
        
        function removeFromLoop(index) {
            loopItems.splice(index, 1);
            renderLoop();
        }
        
        function updateDuration(index, value) {
            loopItems[index].duration_seconds = parseInt(value) || 10;
            updateTotalDuration();
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
            if (confirm('Biztosan törölni szeretnéd az összes elemet?')) {
                loopItems = [];
                renderLoop();
            }
        }
        
        function saveLoop() {
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
            const item = loopItems[index];
            const moduleKey = item.module_key || getModuleKeyById(item.module_id);
            
            // Initialize settings if not exists
            if (!item.settings) {
                item.settings = getDefaultSettings(moduleKey);
            }
            
            showCustomizationModal(item, index);
        }
        
        function getModuleKeyById(moduleId) {
            // Try to find module key from available modules
            const modules = <?php echo json_encode($available_modules); ?>;
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
            
            // Hide iframe, show empty message
            document.getElementById('previewIframe').style.display = 'none';
            document.getElementById('previewEmpty').style.display = 'block';
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
                    params.append('bgColor', '#667eea');
            }
            
            const queryString = params.toString();
            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        }
        
        // Update preview when loop changes
        function renderLoop() {
            const container = document.getElementById('loop-container');
            
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
                loopItem.draggable = true;
                loopItem.dataset.index = index;
                
                loopItem.innerHTML = `
                    <div class="loop-order">${index + 1}</div>
                    <div class="loop-details">
                        <div class="loop-module-name">${item.module_name}</div>
                        <div class="loop-module-desc">${item.description || ''}</div>
                    </div>
                    <div class="loop-duration">
                        <label>Időtartam</label>
                        <input type="number" 
                               value="${item.duration_seconds}" 
                               min="1" 
                               max="300" 
                               onchange="updateDuration(${index}, this.value)"
                               onclick="event.stopPropagation()">
                        <span style="font-size: 11px; opacity: 0.9;">sec</span>
                    </div>
                    <div class="loop-actions">
                        <button class="loop-btn" onclick="customizeModule(${index}); event.stopPropagation();" title="Testreszabás">⚙️</button>
                        <button class="loop-btn" onclick="removeFromLoop(${index}); event.stopPropagation();" title="Törlés">🗑️</button>
                    </div>
                `;
                
                // Drag and drop handlers
                loopItem.addEventListener('dragstart', handleDragStart);
                loopItem.addEventListener('dragover', handleDragOver);
                loopItem.addEventListener('drop', handleDrop);
                loopItem.addEventListener('dragend', handleDragEnd);
                
                container.appendChild(loopItem);
            });
            
            updateTotalDuration();
        }
        
        // Load loop on page load
        loadLoop();
    </script>
</body>
</html>
