<?php
/**
 * API - Text Collections CRUD + linked loop refresh
 */
session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !edudisplej_can_edit_module_content()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
    exit();
}

function edudisplej_text_collections_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS text_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        content_html LONGTEXT NOT NULL,
        external_source_json LONGTEXT NULL,
        bg_color VARCHAR(7) NOT NULL DEFAULT '#000000',
        bg_image_data LONGTEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_active (company_id, is_active),
        INDEX idx_company_updated (company_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columnCheck = $conn->query("SHOW COLUMNS FROM text_collections LIKE 'external_source_json'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE text_collections ADD COLUMN external_source_json LONGTEXT NULL AFTER content_html");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_loop_plans (
        group_id INT PRIMARY KEY,
        plan_json LONGTEXT NOT NULL,
        plan_version BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_text_collections_external_source_default(): array {
    return [
        'format_version' => 'v1',
        'source_name' => '',
        'headline' => '',
        'body' => '',
        'note' => '',
        'published_at' => '',
    ];
}

function edudisplej_text_collections_external_source_sanitize($raw): array {
    $defaults = edudisplej_text_collections_external_source_default();
    $input = [];

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    } elseif (is_array($raw)) {
        $input = $raw;
    }

    $merged = array_merge($defaults, $input);

    foreach (['source_name', 'headline', 'body', 'note', 'published_at'] as $key) {
        $value = trim((string)($merged[$key] ?? ''));
        if (strlen($value) > 4000) {
            $value = substr($value, 0, 4000);
        }
        $merged[$key] = $value;
    }

    $merged['format_version'] = 'v1';
    return $merged;
}

function edudisplej_text_collections_sanitize_html(string $html): string {
    $trimmed = trim($html);
    if ($trimmed === '') {
        return '';
    }

    $allowed = '<p><div><span><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote>';
    $safe = strip_tags($trimmed, $allowed);

    if (strlen($safe) > 30000) {
        $safe = substr($safe, 0, 30000);
    }

    return $safe;
}

function edudisplej_text_collections_resolve_company_id(): int {
    $company_id = (int)($_SESSION['company_id'] ?? 0);
    if ($company_id > 0) {
        return $company_id;
    }

    $acting_company = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    if ($acting_company > 0) {
        return $acting_company;
    }

    return 0;
}

function edudisplej_text_collections_refresh_modules_for_collection(mysqli $conn, int $company_id, int $collection_id, array $collection): array {
    $module_stmt = $conn->prepare("SELECT kgm.id, kgm.group_id, kgm.settings
                                  FROM kiosk_group_modules kgm
                                  INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
                                  WHERE kg.company_id = ? AND kgm.is_active = 1 AND kgm.module_key = 'text'");
    $module_stmt->bind_param('i', $company_id);
    $module_stmt->execute();
    $result = $module_stmt->get_result();

    $updated_modules = 0;
    $affected_groups = [];
    $version_stamp = (int)round(microtime(true) * 1000);

    while ($row = $result->fetch_assoc()) {
        $settings = json_decode((string)($row['settings'] ?? ''), true);
        if (!is_array($settings)) {
            continue;
        }

        $source_type = strtolower(trim((string)($settings['textSourceType'] ?? 'manual')));
        $selected_collection = (int)($settings['textCollectionId'] ?? 0);
        if ($source_type !== 'collection' || $selected_collection !== $collection_id) {
            continue;
        }

        $settings['text'] = (string)($collection['content_html'] ?? '');
        $settings['bgColor'] = (string)($collection['bg_color'] ?? '#000000');
        $settings['bgImageData'] = (string)($collection['bg_image_data'] ?? '');
        $settings['textCollectionLabel'] = (string)($collection['title'] ?? '');
        $settings['textCollectionVersionTs'] = $version_stamp;

        $settings_json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $update_stmt = $conn->prepare("UPDATE kiosk_group_modules SET settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
        $module_id = (int)$row['id'];
        $update_stmt->bind_param('si', $settings_json, $module_id);
        $update_stmt->execute();
        $update_stmt->close();

        $updated_modules++;
        $affected_groups[(int)$row['group_id']] = true;
    }
    $module_stmt->close();

    foreach (array_keys($affected_groups) as $group_id) {
        $plan_stmt = $conn->prepare("UPDATE kiosk_group_loop_plans SET plan_version = ?, updated_at = CURRENT_TIMESTAMP WHERE group_id = ?");
        $plan_version_value = (string)$version_stamp;
        $plan_stmt->bind_param('si', $plan_version_value, $group_id);
        $plan_stmt->execute();
        $plan_stmt->close();
    }

    return [
        'updated_modules' => $updated_modules,
        'updated_groups' => count($affected_groups),
        'version' => $version_stamp,
    ];
}

function edudisplej_text_collections_detach_deleted_collection(mysqli $conn, int $company_id, int $collection_id): array {
    $module_stmt = $conn->prepare("SELECT kgm.id, kgm.group_id, kgm.settings
                                  FROM kiosk_group_modules kgm
                                  INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
                                  WHERE kg.company_id = ? AND kgm.is_active = 1 AND kgm.module_key = 'text'");
    $module_stmt->bind_param('i', $company_id);
    $module_stmt->execute();
    $result = $module_stmt->get_result();

    $updated_modules = 0;
    $affected_groups = [];
    $version_stamp = (int)round(microtime(true) * 1000);

    while ($row = $result->fetch_assoc()) {
        $settings = json_decode((string)($row['settings'] ?? ''), true);
        if (!is_array($settings)) {
            continue;
        }

        $source_type = strtolower(trim((string)($settings['textSourceType'] ?? 'manual')));
        $selected_collection = (int)($settings['textCollectionId'] ?? 0);
        if ($source_type !== 'collection' || $selected_collection !== $collection_id) {
            continue;
        }

        $settings['textSourceType'] = 'manual';
        $settings['textCollectionId'] = 0;
        $settings['textCollectionLabel'] = '';
        $settings['textCollectionVersionTs'] = $version_stamp;

        $settings_json = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $update_stmt = $conn->prepare("UPDATE kiosk_group_modules SET settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
        $module_id = (int)$row['id'];
        $update_stmt->bind_param('si', $settings_json, $module_id);
        $update_stmt->execute();
        $update_stmt->close();

        $updated_modules++;
        $affected_groups[(int)$row['group_id']] = true;
    }
    $module_stmt->close();

    foreach (array_keys($affected_groups) as $group_id) {
        $plan_stmt = $conn->prepare("UPDATE kiosk_group_loop_plans SET plan_version = ?, updated_at = CURRENT_TIMESTAMP WHERE group_id = ?");
        $plan_version_value = (string)$version_stamp;
        $plan_stmt->bind_param('si', $plan_version_value, $group_id);
        $plan_stmt->execute();
        $plan_stmt->close();
    }

    return [
        'updated_modules' => $updated_modules,
        'updated_groups' => count($affected_groups),
        'version' => $version_stamp,
    ];
}

$company_id = edudisplej_text_collections_resolve_company_id();
if ($company_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Érvénytelen cég azonosító']);
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));

try {
    $conn = getDbConnection();
    edudisplej_text_collections_ensure_schema($conn);

    if ($action === 'list') {
        $include_content = !empty($_GET['include_content']);
        $fields = $include_content
            ? 'id, title, content_html, external_source_json, bg_color, bg_image_data, updated_at'
            : 'id, title, updated_at';

        $stmt = $conn->prepare("SELECT {$fields} FROM text_collections WHERE company_id = ? AND is_active = 1 ORDER BY updated_at DESC, id DESC");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $item = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
            if ($include_content) {
                $item['content_html'] = (string)($row['content_html'] ?? '');
                $item['external_source'] = edudisplej_text_collections_external_source_sanitize($row['external_source_json'] ?? null);
                $item['bg_color'] = (string)($row['bg_color'] ?? '#000000');
                $item['bg_image_data'] = (string)($row['bg_image_data'] ?? '');
            }
            $items[] = $item;
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items]);
        exit();
    }

    if ($action === 'save') {
        $raw_body = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($raw_body) ? $raw_body : $_POST;

        $collection_id = (int)($payload['id'] ?? 0);
        $title = trim((string)($payload['title'] ?? ''));
        $content_html = edudisplej_text_collections_sanitize_html((string)($payload['content_html'] ?? ''));
        $external_source = edudisplej_text_collections_external_source_sanitize($payload['external_source'] ?? null);
        $external_source_json = json_encode($external_source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bg_color = strtolower(trim((string)($payload['bg_color'] ?? '#000000')));
        $bg_image_data = trim((string)($payload['bg_image_data'] ?? ''));

        if ($title === '') {
            echo json_encode(['success' => false, 'message' => 'Név megadása kötelező']);
            exit();
        }
        if (strlen($title) > 180) {
            $title = substr($title, 0, 180);
        }

        if (!preg_match('/^#[0-9a-f]{6}$/', $bg_color)) {
            $bg_color = '#000000';
        }

        if (strlen($bg_image_data) > 8000000) {
            $bg_image_data = substr($bg_image_data, 0, 8000000);
        }

        $conn->begin_transaction();
        try {
            if ($collection_id > 0) {
                $update_stmt = $conn->prepare('UPDATE text_collections SET title = ?, content_html = ?, external_source_json = ?, bg_color = ?, bg_image_data = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
                $update_stmt->bind_param('sssssiii', $title, $content_html, $external_source_json, $bg_color, $bg_image_data, $user_id, $collection_id, $company_id);
                $update_stmt->execute();
                $affected = $update_stmt->affected_rows;
                $update_stmt->close();

                if ($affected < 0) {
                    throw new Exception('Frissítés sikertelen');
                }
            } else {
                $insert_stmt = $conn->prepare('INSERT INTO text_collections (company_id, title, content_html, external_source_json, bg_color, bg_image_data, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $insert_stmt->bind_param('isssssii', $company_id, $title, $content_html, $external_source_json, $bg_color, $bg_image_data, $user_id, $user_id);
                $insert_stmt->execute();
                $collection_id = (int)$insert_stmt->insert_id;
                $insert_stmt->close();
            }

            $refresh = edudisplej_text_collections_refresh_modules_for_collection($conn, $company_id, $collection_id, [
                'title' => $title,
                'content_html' => $content_html,
                'bg_color' => $bg_color,
                'bg_image_data' => $bg_image_data,
            ]);

            $conn->commit();
            closeDbConnection($conn);

            echo json_encode([
                'success' => true,
                'message' => 'Szöveg gyűjtemény mentve',
                'item' => [
                    'id' => $collection_id,
                    'title' => $title,
                    'content_html' => $content_html,
                    'external_source' => $external_source,
                    'bg_color' => $bg_color,
                    'bg_image_data' => $bg_image_data,
                ],
                'propagation' => $refresh,
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    if ($action === 'delete') {
        $raw_body = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($raw_body) ? $raw_body : $_POST;
        $collection_id = (int)($payload['id'] ?? 0);

        if ($collection_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Érvénytelen azonosító']);
            exit();
        }

        $conn->begin_transaction();
        try {
            $delete_stmt = $conn->prepare('UPDATE text_collections SET is_active = 0, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
            $delete_stmt->bind_param('iii', $user_id, $collection_id, $company_id);
            $delete_stmt->execute();
            $affected = $delete_stmt->affected_rows;
            $delete_stmt->close();

            if ($affected <= 0) {
                $conn->rollback();
                closeDbConnection($conn);
                echo json_encode(['success' => false, 'message' => 'Elem nem található']);
                exit();
            }

            $refresh = edudisplej_text_collections_detach_deleted_collection($conn, $company_id, $collection_id);
            $conn->commit();
            closeDbConnection($conn);

            echo json_encode([
                'success' => true,
                'message' => 'Szöveg gyűjtemény törölve',
                'propagation' => $refresh,
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    closeDbConnection($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ismeretlen művelet']);
} catch (Exception $e) {
    error_log('api/text_collections.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Szerver hiba']);
}
