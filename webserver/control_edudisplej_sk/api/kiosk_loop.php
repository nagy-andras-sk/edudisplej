<?php
/**
 * API - Get Kiosk Loop Configuration by Device ID
 * Returns loop config and module list for download
 * No session required - uses device_id authentication
 */
require_once '../dbkonfiguracia.php';
require_once 'auth.php';
require_once '../modules/module_asset_service.php';
require_once '../modules/module_standard.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

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

function edudisplej_get_active_block_id(mysqli $conn, int $group_id): array {
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

        if ($block_type === 'date' && (string)($block['specific_date'] ?? '') !== $date_key) {
            continue;
        }

        $allowed_days = [];
        if ($block_type === 'weekly') {
            foreach (explode(',', (string)($block['days_mask'] ?? '')) as $part) {
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

function edudisplej_module_runtime_payload_for_sync(string $module_key): array {
    $resolved_key = strtolower(trim($module_key));
    if ($resolved_key === '') {
        return [
            'module_folder' => '',
            'module_renderer' => '',
            'module_main_file' => '',
        ];
    }

    $runtime = edudisplej_resolve_module_runtime($resolved_key);
    $renderer_rel = trim((string)($runtime['renderer_rel'] ?? ''));
    $main_file = basename($renderer_rel);
    if ($main_file === '.' || $main_file === '/' || $main_file === '\\') {
        $main_file = '';
    }

    return [
        'module_folder' => (string)($runtime['folder'] ?? $resolved_key),
        'module_renderer' => $renderer_rel,
        'module_main_file' => $main_file,
    ];
}

try {
    // Get device_id from POST or GET
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    
    if (empty($device_id)) {
        $response['message'] = 'Missing device_id';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    edudisplej_ensure_time_block_schema($conn);
    
    // Get kiosk by device_id
    $stmt = $conn->prepare("SELECT id, device_id, company_id FROM kiosks WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $company_id = $kiosk['company_id'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $company_id, 'Unauthorized');
    
    // Get kiosk's loop configuration
    // First check if kiosk has specific modules assigned
    $stmt = $conn->prepare("
        SELECT km.*, m.name as module_name, m.module_key
        FROM kiosk_modules km
        JOIN modules m ON km.module_id = m.id
        WHERE km.kiosk_id = ? AND km.is_active = 1
        ORDER BY km.display_order
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loop_config = [];
    $preload_map = [];
    $offline_plan = [
        'base_loop' => [],
        'time_blocks' => []
    ];
    $config_source = 'kiosk';
    $group_id = null;
    $loop_plan_version = 0;
    $loop_plan_updated_at = null;
    while ($row = $result->fetch_assoc()) {
        $runtime_payload = edudisplej_module_runtime_payload_for_sync((string)($row['module_key'] ?? ''));

        $loop_item = [
            'module_id' => (int)$row['module_id'],
            'module_name' => $row['module_name'],
            'module_key' => $row['module_key'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'display_order' => (int)$row['display_order'],
            'settings' => edudisplej_optimize_module_settings_for_sync($conn, (int)$company_id, (string)($row['module_key'] ?? ''), $row['settings'] ? json_decode($row['settings'], true) : []),
            'source' => 'kiosk',
            'module_folder' => $runtime_payload['module_folder'],
            'module_renderer' => $runtime_payload['module_renderer'],
            'module_main_file' => $runtime_payload['module_main_file'],
        ];

        $loop_config[] = $loop_item;
        $offline_plan['base_loop'][] = $loop_item;

        $module_key = (string)($row['module_key'] ?? '');
        if ($module_key !== '' && !isset($preload_map[$module_key])) {
            $preload_map[$module_key] = [
                'module_key' => $module_key,
                'name' => (string)($row['module_name'] ?? $module_key),
                'module_folder' => $runtime_payload['module_folder'],
                'module_renderer' => $runtime_payload['module_renderer'],
                'module_main_file' => $runtime_payload['module_main_file'],
            ];
        }
    }
    $stmt->close();
    
    // If no specific modules, get from group(s)
    if (empty($loop_config)) {
        $config_source = 'group';
        $group_stmt = $conn->prepare("SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1");
        $group_stmt->bind_param("i", $kiosk_id);
        $group_stmt->execute();
        $group_row = $group_stmt->get_result()->fetch_assoc();
        $group_stmt->close();

        if ($group_row) {
            $group_id = (int)$group_row['group_id'];

            $plan_stmt = $conn->prepare("SELECT plan_json, plan_version, updated_at FROM kiosk_group_loop_plans WHERE group_id = ? LIMIT 1");
            $plan_stmt->bind_param("i", $group_id);
            $plan_stmt->execute();
            $plan_meta = $plan_stmt->get_result()->fetch_assoc();
            $plan_stmt->close();

            if ($plan_meta) {
                $loop_plan_version = (int)($plan_meta['plan_version'] ?? 0);
                $loop_plan_updated_at = $plan_meta['updated_at'] ?? null;
            }

            $planner_loop_styles = [];
            $planner_schedule_blocks = [];
            $planner_default_loop_style_id = 0;

            if (!empty($plan_meta['plan_json'])) {
                $decoded_plan = json_decode((string)$plan_meta['plan_json'], true);
                if (is_array($decoded_plan)) {
                    $planner_loop_styles = is_array($decoded_plan['loop_styles'] ?? null) ? $decoded_plan['loop_styles'] : [];
                    $planner_schedule_blocks = is_array($decoded_plan['schedule_blocks'] ?? null) ? $decoded_plan['schedule_blocks'] : [];
                    $planner_default_loop_style_id = (int)($decoded_plan['default_loop_style_id'] ?? 0);
                }
            }

            if (!empty($planner_loop_styles)) {
                $style_map = [];
                foreach ($planner_loop_styles as $style) {
                    if (!is_array($style)) {
                        continue;
                    }
                    $style_id = (int)($style['id'] ?? 0);
                    if ($style_id === 0) {
                        continue;
                    }
                    $style_map[$style_id] = is_array($style['items'] ?? null) ? $style['items'] : [];
                }

                if ($planner_default_loop_style_id === 0 && !empty($style_map)) {
                    $style_ids = array_keys($style_map);
                    $planner_default_loop_style_id = (int)$style_ids[0];
                }

                $base_loop = $style_map[$planner_default_loop_style_id] ?? [];
                $planner_blocks = [];
                foreach ($planner_schedule_blocks as $block) {
                    if (!is_array($block)) {
                        continue;
                    }

                    $loop_style_id = (int)($block['loop_style_id'] ?? 0);
                    $planner_blocks[] = [
                        'id' => (int)($block['id'] ?? 0),
                        'block_name' => (string)($block['block_name'] ?? 'Időblokk'),
                        'block_type' => strtolower((string)($block['block_type'] ?? 'weekly')) === 'date' ? 'date' : 'weekly',
                        'specific_date' => !empty($block['specific_date']) ? (string)$block['specific_date'] : null,
                        'start_time' => (string)($block['start_time'] ?? '00:00:00'),
                        'end_time' => (string)($block['end_time'] ?? '00:00:00'),
                        'days_mask' => (string)($block['days_mask'] ?? '1,2,3,4,5,6,7'),
                        'is_active' => (int)($block['is_active'] ?? 1),
                        'priority' => (int)($block['priority'] ?? 100),
                        'display_order' => (int)($block['display_order'] ?? 0),
                        'loops' => $style_map[$loop_style_id] ?? []
                    ];
                }

                $offline_plan['base_loop'] = $base_loop;
                $offline_plan['time_blocks'] = $planner_blocks;

                $now_day = (int)date('N');
                $now_time = date('H:i:s');
                $now_date = date('Y-m-d');
                $candidates = [];

                foreach ($planner_blocks as $block) {
                    if ((int)($block['is_active'] ?? 1) !== 1) {
                        continue;
                    }

                    $block_type = strtolower((string)($block['block_type'] ?? 'weekly')) === 'date' ? 'date' : 'weekly';
                    if ($block_type === 'date' && (string)($block['specific_date'] ?? '') !== $now_date) {
                        continue;
                    }

                    if ($block_type === 'weekly') {
                        $allowed_days = [];
                        foreach (explode(',', (string)($block['days_mask'] ?? '')) as $part) {
                            $val = (int)trim($part);
                            if ($val >= 1 && $val <= 7) {
                                $allowed_days[$val] = true;
                            }
                        }
                        if (!empty($allowed_days) && !isset($allowed_days[$now_day])) {
                            continue;
                        }
                    }

                    $start = (string)($block['start_time'] ?? '00:00:00');
                    $end = (string)($block['end_time'] ?? '00:00:00');
                    $matches_time = false;
                    if ($start <= $end) {
                        $matches_time = ($now_time >= $start && $now_time <= $end);
                    } else {
                        $matches_time = ($now_time >= $start || $now_time <= $end);
                    }

                    if ($matches_time) {
                        $candidates[] = $block;
                    }
                }

                if (!empty($candidates)) {
                    usort($candidates, function ($a, $b) {
                        $typeA = strtolower((string)($a['block_type'] ?? 'weekly')) === 'date' ? 2 : 1;
                        $typeB = strtolower((string)($b['block_type'] ?? 'weekly')) === 'date' ? 2 : 1;
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

                        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
                    });

                    $active_block = $candidates[0];
                    $active_block_id = (int)($active_block['id'] ?? 0);
                    $response['active_time_block_id'] = $active_block_id > 0 ? $active_block_id : null;
                    $response['active_scope'] = $active_block_id > 0 ? 'block' : 'base';
                    $loop_config = !empty($active_block['loops']) ? $active_block['loops'] : $base_loop;
                } else {
                    $response['active_time_block_id'] = null;
                    $response['active_scope'] = 'base';
                    $loop_config = $base_loop;
                }

                $all_plan_modules = [];
                foreach ($base_loop as $item) {
                    if (is_array($item)) {
                        $all_plan_modules[] = $item;
                    }
                }
                foreach ($planner_blocks as $block) {
                    foreach ((array)($block['loops'] ?? []) as $item) {
                        if (is_array($item)) {
                            $all_plan_modules[] = $item;
                        }
                    }
                }

                foreach ($all_plan_modules as $item) {
                    $module_key = (string)($item['module_key'] ?? '');
                    if ($module_key === '' || isset($preload_map[$module_key])) {
                        continue;
                    }

                    $runtime_payload = edudisplej_module_runtime_payload_for_sync($module_key);
                    $preload_map[$module_key] = [
                        'module_key' => $module_key,
                        'name' => (string)($item['module_name'] ?? $module_key),
                        'module_folder' => (string)($item['module_folder'] ?? $runtime_payload['module_folder']),
                        'module_renderer' => (string)($item['module_renderer'] ?? $runtime_payload['module_renderer']),
                        'module_main_file' => (string)($item['module_main_file'] ?? $runtime_payload['module_main_file']),
                    ];
                }
            } else {

            $block_state = edudisplej_get_active_block_id($conn, $group_id);
            $active_block_id = $block_state['active_block_id'];
            $has_blocks = $block_state['has_blocks'];

            $blocks_stmt = $conn->prepare("SELECT id, block_name, block_type, specific_date, start_time, end_time, days_mask, is_active, priority, display_order
                                           FROM kiosk_group_time_blocks
                                           WHERE group_id = ?
                                           ORDER BY display_order, id");
            $blocks_stmt->bind_param("i", $group_id);
            $blocks_stmt->execute();
            $blocks_result = $blocks_stmt->get_result();

            $block_map = [];
            while ($block = $blocks_result->fetch_assoc()) {
                $block_id = (int)$block['id'];
                $block_map[$block_id] = [
                    'id' => $block_id,
                    'block_name' => (string)($block['block_name'] ?? ''),
                    'block_type' => (string)($block['block_type'] ?? 'weekly'),
                    'specific_date' => $block['specific_date'],
                    'start_time' => (string)($block['start_time'] ?? '00:00:00'),
                    'end_time' => (string)($block['end_time'] ?? '00:00:00'),
                    'days_mask' => (string)($block['days_mask'] ?? '1,2,3,4,5,6,7'),
                    'is_active' => (int)($block['is_active'] ?? 1),
                    'priority' => (int)($block['priority'] ?? 100),
                    'display_order' => (int)($block['display_order'] ?? 0),
                    'loops' => []
                ];
            }
            $blocks_stmt->close();

            $all_modules_stmt = $conn->prepare("SELECT kgm.*, m.name as module_name, m.module_key
                                                FROM kiosk_group_modules kgm
                                                JOIN modules m ON kgm.module_id = m.id
                                                WHERE kgm.group_id = ? AND kgm.is_active = 1
                                                ORDER BY COALESCE(kgm.time_block_id, 0), kgm.display_order, kgm.id");
            $all_modules_stmt->bind_param("i", $group_id);
            $all_modules_stmt->execute();
            $all_modules_result = $all_modules_stmt->get_result();

            $base_loop = [];
            while ($row = $all_modules_result->fetch_assoc()) {
                $runtime_payload = edudisplej_module_runtime_payload_for_sync((string)($row['module_key'] ?? ''));
                $module_item = [
                    'module_id' => (int)$row['module_id'],
                    'module_name' => $row['module_name'],
                    'module_key' => $row['module_key'],
                    'duration_seconds' => (int)$row['duration_seconds'],
                    'display_order' => (int)$row['display_order'],
                    'settings' => edudisplej_optimize_module_settings_for_sync($conn, (int)$company_id, (string)($row['module_key'] ?? ''), $row['settings'] ? json_decode($row['settings'], true) : []),
                    'source' => 'group',
                    'module_folder' => $runtime_payload['module_folder'],
                    'module_renderer' => $runtime_payload['module_renderer'],
                    'module_main_file' => $runtime_payload['module_main_file'],
                ];

                $module_key = (string)($row['module_key'] ?? '');
                if ($module_key !== '' && !isset($preload_map[$module_key])) {
                    $preload_map[$module_key] = [
                        'module_key' => $module_key,
                        'name' => (string)($row['module_name'] ?? $module_key),
                        'module_folder' => $runtime_payload['module_folder'],
                        'module_renderer' => $runtime_payload['module_renderer'],
                        'module_main_file' => $runtime_payload['module_main_file'],
                    ];
                }

                $time_block_id = (int)($row['time_block_id'] ?? 0);
                if ($time_block_id > 0 && isset($block_map[$time_block_id])) {
                    $block_map[$time_block_id]['loops'][] = $module_item;
                } else {
                    $base_loop[] = $module_item;
                }
            }
            $all_modules_stmt->close();

            $offline_plan['base_loop'] = $base_loop;
            $offline_plan['time_blocks'] = array_values($block_map);

                if ($has_blocks && $active_block_id !== null && isset($block_map[$active_block_id])) {
                    $loop_config = $block_map[$active_block_id]['loops'];
                } else {
                    $loop_config = $base_loop;
                }

                if (empty($loop_config) && !empty($base_loop)) {
                    $loop_config = $base_loop;
                }

                $response['active_time_block_id'] = $active_block_id;
                $response['active_scope'] = $active_block_id !== null ? 'block' : 'base';
            }
        }

        if ($group_id) {
            api_require_group_company($conn, $api_company, $group_id);
        }
    }
    
    // Determine loop last update based on source
    // Use MAX of created_at to detect module changes
    $loop_last_update = null;
    if ($config_source === 'kiosk') {
        $stmt = $conn->prepare("SELECT MAX(updated_at) as updated_at, MAX(created_at) as created_at 
                                FROM kiosk_modules 
                                WHERE kiosk_id = ? AND is_active = 1");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $loop_last_update = $row['updated_at'] ?? $row['created_at'] ?? null;
        $stmt->close();
    } else if ($group_id) {
        $stmt = $conn->prepare("SELECT
                                    MAX(kgm.updated_at) as updated_at,
                                    MAX(kgm.created_at) as created_at,
                                    (SELECT klp.updated_at FROM kiosk_group_loop_plans klp WHERE klp.group_id = ? LIMIT 1) as plan_updated_at
                                FROM kiosk_group_modules kgm
                                WHERE kgm.group_id = ? AND kgm.is_active = 1");
        $stmt->bind_param("ii", $group_id, $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $module_update = $row['updated_at'] ?? $row['created_at'] ?? null;
        $plan_update = $row['plan_updated_at'] ?? null;
        $loop_last_update = $module_update;
        if ($plan_update && (!$loop_last_update || strtotime($plan_update) > strtotime($loop_last_update))) {
            $loop_last_update = $plan_update;
        }
        $stmt->close();
    }
    if (!$loop_last_update) {
        $loop_last_update = date('Y-m-d H:i:s');
    }
    
    closeDbConnection($conn);
    
    $response['success'] = true;
    $response['kiosk_id'] = $kiosk_id;
    $response['device_id'] = $device_id;
    $response['loop_config'] = $loop_config;
    $response['module_count'] = count($loop_config);
    $response['preload_modules'] = array_values($preload_map);
    $response['offline_plan'] = $offline_plan;
    $response['loop_plan_version'] = $loop_plan_version;
    $response['loop_plan_updated_at'] = $loop_plan_updated_at;
    if (!isset($response['active_scope'])) {
        $response['active_scope'] = 'base';
    }
    $response['loop_last_update'] = $loop_last_update;
    
    // Use JSON encoding options to prevent output truncation
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Kiosk Loop API Error: ' . $e->getMessage());
    $response['message'] = 'Server error: ' . $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
