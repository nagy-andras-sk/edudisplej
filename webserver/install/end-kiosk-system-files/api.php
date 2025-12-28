<?php
// ============================================================================
// EduDisplej System Files API
// ============================================================================
// Ez az endpoint zip fájlban rekurzívan kiszolgálja az aktuális rendszerfájlokat
// This endpoint serves all system files recursively as a zip
// ============================================================================

$baseDir = __DIR__;
$zipName = 'system-files.zip';
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('edudisplej_', true) . '.zip';

// Csak GET-et engedélyezünk
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

function addDirToZip($dir, $zip, $basePathLen) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        $filePath = $file->getPathname();
        $localPath = substr($filePath, $basePathLen + 1); // relatív útvonal
        if ($file->isFile()) {
            $zip->addFile($filePath, $localPath);
        }
    }
}

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    echo 'Could not create zip file';
    exit;
}

addDirToZip($baseDir, $zip, strlen($baseDir));
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename=' . $zipName);
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;