<?php
/**
 * Company Management - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $company_id = (int)$_GET['delete'];

    try {
        $conn = getDbConnection();

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM kiosks WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $kiosk_count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $user_count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        if ($kiosk_count > 0 || $user_count > 0) {
            $error = "Cannot delete: $kiosk_count kiosk(s), $user_count user(s) assigned.";
        } else {
            $stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
            $stmt->bind_param("i", $company_id);
            if ($stmt->execute()) {
                $success = 'Company deleted successfully';
            } else {
                $error = 'Failed to delete company';
            }
            $stmt->close();
        }

        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Database error occurred';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
    $name = trim($_POST['company_name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $error = 'Company name is required';
    } else {
        try {
            $conn = getDbConnection();

            if ($company_id > 0) {
                $stmt = $conn->prepare("UPDATE companies SET name = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("sii", $name, $is_active, $company_id);
                $success = 'Company updated successfully';
            } else {
                $stmt = $conn->prepare("INSERT INTO companies (name, is_active) VALUES (?, ?)");
                $stmt->bind_param("si", $name, $is_active);
                $success = 'Company created successfully';
            }

            $stmt->execute();
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Failed to save company';
        }
    }
}

$companies = [];
try {
    $conn = getDbConnection();
    $result = $conn->query("
        SELECT c.*, 
               COUNT(DISTINCT k.id) as kiosk_count,
               COUNT(DISTINCT u.id) as user_count
        FROM companies c
        LEFT JOIN kiosks k ON c.id = k.company_id
        LEFT JOIN users u ON c.id = u.company_id
        GROUP BY c.id
        ORDER BY c.name
    ");

    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load companies';
}

$edit_company = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($companies as $company) {
        if ((int)$company['id'] === (int)$_GET['edit']) {
            $edit_company = $company;
            break;
        }
    }
}

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Cegek</div>
    <div class="muted">Ugyfel ceg adatok, kioskok, felhasznalok.</div>
</div>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title"><?php echo $edit_company ? 'Ceg szerkesztes' : 'Uj ceg'; ?></div>
    <form method="post" class="form-row">
        <input type="hidden" name="company_id" value="<?php echo $edit_company ? (int)$edit_company['id'] : 0; ?>">
        <div class="form-field" style="min-width: 260px;">
            <label for="company_name">Ceg neve</label>
            <input id="company_name" name="company_name" type="text" value="<?php echo htmlspecialchars($edit_company['name'] ?? ''); ?>" required>
        </div>
        <div class="form-field">
            <label for="is_active">Aktiv</label>
            <select id="is_active" name="is_active">
                <option value="1" <?php echo ($edit_company && (int)$edit_company['is_active'] === 1) || !$edit_company ? 'selected' : ''; ?>>Igen</option>
                <option value="0" <?php echo $edit_company && (int)$edit_company['is_active'] === 0 ? 'selected' : ''; ?>>Nem</option>
            </select>
        </div>
        <div class="form-field">
            <button type="submit" name="save_company" class="btn btn-primary">Ment</button>
        </div>
        <?php if ($edit_company): ?>
            <div class="form-field">
                <a class="btn btn-secondary" href="companies.php">Megse</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Ceg lista</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nev</th>
                    <th>Aktiv</th>
                    <th>Kioskok</th>
                    <th>Felhasznalok</th>
                    <th>License Key</th>
                    <th>API Token</th>
                    <th>Token Letrehozva</th>
                    <th>Letrehozva</th>
                    <th>Muvelet</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="10" class="muted">Nincs ceg.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td><?php echo (int)$company['id']; ?></td>
                            <td><?php echo htmlspecialchars($company['name']); ?></td>
                            <td><?php echo (int)$company['is_active'] === 1 ? 'Igen' : 'Nem'; ?></td>
                            <td><?php echo (int)$company['kiosk_count']; ?></td>
                            <td><?php echo (int)$company['user_count']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($company['license_key'] ?? '-'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($company['api_token'] ?? '-'); ?></td>
                            <td class="nowrap"><?php echo $company['token_created_at'] ? date('Y-m-d H:i:s', strtotime($company['token_created_at'])) : '-'; ?></td>
                            <td class="nowrap"><?php echo $company['created_at'] ? date('Y-m-d H:i:s', strtotime($company['created_at'])) : '-'; ?></td>
                            <td class="nowrap">
                                <a class="btn btn-small" href="companies.php?edit=<?php echo (int)$company['id']; ?>">Szerkeszt</a>
                                <a class="btn btn-small btn-danger" href="companies.php?delete=<?php echo (int)$company['id']; ?>" onclick="return confirm('Toroljuk a ceget?')">Torol</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
