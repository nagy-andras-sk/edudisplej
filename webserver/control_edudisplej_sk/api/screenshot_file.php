<?php
/**
 * Protected Screenshot File Endpoint
 * Serves screenshots only for authenticated users within their company.
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit();
}

$company_id = $_SESSION['company_id'] ?? null;
$kiosk_id = intval($_GET['kiosk_id'] ?? 0);
$log_id = intval($_GET['log_id'] ?? 0);

if (!$company_id || $kiosk_id <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit();
}

function extract_screenshot_filename(?string $rawPath): ?string {
    if ($rawPath === null) {
        return null;
    }

    $path = trim($rawPath);
    if ($path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $basename = basename($path);
    if ($basename === '' || !preg_match('/\.(png|jpe?g|webp|gif)$/i', $basename)) {
        return null;
    }

    return $basename;
}

try {
    $conn = getDbConnection();

    $verify_stmt = $conn->prepare('SELECT id, screenshot_url FROM kiosks WHERE id = ? AND company_id = ? LIMIT 1');
    $verify_stmt->bind_param('ii', $kiosk_id, $company_id);
    $verify_stmt->execute();
    $kiosk = $verify_stmt->get_result()->fetch_assoc();
    $verify_stmt->close();

    if (!$kiosk) {
        http_response_code(403);
        echo 'Access denied';
        exit();
    }

    $filenameCandidates = [];

    if ($log_id > 0) {
        $log_stmt = $conn->prepare("SELECT details FROM sync_logs WHERE id = ? AND kiosk_id = ? AND action = 'screenshot' LIMIT 1");
        $log_stmt->bind_param('ii', $log_id, $kiosk_id);
        $log_stmt->execute();
        $log = $log_stmt->get_result()->fetch_assoc();
        $log_stmt->close();

        if ($log) {
            $details = json_decode((string)($log['details'] ?? ''), true);
            if (is_array($details) && !empty($details['filename'])) {
                $candidate = extract_screenshot_filename((string)$details['filename']);
                if ($candidate !== null) {
                    $filenameCandidates[] = $candidate;
                }
            }
        }
    }

    $kioskCandidate = extract_screenshot_filename((string)($kiosk['screenshot_url'] ?? ''));
    if ($kioskCandidate !== null) {
        $filenameCandidates[] = $kioskCandidate;
    }

    $latest_stmt = $conn->prepare("SELECT details FROM sync_logs WHERE kiosk_id = ? AND action = 'screenshot' ORDER BY timestamp DESC LIMIT 50");
    $latest_stmt->bind_param('i', $kiosk_id);
    $latest_stmt->execute();
    $latest_result = $latest_stmt->get_result();
    while ($row = $latest_result->fetch_assoc()) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        if (is_array($details) && !empty($details['filename'])) {
            $candidate = extract_screenshot_filename((string)$details['filename']);
            if ($candidate !== null) {
                $filenameCandidates[] = $candidate;
            }
        }
    }
    $latest_stmt->close();

    $filenameCandidates = array_values(array_unique(array_filter($filenameCandidates)));

    if (empty($filenameCandidates)) {
        http_response_code(404);
        echo 'Not found';
        exit();
    }

    $screenshotsDir = realpath(__DIR__ . '/../screenshots');
    if (!$screenshotsDir) {
        http_response_code(500);
        echo 'Screenshot storage unavailable';
        exit();
    }

    $absolutePath = null;
    foreach ($filenameCandidates as $candidateFilename) {
        $candidatePath = realpath($screenshotsDir . DIRECTORY_SEPARATOR . $candidateFilename);
        if ($candidatePath && strpos($candidatePath, $screenshotsDir) === 0 && is_file($candidatePath)) {
            $absolutePath = $candidatePath;
            break;
        }
    }

    if (!$absolutePath) {
        http_response_code(404);
        echo 'Not found';
        exit();
    }

    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mime = 'image/png';
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($ext === 'webp') {
        $mime = 'image/webp';
    } elseif ($ext === 'gif') {
        $mime = 'image/gif';
    }

    $fileSize = filesize($absolutePath);
    if ($fileSize === false) {
        $fileSize = 0;
    }

    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    if ($fileSize > 0) {
        header('Content-Length: ' . (string)$fileSize);
    }

    readfile($absolutePath);
    exit();

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo 'Server error';
}
