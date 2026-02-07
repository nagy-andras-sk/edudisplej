<?php
/**
 * Company Profile - Cég Adatai, Licenszek, Felhasználók
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

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
$company_users = [];
$error = '';
$success = '';

try {
    $conn = getDbConnection();
    
    // Get user and company info
    $stmt = $conn->prepare("SELECT u.*, c.* FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?");
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
    
    $company_id = $user['company_id'];
    $company_name = $user['name'] ?? 'Nincs cég';
    $company_data = $user;
    
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
        
        // Get company users
        $stmt = $conn->prepare("SELECT id, username, email, isadmin, last_login FROM users WHERE company_id = ? ORDER BY username");
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
            <!-- COMPANY INFO - Simplified Table Format -->
            <div style="background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #eee;">
                <h2 style="margin-top: 0;">🏢 Cég Adatai</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px; font-weight: bold; width: 30%;">Cégnév</td>
                        <td style="padding: 12px;"><?php echo htmlspecialchars($company_data['name'] ?? 'N/A'); ?></td>
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
                            <button onclick="copyToClipboard(event, 'token-value')" style="margin-left: 10px; background: #4caf50; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; font-size: 12px;">📋 Másolás</button>
                            <input type="hidden" id="token-value" value="<?php echo htmlspecialchars($company_data['api_token']); ?>">
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- LICENSES -->
            <div style="background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #eee;">
                <h2 style="margin-top: 0;">📜 Licenszek Kezelése</h2>
                <p style="color: #666;">A cégjéhez rendelt modulok és azok felhasználása</p>
                
                <?php if (empty($licenses)): ?>
                    <div style="text-align: center; padding: 30px; color: #999; background: #f9f9f9; border-radius: 3px;">
                        Nincsenek elérhető modulok
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Modul</th>
                                    <th>Összesen</th>
                                    <th>Felhasználva</th>
                                    <th>Szabad</th>
                                    <th>Kihasználtság</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licenses as $lic): 
                                    $total = $lic['total_licenses'];
                                    $used = $lic['used_licenses'];
                                    $free = max(0, $total - $used);
                                    $percent = $total > 0 ? round(($used / $total) * 100) : 0;
                                    $status_color = $percent > 80 ? '#ff9800' : ($percent === 100 ? '#d32f2f' : '#4caf50');
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($lic['name']); ?></strong>
                                            <br><small style="color: #999;"><?php echo htmlspecialchars($lic['module_key']); ?></small>
                                        </td>
                                        <td style="text-align: center;">
                                            <strong><?php echo $total; ?></strong>
                                        </td>
                                        <td style="text-align: center;">
                                            <strong><?php echo $used; ?></strong>
                                        </td>
                                        <td style="text-align: center;">
                                            <strong><?php echo $free; ?></strong>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="background: #eee; border-radius: 10px; width: 100px; height: 8px; overflow: hidden;">
                                                    <div style="background: <?php echo $status_color; ?>; width: <?php echo $percent; ?>%; height: 100%;"></div>
                                                </div>
                                                <span style="color: <?php echo $status_color; ?>; font-weight: bold;"><?php echo $percent; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- USERS MANAGEMENT -->
            <div style="background: white; padding: 20px; border-radius: 5px; border: 1px solid #eee;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;">👥 Felhasználók Kezelése</h2>
                    <button onclick="openAddUserForm()" style="background: #1e40af; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">➠ Új felhasználó</button>
                </div>
                <p style="color: #666; margin-bottom: 15px;">A cégjéhez tartozó felhasználók és azok jogosultságai</p>
                
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
                                    $role = $is_admin ? 'Admin' : 'Felhasználó';
                                    $role_color = $is_admin ? '#ff9800' : '#1e40af';
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
                                            <button onclick="editUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small">✏️ Szerkesztés</button>
                                            <?php if ($u['id'] !== $user_id): ?>
                                                <button onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="action-btn action-btn-small" style="color: #d32f2f;">🗑️ Törlés</button>
                                            <?php else: ?>
                                                <small style="color: #999;">(Saját fiók)</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 5px;">
                <p>Nem rendelt cég vagy nincs hozzáférése az adatokhoz.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function copyToClipboard(event, elementId) {
            const input = document.getElementById(elementId);
            input.select();
            document.execCommand('copy');
            
            // Show feedback
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '✅ Másolva!';
            button.style.background = '#4caf50';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '#4caf50';
            }, 2000);
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
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">Jogosultság</label>
                            <select id="is_admin" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;">
                                <option value="0">👤 Felhasználó (korlátozott hozzáférés)</option>
                                <option value="1">⚙️ Admin (teljes hozzáférés)</option>
                            </select>
                        </div>
                        
                        <div style="background: #f9f9f9; padding: 12px; border-radius: 3px; border-left: 4px solid #1e40af; font-size: 12px; color: #666;">
                            <strong>Felhasználó jogosultságai:</strong>
                            <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                <li>Kijelzők megtekintése és módosítása</li>
                                <li>Hardware adatok megtekintése</li>
                                <li>Modulok kezelése</li>
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
            const is_admin = document.getElementById('is_admin').value;
            
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
            formData.append('is_admin', is_admin);
            
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

