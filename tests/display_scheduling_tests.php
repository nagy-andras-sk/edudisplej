<?php
/**
 * Display Scheduling System - Integration Tests
 * PHP unit tests for scheduling functionality
 */

class DisplaySchedulingTests {
    
    private $scheduler;
    private $test_kijelzo_id = 999; // Test ID
    private $test_group_id = 99;
    
    public function __construct() {
        require_once 'api/display_scheduler.php';
        $this->scheduler = new DisplayScheduler();
    }
    
    /**
     * Test 1: Default schedule creation (22:00-06:00 OFF)
     */
    public function test_default_schedule_creation() {
        echo "TEST 1: Default Schedule Creation\n";
        echo "===================================\n";
        
        try {
            $schedule = $this->scheduler->createDefaultScheduleForGroup(
                $this->test_group_id,
                $this->test_kijelzo_id
            );
            
            echo "✓ Schedule created with ID: " . $schedule['schedule_id'] . "\n";
            
            // Verify 22:00-06:00 is OFF
            $schedule_data = $this->scheduler->getScheduleForDisplay($this->test_kijelzo_id);
            
            // Check each day has OFF slot from 22:00-06:00
            foreach ($schedule_data['time_slots'] as $slot) {
                if ($slot['start_time'] === '22:00:00' && 
                    $slot['end_time'] === '06:00:00' &&
                    $slot['is_enabled'] === 0) {
                    echo "✓ Day {$slot['day_of_week']}: OFF 22:00-06:00\n";
                    return true;
                }
            }
            
            echo "✗ OFF slots not found in schedule\n";
            return false;
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 2: Current display status during OFF hours
     */
    public function test_status_during_off_hours() {
        echo "\nTEST 2: Status During OFF Hours (22:00-06:00)\n";
        echo "==============================================\n";
        
        // Mock current time to 23:00 (within OFF hours)
        $mock_hour = 23;
        
        try {
            $status = $this->scheduler->getCurrentDisplayStatus($this->test_kijelzo_id);
            
            echo "Current status: " . $status . "\n";
            
            if ($status === 'TURNED_OFF') {
                echo "✓ Display correctly reported as TURNED_OFF during 22:00-06:00\n";
                return true;
            } else {
                echo "✗ Expected TURNED_OFF, got: " . $status . "\n";
                return false;
            }
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 3: Current display status during ON hours
     */
    public function test_status_during_on_hours() {
        echo "\nTEST 3: Status During ON Hours (06:00-22:00)\n";
        echo "=============================================\n";
        
        // Mock current time to 12:00 (within ON hours)
        $mock_hour = 12;
        
        try {
            $status = $this->scheduler->getCurrentDisplayStatus($this->test_kijelzo_id);
            
            echo "Current status: " . $status . "\n";
            
            if ($status === 'ACTIVE') {
                echo "✓ Display correctly reported as ACTIVE during 06:00-22:00\n";
                return true;
            } else {
                echo "✗ Expected ACTIVE, got: " . $status . "\n";
                return false;
            }
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 4: Add custom time slot
     */
    public function test_add_custom_slot() {
        echo "\nTEST 4: Add Custom Time Slot\n";
        echo "=============================\n";
        
        try {
            $schedule = $this->scheduler->getScheduleForDisplay($this->test_kijelzo_id);
            $schedule_id = $schedule['schedule_id'];
            
            // Add slot: Monday 08:00-12:00 ON
            $slot = $this->scheduler->addTimeSlot(
                $schedule_id,
                1, // Monday
                '08:00:00',
                '12:00:00',
                1 // enabled
            );
            
            echo "✓ Slot added with ID: " . $slot['slot_id'] . "\n";
            echo "  Day: Monday, Start: 08:00, End: 12:00, Status: ON\n";
            return true;
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 5: Status logging
     */
    public function test_status_logging() {
        echo "\nTEST 5: Status Change Logging\n";
        echo "==============================\n";
        
        try {
            $log = $this->scheduler->logStatusChange(
                $this->test_kijelzo_id,
                'ACTIVE',
                'Test trigger',
                'TURNED_OFF'
            );
            
            echo "✓ Status change logged\n";
            echo "  Change: TURNED_OFF → ACTIVE\n";
            echo "  Reason: Test trigger\n";
            return true;
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 6: Get complete schedule for display
     */
    public function test_get_schedule() {
        echo "\nTEST 6: Get Complete Schedule\n";
        echo "==============================\n";
        
        try {
            $schedule = $this->scheduler->getScheduleForDisplay($this->test_kijelzo_id);
            
            echo "✓ Schedule retrieved\n";
            echo "  Schedule ID: " . $schedule['schedule_id'] . "\n";
            echo "  Kijelzo ID: " . $schedule['kijelzo_id'] . "\n";
            echo "  Time slots count: " . count($schedule['time_slots']) . "\n";
            echo "  Special days count: " . count($schedule['special_days'] ?? []) . "\n";
            
            return true;
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Run all tests
     */
    public function run_all_tests() {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║   DISPLAY SCHEDULING SYSTEM - INTEGRATION TESTS          ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n";
        
        $tests = [
            'test_default_schedule_creation',
            'test_status_during_off_hours',
            'test_status_during_on_hours',
            'test_add_custom_slot',
            'test_status_logging',
            'test_get_schedule'
        ];
        
        $results = [];
        foreach ($tests as $test) {
            ob_start();
            $result = $this->$test();
            $output = ob_get_clean();
            echo $output;
            $results[$test] = $result;
        }
        
        // Summary
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║                    TEST SUMMARY                          ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n";
        
        $passed = array_sum($results);
        $total = count($results);
        $percentage = ($passed / $total) * 100;
        
        echo "Passed: {$passed}/{$total} ({$percentage}%)\n\n";
        
        foreach ($results as $test => $result) {
            $status = $result ? "✓ PASS" : "✗ FAIL";
            echo "{$status}: {$test}\n";
        }
        
        return $passed === $total;
    }
}

// Run tests if called directly
if (php_sapi_name() === 'cli') {
    $tests = new DisplaySchedulingTests();
    $success = $tests->run_all_tests();
    exit($success ? 0 : 1);
}
?>
