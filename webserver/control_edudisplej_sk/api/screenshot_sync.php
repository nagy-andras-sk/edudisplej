<?php
/**
 * Screenshot Sync API
 * EduDisplej Control Panel
 */

header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $screenshot_data = $data['screenshot'] ?? '';
    
    if (empty($mac) || empty($screenshot_data)) {
        $response['message'] = 'MAC address and screenshot data required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Save screenshot
    $filename = 'screenshot_' . md5($mac . time()) . '.png';
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
    $stmt = $conn->prepare("UPDATE kiosks SET screenshot_url = ?, screenshot_requested = 0 WHERE mac = ?");
    $relative_path = 'screenshots/' . $filename;
    $stmt->bind_param("ss", $relative_path, $mac);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Screenshot uploaded successfully';
        
        // Log screenshot upload
        $stmt = $conn->prepare("SELECT id FROM kiosks WHERE mac = ?");
        $stmt->bind_param("s", $mac);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'screenshot', ?)");
            $details = json_encode(['filename' => $filename, 'timestamp' => date('Y-m-d H:i:s')]);
            $log_stmt->bind_param("is", $row['id'], $details);
            $log_stmt->execute();
            $log_stmt->close();
        }
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

