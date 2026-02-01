<?php
/**
 * Module Files Delivery API
 * Serves module files (live.html, configure.json) to kiosks
 * EduDisplej Control Panel
 */

require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => '', 'file_content' => null];

try {
    $module_key = $_GET['module_key'] ?? '';
    $file = $_GET['file'] ?? 'live.html';
    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);
    
    if (empty($module_key)) {
        $response['message'] = 'Module key required';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Validate file name (security)
    $allowed_files = ['live.html', 'configure.json'];
    if (!in_array($file, $allowed_files)) {
        $response['message'] = 'Invalid file requested';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Construct file path
    $module_path = "/home/runner/work/edudisplej/edudisplej/webserver/server_edudisplej_sk/modules/{$module_key}/{$file}";
    
    if (!file_exists($module_path)) {
        $response['message'] = 'Module file not found';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Get module settings for this kiosk if available
    $settings = [];
    if ($kiosk_id > 0) {
        try {
            $conn = getDbConnection();
            
            // Get module settings
            $query = "SELECT km.settings 
                      FROM kiosk_modules km
                      JOIN modules m ON km.module_id = m.id
                      WHERE km.kiosk_id = ? AND m.module_key = ? AND km.is_active = 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("is", $kiosk_id, $module_key);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $settings = json_decode($row['settings'], true) ?? [];
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
    }
    
    // Read file content
    $content = file_get_contents($module_path);
    
    // If it's HTML and we have settings, inject them
    if ($file === 'live.html' && !empty($settings)) {
        // Inject settings as URL parameter
        $settings_json = json_encode($settings);
        $settings_encoded = urlencode($settings_json);
        
        // Add settings to the URL in a script tag
        $injection = "\n<script>\n// Injected settings from server\nwindow.EDUDISPLEJ_SETTINGS = " . $settings_json . ";\n</script>\n";
        $content = str_replace('</head>', $injection . '</head>', $content);
    }
    
    // Determine content type
    $content_type = 'text/html';
    if ($file === 'configure.json') {
        $content_type = 'application/json';
    }
    
    // Serve the file
    header('Content-Type: ' . $content_type);
    echo $content;
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
    header('Content-Type: application/json');
    echo json_encode($response);
}
?>
