<?php
/**
 * Group Management - Simplified Design
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';
require_once '../i18n.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!edudisplej_can_manage_loops()) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$session_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
$default_group_id = null;
$has_group_modules_is_active = false;
$has_group_created_at = false;
$has_group_modules_created_at = false;
$has_group_modules_updated_at = false;

function format_loop_version($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $num = (string)$value;
        if (strlen($num) === 14) {
            return $num;
        }
        $ts = (int)$value;
        if ($ts > 0) {
            return date('YmdHis', $ts);
        }
    }

    $ts = strtotime((string)$value);
    if ($ts === false) {
        return null;
    }

    return date('YmdHis', $ts);
}

// Get user's company
$company_id = null;
try {
    $conn = getDbConnection();
    if ($session_company_id > 0) {
        $company_id = $session_company_id;
    } else {
        $stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $company_id = (int)($user['company_id'] ?? 0);
        $stmt->close();
    }

    if ((int)$company_id <= 0) {
        throw new Exception('Invalid company context in groups.php');
    }
    
    // Ensure kiosk_groups table exists
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_groups (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        company_id INT(11) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        priority INT(11) NOT NULL DEFAULT 0,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Ensure kiosk_group_assignments table exists
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_assignments (
        kiosk_id INT(11) NOT NULL,
        group_id INT(11) NOT NULL,
        PRIMARY KEY (kiosk_id, group_id),
        FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE,
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure kiosk_group_modules table exists (for default group clock module)
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_modules (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id INT(11) NOT NULL,
        module_id INT(11) NOT NULL,
        module_key VARCHAR(100) DEFAULT NULL,
        display_order INT(11) DEFAULT 0,
        duration_seconds INT(11) DEFAULT 10,
        settings TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $has_is_active_col = $conn->query("SHOW COLUMNS FROM kiosk_group_modules LIKE 'is_active'");
    if ($has_is_active_col && $has_is_active_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_modules ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER settings");
        $has_group_modules_is_active = true;
    } else {
        $has_group_modules_is_active = (bool)($has_is_active_col && $has_is_active_col->num_rows > 0);
    }

    // Ensure kiosk_group_loop_plans table exists (planner loop styles per group)
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_loop_plans (
        group_id INT(11) NOT NULL PRIMARY KEY,
        plan_json LONGTEXT NOT NULL,
        plan_version BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at),
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has_plan_version_col = $conn->query("SHOW COLUMNS FROM kiosk_group_loop_plans LIKE 'plan_version'");
    if ($has_plan_version_col && $has_plan_version_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_loop_plans ADD COLUMN plan_version BIGINT NOT NULL DEFAULT 0 AFTER plan_json");
    }

    // Ensure new columns exist for priority and default handling
    $columns_result = $conn->query("SHOW COLUMNS FROM kiosk_groups");
    $existing_columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $existing_columns[$col['Field']] = true;
    }
    if (!isset($existing_columns['priority'])) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN priority INT(11) NOT NULL DEFAULT 0");
    }
    if (!isset($existing_columns['is_default'])) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($existing_columns['created_at'])) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    $has_group_created_at = true;

    $kgm_columns_result = $conn->query("SHOW COLUMNS FROM kiosk_group_modules");
    $kgm_columns = [];
    if ($kgm_columns_result) {
        while ($kgm_col = $kgm_columns_result->fetch_assoc()) {
            $kgm_columns[$kgm_col['Field']] = true;
        }
    }

    if (!isset($kgm_columns['created_at'])) {
        $conn->query("ALTER TABLE kiosk_group_modules ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    if (!isset($kgm_columns['updated_at'])) {
        $conn->query("ALTER TABLE kiosk_group_modules ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    $has_group_modules_created_at = true;
    $has_group_modules_updated_at = true;

    // Ensure default group exists for this company
    $stmt = $conn->prepare("SELECT id, name FROM kiosk_groups WHERE company_id = ? AND is_default = 1 LIMIT 1");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $default_group = $result->fetch_assoc();
    $stmt->close();

    if ($default_group) {
        $default_group_id = $default_group['id'];
        $stmt = $conn->prepare("UPDATE kiosk_groups SET name = 'default', priority = 1 WHERE id = ?");
        $stmt->bind_param("i", $default_group_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO kiosk_groups (name, company_id, description, priority, is_default) VALUES ('default', ?, 'Alapertelmezett csoport', 1, 1)");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $default_group_id = $stmt->insert_id;
        $stmt->close();
    }

    // Enforce clock-only module for default group
    if ($default_group_id) {
        $clock_stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = 'clock' LIMIT 1");
        $clock_stmt->execute();
        $clock_result = $clock_stmt->get_result();
        $clock_module = $clock_result->fetch_assoc();
        $clock_stmt->close();

        if ($clock_module) {
            $clock_module_id = (int)$clock_module['id'];

            $cleanup_stmt = $conn->prepare("DELETE FROM kiosk_group_modules WHERE group_id = ? AND module_id != ?");
            $cleanup_stmt->bind_param("ii", $default_group_id, $clock_module_id);
            $cleanup_stmt->execute();
            $cleanup_stmt->close();

            $check_stmt = $conn->prepare("SELECT id FROM kiosk_group_modules WHERE group_id = ? AND module_id = ? LIMIT 1");
            $check_stmt->bind_param("ii", $default_group_id, $clock_module_id);
            $check_stmt->execute();
            $clock_exists = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$clock_exists) {
                $insert_stmt = $conn->prepare("INSERT INTO kiosk_group_modules (group_id, module_id, module_key, display_order, duration_seconds, settings, is_active) VALUES (?, ?, (SELECT module_key FROM modules WHERE id = ?), 0, 10, NULL, 1)");
                $insert_stmt->bind_param("iii", $default_group_id, $clock_module_id, $clock_module_id);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }
    }

    // Auto-assign kiosks without group to highest priority group
    $stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, id DESC LIMIT 1");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $highest_group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($highest_group) {
        $highest_group_id = (int)$highest_group['id'];
        $assign_stmt = $conn->prepare("INSERT IGNORE INTO kiosk_group_assignments (kiosk_id, group_id)
                                       SELECT k.id, ?
                                       FROM kiosks k
                                       LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                                       WHERE k.company_id = ? AND kga.kiosk_id IS NULL");
        $assign_stmt->bind_param("ii", $highest_group_id, $company_id);
        $assign_stmt->execute();
        $assign_stmt->close();
    }
    
} catch (Exception $e) {
    $error = 'Database error';
    if ($is_admin) {
        $error .= ': ' . $e->getMessage();
    }
    error_log($e->getMessage());
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        $error = 'A csoport neve kötelező';
    } else {
        try {
            $duplicate_stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE company_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            if (!$duplicate_stmt) {
                throw new Exception('Duplicate-check prepare failed: ' . $conn->error);
            }
            $duplicate_stmt->bind_param("is", $company_id, $name);
            $duplicate_stmt->execute();
            $duplicate_row = $duplicate_stmt->get_result()->fetch_assoc();
            $duplicate_stmt->close();

            if ($duplicate_row) {
                $error = t_def('groups.error.duplicate_name', 'Ilyen nevű csoport már létezik ennél a cégnél.');
                throw new Exception('Duplicate group name blocked');
            }

            $stmt = $conn->prepare("SELECT COALESCE(MAX(priority), 0) as max_priority FROM kiosk_groups WHERE company_id = ?");
            $stmt->bind_param("i", $company_id);
            $stmt->execute();
            $max_priority = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $new_priority = (int)$max_priority['max_priority'] + 1;

            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO kiosk_groups (name, company_id, description, priority, is_default) VALUES (?, ?, ?, ?, 0)");
            $stmt->bind_param("sisi", $name, $company_id, $description, $new_priority);

            if (!$stmt->execute()) {
                throw new Exception('Group create failed');
            }

            $new_group_id = (int)$stmt->insert_id;
            $stmt->close();

            $default_plan = [
                'loop_styles' => [[
                    'id' => 1,
                    'name' => 'Alap loop',
                    'items' => []
                ]],
                'default_loop_style_id' => 1,
                'schedule_blocks' => []
            ];

            $plan_json = json_encode($default_plan, JSON_UNESCAPED_UNICODE);
            if ($plan_json === false) {
                throw new Exception(t_def('groups.error.default_plan_json_failed', 'Default loop plan json encode failed'));
            }

            $plan_version = (int)round(microtime(true) * 1000);
            $plan_version_str = (string)$plan_version;
            $plan_stmt = $conn->prepare("INSERT INTO kiosk_group_loop_plans (group_id, plan_json, plan_version) VALUES (?, ?, ?)");
            $plan_stmt->bind_param("iss", $new_group_id, $plan_json, $plan_version_str);

            if (!$plan_stmt->execute()) {
                throw new Exception(t_def('groups.error.default_plan_insert_failed', 'Default loop plan insert failed'));
            }

            $plan_stmt->close();
            $conn->commit();
            $success = t_def('groups.success.created', 'Csoport sikeresen létrehozva.');
        } catch (Exception $e) {
            if ($conn) {
                try { $conn->rollback(); } catch (Throwable $rollbackError) {}
            }
            if ($error === '') {
                $error = t_def('groups.error.db', 'Adatbázis hiba');
                error_log($e->getMessage());
            }
        }
    }
}

// Handle group deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $group_id = intval($_GET['delete']);
    
    try {
        $stmt = $conn->prepare("SELECT company_id, is_default FROM kiosk_groups WHERE id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $group = $result->fetch_assoc();
        
        if ($group && ($is_admin || $group['company_id'] == $company_id)) {
            if ((int)$group['is_default'] === 1) {
                $error = t_def('groups.error.default_not_deletable', 'Az alapértelmezett csoport nem törölhető');
            } else {
                if ($default_group_id) {
                    $move_stmt = $conn->prepare("INSERT IGNORE INTO kiosk_group_assignments (kiosk_id, group_id)
                                                 SELECT kiosk_id, ? FROM kiosk_group_assignments WHERE group_id = ?");
                    $move_stmt->bind_param("ii", $default_group_id, $group_id);
                    $move_stmt->execute();
                    $move_stmt->close();
                }

                $stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE group_id = ?");
                $stmt->bind_param("i", $group_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM kiosk_groups WHERE id = ?");
                $stmt->bind_param("i", $group_id);
                
                if ($stmt->execute()) {
                    $success = t_def('groups.success.deleted_and_moved', 'Csoport sikeresen törölve, a kijelzők átkerültek az alapértelmezett csoportba');
                } else {
                    $error = t_def('groups.error.delete_failed', 'A csoport törlése sikertelen');
                }
            }
        } else {
            $error = t_def('groups.error.access_denied', 'Hozzáférés megtagadva');
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $error = t_def('groups.error.db', 'Adatbázis hiba');
        error_log($e->getMessage());
    }
}

// Get groups for this company
$groups = [];
try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = getDbConnection();
    }

    if ((int)$company_id <= 0) {
        throw new Exception('Missing company_id for groups listing');
    }

    $active_filter_sql = $has_group_modules_is_active ? ' AND kgm.is_active = 1' : '';
    $module_time_sql = 'NULL';
    if ($has_group_modules_updated_at && $has_group_modules_created_at) {
        $module_time_sql = 'COALESCE(kgm.updated_at, kgm.created_at)';
    } elseif ($has_group_modules_updated_at) {
        $module_time_sql = 'kgm.updated_at';
    } elseif ($has_group_modules_created_at) {
        $module_time_sql = 'kgm.created_at';
    }

    $group_fallback_sql = $has_group_created_at
        ? "DATE_FORMAT(g.created_at, '%Y%m%d%H%i%s')"
        : "DATE_FORMAT(NOW(), '%Y%m%d%H%i%s')";

    $query = "SELECT g.*,
              (SELECT COUNT(*) FROM kiosk_group_assignments WHERE group_id = g.id) as kiosk_count,
                            COALESCE(
                                (SELECT DATE_FORMAT(MAX({$module_time_sql}), '%Y%m%d%H%i%s')
                 FROM kiosk_group_modules kgm
                                WHERE kgm.group_id = g.id{$active_filter_sql}),
                                {$group_fallback_sql}
                            ) as loop_version
              FROM kiosk_groups g 
              WHERE g.company_id = ? 
              ORDER BY g.priority DESC, g.name";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Group list query prepare failed: ' . $conn->error);
    }
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    $error = t_def('groups.error.db', 'Adatbázis hiba');
    if ($is_admin) {
        $error .= ': ' . $e->getMessage();
    }
    error_log($e->getMessage());
}

closeDbConnection($conn);
?>
<?php include '../admin/header.php'; ?>
    <style>
        .group-row.dragging {
            opacity: 0.5;
        }

        .drag-handle {
            cursor: grab;
            font-size: 16px;
            color: #666;
        }

        .group-row.not-draggable .drag-handle {
            cursor: not-allowed;
            opacity: 0.4;
        }

        .group-kiosk-chip {
            background: #e7f3ff;
            color: #0b4b8a;
            padding: 6px 10px;
            border-radius: 16px;
            font-size: 12px;
            cursor: pointer;
            display: inline-block;
            font-weight: 600;
            border: 1px solid #bfd8f6;
        }

        .group-action-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .group-action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 92px;
        }

        .group-action-btn-primary {
            background: #1a3a52;
            color: #fff;
            border-color: #0f2537;
        }

        .group-action-btn-secondary {
            background: #eef1f4;
            color: #22313f;
            border-color: #cfd9e2;
        }

        .group-action-btn-danger {
            background: #fbe8e8;
            color: #9c1e1e;
            border-color: #efc8c8;
            min-width: 46px;
            padding: 6px 10px;
        }

        .group-action-btn-disabled {
            background: #eef1f4;
            color: #9aa5b1;
            border-color: #d8dfe6;
            cursor: not-allowed;
        }

        .new-group-separator {
            background: #f5f8fb;
        }

        .new-group-separator td {
            padding: 10px 12px;
            border-top: 2px solid #d9e2ea;
            border-bottom: 1px solid #d9e2ea;
            font-size: 12px;
            color: #40566b;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .new-group-row {
            background: #fbfdff;
        }

        .new-group-row input[type="text"] {
            width: 100%;
        }

        @media (max-width: 768px) {
            .new-group-row td {
                display: block;
                width: 100% !important;
                border-bottom: none;
            }
        }
    </style>

    <div id="groups-notifier" style="position:fixed; top:20px; right:20px; z-index:3000; min-width:280px; max-width:420px; display:none; padding:12px 14px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,.2); font-size:13px; font-weight:600;"></div>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Groups Table -->
        <div class="card">
            <div style="margin-bottom: 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <div style="font-size: 14px; font-weight: 700; color: #1f2d3d;"><?php echo htmlspecialchars(t_def('groups.title', 'Csoportok')); ?> (<?php echo count($groups); ?>)</div>
                <a href="group_kiosks.php" class="btn btn-primary"><?php echo htmlspecialchars(t_def('groups.assignment_button', 'Kijelzők hozzárendelése csoportokhoz')); ?></a>
            </div>
            
            <form method="POST" action="">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th><?php echo htmlspecialchars(t_def('groups.table.name', 'Csoport neve')); ?></th>
                            <th><?php echo htmlspecialchars(t_def('groups.table.description', 'Leírás')); ?></th>
                            <th><?php echo htmlspecialchars(t_def('groups.table.kiosks', 'Kijelzők')); ?></th>
                            <th><?php echo htmlspecialchars(t_def('groups.table.priority', 'Prioritás')); ?></th>
                            <th><?php echo htmlspecialchars(t_def('groups.table.actions', 'Műveletek')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="groupsTableBody">
                        <?php foreach ($groups as $group): ?>
                            <tr class="group-row <?php echo !empty($group['is_default']) ? 'not-draggable' : ''; ?>" data-group-id="<?php echo $group['id']; ?>" data-default="<?php echo !empty($group['is_default']) ? '1' : '0'; ?>">
                                <td style="width: 32px; text-align: center;">
                                    <?php if (!empty($group['is_default'])): ?>
                                        <span class="drag-handle" title="<?php echo htmlspecialchars(t_def('groups.default_group_title', 'Alapértelmezett csoport')); ?>">🔒</span>
                                    <?php else: ?>
                                        <span class="drag-handle" title="<?php echo htmlspecialchars(t_def('groups.drag_to_reorder', 'Húzd a sorrendhez')); ?>">☰</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php
                                            $group_loop_version = format_loop_version($group['loop_version'] ?? null) ?? 'n/a';
                                            $is_default_group = !empty($group['is_default']);
                                        ?>
                                        <strong id="group-name-<?php echo $group['id']; ?>"<?php echo !$is_default_group ? ' title="Loop verzió: ' . htmlspecialchars($group_loop_version) . '"' : ''; ?>>
                                            <?php echo htmlspecialchars($group['name']); ?>
                                        </strong>
                                        <?php if (!empty($group['is_default'])): ?>
                                            <span style="background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;"><?php echo htmlspecialchars(t_def('groups.default_badge', 'Alapértelmezett')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!$is_default_group): ?>
                                        <div style="font-size:11px;color:#75879a;margin-top:2px;"><?php echo htmlspecialchars(t_def('groups.loop_version', 'Loop verzió')); ?>: <?php echo htmlspecialchars($group_loop_version); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="color: #666; font-size: 13px;">
                                    <?php echo htmlspecialchars($group['description'] ?? '—'); ?>
                                </td>
                                <td>
                                    <span class="group-kiosk-chip">
                                        <?php echo (int)$group['kiosk_count']; ?> <?php echo htmlspecialchars(t_def('groups.kiosk_unit', 'kijelző')); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="priority-value" id="group-priority-value-<?php echo $group['id']; ?>" style="font-size: 12px; color: #333; font-weight: 600;">
                                        <?php echo (int)$group['priority']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($group['is_default'])): ?>
                                        <span style="color:#9aa5b1; font-size:12px;">—</span>
                                    <?php else: ?>
                                        <div class="group-action-row">
                                            <a href="group_loop/index.php?id=<?php echo htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8'); ?>" class="group-action-btn group-action-btn-primary">⚙️ <?php echo htmlspecialchars(t_def('groups.customize', 'Testreszabás')); ?></a>
                                            <a href="?delete=<?php echo htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8'); ?>" class="group-action-btn group-action-btn-danger" onclick="return confirm('<?php echo htmlspecialchars(t_def('groups.confirm_delete', 'Biztosan törlöd ezt a csoportot?'), ENT_QUOTES, 'UTF-8'); ?>');">🗑️</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tbody>
                        <tr class="new-group-separator">
                            <td colspan="6"><?php echo htmlspecialchars(t_def('groups.new_group_section', 'Új csoport létrehozása')); ?></td>
                        </tr>
                        <tr class="new-group-row">
                            <td></td>
                            <td><input type="text" id="group_name" name="group_name" required placeholder="<?php echo htmlspecialchars(t_def('groups.placeholder.name', 'Csoport neve')); ?>"></td>
                            <td><input type="text" id="description" name="description" placeholder="<?php echo htmlspecialchars(t_def('groups.placeholder.description', 'pl. Emelet 1, Épület A')); ?>"></td>
                            <td style="color:#75879a; font-size:12px;">0 <?php echo htmlspecialchars(t_def('groups.kiosk_unit', 'kijelző')); ?></td>
                            <td style="color:#75879a; font-size:12px;"><?php echo htmlspecialchars(t_def('groups.auto', 'Automatikus')); ?></td>
                            <td><button type="submit" name="create_group" class="btn btn-primary" style="width: 100%;">+ <?php echo htmlspecialchars(t_def('groups.create', 'Létrehozás')); ?></button></td>
                        </tr>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <script>
        const groupsI18n = <?php echo json_encode([
            'errorOccurred' => t_def('groups.error_occurred', 'Hiba történt: {error}'),
            'serverError' => (string)$error,
            'serverSuccess' => (string)$success
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        const totalGroups = <?php echo count($groups); ?>;

        function showGroupsNotice(message, type = 'error') {
            const notifier = document.getElementById('groups-notifier');
            if (!notifier || !message) {
                return;
            }

            notifier.textContent = message;
            if (type === 'success') {
                notifier.style.background = '#e8f5e9';
                notifier.style.color = '#1b5e20';
                notifier.style.border = '1px solid #a5d6a7';
            } else {
                notifier.style.background = '#ffebee';
                notifier.style.color = '#b71c1c';
                notifier.style.border = '1px solid #ef9a9a';
            }
            notifier.style.display = 'block';

            window.clearTimeout(showGroupsNotice.__timer);
            showGroupsNotice.__timer = window.setTimeout(() => {
                notifier.style.display = 'none';
            }, type === 'success' ? 2800 : 4200);
        }

        function updatePriorityLabels() {
            const rows = Array.from(document.querySelectorAll('#groupsTableBody .group-row'));
            let nextPriority = totalGroups;

            rows.forEach(row => {
                const groupId = row.getAttribute('data-group-id');
                const isDefault = row.getAttribute('data-default') === '1';
                const label = document.getElementById('group-priority-value-' + groupId);

                if (!label) return;

                if (isDefault) {
                    label.textContent = '1';
                } else {
                    label.textContent = String(nextPriority);
                    nextPriority -= 1;
                    if (nextPriority === 1) {
                        nextPriority -= 1;
                    }
                }
            });
        }

        function persistGroupOrder() {
            const rows = Array.from(document.querySelectorAll('#groupsTableBody .group-row'));
            const orderedIds = rows
                .filter(row => row.getAttribute('data-default') !== '1')
                .map(row => parseInt(row.getAttribute('data-group-id'), 10));

            fetch('../api/update_group_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ordered_ids: orderedIds })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updatePriorityLabels();
                } else {
                    showGroupsNotice('⚠️ ' + (data.message || 'Ismeretlen hiba'), 'error');
                }
            })
            .catch(error => {
                showGroupsNotice('⚠️ ' + groupsI18n.errorOccurred.replace('{error}', String(error)), 'error');
            });
        }

        function initDragAndDrop() {
            const tbody = document.getElementById('groupsTableBody');
            const rows = Array.from(tbody.querySelectorAll('.group-row'));
            const defaultRow = tbody.querySelector('.group-row[data-default="1"]');
            let draggedRow = null;

            rows.forEach(row => {
                const isDefault = row.getAttribute('data-default') === '1';
                if (isDefault) {
                    row.draggable = false;
                    return;
                }

                row.draggable = true;
                row.addEventListener('dragstart', () => {
                    draggedRow = row;
                    row.classList.add('dragging');
                });

                row.addEventListener('dragend', () => {
                    row.classList.remove('dragging');
                    draggedRow = null;
                    persistGroupOrder();
                });
            });

            tbody.addEventListener('dragover', (event) => {
                if (!draggedRow) return;
                event.preventDefault();

                const afterElement = getDragAfterElement(tbody, event.clientY);
                if (afterElement == null) {
                    if (defaultRow) {
                        tbody.insertBefore(draggedRow, defaultRow);
                    } else {
                        tbody.appendChild(draggedRow);
                    }
                } else if (afterElement !== draggedRow) {
                    tbody.insertBefore(draggedRow, afterElement);
                }
            });
        }

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.group-row:not(.dragging)')].filter(row => row.getAttribute('data-default') !== '1');

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
        }

        updatePriorityLabels();
        initDragAndDrop();

        if (groupsI18n.serverError) {
            showGroupsNotice('⚠️ ' + groupsI18n.serverError, 'error');
        } else if (groupsI18n.serverSuccess) {
            showGroupsNotice('✓ ' + groupsI18n.serverSuccess, 'success');
        }
    </script>
<?php include '../admin/footer.php'; ?>

