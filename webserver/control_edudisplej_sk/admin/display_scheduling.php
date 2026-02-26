<?php
/**
 * Display Scheduling Admin Panel
 * Allows administrators to configure display scheduling
 */

require_once '../../auth_roles.php';
require_once '../../api/display_scheduler.php';

// Check authorization
if (!check_admin()) {
    http_response_code(403);
    die('Hozzáférés megtagadva');
}

// Get all displays/kiosks if searching
$displays = [];
$selected_kijelzo_id = isset($_GET['kijelzo_id']) ? intval($_GET['kijelzo_id']) : null;
$selected_schedule = null;

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Create new default schedule
    if ($action === 'create_default' && $selected_kijelzo_id) {
        try {
            $scheduler = new DisplayScheduler();
            $group_id = intval($_POST['group_id']); // Get group from context
            $scheduler->createDefaultScheduleForGroup($group_id, $selected_kijelzo_id);
            $success_msg = 'Ütemezés sikeresen létrehozva! Alapértelmezés: 22:00-06:00 között kikapcsolt.';
        } catch (Exception $e) {
            $error_msg = 'Hiba az ütemezés létrehozásakor: ' . $e->getMessage();
        }
    }
    
    // Add/update time slot
    if ($action === 'add_slot' && $selected_kijelzo_id) {
        try {
            $scheduler = new DisplayScheduler();
            $schedule_id = intval($_POST['schedule_id']);
            $day = intval($_POST['day_of_week']);
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
            
            $scheduler->addTimeSlot($schedule_id, $day, $start_time, $end_time, $is_enabled);
            $success_msg = 'Időblokkot sikeresen hozzáadva!';
        } catch (Exception $e) {
            $error_msg = 'Hiba az időblokk hozzáadásakor: ' . $e->getMessage();
        }
    }
}

// Load schedule if kijelzo selected
if ($selected_kijelzo_id) {
    try {
        $scheduler = new DisplayScheduler();
        $selected_schedule = $scheduler->getScheduleForDisplay($selected_kijelzo_id);
    } catch (Exception $e) {
        $error_msg = 'Hiba az ütemezés betöltésekor: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kijelzo Ütemezés - Admin Panel</title>
    <link rel="stylesheet" href="../../style.css">
    <style>
        .schedule-container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .schedule-header {
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .schedule-header h1 {
            margin: 0;
            color: #2c3e50;
        }

        .schedule-selection {
            background: #f5f7fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }

        .form-group select,
        .form-group input,
        .form-group button {
            padding: 8px 12px;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group button {
            background: #3498db;
            color: white;
            cursor: pointer;
            border: none;
            transition: background 0.3s;
        }

        .form-group button:hover {
            background: #2980b9;
        }

        .alert {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #f5c6cb;
            color: #721c24;
        }

        .schedule-grid-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            overflow-x: auto;
            margin: 20px 0;
        }

        .schedule-grid {
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr);
            gap: 1px;
            background: #ddd;
            padding: 1px;
        }

        .grid-cell {
            background: white;
            padding: 8px;
            text-align: center;
            font-size: 12px;
            min-height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .grid-cell.header {
            background: #2c3e50;
            color: white;
            font-weight: bold;
        }

        .grid-cell.time-label {
            background: #f0f0f0;
            font-weight: bold;
        }

        .grid-cell.active {
            background: #2ecc71;
            color: white;
            cursor: pointer;
        }

        .grid-cell.inactive {
            background: #e74c3c;
            color: white;
            cursor: pointer;
        }

        .time-slot-form {
            background: #f5f7fa;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }

        .time-slot-form h3 {
            margin-top: 0;
            color: #2c3e50;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr auto;
            gap: 10px;
            align-items: flex-end;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .status-indicator.active {
            background: #d4edda;
            color: #155724;
        }

        .status-indicator.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .info-box {
            background: #ecf0f1;
            padding: 12px;
            border-left: 4px solid #3498db;
            border-radius: 4px;
            font-size: 13px;
            color: #555;
            margin: 20px 0;
        }

        .info-box strong {
            color: #2c3e50;
        }

        .info-box ul {
            margin: 8px 0;
            padding-left: 20px;
        }

        .info-box li {
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="schedule-container">
        <div class="schedule-header">
            <h1>📅 Kijelzo Ütemezés Kezelés</h1>
            <p>Állítsa be, hogy mikor legyenek bekapcsolt a kijelzők</p>
        </div>

        <?php if (isset($success_msg)): ?>
            <div class="alert alert-success">✓ <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>

        <?php if (isset($error_msg)): ?>
            <div class="alert alert-error">✗ <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <!-- Selection Form -->
        <div class="schedule-selection">
            <form method="GET">
                <div class="form-group">
                    <label for="kijelzo_id">Kijelzo kiválasztása:</label>
                    <select name="kijelzo_id" id="kijelzo_id" onchange="this.form.submit()">
                        <option value="">-- Válasszon kijelzőt --</option>
                        <?php
                        // In real implementation, load kiosks from database
                        // For now, show input for testing
                        ?>
                        <option value="1" <?php echo $selected_kijelzo_id === 1 ? 'selected' : ''; ?>>Kijelzo 1</option>
                        <option value="2" <?php echo $selected_kijelzo_id === 2 ? 'selected' : ''; ?>>Kijelzo 2</option>
                        <option value="3" <?php echo $selected_kijelzo_id === 3 ? 'selected' : ''; ?>>Kijelzo 3</option>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($selected_kijelzo_id): ?>
            <!-- Current Status -->
            <div class="status-indicator active">
                ● Aktív és ütemezve
            </div>

            <?php if ($selected_schedule): ?>
                <!-- Schedule Information -->
                <div class="info-box">
                    <strong>ℹ️ Az ütemezés:</strong>
                    <ul>
                        <li><strong>Kijelzo ID:</strong> <?php echo $selected_kijelzo_id; ?></li>
                        <li><strong>Ütemezés létrehozva:</strong> <?php echo htmlspecialchars($selected_schedule['created_at']); ?></li>
                        <li><strong>Módosítva:</strong> <?php echo htmlspecialchars($selected_schedule['updated_at']); ?></li>
                    </ul>
                </div>

                <!-- Schedule Grid (Placeholder) -->
                <div class="schedule-grid-container">
                    <h3>Heti Ütemezés</h3>
                    <p style="color: #666; font-size: 13px;">
                        Az ütemezés a kijelzőn csütörtöktöl vasárnapig 22:00-06:00 között ki van kapcsolva.
                    </p>
                </div>

                <!-- Time Slot Form -->
                <div class="time-slot-form">
                    <h3>Új Időblokk Hozzáadása</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_slot">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_schedule['schedule_id']; ?>">
                        <input type="hidden" name="kijelzo_id" value="<?php echo $selected_kijelzo_id; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="day_of_week">Nap:</label>
                                <select name="day_of_week" id="day_of_week" required>
                                    <option value="0">Vasárnap</option>
                                    <option value="1">Hétfő</option>
                                    <option value="2">Kedd</option>
                                    <option value="3">Szerda</option>
                                    <option value="4">Csütörtök</option>
                                    <option value="5">Péntek</option>
                                    <option value="6">Szombat</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="start_time">Kezdés:</label>
                                <input type="text" name="start_time" id="start_time" required inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$" title="24 órás formátum: HH:MM">
                            </div>
                            <div class="form-group">
                                <label for="end_time">Vége:</label>
                                <input type="text" name="end_time" id="end_time" required inputmode="numeric" placeholder="HH:MM" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$" title="24 órás formátum: HH:MM">
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_enabled" value="1" checked>
                                    Aktív
                                </label>
                            </div>
                            <button type="submit">Hozzáadás</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- No Schedule Created -->
                <div class="alert alert-error">
                    ⚠️ Ehhez a kijelzőhöz még nincs ütemezés beállítva.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_default">
                    <input type="hidden" name="kijelzo_id" value="<?php echo $selected_kijelzo_id; ?>">
                    <input type="hidden" name="group_id" value="1"> <!-- Get from context -->
                    <button type="submit" class="form-group button" style="display: inline-block; padding: 10px 20px;">
                        Alapértelmezett Ütemezés Létrehozása
                    </button>
                </form>
                <p style="font-size: 13px; color: #666; margin-top: 10px;">
                    Ez létrehozza az alapértelmezett ütemezést: <strong>22:00-06:00 között kikapcsolt</strong>
                </p>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="info-box">
                <strong>💡 Útmutató:</strong>
                <ul>
                    <li>Az ütemezés a Raspberry Pi-n futó démonhoz csatlakozik</li>
                    <li>A kijelző automatikusan bekapcsol/kikapcsol az ütemezés szerint</li>
                    <li>Éjszaka (22:00-06:00) alapértelmezés szerint kikapcsolt a kijelző</li>
                    <li>Nap 06:00-22:00 között bekapcsolt a kijelző</li>
                    <li>A démon percenként ellenőrzi az aktuális státuszt</li>
                </ul>
            </div>

        <?php else: ?>
            <p style="text-align: center; color: #999; padding: 40px 0;">
                Válasszon een kijelzőt az ütemezés szerkesztéséhez →
            </p>
        <?php endif; ?>
    </div>

    <!-- Load the display scheduler module -->
    <script src="../../assets/js/modules/display-scheduler.js"></script>

</body>
</html>
