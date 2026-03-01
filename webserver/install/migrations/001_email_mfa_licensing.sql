-- Migration 001: Email settings, templates, password reset, backup codes, licensing
-- Run once on existing schema.

-- Email/SMTP settings
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    is_encrypted  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Multilingual email templates
CREATE TABLE IF NOT EXISTS email_templates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL,
    lang         VARCHAR(10)  NOT NULL DEFAULT 'hu',
    subject      VARCHAR(500) NOT NULL,
    body_html    TEXT         NOT NULL,
    body_text    TEXT         NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_lang (template_key, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email send log
CREATE TABLE IF NOT EXISTS email_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    template_key  VARCHAR(100) NULL,
    to_email      VARCHAR(255) NOT NULL,
    subject       VARCHAR(500) NULL,
    result        ENUM('success','error') NOT NULL,
    error_message TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_result  (result),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email queue
CREATE TABLE IF NOT EXISTS email_queue (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    template_key  VARCHAR(100) NULL,
    to_email      VARCHAR(255) NOT NULL,
    to_name       VARCHAR(255) NULL,
    subject       VARCHAR(255) NOT NULL,
    body_html     LONGTEXT NULL,
    body_text     LONGTEXT NULL,
    status        ENUM('queued','processing','sent','failed','archived') NOT NULL DEFAULT 'queued',
    attempts      INT NOT NULL DEFAULT 0,
    last_error    TEXT NULL,
    sent_at       DATETIME NULL,
    archived_at   DATETIME NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_created (status, created_at),
    INDEX idx_template (template_key),
    INDEX idx_to_email (to_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Base HTML layout for all emails
INSERT INTO system_settings (setting_key, setting_value, is_encrypted)
VALUES (
    'email_base_layout_html',
    '<!doctype html>\n<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f6fb;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">\n<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fb;padding:24px 12px;">\n  <tr><td align="center">\n    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">\n      <tr><td style="background:linear-gradient(135deg,#1e40af 0%,#0369a1 100%);padding:20px 24px;color:#ffffff;font-size:20px;font-weight:700;">{{site_name}}</td></tr>\n      <tr><td style="padding:24px;">\n        <h2 style="margin:0 0 14px 0;font-size:20px;color:#0f172a;">{{subject}}</h2>\n        {{content}}\n      </td></tr>\n      <tr><td style="padding:16px 24px;color:#64748b;font-size:12px;border-top:1px solid #e5e7eb;">This is an automated message from {{site_name}}.</td></tr>\n    </table>\n  </td></tr>\n</table>\n</body></html>',
    0
)
ON DUPLICATE KEY UPDATE setting_value = IF(setting_value IS NULL OR setting_value = '', VALUES(setting_value), setting_value);

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    token_hash  VARCHAR(64)  NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token   (token_hash),
    INDEX      idx_user   (user_id),
    INDEX      idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hashed backup codes for OTP (JSON array)
ALTER TABLE users ADD COLUMN IF NOT EXISTS backup_codes TEXT NULL COMMENT 'JSON array of hashed backup codes';

-- Company licenses
CREATE TABLE IF NOT EXISTS company_licenses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    company_id   INT          NOT NULL,
    valid_from   DATE         NOT NULL,
    valid_until  DATE         NOT NULL,
    device_limit INT          NOT NULL DEFAULT 10,
    status       ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
    notes        TEXT         NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_company    (company_id),
    INDEX idx_valid_until (valid_until),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kiosk license slot tracking
ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS license_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = occupies a license slot, 0 = deactivated/freed';
ALTER TABLE kiosks ADD COLUMN IF NOT EXISTS activated_at   DATETIME NULL COMMENT 'When device first activated/registered';
