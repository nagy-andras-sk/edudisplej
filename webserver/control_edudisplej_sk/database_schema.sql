-- EduDisplej Database Schema
-- Control Panel Database Tables

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `edudisplej_sk` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci;
USE `edudisplej_sk`;

-- Users table for authentication
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `isadmin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Kiosks table for display management
CREATE TABLE IF NOT EXISTS `kiosks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` text DEFAULT NULL,
  `installed` datetime NOT NULL DEFAULT current_timestamp(),
  `mac` text NOT NULL,
  `last_seen` timestamp NULL DEFAULT NULL,
  `hw_info` text DEFAULT NULL,
  `screenshot_url` text DEFAULT NULL,
  `screenshot_requested` tinyint(1) DEFAULT 0,
  `status` enum('online','offline','pending') DEFAULT 'pending',
  `company_id` int(11) DEFAULT NULL,
  `location` text DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `sync_interval` int(11) DEFAULT 300,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Companies table for multi-tenant support
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Kiosk groups for organization
CREATE TABLE IF NOT EXISTS `kiosk_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Kiosk to group assignments
CREATE TABLE IF NOT EXISTS `kiosk_group_assignments` (
  `kiosk_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`kiosk_id`, `group_id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Sync logs for debugging
CREATE TABLE IF NOT EXISTS `sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kiosk_id` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kiosk_id` (`kiosk_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Add foreign keys
ALTER TABLE `kiosks`
  ADD CONSTRAINT `kiosks_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL;

ALTER TABLE `kiosk_groups`
  ADD CONSTRAINT `kiosk_groups_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

ALTER TABLE `kiosk_group_assignments`
  ADD CONSTRAINT `kga_kiosk_fk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kga_group_fk` FOREIGN KEY (`group_id`) REFERENCES `kiosk_groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `sync_logs`
  ADD CONSTRAINT `sync_logs_kiosk_fk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`) ON DELETE SET NULL;

-- Insert default admin user (password: admin123 - hashed with password_hash)
-- Note: Change this password after first login!
INSERT INTO `users` (`username`, `password`, `email`, `isadmin`) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@edudisplej.sk', 1)
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Insert default company
INSERT INTO `companies` (`name`) 
VALUES ('Default Company')
ON DUPLICATE KEY UPDATE `name`=`name`;
