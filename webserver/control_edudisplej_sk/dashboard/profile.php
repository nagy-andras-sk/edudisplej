<?php
/**
 * Company Profile - Cég Adatai, Licenszek, Felhasználók
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';
require_once '../modules/module_asset_service.php';
require_once '../i18n.php';

function profile_format_bytes($bytes): string {
    $bytes = max(0, (int)$bytes);
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = $bytes / 1024;
    $unit_index = 0;
    while ($size >= 1024 && $unit_index < count($units) - 1) {
        $size /= 1024;
        $unit_index++;
    }
    return number_format($size, 2, ',', ' ') . ' ' . $units[$unit_index];
}

function profile_extract_asset_id_from_url(string $url): int {
    $query = (string)parse_url($url, PHP_URL_QUERY);
    if ($query === '') {
        return 0;
    }
    parse_str($query, $params);
    return (int)($params['asset_id'] ?? 0);
}

function profile_collect_used_assets(mysqli $conn, int $company_id): array {
    $used_asset_ids = [];
    $used_asset_paths = [];

    if ($company_id <= 0) {
        return [$used_asset_ids, $used_asset_paths];
    }

    $stmt = $conn->prepare(" 
        SELECT kgm.module_key, kgm.settings
        FROM kiosk_group_modules kgm
        INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
        WHERE kg.company_id = ? AND kgm.is_active = 1
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $settings_raw = (string)($row['settings'] ?? '');
        $settings = json_decode($settings_raw, true);
        if (!is_array($settings)) {
            continue;
        }

        $pdf_asset_id = (int)($settings['pdfAssetId'] ?? 0);
        if ($pdf_asset_id > 0) {
            $used_asset_ids[$pdf_asset_id] = true;
        }

        $pdf_asset_url = trim((string)($settings['pdfAssetUrl'] ?? ''));
        if ($pdf_asset_url !== '') {
            $pdf_url_asset_id = profile_extract_asset_id_from_url($pdf_asset_url);
            if ($pdf_url_asset_id > 0) {
                $used_asset_ids[$pdf_url_asset_id] = true;
            }
            $pdf_path = edudisplej_module_asset_extract_rel_path($pdf_asset_url);
            if ($pdf_path !== '') {
                $used_asset_paths[$pdf_path] = true;
            }
        }

        $image_urls_json = (string)($settings['imageUrlsJson'] ?? '[]');
        $image_urls = json_decode($image_urls_json, true);
        if (is_array($image_urls)) {
            foreach ($image_urls as $image_url_raw) {
                $image_url = trim((string)$image_url_raw);
                if ($image_url === '') {
                    continue;
                }
                $img_asset_id = profile_extract_asset_id_from_url($image_url);
                if ($img_asset_id > 0) {
                    $used_asset_ids[$img_asset_id] = true;
                }
                $img_path = edudisplej_module_asset_extract_rel_path($image_url);
                if ($img_path !== '') {
                    $used_asset_paths[$img_path] = true;
                }
            }
        }

        $video_asset_id = (int)($settings['videoAssetId'] ?? 0);
        if ($video_asset_id > 0) {
            $used_asset_ids[$video_asset_id] = true;
        }

        $video_asset_url = trim((string)($settings['videoAssetUrl'] ?? ''));
        if ($video_asset_url !== '') {
            $video_url_asset_id = profile_extract_asset_id_from_url($video_asset_url);
            if ($video_url_asset_id > 0) {
                $used_asset_ids[$video_url_asset_id] = true;
            }
            $video_path = edudisplej_module_asset_extract_rel_path($video_asset_url);
            if ($video_path !== '') {
                $used_asset_paths[$video_path] = true;
            }
        }
    }
    $stmt->close();

    return [$used_asset_ids, $used_asset_paths];
}

function profile_asset_is_in_use(int $asset_id, string $asset_path, array $used_asset_ids, array $used_asset_paths): bool {
    return ($asset_id > 0 && isset($used_asset_ids[$asset_id]))
        || ($asset_path !== '' && isset($used_asset_paths[$asset_path]));
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$company_id = null;
$company_name = '';
$company_data = [];
$licenses = [];
$enabled_licenses = [];
$disabled_licenses = [];
$company_users = [];
$company_assets_summary = [];
$company_assets_recent = [];
$used_asset_ids = [];
$used_asset_paths = [];
$error = '';
$success = '';
$is_admin_user = !empty($_SESSION['isadmin']);
$can_manage_assets = false;
$current_role = edudisplej_get_session_role();
$current_lang = edudisplej_apply_language_preferences();

if ($current_role === 'content_editor' || $current_role === 'loop_manager') {
    header('Location: index.php');
    exit();
}

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);

    // Ensure optional institution columns exist
    $existing_company_columns = [];
    $columns_result = $conn->query("SHOW COLUMNS FROM companies");
    if ($columns_result) {
        while ($column = $columns_result->fetch_assoc()) {
            $existing_company_columns[$column['Field']] = true;
        }
    }
    if (!isset($existing_company_columns['address'])) {
        $conn->query("ALTER TABLE companies ADD COLUMN address TEXT DEFAULT NULL AFTER name");
    }
    if (!isset($existing_company_columns['tax_number'])) {
        $conn->query("ALTER TABLE companies ADD COLUMN tax_number VARCHAR(64) DEFAULT NULL AFTER address");
    }
    
    // Get current user info
    $stmt = $conn->prepare("SELECT id, company_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        $conn->close();
        header('Location: ../login.php');
        exit();
    }
    
    $company_id = (int)($user['company_id'] ?? 0);
    if ($is_admin_user && !empty($_SESSION['company_id'])) {
        $company_id = (int)$_SESSION['company_id'];
    }
    $can_manage_assets = ($company_id > 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($can_manage_assets && $company_id > 0)
        && (isset($_POST['delete_asset']) || isset($_POST['delete_selected_assets']))) {
        edudisplej_module_asset_ensure_schema($conn);
        [$used_asset_ids, $used_asset_paths] = profile_collect_used_assets($conn, $company_id);

        $delete_asset_ids = [];
        if (isset($_POST['delete_selected_assets'])) {
            $selected_asset_ids = $_POST['delete_asset_ids'] ?? [];
            if (is_array($selected_asset_ids)) {
                foreach ($selected_asset_ids as $selected_asset_id) {
                    $selected_id = (int)$selected_asset_id;
                    if ($selected_id > 0) {
                        $delete_asset_ids[$selected_id] = true;
                    }
                }
            }
        } else {
            $single_delete_asset_id = (int)($_POST['delete_asset_id'] ?? 0);
            if ($single_delete_asset_id > 0) {
                $delete_asset_ids[$single_delete_asset_id] = true;
            }
        }

        $delete_asset_ids = array_keys($delete_asset_ids);
        if (empty($delete_asset_ids)) {
            $error = t_def('profile.assets.delete_invalid', 'Érvénytelen tartalom azonosító.');
        } else {
            $deleted_count = 0;
            $not_found_count = 0;
            $already_deleted_count = 0;
            $used_count = 0;
            $failed_count = 0;

            foreach ($delete_asset_ids as $delete_asset_id) {
                $asset_stmt = $conn->prepare('SELECT id, storage_rel_path, is_active FROM module_asset_store WHERE id = ? AND company_id = ? LIMIT 1');
                $asset_stmt->bind_param('ii', $delete_asset_id, $company_id);
                $asset_stmt->execute();
                $asset_row = $asset_stmt->get_result()->fetch_assoc();
                $asset_stmt->close();

                if (!$asset_row) {
                    $not_found_count++;
                    continue;
                }

                $normalized_path = edudisplej_module_asset_extract_rel_path((string)($asset_row['storage_rel_path'] ?? ''));
                if (profile_asset_is_in_use($delete_asset_id, $normalized_path, $used_asset_ids, $used_asset_paths)) {
                    $used_count++;
                    continue;
                }

                if ((int)($asset_row['is_active'] ?? 0) !== 1) {
                    $already_deleted_count++;
                    continue;
                }

                $deactivate_stmt = $conn->prepare('UPDATE module_asset_store SET is_active = 0 WHERE id = ? AND company_id = ? LIMIT 1');
                $deactivate_stmt->bind_param('ii', $delete_asset_id, $company_id);
                $deleted = $deactivate_stmt->execute();
                $deactivate_stmt->close();

                if (!$deleted) {
                    $failed_count++;
                    continue;
                }

                if ($normalized_path !== '') {
                    $active_ref_stmt = $conn->prepare('SELECT COUNT(*) AS active_count FROM module_asset_store WHERE company_id = ? AND storage_rel_path = ? AND is_active = 1');
                    $active_ref_stmt->bind_param('is', $company_id, $normalized_path);
                    $active_ref_stmt->execute();
                    $active_refs = (int)(($active_ref_stmt->get_result()->fetch_assoc()['active_count'] ?? 0));
                    $active_ref_stmt->close();

                    if ($active_refs === 0) {
                        $root_abs = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
                        $file_abs = rtrim(str_replace('\\', '/', (string)$root_abs), '/') . '/' . $normalized_path;
                        $resolved_file = realpath($file_abs);
                        if ($resolved_file && is_file($resolved_file)) {
                            @unlink($resolved_file);
                        }
                    }
                }

                $deleted_count++;
            }

            $success_messages = [];
            $error_messages = [];

            if ($deleted_count > 0) {
                $success_messages[] = $deleted_count === 1
                    ? t_def('profile.assets.delete_success', 'Tartalom sikeresen törölve.')
                    : t_def('profile.assets.delete_success_many', 'Tartalmak sikeresen törölve: ') . $deleted_count;
            }
            if ($already_deleted_count > 0) {
                $success_messages[] = t_def('profile.assets.delete_already_done_many', 'Már törölt elemek: ') . $already_deleted_count;
            }
            if ($used_count > 0) {
                $error_messages[] = t_def('profile.assets.delete_used_blocked_many', 'Használatban lévő elemek nem törölhetők: ') . $used_count;
            }
            if ($not_found_count > 0) {
                $error_messages[] = t_def('profile.assets.delete_not_found_many', 'Nem található elemek: ') . $not_found_count;
            }
            if ($failed_count > 0) {
                $error_messages[] = t_def('profile.assets.delete_failed_many', 'Sikertelen törlések: ') . $failed_count;
            }

            if (!empty($success_messages)) {
                $success = implode(' • ', $success_messages);
            }
            if (!empty($error_messages)) {
                $error = implode(' • ', $error_messages);
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_institution']) && $is_admin_user && $company_id > 0) {
        $institution_name = trim((string)($_POST['institution_name'] ?? ''));
        $institution_address = trim((string)($_POST['institution_address'] ?? ''));
        $tax_number = trim((string)($_POST['tax_number'] ?? ''));

        if ($institution_name === '') {
            $error = t_def('profile.institution.name_required', 'Az intézmény neve kötelező.');
        } else {
            $update_stmt = $conn->prepare("UPDATE companies SET name = ?, address = ?, tax_number = ? WHERE id = ?");
            $update_stmt->bind_param("sssi", $institution_name, $institution_address, $tax_number, $company_id);
            if ($update_stmt->execute()) {
                $success = t_def('profile.institution.update_success', 'Intézmény adatai sikeresen frissítve.');
            } else {
                $error = t_def('profile.institution.update_failed', 'Az intézmény adatainak mentése sikertelen.');
            }
            $update_stmt->close();
        }
    }

    if ($company_id > 0) {
        $stmt = $conn->prepare("SELECT id, name, address, tax_number, created_at, api_token FROM companies WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $company_data = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $company_name = $company_data['name'] ?? t_def('profile.no_institution', 'Nincs intézmény');
    }
    
    // Get company licenses
    if ($company_id) {
        edudisplej_module_asset_ensure_schema($conn);

        $stmt = $conn->prepare("
            SELECT m.id, m.name, m.description, m.module_key, 
                   COALESCE(ml.quantity, 0) as total_licenses,
                   (SELECT COUNT(DISTINCT km.kiosk_id) FROM kiosk_modules km 
                    JOIN kiosks k ON km.kiosk_id = k.id 
                    WHERE k.company_id = ? AND km.module_id = m.id) as used_licenses
            FROM modules m 
            LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
            ORDER BY m.name
        ");
        $stmt->bind_param("ii", $company_id, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $licenses[] = $row;
        }
        $stmt->close();

        $filtered_licenses = [];
        foreach ($licenses as $row) {
            $module_key = strtolower(trim((string)($row['module_key'] ?? '')));
            $module_name = strtolower(trim((string)($row['name'] ?? '')));
            $is_technical_unconfigured = strpos($module_key, 'unconfigured') !== false
                || (strpos($module_name, 'unconfigured') !== false
                    && (strpos($module_name, 'displej') !== false || strpos($module_name, 'display') !== false));
            if ($is_technical_unconfigured) {
                continue;
            }
            $filtered_licenses[] = $row;
        }

        $enabled_licenses = array_values(array_filter($filtered_licenses, fn($lic) => (int)($lic['total_licenses'] ?? 0) > 0));
        $disabled_licenses = array_values(array_filter($filtered_licenses, fn($lic) => (int)($lic['total_licenses'] ?? 0) <= 0));
        
        // Get company users
        $stmt = $conn->prepare("SELECT id, username, email, isadmin, user_role, last_login FROM users WHERE company_id = ? ORDER BY username");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $company_users[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT module_key, asset_kind, COUNT(*) AS asset_count, COALESCE(SUM(file_size), 0) AS total_bytes, MAX(created_at) AS last_upload
            FROM module_asset_store
            WHERE company_id = ? AND is_active = 1
            GROUP BY module_key, asset_kind
            ORDER BY module_key ASC, asset_kind ASC
        ");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $company_assets_summary[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare(" 
            SELECT id, module_key, asset_kind, original_name, mime_type, file_size, storage_rel_path, created_at
            FROM module_asset_store
            WHERE company_id = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $company_assets_recent[] = $row;
        }
        $stmt->close();

        [$used_asset_ids, $used_asset_paths] = profile_collect_used_assets($conn, $company_id);

        foreach ($company_assets_recent as &$asset_item) {
            $asset_id = (int)($asset_item['id'] ?? 0);
            $asset_path = edudisplej_module_asset_extract_rel_path((string)($asset_item['storage_rel_path'] ?? ''));
            $asset_item['is_used'] = profile_asset_is_in_use($asset_id, $asset_path, $used_asset_ids, $used_asset_paths);
            $asset_item['asset_url'] = $asset_id > 0 ? edudisplej_module_asset_api_url_by_id($asset_id) : '';
        }
        unset($asset_item);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $error = t_def('common.database_error', 'Adatbázis hiba:') . ' ' . $e->getMessage();
    error_log($e->getMessage());
}

$logout_url = '../login.php?logout=1';
?>
<?php include '../admin/header.php'; ?>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div style="background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($company_id): ?>
            <!-- USERS MANAGEMENT -->
            <div class="panel" style="padding: 20px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;"><?php echo htmlspecialchars(t_def('profile.users.title', '👥 Felhasználók kezelése')); ?></h2>
                    <button onclick="openAddUserForm()" style="background: #1e40af; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;"><?php echo htmlspecialchars(t_def('profile.users.add', '➠ Új felhasználó')); ?></button>
                </div>
                <p style="color: #666; margin-bottom: 15px;"><?php echo htmlspecialchars(t_def('profile.users.subtitle', 'Az intézményhez tartozó felhasználók és jogosultságaik.')); ?></p>
                
                <?php if (empty($company_users)): ?>
                    <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                        <?php echo htmlspecialchars(t_def('profile.users.empty', 'Nincsenek felhasználók')); ?>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th><?php echo htmlspecialchars(t_def('profile.users.col.username', 'Felhasználónév')); ?></th>
                                    <th><?php echo htmlspecialchars(t_def('common.email', 'Email')); ?></th>
                                    <th><?php echo htmlspecialchars(t_def('profile.users.col.role', 'Jogosultság')); ?></th>
                                    <th><?php echo htmlspecialchars(t_def('profile.users.col.last_login', 'Utolsó bejelentkezés')); ?></th>
                                    <th><?php echo htmlspecialchars(t_def('profile.users.col.actions', 'Műveletek')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($company_users as $u): 
                                    $is_admin = $u['isadmin'];
                                    if ($is_admin) {
                                        $role = t_def('profile.role.admin', 'Admin');
                                        $role_color = '#ff9800';
                                    } else {
                                        $normalized_role = edudisplej_normalize_user_role($u['user_role'] ?? 'user', false);
                                        $role = t_def('profile.role.user', 'Felhasználó');
                                        $role_color = '#1e40af';
                                        if ($normalized_role === 'loop_manager') {
                                            $role = t_def('profile.role.loop_manager', 'Loop/modul kezelő');
                                            $role_color = '#00695c';
                                        } elseif ($normalized_role === 'content_editor') {
                                            $role = t_def('profile.role.content_editor', 'Tartalom módosító');
                                            $role_color = '#6a1b9a';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                        <td><small><?php echo htmlspecialchars($u['email'] ?? '-'); ?></small></td>
                                        <td>
                                            <span style="background: <?php echo $role_color; ?>20; color: <?php echo $role_color; ?>; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold;">
                                                <?php echo $role; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $u['last_login'] ? date('Y-m-d H:i', strtotime($u['last_login'])) : htmlspecialchars(t_def('common.never', 'Soha')); ?></td>
                                        <td>
                                            <?php if ($u['id'] !== $user_id): ?>
                                                <button onclick="editUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small"><?php echo htmlspecialchars(t_def('common.edit', '✏️ Szerkesztés')); ?></button>
                                                <button onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small" style="color: #d32f2f;"><?php echo htmlspecialchars(t_def('common.delete', '🗑️ Törlés')); ?></button>
                                            <?php else: ?>
                                                <small style="color: #999;"><?php echo htmlspecialchars(t_def('profile.users.own_account_note', '(Saját fiók: jelszó itt nem szerkeszthető)')); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start;">
                <!-- INSTITUTION INFO -->
                <div class="panel" style="padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;"><?php echo htmlspecialchars(t_def('profile.institution.title', '🏢 Intézmény adatai')); ?></h2>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold; width: 30%;"><?php echo htmlspecialchars(t_def('profile.institution.name', 'Intézmény megnevezése')); ?></td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['name'] ?? t_def('common.na', 'N/A')); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars(t_def('profile.institution.address', 'Cím')); ?></td>
                            <td style="padding: 12px;"><?php echo nl2br(htmlspecialchars((string)($company_data['address'] ?? '—'))); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars(t_def('profile.institution.tax_number', 'Adószám')); ?></td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['tax_number'] ?? '—'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars(t_def('profile.institution.registered_at', 'Regisztrációs időpont')); ?></td>
                            <td style="padding: 12px;"><?php echo $company_data['created_at'] ? date('Y-m-d H:i', strtotime($company_data['created_at'])) : htmlspecialchars(t_def('common.na', 'N/A')); ?></td>
                        </tr>
                        <?php if (!empty($company_data['api_token'])): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars(t_def('profile.api_token', 'API Token')); ?></td>
                            <td style="padding: 12px;">
                                <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($company_data['api_token']); ?></code>
                                <button data-copy="<?php echo htmlspecialchars($company_data['api_token']); ?>" onclick="copyToClipboard(event)" style="margin-left: 10px; background: #4caf50; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; font-size: 12px;"><?php echo htmlspecialchars(t_def('common.copy', '📋 Másolás')); ?></button>
                                <div style="margin-top: 10px;">
                                    <strong style="display: inline-block; margin-right: 8px;"><?php echo htmlspecialchars(t_def('profile.install_command', 'Install parancs:')); ?></strong>
                                    <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px; font-size: 12px; display: inline-block;">
                                        <?php echo htmlspecialchars('curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' . $company_data['api_token']); ?>
                                    </code>
                                    <button data-copy="<?php echo htmlspecialchars('curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' . $company_data['api_token']); ?>" onclick="copyToClipboard(event)" style="margin-left: 10px; background: #4caf50; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; font-size: 12px;"><?php echo htmlspecialchars(t_def('common.copy', '📋 Másolás')); ?></button>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php if ($is_admin_user): ?>
                        <form method="POST" style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                            <input type="hidden" name="update_institution" value="1">
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div>
                                    <label for="institution_name" style="display:block; font-weight:600; margin-bottom:6px;"><?php echo htmlspecialchars(t_def('profile.institution.name', 'Intézmény megnevezése')); ?></label>
                                    <input id="institution_name" name="institution_name" type="text" value="<?php echo htmlspecialchars($company_data['name'] ?? ''); ?>" required style="width:100%;">
                                </div>
                                <div>
                                    <label for="institution_address" style="display:block; font-weight:600; margin-bottom:6px;"><?php echo htmlspecialchars(t_def('profile.institution.address', 'Cím')); ?></label>
                                    <textarea id="institution_address" name="institution_address" rows="3" style="width:100%; resize:vertical;"><?php echo htmlspecialchars($company_data['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <div style="margin-top: 12px; max-width: 420px;">
                                <label for="tax_number" style="display:block; font-weight:600; margin-bottom:6px;"><?php echo htmlspecialchars(t_def('profile.institution.tax_number', 'Adószám')); ?></label>
                                <input id="tax_number" name="tax_number" type="text" value="<?php echo htmlspecialchars($company_data['tax_number'] ?? ''); ?>" style="width:100%;">
                            </div>
                            <div style="margin-top: 12px;">
                                <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t_def('profile.institution.save', 'Intézmény adatok mentése')); ?></button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p style="margin-top: 12px; color: #666;"><?php echo htmlspecialchars(t_def('profile.institution.admin_only', 'Az intézmény adatait csak admin jogosultsággal lehet szerkeszteni.')); ?></p>
                    <?php endif; ?>
                </div>

                <!-- LICENSES -->
                <div class="panel" style="padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;"><?php echo htmlspecialchars(t_def('profile.licenses.title', '📜 Licenszek kezelése')); ?></h2>
                    <p style="color: #666;"><?php echo htmlspecialchars(t_def('profile.licenses.subtitle', 'Felül a bekapcsolt modulok, alul a kikapcsoltak.')); ?></p>
                    
                    <?php if (empty($enabled_licenses) && empty($disabled_licenses)): ?>
                        <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                            <?php echo htmlspecialchars(t_def('profile.licenses.empty', 'Nincsenek elérhető modulok')); ?>
                        </div>
                    <?php else: ?>
                        <h3 style="margin-top:0;"><?php echo htmlspecialchars(t_def('profile.licenses.enabled', 'Bekapcsolt modulok')); ?></h3>
                        <div style="overflow-x: auto; margin-bottom: 14px;">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th><?php echo htmlspecialchars(t_def('profile.licenses.col.module', 'Modul')); ?></th>
                                        <th><?php echo htmlspecialchars(t_def('profile.licenses.col.total', 'Összesen')); ?></th>
                                        <th><?php echo htmlspecialchars(t_def('profile.licenses.col.used', 'Felhasználva')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($enabled_licenses)): ?>
                                        <tr><td colspan="3" style="text-align:center; color:#999;"><?php echo htmlspecialchars(t_def('profile.licenses.enabled_empty', 'Nincs bekapcsolt modul.')); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($enabled_licenses as $lic): 
                                        $total = (int)($lic['total_licenses'] ?? 0);
                                        $used = (int)($lic['used_licenses'] ?? 0);
                                        $module_link = 'https://www.edudisplej.sk/modules/' . rawurlencode((string)($lic['module_key'] ?? ''));
                                    ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($module_link); ?>" target="_blank" rel="noopener noreferrer">
                                                    <strong><?php echo htmlspecialchars($lic['name']); ?></strong>
                                                </a>
                                                <br><small style="color: #999;"><?php echo htmlspecialchars($lic['module_key']); ?></small>
                                            </td>
                                            <td style="text-align: center;">
                                                <strong><?php echo $total; ?></strong>
                                            </td>
                                            <td style="text-align: center;">
                                                <strong><?php echo $used; ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h3><?php echo htmlspecialchars(t_def('profile.licenses.disabled', 'Kikapcsolt modulok')); ?></h3>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th><?php echo htmlspecialchars(t_def('profile.licenses.col.module', 'Modul')); ?></th>
                                        <th><?php echo htmlspecialchars(t_def('profile.licenses.col.state', 'Állapot')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($disabled_licenses)): ?>
                                        <tr><td colspan="2" style="text-align:center; color:#999;"><?php echo htmlspecialchars(t_def('profile.licenses.disabled_empty', 'Nincs kikapcsolt modul.')); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($disabled_licenses as $lic): 
                                        $module_link = 'https://www.edudisplej.sk/modules/' . rawurlencode((string)($lic['module_key'] ?? ''));
                                    ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($module_link); ?>" target="_blank" rel="noopener noreferrer">
                                                    <strong><?php echo htmlspecialchars($lic['name']); ?></strong>
                                                </a>
                                                <br><small style="color:#999;"><?php echo htmlspecialchars($lic['module_key']); ?></small>
                                            </td>
                                            <td><span style="color:#999;"><?php echo htmlspecialchars(t_def('profile.licenses.state.disabled', 'Kikapcsolva')); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel" style="padding: 20px; margin-bottom: 20px;">
                <h2 style="margin-top: 0;"><?php echo htmlspecialchars(t('profile.assets.title')); ?></h2>
                <p style="color: #666; margin-bottom: 14px;"><?php echo htmlspecialchars(t('profile.assets.subtitle')); ?></p>

                <?php if (empty($company_assets_summary)): ?>
                    <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                        <?php echo htmlspecialchars(t('profile.assets.empty')); ?>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto; margin-bottom: 16px;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.module')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.kind')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.count')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.size')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.last_upload')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($company_assets_summary as $asset_summary): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars((string)$asset_summary['module_key']); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)$asset_summary['asset_kind']); ?></td>
                                        <td style="text-align:center;"><?php echo (int)$asset_summary['asset_count']; ?></td>
                                        <td style="text-align:right;"><?php echo htmlspecialchars(profile_format_bytes((int)$asset_summary['total_bytes'])); ?></td>
                                        <td><?php echo !empty($asset_summary['last_upload']) ? date('Y-m-d H:i', strtotime((string)$asset_summary['last_upload'])) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin: 12px 0;"><?php echo htmlspecialchars(t('profile.assets.latest_uploads')); ?></h3>
                    <?php $has_unused_assets = false; ?>
                    <?php foreach ($company_assets_recent as $asset_item_for_select): ?>
                        <?php if (empty($asset_item_for_select['is_used']) && (int)($asset_item_for_select['id'] ?? 0) > 0): ?>
                            <?php $has_unused_assets = true; ?>
                            <?php break; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($can_manage_assets): ?>
                        <form id="bulk-asset-delete-form" method="POST" onsubmit="return confirmSelectedAssetDelete(this);" style="margin:0;">
                            <input type="hidden" name="delete_selected_assets" value="1">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
                                <label style="display:inline-flex; align-items:center; gap:8px; font-size:13px; color:#475467;">
                                    <input type="checkbox" id="select-all-assets" <?php echo $has_unused_assets ? '' : 'disabled'; ?>>
                                    <?php echo htmlspecialchars(t_def('profile.assets.select_all_unused', 'Označiť všetky nepoužívané')); ?>
                                </label>
                                <button type="submit" <?php echo $has_unused_assets ? '' : 'disabled'; ?> style="padding:6px 10px; border:1px solid #c43b2f; color:#c43b2f; background:#fff; border-radius:4px; cursor:pointer; font-size:12px; <?php echo $has_unused_assets ? '' : 'opacity:0.55; cursor:not-allowed;'; ?>">🗑️ <?php echo htmlspecialchars(t_def('profile.assets.delete_selected_unused', 'Kijelölt, nem használt fájlok törlése')); ?></button>
                            </div>
                    <?php endif; ?>

                    <div style="overflow-x: auto;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.time')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.module')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.file')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.kind')); ?></th>
                                    <th><?php echo htmlspecialchars(t_def('profile.assets.col.usage', 'Használat')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.size')); ?></th>
                                    <th><?php echo htmlspecialchars(t('profile.assets.col.storage_path')); ?></th>
                                    <?php if ($can_manage_assets): ?>
                                        <th><?php echo htmlspecialchars(t_def('profile.assets.col.select', 'Kijelölés')); ?></th>
                                    <?php endif; ?>
                                    <th><?php echo htmlspecialchars(t_def('profile.assets.col.actions', 'Műveletek')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($company_assets_recent as $asset_item): ?>
                                    <?php
                                        $asset_is_used = !empty($asset_item['is_used']);
                                        $asset_url = (string)($asset_item['asset_url'] ?? '');
                                        $asset_id = (int)($asset_item['id'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo !empty($asset_item['created_at']) ? date('Y-m-d H:i', strtotime((string)$asset_item['created_at'])) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars((string)$asset_item['module_key']); ?></td>
                                        <td>
                                            <?php if ($asset_url !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($asset_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars((string)$asset_item['original_name']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars((string)$asset_item['original_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$asset_item['asset_kind']); ?></td>
                                        <td>
                                            <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; <?php echo $asset_is_used ? 'background:#ecfdf3; color:#027a48; border:1px solid #abefc6;' : 'background:#fff4ed; color:#b54708; border:1px solid #fed7aa;'; ?>">
                                                <?php echo $asset_is_used
                                                    ? htmlspecialchars(t_def('profile.assets.used', 'Használatban'))
                                                    : htmlspecialchars(t_def('profile.assets.unused', 'Nincs használatban')); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;"><?php echo htmlspecialchars(profile_format_bytes((int)$asset_item['file_size'])); ?></td>
                                        <td><small><?php echo htmlspecialchars((string)$asset_item['storage_rel_path']); ?></small></td>
                                        <?php if ($can_manage_assets): ?>
                                            <td style="text-align:center;">
                                                <?php if (!$asset_is_used && $asset_id > 0): ?>
                                                    <input type="checkbox" name="delete_asset_ids[]" value="<?php echo $asset_id; ?>" data-asset-name="<?php echo htmlspecialchars((string)$asset_item['original_name']); ?>" aria-label="<?php echo htmlspecialchars((string)$asset_item['original_name']); ?>">
                                                <?php else: ?>
                                                    <span style="color:#98a2b3;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if ($can_manage_assets && $asset_id > 0): ?>
                                                <?php if (!$asset_is_used): ?>
                                                    <button type="button" onclick="submitSingleAssetDelete(<?php echo $asset_id; ?>, <?php echo json_encode((string)$asset_item['original_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)" style="padding:4px 8px; border:1px solid #c43b2f; color:#c43b2f; background:#fff; border-radius:4px; cursor:pointer; font-size:12px;">🗑️ <?php echo htmlspecialchars(t_def('common.delete', 'Törlés')); ?></button>
                                                <?php else: ?>
                                                    <span style="color:#98a2b3;"><?php echo htmlspecialchars(t_def('profile.assets.in_use_locked', 'Használatban')); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#98a2b3;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($can_manage_assets): ?>
                        </form>
                        <form id="single-asset-delete-form" method="POST" style="display:none;">
                            <input type="hidden" name="delete_asset" value="1">
                            <input type="hidden" name="delete_asset_id" id="single-delete-asset-id" value="">
                            <input type="hidden" name="delete_asset_name" id="single-delete-asset-name" value="">
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="panel" style="text-align: center; padding: 40px; color: #999;">
                <p><?php echo htmlspecialchars(t_def('profile.no_company_access', 'Nem rendelt cég vagy nincs hozzáférése az adatokhoz.')); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        const PROFILE_I18N = {
            copySuccess: <?php echo json_encode(t_def('common.copied', '✅ Másolva!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            unknownFile: <?php echo json_encode(t_def('profile.assets.unknown_file', 'ismeretlen fájl'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            usernamePlaceholder: <?php echo json_encode(t_def('profile.users.placeholder.username', 'pl. janos.kovacs'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            emailPlaceholder: <?php echo json_encode(t_def('profile.users.placeholder.email', 'janos@example.com'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            addUserTitle: <?php echo json_encode(t_def('profile.users.modal.add_title', '➕ Új felhasználó'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            username: <?php echo json_encode(t_def('profile.users.col.username', 'Felhasználónév'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            email: <?php echo json_encode(t_def('common.email', 'Email'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            password: <?php echo json_encode(t_def('common.password', 'Jelszó'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            role: <?php echo json_encode(t_def('profile.users.col.role', 'Jogosultság'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            roleUser: <?php echo json_encode(t_def('profile.role.user', '👤 Felhasználó'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            roleLoopManager: <?php echo json_encode(t_def('profile.role.loop_manager', '🔁 Loop/modul kezelő'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            roleContentEditor: <?php echo json_encode(t_def('profile.role.content_editor', '📝 Tartalom módosító'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            permissionsTitle: <?php echo json_encode(t_def('profile.users.permissions_title', 'Felhasználó jogosultságai:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            permissions1: <?php echo json_encode(t_def('profile.users.permissions.item1', 'Admin jogosultság itt nem adható'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            permissions2: <?php echo json_encode(t_def('profile.users.permissions.item2', 'Szerepkör szerint eltérő dashboard hozzáférés'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            permissions3: <?php echo json_encode(t_def('profile.users.permissions.item3', 'Tartalom módosító csak modul tartalmat kezelhet'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            cancel: <?php echo json_encode(t_def('common.cancel', 'Mégsem'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            create: <?php echo json_encode(t_def('common.create', 'Létrehozás'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            requiredFields: <?php echo json_encode(t_def('profile.users.required_fields', 'Kérem töltse ki az összes szükséges mezőt!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            passwordMinLength: <?php echo json_encode(t_def('profile.users.password_min_length', 'A jelszó legalább 6 karakter hosszú legyen!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            createSuccess: <?php echo json_encode(t_def('profile.users.create_success', 'Felhasználó sikeresen létrehozva!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            updateSuccess: <?php echo json_encode(t_def('profile.users.update_success', 'Felhasználó sikeresen frissítve!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            deleteSuccess: <?php echo json_encode(t_def('profile.users.delete_success', 'Felhasználó sikeresen törölve!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            ownPasswordNotEditable: <?php echo json_encode(t_def('profile.users.own_password_not_editable', 'A saját jelszó itt nem módosítható.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            passwordPrompt: <?php echo json_encode(t_def('profile.users.password_prompt', 'új jelszava (hagyja üresen a jelenlegi megmaradásához):'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            confirmDeleteUser: <?php echo json_encode(t_def('profile.users.confirm_delete', 'Valóban törli a(z) "{username}" felhasználót?'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            confirmDeleteAsset: <?php echo json_encode(t_def('profile.assets.confirm_delete', 'Valóban törli ezt a tartalmat: "{name}"?'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            confirmDeleteSelectedAssets: <?php echo json_encode(t_def('profile.assets.confirm_delete_selected', 'Valóban törli a kijelölt, nem használt tartalmakat? ({count} db)'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            selectAssetFirst: <?php echo json_encode(t_def('profile.assets.select_first', 'Jelöljön ki legalább egy nem használt fájlt!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            errorPrefix: <?php echo json_encode(t_def('common.error_prefix', 'Hiba:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>
        };

        function confirmAssetDeleteByName(assetNameRaw) {
            const assetName = assetNameRaw || PROFILE_I18N.unknownFile;
            const message = (PROFILE_I18N.confirmDeleteAsset || 'Valóban törli ezt a tartalmat: "{name}"?').replace('{name}', assetName);
            return window.confirm(message);
        }

        function submitSingleAssetDelete(assetId, assetName) {
            if (!assetId) {
                return;
            }
            if (!confirmAssetDeleteByName(assetName || PROFILE_I18N.unknownFile)) {
                return;
            }

            const idInput = document.getElementById('single-delete-asset-id');
            const nameInput = document.getElementById('single-delete-asset-name');
            const form = document.getElementById('single-asset-delete-form');
            if (!idInput || !nameInput || !form) {
                return;
            }

            idInput.value = String(assetId);
            nameInput.value = String(assetName || PROFILE_I18N.unknownFile);
            form.submit();
        }

        function confirmSelectedAssetDelete(formEl) {
            const checked = formEl?.querySelectorAll('input[name="delete_asset_ids[]"]:checked') || [];
            const count = checked.length;
            if (count < 1) {
                alert(PROFILE_I18N.selectAssetFirst || 'Jelöljön ki legalább egy nem használt fájlt!');
                return false;
            }

            const message = (PROFILE_I18N.confirmDeleteSelectedAssets || 'Valóban törli a kijelölt, nem használt tartalmakat? ({count} db)')
                .replace('{count}', String(count));
            return window.confirm(message);
        }

        function initAssetBulkSelect() {
            const form = document.getElementById('bulk-asset-delete-form');
            const selectAll = document.getElementById('select-all-assets');
            if (!form || !selectAll) {
                return;
            }

            const getAssetCheckboxes = () => Array.from(form.querySelectorAll('input[name="delete_asset_ids[]"]'));

            const updateSelectAllState = () => {
                const checkboxes = getAssetCheckboxes();
                if (checkboxes.length === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    selectAll.disabled = true;
                    return;
                }

                const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
                selectAll.disabled = false;
                selectAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            };

            selectAll.addEventListener('change', () => {
                const shouldCheck = !!selectAll.checked;
                getAssetCheckboxes().forEach((checkbox) => {
                    checkbox.checked = shouldCheck;
                });
                updateSelectAllState();
            });

            getAssetCheckboxes().forEach((checkbox) => {
                checkbox.addEventListener('change', updateSelectAllState);
            });

            updateSelectAllState();
        }

        document.addEventListener('DOMContentLoaded', initAssetBulkSelect);

        function copyToClipboard(event) {
            const button = event.currentTarget;
            const textToCopy = button.getAttribute('data-copy') || '';
            if (!textToCopy) {
                return;
            }

            const showSuccess = () => {
                const originalText = button.textContent;
                button.textContent = PROFILE_I18N.copySuccess;
                button.style.background = '#4caf50';
                setTimeout(() => {
                    button.textContent = originalText;
                    button.style.background = '#4caf50';
                }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy)
                    .then(showSuccess)
                    .catch(() => {
                        fallbackCopyText(textToCopy);
                        showSuccess();
                    });
                return;
            }

            fallbackCopyText(textToCopy);
            showSuccess();
        }

        function fallbackCopyText(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
        
        function openAddUserForm() {
            const form = document.createElement('div');
            form.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            form.addEventListener('click', function(e) {
                if (e.target === form) form.remove();
            });
            
            form.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 500px;
                    width: 90%;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">${PROFILE_I18N.addUserTitle}</h2>
                        <button onclick="this.closest('div').parentElement.remove()" style="
                            background: #1e40af;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        ">✕</button>
                    </div>
                    
                    <form id="add-user-form" style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">${PROFILE_I18N.username} *</label>
                            <input type="text" id="username" placeholder="${PROFILE_I18N.usernamePlaceholder}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">${PROFILE_I18N.email} *</label>
                            <input type="email" id="email" placeholder="${PROFILE_I18N.emailPlaceholder}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">${PROFILE_I18N.password} *</label>
                            <input type="password" id="password" placeholder="••••••••" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">${PROFILE_I18N.role}</label>
                            <select id="user_role" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;">
                                <option value="user">${PROFILE_I18N.roleUser}</option>
                                <option value="loop_manager">${PROFILE_I18N.roleLoopManager}</option>
                                <option value="content_editor">${PROFILE_I18N.roleContentEditor}</option>
                            </select>
                        </div>
                        
                        <div style="background: #f9f9f9; padding: 12px; border-radius: 3px; border-left: 4px solid #1e40af; font-size: 12px; color: #666;">
                            <strong>${PROFILE_I18N.permissionsTitle}</strong>
                            <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                <li>${PROFILE_I18N.permissions1}</li>
                                <li>${PROFILE_I18N.permissions2}</li>
                                <li>${PROFILE_I18N.permissions3}</li>
                            </ul>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                            <button type="button" onclick="this.closest('div').parentElement.parentElement.remove()" style="background: #ddd; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">${PROFILE_I18N.cancel}</button>
                            <button type="button" onclick="createUser()" style="background: #1e40af; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer; font-weight: bold;">${PROFILE_I18N.create}</button>
                        </div>
                    </form>
                </div>
            `;
            
            document.body.appendChild(form);
        }
        
        function createUser() {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            const user_role = document.getElementById('user_role').value;
            
            if (!username || !email || !password) {
                alert(PROFILE_I18N.requiredFields);
                return;
            }
            
            if (password.length < 6) {
                alert(PROFILE_I18N.passwordMinLength);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'create_user');
            formData.append('username', username);
            formData.append('email', email);
            formData.append('password', password);
            formData.append('is_admin', '0');
            formData.append('user_role', user_role);
            
            fetch('../api/manage_users.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(PROFILE_I18N.createSuccess);
                    location.reload();
                } else {
                    alert(PROFILE_I18N.errorPrefix + ' ' + data.message);
                }
            })
            .catch(err => alert(PROFILE_I18N.errorPrefix + ' ' + err));
        }
        
        function editUser(userId, username) {
            if (Number(userId) === <?php echo (int)$user_id; ?>) {
                alert(PROFILE_I18N.ownPasswordNotEditable);
                return;
            }
            const password = prompt(`${username} ${PROFILE_I18N.passwordPrompt}`);
            if (password === null) return;
            
            if (password && password.length < 6) {
                alert(PROFILE_I18N.passwordMinLength);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_user');
            formData.append('user_id', userId);
            if (password) formData.append('password', password);
            
            fetch('../api/manage_users.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(PROFILE_I18N.updateSuccess);
                    location.reload();
                } else {
                    alert(PROFILE_I18N.errorPrefix + ' ' + data.message);
                }
            })
            .catch(err => alert(PROFILE_I18N.errorPrefix + ' ' + err));
        }
        
        function deleteUser(userId, username) {
            if (!confirm(PROFILE_I18N.confirmDeleteUser.replace('{username}', username))) return;
            
            fetch(`../api/manage_users.php?action=delete_user&user_id=${userId}`, {
                method: 'DELETE'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(PROFILE_I18N.deleteSuccess);
                    location.reload();
                } else {
                    alert(PROFILE_I18N.errorPrefix + ' ' + data.message);
                }
            })
            .catch(err => alert(PROFILE_I18N.errorPrefix + ' ' + err));
        }
    </script>

<?php include '../admin/footer.php'; ?>

