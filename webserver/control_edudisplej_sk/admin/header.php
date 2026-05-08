<?php
/**
 * Admin / Dashboard Layout – Header
 * Included by dashboard/index.php and other admin pages.
 *
 * Expected PHP variables (set by the including page):
 *   $current_lang  string   – active language code
 *   $company_name  string   – logged-in company/user name (may be empty)
 *   $session_role  string   – e.g. 'admin', 'user'
 *   $user_id       int      – 0 = static built-in admin
 *   $db_warning    bool     – true when DB is not available (optional)
 */

if (!isset($current_lang))  { $current_lang  = 'sk'; }
if (!isset($company_name))  { $company_name  = $_SESSION['company_name'] ?? ''; }
if (!isset($session_role))  { $session_role  = $_SESSION['user_role']    ?? 'user'; }
if (!isset($user_id))       { $user_id       = (int)($_SESSION['user_id'] ?? 0); }
if (!isset($db_warning))    { $db_warning    = false; }

$is_static_admin = ($user_id === 0 && !empty($_SESSION['isadmin']));
$nav_username    = $is_static_admin ? 'bc' : (string)($_SESSION['username'] ?? $company_name);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduDisplej – Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <style>
        /* ── Reset & base ─────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:        #1e40af;
            --primary-light:  #3b82f6;
            --primary-dark:   #1e3a8a;
            --danger:         #dc2626;
            --warning:        #d97706;
            --success:        #16a34a;
            --info:           #0369a1;
            --gray-50:        #f9fafb;
            --gray-100:       #f3f4f6;
            --gray-200:       #e5e7eb;
            --gray-300:       #d1d5db;
            --gray-400:       #9ca3af;
            --gray-500:       #6b7280;
            --gray-600:       #4b5563;
            --gray-700:       #374151;
            --gray-800:       #1f2937;
            --gray-900:       #111827;
            --radius:         6px;
            --shadow:         0 1px 4px rgba(0,0,0,.10);
            --shadow-md:      0 4px 16px rgba(0,0,0,.12);
        }

        html, body {
            height: 100%;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            color: var(--gray-800);
            background: var(--gray-50);
        }

        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Topbar nav ────────────────────────────────────────────────────── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 0 20px;
            height: 50px;
            box-shadow: var(--shadow-md);
        }
        .topbar-brand {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #fff;
            text-decoration: none;
            white-space: nowrap;
        }
        .topbar-brand:hover { text-decoration: none; opacity: .9; }
        .topbar-spacer { flex: 1; }
        .topbar-user {
            font-size: 13px;
            opacity: .85;
            white-space: nowrap;
        }
        .topbar-link {
            color: #fff;
            font-size: 13px;
            padding: 6px 12px;
            border-radius: var(--radius);
            background: rgba(255,255,255,.12);
            text-decoration: none;
            transition: background .15s;
        }
        .topbar-link:hover { background: rgba(255,255,255,.25); text-decoration: none; }

        /* ── Alert banners ─────────────────────────────────────────────────── */
        .alert {
            padding: 10px 18px;
            border-left: 4px solid currentColor;
            border-radius: var(--radius);
            margin-bottom: 14px;
            font-size: 13px;
            line-height: 1.5;
        }
        .alert-warning {
            background: #fffbeb;
            color: var(--warning);
            border-color: var(--warning);
        }
        .alert-danger {
            background: #fef2f2;
            color: var(--danger);
            border-color: var(--danger);
        }
        .alert-info {
            background: #eff6ff;
            color: var(--info);
            border-color: var(--info);
        }

        /* ── Page layout ────────────────────────────────────────────────────── */
        .page-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 22px 20px 40px;
        }

        /* ── Panels / cards ─────────────────────────────────────────────────── */
        .panel {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 18px 20px;
            box-shadow: var(--shadow);
        }

        /* ── Buttons ─────────────────────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            background: #fff;
            color: var(--gray-700);
            text-decoration: none;
            transition: background .12s, border-color .12s;
        }
        .btn:hover { background: var(--gray-100); text-decoration: none; }
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-danger {
            background: var(--danger);
            border-color: var(--danger);
            color: #fff;
        }

        /* ── Tables ──────────────────────────────────────────────────────────── */
        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }
        .minimal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .minimal-table th,
        .minimal-table td {
            padding: 9px 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        .minimal-table th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-600);
            white-space: nowrap;
        }
        .minimal-table tbody tr:hover { background: var(--gray-50); }

        /* ── Utility ─────────────────────────────────────────────────────────── */
        .muted    { color: var(--gray-500); }
        .nowrap   { white-space: nowrap; }
        .text-sm  { font-size: 12px; }

        /* ── Status badges ───────────────────────────────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-badge.status-online {
            background: #dcfce7; color: #15803d; border: 1px solid #86efac;
        }
        .status-badge.status-offline {
            background: #fee2e2; color: var(--danger); border: 1px solid #fca5a5;
        }

        /* ── Summary bar ─────────────────────────────────────────────────────── */
        .minimal-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
            align-items: center;
        }
        .summary-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            background: #fff;
            transition: background .12s;
        }
        .summary-item:hover,
        .summary-item.active { background: var(--gray-100); border-color: var(--gray-300); }
        .summary-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-total   { background: var(--gray-400); }
        .dot-online  { background: #16a34a; }
        .dot-offline { background: var(--danger); }
        .dot-groups  { background: var(--primary-light); }

        /* ── Screenshot preview cards ────────────────────────────────────────── */
        .kiosk-screenshot-cell { max-width: 160px; }
        .preview-card {
            position: relative;
            border: 1px solid var(--gray-200);
            border-radius: 5px;
            overflow: hidden;
            background: var(--gray-50);
            width: 100%;
            aspect-ratio: 16/9;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .preview-card .screenshot-img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .preview-card .screenshot-timestamp {
            position: absolute;
            bottom: 2px; right: 4px;
            font-size: 10px;
            color: #fff;
            background: rgba(0,0,0,.55);
            padding: 1px 4px;
            border-radius: 3px;
        }
        .preview-card.placeholder {
            background: repeating-linear-gradient(135deg, #e5e7eb 0 10px, #d1d5db 10px 20px);
        }
        .screenshot-loader {
            font-size: 11px;
            color: var(--gray-600);
            text-align: center;
            padding: 8px;
        }

        /* ── Kiosk modal ─────────────────────────────────────────────────────── */
        .kiosk-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 500;
            align-items: center;
            justify-content: center;
        }
        .kiosk-modal.open { display: flex; }
        .kiosk-modal-box {
            background: #fff;
            border-radius: 8px;
            box-shadow: var(--shadow-md);
            width: min(680px, 96vw);
            max-height: 90vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .kiosk-modal-header {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            border-bottom: 1px solid var(--gray-200);
        }
        .kiosk-modal-title { font-weight: 600; flex: 1; }
        .kiosk-modal-close {
            background: none; border: none; font-size: 22px;
            cursor: pointer; color: var(--gray-500); line-height: 1;
            padding: 0 4px;
        }
        .kiosk-modal-close:hover { color: var(--danger); }
        .kiosk-modal-body { padding: 18px; }

        /* ── Kiosk detail table (inside modal) ──────────────────────────────── */
        .kiosk-detail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .kiosk-detail-table th { width: 35%; text-align: left; padding: 6px 0; color: var(--gray-500); font-weight: 600; }
        .kiosk-detail-table td { padding: 6px 0 6px 10px; }

        /* ── Screenshot viewer overlay (inside modal) ────────────────────────── */
        #screenshot-viewer-img, #screenshot-viewer-placeholder { border-radius: 5px; }

        /* ── History gallery ─────────────────────────────────────────────────── */
        .history-gallery { display: flex; flex-wrap: wrap; gap: 8px; }
        .history-offline-marker { /* styled inline */ }

        /* ── Countdown timer label ───────────────────────────────────────────── */
        .countdown-timer { font-size: 11px; color: #888; }

        /* ── Responsive ──────────────────────────────────────────────────────── */
        @media (max-width: 640px) {
            .topbar { gap: 8px; padding: 0 12px; }
            .page-content { padding: 14px 10px 30px; }
        }
    </style>
</head>
<body>

<nav class="topbar">
    <a class="topbar-brand" href="../dashboard/">EduDisplej</a>
    <div class="topbar-spacer"></div>
    <?php if ($nav_username !== ''): ?>
        <span class="topbar-user">👤 <?php echo htmlspecialchars($nav_username); ?></span>
    <?php endif; ?>
    <a class="topbar-link" href="../login?logout=1">
        <?php echo htmlspecialchars(function_exists('t_def') ? t_def('nav.logout', 'Odhlásiť') : 'Odhlásiť'); ?>
    </a>
</nav>

<div class="page-content">

<?php if ($db_warning || $is_static_admin): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
    ⚠️ <?php
        $msg = function_exists('t_def')
            ? t_def('dashboard.warning.no_db', 'Adatbázis-kapcsolat nem elérhető. Csak a statikus teszt-fiók működik. A kiosk-lista üres.')
            : 'Adatbázis-kapcsolat nem elérhető. Csak a statikus teszt-fiók működik. A kiosk-lista üres.';
        echo htmlspecialchars($msg);
    ?>
    <br><small style="opacity:.8;">
        <?php
        echo htmlspecialchars(function_exists('t_def')
            ? t_def('dashboard.warning.no_db_hint', 'Bejelentkezve: statikus admin fiók (bc@nagyandras.sk). Az éles adatok eléréséhez konfigurálja az adatbázist (dbkonfiguracia.php).')
            : 'Bejelentkezve: statikus admin fiók (bc@nagyandras.sk). Az éles adatok eléréséhez konfigurálja az adatbázist (dbkonfiguracia.php).');
        ?>
    </small>
</div>
<?php endif; ?>
