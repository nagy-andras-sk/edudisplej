<?php
require_once '../../dbkonfiguracia.php';
require_once '../auth.php';
require_once '../../modules/module_asset_service.php';

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
    if ($resolved_file === false || strpos($resolved_file_normalized, $uploads_root) !== 0 || !is_file($resolved_file)) {
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

    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . (string)filesize($resolved_file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $original_name) . '"');

    readfile($resolved_file);
    exit;
} catch (Throwable $e) {
    error_log('module_asset_file error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
