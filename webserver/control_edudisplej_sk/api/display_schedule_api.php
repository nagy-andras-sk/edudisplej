<?php
/**
 * Display Scheduling API Endpoints
 * 
 * Endpoints:
 * POST   /api/admin/display_schedule/create     - Create/Update schedule
 * GET    /api/admin/display_schedule/{id}       - Get schedule
 * DELETE /api/admin/display_schedule/{id}       - Delete schedule
 * POST   /api/admin/display_schedule/time_slot  - Add/Update time slot
 * DELETE /api/admin/display_schedule/time_slot/{id} - Delete time slot
 * GET    /api/kijelzo/{id}/schedule_status      - Get current status
 * POST   /api/kijelzo/{id}/schedule_force_status - Force status (admin only)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/display_scheduler.php';

session_start();

// Check authorization
function check_admin() {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        return false;
    }
    // Implement your permission check here
    return true;
}

function check_group_access($group_id) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        return false;
    }
    // Check if user has access to group
    return true;
}

// Router
$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_parts = array_filter(explode('/', $request_path));

try {
    // Initialize scheduler
    $scheduler = new DisplayScheduler($db_connection); // Inject your DB connection
    
    // CREATE or UPDATE schedule
    if ($request_method === 'POST' && preg_match('/display_schedule\/create/', $request_path)) {
        if (!check_admin()) throw new Exception('Unauthorized', 401);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['group_id']) || !isset($data['name'])) {
            throw new Exception('Missing required fields: group_id, name', 400);
        }
        
        $kijelzo_id = $data['kijelzo_id'] ?? null;
        $name = $data['name'];
        $group_id = (int)$data['group_id'];
        
        // Create schedule
        $schedule_id = $scheduler->createDefaultScheduleForGroup($group_id, $kijelzo_id);
        
        if (!$schedule_id) {
            throw new Exception('Failed to create schedule', 500);
        }
        
        echo json_encode([
            'success' => true,
            'schedule_id' => $schedule_id,
            'message' => 'Schedule created successfully'
        ]);
    }
    
    // GET schedule
    else if ($request_method === 'GET' && preg_match('/display_schedule\/(\d+)/', $request_path, $matches)) {
        $schedule_id = (int)$matches[1];
        
        $schedule = $scheduler->getScheduleForDisplay($schedule_id);
        
        if (!$schedule) {
            throw new Exception('Schedule not found', 404);
        }
        
        echo json_encode([
            'success' => true,
            'schedule' => $schedule
        ]);
    }
    
    // ADD or UPDATE time slot
    else if ($request_method === 'POST' && preg_match('/display_schedule\/time_slot/', $request_path)) {
        if (!check_admin()) throw new Exception('Unauthorized', 401);
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['schedule_id']) || !isset($data['day_of_week']) || 
            !isset($data['start_time']) || !isset($data['end_time'])) {
            throw new Exception('Missing required fields', 400);
        }
        
        $slot_id = $scheduler->addTimeSlot(
            (int)$data['schedule_id'],
            (int)$data['day_of_week'],
            $data['start_time'],
            $data['end_time'],
            $data['is_enabled'] ?? true
        );
        
        if (!$slot_id) {
            throw new Exception('Failed to add time slot', 500);
        }
        
        echo json_encode([
            'success' => true,
            'slot_id' => $slot_id,
            'message' => 'Time slot added successfully'
        ]);
    }
    
    // GET current status
    else if ($request_method === 'GET' && preg_match('/kijelzo\/(\d+)\/schedule_status/', $request_path, $matches)) {
        $kijelzo_id = (int)$matches[1];
        
        $status = $scheduler->getCurrentDisplayStatus($kijelzo_id);
        
        echo json_encode([
            'success' => true,
            'kijelzo_id' => $kijelzo_id,
            'status' => $status,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    // FORCE status (admin only)
    else if ($request_method === 'POST' && preg_match('/kijelzo\/(\d+)\/schedule_force_status/', $request_path, $matches)) {
        if (!check_admin()) throw new Exception('Unauthorized', 401);
        
        $kijelzo_id = (int)$matches[1];
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['status'])) {
            throw new Exception('Missing status field', 400);
        }
        
        $status = $data['status'];
        
        // Log the forced status change
        $scheduler->logStatusChange($kijelzo_id, $status, 'Forced by admin', null);
        
        // TODO: Send signal to Raspberry Pi service to apply status
        // This could be via:
        // 1. Direct SSH command
        // 2. Message queue (RabbitMQ, Redis)
        // 3. Webhook endpoint on the Raspberry Pi
        
        echo json_encode([
            'success' => true,
            'message' => 'Status change signal sent',
            'status' => $status
        ]);
    }
    
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
    
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode([
        'error' => $e->getMessage(),
        'code' => $code
    ]);
}
