<?php
/**
 * Common Header/Navigation Component
 * Simple, compact design
 */
require_once dirname(__DIR__) . '/i18n.php';

$current_lang = edudisplej_apply_language_preferences();

// Determine active page for navigation highlighting
// basename() removes any directory components; validate against alphanumeric + dot chars only
$current_file = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_SERVER['PHP_SELF']));

// Admin nav page map: filename => label
$admin_nav_pages = [
    'dashboard.php'       => ['href' => 'dashboard.php',      'label' => 'Dashboard'],
    'companies.php'       => ['href' => 'companies.php',       'label' => 'Cégek'],
    'users.php'           => ['href' => 'users.php',           'label' => 'Felhasználók'],
    'kiosk_health.php'    => ['href' => 'kiosk_health.php',    'label' => 'Kiosk Health'],
    'module_licenses.php' => ['href' => 'module_licenses.php', 'label' => 'Licenszek'],
    'api_logs.php'        => ['href' => 'api_logs.php',        'label' => 'API Logok'],
    'security_logs.php'   => ['href' => 'security_logs.php',   'label' => 'Security Logok'],
];

// Dashboard nav page map
$dashboard_nav_pages = [
    'index.php'          => ['href' => 'index.php',          'label' => '🖥️ ' . t('nav.kiosks'),  'key' => 'kiosks'],
    'groups.php'         => ['href' => 'groups.php',         'label' => '📁 ' . t('nav.groups'),  'key' => 'groups'],
    'group_modules.php'  => ['href' => 'group_modules.php',  'label' => '🎬 ' . t('nav.modules'), 'key' => 'modules'],
    'profile.php'        => ['href' => 'profile.php',        'label' => '🏢 ' . t('nav.profile'), 'key' => 'profile'],
];

$breadcrumb_label = $admin_nav_pages[$current_file]['label']
    ?? $dashboard_nav_pages[$current_file]['label']
    ?? null;
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
                    <?php foreach ($dashboard_nav_pages as $page_file => $page): ?>
                        <a href="<?php echo htmlspecialchars($page['href']); ?>" class="header-link<?php echo $current_file === $page_file ? ' active' : ''; ?>">
                            <?php echo htmlspecialchars($page['label']); ?>
                        </a>
                    <?php endforeach; ?>
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
            <?php foreach ($admin_nav_pages as $page_file => $page): ?>
                <a href="<?php echo htmlspecialchars($page['href']); ?>"<?php echo $current_file === $page_file ? ' class="active"' : ''; ?>>
                    <?php echo htmlspecialchars($page['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($breadcrumb_label !== null): ?>
        <div class="breadcrumb">
            <span class="breadcrumb-current"><?php echo htmlspecialchars(strip_tags($breadcrumb_label)); ?></span>
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

