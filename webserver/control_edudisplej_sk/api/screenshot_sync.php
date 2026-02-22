<?php
/**
 * Screenshot Sync API
 * EduDisplej Control Panel
 *
 * @deprecated Use /api/v1/device/sync.php instead (include screenshot in the sync payload).
 */

header('Content-Type: application/json');
header('X-EDU-Deprecated: true');
header('X-EDU-Successor: /api/v1/device/sync.php');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => ''];

function sanitize_screenshot_filename($filename) {
    $name = basename((string)$filename);
    $name = str_replace(['\\', '/'], '', $name);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

    if ($name === '' || !preg_match('/\.(png|jpe?g)$/i', $name)) {
        return '';
    }

    return $name;
}

function enforce_screenshot_retention(mysqli $conn, int $kiosk_id, int $max_per_kiosk = 100): void {
    if ($max_per_kiosk < 1) {
        return;
    }

    $screenshots_dir = realpath(__DIR__ . '/../screenshots');

    $old_stmt = $conn->prepare(
        "SELECT id, details
           FROM sync_logs
          WHERE kiosk_id = ? AND action = 'screenshot'
          ORDER BY timestamp DESC, id DESC
          LIMIT 100000 OFFSET ?"
    );
    $old_stmt->bind_param("ii", $kiosk_id, $max_per_kiosk);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();

    $ids_to_delete = [];
    while ($row = $old_result->fetch_assoc()) {
        $ids_to_delete[] = (int)$row['id'];

        $details = json_decode((string)$row['details'], true);
        if (!is_array($details) || empty($details['filename']) || !$screenshots_dir) {
            continue;
        }

        $filename = sanitize_screenshot_filename((string)$details['filename']);
        if ($filename === '') {
            continue;
        }

        $file_path = $screenshots_dir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($file_path)) {
            @unlink($file_path);
        }
    }
    $old_stmt->close();

    if (empty($ids_to_delete)) {
        return;
    }

    $id_list = implode(',', array_map('intval', $ids_to_delete));
    $conn->query("DELETE FROM sync_logs WHERE id IN ($id_list)");
}

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $screenshot_data = $data['screenshot'] ?? '';
    $custom_filename = $data['filename'] ?? ''; // Support custom filename format
    
    if (empty($mac) || empty($screenshot_data)) {
        $response['message'] = 'MAC address and screenshot data required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Verify kiosk and enforce company ownership
    $kiosk_lookup = $conn->prepare("SELECT id, company_id FROM kiosks WHERE mac = ? LIMIT 1");
    $kiosk_lookup->bind_param("s", $mac);
    $kiosk_lookup->execute();
    $kiosk_result = $kiosk_lookup->get_result();
    $kiosk_row = $kiosk_result->fetch_assoc();
    $kiosk_lookup->close();

    if (!$kiosk_row) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }

    api_require_company_match($api_company, $kiosk_row['company_id'], 'Unauthorized');

    // Use custom filename if provided, otherwise generate default
    if (!empty($custom_filename)) {
        $filename = sanitize_screenshot_filename($custom_filename);
        if ($filename === '') {
            $filename = 'screenshot_' . md5($mac . time()) . '.png';
        }
    } else {
        $filename = 'screenshot_' . md5($mac . time()) . '.png';
    }
    $filepath = '../screenshots/' . $filename;
    
    // Create screenshots directory if it doesn't exist
    if (!is_dir('../screenshots')) {
        mkdir('../screenshots', 0755, true);
    }
    
    // Decode and save base64 image
    $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $screenshot_data));
    
    // Validate it's actually image data by checking the header
    if ($image_data === false || strlen($image_data) < 100) {
        $response['message'] = 'Invalid image data';
        echo json_encode($response);
        exit;
    }
    
    // Check for PNG/JPEG magic bytes
    $is_png = (substr($image_data, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A");
    $is_jpeg = (substr($image_data, 0, 3) === "\xFF\xD8\xFF");
    
    if (!$is_png && !$is_jpeg) {
        $response['message'] = 'Invalid image format. Only PNG and JPEG are supported.';
        echo json_encode($response);
        exit;
    }
    
    file_put_contents($filepath, $image_data);
    
    // Update kiosk
    $stmt = $conn->prepare("UPDATE kiosks SET screenshot_url = ?, screenshot_timestamp = NOW(), screenshot_requested = 0 WHERE mac = ?");
    $relative_path = 'screenshots/' . $filename;
    $stmt->bind_param("ss", $relative_path, $mac);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Screenshot uploaded successfully';
        
        // Log screenshot upload
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'screenshot', ?)");
        $details = json_encode(['filename' => $filename, 'timestamp' => date('Y-m-d H:i:s')]);
        $log_stmt->bind_param("is", $kiosk_row['id'], $details);
        $log_stmt->execute();
        $log_stmt->close();

        enforce_screenshot_retention($conn, (int)$kiosk_row['id'], 100);
    } else {
        $response['message'] = 'Screenshot upload failed';
    }
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>

