<?php
session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once dirname(__DIR__) . '/modules/module_standard.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

$project_root = dirname(__DIR__);
$modules_root = realpath($project_root . '/modules') ?: ($project_root . '/modules');

function edudisplej_safe_rrmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $items = scandir($dir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!edudisplej_safe_rrmdir($path)) {
                return false;
            }
        } elseif (!@unlink($path)) {
            return false;
        }
    }

    return @rmdir($dir);
}

function edudisplej_read_json_file(string $absPath): ?array
{
    if (!is_file($absPath)) {
        return null;
    }

    $raw = @file_get_contents($absPath);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function edudisplej_is_protected_module_key(string $moduleKey): bool
{
    return strtolower(trim($moduleKey)) === 'unconfigured';
}

function edudisplej_extract_zip_to_temp(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'ZIP extension is not available on server'];
    }

    $tmpBase = sys_get_temp_dir() . '/edudisplej_module_import_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpBase, 0775, true) && !is_dir($tmpBase)) {
        return ['ok' => false, 'message' => 'Failed to create temp directory'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        edudisplej_safe_rrmdir($tmpBase);
        return ['ok' => false, 'message' => 'Invalid ZIP file'];
    }

    if (!$zip->extractTo($tmpBase)) {
        $zip->close();
        edudisplej_safe_rrmdir($tmpBase);
        return ['ok' => false, 'message' => 'Failed to extract ZIP'];
    }

    $zip->close();
    return ['ok' => true, 'temp_dir' => $tmpBase];
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $module_id = (int)$_GET['toggle'];

    if ($module_id > 0) {
        try {
            $conn = getDbConnection();

            $check_stmt = $conn->prepare('SELECT module_key FROM modules WHERE id = ? LIMIT 1');
            $check_stmt->bind_param('i', $module_id);
            $check_stmt->execute();
            $toggle_module = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if (!$toggle_module) {
                $error = 'Module not found';
                closeDbConnection($conn);
            } elseif (edudisplej_is_protected_module_key((string)$toggle_module['module_key'])) {
                $error = 'Unconfigured module cannot be deactivated';
                closeDbConnection($conn);
            } else {
                $stmt = $conn->prepare("UPDATE modules SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->bind_param("i", $module_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success = 'Module status updated successfully';
            } else {
                $error = 'Module not found';
            }

            $stmt->close();
            closeDbConnection($conn);
            }
        } catch (Exception $e) {
            $error = 'Failed to update module status';
            error_log($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_module'])) {
    $module_id = (int)($_POST['module_id'] ?? 0);

    if ($module_id <= 0) {
        $error = 'Invalid module id';
    } else {
        try {
            $conn = getDbConnection();

            $stmt = $conn->prepare('SELECT id, module_key, name FROM modules WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $module_id);
            $stmt->execute();
            $module_to_delete = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$module_to_delete) {
                $error = 'Module not found';
            } elseif (edudisplej_is_protected_module_key((string)$module_to_delete['module_key'])) {
                $error = 'Unconfigured module cannot be deleted';
            } else {
                $deleted_key = (string)$module_to_delete['module_key'];
                $deleted_name = (string)$module_to_delete['name'];
                $deleted_meta = edudisplej_module_meta($deleted_key);
                $deleted_folder_key = $deleted_meta['folder_key'] ?? null;
                $deleted_folder = $deleted_meta['folder'] ?? $deleted_key;

                $conn->begin_transaction();

                $delete_stmt = $conn->prepare('DELETE FROM modules WHERE id = ?');
                $delete_stmt->bind_param('i', $module_id);
                $delete_stmt->execute();
                $delete_stmt->close();

                $remaining_stmt = $conn->prepare('SELECT module_key FROM modules');
                $remaining_stmt->execute();
                $remaining_result = $remaining_stmt->get_result();

                $folder_still_used = false;
                if ($deleted_folder_key !== null) {
                    while ($row = $remaining_result->fetch_assoc()) {
                        $other_meta = edudisplej_module_meta((string)$row['module_key']);
                        if ($other_meta && ($other_meta['folder_key'] ?? null) === $deleted_folder_key) {
                            $folder_still_used = true;
                            break;
                        }
                    }
                }
                $remaining_stmt->close();

                $conn->commit();

                $deleted_folder_rel = 'modules/' . trim(str_replace('\\', '/', $deleted_folder), '/');
                $deleted_folder_abs = $project_root . '/' . $deleted_folder_rel;

                if (!$folder_still_used && is_dir($deleted_folder_abs)) {
                    if (edudisplej_safe_rrmdir($deleted_folder_abs)) {
                        $success = 'Module deleted: ' . $deleted_name . ' (folder removed: ' . $deleted_folder_rel . ')';
                    } else {
                        $success = 'Module deleted: ' . $deleted_name . ' (folder remove failed: ' . $deleted_folder_rel . ')';
                    }
                } else {
                    $success = 'Module deleted: ' . $deleted_name;
                    if ($folder_still_used) {
                        $success .= ' (shared folder kept)';
                    }
                }
            }

            closeDbConnection($conn);
        } catch (Exception $e) {
            if (isset($conn) && $conn instanceof mysqli) {
                @$conn->rollback();
                closeDbConnection($conn);
            }
            $error = 'Failed to delete module';
            error_log($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_module'])) {
    $module_id = (int)($_POST['module_id'] ?? 0);
    $module_key = strtolower(trim((string)($_POST['module_key'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if ($module_key === '' || $name === '') {
        $error = 'Module key and name are required';
    } elseif (!edudisplej_module_key_is_valid($module_key)) {
        $error = 'Module key format invalid (allowed: a-z, 0-9, dot, underscore, dash)';
    } else {
        try {
            $conn = getDbConnection();

            if ($module_id > 0) {
                $existing_stmt = $conn->prepare('SELECT module_key FROM modules WHERE id = ? LIMIT 1');
                $existing_stmt->bind_param('i', $module_id);
                $existing_stmt->execute();
                $existing_module = $existing_stmt->get_result()->fetch_assoc();
                $existing_stmt->close();

                if ($existing_module && edudisplej_is_protected_module_key((string)$existing_module['module_key'])) {
                    $module_key = 'unconfigured';
                    $is_active = 1;
                }

                $stmt = $conn->prepare("UPDATE modules SET module_key = ?, name = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("sssii", $module_key, $name, $description, $is_active, $module_id);
                $stmt->execute();

                if ($stmt->affected_rows >= 0) {
                    $success = 'Module updated successfully';
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $module_key, $name, $description, $is_active);
                $stmt->execute();
                $success = 'Module created successfully';
            }

            $stmt->close();
            closeDbConnection($conn);
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                $error = 'Module key already exists';
            } else {
                $error = 'Database error occurred';
                error_log($e->getMessage());
            }
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_module_zip'])) {
    $file = $_FILES['module_zip'] ?? null;
    $overwrite = isset($_POST['overwrite_existing']) && $_POST['overwrite_existing'] === '1';

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Module ZIP upload failed';
    } else {
        $extracted = edudisplej_extract_zip_to_temp((string)$file['tmp_name']);
        if (!($extracted['ok'] ?? false)) {
            $error = (string)($extracted['message'] ?? 'Failed to process ZIP');
        } else {
            $tempDir = (string)$extracted['temp_dir'];
            try {
                $packageRoot = edudisplej_detect_package_root($tempDir);
                if ($packageRoot === null) {
                    throw new RuntimeException('module.json not found in ZIP root');
                }

                $manifest = edudisplej_read_json_file_safe($packageRoot . '/module.json');
                if (!$manifest) {
                    throw new RuntimeException('module.json is missing or invalid JSON');
                }

                $manifestErrors = edudisplej_validate_manifest_payload($manifest);
                if (!empty($manifestErrors)) {
                    throw new RuntimeException(implode('; ', $manifestErrors));
                }

                $moduleKey = strtolower(trim((string)$manifest['module_key']));
                $moduleName = trim((string)$manifest['name']);
                $moduleDescription = trim((string)($manifest['description'] ?? ''));
                $rendererRel = trim((string)$manifest['renderer']);
                $defaultsRel = trim((string)$manifest['config']['defaults']);
                $folderName = trim((string)($manifest['folder'] ?? $moduleKey));
                $folderName = trim(str_replace('\\', '/', $folderName), '/');

                if ($folderName === '' || preg_match('/\.\./', $folderName)) {
                    throw new RuntimeException('Invalid module folder value in manifest');
                }

                if (!is_file($packageRoot . '/' . $rendererRel)) {
                    throw new RuntimeException('Renderer file missing: ' . $rendererRel);
                }

                if (!is_file($packageRoot . '/' . $defaultsRel)) {
                    throw new RuntimeException('Default settings file missing: ' . $defaultsRel);
                }

                $targetDir = rtrim($modules_root, '/\\') . '/' . $folderName;

                if (is_dir($targetDir)) {
                    if (!$overwrite) {
                        throw new RuntimeException('Target module folder already exists: modules/' . $folderName . ' (enable overwrite to replace)');
                    }
                    if (!edudisplej_safe_rrmdir($targetDir)) {
                        throw new RuntimeException('Failed to remove existing module folder before overwrite');
                    }
                }

                if (!edudisplej_safe_recursive_copy($packageRoot, $targetDir)) {
                    throw new RuntimeException('Failed to install module files');
                }

                $conn = getDbConnection();

                $findStmt = $conn->prepare('SELECT id FROM modules WHERE module_key = ? LIMIT 1');
                $findStmt->bind_param('s', $moduleKey);
                $findStmt->execute();
                $existing = $findStmt->get_result()->fetch_assoc();
                $findStmt->close();

                if ($existing) {
                    $updateStmt = $conn->prepare('UPDATE modules SET name = ?, description = ?, is_active = 1 WHERE id = ?');
                    $updateStmt->bind_param('ssi', $moduleName, $moduleDescription, $existing['id']);
                    $updateStmt->execute();
                    $updateStmt->close();
                    $success = 'Module re-imported successfully: ' . $moduleKey;
                } else {
                    $insertStmt = $conn->prepare('INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, 1)');
                    $insertStmt->bind_param('sss', $moduleKey, $moduleName, $moduleDescription);
                    $insertStmt->execute();
                    $insertStmt->close();
                    $success = 'Module imported successfully: ' . $moduleKey;
                }

                closeDbConnection($conn);
            } catch (Exception $e) {
                if (isset($conn) && $conn instanceof mysqli) {
                    closeDbConnection($conn);
                }
                $error = 'Import failed: ' . $e->getMessage();
            } finally {
                edudisplej_safe_rrmdir($tempDir);
            }
        }
    }
}

$modules = [];

try {
    $conn = getDbConnection();

        $query = "SELECT m.*, 
                     COUNT(DISTINCT ml.id) AS license_rows,
                COUNT(DISTINCT km.id) AS kiosk_rows,
                COUNT(DISTINCT kgm.id) AS group_rows
              FROM modules m
              LEFT JOIN module_licenses ml ON ml.module_id = m.id
              LEFT JOIN kiosk_modules km ON km.module_id = m.id
            LEFT JOIN kiosk_group_modules kgm ON kgm.module_id = m.id
              GROUP BY m.id
              ORDER BY m.name";
    $result = $conn->query($query);

    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load modules';
    error_log($e->getMessage());
}

$edit_module = null;
$view_module = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($modules as $module) {
        if ((int)$module['id'] === (int)$_GET['edit']) {
            $edit_module = $module;
            break;
        }
    }
}

$is_edit_protected = $edit_module && edudisplej_is_protected_module_key((string)$edit_module['module_key']);

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    foreach ($modules as $module) {
        if ((int)$module['id'] === (int)$_GET['view']) {
            $view_module = $module;
            break;
        }
    }
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($view_module): ?>
    <?php
        $view_key = (string)$view_module['module_key'];
        $view_meta = edudisplej_module_meta($view_key);
        $registry = edudisplej_module_registry();

        $folder_rel = $view_meta ? ('modules/' . trim((string)$view_meta['folder'], '/')) : ('modules/' . $view_key);
        $config_dir_rel = $view_meta['config_dir'] ?? ($folder_rel . '/config');
        $renderer_rel = $view_meta['renderer'] ?? ($folder_rel . '/m_' . $view_key . '.html');
        $default_settings_rel = $view_meta['default_settings_file'] ?? ($config_dir_rel . '/default_settings.json');

        $folder_abs = $project_root . '/' . $folder_rel;
        $manifest_abs = $project_root . '/' . $folder_rel . '/module.json';
        $defaults_abs = $project_root . '/' . $default_settings_rel;

        $manifest_data = edudisplej_read_json_file($manifest_abs);
        $default_settings_data = edudisplej_read_json_file($defaults_abs);
        $required_files = $registry['required_files'] ?? [];
    ?>
    <div class="panel">
        <div class="panel-title">Module details: <?php echo htmlspecialchars((string)$view_module['name']); ?></div>

        <div class="table-wrap" style="margin-bottom:12px;">
            <table>
                <tbody>
                    <tr>
                        <th style="width:220px;">Module key</th>
                        <td class="mono"><?php echo htmlspecialchars($view_key); ?></td>
                    </tr>
                    <tr>
                        <th>Module folder</th>
                        <td class="mono"><?php echo htmlspecialchars($folder_rel); ?></td>
                    </tr>
                    <tr>
                        <th>Config folder</th>
                        <td class="mono"><?php echo htmlspecialchars($config_dir_rel); ?></td>
                    </tr>
                    <tr>
                        <th>Renderer file</th>
                        <td class="mono"><?php echo htmlspecialchars($renderer_rel); ?></td>
                    </tr>
                    <tr>
                        <th>Default settings file</th>
                        <td class="mono"><?php echo htmlspecialchars($default_settings_rel); ?></td>
                    </tr>
                    <tr>
                        <th>Folder exists</th>
                        <td><?php echo is_dir($folder_abs) ? 'Yes' : 'No'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($view_meta && !empty($view_meta['functions'])): ?>
            <div style="margin-bottom:10px;">
                <strong>Functions:</strong>
                <?php foreach ($view_meta['functions'] as $func): ?>
                    <span class="badge info" style="margin-right:6px;"><?php echo htmlspecialchars((string)$func); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($view_meta && !empty($view_meta['settings_schema'])): ?>
            <div style="margin-bottom:10px;">
                <strong>Settings schema keys:</strong>
                <?php foreach ($view_meta['settings_schema'] as $setting_key): ?>
                    <span class="badge" style="margin-right:6px;"><?php echo htmlspecialchars((string)$setting_key); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="table-wrap" style="margin-bottom:12px;">
            <table>
                <thead>
                    <tr>
                        <th>Required structure</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($required_files)): ?>
                        <tr><td colspan="2" class="muted">No registry requirements.</td></tr>
                    <?php else: ?>
                        <?php foreach ($required_files as $required_rel): ?>
                            <?php $required_abs = $project_root . '/' . $folder_rel . '/' . $required_rel; ?>
                            <tr>
                                <td class="mono"><?php echo htmlspecialchars($folder_rel . '/' . $required_rel); ?></td>
                                <td><?php echo is_file($required_abs) ? 'OK' : 'Missing'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="form-row" style="align-items:flex-start;">
            <div class="form-field" style="min-width: 420px; flex: 1;">
                <label>Manifest (`module.json`)</label>
                <pre class="mono" style="white-space: pre-wrap;"><?php echo htmlspecialchars($manifest_data ? json_encode($manifest_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'N/A'); ?></pre>
            </div>
            <div class="form-field" style="min-width: 420px; flex: 1;">
                <label>Default settings</label>
                <pre class="mono" style="white-space: pre-wrap;"><?php echo htmlspecialchars($default_settings_data ? json_encode($default_settings_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'N/A'); ?></pre>
            </div>
        </div>

        <div class="form-field" style="margin-top: 8px;">
            <a class="btn btn-secondary" href="modules.php">Close details</a>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title"><?php echo $edit_module ? 'Edit module' : 'New module'; ?></div>
    <form method="post" class="form-row">
        <input type="hidden" name="module_id" value="<?php echo $edit_module ? (int)$edit_module['id'] : 0; ?>">

        <div class="form-field" style="min-width: 180px;">
            <label for="module_key">Module key</label>
            <input id="module_key" name="module_key" type="text" value="<?php echo htmlspecialchars($edit_module['module_key'] ?? ''); ?>" <?php echo $is_edit_protected ? 'readonly' : ''; ?> required>
        </div>

        <div class="form-field" style="min-width: 220px;">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($edit_module['name'] ?? ''); ?>" required>
        </div>

        <div class="form-field" style="min-width: 320px;">
            <label for="description">Description</label>
            <input id="description" name="description" type="text" value="<?php echo htmlspecialchars($edit_module['description'] ?? ''); ?>">
        </div>

        <div class="form-field">
            <label for="is_active">Active</label>
            <select id="is_active" name="is_active" <?php echo $is_edit_protected ? 'disabled' : ''; ?>>
                <option value="1" <?php echo ($edit_module && (int)$edit_module['is_active'] === 1) || !$edit_module ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?php echo $edit_module && (int)$edit_module['is_active'] === 0 ? 'selected' : ''; ?>>No</option>
            </select>
            <?php if ($is_edit_protected): ?>
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>

        <div class="form-field">
            <button type="submit" name="save_module" class="btn btn-primary">Save</button>
        </div>

        <?php if ($edit_module): ?>
            <div class="form-field">
                <a class="btn btn-secondary" href="modules.php">Cancel</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Import module package (ZIP)</div>
    <form method="post" enctype="multipart/form-data" class="form-row">
        <div class="form-field" style="min-width: 340px;">
            <label for="module_zip">ZIP file</label>
            <input id="module_zip" name="module_zip" type="file" accept=".zip" required>
        </div>

        <div class="form-field">
            <label for="overwrite_existing">Overwrite existing folder</label>
            <select id="overwrite_existing" name="overwrite_existing">
                <option value="0" selected>No</option>
                <option value="1">Yes</option>
            </select>
        </div>

        <div class="form-field">
            <button type="submit" name="import_module_zip" class="btn btn-primary">Import ZIP</button>
        </div>
    </form>
    <div class="muted" style="margin-top:8px;">
        Required package structure: <span class="mono">module.json</span>, <span class="mono">config/default_settings.json</span>, and renderer file defined in <span class="mono">module.json</span>.
    </div>
</div>

<div class="panel">
    <div class="panel-title">Module list</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Module key</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Active</th>
                    <th>Licenses</th>
                    <th>Kiosk uses</th>
                    <th>Group uses</th>
                    <th>Created at</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($modules)): ?>
                    <tr>
                        <td colspan="10" class="muted">No modules.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($modules as $module): ?>
                        <?php $is_protected = edudisplej_is_protected_module_key((string)$module['module_key']); ?>
                        <tr>
                            <td><?php echo (int)$module['id']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($module['module_key']); ?></td>
                            <td><?php echo htmlspecialchars($module['name']); ?></td>
                            <td><?php echo htmlspecialchars($module['description'] ?? '-'); ?></td>
                            <td><?php echo (int)$module['is_active'] === 1 ? 'Yes' : 'No'; ?></td>
                            <td><?php echo (int)$module['license_rows']; ?></td>
                            <td><?php echo (int)$module['kiosk_rows']; ?></td>
                            <td><?php echo (int)$module['group_rows']; ?></td>
                            <td class="nowrap"><?php echo $module['created_at'] ? date('Y-m-d H:i:s', strtotime($module['created_at'])) : '-'; ?></td>
                            <td class="nowrap">
                                <a class="btn btn-small" href="modules.php?view=<?php echo (int)$module['id']; ?>">Details</a>
                                <a class="btn btn-small" href="modules.php?edit=<?php echo (int)$module['id']; ?>">Edit</a>
                                <?php if ($is_protected): ?>
                                    <span class="btn btn-small btn-secondary" style="opacity:0.6; cursor:not-allowed;">Protected</span>
                                <?php else: ?>
                                    <a class="btn btn-small btn-secondary" href="modules.php?toggle=<?php echo (int)$module['id']; ?>">
                                        <?php echo (int)$module['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete module completely? This removes related assignments and licenses as well.');">
                                        <input type="hidden" name="module_id" value="<?php echo (int)$module['id']; ?>">
                                        <button class="btn btn-small btn-danger" type="submit" name="delete_module">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
