<?php
session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    header('Location: index.php');
    exit();
}

function meal_maintenance_ensure_settings_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value VARCHAR(2000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        'jedalen_sync_enabled' => '1',
        'jedalen_sync_window_start' => '0',
        'jedalen_sync_window_end' => '5',
        'jedalen_sync_regions' => 'TT,NR,TN,BB,PO,KE,BA,ZA',
        'jedalen_sync_every_cycle' => '0',
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO maintenance_settings (setting_key, setting_value) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }

    foreach ($defaults as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}

function meal_maintenance_load_settings(mysqli $conn): array {
    $settings = [
        'jedalen_sync_enabled' => '1',
        'jedalen_sync_window_start' => '0',
        'jedalen_sync_window_end' => '5',
        'jedalen_sync_regions' => 'TT,NR,TN,BB,PO,KE,BA,ZA',
        'jedalen_sync_every_cycle' => '0',
    ];

    $result = $conn->query("SELECT setting_key, setting_value FROM maintenance_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = (string)($row['setting_key'] ?? '');
            if ($key === '' || !array_key_exists($key, $settings)) {
                continue;
            }
            $settings[$key] = (string)($row['setting_value'] ?? '');
        }
    }

    return $settings;
}

function meal_maintenance_save_settings(mysqli $conn, array $input): void {
    $enabled = !empty($input['jedalen_sync_enabled']) ? '1' : '0';
    $windowStart = max(0, min(23, (int)($input['jedalen_sync_window_start'] ?? 0)));
    $windowEnd = max(0, min(23, (int)($input['jedalen_sync_window_end'] ?? 5)));

    $regionsRaw = strtoupper((string)($input['jedalen_sync_regions'] ?? 'TT,NR,TN,BB,PO,KE,BA,ZA'));
    $regionsClean = [];
    foreach (explode(',', $regionsRaw) as $region) {
        $normalized = trim($region);
        if ($normalized === '' || !preg_match('/^[A-Z]{2}$/', $normalized)) {
            continue;
        }
        $regionsClean[$normalized] = true;
    }
    if (empty($regionsClean)) {
        $regionsClean = ['TT' => true, 'NR' => true, 'TN' => true, 'BB' => true, 'PO' => true, 'KE' => true, 'BA' => true, 'ZA' => true];
    }

    $settings = [
        'jedalen_sync_enabled' => $enabled,
        'jedalen_sync_window_start' => (string)$windowStart,
        'jedalen_sync_window_end' => (string)$windowEnd,
        'jedalen_sync_regions' => implode(',', array_keys($regionsClean)),
        'jedalen_sync_every_cycle' => !empty($input['jedalen_sync_every_cycle']) ? '1' : '0',
    ];

    $stmt = $conn->prepare("INSERT INTO maintenance_settings (setting_key, setting_value)
                           VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        throw new RuntimeException('Settings save prepare failed');
    }

    foreach ($settings as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}

function meal_maintenance_is_shell_callable(string $functionName): bool {
    if (!function_exists($functionName)) {
        return false;
    }

    $disabled = strtolower((string)ini_get('disable_functions'));
    if ($disabled === '') {
        return true;
    }

    $items = array_map('trim', explode(',', $disabled));
    return !in_array(strtolower($functionName), $items, true);
}

function meal_maintenance_probe_php_binary(string $binaryPath): ?string {
    $binaryPath = trim($binaryPath);
    if ($binaryPath === '') {
        return null;
    }

    $basename = strtolower((string)pathinfo($binaryPath, PATHINFO_BASENAME));
    if (strpos($basename, 'fpm') !== false || strpos($basename, 'cgi') !== false) {
        return null;
    }

    if (!meal_maintenance_is_shell_callable('shell_exec')) {
        return null;
    }

    $probeCmd = escapeshellarg($binaryPath) . " -r " . escapeshellarg("echo PHP_SAPI . ':' . PHP_VERSION . ':mysqli=' . ((extension_loaded('mysqli') && class_exists('mysqli')) ? '1' : '0');") . " 2>/dev/null";
    $probeOut = trim((string)@shell_exec($probeCmd));
    if ($probeOut === '') {
        return null;
    }

    if (stripos($probeOut, 'cli:') !== 0) {
        return null;
    }

    if (stripos($probeOut, ':mysqli=1') === false) {
        return null;
    }

    return $binaryPath;
}

function meal_maintenance_find_cli_php(): ?string {
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }

    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/local/bin/php82', '/usr/local/bin/php81', '/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php81'] as $path) {
        $candidates[] = $path;
    }

    if (meal_maintenance_is_shell_callable('shell_exec')) {
        $whichOut = trim((string)@shell_exec("command -v php 2>/dev/null; command -v php8 2>/dev/null; command -v php82 2>/dev/null; command -v php81 2>/dev/null; command -v php-cli 2>/dev/null"));
        if ($whichOut !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $whichOut) as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $candidates[] = $line;
                }
            }
        }
    }

    $unique = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        if (!isset($unique[$candidate])) {
            $unique[$candidate] = true;
        }
    }

    foreach (array_keys($unique) as $candidate) {
        $ok = meal_maintenance_probe_php_binary($candidate);
        if ($ok !== null) {
            return $ok;
        }
    }

    return null;
}

function meal_maintenance_exec_capture(string $command): array {
    $output = '';
    $exitCode = null;

    if (meal_maintenance_is_shell_callable('exec')) {
        $lines = [];
        $code = 1;
        @exec($command . ' 2>&1', $lines, $code);
        $output = trim(implode("\n", $lines));
        $exitCode = (int)$code;
        return ['output' => $output, 'exit_code' => $exitCode];
    }

    if (meal_maintenance_is_shell_callable('shell_exec')) {
        $raw = @shell_exec($command . ' 2>&1');
        if ($raw === null) {
            return ['output' => '', 'exit_code' => null];
        }
        $output = trim((string)$raw);
        return ['output' => $output, 'exit_code' => null];
    }

    return ['output' => '', 'exit_code' => null];
}

function meal_maintenance_run_via_http_fallback(string $runScript, array $queryParams = []): array {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return ['success' => false, 'output' => 'HTTP fallback sikertelen: hiányzó HTTP_HOST.', 'url' => ''];
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminDir = trim((string)dirname($scriptName), '/.');
    $basePath = $adminDir !== '' && strtolower(substr($adminDir, -5)) === '/admin'
        ? substr($adminDir, 0, -6)
        : '';

    $relativeRunPath = str_replace('\\', '/', str_replace(realpath(__DIR__ . '/..') ?: '', '', $runScript));
    $relativeRunPath = ltrim($relativeRunPath, '/');
    if ($relativeRunPath === '') {
        $relativeRunPath = 'cron/maintenance/run_maintenance.php';
    }

    $params = array_merge([
        'force_jedalen_sync' => '1',
        'only_jedalen_sync' => '1',
    ], $queryParams);
    $queryString = http_build_query($params);

    $url = $scheme . '://' . $host . ($basePath ? '/' . trim($basePath, '/') : '') . '/' . $relativeRunPath
        . '?' . $queryString;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 180,
            'ignore_errors' => true,
            'header' => "Connection: close\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['success' => false, 'output' => 'HTTP fallback lekérés sikertelen (file_get_contents).', 'url' => $url];
    }

    $statusLine = '';
    if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
        $statusLine = (string)$http_response_header[0];
    }

    $statusOk = $statusLine === '' || stripos($statusLine, ' 200 ') !== false;
    $out = trim((string)$response);
    if ($statusLine !== '') {
        $out = "HTTP: {$statusLine}\n" . $out;
    }

    return ['success' => $statusOk, 'output' => $out, 'url' => $url];
}

function meal_maintenance_run_manual_sync(string $runScript, array $cliArgs, array $httpParams): array {
    $phpCli = meal_maintenance_find_cli_php();
    if ($phpCli !== null) {
        $command = escapeshellarg($phpCli) . ' ' . escapeshellarg($runScript);
        foreach ($cliArgs as $arg) {
            $command .= ' ' . $arg;
        }

        $result = meal_maintenance_exec_capture($command);
        $output = trim((string)($result['output'] ?? ''));
        $exitCode = $result['exit_code'];
        if ($exitCode !== null && (int)$exitCode !== 0) {
            throw new RuntimeException('Kézi futtatás hibakóddal tért vissza (exit: ' . (int)$exitCode . ').');
        }
        if ($output === '') {
            $output = 'Kézi futás lefutott, de nem érkezett szöveges kimenet.';
        }

        return [
            'runner_info' => 'CLI futtató: ' . $phpCli,
            'output' => $output,
            'fallback' => false,
        ];
    }

    $fallback = meal_maintenance_run_via_http_fallback($runScript, $httpParams);
    $output = trim((string)($fallback['output'] ?? ''));
    if (!$fallback['success']) {
        throw new RuntimeException(
            'Kézi futtatás sikertelen: nem található CLI PHP futtató, és a HTTP fallback is hibázott.'
            . (!empty($fallback['url']) ? ' URL: ' . $fallback['url'] : '')
        );
    }

    $runnerInfo = 'Fallback futtató: HTTP self-call';
    if (!empty($fallback['url'])) {
        $runnerInfo .= ' (' . $fallback['url'] . ')';
    }
    if ($output === '') {
        $output = 'Kézi futás lefutott, de nem érkezett szöveges kimenet.';
    }

    return [
        'runner_info' => $runnerInfo,
        'output' => $output,
        'fallback' => true,
    ];
}

$error = '';
$success = '';
$manualOutput = '';
$manualRunnerInfo = '';
$settings = [];
$lastJob = null;
$browseRows = [];
$futureRows = [];
$manualInstitutionRows = [];
$selectedManualInstitutionIds = [];
$browseFilters = [
    'from_date' => date('Y-m-d', strtotime('-30 days')),
    'to_date' => date('Y-m-d'),
    'source_type' => '',
    'institution_q' => '',
];

try {
    $conn = getDbConnection();
    meal_maintenance_ensure_settings_table($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_jedalen_settings'])) {
        meal_maintenance_save_settings($conn, $_POST);
        $success = 'Jedalen szinkron beállítások mentve.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['run_jedalen_now']) || isset($_POST['run_jedalen_institutions_only']) || isset($_POST['run_jedalen_menus_for_selected']))) {
        $runScript = realpath(dirname(__DIR__) . '/cron/maintenance/run_maintenance.php');
        if (!$runScript || !is_file($runScript)) {
            throw new RuntimeException('run_maintenance.php nem található.');
        }

        $cliArgs = ['--force-jedalen-sync', '--only-jedalen-sync'];
        $httpParams = [
            'force_jedalen_sync' => '1',
            'only_jedalen_sync' => '1',
        ];
        $successBase = 'Kézi Jedalen lekérdezés lefutott.';

        if (isset($_POST['run_jedalen_institutions_only'])) {
            $cliArgs[] = '--jedalen-fetch-institutions-only';
            $httpParams['jedalen_fetch_institutions_only'] = '1';
            $successBase = 'Kézi Jedalen intézménylista-lekérdezés lefutott.';
        }

        if (isset($_POST['run_jedalen_menus_for_selected'])) {
            $cliArgs[] = '--jedalen-fetch-menus-only';
            $httpParams['jedalen_fetch_menus_only'] = '1';

            $selectedIdsRaw = $_POST['selected_institution_ids'] ?? [];
            $selectedIds = [];
            if (is_array($selectedIdsRaw)) {
                foreach ($selectedIdsRaw as $value) {
                    $id = (int)$value;
                    if ($id > 0) {
                        $selectedIds[$id] = true;
                    }
                }
            }
            $selectedManualInstitutionIds = array_values(array_map('intval', array_keys($selectedIds)));
            if (empty($selectedManualInstitutionIds)) {
                throw new RuntimeException('Menü lekéréshez válassz ki legalább 1 intézményt.');
            }

            $idsCsv = implode(',', $selectedManualInstitutionIds);
            $cliArgs[] = '--jedalen-institution-ids=' . escapeshellarg($idsCsv);
            $httpParams['jedalen_institution_ids'] = $idsCsv;
            $successBase = 'Kézi Jedalen étrend-lekérdezés lefutott a kiválasztott intézményekre.';
        }

        $manualRun = meal_maintenance_run_manual_sync($runScript, $cliArgs, $httpParams);
        $manualRunnerInfo = (string)($manualRun['runner_info'] ?? '');
        $manualOutput = (string)($manualRun['output'] ?? '');
        $success = !empty($manualRun['fallback']) ? ($successBase . ' (HTTP fallback).') : $successBase;
    }

    $settings = meal_maintenance_load_settings($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_job_runs (
        job_key VARCHAR(120) PRIMARY KEY,
        last_run_at DATETIME NULL,
        last_status VARCHAR(20) NOT NULL DEFAULT 'idle',
        last_message VARCHAR(1000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $jobStmt = $conn->prepare("SELECT job_key, last_run_at, last_status, last_message, updated_at
                              FROM maintenance_job_runs
                              WHERE job_key = 'jedalen_daily_sync'
                              LIMIT 1");
    $jobStmt->execute();
    $lastJob = $jobStmt->get_result()->fetch_assoc();
    $jobStmt->close();

    $manualInstitutionsStmt = $conn->prepare("SELECT mi.id, mi.institution_name, mi.city, mi.external_key
                                              FROM meal_plan_institutions mi
                                              INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
                                              WHERE ms.site_key = 'jedalen.sk'
                                                AND mi.company_id = 0
                                              ORDER BY mi.institution_name ASC");
    if ($manualInstitutionsStmt) {
        $manualInstitutionsStmt->execute();
        $manualInstitutionsRes = $manualInstitutionsStmt->get_result();
        while ($row = $manualInstitutionsRes->fetch_assoc()) {
            $manualInstitutionRows[] = $row;
        }
        $manualInstitutionsStmt->close();
    }

    $browseFilters['from_date'] = trim((string)($_GET['from_date'] ?? $browseFilters['from_date']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $browseFilters['from_date'])) {
        $browseFilters['from_date'] = date('Y-m-d', strtotime('-30 days'));
    }

    $browseFilters['to_date'] = trim((string)($_GET['to_date'] ?? $browseFilters['to_date']));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $browseFilters['to_date'])) {
        $browseFilters['to_date'] = date('Y-m-d');
    }

    $sourceTypeRaw = strtolower(trim((string)($_GET['source_type'] ?? '')));
    $browseFilters['source_type'] = in_array($sourceTypeRaw, ['manual', 'server', 'auto_jedalen'], true) ? $sourceTypeRaw : '';
    $browseFilters['institution_q'] = trim((string)($_GET['institution_q'] ?? ''));

    $institutionLike = $browseFilters['institution_q'];
    $sourceType = $browseFilters['source_type'];

    $browseStmt = $conn->prepare("SELECT
            mpi.menu_date,
            mpi.source_type,
            mpi.updated_at,
            mi.institution_name,
            mi.city,
            ms.site_key,
            c.name AS company_name,
            mpi.breakfast,
            mpi.snack_am,
            mpi.lunch,
            mpi.snack_pm,
            mpi.dinner
        FROM meal_plan_items mpi
        LEFT JOIN meal_plan_institutions mi ON mi.id = mpi.institution_id
        LEFT JOIN meal_plan_sites ms ON ms.id = mi.site_id
        LEFT JOIN companies c ON c.id = mpi.company_id
        WHERE mpi.menu_date BETWEEN ? AND ?
          AND (? = '' OR mpi.source_type = ?)
          AND (? = '' OR LOWER(mi.institution_name) LIKE CONCAT('%', LOWER(?), '%'))
        ORDER BY mpi.menu_date DESC, mpi.updated_at DESC
        LIMIT 250");
    $browseStmt->bind_param(
        'ssssss',
        $browseFilters['from_date'],
        $browseFilters['to_date'],
        $sourceType,
        $sourceType,
        $institutionLike,
        $institutionLike
    );
    $browseStmt->execute();
    $browseResult = $browseStmt->get_result();
    while ($row = $browseResult->fetch_assoc()) {
        $browseRows[] = $row;
    }
    $browseStmt->close();

    $futureStmt = $conn->prepare("SELECT
            mpi.menu_date,
            mpi.source_type,
            mpi.updated_at,
            mi.institution_name,
            mi.city,
            ms.site_key,
            c.name AS company_name,
            mpi.breakfast,
            mpi.snack_am,
            mpi.lunch,
            mpi.snack_pm,
            mpi.dinner
        FROM meal_plan_items mpi
        LEFT JOIN meal_plan_institutions mi ON mi.id = mpi.institution_id
        LEFT JOIN meal_plan_sites ms ON ms.id = mi.site_id
        LEFT JOIN companies c ON c.id = mpi.company_id
        WHERE mpi.menu_date >= CURDATE()
        ORDER BY mpi.menu_date ASC, mi.institution_name ASC, mpi.updated_at DESC");
    if ($futureStmt) {
        $futureStmt->execute();
        $futureResult = $futureStmt->get_result();
        while ($row = $futureResult->fetch_assoc()) {
            $futureRows[] = $row;
        }
        $futureStmt->close();
    }

    closeDbConnection($conn);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        closeDbConnection($conn);
    }
    $error = 'Hiba: ' . $e->getMessage();
    error_log('admin/meal_menu_maintenance.php: ' . $e->getMessage());
}

$breadcrumb_items = [
    ['label' => '🍽️ Étrend maintenance', 'current' => true],
];
$logout_url = '../login.php?logout=1';
include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Étrend modul – technikai vezérlés</div>
    <div class="muted">Itt állítható a Jedalen szinkron, és indítható kézi adatlekérés.</div>
</div>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Jedalen automatikus szinkron beállítások</div>
    <form method="post" style="display:grid; gap:10px; max-width:680px;">
        <label><input type="checkbox" name="jedalen_sync_enabled" value="1" <?php echo (!empty($settings['jedalen_sync_enabled']) && (string)$settings['jedalen_sync_enabled'] === '1') ? 'checked' : ''; ?>> Szinkron engedélyezve</label>
        <label><input type="checkbox" name="jedalen_sync_every_cycle" value="1" <?php echo (!empty($settings['jedalen_sync_every_cycle']) && (string)$settings['jedalen_sync_every_cycle'] === '1') ? 'checked' : ''; ?>> Minden maintenance ciklusban fusson (ha nincs bepipálva: napi 1x, updated_at alapján)</label>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <div>
                <label style="display:block; margin-bottom:4px;">Futtatási ablak kezdő óra (0-23)</label>
                <input type="number" name="jedalen_sync_window_start" min="0" max="23" value="<?php echo htmlspecialchars((string)($settings['jedalen_sync_window_start'] ?? '0')); ?>">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px;">Futtatási ablak záró óra (0-23)</label>
                <input type="number" name="jedalen_sync_window_end" min="0" max="23" value="<?php echo htmlspecialchars((string)($settings['jedalen_sync_window_end'] ?? '5')); ?>">
            </div>
        </div>

        <div>
            <label style="display:block; margin-bottom:4px;">Régiók (vesszővel, pl.: TT,NR,TN,BB,PO,KE,BA,ZA)</label>
            <input type="text" name="jedalen_sync_regions" value="<?php echo htmlspecialchars((string)($settings['jedalen_sync_regions'] ?? 'TT,NR,TN,BB,PO,KE,BA,ZA')); ?>">
        </div>

        <div>
            <button type="submit" name="save_jedalen_settings" class="btn btn-primary">Beállítások mentése</button>
        </div>
    </form>
</div>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Kézi lekérdezés</div>
    <div class="muted" style="margin-bottom:8px;">A Jedalen kézi lekérés két külön lépésre bontva futtatható. A menülekérdezés több hétre is próbálkozik (aktuális + következő hetek), ha az aktuális hét üres.</div>
    <div class="muted" style="margin-bottom:8px;">Technikai megjegyzés: a rendszer HTTPS-en kéri le az EatMenu oldalakat, mert HTTP alatt több intézménynél üres/noeat tartalom érkezik.</div>

    <form method="post" style="margin-bottom:10px;">
        <button type="submit" name="run_jedalen_institutions_only" class="btn btn-primary">Jedalen intézménylista letöltése most</button>
    </form>

    <form method="post" style="display:grid; gap:8px;">
        <label style="display:block; margin-bottom:2px;"><strong>Intézmények kiválasztása menü letöltéshez</strong></label>
        <?php if (empty($manualInstitutionRows)): ?>
            <div class="muted">Nincs betöltött Jedalen intézménylista. Előbb futtasd az intézménylista letöltést.</div>
        <?php else: ?>
            <select name="selected_institution_ids[]" multiple size="10" style="min-height:220px;">
                <?php foreach ($manualInstitutionRows as $instRow): ?>
                    <?php
                        $instId = (int)($instRow['id'] ?? 0);
                        $instName = trim((string)($instRow['institution_name'] ?? ''));
                        $instCity = trim((string)($instRow['city'] ?? ''));
                        $instExt = trim((string)($instRow['external_key'] ?? ''));
                        $instLabel = $instName;
                        if ($instCity !== '') {
                            $instLabel .= ' (' . $instCity . ')';
                        }
                        if ($instExt !== '') {
                            $instLabel .= ' [' . $instExt . ']';
                        }
                    ?>
                    <option value="<?php echo $instId; ?>" <?php echo in_array($instId, $selectedManualInstitutionIds, true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($instLabel); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="muted">Tipp: Ctrl/Shift billentyűvel több intézmény is választható.</div>
        <?php endif; ?>

        <div>
            <button type="submit" name="run_jedalen_menus_for_selected" class="btn btn-primary" <?php echo empty($manualInstitutionRows) ? 'disabled' : ''; ?>>Etrend letöltése a kiválasztott intézményekre</button>
        </div>
    </form>
</div>

<?php if ($manualOutput !== ''): ?>
    <div class="panel" style="margin-bottom:12px;">
        <div class="panel-title">Kézi futás kimenete</div>
        <?php if ($manualRunnerInfo !== ''): ?>
            <div class="muted" style="margin-bottom:8px;"><?php echo htmlspecialchars($manualRunnerInfo); ?></div>
        <?php endif; ?>
        <?php if (stripos($manualOutput, 'no menu rows parsed') !== false): ?>
            <div class="alert error" style="margin-bottom:8px;">A kiválasztott intézménynél a Jedalen oldalon nem található publikált menü a bejárt heteken sem. Ellenőrizd az intézmény EatMenu oldalát, vagy válassz másik intézményt.</div>
        <?php endif; ?>
        <pre style="white-space:pre-wrap; max-height:340px; overflow:auto;"><?php echo htmlspecialchars($manualOutput); ?></pre>
    </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Utolsó Jedalen futás állapota</div>
    <?php if ($lastJob): ?>
        <div><strong>Státusz:</strong> <?php echo htmlspecialchars((string)($lastJob['last_status'] ?? '')); ?></div>
        <div><strong>Utoljára futott:</strong> <?php echo htmlspecialchars((string)($lastJob['last_run_at'] ?? '')); ?></div>
        <div><strong>Üzenet:</strong> <?php echo htmlspecialchars((string)($lastJob['last_message'] ?? '')); ?></div>
        <div class="muted" style="margin-top:6px;">Frissítve: <?php echo htmlspecialchars((string)($lastJob['updated_at'] ?? '')); ?></div>
    <?php else: ?>
        <div class="muted">Még nincs futási rekord.</div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Lekérdezések manuális böngészése</div>
    <form method="get" style="display:grid; gap:10px; margin-bottom:10px;">
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1.2fr auto; gap:8px; align-items:end;">
            <div>
                <label style="display:block; margin-bottom:4px;">Dátumtól</label>
                <input type="date" name="from_date" value="<?php echo htmlspecialchars($browseFilters['from_date']); ?>">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px;">Dátumig</label>
                <input type="date" name="to_date" value="<?php echo htmlspecialchars($browseFilters['to_date']); ?>">
            </div>
            <div>
                <label style="display:block; margin-bottom:4px;">Forrás</label>
                <select name="source_type">
                    <option value="" <?php echo $browseFilters['source_type'] === '' ? 'selected' : ''; ?>>Mind</option>
                    <option value="manual" <?php echo $browseFilters['source_type'] === 'manual' ? 'selected' : ''; ?>>manual</option>
                    <option value="server" <?php echo $browseFilters['source_type'] === 'server' ? 'selected' : ''; ?>>server</option>
                    <option value="auto_jedalen" <?php echo $browseFilters['source_type'] === 'auto_jedalen' ? 'selected' : ''; ?>>auto_jedalen (legacy)</option>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:4px;">Intézmény név</label>
                <input type="text" name="institution_q" value="<?php echo htmlspecialchars($browseFilters['institution_q']); ?>" placeholder="pl. Jedáleň ...">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Szűrés</button>
            </div>
        </div>
    </form>

    <?php if (empty($browseRows)): ?>
        <div class="muted">Nincs találat a megadott szűrőkre.</div>
    <?php else: ?>
        <div style="overflow:auto; max-height:420px; border:1px solid #e5e7eb; border-radius:6px;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Dátum</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Forrás</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Intézmény</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Oldal</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Cég</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Tartalom</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Frissítve</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($browseRows as $row): ?>
                        <?php
                            $mealParts = [];
                            foreach (['breakfast' => 'R', 'snack_am' => 'T', 'lunch' => 'E', 'snack_pm' => 'U', 'dinner' => 'V'] as $mealKey => $mealShort) {
                                $mealText = trim((string)($row[$mealKey] ?? ''));
                                if ($mealText === '') {
                                    continue;
                                }
                                $mealText = preg_replace('/\s+/u', ' ', $mealText);
                                if (mb_strlen($mealText, 'UTF-8') > 80) {
                                    $mealText = mb_substr($mealText, 0, 80, 'UTF-8') . '…';
                                }
                                $mealParts[] = $mealShort . ': ' . $mealText;
                            }
                            $institutionLabel = trim((string)($row['institution_name'] ?? ''));
                            $city = trim((string)($row['city'] ?? ''));
                            if ($city !== '') {
                                $institutionLabel .= ' (' . $city . ')';
                            }
                        ?>
                        <tr>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['menu_date'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['source_type'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($institutionLabel); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['site_key'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['company_name'] ?? 'Global')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars(implode(' | ', $mealParts)); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['updated_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="muted" style="margin-top:6px;">Maximum 250 rekord jelenik meg.</div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title">Tárolt menünapok (ma és jövő)</div>

    <?php if (empty($futureRows)): ?>
        <div class="muted">Nincs tárolt menü ma vagy jövőbeli napra.</div>
    <?php else: ?>
        <div style="overflow:auto; max-height:460px; border:1px solid #e5e7eb; border-radius:6px;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Dátum</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Forrás</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Intézmény</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Oldal</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Cég</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Tartalom</th>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Frissítve</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($futureRows as $row): ?>
                        <?php
                            $mealParts = [];
                            foreach (['breakfast' => 'R', 'snack_am' => 'T', 'lunch' => 'E', 'snack_pm' => 'U', 'dinner' => 'V'] as $mealKey => $mealShort) {
                                $mealText = trim((string)($row[$mealKey] ?? ''));
                                if ($mealText === '') {
                                    continue;
                                }
                                $mealText = preg_replace('/\s+/u', ' ', $mealText);
                                if (mb_strlen($mealText, 'UTF-8') > 80) {
                                    $mealText = mb_substr($mealText, 0, 80, 'UTF-8') . '…';
                                }
                                $mealParts[] = $mealShort . ': ' . $mealText;
                            }
                            $institutionLabel = trim((string)($row['institution_name'] ?? ''));
                            $city = trim((string)($row['city'] ?? ''));
                            if ($city !== '') {
                                $institutionLabel .= ' (' . $city . ')';
                            }
                        ?>
                        <tr>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['menu_date'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['source_type'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars($institutionLabel); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['site_key'] ?? '')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['company_name'] ?? 'Global')); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars(implode(' | ', $mealParts)); ?></td>
                            <td style="padding:8px; border-bottom:1px solid #f1f5f9;"><?php echo htmlspecialchars((string)($row['updated_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="muted" style="margin-top:6px;">Összesen <?php echo count($futureRows); ?> rekord.</div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
