<?php
/**
 * Company Management - Simplified Version
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
            $error = "Cannot delete company: it has $kiosk_count kiosk(s) and $user_count user(s) assigned.";
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
    }
}

// Handle company creation/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $company_id = isset($_POST['company_id']) ? intval($_POST['company_id']) : 0;
    $name = trim($_POST['company_name'] ?? '');
    
    if (empty($name)) {
        $error = 'Company name is required';
    } else {
        try {
            $conn = getDbConnection();
            
            if ($company_id > 0) {
                // Update existing
                $stmt = $conn->prepare("UPDATE companies SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $name, $company_id);
                $success = 'Company updated successfully';
            } else {
                // Create new
                $stmt = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
                $stmt->bind_param("s", $name);
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

// Get companies
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

// Get company for editing
$edit_company = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($companies as $company) {
        if ($company['id'] == $_GET['edit']) {
            $edit_company = $company;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Management - EduDisplej</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        
        .header {
            background: #1e40af;
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 20px;
        }
        
        .header a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
        }
        
        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }
        
        .alert {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-size: 14px;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        button, .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #1e40af;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            color: #495057;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .token-display {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .token-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            width: 90%;
        }
        
        .token-content h3 {
            margin-bottom: 15px;
        }
        
        .token-value {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
            word-break: break-all;
            font-family: monospace;
            font-size: 12px;
        }
        
        .close-btn {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏢 Company Management</h1>
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="alert error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Create/Edit Form -->
        <div class="card">
            <h2><?php echo $edit_company ? '✏️ Edit Company' : '➕ Create New Company'; ?></h2>
            <form method="POST" action="">
                <?php if ($edit_company): ?>
                    <input type="hidden" name="company_id" value="<?php echo $edit_company['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" 
                           value="<?php echo $edit_company ? htmlspecialchars($edit_company['name']) : ''; ?>" 
                           required>
                </div>
                
                <button type="submit" name="save_company" class="btn-primary">
                    <?php echo $edit_company ? '💾 Update Company' : '➕ Create Company'; ?>
                </button>
                
                <?php if ($edit_company): ?>
                    <a href="companies.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Companies Table -->
        <div class="card">
            <h2>📋 All Companies</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company Name</th>
                        <th>Kiosks</th>
                        <th>Users</th>
                        <th>API Token</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($companies)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #999;">No companies found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($companies as $company): ?>
                            <tr>
                                <td><?php echo $company['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($company['name']); ?></strong></td>
                                <td><?php echo $company['kiosk_count']; ?></td>
                                <td><?php echo $company['user_count']; ?></td>
                                <td>
                                    <?php if (!empty($company['api_token'])): ?>
                                        <button onclick="viewToken(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($company['api_token'], ENT_QUOTES); ?>')" 
                                                class="btn btn-success btn-sm">
                                            🔑 View Token
                                        </button>
                                        <button onclick="regenerateToken(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>')" 
                                                class="btn btn-warning btn-sm">
                                            🔄 Regenerate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="generateToken(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>')" 
                                                class="btn btn-primary btn-sm">
                                            ➕ Generate Token
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?edit=<?php echo $company['id']; ?>" class="btn btn-primary btn-sm">
                                        ✏️ Edit
                                    </a>
                                    <a href="?delete=<?php echo $company['id']; ?>" 
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Delete <?php echo htmlspecialchars($company['name'], ENT_QUOTES); ?>?')">
                                        🗑️ Delete
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Token Display Modal -->
    <div id="tokenModal" class="token-display">
        <div class="token-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">API Token</h3>
            <p><strong>Company:</strong> <span id="modalCompany"></span></p>
            
            <div style="margin: 20px 0;">
                <strong>API Token:</strong>
                <div class="token-value" id="modalToken"></div>
                <button onclick="copyToken()" class="btn btn-primary btn-sm">📋 Copy Token</button>
            </div>
            
            <div style="margin: 20px 0;">
                <strong>Install Command:</strong>
                <div class="token-value" id="modalCommand"></div>
                <button onclick="copyCommand()" class="btn btn-primary btn-sm">📋 Copy Command</button>
            </div>
            
            <button onclick="closeModal()" class="btn btn-secondary">Close</button>
        </div>
    </div>
    
    <script>
        let currentToken = '';
        let currentCommand = '';
        
        function viewToken(companyId, companyName, token) {
            document.getElementById('modalTitle').textContent = 'View API Token';
            document.getElementById('modalCompany').textContent = companyName;
            document.getElementById('modalToken').textContent = token;
            
            currentToken = token;
            currentCommand = 'curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' + token;
            
            document.getElementById('modalCommand').textContent = currentCommand;
            document.getElementById('tokenModal').style.display = 'block';
        }
        
        function generateToken(companyId, companyName) {
            if (!confirm('Generate new API token for ' + companyName + '?')) {
                return;
            }
            
            fetch('../api/generate_token.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    company_id: companyId,
                    action: 'generate'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.token) {
                    viewToken(companyId, companyName, data.token);
                } else {
                    alert('Error: ' + (data.message || 'Failed to generate token'));
                }
            })
            .catch(error => {
                alert('Error generating token: ' + error);
            });
        }
        
        function regenerateToken(companyId, companyName) {
            if (!confirm('WARNING: Regenerating the token will invalidate the old one!\n\nRegenerate token for ' + companyName + '?')) {
                return;
            }
            
            fetch('../api/generate_token.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    company_id: companyId,
                    action: 'regenerate'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.token) {
                    viewToken(companyId, companyName, data.token);
                } else {
                    alert('Error: ' + (data.message || 'Failed to regenerate token'));
                }
            })
            .catch(error => {
                alert('Error regenerating token: ' + error);
            });
        }
        
        function closeModal() {
            document.getElementById('tokenModal').style.display = 'none';
        }
        
        function copyToken() {
            navigator.clipboard.writeText(currentToken).then(() => {
                alert('Token copied to clipboard!');
            });
        }
        
        function copyCommand() {
            navigator.clipboard.writeText(currentCommand).then(() => {
                alert('Install command copied to clipboard!');
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('tokenModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
