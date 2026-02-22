<?php
/**
 * Common Header/Navigation Component
 * Simple, compact design
 */
require_once dirname(__DIR__) . '/i18n.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once dirname(__DIR__) . '/auth_roles.php';

$php_self_path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
$path_segments = array_values(array_filter(explode('/', trim($php_self_path, '/')), 'strlen'));
$app_root_index = array_search('control_edudisplej_sk', $path_segments, true);
$depth_from_app_root = 0;
if ($app_root_index !== false) {
    $depth_from_app_root = max(0, count($path_segments) - ($app_root_index + 2));
} else {
    $entry_point_index = array_search('dashboard', $path_segments, true);
    if ($entry_point_index === false) {
        $entry_point_index = array_search('admin', $path_segments, true);
    }
    if ($entry_point_index !== false) {
        $depth_from_app_root = max(0, count($path_segments) - ($entry_point_index + 1));
    }
}
$app_root_prefix = str_repeat('../', $depth_from_app_root);

$dashboard_index = array_search('dashboard', $path_segments, true);
$depth_from_dashboard = 0;
if ($dashboard_index !== false) {
    $depth_from_dashboard = max(0, count($path_segments) - ($dashboard_index + 2));
}
$dashboard_prefix = str_repeat('../', $depth_from_dashboard);
$default_logout_url = $app_root_prefix . 'login.php?logout=1';

$inactivity_limit_seconds = 10 * 60;
if (isset($_SESSION['user_id'])) {
    $last_activity = (int)($_SESSION['last_activity_at'] ?? 0);
    if ($last_activity > 0 && (time() - $last_activity) >= $inactivity_limit_seconds) {
        session_unset();
        session_destroy();
        header('Location: ' . $default_logout_url);
        exit();
    }
    $_SESSION['last_activity_at'] = time();
}

$is_admin_user = !empty($_SESSION['isadmin']);
$is_admin_acting_company = $is_admin_user && !empty($_SESSION['admin_acting_company_id']);
$current_user_role = edudisplej_get_session_role();
$current_lang = edudisplej_apply_language_preferences();

// Determine active page for navigation highlighting
// basename() removes any directory components; validate against alphanumeric + dot chars only
$current_file = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_SERVER['PHP_SELF']));

// Admin nav page map: filename => label
$admin_nav_pages = [
    'dashboard.php'       => ['href' => 'dashboard.php',      'label' => t_def('nav.admin.dashboard', 'Dashboard')],
    'companies.php'       => ['href' => 'companies.php',       'label' => t_def('nav.admin.companies', 'Institutions')],
    'modules.php'         => ['href' => 'modules.php',         'label' => t_def('nav.admin.modules', 'Modules')],
    'users.php'           => ['href' => 'users.php',           'label' => t_def('nav.admin.users', 'Users')],
    'archived_users.php'  => ['href' => 'archived_users.php',  'label' => t_def('nav.admin.archived_users', 'Archived Users')],
    'kiosk_migrations.php'=> ['href' => 'kiosk_migrations.php','label' => t_def('nav.admin.kiosk_migrations', 'Kiosk Migration')],
    'translations.php'    => ['href' => 'translations.php',    'label' => t('nav.translations')],
    'settings.php'        => ['href' => '../dashboard/settings.php', 'label' => t('nav.settings')],
    'kiosk_health.php'    => ['href' => 'kiosk_health.php',    'label' => t_def('nav.admin.kiosk_health', 'Kiosk Health')],
    'module_licenses.php' => ['href' => 'module_licenses.php', 'label' => t_def('nav.admin.module_licenses', 'Module Licenses')],
    'licenses.php'        => ['href' => 'licenses.php',        'label' => t_def('nav.admin.licenses', 'Institution Licenses')],
    'services.php'        => ['href' => 'services.php',        'label' => t_def('nav.admin.services', 'Service Updates')],
    'email_settings.php'  => ['href' => 'email_settings.php',  'label' => t_def('nav.admin.email_settings', 'Email Settings')],
    'email_templates.php' => ['href' => 'email_templates.php', 'label' => t_def('nav.admin.email_templates', 'Email Templates')],
    'api_logs.php'        => ['href' => 'api_logs.php',        'label' => t_def('nav.admin.api_logs', 'API Logs')],
    'security_logs.php'   => ['href' => 'security_logs.php',   'label' => t_def('nav.admin.security_logs', 'Security Logs')],
];

// Dashboard nav page map
$dashboard_nav_pages = [
    'index.php'          => ['href' => 'index.php',          'label' => '🖥️ ' . t('nav.kiosks'),  'key' => 'kiosks'],
    'groups.php'         => ['href' => 'groups.php',         'label' => '📁 ' . t('nav.groups'),  'key' => 'groups'],
    'profile.php'        => ['href' => 'profile.php',        'label' => '🏢 ' . t('nav.profile'), 'key' => 'profile'],
    'settings.php'       => ['href' => 'settings.php',       'label' => '⚙️ ' . t('nav.settings'), 'key' => 'settings'],
];

if (!$is_admin_user) {
    if ($current_user_role === 'content_editor') {
        $dashboard_nav_pages = [
            'content_editor_index.php' => ['href' => 'content_editor_index.php', 'label' => '🖥️ ' . t('nav.kiosks'), 'key' => 'kiosks'],
            'settings.php' => ['href' => 'settings.php', 'label' => '⚙️ ' . t('nav.settings'), 'key' => 'settings'],
        ];
    } elseif ($current_user_role === 'loop_manager') {
        $dashboard_nav_pages = [
            'index.php'  => ['href' => 'index.php', 'label' => '🖥️ ' . t('nav.kiosks'), 'key' => 'kiosks'],
            'groups.php' => ['href' => 'groups.php', 'label' => '📁 ' . t('nav.groups'), 'key' => 'groups'],
            'settings.php' => ['href' => 'settings.php', 'label' => '⚙️ ' . t('nav.settings'), 'key' => 'settings'],
        ];
    }
}

$breadcrumb_label = $admin_nav_pages[$current_file]['label']
    ?? $dashboard_nav_pages[$current_file]['label']
    ?? null;

if (!isset($breadcrumb_items) || !is_array($breadcrumb_items)) {
    $breadcrumb_items = [];
    if ($breadcrumb_label !== null) {
        $breadcrumb_items[] = [
            'label' => strip_tags((string)$breadcrumb_label),
            'current' => true,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('app.title')); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($app_root_prefix . 'favicon.svg'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_root_prefix . 'admin/style.css'); ?>">
</head>
<body>
    <!-- COMPACT HEADER -->
    <div class="header">
        <h1>EDUDISPLEJ</h1>
        <div class="header-nav">
            <?php
                $current_user = $_SESSION['username'] ?? 'Felhasználó';
                $company_display = $company_name ?? '';
                $header_user_href = $is_admin_user
                    ? ($app_root_prefix . 'admin/companies.php')
                    : ($app_root_prefix . 'dashboard/profile.php');
            ?>
            <a href="<?php echo htmlspecialchars($header_user_href); ?>" class="header-user" style="text-decoration:none;color:inherit;cursor:pointer;">
                👤 <strong><?php echo htmlspecialchars($current_user); ?></strong>
                <?php if ($company_display): ?>
                    <span style="color: rgba(255,255,255,0.6);">(<?php echo htmlspecialchars($company_display); ?>)</span>
                <?php endif; ?>
            </a>
            
            <div class="header-links">
                <!-- Dashboard navigation for company users -->
                <?php if (strpos($_SERVER['PHP_SELF'], 'dashboard') !== false && (!$is_admin_user || $is_admin_acting_company)): ?>
                    <?php foreach ($dashboard_nav_pages as $page_file => $page): ?>
                        <a href="<?php echo htmlspecialchars($dashboard_prefix . $page['href']); ?>" class="header-link<?php echo $current_file === $page_file ? ' active' : ''; ?>">
                            <?php echo htmlspecialchars($page['label']); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if ($is_admin_user && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false): ?>
                    <a href="<?php echo htmlspecialchars($app_root_prefix . 'admin/index.php'); ?>" class="header-link">🔐 <?php echo htmlspecialchars(t('nav.admin')); ?></a>
                <?php endif; ?>

                <a href="<?php echo isset($logout_url) ? htmlspecialchars($logout_url) : htmlspecialchars($default_logout_url); ?>" class="header-link logout">🚪 <?php echo htmlspecialchars(t('nav.logout')); ?></a>
            </div>
        </div>
    </div>
    <?php if ($is_admin_user): ?>
        <div class="admin-nav">
            <?php foreach ($admin_nav_pages as $page_file => $page): ?>
                <?php
                    $admin_href = (string)($page['href'] ?? '');
                    if ($admin_href !== '' && preg_match('#^(?:https?:)?//#i', $admin_href) !== 1 && strpos($admin_href, '#') !== 0) {
                        if (strpos($admin_href, '../dashboard/') === 0) {
                            $admin_href = $app_root_prefix . 'dashboard/' . substr($admin_href, strlen('../dashboard/'));
                        } elseif (strpos($admin_href, 'dashboard/') === 0) {
                            $admin_href = $app_root_prefix . $admin_href;
                        } elseif (strpos($admin_href, '../admin/') === 0) {
                            $admin_href = $app_root_prefix . 'admin/' . substr($admin_href, strlen('../admin/'));
                        } elseif (strpos($admin_href, 'admin/') === 0) {
                            $admin_href = $app_root_prefix . $admin_href;
                        } elseif (strpos($admin_href, '/') === false) {
                            $admin_href = $app_root_prefix . 'admin/' . $admin_href;
                        } else {
                            $admin_href = $app_root_prefix . ltrim($admin_href, './');
                        }
                    }
                ?>
                <a href="<?php echo htmlspecialchars($admin_href); ?>"<?php echo $current_file === $page_file ? ' class="active"' : ''; ?>>
                    <?php echo htmlspecialchars($page['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($breadcrumb_items)): ?>
        <div class="breadcrumb">
            <?php $breadcrumb_last_index = count($breadcrumb_items) - 1; ?>
            <?php foreach ($breadcrumb_items as $breadcrumb_index => $breadcrumb_item): ?>
                <?php
                    $item_label = '';
                    $item_href = null;
                    $item_is_current = false;

                    if (is_array($breadcrumb_item)) {
                        $item_label = (string)($breadcrumb_item['label'] ?? '');
                        $item_href = isset($breadcrumb_item['href']) ? (string)$breadcrumb_item['href'] : null;
                        $item_is_current = !empty($breadcrumb_item['current']) || !empty($breadcrumb_item['is_current']);
                    } else {
                        $item_label = (string)$breadcrumb_item;
                    }

                    if (!$item_is_current && $breadcrumb_index === $breadcrumb_last_index) {
                        $item_is_current = true;
                    }

                    $item_label = strip_tags($item_label);
                ?>
                <?php if ($item_label === '') { continue; } ?>
                <?php if ($breadcrumb_index > 0): ?>
                    <span class="breadcrumb-sep">&gt;</span>
                <?php endif; ?>

                <?php if ($item_href && !$item_is_current): ?>
                    <a href="<?php echo htmlspecialchars($item_href); ?>"><?php echo htmlspecialchars($item_label); ?></a>
                <?php elseif ($item_is_current): ?>
                    <span class="breadcrumb-current"><?php echo htmlspecialchars($item_label); ?></span>
                <?php else: ?>
                    <span><?php echo htmlspecialchars($item_label); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
        (function initInactivityLogout() {
            var inactivityLimitMs = 10 * 60 * 1000;
            var logoutUrl = <?php echo json_encode(isset($logout_url) ? $logout_url : $default_logout_url); ?>;
            var warningMessage = <?php echo json_encode(t('session.timeout.warning')); ?>;
            var idleTimer = null;

            function logoutAfterIdle() {
                alert(warningMessage);
                window.location.href = logoutUrl;
            }

            function resetIdleTimer() {
                if (idleTimer) {
                    clearTimeout(idleTimer);
                }
                idleTimer = setTimeout(logoutAfterIdle, inactivityLimitMs);
            }

            ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'].forEach(function (eventName) {
                window.addEventListener(eventName, resetIdleTimer, { passive: true });
            });

            resetIdleTimer();
        })();
    </script>
    
    <div class="container">

