<?php
/**
 * Lightweight DB auto-fix for admin portal bootstrap.
 * Runs at most once every 15 minutes per PHP process lifecycle window.
 */

if (defined('EDUDISPLEJ_DB_AUTOFIX_BOOTSTRAPPED')) {
    return;
}
define('EDUDISPLEJ_DB_AUTOFIX_BOOTSTRAPPED', true);

require_once dirname(__DIR__) . '/dbkonfiguracia.php';

function edudisplej_db_autofix_run(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $last_run = (int)($_SESSION['db_autofix_last_run'] ?? 0);
        if ($last_run > 0 && (time() - $last_run) < 900) {
            return;
        }
        $_SESSION['db_autofix_last_run'] = time();
    }

    try {
        $conn = getDbConnection();

        $conn->query("CREATE TABLE IF NOT EXISTS company_licenses (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_id INT(11) NOT NULL,
            valid_from DATE NOT NULL,
            valid_until DATE NOT NULL,
            device_limit INT(11) NOT NULL DEFAULT 10,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_status (status),
            CONSTRAINT company_licenses_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value LONGTEXT DEFAULT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS email_templates (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) NOT NULL,
            lang VARCHAR(5) NOT NULL DEFAULT 'en',
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_template_lang (template_key, lang),
            INDEX idx_template_key (template_key),
            INDEX idx_lang (lang)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS email_logs (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) DEFAULT NULL,
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            result VARCHAR(20) NOT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_result (result),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS email_queue (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) DEFAULT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            status ENUM('queued','processing','sent','failed','archived') NOT NULL DEFAULT 'queued',
            attempts INT(11) NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status_created (status, created_at),
            INDEX idx_template (template_key),
            INDEX idx_to_email (to_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token (token_hash),
            INDEX idx_user (user_id),
            INDEX idx_expires (expires_at),
            CONSTRAINT password_reset_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS service_versions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(255) NOT NULL,
            version_token VARCHAR(64) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by_user_id INT(11) DEFAULT NULL,
            UNIQUE KEY uniq_service_name (service_name),
            INDEX idx_updated_by_user (updated_by_user_id),
            CONSTRAINT service_versions_user_fk FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $kiosk_columns = [
            'last_heartbeat' => "ALTER TABLE kiosks ADD COLUMN last_heartbeat DATETIME DEFAULT NULL",
            'license_active' => "ALTER TABLE kiosks ADD COLUMN license_active TINYINT(1) NOT NULL DEFAULT 1",
            'activated_at' => "ALTER TABLE kiosks ADD COLUMN activated_at DATETIME DEFAULT NULL",
            'debug_mode' => "ALTER TABLE kiosks ADD COLUMN debug_mode TINYINT(1) NOT NULL DEFAULT 0",
            'screen_off_mode' => "ALTER TABLE kiosks ADD COLUMN screen_off_mode VARCHAR(20) NOT NULL DEFAULT 'signal_off'",
            'upgrade_started_at' => "ALTER TABLE kiosks ADD COLUMN upgrade_started_at DATETIME DEFAULT NULL"
        ];

        foreach ($kiosk_columns as $col => $sql) {
            $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE '" . $conn->real_escape_string($col) . "'");
            if (!$check || $check->num_rows === 0) {
                $conn->query($sql);
            }
        }

        $status_col = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'status'");
        if ($status_col && $status_col->num_rows > 0) {
            $status_row = $status_col->fetch_assoc();
            $status_type = strtolower((string)($status_row['Type'] ?? ''));
            if (strpos($status_type, "'upgrading'") === false || strpos($status_type, "'error'") === false) {
                $conn->query("ALTER TABLE kiosks MODIFY COLUMN status ENUM('online','offline','pending','unconfigured','upgrading','error') DEFAULT 'unconfigured'");
            }
        }

        $user_role_check = $conn->query("SHOW COLUMNS FROM users LIKE 'user_role'");
        if (!$user_role_check || $user_role_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN user_role VARCHAR(32) NOT NULL DEFAULT 'user' AFTER isadmin");
            $conn->query("UPDATE users SET user_role = 'admin' WHERE isadmin = 1");
        }

        $user_last_activity_check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity_at'");
        if (!$user_last_activity_check || $user_last_activity_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN last_activity_at DATETIME NULL DEFAULT NULL AFTER last_login");
        }

        $user_backup_codes_check = $conn->query("SHOW COLUMNS FROM users LIKE 'backup_codes'");
        if (!$user_backup_codes_check || $user_backup_codes_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN backup_codes TEXT NULL COMMENT 'JSON array of hashed backup codes'");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS archived_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_user_id INT NULL,
            username VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NOT NULL,
            company_id INT NULL,
            isadmin TINYINT(1) NOT NULL DEFAULT 0,
            user_role VARCHAR(32) NOT NULL DEFAULT 'user',
            otp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            otp_verified TINYINT(1) NOT NULL DEFAULT 0,
            otp_secret VARCHAR(255) NULL,
            last_login DATETIME NULL,
            original_created_at DATETIME NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_by_user_id INT NULL,
            archive_reason VARCHAR(64) NOT NULL DEFAULT 'manual_delete',
            archive_note TEXT NULL,
            restored_at DATETIME NULL,
            restored_by_user_id INT NULL,
            restored_user_id INT NULL,
            INDEX idx_archived_at (archived_at),
            INDEX idx_original_user (original_user_id),
            INDEX idx_username (username),
            INDEX idx_company (company_id),
            INDEX idx_restored_at (restored_at),
            CONSTRAINT archived_users_company_fk FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            CONSTRAINT archived_users_archived_by_fk FOREIGN KEY (archived_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT archived_users_restored_by_fk FOREIGN KEY (restored_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT archived_users_restored_user_fk FOREIGN KEY (restored_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS kiosk_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kiosk_id INT NOT NULL,
            source_company_id INT NULL,
            target_company_id INT NOT NULL,
            target_api_token VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            requested_by_user_id INT NULL,
            command_queue_id INT NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_kiosk (kiosk_id),
            INDEX idx_status (status),
            INDEX idx_target_company (target_company_id),
            CONSTRAINT kiosk_migrations_kiosk_fk FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE,
            CONSTRAINT kiosk_migrations_source_company_fk FOREIGN KEY (source_company_id) REFERENCES companies(id) ON DELETE SET NULL,
            CONSTRAINT kiosk_migrations_target_company_fk FOREIGN KEY (target_company_id) REFERENCES companies(id) ON DELETE CASCADE,
            CONSTRAINT kiosk_migrations_requested_by_fk FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT kiosk_migrations_command_fk FOREIGN KEY (command_queue_id) REFERENCES kiosk_command_queue(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $layout_key = 'email_base_layout_html';
        $layout_value = '<!doctype html>\n<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">\n<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fb;padding:24px 12px;">\n  <tr><td align="center">\n    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">\n      <tr><td style="background:linear-gradient(135deg,#1e40af 0%,#0369a1 100%);padding:20px 24px;color:#ffffff;font-size:20px;font-weight:700;">{{site_name}}</td></tr>\n      <tr><td style="padding:24px;">\n        <h2 style="margin:0 0 14px 0;font-size:20px;color:#0f172a;">{{subject}}</h2>\n        {{content}}\n      </td></tr>\n      <tr><td style="padding:16px 24px;color:#64748b;font-size:12px;border-top:1px solid #e5e7eb;">This is an automated message from {{site_name}}.</td></tr>\n    </table>\n  </td></tr>\n</table>\n</body></html>';
        $ins_layout = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = IF(setting_value IS NULL OR setting_value = '', VALUES(setting_value), setting_value)");
        if ($ins_layout) {
            $ins_layout->bind_param('ss', $layout_key, $layout_value);
            $ins_layout->execute();
            $ins_layout->close();
        }

        $seed_templates = [
            ['password_reset', 'hu', 'Jelszó visszaállítás', '<p>Kedves {{name}},</p><p>Új jelszó beállításához kattintson az alábbi gombra:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Jelszó visszaállítása</a></p><p>Ha nem Ön kérte, hagyja figyelmen kívül ezt az üzenetet.</p>', "Kedves {{name}},\n\nÚj jelszó beállításához nyissa meg: {{reset_link}}\n\nHa nem Ön kérte, hagyja figyelmen kívül ezt az üzenetet."],
            ['password_reset', 'en', 'Password reset', '<p>Hello {{name}},</p><p>To set a new password, click the button below:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Reset password</a></p><p>If you did not request this, you can ignore this message.</p>', "Hello {{name}},\n\nTo set a new password, open: {{reset_link}}\n\nIf you did not request this, you can ignore this message."],
            ['password_reset', 'sk', 'Obnovenie hesla', '<p>Dobrý deň {{name}},</p><p>Pre nastavenie nového hesla kliknite na tlačidlo nižšie:</p><p><a href="{{reset_link}}" style="display:inline-block;padding:10px 16px;background:#1e40af;color:#fff;text-decoration:none;border-radius:6px;">Obnoviť heslo</a></p><p>Ak ste o to nepožiadali, tento email ignorujte.</p>', "Dobrý deň {{name}},\n\nPre nastavenie nového hesla otvorte: {{reset_link}}\n\nAk ste o to nepožiadali, tento email ignorujte."],
        ];

        $seed_stmt = $conn->prepare("INSERT INTO email_templates (template_key, lang, subject, body_html, body_text) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = IF(subject IS NULL OR subject = '', VALUES(subject), subject), body_html = IF(body_html IS NULL OR body_html = '', VALUES(body_html), body_html), body_text = IF(body_text IS NULL OR body_text = '', VALUES(body_text), body_text)");
        if ($seed_stmt) {
            foreach ($seed_templates as $tpl) {
                $seed_stmt->bind_param('sssss', $tpl[0], $tpl[1], $tpl[2], $tpl[3], $tpl[4]);
                $seed_stmt->execute();
            }
            $seed_stmt->close();
        }

        closeDbConnection($conn);
    } catch (Throwable $e) {
        error_log('db_autofix_bootstrap: ' . $e->getMessage());
    }
}

edudisplej_db_autofix_run();
