-- Migration 001: Phase 1 Security Hardening
-- Date: 2026-11-18
-- Author: Principal PHP/MySQL Engineer

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Add must_change_password column to users table if not exists
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role', 
    'SELECT "Column must_change_password already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create login_attempts table for database-backed brute force protection
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    locked_until DATETIME NULL,
    last_attempt_at DATETIME NOT NULL,
    INDEX idx_ip_username (ip_address, username),
    INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record migration version
INSERT IGNORE INTO schema_migrations (version) VALUES ('001_phase1_security_hardening');

-- Rollback Notes:
-- ALTER TABLE users DROP COLUMN must_change_password;
-- DROP TABLE IF EXISTS login_attempts;
-- DELETE FROM schema_migrations WHERE version = '001_phase1_security_hardening';
