<?php
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../modules/module_asset_service.php';

$asset_file_debug = static function (string $message, array $context = []): void {
    $pairs = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $pairs[] = $key . '=' . var_export($value, true);
        } else {
            $pairs[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    error_log('module_asset_file debug: ' . $message . ($pairs ? ' | ' . implode(' ', $pairs) : ''));
};

$api_company = validate_api_token();

$asset_id = (int)($_GET['asset_id'] ?? 0);
$path_input = trim((string)($_GET['path'] ?? ''));
$normalized_path = edudisplej_module_asset_extract_rel_path($path_input);

if ($asset_id <= 0 && $normalized_path === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Missing asset_id or path']);
    exit;
}

try {
    $conn = getDbConnection();
    edudisplej_module_asset_ensure_schema($conn);

    $api_company_id = (int)($api_company['id'] ?? 0);
    $api_is_admin = !empty($api_company['is_admin']);
    $session_user_id = (int)($_SESSION['user_id'] ?? 0);

    if ($api_company_id <= 0 && $session_user_id > 0) {
        $company_stmt = $conn->prepare('SELECT company_id FROM users WHERE id = ? LIMIT 1');
        if ($company_stmt) {
            $company_stmt->bind_param('i', $session_user_id);
            $company_stmt->execute();
            $company_row = $company_stmt->get_result()->fetch_assoc();
            $company_stmt->close();

            $resolved_company_id = (int)($company_row['company_id'] ?? 0);
            if ($resolved_company_id > 0) {
                $api_company['id'] = $resolved_company_id;
                $api_company_id = $resolved_company_id;
            }
        }
    }

    if ($asset_id > 0) {
        $stmt = $conn->prepare("SELECT id, company_id, mime_type, original_name, storage_rel_path, is_active FROM module_asset_store WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $asset_id);
    } else {
        $stmt = $conn->prepare("SELECT id, company_id, mime_type, original_name, storage_rel_path, is_active FROM module_asset_store WHERE storage_rel_path = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('s', $normalized_path);
    }

    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$asset || (int)($asset['is_active'] ?? 0) !== 1) {
        $conn->close();
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Asset not found']);
        exit;
    }

    api_require_company_match($api_company, (int)$asset['company_id'], 'Unauthorized');

    $storage_rel_path = edudisplej_module_asset_extract_rel_path((string)$asset['storage_rel_path']);
    if ($storage_rel_path === '') {
        $conn->close();
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Invalid asset path']);
        exit;
    }

    $root_abs = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
    $file_abs = rtrim(str_replace('\\', '/', (string)$root_abs), '/') . '/' . $storage_rel_path;
    $resolved_file = realpath($file_abs);

    $uploads_root_abs = realpath(rtrim(str_replace('\\', '/', (string)$root_abs), '/') . '/uploads/companies')
        ?: (rtrim(str_replace('\\', '/', (string)$root_abs), '/') . '/uploads/companies');
    $uploads_root = rtrim(str_replace('\\', '/', (string)$uploads_root_abs), '/') . '/';
    $resolved_file_normalized = str_replace('\\', '/', (string)$resolved_file);
    $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $path_in_upload_root = $is_windows
        ? (strpos(strtolower($resolved_file_normalized), strtolower($uploads_root)) === 0)
        : (strpos($resolved_file_normalized, $uploads_root) === 0);

    if ($resolved_file === false || !$path_in_upload_root || !is_file($resolved_file)) {
        $asset_file_debug('asset file validation failed', [
            'asset_id' => (int)($asset['id'] ?? 0),
            'storage_rel_path' => $storage_rel_path,
            'resolved_file' => $resolved_file_normalized,
            'uploads_root' => $uploads_root,
            'path_in_upload_root' => $path_in_upload_root,
            'is_file' => $resolved_file !== false ? is_file($resolved_file) : false,
        ]);
        $conn->close();
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Asset file missing']);
        exit;
    }

    $mime_type = trim((string)($asset['mime_type'] ?? ''));
    if ($mime_type === '' || strtolower($mime_type) === 'application/octet-stream') {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = (string)finfo_file($finfo, $resolved_file);
                finfo_close($finfo);
                if ($detected !== '') {
                    $mime_type = $detected;
                }
            }
        }

        if ($mime_type === '' || strtolower($mime_type) === 'application/octet-stream') {
            $ext = strtolower(pathinfo($resolved_file, PATHINFO_EXTENSION));
            $ext_map = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
            ];
            $mime_type = $ext_map[$ext] ?? 'application/octet-stream';
        }
    }

    $original_name = trim((string)($asset['original_name'] ?? basename($resolved_file)));
    if ($original_name === '') {
        $original_name = basename($resolved_file);
    }

    $conn->close();

    if (function_exists('session_write_close')) {
        session_write_close();
    }

    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    clearstatcache(true, $resolved_file);
    $file_size = filesize($resolved_file);

    $asset_file_debug('streaming asset response', [
        'asset_id' => (int)($asset['id'] ?? 0),
        'company_id' => (int)($asset['company_id'] ?? 0),
        'mime_type' => $mime_type,
        'file_size' => $file_size,
        'resolved_file' => $resolved_file,
    ]);

    header('Content-Type: ' . $mime_type);
    if ($file_size !== false && $file_size > 0) {
        header('Content-Length: ' . (string)$file_size);
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $original_name) . '"');

    $bytes_sent = readfile($resolved_file);
    if ($bytes_sent === false) {
        $asset_file_debug('readfile failed', [
            'asset_id' => (int)($asset['id'] ?? 0),
            'resolved_file' => $resolved_file,
        ]);
    }
    exit;
} catch (Throwable $e) {
    error_log('module_asset_file error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
