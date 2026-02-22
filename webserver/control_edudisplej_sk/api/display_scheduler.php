<?php
/**
 * Display Scheduling System
 * 
 * Handles display power management and content service scheduling
 * on Raspberry Pi devices.
 * 
 * Database Schema Migration
 */

// Migration: Create display scheduling tables
$migration_sql = <<<SQL

-- Display Scheduling Table
CREATE TABLE IF NOT EXISTS display_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    kijelzo_id INT,
    name VARCHAR(255) NOT NULL DEFAULT 'Default Schedule',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (kijelzo_id) REFERENCES kijelzok(id) ON DELETE SET NULL,
    UNIQUE KEY unique_schedule_per_display (group_id, kijelzo_id)
);

-- Daily Time Slots (one schedule can have multiple time slots per day)
CREATE TABLE IF NOT EXISTS schedule_time_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    day_of_week INT NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES display_schedules(id) ON DELETE CASCADE,
    INDEX idx_schedule_day (schedule_id, day_of_week)
);

-- Special Days (date-specific overrides)
CREATE TABLE IF NOT EXISTS schedule_special_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    date_value DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES display_schedules(id) ON DELETE CASCADE,
    UNIQUE KEY unique_special_day (schedule_id, date_value),
    INDEX idx_schedule_date (schedule_id, date_value)
);

-- Display Status Log (for monitoring)
CREATE TABLE IF NOT EXISTS display_status_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kijelzo_id INT NOT NULL,
    status VARCHAR(50) NOT NULL COMMENT 'ACTIVE, TURNED_OFF, SERVICE_ERROR, etc.',
    message TEXT,
    previous_status VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kijelzo_id) REFERENCES kijelzok(id) ON DELETE CASCADE,
    INDEX idx_kijelzo_created (kijelzo_id, created_at)
);

SQL;

/**
 * Helper functions for scheduling
 */

class DisplayScheduler {
    private $db;
    
    public function __construct($database_connection) {
        $this->db = $database_connection;
    }
    
    /**
     * Create default schedule for new group
     * Default: 22:00 - 06:00 TURNED OFF
     */
    public function createDefaultScheduleForGroup($group_id, $kijelzo_id = null) {
        try {
            // Create schedule
            $stmt = $this->db->prepare(
                "INSERT INTO display_schedules (group_id, kijelzo_id, name, is_active)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('iisi', $group_id, $kijelzo_id, $name, $is_active);
            $name = 'Default Schedule';
            $is_active = true;
            $stmt->execute();
            $schedule_id = $this->db->insert_id;
            
            // Add time slots for all days: 22:00 - 06:00 OFF, rest ON
            for ($day = 0; $day < 7; $day++) {
                // OFF slot: 22:00 - 06:00
                $this->addTimeSlot($schedule_id, $day, '22:00', '06:00', false);
                
                // ON slot: 06:00 - 22:00
                $this->addTimeSlot($schedule_id, $day, '06:00', '22:00', true);
            }
            
            return $schedule_id;
        } catch (Exception $e) {
            error_log("Error creating default schedule: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add time slot to schedule
     */
    public function addTimeSlot($schedule_id, $day_of_week, $start_time, $end_time, $is_enabled = true) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO schedule_time_slots (schedule_id, day_of_week, start_time, end_time, is_enabled)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iissi', $schedule_id, $day_of_week, $start_time, $end_time, $is_enabled);
            $stmt->execute();
            return $this->db->insert_id;
        } catch (Exception $e) {
            error_log("Error adding time slot: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current status for display based on schedule
     */
    public function getCurrentDisplayStatus($kijelzo_id) {
        try {
            $now = new DateTime();
            $current_day = (int)$now->format('w');
            $current_time = $now->format('H:i:s');
            
            // Get active schedule for this display
            $stmt = $this->db->prepare(
                "SELECT id FROM display_schedules 
                 WHERE kijelzo_id = ? AND is_active = TRUE"
            );
            $stmt->bind_param('i', $kijelzo_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return 'ACTIVE'; // Default if no schedule
            }
            
            $schedule = $result->fetch_assoc();
            $schedule_id = $schedule['id'];
            
            // Check special days first
            $today = $now->format('Y-m-d');
            $stmt = $this->db->prepare(
                "SELECT is_enabled FROM schedule_special_days 
                 WHERE schedule_id = ? AND date_value = ?
                 AND ? BETWEEN start_time AND end_time"
            );
            $stmt->bind_param('iss', $schedule_id, $today, $current_time);
            $stmt->execute();
            $special_result = $stmt->get_result();
            
            if ($special_result->num_rows > 0) {
                $special = $special_result->fetch_assoc();
                return $special['is_enabled'] ? 'ACTIVE' : 'TURNED_OFF';
            }
            
            // Check regular time slots for this day
            $stmt = $this->db->prepare(
                "SELECT is_enabled FROM schedule_time_slots 
                 WHERE schedule_id = ? 
                 AND day_of_week = ?
                 AND ? BETWEEN start_time AND end_time
                 LIMIT 1"
            );
            $stmt->bind_param('iis', $schedule_id, $current_day, $current_time);
            $stmt->execute();
            $time_result = $stmt->get_result();
            
            if ($time_result->num_rows > 0) {
                $slot = $time_result->fetch_assoc();
                return $slot['is_enabled'] ? 'ACTIVE' : 'TURNED_OFF';
            }
            
            // Default to ACTIVE if no matching time slot
            return 'ACTIVE';
            
        } catch (Exception $e) {
            error_log("Error getting display status: " . $e->getMessage());
            return 'ERROR';
        }
    }
    
    /**
     * Get full schedule for display
     */
    public function getScheduleForDisplay($kijelzo_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, group_id, name, is_active FROM display_schedules 
                 WHERE kijelzo_id = ?"
            );
            $stmt->bind_param('i', $kijelzo_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return null;
            }
            
            $schedule = $result->fetch_assoc();
            $schedule_id = $schedule['id'];
            
            // Get time slots
            $slots_stmt = $this->db->prepare(
                "SELECT day_of_week, start_time, end_time, is_enabled 
                 FROM schedule_time_slots 
                 WHERE schedule_id = ?
                 ORDER BY day_of_week, start_time"
            );
            $slots_stmt->bind_param('i', $schedule_id);
            $slots_stmt->execute();
            $slots_result = $slots_stmt->get_result();
            
            $schedule['time_slots'] = [];
            while ($slot = $slots_result->fetch_assoc()) {
                $schedule['time_slots'][] = $slot;
            }
            
            // Get special days
            $special_stmt = $this->db->prepare(
                "SELECT date_value, start_time, end_time, note 
                 FROM schedule_special_days 
                 WHERE schedule_id = ?
                 ORDER BY date_value DESC"
            );
            $special_stmt->bind_param('i', $schedule_id);
            $special_stmt->execute();
            $special_result = $special_stmt->get_result();
            
            $schedule['special_days'] = [];
            while ($special = $special_result->fetch_assoc()) {
                $schedule['special_days'][] = $special;
            }
            
            return $schedule;
        } catch (Exception $e) {
            error_log("Error getting schedule: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Log status change
     */
    public function logStatusChange($kijelzo_id, $new_status, $message = null, $previous_status = null) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO display_status_log (kijelzo_id, status, message, previous_status)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('isss', $kijelzo_id, $new_status, $message, $previous_status);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            error_log("Error logging status: " . $e->getMessage());
            return false;
        }
    }
}
