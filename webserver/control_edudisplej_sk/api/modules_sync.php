<?php
$start_time = microtime(true);
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';
require_once '../logging.php';
require_once '../modules/module_asset_service.php';

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => '', 'modules' => []];

function parse_unix_timestamp($value) {
    if (!$value) {
        return null;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $ts = strtotime($value);
    return $ts ? $ts : null;
}

function edudisplej_ensure_time_block_schema(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_time_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        block_name VARCHAR(120) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        days_mask VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_group (group_id),
        INDEX idx_active (group_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has_block_type = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'block_type'");
    if ($has_block_type && $has_block_type->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN block_type VARCHAR(16) NOT NULL DEFAULT 'weekly' AFTER block_name");
    }

    $has_specific_date = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'specific_date'");
    if ($has_specific_date && $has_specific_date->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN specific_date DATE NULL AFTER block_type");
    }

    $has_priority = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'priority'");
    if ($has_priority && $has_priority->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN priority INT NOT NULL DEFAULT 100 AFTER is_active");
    }

    $has_time_block_col = $conn->query("SHOW COLUMNS FROM kiosk_group_modules LIKE 'time_block_id'");
    if ($has_time_block_col && $has_time_block_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_modules ADD COLUMN time_block_id INT NULL AFTER group_id");
        $conn->query("CREATE INDEX idx_group_time_block ON kiosk_group_modules (group_id, time_block_id)");
    }
}

function edudisplej_ensure_loop_plan_schema(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_loop_plans (
        group_id INT PRIMARY KEY,
        plan_json LONGTEXT NOT NULL,
        plan_version BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has_plan_version = $conn->query("SHOW COLUMNS FROM kiosk_group_loop_plans LIKE 'plan_version'");
    if ($has_plan_version && $has_plan_version->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_loop_plans ADD COLUMN plan_version BIGINT NOT NULL DEFAULT 0 AFTER plan_json");
    }
}

function edudisplej_resolve_active_time_block_id(mysqli $conn, int $group_id): array {
    $stmt = $conn->prepare("SELECT id, block_type, specific_date, start_time, end_time, days_mask, priority, display_order FROM kiosk_group_time_blocks WHERE group_id = ? AND is_active = 1 ORDER BY display_order, id");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $blocks = [];
    while ($row = $result->fetch_assoc()) {
        $blocks[] = $row;
    }
    $stmt->close();

    if (empty($blocks)) {
        return ['has_blocks' => false, 'active_block_id' => null];
    }

    $day = (int)date('N');
    $now = date('H:i:s');

    $date_key = date('Y-m-d');
    $candidates = [];
    foreach ($blocks as $block) {
        $block_type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));
        if (!in_array($block_type, ['weekly', 'date'], true)) {
            $block_type = 'weekly';
        }

        if ($block_type === 'date') {
            if ((string)($block['specific_date'] ?? '') !== $date_key) {
                continue;
            }
        }

        $days_raw = trim((string)($block['days_mask'] ?? ''));
        $allowed_days = [];
        if ($block_type === 'weekly' && $days_raw !== '') {
            foreach (explode(',', $days_raw) as $part) {
                $val = (int)trim($part);
                if ($val >= 1 && $val <= 7) {
                    $allowed_days[$val] = true;
                }
            }
        }

        if ($block_type === 'weekly' && !empty($allowed_days) && !isset($allowed_days[$day])) {
            continue;
        }

        $start = (string)$block['start_time'];
        $end = (string)$block['end_time'];
        if ($start <= $end) {
            if ($now >= $start && $now <= $end) {
                $candidates[] = $block;
            }
        } else {
            if ($now >= $start || $now <= $end) {
                $candidates[] = $block;
            }
        }
    }

    if (!empty($candidates)) {
        usort($candidates, function ($a, $b) {
            $typeA = strtolower(trim((string)($a['block_type'] ?? 'weekly'))) === 'date' ? 2 : 1;
            $typeB = strtolower(trim((string)($b['block_type'] ?? 'weekly'))) === 'date' ? 2 : 1;
            if ($typeA !== $typeB) {
                return $typeB <=> $typeA;
            }

            $priorityA = (int)($a['priority'] ?? 100);
            $priorityB = (int)($b['priority'] ?? 100);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $orderA = (int)($a['display_order'] ?? 0);
            $orderB = (int)($b['display_order'] ?? 0);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        return ['has_blocks' => true, 'active_block_id' => (int)$candidates[0]['id']];
    }

    return ['has_blocks' => true, 'active_block_id' => null];
}

function edudisplej_sync_hydrate_text_collection_settings(mysqli $conn, int $company_id, array $settings): array {
    $source_type = strtolower(trim((string)($settings['textSourceType'] ?? 'manual')));
    if ($source_type !== 'collection') {
        return $settings;
    }

    $collection_id = (int)($settings['textCollectionId'] ?? 0);
    if ($collection_id <= 0 || $company_id <= 0) {
        return $settings;
    }

    static $collectionCache = [];
    $cacheKey = $company_id . ':' . $collection_id;
    if (!array_key_exists($cacheKey, $collectionCache)) {
        $stmt = $conn->prepare("SELECT id, title, content_html, bg_color, bg_image_data, updated_at
                                FROM text_collections
                                WHERE id = ? AND company_id = ? AND is_active = 1
                                LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $collection_id, $company_id);
            $stmt->execute();
            $collectionCache[$cacheKey] = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        } else {
            $collectionCache[$cacheKey] = null;
        }
    }

    $collection = $collectionCache[$cacheKey] ?? null;
    if (!$collection) {
        return $settings;
    }

    $settings['text'] = (string)($collection['content_html'] ?? $settings['text'] ?? '');
    $settings['bgColor'] = (string)($collection['bg_color'] ?? $settings['bgColor'] ?? '#000000');
    $settings['bgImageData'] = (string)($collection['bg_image_data'] ?? $settings['bgImageData'] ?? '');
    $settings['textCollectionLabel'] = (string)($collection['title'] ?? $settings['textCollectionLabel'] ?? '');
    $versionTs = strtotime((string)($collection['updated_at'] ?? ''));
    if ($versionTs) {
        $settings['textCollectionVersionTs'] = $versionTs * 1000;
    }

    return $settings;
}

function edudisplej_sync_prefetch_meal_menu_payload(mysqli $conn, int $company_id, array $settings): ?array {
    $institution_id = (int)($settings['institutionId'] ?? 0);
    if ($institution_id <= 0 || $company_id <= 0) {
        return null;
    }

    $source_type = strtolower(trim((string)($settings['sourceType'] ?? 'server')));
    if ($source_type !== 'manual') {
        $source_type = 'server';
    }

    $date_value = date('Y-m-d');
    $showBreakfast = !empty($settings['showBreakfast']);
    $showSnackAm = !empty($settings['showSnackAm']);
    $showLunch = !empty($settings['showLunch']);
    $showSnackPm = !empty($settings['showSnackPm']);
    $showDinner = !empty($settings['showDinner']);

    $inst_stmt = $conn->prepare("SELECT institution_name
                                FROM meal_plan_institutions
                                WHERE id = ? AND (company_id = 0 OR company_id = ?)
                                ORDER BY company_id DESC
                                LIMIT 1");
    if (!$inst_stmt) {
        return null;
    }
    $inst_stmt->bind_param('ii', $institution_id, $company_id);
    $inst_stmt->execute();
    $inst = $inst_stmt->get_result()->fetch_assoc();
    $inst_stmt->close();

    if (!$inst) {
        return null;
    }

    $source_effective = $source_type;
    $menu_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                FROM meal_plan_items
                                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date = ?
                                    AND (
                                        ? = ''
                                        OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                        OR source_type = ?
                                    )
                                ORDER BY company_id DESC
                                LIMIT 1");
    if (!$menu_stmt) {
        return null;
    }
    $menu_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
    $menu_stmt->execute();
    $menu = $menu_stmt->get_result()->fetch_assoc();
    $menu_stmt->close();

    if (!$menu && $source_type === 'manual') {
        $source_effective = 'server';
        $menu_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                    FROM meal_plan_items
                                    WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date = ?
                                        AND (
                                            ? = ''
                                            OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                            OR source_type = ?
                                        )
                                    ORDER BY company_id DESC
                                    LIMIT 1");
        if ($menu_stmt) {
            $menu_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
            $menu_stmt->execute();
            $menu = $menu_stmt->get_result()->fetch_assoc();
            $menu_stmt->close();
        }
    }

    if (!$menu) {
        $fallback_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                        FROM meal_plan_items
                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date <= ?
                                            AND (
                                                ? = ''
                                                OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                OR source_type = ?
                                            )
                                        ORDER BY menu_date DESC, company_id DESC
                                        LIMIT 1");
        if ($fallback_stmt) {
            $fallback_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
            $fallback_stmt->execute();
            $menu = $fallback_stmt->get_result()->fetch_assoc();
            $fallback_stmt->close();
        }
    }

    if (!$menu && $source_type === 'manual' && $source_effective !== 'server') {
        $source_effective = 'server';
        $fallback_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                        FROM meal_plan_items
                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date <= ?
                                            AND (
                                                ? = ''
                                                OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                OR source_type = ?
                                            )
                                        ORDER BY menu_date DESC, company_id DESC
                                        LIMIT 1");
        if ($fallback_stmt) {
            $fallback_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
            $fallback_stmt->execute();
            $menu = $fallback_stmt->get_result()->fetch_assoc();
            $fallback_stmt->close();
        }
    }

    if (!$menu) {
        $future_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                        FROM meal_plan_items
                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date >= ?
                                            AND (
                                                ? = ''
                                                OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                OR source_type = ?
                                            )
                                        ORDER BY menu_date ASC, company_id DESC
                                        LIMIT 1");
        if ($future_stmt) {
            $future_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
            $future_stmt->execute();
            $menu = $future_stmt->get_result()->fetch_assoc();
            $future_stmt->close();
        }
    }

    if (!$menu && $source_type === 'manual' && $source_effective !== 'server') {
        $source_effective = 'server';
        $future_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type, updated_at
                                        FROM meal_plan_items
                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date >= ?
                                            AND (
                                                ? = ''
                                                OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                OR source_type = ?
                                            )
                                        ORDER BY menu_date ASC, company_id DESC
                                        LIMIT 1");
        if ($future_stmt) {
            $future_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_effective, $source_effective, $source_effective);
            $future_stmt->execute();
            $menu = $future_stmt->get_result()->fetch_assoc();
            $future_stmt->close();
        }
    }

    $meals = [];
    if ($showBreakfast) { $meals[] = ['key' => 'breakfast', 'label' => 'Reggeli', 'text' => (string)($menu['breakfast'] ?? '')]; }
    if ($showSnackAm) { $meals[] = ['key' => 'snack_am', 'label' => 'Tízórai', 'text' => (string)($menu['snack_am'] ?? '')]; }
    if ($showLunch) { $meals[] = ['key' => 'lunch', 'label' => 'Ebéd', 'text' => (string)($menu['lunch'] ?? '')]; }
    if ($showSnackPm) { $meals[] = ['key' => 'snack_pm', 'label' => 'Uzsonna', 'text' => (string)($menu['snack_pm'] ?? '')]; }
    if ($showDinner) { $meals[] = ['key' => 'dinner', 'label' => 'Vacsora', 'text' => (string)($menu['dinner'] ?? '')]; }

    return [
        'institution_name' => (string)($inst['institution_name'] ?? ''),
        'menu_date' => (string)($menu['menu_date'] ?? $date_value),
        'meals' => $meals,
        'source_type' => (string)($menu['source_type'] ?? ''),
        'updated_at' => (string)($menu['updated_at'] ?? ''),
    ];
}

function edudisplej_optimize_module_settings_for_sync(mysqli $conn, int $company_id, string $module_key, $settings): array {
    $normalized = is_array($settings) ? $settings : [];
    $key = strtolower(trim($module_key));
    $requestToken = edudisplej_current_request_api_token();

    if ($key === 'pdf') {
        $assetId = (int)($normalized['pdfAssetId'] ?? 0);
        $assetUrl = trim((string)($normalized['pdfAssetUrl'] ?? ''));
        if ($assetId > 0) {
            $normalized['pdfAssetUrl'] = edudisplej_module_asset_api_url_by_id($assetId, $requestToken);
        } else {
            $normalized['pdfAssetUrl'] = edudisplej_module_asset_normalize_url_for_api($assetUrl, $requestToken);
        }

        $assetUrl = trim((string)($normalized['pdfAssetUrl'] ?? ''));
        if ($assetUrl !== '' && array_key_exists('pdfDataBase64', $normalized)) {
            $normalized['pdfDataBase64'] = '';
        }
        return $normalized;
    }

    if ($key === 'image-gallery' || $key === 'gallery') {
        $raw = (string)($normalized['imageUrlsJson'] ?? '[]');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $clean = [];
            foreach ($parsed as $item) {
                $value = trim((string)$item);
                if ($value !== '') {
                    $normalizedValue = edudisplej_module_asset_normalize_url_for_api($value, $requestToken);
                    $clean[] = $normalizedValue !== '' ? $normalizedValue : $value;
                }
                if (count($clean) >= 10) {
                    break;
                }
            }
            $normalized['imageUrlsJson'] = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    if ($key === 'video') {
        $assetId = (int)($normalized['videoAssetId'] ?? 0);
        $assetUrl = trim((string)($normalized['videoAssetUrl'] ?? ''));
        if ($assetId > 0) {
            $normalized['videoAssetUrl'] = edudisplej_module_asset_api_url_by_id($assetId, $requestToken);
        } else {
            $normalized['videoAssetUrl'] = edudisplej_module_asset_normalize_url_for_api($assetUrl, $requestToken);
        }
        return $normalized;
    }

    if ($key === 'text') {
        return edudisplej_sync_hydrate_text_collection_settings($conn, $company_id, $normalized);
    }

    if ($key === 'meal-menu' || $key === 'meal_menu') {
        $prefetched = edudisplej_sync_prefetch_meal_menu_payload($conn, $company_id, $normalized);
        if ($prefetched) {
            $normalized['offlinePrefetchedMenuData'] = $prefetched;
            $normalized['offlinePrefetchedMenuSavedAt'] = date('c');
        }
    }

    return $normalized;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $kiosk_id = $data['kiosk_id'] ?? null;
    $last_loop_update = $data['last_loop_update'] ?? null; // Client's last known update timestamp
    
    if (empty($mac) && empty($kiosk_id)) {
        $response['message'] = 'MAC address or kiosk ID required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    edudisplej_ensure_time_block_schema($conn);
    edudisplej_ensure_loop_plan_schema($conn);
    
    // Find kiosk
    if ($kiosk_id) {
        if ($kiosk_id <= 0) {
            $response['message'] = 'Invalid kiosk ID';
            echo json_encode($response);
            exit;
        }
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval, loop_last_update FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval, loop_last_update FROM kiosks WHERE mac = ?");
        $stmt->bind_param("s", $mac);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();

    // Enforce company ownership
    if (!empty($kiosk['company_id'])) {
        api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    } elseif (!empty($api_company['id']) && !api_is_admin_session($api_company)) {
        $assign_stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
        $assign_stmt->bind_param("ii", $api_company['id'], $kiosk['id']);
        $assign_stmt->execute();
        $assign_stmt->close();
        $kiosk['company_id'] = $api_company['id'];
    }
    $stmt->close();
    
    // Update last_seen
    $update_stmt = $conn->prepare("UPDATE kiosks SET last_seen = NOW() WHERE id = ?");
    $update_stmt->bind_param("i", $kiosk['id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    $response['kiosk_id'] = $kiosk['id'];
    $response['device_id'] = $kiosk['device_id'];
    $response['sync_interval'] = (int)$kiosk['sync_interval'];

    // Check if kiosk belongs to any group and get the latest update timestamp
    $group_query = "SELECT
                        kga.group_id,
                        MAX(kgm.updated_at) as modules_latest_update,
                        (SELECT klp.updated_at FROM kiosk_group_loop_plans klp WHERE klp.group_id = kga.group_id LIMIT 1) as plan_latest_update,
                        (SELECT klp.plan_version FROM kiosk_group_loop_plans klp WHERE klp.group_id = kga.group_id LIMIT 1) as plan_version
                    FROM kiosk_group_assignments kga
                    JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
                    WHERE kga.kiosk_id = ?
                    GROUP BY kga.group_id
                    LIMIT 1";
    $group_stmt = $conn->prepare($group_query);
    $group_stmt->bind_param("i", $kiosk['id']);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    $group_row = $group_result->fetch_assoc();
    $group_stmt->close();

    if ($group_row && !empty($group_row['group_id'])) {
        api_require_group_company($conn, $api_company, (int)$group_row['group_id']);
    }

    $has_group_configuration = !empty($group_row) && !empty($group_row['group_id']);
    $effective_configured = (bool)$kiosk['is_configured'] || $has_group_configuration;
    $response['is_configured'] = $effective_configured;

    // If still not configured and no group fallback exists, return unconfigured module
    if (!$effective_configured) {
        $response['success'] = true;
        $response['message'] = 'Kiosk not configured';
        $response['modules'] = [
            [
                'module_key' => 'unconfigured',
                'display_order' => 0,
                'duration_seconds' => 60,
                'settings' => []
            ]
        ];
        $response['preload_modules'] = [
            ['module_key' => 'unconfigured', 'name' => 'Unconfigured']
        ];
        $response['active_scope'] = 'base';
        $response['needs_update'] = false;
        
        // Log modules sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
        $details = json_encode(['status' => 'unconfigured', 'timestamp' => date('Y-m-d H:i:s')]);
        $log_stmt->bind_param("is", $kiosk['id'], $details);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode($response);
        exit;
    }

    $active_time_block_id = null;
    $has_time_blocks = false;
    if ($group_row && !empty($group_row['group_id'])) {
        $block_state = edudisplej_resolve_active_time_block_id($conn, (int)$group_row['group_id']);
        $active_time_block_id = $block_state['active_block_id'];
        $has_time_blocks = $block_state['has_blocks'];
    }
    
    $server_timestamp = null;
    $needs_update = true;

    $stored_last_update = $kiosk['loop_last_update'] ?? null;
    $stored_ts = parse_unix_timestamp($stored_last_update);
    $client_ts = parse_unix_timestamp($last_loop_update);

    if ($client_ts && (!$stored_ts || $client_ts > $stored_ts)) {
        $stored_ts = $client_ts;
        $stored_last_update = date('Y-m-d H:i:s', $client_ts);
        $update_loop_stmt = $conn->prepare("UPDATE kiosks SET loop_last_update = ? WHERE id = ?");
        $update_loop_stmt->bind_param("si", $stored_last_update, $kiosk['id']);
        $update_loop_stmt->execute();
        $update_loop_stmt->close();
    }

    if ($group_row) {
        $server_timestamp = $group_row['modules_latest_update'];
        if (!empty($group_row['plan_latest_update'])) {
            $modules_ts = parse_unix_timestamp($group_row['modules_latest_update'] ?? null);
            $plan_ts = parse_unix_timestamp($group_row['plan_latest_update'] ?? null);
            if ($plan_ts && (!$modules_ts || $plan_ts > $modules_ts)) {
                $server_timestamp = $group_row['plan_latest_update'];
            }
        }
        $server_ts = parse_unix_timestamp($server_timestamp);

        if ($server_ts) {
            if (!$stored_ts) {
                $needs_update = true;
            } elseif ($server_ts <= $stored_ts) {
                $needs_update = false;
            }
        }
    } else {
        $ts_stmt = $conn->prepare("SELECT MAX(updated_at) as latest_update, MAX(created_at) as created_at FROM kiosk_modules WHERE kiosk_id = ? AND is_active = 1");
        $ts_stmt->bind_param("i", $kiosk['id']);
        $ts_stmt->execute();
        $ts_result = $ts_stmt->get_result();
        $ts_row = $ts_result->fetch_assoc();
        $ts_stmt->close();

        $server_timestamp = $ts_row['latest_update'] ?? $ts_row['created_at'] ?? null;
        $server_ts = parse_unix_timestamp($server_timestamp);
        if ($server_ts) {
            if (!$stored_ts) {
                $needs_update = true;
            } elseif ($server_ts <= $stored_ts) {
                $needs_update = false;
            }
        }
    }

    if (!empty($group_row) && $has_time_blocks) {
        $needs_update = true;
    }
    
    $response['server_timestamp'] = $server_timestamp;
    $response['loop_plan_version'] = $group_row ? (int)($group_row['plan_version'] ?? 0) : 0;
    $response['needs_update'] = $needs_update;
    
    // If no update needed, return minimal response
    if (!$needs_update) {
        $response['success'] = true;
        $response['message'] = 'No update needed';
        $response['modules'] = []; // Empty array indicates no changes
        
        closeDbConnection($conn);
        echo json_encode($response);
        exit;
    }
    
    // Fetch modules (update needed)
    $modules = [];
    $preload_map = [];
    
    if ($group_row) {
        $group_id_int = (int)$group_row['group_id'];

        $preload_stmt = $conn->prepare("SELECT DISTINCT m.module_key, m.name
                                        FROM kiosk_group_modules kgm
                                        JOIN modules m ON kgm.module_id = m.id
                                        WHERE kgm.group_id = ? AND kgm.is_active = 1
                                        ORDER BY m.module_key ASC");
        $preload_stmt->bind_param("i", $group_id_int);
        $preload_stmt->execute();
        $preload_result = $preload_stmt->get_result();
        while ($preload_row = $preload_result->fetch_assoc()) {
            $module_key = (string)($preload_row['module_key'] ?? '');
            if ($module_key === '') {
                continue;
            }
            $preload_map[$module_key] = [
                'module_key' => $module_key,
                'name' => (string)($preload_row['name'] ?? $module_key)
            ];
        }
        $preload_stmt->close();

        // Get modules from group configuration
        if ($has_time_blocks && $active_time_block_id !== null) {
            $query = "SELECT m.module_key, m.name, kgm.display_order, kgm.duration_seconds, kgm.settings
                      FROM kiosk_group_modules kgm
                      JOIN modules m ON kgm.module_id = m.id
                      WHERE kgm.group_id = ? AND kgm.is_active = 1 AND kgm.time_block_id = ?
                      ORDER BY kgm.display_order ASC";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $group_row['group_id'], $active_time_block_id);
        } else {
            $query = "SELECT m.module_key, m.name, kgm.display_order, kgm.duration_seconds, kgm.settings
                      FROM kiosk_group_modules kgm
                      JOIN modules m ON kgm.module_id = m.id
                      WHERE kgm.group_id = ? AND kgm.is_active = 1 AND kgm.time_block_id IS NULL
                      ORDER BY kgm.display_order ASC";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $group_row['group_id']);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $settings = [];
            if (!empty($row['settings'])) {
                $settings = json_decode($row['settings'], true) ?? [];
            }
            $settings = edudisplej_optimize_module_settings_for_sync($conn, (int)($kiosk['company_id'] ?? 0), (string)($row['module_key'] ?? ''), $settings);
            $duration = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration = 60;
            }
            
            $modules[] = [
                'module_key' => $row['module_key'],
                'name' => $row['name'],
                'display_order' => (int)$row['display_order'],
                'duration_seconds' => $duration,
                'settings' => $settings
            ];

            $module_key = (string)($row['module_key'] ?? '');
            if ($module_key !== '' && !isset($preload_map[$module_key])) {
                $preload_map[$module_key] = [
                    'module_key' => $module_key,
                    'name' => (string)($row['name'] ?? $module_key)
                ];
            }
        }
        
        $stmt->close();
        $response['config_source'] = 'group';
        $response['group_id'] = $group_id_int;
        $response['active_time_block_id'] = $active_time_block_id;
        $response['active_scope'] = $active_time_block_id !== null ? 'block' : 'base';
    }
    
    // If no group modules found, fall back to kiosk-specific configuration
    if (empty($modules)) {
        $query = "SELECT m.module_key, m.name, km.display_order, km.duration_seconds, km.settings
                  FROM kiosk_modules km
                  JOIN modules m ON km.module_id = m.id
                  WHERE km.kiosk_id = ? AND km.is_active = 1
                  ORDER BY km.display_order ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $kiosk['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $settings = [];
            if (!empty($row['settings'])) {
                $settings = json_decode($row['settings'], true) ?? [];
            }
            $settings = edudisplej_optimize_module_settings_for_sync($conn, (int)($kiosk['company_id'] ?? 0), (string)($row['module_key'] ?? ''), $settings);
            $duration = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration = 60;
            }
            
            $modules[] = [
                'module_key' => $row['module_key'],
                'name' => $row['name'],
                'display_order' => (int)$row['display_order'],
                'duration_seconds' => $duration,
                'settings' => $settings
            ];

            $module_key = (string)($row['module_key'] ?? '');
            if ($module_key !== '' && !isset($preload_map[$module_key])) {
                $preload_map[$module_key] = [
                    'module_key' => $module_key,
                    'name' => (string)($row['name'] ?? $module_key)
                ];
            }
        }
        
        $stmt->close();
        $response['config_source'] = 'kiosk';
        $response['active_scope'] = 'base';
    }
    
    $response['success'] = true;
    $response['message'] = count($modules) > 0 ? 'Modules retrieved' : 'No modules configured';
    $response['modules'] = $modules;
    $response['preload_modules'] = array_values($preload_map);
    
    // Log modules sync
    $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
    $details = json_encode([
        'module_count' => count($modules), 
        'timestamp' => date('Y-m-d H:i:s'),
        'needs_update' => $needs_update
    ]);
    $log_stmt->bind_param("is", $kiosk['id'], $details);
    $log_stmt->execute();
    $log_stmt->close();
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

// Log API request
$execution_time = microtime(true) - $start_time;
$status_code = $response['success'] ? 200 : 400;
log_api_request(
    $kiosk['company_id'] ?? null,
    $kiosk['id'] ?? null,
    '/api/modules_sync.php',
    'POST',
    $status_code,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    null,
    null,
    $execution_time
);

echo json_encode($response);
?>

