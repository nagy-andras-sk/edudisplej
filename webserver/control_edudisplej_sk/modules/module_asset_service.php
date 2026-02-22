<?php

function edudisplej_module_asset_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS module_asset_store (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        group_id INT NOT NULL,
        module_key VARCHAR(64) NOT NULL,
        asset_kind VARCHAR(64) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        storage_rel_path VARCHAR(255) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        created_by INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_module (company_id, module_key),
        INDEX idx_group_module (group_id, module_key),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $neededColumns = [
        'company_id' => "ALTER TABLE module_asset_store ADD COLUMN company_id INT NOT NULL DEFAULT 0 AFTER id",
        'group_id' => "ALTER TABLE module_asset_store ADD COLUMN group_id INT NOT NULL DEFAULT 0 AFTER company_id",
        'module_key' => "ALTER TABLE module_asset_store ADD COLUMN module_key VARCHAR(64) NOT NULL DEFAULT '' AFTER group_id",
        'asset_kind' => "ALTER TABLE module_asset_store ADD COLUMN asset_kind VARCHAR(64) NOT NULL DEFAULT 'file' AFTER module_key",
        'is_active' => "ALTER TABLE module_asset_store ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER created_by",
        'updated_at' => "ALTER TABLE module_asset_store ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($neededColumns as $field => $ddl) {
        $check = $conn->query("SHOW COLUMNS FROM module_asset_store LIKE '" . $conn->real_escape_string($field) . "'");
        if ($check && $check->num_rows === 0) {
            $conn->query($ddl);
        }
    }
}

function edudisplej_module_asset_storage_paths(int $companyId, string $moduleKey): array
{
    $companyPart = 'company_' . max(0, $companyId);
    $modulePart = preg_replace('/[^a-z0-9_.-]/', '-', strtolower(trim($moduleKey)));
    if ($modulePart === '' || $modulePart === '-' || $modulePart === '.') {
        $modulePart = 'unknown-module';
    }

    $relDir = 'uploads/companies/' . $companyPart . '/modules/' . $modulePart;
    $rootAbs = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $absDir = rtrim(str_replace('\\', '/', $rootAbs), '/') . '/' . $relDir;

    return [
        'rel_dir' => $relDir,
        'abs_dir' => $absDir,
    ];
}

function edudisplej_module_asset_public_url(string $relPath): string
{
    return edudisplej_module_asset_api_url_by_path($relPath, null);
}

function edudisplej_current_request_api_token(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    $tokenFromQuery = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $tokenFromHeader = (string)($headers['X-API-Token'] ?? $headers['x-api-token'] ?? '');

    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = trim((string)$matches[1]);
        return $token !== '' ? $token : null;
    }

    if ($tokenFromHeader !== '') {
        return trim($tokenFromHeader);
    }

    if ($tokenFromQuery !== '') {
        return trim($tokenFromQuery);
    }

    return null;
}

function edudisplej_module_asset_extract_rel_path(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    $candidate = $raw;
    if (preg_match('/^https?:\/\//i', $candidate)) {
        $parsedPath = (string)parse_url($candidate, PHP_URL_PATH);
        $candidate = $parsedPath !== '' ? $parsedPath : $candidate;
    } else {
        $parsedPath = (string)parse_url($candidate, PHP_URL_PATH);
        if ($parsedPath !== '') {
            $candidate = $parsedPath;
        }
    }

    $candidate = urldecode($candidate);
    $candidate = str_replace('\\', '/', $candidate);
    $candidate = ltrim($candidate, '/');

    $needle = 'uploads/companies/';
    $pos = stripos($candidate, $needle);
    if ($pos === false) {
        return '';
    }

    $normalized = substr($candidate, $pos);
    $normalized = preg_replace('#/+#', '/', (string)$normalized);

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    return $normalized;
}

function edudisplej_module_asset_api_url_by_id(int $assetId, ?string $token = null): string
{
    $buildUrl = function (array $params): string {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $relative = '../../api/group_loop/module_asset_file.php?' . $query;

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $appPos = strpos($scriptName, '/control_edudisplej_sk/');
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($appPos === false || $host === '') {
            return $relative;
        }

        $basePath = substr($scriptName, 0, $appPos + strlen('/control_edudisplej_sk'));
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host . $basePath . '/api/group_loop/module_asset_file.php?' . $query;
    };

    $params = ['asset_id' => $assetId];
    if (!empty($token)) {
        $params['token'] = $token;
    }

    return $buildUrl($params);
}

function edudisplej_module_asset_api_url_by_path(string $relPath, ?string $token = null): string
{
    $normalizedPath = edudisplej_module_asset_extract_rel_path($relPath);
    if ($normalizedPath === '') {
        return '';
    }

    $params = ['path' => $normalizedPath];
    if (!empty($token)) {
        $params['token'] = $token;
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $relative = '../../api/group_loop/module_asset_file.php?' . $query;

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $appPos = strpos($scriptName, '/control_edudisplej_sk/');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($appPos === false || $host === '') {
        return $relative;
    }

    $basePath = substr($scriptName, 0, $appPos + strlen('/control_edudisplej_sk'));
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . $basePath . '/api/group_loop/module_asset_file.php?' . $query;
}

function edudisplej_module_asset_normalize_url_for_api(string $url, ?string $token = null): string
{
    $candidate = trim($url);
    if ($candidate === '') {
        return '';
    }

    if (stripos($candidate, 'module_asset_file.php') !== false) {
        if (!empty($token) && stripos($candidate, 'token=') === false) {
            $glue = (strpos($candidate, '?') === false) ? '?' : '&';
            return $candidate . $glue . 'token=' . rawurlencode($token);
        }
        return $candidate;
    }

    $byPath = edudisplej_module_asset_api_url_by_path($candidate, $token);
    if ($byPath !== '') {
        return $byPath;
    }

    return $candidate;
}
