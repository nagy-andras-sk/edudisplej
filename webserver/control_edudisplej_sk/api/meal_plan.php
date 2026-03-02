<?php
/**
 * API - Meal Plan (Étrend)
 * Public read endpoints + authenticated admin CRUD.
 */
session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';

header('Content-Type: application/json; charset=utf-8');

function edudisplej_meal_plan_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        site_key VARCHAR(80) NOT NULL,
        site_name VARCHAR(150) NOT NULL,
        base_url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_site_key (company_id, site_key),
        INDEX idx_company_active (company_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_institutions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        site_id INT NOT NULL,
        external_key VARCHAR(120) NOT NULL DEFAULT '',
        institution_name VARCHAR(220) NOT NULL,
        city VARCHAR(180) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_site_active (company_id, site_id, is_active),
        UNIQUE KEY uq_company_external (company_id, site_id, external_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        institution_id INT NOT NULL,
        menu_date DATE NOT NULL,
        breakfast TEXT NULL,
        snack_am TEXT NULL,
        lunch TEXT NULL,
        snack_pm TEXT NULL,
        dinner TEXT NULL,
        breakfast_rows_json LONGTEXT NULL,
        snack_am_rows_json LONGTEXT NULL,
        lunch_rows_json LONGTEXT NULL,
        snack_pm_rows_json LONGTEXT NULL,
        dinner_rows_json LONGTEXT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_institution_date (company_id, institution_id, menu_date),
        INDEX idx_company_institution_date (company_id, institution_id, menu_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $jsonColumns = [
        'breakfast_rows_json',
        'snack_am_rows_json',
        'lunch_rows_json',
        'snack_pm_rows_json',
        'dinner_rows_json',
    ];
    foreach ($jsonColumns as $columnName) {
        $columnSafe = preg_replace('/[^a-z0-9_]/i', '', (string)$columnName);
        if ($columnSafe === '') {
            continue;
        }
        $check = $conn->query("SHOW COLUMNS FROM meal_plan_items LIKE '" . $conn->real_escape_string($columnSafe) . "'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE meal_plan_items ADD COLUMN $columnSafe LONGTEXT NULL");
        }
    }

    $seed_check = $conn->query("SELECT id FROM meal_plan_sites WHERE company_id = 0 AND site_key = 'jedalen.sk' LIMIT 1");
    if ($seed_check && $seed_check->num_rows === 0) {
        $conn->query("INSERT INTO meal_plan_sites (company_id, site_key, site_name, base_url, is_active) VALUES (0, 'jedalen.sk', 'Jedalen.sk', 'https://www.jedalen.sk', 1)");
    }
}

function edudisplej_meal_plan_can_admin(): bool {
    return isset($_SESSION['user_id']) && edudisplej_can_edit_module_content();
}

function edudisplej_meal_plan_session_company_id(): int {
    $cid = (int)($_SESSION['company_id'] ?? 0);
    if ($cid > 0) {
        return $cid;
    }
    $acting = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    return $acting > 0 ? $acting : 0;
}

function edudisplej_meal_plan_public_company_id(): int {
    $requested = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
    if ($requested > 0) {
        return $requested;
    }
    return 0;
}

function edudisplej_meal_plan_response_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function edudisplej_meal_plan_trim_text($value, int $max = 12000): string {
    $text = trim((string)$value);
    if (mb_strlen($text, 'UTF-8') > $max) {
        $text = mb_substr($text, 0, $max, 'UTF-8');
    }
    return $text;
}

function edudisplej_meal_plan_normalize_site_key($value): string {
    $siteKey = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9._-]/', '', $siteKey);
}

function edudisplej_meal_plan_allowed_site_keys(): array {
    return ['jedalen.sk' => true];
}

function edudisplej_meal_plan_is_allowed_site_key(string $siteKey): bool {
    $allowed = edudisplej_meal_plan_allowed_site_keys();
    return isset($allowed[$siteKey]);
}

function edudisplej_meal_plan_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function edudisplej_meal_plan_extract_recipe_code(string $line): ?string {
    if (preg_match_all('/\b([0-9]{1,2}\.[0-9]{3})\b/u', (string)$line, $matches) && !empty($matches[1])) {
        $last = end($matches[1]);
        return is_string($last) && $last !== '' ? $last : null;
    }
    return null;
}

function edudisplej_meal_plan_recipe_category(?string $recipeCode): int {
    $code = trim((string)$recipeCode);
    if (!preg_match('/^([0-9]{1,2})\.[0-9]{3}$/', $code, $m)) {
        return 0;
    }
    return (int)$m[1];
}

function edudisplej_meal_plan_clean_display_meal_line(string $line): string {
    $value = trim((string)$line);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\((?:Allerg[ée]n(?:ek)?|Alerg[ée]ny(?:ek)?)\s*:[^)]*\)/iu', '', $value);
    $value = preg_replace('/\bALERG[ÉE]NY\s*:\s*.*$/iu', '', (string)$value);
    $value = preg_replace('/(?:Allerg[ée]n(?:ek)?|Alerg[ée]ny(?:ek)?)\s*:\s*[^\n\r;]+/iu', '', (string)$value);
    $value = preg_replace('/\b[0-9]{1,2}\.[0-9]{3}\b(?:\s*[,;:.\)\(\-])?/u', '', (string)$value);
    $value = preg_replace('/,\s*(?:Kysličník|Obilniny|Vajcia|Mlieko|Ryby|Zeler|Horčica|Orech|Sezam|S[oó]j|Lupina|M[aä]kk[ýy]še).*$/iu', '', (string)$value);
    $value = preg_replace('/^\d+\.\s*/u', '', (string)$value);
    $value = preg_replace('/,\s*,+/u', ', ', (string)$value);
    $value = preg_replace('/\s+,/u', ',', (string)$value);
    $value = preg_replace('/,\s*$/u', '', (string)$value);
    $value = preg_replace('/[ \t]{2,}/u', ' ', (string)$value);
    $value = preg_replace('/\s+\)\s*$/u', '', (string)$value);
    return trim((string)$value);
}

function edudisplej_meal_plan_parse_slot_rows(array $lines): array {
    $rows = [];
    $seen = [];
    foreach ($lines as $rawLine) {
        $line = trim((string)$rawLine);
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\r?\n/u', $line) ?: [$line];
        foreach ($parts as $partRaw) {
            $part = trim((string)$partRaw);
            if ($part === '' || preg_match('/^\d+\.\s*$/u', $part)) {
                continue;
            }

            $code = edudisplej_meal_plan_extract_recipe_code($part);
            $category = edudisplej_meal_plan_recipe_category($code);
            $text = edudisplej_meal_plan_clean_display_meal_line($part);
            if ($text === '') {
                continue;
            }

            $dedupKey = strtolower(preg_replace('/\s+/u', ' ', $text));
            if ($dedupKey === '' || isset($seen[$dedupKey])) {
                continue;
            }
            $seen[$dedupKey] = true;

            $rows[] = [
                'text' => $text,
                'recipe_code' => $code,
                'recipe_category' => $category,
                'is_drink' => ($category === 1),
                'is_soup' => ($category === 5),
            ];
        }
    }
    return $rows;
}

function edudisplej_meal_plan_sort_rows_by_categories(array $rows, array $categoryOrder): array {
    if (empty($rows)) {
        return [];
    }

    $weight = [];
    $base = 0;
    foreach ($categoryOrder as $cat) {
        $weight[(int)$cat] = $base;
        $base += 10;
    }
    $fallbackWeight = 10000;

    foreach ($rows as $idx => &$row) {
        $cat = (int)($row['recipe_category'] ?? 0);
        $row['_sort'] = ($weight[$cat] ?? $fallbackWeight) + $idx;
    }
    unset($row);

    usort($rows, static function (array $a, array $b): int {
        return ((int)($a['_sort'] ?? 0)) <=> ((int)($b['_sort'] ?? 0));
    });

    foreach ($rows as &$row) {
        unset($row['_sort']);
    }
    unset($row);

    return $rows;
}

function edudisplej_meal_plan_build_structured_slot_rows(string $slot, array $dayMeals): array {
    $slotKey = trim((string)$slot);
    $baseRows = edudisplej_meal_plan_parse_slot_rows((array)($dayMeals[$slotKey] ?? []));

    if ($slotKey === 'lunch') {
        return edudisplej_meal_plan_sort_rows_by_categories($baseRows, [5, 13, 14, 15, 17, 22, 23, 24, 1]);
    }

    if ($slotKey === 'breakfast') {
        $rows = edudisplej_meal_plan_sort_rows_by_categories($baseRows, [2, 3, 1]);
        $snackRows = edudisplej_meal_plan_parse_slot_rows((array)($dayMeals['snack_am'] ?? []));
        if (!empty($snackRows)) {
            $summaryParts = array_values(array_map(static function (array $row): string {
                return (string)($row['text'] ?? '');
            }, $snackRows));
            $summaryParts = array_values(array_filter($summaryParts, static function (string $value): bool {
                return trim($value) !== '';
            }));
            if (!empty($summaryParts)) {
                $rows[] = [
                    'text' => implode(', ', $summaryParts),
                    'recipe_code' => null,
                    'recipe_category' => 0,
                    'is_drink' => false,
                    'is_soup' => false,
                    'source_slot' => 'snack_am',
                    'is_joined_snack' => true,
                ];
            }
        }
        return $rows;
    }

    if ($slotKey === 'snack_am' || $slotKey === 'snack_pm') {
        return edudisplej_meal_plan_sort_rows_by_categories($baseRows, [3, 2, 1, 22, 23]);
    }

    if ($slotKey === 'dinner') {
        return edudisplej_meal_plan_sort_rows_by_categories($baseRows, [13, 14, 15, 17, 22, 23, 1]);
    }

    return $baseRows;
}

function edudisplej_meal_plan_json_decode_rows($raw): array {
    $text = trim((string)$raw);
    if ($text === '') {
        return [];
    }
    $parsed = json_decode($text, true);
    return is_array($parsed) ? $parsed : [];
}

function edudisplej_meal_plan_generate_external_key(string $institutionName, string $city): string {
    $base = strtolower(trim($institutionName . '-' . $city));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    if ($base === '') {
        $base = 'institution';
    }

    return '__auto__' . $base . '-' . substr(sha1($institutionName . '|' . $city . '|' . microtime(true) . '|' . random_int(1000, 9999)), 0, 12);
}

function edudisplej_meal_plan_institution_unique_key(array $row): string {
    $external = strtolower(trim((string)($row['external_key'] ?? '')));
    if ($external !== '') {
        return 'ext:' . $external;
    }

    $name = strtolower(trim((string)($row['institution_name'] ?? '')));
    $city = strtolower(trim((string)($row['city'] ?? '')));
    return 'name:' . $name . '|' . $city;
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'sites')));

try {
    $conn = getDbConnection();
    edudisplej_meal_plan_ensure_schema($conn);

    if ($action === 'sites') {
        $company_id = edudisplej_meal_plan_can_admin() ? edudisplej_meal_plan_session_company_id() : edudisplej_meal_plan_public_company_id();

        $stmt = $conn->prepare("SELECT id, site_key, site_name, base_url FROM meal_plan_sites WHERE is_active = 1 AND (company_id = 0 OR company_id = ?) ORDER BY company_id DESC, site_name ASC");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'site_key' => (string)$row['site_key'],
                'site_name' => (string)$row['site_name'],
                'base_url' => (string)($row['base_url'] ?? ''),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'institutions') {
        $company_id = edudisplej_meal_plan_can_admin() ? edudisplej_meal_plan_session_company_id() : edudisplej_meal_plan_public_company_id();
        $site_key = edudisplej_meal_plan_normalize_site_key($_GET['site_key'] ?? $_POST['site_key'] ?? '');

        if (!edudisplej_meal_plan_is_allowed_site_key($site_key)) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $site_stmt = $conn->prepare("SELECT id FROM meal_plan_sites WHERE site_key = ? AND is_active = 1 AND (company_id = 0 OR company_id = ?) ORDER BY company_id DESC LIMIT 1");
        $site_stmt->bind_param('si', $site_key, $company_id);
        $site_stmt->execute();
        $site = $site_stmt->get_result()->fetch_assoc();
        $site_stmt->close();

        $site_id = (int)($site['id'] ?? 0);
        if ($site_id <= 0) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $stmt = $conn->prepare("SELECT id, company_id, institution_name, city, external_key FROM meal_plan_institutions WHERE site_id = ? AND is_active = 1 AND (company_id = 0 OR company_id = ?) ORDER BY company_id DESC, institution_name ASC");
        $stmt->bind_param('ii', $site_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        $seen = [];
        while ($row = $result->fetch_assoc()) {
            $uniqueKey = edudisplej_meal_plan_institution_unique_key($row);
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;

            $items[] = [
                'id' => (int)$row['id'],
                'institution_name' => (string)$row['institution_name'],
                'city' => (string)($row['city'] ?? ''),
                'external_key' => (string)($row['external_key'] ?? ''),
            ];
        }
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'menu') {
        $company_id = edudisplej_meal_plan_can_admin() ? edudisplej_meal_plan_session_company_id() : edudisplej_meal_plan_public_company_id();
        $site_key = edudisplej_meal_plan_normalize_site_key($_GET['site_key'] ?? $_POST['site_key'] ?? '');
        $institution_id = (int)($_GET['institution_id'] ?? $_POST['institution_id'] ?? 0);
        $date_raw = trim((string)($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d')));
        $date_value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw) ? $date_raw : date('Y-m-d');
        $exact_date = edudisplej_meal_plan_truthy($_GET['exact_date'] ?? $_POST['exact_date'] ?? false);
        $source_type_raw = strtolower(trim((string)($_GET['source_type'] ?? $_POST['source_type'] ?? $_GET['source'] ?? $_POST['source'] ?? '')));
        $source_type = in_array($source_type_raw, ['manual', 'server'], true) ? $source_type_raw : '';

        $emptyMeta = [
            'server_data_available' => false,
            'pending_server_sync' => false,
        ];

        if ($institution_id <= 0 || $site_key === '' || !edudisplej_meal_plan_is_allowed_site_key($site_key)) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'data' => ['institution_name' => '', 'menu_date' => $date_value, 'meals' => []], 'meta' => $emptyMeta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $inst_stmt = $conn->prepare("SELECT mi.id, mi.institution_name
                                    FROM meal_plan_institutions mi
                                    INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
                                    WHERE mi.id = ? AND mi.is_active = 1 AND ms.site_key = ?
                                      AND (mi.company_id = 0 OR mi.company_id = ?)
                                      AND (ms.company_id = 0 OR ms.company_id = ?)
                                    LIMIT 1");
        $inst_stmt->bind_param('isii', $institution_id, $site_key, $company_id, $company_id);
        $inst_stmt->execute();
        $inst = $inst_stmt->get_result()->fetch_assoc();
        $inst_stmt->close();

        if (!$inst) {
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'data' => ['institution_name' => '', 'menu_date' => $date_value, 'meals' => []], 'meta' => $emptyMeta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $serverDataAvailable = false;
        $availability_stmt = $conn->prepare("SELECT id FROM meal_plan_items
                                            WHERE institution_id = ?
                                              AND (company_id = 0 OR company_id = ?)
                                              AND source_type IN ('server', 'auto_jedalen')
                                            LIMIT 1");
        if ($availability_stmt) {
            $availability_stmt->bind_param('ii', $institution_id, $company_id);
            $availability_stmt->execute();
            $serverDataAvailable = (bool)$availability_stmt->get_result()->fetch_assoc();
            $availability_stmt->close();
        }

                $menu_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                                        FROM meal_plan_items
                                                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date = ?
                                                                            AND (
                                                                                        ? = ''
                                                                                        OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                                                        OR source_type = ?
                                                                                    )
                                                                        ORDER BY company_id DESC
                                                                        LIMIT 1");
                $source_type_effective = $source_type;
                $menu_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
        $menu_stmt->execute();
        $menu = $menu_stmt->get_result()->fetch_assoc();
        $menu_stmt->close();

                if (!$menu && $source_type === 'manual') {
                        $source_type_effective = 'server';
                        $menu_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                                                FROM meal_plan_items
                                                                                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date = ?
                                                                                    AND (
                                                                                                ? = ''
                                                                                                OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                                                                OR source_type = ?
                                                                                            )
                                                                                ORDER BY company_id DESC
                                                                                LIMIT 1");
                        $menu_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
                        $menu_stmt->execute();
                        $menu = $menu_stmt->get_result()->fetch_assoc();
                        $menu_stmt->close();
                }

        if (!$menu && !$exact_date) {
            $future_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                            FROM meal_plan_items
                                            WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date >= ?
                                                AND (
                                                    ? = ''
                                                    OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                    OR source_type = ?
                                                )
                                            ORDER BY menu_date ASC, company_id DESC
                                            LIMIT 1");
            $future_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
            $future_stmt->execute();
            $menu = $future_stmt->get_result()->fetch_assoc();
            $future_stmt->close();

            if (!$menu && $source_type === 'manual' && $source_type_effective !== 'server') {
                $source_type_effective = 'server';
                $future_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                FROM meal_plan_items
                                                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date >= ?
                                                    AND (
                                                        ? = ''
                                                        OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                        OR source_type = ?
                                                    )
                                                ORDER BY menu_date ASC, company_id DESC
                                                LIMIT 1");
                $future_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
                $future_stmt->execute();
                $menu = $future_stmt->get_result()->fetch_assoc();
                $future_stmt->close();
            }

            if (!$menu) {
                $fallback_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                FROM meal_plan_items
                                                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date <= ?
                                                    AND (
                                                        ? = ''
                                                        OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                        OR source_type = ?
                                                    )
                                                ORDER BY menu_date DESC, company_id DESC
                                                LIMIT 1");
                $fallback_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
                $fallback_stmt->execute();
                $menu = $fallback_stmt->get_result()->fetch_assoc();
                $fallback_stmt->close();
            }

            if (!$menu && $source_type === 'manual' && $source_type_effective !== 'server') {
                $source_type_effective = 'server';
                $fallback_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                FROM meal_plan_items
                                                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date <= ?
                                                    AND (
                                                        ? = ''
                                                        OR (? = 'server' AND source_type IN ('server', 'auto_jedalen'))
                                                        OR source_type = ?
                                                    )
                                                ORDER BY menu_date DESC, company_id DESC
                                                LIMIT 1");
                $fallback_stmt->bind_param('iissss', $institution_id, $company_id, $date_value, $source_type_effective, $source_type_effective, $source_type_effective);
                $fallback_stmt->execute();
                $menu = $fallback_stmt->get_result()->fetch_assoc();
                $fallback_stmt->close();
            }

            if ($menu) {
                $requestedDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date_value);
                $menuDateObj = DateTimeImmutable::createFromFormat('Y-m-d', (string)($menu['menu_date'] ?? ''));
                if ($requestedDateObj && $menuDateObj) {
                    $dayDiff = abs((int)$requestedDateObj->diff($menuDateObj)->format('%r%a'));
                    if ($dayDiff > 14) {
                        $menu = null;
                    }
                }
            }
        }

        $menuHasRenderableContent = static function (?array $row): bool {
            if (!$row) {
                return false;
            }

            $textFields = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];
            foreach ($textFields as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') {
                    return true;
                }
            }

            $jsonFields = ['breakfast_rows_json', 'snack_am_rows_json', 'lunch_rows_json', 'snack_pm_rows_json', 'dinner_rows_json'];
            foreach ($jsonFields as $field) {
                $raw = trim((string)($row[$field] ?? ''));
                if ($raw === '' || $raw === 'null' || $raw === '[]') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return true;
                }
            }

            return false;
        };

        if ($source_type === 'server' && (!$menu || !$menuHasRenderableContent($menu))) {
            $manualMenu = null;

            $manual_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                            FROM meal_plan_items
                                            WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date = ?
                                                AND source_type = 'manual'
                                            ORDER BY company_id DESC
                                            LIMIT 1");
            if ($manual_stmt) {
                $manual_stmt->bind_param('iis', $institution_id, $company_id, $date_value);
                $manual_stmt->execute();
                $manualMenu = $manual_stmt->get_result()->fetch_assoc();
                $manual_stmt->close();
            }

            if (!$manualMenu && !$exact_date) {
                $manual_future_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                        FROM meal_plan_items
                                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date >= ?
                                                            AND source_type = 'manual'
                                                        ORDER BY menu_date ASC, company_id DESC
                                                        LIMIT 1");
                if ($manual_future_stmt) {
                    $manual_future_stmt->bind_param('iis', $institution_id, $company_id, $date_value);
                    $manual_future_stmt->execute();
                    $manualMenu = $manual_future_stmt->get_result()->fetch_assoc();
                    $manual_future_stmt->close();
                }
            }

            if (!$manualMenu && !$exact_date) {
                $manual_fallback_stmt = $conn->prepare("SELECT menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                                        FROM meal_plan_items
                                                        WHERE institution_id = ? AND (company_id = 0 OR company_id = ?) AND menu_date <= ?
                                                            AND source_type = 'manual'
                                                        ORDER BY menu_date DESC, company_id DESC
                                                        LIMIT 1");
                if ($manual_fallback_stmt) {
                    $manual_fallback_stmt->bind_param('iis', $institution_id, $company_id, $date_value);
                    $manual_fallback_stmt->execute();
                    $manualMenu = $manual_fallback_stmt->get_result()->fetch_assoc();
                    $manual_fallback_stmt->close();
                }
            }

            if ($manualMenu && $menuHasRenderableContent($manualMenu)) {
                $menu = $manualMenu;
            }
        }

        $showBreakfast = in_array(strtolower((string)($_GET['show_breakfast'] ?? $_POST['show_breakfast'] ?? '1')), ['1', 'true', 'yes', 'on'], true);
        $showSnackAm = in_array(strtolower((string)($_GET['show_snack_am'] ?? $_POST['show_snack_am'] ?? '1')), ['1', 'true', 'yes', 'on'], true);
        $showLunch = in_array(strtolower((string)($_GET['show_lunch'] ?? $_POST['show_lunch'] ?? '1')), ['1', 'true', 'yes', 'on'], true);
        $showSnackPm = in_array(strtolower((string)($_GET['show_snack_pm'] ?? $_POST['show_snack_pm'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
        $showDinner = in_array(strtolower((string)($_GET['show_dinner'] ?? $_POST['show_dinner'] ?? '0')), ['1', 'true', 'yes', 'on'], true);

        $meals = [];
        if ($showBreakfast) { $meals[] = ['key' => 'breakfast', 'label' => 'Reggeli', 'text' => (string)($menu['breakfast'] ?? ''), 'structured_rows' => edudisplej_meal_plan_json_decode_rows($menu['breakfast_rows_json'] ?? '')]; }
        if ($showSnackAm) { $meals[] = ['key' => 'snack_am', 'label' => 'Tízórai', 'text' => (string)($menu['snack_am'] ?? ''), 'structured_rows' => edudisplej_meal_plan_json_decode_rows($menu['snack_am_rows_json'] ?? '')]; }
        if ($showLunch) { $meals[] = ['key' => 'lunch', 'label' => 'Ebéd', 'text' => (string)($menu['lunch'] ?? ''), 'structured_rows' => edudisplej_meal_plan_json_decode_rows($menu['lunch_rows_json'] ?? '')]; }
        if ($showSnackPm) { $meals[] = ['key' => 'snack_pm', 'label' => 'Uzsonna', 'text' => (string)($menu['snack_pm'] ?? ''), 'structured_rows' => edudisplej_meal_plan_json_decode_rows($menu['snack_pm_rows_json'] ?? '')]; }
        if ($showDinner) { $meals[] = ['key' => 'dinner', 'label' => 'Vacsora', 'text' => (string)($menu['dinner'] ?? ''), 'structured_rows' => edudisplej_meal_plan_json_decode_rows($menu['dinner_rows_json'] ?? '')]; }

        $data = [
            'institution_name' => (string)($inst['institution_name'] ?? ''),
            'menu_date' => (string)($menu['menu_date'] ?? $date_value),
            'meals' => $meals,
            'source_type' => (string)($menu['source_type'] ?? ''),
            'updated_at' => (string)($menu['updated_at'] ?? ''),
        ];

        $meta = [
            'server_data_available' => $serverDataAvailable,
            'pending_server_sync' => ($source_type === 'server' && !$serverDataAvailable),
        ];

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'data' => $data, 'meta' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if (!edudisplej_meal_plan_can_admin()) {
        closeDbConnection($conn);
        edudisplej_meal_plan_response_error('Hozzáférés megtagadva', 403);
    }

    $company_id = edudisplej_meal_plan_session_company_id();
    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'admin_sites') {
        $stmt = $conn->prepare("SELECT id, company_id, site_key, site_name, base_url, is_active FROM meal_plan_sites WHERE company_id IN (0, ?) ORDER BY company_id DESC, site_name ASC");
        $stmt->bind_param('i', $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'site_key' => (string)$row['site_key'],
                'site_name' => (string)$row['site_name'],
                'base_url' => (string)($row['base_url'] ?? ''),
                'is_active' => (int)($row['is_active'] ?? 0),
                'is_global' => ((int)($row['company_id'] ?? 0) === 0)
            ];
        }
        $stmt->close();
        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'admin_institutions') {
        $site_id = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, company_id, site_id, external_key, institution_name, city, is_active FROM meal_plan_institutions WHERE site_id = ? AND company_id IN (0, ?) ORDER BY company_id DESC, institution_name ASC");
        $stmt->bind_param('ii', $site_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'site_id' => (int)$row['site_id'],
                'external_key' => (string)($row['external_key'] ?? ''),
                'institution_name' => (string)$row['institution_name'],
                'city' => (string)($row['city'] ?? ''),
                'is_active' => (int)($row['is_active'] ?? 0),
                'is_global' => ((int)($row['company_id'] ?? 0) === 0),
            ];
        }
        $stmt->close();
        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'menus') {
        $institution_id = (int)($_GET['institution_id'] ?? $_POST['institution_id'] ?? 0);
        $from_date = trim((string)($_GET['from_date'] ?? $_POST['from_date'] ?? date('Y-m-d')));
        $to_date = trim((string)($_GET['to_date'] ?? $_POST['to_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) { $from_date = date('Y-m-d'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) { $to_date = date('Y-m-d'); }

        $stmt = $conn->prepare("SELECT id, menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type, updated_at
                                FROM meal_plan_items
                                WHERE institution_id = ? AND company_id = ? AND menu_date BETWEEN ? AND ?
                                ORDER BY menu_date ASC");
        $stmt->bind_param('iiss', $institution_id, $company_id, $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'menu_date' => (string)$row['menu_date'],
                'breakfast' => (string)($row['breakfast'] ?? ''),
                'snack_am' => (string)($row['snack_am'] ?? ''),
                'lunch' => (string)($row['lunch'] ?? ''),
                'snack_pm' => (string)($row['snack_pm'] ?? ''),
                'dinner' => (string)($row['dinner'] ?? ''),
                'breakfast_rows' => edudisplej_meal_plan_json_decode_rows($row['breakfast_rows_json'] ?? ''),
                'snack_am_rows' => edudisplej_meal_plan_json_decode_rows($row['snack_am_rows_json'] ?? ''),
                'lunch_rows' => edudisplej_meal_plan_json_decode_rows($row['lunch_rows_json'] ?? ''),
                'snack_pm_rows' => edudisplej_meal_plan_json_decode_rows($row['snack_pm_rows_json'] ?? ''),
                'dinner_rows' => edudisplej_meal_plan_json_decode_rows($row['dinner_rows_json'] ?? ''),
                'source_type' => (string)($row['source_type'] ?? 'manual'),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();
        closeDbConnection($conn);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_site') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $site_key = edudisplej_meal_plan_normalize_site_key($input['site_key'] ?? '');
        $site_name = edudisplej_meal_plan_trim_text($input['site_name'] ?? '', 150);
        $base_url = edudisplej_meal_plan_trim_text($input['base_url'] ?? '', 500);
        $is_active = !empty($input['is_active']) ? 1 : 0;

        if ($site_key === '' || $site_name === '') {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Hiányzó oldal azonosító vagy név');
        }

        if (!edudisplej_meal_plan_is_allowed_site_key($site_key)) {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Jelenleg csak a jedalen.sk forrás támogatott.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE meal_plan_sites SET site_key = ?, site_name = ?, base_url = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->bind_param('sssiii', $site_key, $site_name, $base_url, $is_active, $id, $company_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO meal_plan_sites (company_id, site_key, site_name, base_url, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('isssi', $company_id, $site_key, $site_name, $base_url, $is_active);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_institution') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $id = (int)($input['id'] ?? 0);
        $site_id = (int)($input['site_id'] ?? 0);
        $external_key = edudisplej_meal_plan_trim_text($input['external_key'] ?? '', 120);
        $institution_name = edudisplej_meal_plan_trim_text($input['institution_name'] ?? '', 220);
        $city = edudisplej_meal_plan_trim_text($input['city'] ?? '', 180);
        $is_active = !empty($input['is_active']) ? 1 : 0;

        if ($site_id <= 0 || $institution_name === '') {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Hiányzó site vagy intézmény név');
        }

        $site_stmt = $conn->prepare("SELECT id, site_key FROM meal_plan_sites WHERE id = ? AND (company_id = 0 OR company_id = ?) LIMIT 1");
        $site_stmt->bind_param('ii', $site_id, $company_id);
        $site_stmt->execute();
        $site_row = $site_stmt->get_result()->fetch_assoc();
        $site_stmt->close();

        if (!$site_row) {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('A kiválasztott forrás oldal nem érhető el ennél az intézménynél.');
        }

        $site_key = edudisplej_meal_plan_normalize_site_key($site_row['site_key'] ?? '');
        if (!edudisplej_meal_plan_is_allowed_site_key($site_key)) {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Jelenleg csak a jedalen.sk forrás támogatott.');
        }

        if ($external_key === '') {
            $external_key = edudisplej_meal_plan_generate_external_key($institution_name, $city);
        }

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE meal_plan_institutions SET site_id = ?, external_key = ?, institution_name = ?, city = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->bind_param('isssiii', $site_id, $external_key, $institution_name, $city, $is_active, $id, $company_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO meal_plan_institutions (company_id, site_id, external_key, institution_name, city, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisssi', $company_id, $site_id, $external_key, $institution_name, $city, $is_active);
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'save_menu') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($payload) ? $payload : $_POST;

        $institution_id = (int)($input['institution_id'] ?? 0);
        $menu_date = trim((string)($input['menu_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $menu_date)) {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Érvénytelen dátum');
        }

        $breakfast = edudisplej_meal_plan_trim_text($input['breakfast'] ?? '', 12000);
        $snack_am = edudisplej_meal_plan_trim_text($input['snack_am'] ?? '', 12000);
        $lunch = edudisplej_meal_plan_trim_text($input['lunch'] ?? '', 12000);
        $snack_pm = edudisplej_meal_plan_trim_text($input['snack_pm'] ?? '', 12000);
        $dinner = edudisplej_meal_plan_trim_text($input['dinner'] ?? '', 12000);

        $dayMeals = [
            'breakfast' => preg_split('/\r?\n/u', $breakfast) ?: [],
            'snack_am' => preg_split('/\r?\n/u', $snack_am) ?: [],
            'lunch' => preg_split('/\r?\n/u', $lunch) ?: [],
            'snack_pm' => preg_split('/\r?\n/u', $snack_pm) ?: [],
            'dinner' => preg_split('/\r?\n/u', $dinner) ?: [],
        ];
        $breakfastRowsJson = json_encode(edudisplej_meal_plan_build_structured_slot_rows('breakfast', $dayMeals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $snackAmRowsJson = json_encode(edudisplej_meal_plan_build_structured_slot_rows('snack_am', $dayMeals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lunchRowsJson = json_encode(edudisplej_meal_plan_build_structured_slot_rows('lunch', $dayMeals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $snackPmRowsJson = json_encode(edudisplej_meal_plan_build_structured_slot_rows('snack_pm', $dayMeals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dinnerRowsJson = json_encode(edudisplej_meal_plan_build_structured_slot_rows('dinner', $dayMeals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($institution_id <= 0) {
            closeDbConnection($conn);
            edudisplej_meal_plan_response_error('Hiányzó intézmény');
        }

                $inst_stmt = $conn->prepare("SELECT mi.id
                                                                        FROM meal_plan_institutions mi
                                                                        INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
                                                                        WHERE mi.id = ?
                                                                            AND (mi.company_id = 0 OR mi.company_id = ?)
                                                                            AND (ms.company_id = 0 OR ms.company_id = ?)
                                                                            AND ms.is_active = 1
                                                                            AND mi.is_active = 1
                                                                        LIMIT 1");
                $inst_stmt->bind_param('iii', $institution_id, $company_id, $company_id);
                $inst_stmt->execute();
                $inst_row = $inst_stmt->get_result()->fetch_assoc();
                $inst_stmt->close();

                if (!$inst_row) {
                        closeDbConnection($conn);
                        edudisplej_meal_plan_response_error('A kiválasztott intézmény nem érhető el.');
                }

        $stmt = $conn->prepare("INSERT INTO meal_plan_items (company_id, institution_id, menu_date, breakfast, snack_am, lunch, snack_pm, dinner, breakfast_rows_json, snack_am_rows_json, lunch_rows_json, snack_pm_rows_json, dinner_rows_json, source_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual')
                    ON DUPLICATE KEY UPDATE breakfast = VALUES(breakfast), snack_am = VALUES(snack_am), lunch = VALUES(lunch), snack_pm = VALUES(snack_pm), dinner = VALUES(dinner), breakfast_rows_json = VALUES(breakfast_rows_json), snack_am_rows_json = VALUES(snack_am_rows_json), lunch_rows_json = VALUES(lunch_rows_json), snack_pm_rows_json = VALUES(snack_pm_rows_json), dinner_rows_json = VALUES(dinner_rows_json), source_type = 'manual', updated_at = CURRENT_TIMESTAMP");
        $stmt->bind_param('iisssssssssss', $company_id, $institution_id, $menu_date, $breakfast, $snack_am, $lunch, $snack_pm, $dinner, $breakfastRowsJson, $snackAmRowsJson, $lunchRowsJson, $snackPmRowsJson, $dinnerRowsJson);
        $stmt->execute();
        $stmt->close();

        closeDbConnection($conn);
        echo json_encode(['success' => true, 'message' => 'Étrend mentve'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    closeDbConnection($conn);
    edudisplej_meal_plan_response_error('Ismeretlen művelet');
} catch (Throwable $e) {
    error_log('api/meal_plan.php: ' . $e->getMessage());
    edudisplej_meal_plan_response_error('Szerver hiba', 500);
}
