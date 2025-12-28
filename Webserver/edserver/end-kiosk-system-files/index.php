<?php
// ============================================================================
// EduDisplej System Files Index
// ============================================================================
// Ez az endpoint zip fájlban kiszolgálja az aktuális rendszerfájlokat
// This endpoint serves the current system files as a zip
// ============================================================================

$baseDir = __DIR__;
$filesDir = $baseDir;
$zipName = 'system-files.zip';
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('edudisplej_', true) . '.zip';

// Csak GET-et engedélyezünk
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// Fájlok listája (init és társai)
$includeFiles = [
    'common.sh',
    'display.sh',
    'kiosk.sh',
    'language.sh',
    'network.sh',
    'xclient.sh',
];

$initDir = $filesDir . '/init';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    echo 'Could not create zip file';
    exit;
}

foreach ($includeFiles as $file) {
    $filePath = $initDir . '/' . $file;
    if (file_exists($filePath)) {
        $zip->addFile($filePath, $file);
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename=' . $zipName);
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);

// Törlés a kiszolgálás után
unlink($tmpZip);
exit;
