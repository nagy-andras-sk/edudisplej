<?php
/**
 * Geolocation API - Get location from IP address
 * EduDisplej Control Panel
 */

header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => '', 'location' => ''];

try {
    // Get the IP address to lookup
    $ip = $_GET['ip'] ?? '';
    
    if (empty($ip)) {
        $response['message'] = 'IP address required';
        echo json_encode($response);
        exit;
    }
    
    // Use a free geolocation API (ip-api.com)
    // Alternative APIs: ipapi.co, ipinfo.io, freegeoip.app
    $api_url = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,query";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($result === false || $http_code !== 200) {
        $response['message'] = 'Failed to fetch location data';
        echo json_encode($response);
        exit;
    }
    
    $geo_data = json_decode($result, true);
    
    if ($geo_data && $geo_data['status'] === 'success') {
        // Build location string
        $location_parts = [];
        
        if (!empty($geo_data['city'])) {
            $location_parts[] = $geo_data['city'];
        }
        if (!empty($geo_data['regionName'])) {
            $location_parts[] = $geo_data['regionName'];
        }
        if (!empty($geo_data['country'])) {
            $location_parts[] = $geo_data['country'];
        }
        
        $location = implode(', ', $location_parts);
        
        $response['success'] = true;
        $response['location'] = $location;
        $response['message'] = 'Location fetched successfully';
    } else {
        $response['message'] = 'Location not found for IP';
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>

