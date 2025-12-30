
<?php
$baseDir = __DIR__;

if (isset($_GET['getfiles'])) {
    foreach (scandir($baseDir) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (is_file($baseDir . '/' . $file)) {
            echo $file . ";" . filesize($baseDir . '/' . $file) . ";" . date('Y-m-d H:i:s', filemtime($baseDir . '/' . $file)) . "\n";
        }
    }
    exit;
}

if (isset($_GET['streamfile'])) {
    $file = basename($_GET['streamfile']);
    $filePath = $baseDir . '/' . $file;

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo "File not found.";
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

echo "Invalid request. Use ?getfiles or ?streamfile=filename";
?>
