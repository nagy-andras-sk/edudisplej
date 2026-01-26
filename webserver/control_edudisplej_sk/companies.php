<?php
/**
 * Company Management
 * EduDisplej Control Panel
 */

session_start();
require_once 'dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: admin.php');
    exit();
}

$error = '';
$success = '';

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

try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .navbar h1 {
            font-size: 24px;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        
        .navbar a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input[type="text"],
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
        
        button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        
        button:hover {
            opacity: 0.9;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        
        .success {
            background: #efe;
            color: #3c3;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #cfc;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f9f9f9;
            font-weight: 600;
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
        <a href="admin.php">← Back to Dashboard</a>
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
                <h2>Create Company</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name" required>
                    </div>
                    <button type="submit" name="create_company">Create Company</button>
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $company): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['id']); ?></td>
                                <td><?php echo htmlspecialchars($company['name']); ?></td>
                                <td><?php echo count(array_filter($kiosks, fn($k) => $k['company_id'] == $company['id'])); ?></td>
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
