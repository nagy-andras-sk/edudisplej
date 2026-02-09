<?php
/**
 * Common Header/Navigation Component
 * Simple, compact design
 */
require_once dirname(__DIR__) . '/i18n.php';

$current_lang = edudisplej_apply_language_preferences();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('app.title')); ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="<?php echo strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'style.css' : '../admin/style.css'; ?>">
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
            
            <div class="lang-selector">
                <span><?php echo htmlspecialchars(t('lang.label')); ?></span>
                <select onchange="changeLanguage(this.value)">
                    <option value="hu" <?php echo $current_lang === 'hu' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.hu')); ?></option>
                    <option value="en" <?php echo $current_lang === 'en' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.en')); ?></option>
                    <option value="sk" <?php echo $current_lang === 'sk' ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('lang.sk')); ?></option>
                </select>
            </div>

            <div class="header-links">
                <!-- Dashboard navigation for company users -->
                <?php if (!$is_admin_user && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false): ?>
                    <a href="index.php" class="header-link">🖥️ <?php echo htmlspecialchars(t('nav.kiosks')); ?></a>
                    <a href="groups.php" class="header-link">📁 <?php echo htmlspecialchars(t('nav.groups')); ?></a>
                    <a href="group_modules.php" class="header-link">🎬 <?php echo htmlspecialchars(t('nav.modules')); ?></a>
                    <a href="profile.php" class="header-link">🏢 <?php echo htmlspecialchars(t('nav.profile')); ?></a>
                <?php endif; ?>
                
                <?php if ($is_admin_user && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false): ?>
                    <a href="../admin/index.php" class="header-link">🔐 <?php echo htmlspecialchars(t('nav.admin')); ?></a>
                <?php endif; ?>
                
                <a href="<?php echo isset($logout_url) ? htmlspecialchars($logout_url) : '../login.php?logout=1'; ?>" class="header-link logout">🚪 <?php echo htmlspecialchars(t('nav.logout')); ?></a>
            </div>
        </div>
    </div>
    <?php if ($is_admin_user): ?>
        <div class="admin-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="companies.php">Cégek</a>
            <a href="users.php">Felhasználók</a>
            <a href="kiosk_health.php">Kiosk Health</a>
            <a href="module_licenses.php">Licenszek</a>
            <a href="api_logs.php">API Logok</a>
            <a href="security_logs.php">Security Logok</a>
        </div>
    <?php endif; ?>
    <script>
        function changeLanguage(lang) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }
    </script>
    
    <div class="container">

