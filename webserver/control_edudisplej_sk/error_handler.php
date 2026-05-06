<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo '<pre style="color:red;padding:1em;background:#fff2f2;border:1px solid red;">';
    echo htmlspecialchars(get_class($e) . ': ' . $e->getMessage()
        . "\n" . $e->getFile() . ':' . $e->getLine()
        . "\n\n" . $e->getTraceAsString());
    echo '</pre>';
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
