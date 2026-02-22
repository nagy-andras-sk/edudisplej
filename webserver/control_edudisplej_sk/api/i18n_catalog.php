<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../i18n.php';
require_once 'auth.php';

$requested_lang = edudisplej_normalize_lang($_GET['lang'] ?? $_POST['lang'] ?? '');
$lang = $requested_lang ?: edudisplej_get_lang();
$prefix = trim((string)($_GET['prefix'] ?? $_POST['prefix'] ?? ''));

$is_authenticated = !empty($_SESSION['user_id']);

if (!$is_authenticated) {
    try {
        $api_company = validate_api_token();
        $is_authenticated = !empty($api_company);
    } catch (Throwable $e) {
        $is_authenticated = false;
    }
}

if (!$is_authenticated) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$catalog = edudisplej_i18n_catalog($lang);

if ($prefix !== '') {
    $filtered = [];
    foreach ($catalog as $key => $value) {
        if (strpos((string)$key, $prefix) === 0) {
            $filtered[$key] = (string)$value;
        }
    }
    $catalog = $filtered;
}

echo json_encode([
    'success' => true,
    'lang' => $lang,
    'count' => count($catalog),
    'catalog' => $catalog,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
