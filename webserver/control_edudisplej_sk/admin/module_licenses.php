<?php
/**
 * Module License Management
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Handle license update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_license'])) {
    $company_id = intval($_POST['company_id'] ?? 0);
    $module_id = intval($_POST['module_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    
    if ($company_id > 0 && $module_id > 0) {
        try {
            $conn = getDbConnection();
            
            // Check if license already exists
            $stmt = $conn->prepare("SELECT id FROM module_licenses WHERE company_id = ? AND module_id = ?");
            $stmt->bind_param("ii", $company_id, $module_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing license
                if ($quantity > 0) {
                    $stmt = $conn->prepare("UPDATE module_licenses SET quantity = ? WHERE company_id = ? AND module_id = ?");
                    $stmt->bind_param("iii", $quantity, $company_id, $module_id);
                    $stmt->execute();
                    $success = 'License updated successfully';
                } else {
                    // Remove license if quantity is 0
                    $stmt = $conn->prepare("DELETE FROM module_licenses WHERE company_id = ? AND module_id = ?");
                    $stmt->bind_param("ii", $company_id, $module_id);
                    $stmt->execute();
                    $success = 'License removed successfully';
                }
            } else {
                // Insert new license
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

// Get companies, modules, and licenses
$companies = [];
$modules = [];
$licenses = [];

try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get modules
    $result = $conn->query("SELECT * FROM modules WHERE is_active = 1 ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    
    // Get licenses
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module License Management - EduDisplej</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .license-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .license-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .license-card h3 {
            margin-bottom: 15px;
            color: #1e40af;
        }
        
        .module-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            margin-bottom: 10px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        
        .quantity-input {
            width: 60px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-align: center;
        }
        
        .save-btn {
            padding: 5px 10px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .save-btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔑 Module License Management</h1>
        <a href="index.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h2>Module License Overview</h2>
            <p style="color: #666; margin-top: 10px;">
                Manage module licenses for each company. Set the quantity to control how many kiosks can use each module.
            </p>
        </div>
        
        <?php if (empty($companies)): ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                No companies found. <a href="companies.php">Create a company first</a>.
            </div>
        <?php else: ?>
            <div class="license-grid">
                <?php foreach ($companies as $company): ?>
                    <div class="license-card">
                        <h3>🏢 <?php echo htmlspecialchars($company['name']); ?></h3>
                        
                        <?php foreach ($modules as $module): 
                            // Find existing license
                            $current_quantity = 0;
                            foreach ($licenses as $license) {
                                if ($license['company_id'] == $company['id'] && $license['module_id'] == $module['id']) {
                                    $current_quantity = $license['quantity'];
                                    break;
                                }
                            }
                        ?>
                            <form method="POST" class="module-item">
                                <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                                <input type="hidden" name="module_id" value="<?php echo $module['id']; ?>">
                                
                                <div>
                                    <strong><?php echo htmlspecialchars($module['name']); ?></strong>
                                    <br>
                                    <small style="color: #999;"><?php echo htmlspecialchars($module['module_key']); ?></small>
                                </div>
                                
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input 
                                        type="number" 
                                        name="quantity" 
                                        class="quantity-input" 
                                        value="<?php echo $current_quantity; ?>" 
                                        min="0" 
                                        max="999"
                                        placeholder="0"
                                    >
                                    <button type="submit" name="update_license" class="save-btn">💾 Save</button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($licenses)): ?>
            <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-top: 30px;">
                <h2>Active Licenses Summary</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Module</th>
                            <th>Module Key</th>
                            <th>Quantity</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $license): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($license['company_name']); ?></td>
                                <td><?php echo htmlspecialchars($license['module_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($license['module_key']); ?></code></td>
                                <td><strong><?php echo $license['quantity']; ?></strong></td>
                                <td><?php echo date('Y-m-d', strtotime($license['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

