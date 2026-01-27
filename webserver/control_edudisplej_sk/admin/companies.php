<?php
/**
 * Company Management
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

// Handle company deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $company_id = intval($_GET['delete']);
    
    try {
        $conn = getDbConnection();
        
        // Check if company has any kiosks or users
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM kiosks WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk_count = $result->fetch_assoc()['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_count = $result->fetch_assoc()['count'];
        
        if ($kiosk_count > 0 || $user_count > 0) {
            $error = "Cannot delete company: it has $kiosk_count kiosk(s) and $user_count user(s) assigned. Please reassign or remove them first.";
        } else {
            $stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
            $stmt->bind_param("i", $company_id);
            
            if ($stmt->execute()) {
                $success = 'Company deleted successfully';
            } else {
                $error = 'Failed to delete company';
            }
        }
        
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Database error occurred';
        error_log($e->getMessage());
    }
}

// Handle company edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_company'])) {
    $company_id = intval($_POST['company_id'] ?? 0);
    $name = trim($_POST['company_name'] ?? '');
    
    if (empty($name)) {
        $error = 'Company name is required';
    } else {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE companies SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $company_id);
            
            if ($stmt->execute()) {
                $success = 'Company updated successfully';
            } else {
                $error = 'Failed to update company';
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

// Handle company creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_company'])) {
    $name = trim($_POST['company_name'] ?? '');
    
    if (empty($name)) {
        $error = 'Company name is required';
    } else {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            
            if ($stmt->execute()) {
                $success = 'Company created successfully';
            } else {
                $error = 'Failed to create company';
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

// Handle kiosk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_kiosk'])) {
    $kiosk_id = intval($_POST['kiosk_id'] ?? 0);
    $company_id = intval($_POST['company_id'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE kiosks SET company_id = ?, location = ?, comment = ? WHERE id = ?");
        $stmt->bind_param("issi", $company_id, $location, $comment, $kiosk_id);
        
        if ($stmt->execute()) {
            $success = 'Kiosk assigned successfully';
        } else {
            $error = 'Failed to assign kiosk';
        }
        
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Database error occurred';
        error_log($e->getMessage());
    }
}

// Get data
$companies = [];
$kiosks = [];
$edit_company = null;

// Get company for editing if edit mode
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
}

try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
        if (isset($edit_id) && $row['id'] == $edit_id) {
            $edit_company = $row;
        }
    }
    
    // Get kiosks
    $query = "SELECT k.*, c.name as company_name 
              FROM kiosks k 
              LEFT JOIN companies c ON k.company_id = c.id 
              ORDER BY k.hostname";
    $result = $conn->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
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
    <title>Company Management - EduDisplej</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🏢 Company Management</h1>
        <a href="index.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div class="grid">
            <div class="card">
                <h2><?php echo $edit_company ? 'Edit Company' : 'Create Company'; ?></h2>
                <form method="POST">
                    <?php if ($edit_company): ?>
                        <input type="hidden" name="company_id" value="<?php echo $edit_company['id']; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name" 
                               value="<?php echo $edit_company ? htmlspecialchars($edit_company['name']) : ''; ?>" 
                               required>
                    </div>
                    <button type="submit" name="<?php echo $edit_company ? 'edit_company' : 'create_company'; ?>">
                        <?php echo $edit_company ? 'Update Company' : 'Create Company'; ?>
                    </button>
                    <?php if ($edit_company): ?>
                        <a href="companies.php" style="display: inline-block; margin-left: 10px; padding: 12px 24px; background: #ccc; color: #333; text-decoration: none; border-radius: 5px;">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="card">
                <h2>Companies</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Kiosks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $company): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['id']); ?></td>
                                <td><?php echo htmlspecialchars($company['name']); ?></td>
                                <td><?php echo count(array_filter($kiosks, fn($k) => $k['company_id'] == $company['id'])); ?></td>
                                <td>
                                    <a href="?edit=<?php echo $company['id']; ?>" style="display: inline-block; padding: 6px 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-size: 12px;">✏️ Edit</a>
                                    <a href="?delete=<?php echo $company['id']; ?>" 
                                       style="display: inline-block; padding: 6px 12px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; text-decoration: none; border-radius: 5px; font-size: 12px;" 
                                       onclick="return confirm('Are you sure you want to delete this company?')">🗑️ Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <h2>Assign Kiosk to Company</h2>
            <form method="POST">
                <div class="grid">
                    <div class="form-group">
                        <label for="kiosk_id">Select Kiosk</label>
                        <select id="kiosk_id" name="kiosk_id" required>
                            <option value="">-- Select Kiosk --</option>
                            <?php foreach ($kiosks as $kiosk): ?>
                                <option value="<?php echo $kiosk['id']; ?>">
                                    <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                    (<?php echo htmlspecialchars($kiosk['company_name'] ?? 'Unassigned'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_id">Assign to Company</label>
                        <select id="company_id" name="company_id">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>">
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" placeholder="e.g., Main Building, Room 101">
                    </div>
                    
                    <div class="form-group">
                        <label for="comment">Comment</label>
                        <textarea id="comment" name="comment" placeholder="Any notes about this kiosk..."></textarea>
                    </div>
                </div>
                
                <button type="submit" name="assign_kiosk">Assign Kiosk</button>
            </form>
        </div>
    </div>
</body>
</html>
