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
