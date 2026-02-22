<?php
session_start();
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../../auth_roles.php';
require_once __DIR__ . '/../../modules/module_asset_service.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (!edudisplej_can_edit_module_content()) {
    echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
    exit();
}

$module_key = strtolower(trim((string)($_GET['module_key'] ?? 'image-gallery')));
$asset_kind = strtolower(trim((string)($_GET['asset_kind'] ?? 'image')));
$limit = (int)($_GET['limit'] ?? 100);
if ($limit <= 0) {
    $limit = 100;
}
$limit = min($limit, 300);

$company_id = (int)($_SESSION['company_id'] ?? 0);
$is_admin = !empty($_SESSION['isadmin']);
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);
    edudisplej_module_asset_ensure_schema($conn);

    if ($is_admin && $company_id <= 0 && $user_id > 0) {
        $company_stmt = $conn->prepare('SELECT company_id FROM users WHERE id = ? LIMIT 1');
        $company_stmt->bind_param('i', $user_id);
        $company_stmt->execute();
        $company_row = $company_stmt->get_result()->fetch_assoc();
        $company_stmt->close();
        $company_id = (int)($company_row['company_id'] ?? 0);
    }

    if ($company_id <= 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Hiányzó company context']);
        exit();
    }

    if ($module_key === 'image-gallery' || $module_key === 'gallery') {
        $stmt = $conn->prepare(
            'SELECT id, original_name, mime_type, file_size, created_at, group_id
             FROM module_asset_store
             WHERE company_id = ? AND module_key IN ("image-gallery", "gallery") AND asset_kind = ? AND is_active = 1
             ORDER BY created_at DESC, id DESC
             LIMIT ?'
        );
        $stmt->bind_param('isi', $company_id, $asset_kind, $limit);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, original_name, mime_type, file_size, created_at, group_id
             FROM module_asset_store
             WHERE company_id = ? AND module_key = ? AND asset_kind = ? AND is_active = 1
             ORDER BY created_at DESC, id DESC
             LIMIT ?'
        );
        $stmt->bind_param('issi', $company_id, $module_key, $asset_kind, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $assets = [];
    while ($row = $result->fetch_assoc()) {
        $asset_id = (int)($row['id'] ?? 0);
        if ($asset_id <= 0) {
            continue;
        }

        $assets[] = [
            'asset_id' => $asset_id,
            'asset_url' => edudisplej_module_asset_api_url_by_id($asset_id),
            'original_name' => (string)($row['original_name'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'group_id' => (int)($row['group_id'] ?? 0),
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'assets' => $assets,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('module_asset_library error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Szerver hiba']);
}
