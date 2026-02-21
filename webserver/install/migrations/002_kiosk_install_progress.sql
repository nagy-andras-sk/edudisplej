-- Migration 002: Kiosk install progress tracking
-- Run once on existing schema.

CREATE TABLE IF NOT EXISTS kiosk_install_progress (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    kiosk_id    INT NOT NULL,
    company_id  INT NOT NULL,
    phase       VARCHAR(64) NOT NULL DEFAULT 'unknown',
    step        INT NOT NULL DEFAULT 0,
    total       INT NOT NULL DEFAULT 0,
    percent     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    state       ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    message     TEXT NULL,
    eta_seconds INT NULL,
    payload_json LONGTEXT NULL,
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kiosk_progress (kiosk_id),
    INDEX idx_company_state (company_id, state),
    INDEX idx_reported_at (reported_at),
    CONSTRAINT fk_kiosk_install_progress_kiosk
        FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
