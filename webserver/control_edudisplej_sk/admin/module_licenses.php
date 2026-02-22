<?php
/**
 * Module License Management - Simplified per-company editor
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

function edudisplej_admin_ensure_module_present(mysqli $conn, string $moduleKey, string $name, string $description): void {
    $stmt = $conn->prepare('SELECT id, is_active FROM modules WHERE module_key = ? LIMIT 1');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $moduleKey);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        $insert = $conn->prepare('INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, 1)');
        if ($insert) {
            $insert->bind_param('sss', $moduleKey, $name, $description);
            $insert->execute();
            $insert->close();
        }
        return;
    }

    $moduleId = (int)($existing['id'] ?? 0);
    if ($moduleId <= 0) {
        return;
    }

    $update = $conn->prepare('UPDATE modules SET name = ?, description = ?, is_active = 1 WHERE id = ?');
    if ($update) {
        $update->bind_param('ssi', $name, $description, $moduleId);
        $update->execute();
        $update->close();
    }
}

$error = '';
$success = '';
$selected_company_id = (int)($_GET['company_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company_licenses'])) {
    $company_id = (int)($_POST['company_id'] ?? 0);
    $enabled_modules = isset($_POST['enabled_modules']) && is_array($_POST['enabled_modules']) ? $_POST['enabled_modules'] : [];
    $module_quantities = isset($_POST['module_quantities']) && is_array($_POST['module_quantities']) ? $_POST['module_quantities'] : [];
    $selected_company_id = $company_id;

    if ($company_id <= 0) {
        $error = 'Invalid company';
    } else {
        try {
            $conn = getDbConnection();

            $company_stmt = $conn->prepare('SELECT id FROM companies WHERE id = ? LIMIT 1');
            $company_stmt->bind_param('i', $company_id);
            $company_stmt->execute();
            $company_exists = $company_stmt->get_result()->num_rows > 0;
            $company_stmt->close();

            if (!$company_exists) {
                $error = 'Company not found';
            } else {
                $modules = [];
                $module_result = $conn->query('SELECT id FROM modules WHERE is_active = 1 ORDER BY name');
                if ($module_result) {
                    while ($module_row = $module_result->fetch_assoc()) {
                        $modules[] = (int)$module_row['id'];
                    }
                }

                $enabled_lookup = [];
                foreach ($enabled_modules as $enabled_module_id) {
                    $enabled_lookup[(int)$enabled_module_id] = true;
                }

                $existing_lookup = [];
                $existing_stmt = $conn->prepare('SELECT id, module_id, quantity FROM module_licenses WHERE company_id = ?');
                $existing_stmt->bind_param('i', $company_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                while ($existing_row = $existing_result->fetch_assoc()) {
                    $existing_lookup[(int)$existing_row['module_id']] = [
                        'id' => (int)$existing_row['id'],
                        'quantity' => (int)($existing_row['quantity'] ?? 0),
                    ];
                }
                $existing_stmt->close();

                $conn->begin_transaction();

                $update_stmt = $conn->prepare('UPDATE module_licenses SET quantity = ? WHERE id = ?');
                $insert_stmt = $conn->prepare('INSERT INTO module_licenses (company_id, module_id, quantity) VALUES (?, ?, ?)');
                $delete_stmt = $conn->prepare('DELETE FROM module_licenses WHERE company_id = ? AND module_id = ?');

                foreach ($modules as $module_id) {
                    $is_enabled = isset($enabled_lookup[$module_id]);
                    $raw_quantity = isset($module_quantities[$module_id]) ? (int)$module_quantities[$module_id] : 0;
                    $normalized_quantity = $is_enabled ? max(1, $raw_quantity) : 0;
                    $existing = $existing_lookup[$module_id] ?? null;

                    if ($is_enabled) {
                        if ($existing) {
                            if ((int)$existing['quantity'] !== $normalized_quantity) {
                                $license_id = (int)$existing['id'];
                                $update_stmt->bind_param('ii', $normalized_quantity, $license_id);
                                $update_stmt->execute();
                            }
                        } else {
                            $insert_stmt->bind_param('iii', $company_id, $module_id, $normalized_quantity);
                            $insert_stmt->execute();
                        }
                    } else {
                        if ($existing) {
                            $delete_stmt->bind_param('ii', $company_id, $module_id);
                            $delete_stmt->execute();
                        }
                    }
                }

                $update_stmt->close();
                $insert_stmt->close();
                $delete_stmt->close();

                $conn->commit();
                $success = 'Company module licenses updated successfully';
            }

        } catch (Exception $e) {
            if (isset($conn) && $conn instanceof mysqli) {
                $conn->rollback();
                closeDbConnection($conn);
            }
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

$companies = [];
$modules = [];
$licenses_by_company = [];

try {
    $conn = getDbConnection();

    edudisplej_admin_ensure_module_present(
        $conn,
        'meal-menu',
        'Meal Menu',
        'Display school meal plan with source/institution filtering and offline fallback'
    );
    edudisplej_admin_ensure_module_present(
        $conn,
        'room-occupancy',
        'Room Occupancy',
        'Display room occupancy schedule with manual and external API sync support'
    );

    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $result = $conn->query("SELECT * FROM modules WHERE is_active = 1 ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }

    $query = "SELECT company_id, module_id, quantity FROM module_licenses";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $cid = (int)($row['company_id'] ?? 0);
        $mid = (int)($row['module_id'] ?? 0);
        if ($cid > 0 && $mid > 0) {
            if (!isset($licenses_by_company[$cid])) {
                $licenses_by_company[$cid] = [];
            }
            $licenses_by_company[$cid][$mid] = (int)($row['quantity'] ?? 0);
        }
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data';
    error_log($e->getMessage());
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Company module licenses</div>
    <div class="muted" style="margin-bottom:12px;">Open a company, check enabled modules, set quantity, then save. Checking a module auto-sets quantity to 1.</div>

    <?php if (empty($companies)): ?>
        <div class="muted">No companies.</div>
    <?php else: ?>
        <?php foreach ($companies as $company): ?>
            <?php
                $company_id = (int)($company['id'] ?? 0);
                $company_licenses = $licenses_by_company[$company_id] ?? [];
                $open = ($selected_company_id > 0 && $selected_company_id === $company_id);
                $enabled_count = 0;
                foreach ($modules as $module) {
                    $module_id = (int)$module['id'];
                    if (!empty($company_licenses[$module_id]) && (int)$company_licenses[$module_id] > 0) {
                        $enabled_count++;
                    }
                }
            ?>
            <details <?php echo $open ? 'open' : ''; ?> style="margin-bottom:10px; border:1px solid #d7dde5; border-radius:6px; background:#fff;">
                <summary style="padding:10px 12px; cursor:pointer; font-weight:600;">
                    <?php echo htmlspecialchars((string)$company['name']); ?> (<?php echo $enabled_count; ?> enabled)
                </summary>
                <div style="padding:12px; border-top:1px solid #e5e7eb;">
                    <form method="post">
                        <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Enabled</th>
                                        <th>Module</th>
                                        <th style="width:160px;">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($modules as $module): ?>
                                        <?php
                                            $module_id = (int)$module['id'];
                                            $qty = isset($company_licenses[$module_id]) ? (int)$company_licenses[$module_id] : 0;
                                            $checked = $qty > 0;
                                            $qty_value = $checked ? $qty : 0;
                                            $qty_input_id = 'qty_' . $company_id . '_' . $module_id;
                                        ?>
                                        <tr>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    class="module-toggle"
                                                    name="enabled_modules[]"
                                                    value="<?php echo $module_id; ?>"
                                                    data-qty-id="<?php echo htmlspecialchars($qty_input_id); ?>"
                                                    <?php echo $checked ? 'checked' : ''; ?>
                                                >
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars((string)$module['name']); ?>
                                                <span class="muted mono" style="margin-left:8px;"><?php echo htmlspecialchars((string)$module['module_key']); ?></span>
                                            </td>
                                            <td>
                                                <input
                                                    id="<?php echo htmlspecialchars($qty_input_id); ?>"
                                                    name="module_quantities[<?php echo $module_id; ?>]"
                                                    type="number"
                                                    min="0"
                                                    max="999"
                                                    value="<?php echo $qty_value; ?>"
                                                    <?php echo $checked ? '' : 'disabled'; ?>
                                                >
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top:10px;">
                            <button type="submit" name="save_company_licenses" class="btn btn-primary">Save company licenses</button>
                        </div>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.module-toggle').forEach(function (toggle) {
    toggle.addEventListener('change', function () {
        var qtyId = toggle.getAttribute('data-qty-id');
        if (!qtyId) {
            return;
        }
        var qtyInput = document.getElementById(qtyId);
        if (!qtyInput) {
            return;
        }

        if (toggle.checked) {
            qtyInput.disabled = false;
            var current = parseInt(qtyInput.value || '0', 10);
            if (isNaN(current) || current < 1) {
                qtyInput.value = 1;
            }
        } else {
            qtyInput.value = 0;
            qtyInput.disabled = true;
        }
    });
});
</script>

<?php include 'footer.php'; ?>
