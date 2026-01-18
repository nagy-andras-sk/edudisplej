<?php
// header.php - Unified header for EduDisplej website
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - EduDisplej' : 'EduDisplej - Digitálne Zobrazovanie'; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
        }
        
        /* Header Styles */
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-section img {
            height: 50px;
            width: auto;
        }
        
        .logo-section h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        /* Navigation Menu */
        nav {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: flex-start;
            padding: 0 2rem;
        }
        
        nav ul {
            list-style: none;
            display: flex;
            gap: 0;
        }
        
        nav ul li {
            position: relative;
        }
        
        nav ul li a {
            color: white;
            text-decoration: none;
            padding: 1rem 1.5rem;
            display: block;
            transition: background-color 0.3s ease;
            font-weight: 500;
        }
        
        nav ul li a:hover,
        nav ul li a.active {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        /* Main Content */
        main {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .content-card {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        h2 {
            color: #667eea;
            margin-bottom: 1rem;
            font-size: 1.8rem;
        }
        
        h3 {
            color: #764ba2;
            margin-top: 1.5rem;
            margin-bottom: 0.8rem;
            font-size: 1.4rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            font-weight: 500;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 1rem;
            }
            
            nav ul {
                flex-direction: column;
                width: 100%;
            }
            
            nav ul li a {
                padding: 0.8rem 1rem;
            }
            
            .nav-container {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="logo-section">
                <img src="logo.png" alt="EduDisplej Logo">
                <h1>EduDisplej</h1>
            </div>
        </div>
        <nav>
            <div class="nav-container">
                <ul>
                    <li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">Domov</a></li>
                    <li><a href="dashboard/" class="<?php echo strpos($_SERVER['PHP_SELF'], 'dashboard') !== false ? 'active' : ''; ?>">Dashboard</a></li>
                    <li><a href="#" onclick="alert('Pripravuje sa');">O projekte</a></li>
                    <li><a href="#" onclick="alert('Pripravuje sa');">Kontakt</a></li>
                </ul>
            </div>
        </nav>
    </header>
    
    <main>
