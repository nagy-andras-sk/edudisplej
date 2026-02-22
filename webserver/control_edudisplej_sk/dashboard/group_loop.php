<?php
/**
 * Backward-compatible entrypoint for Group Loop feature.
 * Delegates to the dedicated folder-based implementation.
 */

$target = 'group_loop/index.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target, true, 302);
exit();
