
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

    // Disable output buffering to prevent truncation
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Disable time limit for large files
    set_time_limit(0);
    
    $fileSize = filesize($filePath);
    if ($fileSize === false) {
        http_response_code(500);
        echo "Error getting file size: " . $file;
        exit;
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Stream file in chunks to prevent memory issues and ensure complete transfer
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        echo "Error opening file: " . $file;
        exit;
    }
    
    $chunkSize = 8192; // 8KB chunks
    while (!feof($handle)) {
        $buffer = fread($handle, $chunkSize);
        if ($buffer === false) {
            fclose($handle);
            http_response_code(500);
            echo "Error reading file: " . $file;
            exit;
        }
        echo $buffer;
        flush(); // Force output to be sent immediately
    }
    
    fclose($handle);
    exit;
}

echo "Invalid request. Use ?getfiles or ?streamfile=filename";
?>
