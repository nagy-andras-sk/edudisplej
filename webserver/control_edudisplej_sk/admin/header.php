<?php
/**
 * Common Header/Navigation Component
 * Simple, compact design
 */
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduDisplej Control</title>
    <link rel="stylesheet" href="<?php echo strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'style.css' : '../admin/style.css'; ?>">
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
        
        /* HEADER - Simple navbar */
        .header {
            background: #0a1929;
            color: white;
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            letter-spacing: 1px;
        }
        
        .header-nav {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-user {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 13px;
            color: rgba(255,255,255,0.8);
        }
        
        .header-user strong {
            color: white;
        }
        
        .header-links {
            display: flex;
            gap: 10px;
        }
        
        .header-link {
            padding: 6px 12px;
            background: rgba(255,255,255,0.15);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .header-link:hover {
            background: rgba(255,255,255,0.25);
        }
        
        .header-link.logout {
            background: rgba(239, 83, 80, 0.2);
        }
        
        .header-link.logout:hover {
            background: #ef5350;
        }
        
        .container {
            padding: 25px 30px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        
        /* TABLE STYLES */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        
        table thead {
            background: #f9f9f9;
            border-bottom: 2px solid #ddd;
        }
        
        table thead th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        
        table tbody tr:hover {
            background: #f9f9f9;
        }
        
        table tbody td {
            padding: 12px 15px;
            font-size: 13px;
            color: #555;
        }
        
        table tbody tr:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <!-- COMPACT HEADER -->
    <div class="header">
        <h1>EDUDISPLEJ</h1>
        <div class="header-nav">
            <div class="header-user">
                <?php 
                $current_user = $_SESSION['username'] ?? 'Felhasználó';
                $company_display = $company_name ?? '';
                $is_admin_user = $_SESSION['isadmin'] ?? false;
                ?>
                👤 <strong><?php echo htmlspecialchars($current_user); ?></strong>
                <?php if ($company_display): ?>
                    <span style="color: rgba(255,255,255,0.6);">(<?php echo htmlspecialchars($company_display); ?>)</span>
                <?php endif; ?>
            </div>
            
            <div class="header-links">
                <!-- Dashboard navigation for company users -->
                <?php if (!$is_admin_user && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false): ?>
                    <a href="index.php" class="header-link">🖥️ Kijelzők</a>
                    <a href="groups.php" class="header-link">📁 Csoportok</a>
                    <a href="group_modules.php" class="header-link">🎬 Modulok</a>
                    <a href="profile.php" class="header-link">🏢 Profil</a>
                <?php endif; ?>
                
                <?php if ($is_admin_user && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false): ?>
                    <a href="../admin/index.php" class="header-link">🔐 Admin</a>
                <?php endif; ?>
                
                <a href="<?php echo isset($logout_url) ? htmlspecialchars($logout_url) : '../login.php?logout=1'; ?>" class="header-link logout">🚪 Kilépés</a>
            </div>
        </div>
    </div>
    
    <div class="container">

