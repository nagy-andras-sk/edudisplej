<?php
/**
 * Module License Management - Minimal
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_license'])) {
    $company_id = (int)($_POST['company_id'] ?? 0);
    $module_id = (int)($_POST['module_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);

    if ($company_id > 0 && $module_id > 0) {
        try {
            $conn = getDbConnection();

            $stmt = $conn->prepare("SELECT id FROM module_licenses WHERE company_id = ? AND module_id = ?");
            $stmt->bind_param("ii", $company_id, $module_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                if ($quantity > 0) {
                    $stmt = $conn->prepare("UPDATE module_licenses SET quantity = ? WHERE company_id = ? AND module_id = ?");
                    $stmt->bind_param("iii", $quantity, $company_id, $module_id);
                    $stmt->execute();
                    $success = 'License updated successfully';
                } else {
                    $stmt = $conn->prepare("DELETE FROM module_licenses WHERE company_id = ? AND module_id = ?");
                    $stmt->bind_param("ii", $company_id, $module_id);
                    $stmt->execute();
                    $success = 'License removed successfully';
                }
            } else {
                if ($quantity > 0) {
                    $stmt = $conn->prepare("INSERT INTO module_licenses (company_id, module_id, quantity) VALUES (?, ?, ?)");
                    $stmt->bind_param("iii", $company_id, $module_id, $quantity);
                    $stmt->execute();
                    $success = 'License created successfully';
                }
            }

            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    } else {
        $error = 'Invalid company or module';
    }
}

$companies = [];
$modules = [];
$licenses = [];

try {
    $conn = getDbConnection();

    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $result = $conn->query("SELECT * FROM modules WHERE is_active = 1 ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }

    $query = "SELECT ml.*, c.name as company_name, m.name as module_name, m.module_key 
              FROM module_licenses ml
              JOIN companies c ON ml.company_id = c.id
              JOIN modules m ON ml.module_id = m.id
              ORDER BY c.name, m.name";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $licenses[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data';
    error_log($e->getMessage());
}

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Module Licenszek</div>
    <div class="muted">Ceg/modul hozzarendeles, mennyiseg.</div>
</div>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">License beallitas</div>
    <form method="post" class="form-row">
        <div class="form-field">
            <label for="company_id">Ceg</label>
            <select id="company_id" name="company_id" required>
                <option value="">Valassz</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="module_id">Modul</label>
            <select id="module_id" name="module_id" required>
                <option value="">Valassz</option>
                <?php foreach ($modules as $module): ?>
                    <option value="<?php echo (int)$module['id']; ?>"><?php echo htmlspecialchars($module['name']); ?> (<?php echo htmlspecialchars($module['module_key']); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="quantity">Mennyiseg</label>
            <input id="quantity" name="quantity" type="number" min="0" max="999" value="0">
        </div>
        <div class="form-field">
            <button type="submit" name="update_license" class="btn btn-primary">Ment</button>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Aktiv licenszek</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Module</th>
                    <th>Module key</th>
                    <th>Quantity</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licenses)): ?>
                    <tr>
                        <td colspan="5" class="muted">Nincs license.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($license['company_name']); ?></td>
                            <td><?php echo htmlspecialchars($license['module_name']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($license['module_key']); ?></td>
                            <td><?php echo (int)$license['quantity']; ?></td>
                            <td class="nowrap"><?php echo date('Y-m-d', strtotime($license['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
