<?php
/**
 * Legacy alias for group assignment.
 * Redirects permanently to the maintained group_kiosks page.
 */

session_start();

$query = $_GET;
$target = 'group_kiosks.php';
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 301);
exit();
