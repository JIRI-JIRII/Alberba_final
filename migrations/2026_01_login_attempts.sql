-- =====================================================================
-- Login throttling support
-- Run once against an existing alberba_dental_clinic database:
--     mysql -u root alberba_dental_clinic < migrations/2026_01_login_attempts.sql
--
-- Records failed login attempts so login.php can lock out brute-force
-- guessing. Rows older than a day are cleaned up opportunistically by
-- func/login_throttle.php.
-- =====================================================================

USE alberba_dental_clinic;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,   -- 45 chars fits a full IPv6 address
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- The throttle query filters on ip_address + attempted_at on every login POST.
CREATE INDEX idx_login_attempts_ip_time ON login_attempts (ip_address, attempted_at);
CREATE INDEX idx_login_attempts_user    ON login_attempts (username, ip_address);
