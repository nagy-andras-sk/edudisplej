<?php
$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$base_prefix = rtrim(dirname($script_name), '/');
if ($base_prefix === '.' || $base_prefix === '/') {
    $base_prefix = '';
}
$login_path = $base_prefix . '/login';

$token = trim($_GET['token'] ?? '');
if ($token !== '') {
    header('Location: ' . $login_path . '?token=' . urlencode($token), true, 302);
    exit();
}
header('Location: ' . $login_path . '?reset=1', true, 302);
exit();
