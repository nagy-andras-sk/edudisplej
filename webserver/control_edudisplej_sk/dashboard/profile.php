<?php
/**
 * Company Profile - Cég Adatai, Licenszek, Felhasználók
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../auth_roles.php';

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
$error = '';
$success = '';
$is_admin_user = !empty($_SESSION['isadmin']);
$current_role = edudisplej_get_session_role();

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
        $conn->query("ALTER TABLE companies ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER name");
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_institution']) && $is_admin_user && $company_id > 0) {
        $institution_name = trim((string)($_POST['institution_name'] ?? ''));
        $institution_address = trim((string)($_POST['institution_address'] ?? ''));
        $tax_number = trim((string)($_POST['tax_number'] ?? ''));

        if ($institution_name === '') {
            $error = 'Az intézmény neve kötelező.';
        } else {
            $update_stmt = $conn->prepare("UPDATE companies SET name = ?, address = ?, tax_number = ? WHERE id = ?");
            $update_stmt->bind_param("sssi", $institution_name, $institution_address, $tax_number, $company_id);
            if ($update_stmt->execute()) {
                $success = 'Intézmény adatai sikeresen frissítve.';
            } else {
                $error = 'Az intézmény adatainak mentése sikertelen.';
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
        $company_name = $company_data['name'] ?? 'Nincs intézmény';
    }
    
    // Get company licenses
    if ($company_id) {
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
        $legacy_alias_keys = ['datetime', 'dateclock'];
        foreach ($licenses as $row) {
            $module_key = strtolower(trim((string)($row['module_key'] ?? '')));
            $module_name = strtolower(trim((string)($row['name'] ?? '')));
            $is_technical_unconfigured = strpos($module_key, 'unconfigured') !== false
                || (strpos($module_name, 'unconfigured') !== false
                    && (strpos($module_name, 'displej') !== false || strpos($module_name, 'display') !== false));
            if ($is_technical_unconfigured || in_array($module_key, $legacy_alias_keys, true)) {
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
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
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
                    <h2 style="margin: 0;">👥 Felhasználók Kezelése</h2>
                    <button onclick="openAddUserForm()" style="background: #1e40af; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">➠ Új felhasználó</button>
                </div>
                <p style="color: #666; margin-bottom: 15px;">Az intézményhez tartozó felhasználók és jogosultságaik.</p>
                
                <?php if (empty($company_users)): ?>
                    <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                        Nincsenek felhasználók
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Felhasználónév</th>
                                    <th>Email</th>
                                    <th>Jogosultság</th>
                                    <th>Utolsó bejelentkezés</th>
                                    <th>Műveletek</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($company_users as $u): 
                                    $is_admin = $u['isadmin'];
                                    if ($is_admin) {
                                        $role = 'Admin';
                                        $role_color = '#ff9800';
                                    } else {
                                        $normalized_role = edudisplej_normalize_user_role($u['user_role'] ?? 'user', false);
                                        $role = 'Felhasználó';
                                        $role_color = '#1e40af';
                                        if ($normalized_role === 'loop_manager') {
                                            $role = 'Loop/modul kezelő';
                                            $role_color = '#00695c';
                                        } elseif ($normalized_role === 'content_editor') {
                                            $role = 'Tartalom módosító';
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
                                        <td><?php echo $u['last_login'] ? date('Y-m-d H:i', strtotime($u['last_login'])) : 'Soha'; ?></td>
                                        <td>
                                            <?php if ($u['id'] !== $user_id): ?>
                                                <button onclick="editUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small">✏️ Szerkesztés</button>
                                                <button onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small" style="color: #d32f2f;">🗑️ Törlés</button>
                                            <?php else: ?>
                                                <small style="color: #999;">(Saját fiók: jelszó itt nem szerkeszthető)</small>
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
                    <h2 style="margin-top: 0;">🏢 Intézmény adatai</h2>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold; width: 30%;">Intézmény megnevezése</td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['name'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;">Cím</td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['address'] ?? '—'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;">Adószám</td>
                            <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['tax_number'] ?? '—'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;">Regisztrációs időpont</td>
                            <td style="padding: 12px;"><?php echo $company_data['created_at'] ? date('Y-m-d H:i', strtotime($company_data['created_at'])) : 'N/A'; ?></td>
                        </tr>
                        <?php if (!empty($company_data['api_token'])): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px; font-weight: bold;">API Token</td>
                            <td style="padding: 12px;">
                                <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($company_data['api_token']); ?></code>
                                <button data-copy="<?php echo htmlspecialchars($company_data['api_token']); ?>" onclick="copyToClipboard(event)" style="margin-left: 10px; background: #4caf50; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; font-size: 12px;">📋 Másolás</button>
                                <div style="margin-top: 10px;">
                                    <strong style="display: inline-block; margin-right: 8px;">Install parancs:</strong>
                                    <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px; font-size: 12px; display: inline-block;">
                                        <?php echo htmlspecialchars('curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' . $company_data['api_token']); ?>
                                    </code>
                                    <button data-copy="<?php echo htmlspecialchars('curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' . $company_data['api_token']); ?>" onclick="copyToClipboard(event)" style="margin-left: 10px; background: #4caf50; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; font-size: 12px;">📋 Másolás</button>
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
                                    <label for="institution_name" style="display:block; font-weight:600; margin-bottom:6px;">Intézmény megnevezése</label>
                                    <input id="institution_name" name="institution_name" type="text" value="<?php echo htmlspecialchars($company_data['name'] ?? ''); ?>" required style="width:100%;">
                                </div>
                                <div>
                                    <label for="institution_address" style="display:block; font-weight:600; margin-bottom:6px;">Cím</label>
                                    <input id="institution_address" name="institution_address" type="text" value="<?php echo htmlspecialchars($company_data['address'] ?? ''); ?>" style="width:100%;">
                                </div>
                            </div>
                            <div style="margin-top: 12px; max-width: 420px;">
                                <label for="tax_number" style="display:block; font-weight:600; margin-bottom:6px;">Adószám</label>
                                <input id="tax_number" name="tax_number" type="text" value="<?php echo htmlspecialchars($company_data['tax_number'] ?? ''); ?>" style="width:100%;">
                            </div>
                            <div style="margin-top: 12px;">
                                <button type="submit" class="btn btn-primary">Intézmény adatok mentése</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p style="margin-top: 12px; color: #666;">Az intézmény adatait csak admin jogosultsággal lehet szerkeszteni.</p>
                    <?php endif; ?>
                </div>

                <!-- LICENSES -->
                <div class="panel" style="padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">📜 Licenszek kezelése</h2>
                    <p style="color: #666;">Felül a bekapcsolt modulok, alul a kikapcsoltak.</p>
                    
                    <?php if (empty($enabled_licenses) && empty($disabled_licenses)): ?>
                        <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                            Nincsenek elérhető modulok
                        </div>
                    <?php else: ?>
                        <h3 style="margin-top:0;">Bekapcsolt modulok</h3>
                        <div style="overflow-x: auto; margin-bottom: 14px;">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Modul</th>
                                        <th>Összesen</th>
                                        <th>Felhasználva</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($enabled_licenses)): ?>
                                        <tr><td colspan="3" style="text-align:center; color:#999;">Nincs bekapcsolt modul.</td></tr>
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

                        <h3>Kikapcsolt modulok</h3>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Modul</th>
                                        <th>Állapot</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($disabled_licenses)): ?>
                                        <tr><td colspan="2" style="text-align:center; color:#999;">Nincs kikapcsolt modul.</td></tr>
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
                                            <td><span style="color:#999;">Kikapcsolva</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="panel" style="text-align: center; padding: 40px; color: #999;">
                <p>Nem rendelt cég vagy nincs hozzáférése az adatokhoz.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function copyToClipboard(event) {
            const button = event.currentTarget;
            const textToCopy = button.getAttribute('data-copy') || '';
            if (!textToCopy) {
                return;
            }

            const showSuccess = () => {
                const originalText = button.textContent;
                button.textContent = '✅ Másolva!';
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
                        <h2 style="margin: 0;">➕ Új Felhasználó</h2>
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
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Felhasználónév *</label>
                            <input type="text" id="username" placeholder="pl. janos.kovacs" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Email *</label>
                            <input type="email" id="email" placeholder="janos@example.com" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Jelszó *</label>
                            <input type="password" id="password" placeholder="••••••••" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;" required>
                        </div>
                        
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Szerepkör</label>
                            <select id="user_role" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;">
                                <option value="user">👤 Felhasználó</option>
                                <option value="loop_manager">🔁 Loop/modul kezelő</option>
                                <option value="content_editor">📝 Tartalom módosító</option>
                            </select>
                        </div>
                        
                        <div style="background: #f9f9f9; padding: 12px; border-radius: 3px; border-left: 4px solid #1e40af; font-size: 12px; color: #666;">
                            <strong>Felhasználó jogosultságai:</strong>
                            <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                <li>Admin jogosultság itt nem adható</li>
                                <li>Szerepkör szerint eltérő dashboard hozzáférés</li>
                                <li>Tartalom módosító csak modul tartalmat kezelhet</li>
                            </ul>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                            <button type="button" onclick="this.closest('div').parentElement.parentElement.remove()" style="background: #ddd; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">Mégsem</button>
                            <button type="button" onclick="createUser()" style="background: #1e40af; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer; font-weight: bold;">Létrehozás</button>
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
                alert('Kérem töltse ki az összes szükséges mezőt!');
                return;
            }
            
            if (password.length < 6) {
                alert('A jelszó legalább 6 karakter hosszú legyen!');
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
                    alert('Felhasználó sikeresen létrehozva!');
                    location.reload();
                } else {
                    alert('Hiba: ' + data.message);
                }
            })
            .catch(err => alert('Hiba: ' + err));
        }
        
        function editUser(userId, username) {
            if (Number(userId) === <?php echo (int)$user_id; ?>) {
                alert('A saját jelszó itt nem módosítható.');
                return;
            }
            const password = prompt(`${username} új jelszava (hagyja üresen a jelenlegi megmaradásához):`);
            if (password === null) return;
            
            if (password && password.length < 6) {
                alert('A jelszó legalább 6 karakter hosszú legyen!');
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
                    alert('Felhasználó sikeresen frissítve!');
                    location.reload();
                } else {
                    alert('Hiba: ' + data.message);
                }
            })
            .catch(err => alert('Hiba: ' + err));
        }
        
        function deleteUser(userId, username) {
            if (!confirm(`Valóban törli a "${username}" felhasználót?`)) return;
            
            fetch(`../api/manage_users.php?action=delete_user&user_id=${userId}`, {
                method: 'DELETE'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Felhasználó sikeresen törölve!');
                    location.reload();
                } else {
                    alert('Hiba: ' + data.message);
                }
            })
            .catch(err => alert('Hiba: ' + err));
        }
    </script>

<?php include '../admin/footer.php'; ?>

