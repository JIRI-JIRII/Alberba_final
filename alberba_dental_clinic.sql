-- =====================================================================
-- ALBERBA DENTAL CLINIC - Online Appointment System Database
-- =====================================================================

DROP DATABASE IF EXISTS alberba_dental_clinic;
CREATE DATABASE alberba_dental_clinic CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE alberba_dental_clinic;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. ROLES & ACCESS CONTROL
-- =====================================================================

CREATE TABLE roles (
    role_id     INT AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (role_name) VALUES
('Administrator'), ('Dentist'), ('Receptionist'), ('Patient');

CREATE TABLE modules (
    module_id    INT AUTO_INCREMENT PRIMARY KEY,
    module_name  VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO modules (module_name) VALUES
('Dashboard'),
('Appointment Management'),
('Patient Management'),
('Schedule/Calendar'),
('Reports and Analytics'),
('Payments/Billing'),
('Maintenance/User Management');

-- Default module access per role (admin can override per-employee below)
CREATE TABLE role_module_access (
    role_id    INT NOT NULL,
    module_id  INT NOT NULL,
    PRIMARY KEY (role_id, module_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Administrator = full access
INSERT INTO role_module_access (role_id, module_id)
SELECT (SELECT role_id FROM roles WHERE role_name = 'Administrator'), module_id FROM modules;

-- Dentist default = Dashboard, Appointment Management, Patient Management, Schedule
INSERT INTO role_module_access (role_id, module_id)
SELECT (SELECT role_id FROM roles WHERE role_name = 'Dentist'), module_id
FROM modules WHERE module_name IN ('Dashboard','Appointment Management','Patient Management','Schedule/Calendar');

-- Receptionist default = Dashboard, Appointment Management, Patient Management, Schedule, Payments/Billing
INSERT INTO role_module_access (role_id, module_id)
SELECT (SELECT role_id FROM roles WHERE role_name = 'Receptionist'), module_id
FROM modules WHERE module_name IN ('Dashboard','Appointment Management','Patient Management','Schedule/Calendar','Payments/Billing');

-- Patient default = Dashboard, Appointment Management (own records only, enforced in app layer)
INSERT INTO role_module_access (role_id, module_id)
SELECT (SELECT role_id FROM roles WHERE role_name = 'Patient'), module_id
FROM modules WHERE module_name IN ('Dashboard','Appointment Management');

-- =====================================================================
-- 2. USERS (login/auth table shared by all account types)
-- =====================================================================

CREATE TABLE users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    email          VARCHAR(100) NOT NULL UNIQUE,
    role_id        INT NOT NULL,
    account_status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE employee_module_access (
    employee_id  INT NOT NULL,
    module_id    INT NOT NULL,
    granted_by   INT NOT NULL,
    granted_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, module_id)
) ENGINE=InnoDB;
-- (foreign keys for employee_module_access added after `employees` table exists)

-- =====================================================================
-- 3. EMPLOYEES (Dentist / Receptionist profiles) & PATIENTS
-- =====================================================================

CREATE TABLE employees (
    employee_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    first_name      VARCHAR(50) NOT NULL,
    last_name       VARCHAR(50) NOT NULL,
    contact_number  VARCHAR(20),
    address         VARCHAR(255),
    specialization  VARCHAR(100) NULL,   -- used for dentists only
    hire_date       DATE,
    account_status  ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by      INT NOT NULL,        -- admin user_id who created this account
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

ALTER TABLE employee_module_access
    ADD FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    ADD FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    ADD FOREIGN KEY (granted_by) REFERENCES users(user_id);

CREATE TABLE patients (
    patient_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    first_name      VARCHAR(50) NOT NULL,
    last_name       VARCHAR(50) NOT NULL,
    birthdate       DATE,
    contact_number  VARCHAR(20),
    address         VARCHAR(255),
    registered_via  ENUM('online','walk-in') NOT NULL DEFAULT 'online',
    created_by      INT NULL,   -- receptionist user_id, if registered as walk-in
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE INDEX idx_patient_name ON patients (last_name, first_name);

-- Notification triggered whenever admin edits an employee account ("Request Change")
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    message          VARCHAR(255) NOT NULL,
    is_read          TINYINT(1) NOT NULL DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit trail of what the admin changed on an employee account
CREATE TABLE account_change_logs (
    log_id         INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT NOT NULL,
    changed_by     INT NOT NULL,
    field_changed  VARCHAR(50) NOT NULL,
    old_value      VARCHAR(255),
    new_value      VARCHAR(255),
    changed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Historical log of archived patient records
CREATE TABLE patient_record_archives (
    archive_id       INT AUTO_INCREMENT PRIMARY KEY,
    patient_id       INT NOT NULL,
    snapshot_data    JSON NOT NULL,   -- full patient record snapshot at time of archiving
    archived_reason  VARCHAR(150),
    archived_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. SERVICES (Manage Services / Recently Deleted / Services Archives)
-- =====================================================================

CREATE TABLE services (
    service_id        INT AUTO_INCREMENT PRIMARY KEY,
    service_name       VARCHAR(100) NOT NULL,
    description         TEXT,
    category            VARCHAR(50),
    duration_minutes    INT NOT NULL DEFAULT 30,
    price                DECIMAL(10,2) NOT NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at           DATETIME NULL,   -- soft delete timestamp; sits in "Recently Deleted" for 30 days
    created_by           INT NOT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Permanent historical log once a soft-deleted service is purged (30 days) or removed by admin
CREATE TABLE services_archive (
    archive_id           INT AUTO_INCREMENT PRIMARY KEY,
    original_service_id  INT NOT NULL,
    service_name         VARCHAR(100) NOT NULL,
    description          TEXT,
    category             VARCHAR(50),
    duration_minutes     INT,
    price                DECIMAL(10,2),
    deleted_at           DATETIME,
    archived_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Convenience view: services currently sitting in the Recently Deleted bin
CREATE VIEW vw_recently_deleted_services AS
SELECT service_id, service_name, category, price, deleted_at,
       DATEDIFF(deleted_at + INTERVAL 30 DAY, NOW()) AS days_left_before_purge
FROM services
WHERE deleted_at IS NOT NULL
  AND deleted_at > NOW() - INTERVAL 30 DAY;

-- =====================================================================
-- 5. CLINIC HOURS, CLOSURES & DENTIST SCHEDULES (Calendar module)
-- =====================================================================

CREATE TABLE clinic_hours (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week  TINYINT NOT NULL,   -- 0 = Sunday ... 6 = Saturday
    open_time    TIME,
    close_time   TIME,
    is_closed    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE (day_of_week)
) ENGINE=InnoDB;

INSERT INTO clinic_hours (day_of_week, open_time, close_time, is_closed) VALUES
(0, '07:00:00', '18:00:00', 0),          
(1, '07:00:00', '18:00:00', 0),
(2, '07:00:00', '18:00:00', 0),
(3, '07:00:00', '18:00:00', 0),
(4, '07:00:00', '18:00:00', 0),
(5, '07:00:00', '18:00:00', 0),
(6, '07:00:00', '18:00:00', 0);

-- One-off closures (holidays, etc.) -> shown as Gray on the calendar
CREATE TABLE clinic_closures (
    closure_id    INT AUTO_INCREMENT PRIMARY KEY,
    closure_date  DATE NOT NULL,
    reason        VARCHAR(255),
    UNIQUE (closure_date)
) ENGINE=InnoDB;

-- Recurring weekly working hours per dentist
CREATE TABLE dentist_schedules (
    schedule_id  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT NOT NULL,
    day_of_week  TINYINT NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 6. APPOINTMENTS
-- =====================================================================

CREATE TABLE appointments (
    appointment_id    INT AUTO_INCREMENT PRIMARY KEY,
    patient_id        INT NOT NULL,
    dentist_id        INT NOT NULL,          -- employees.employee_id (role = Dentist)
    service_id        INT NOT NULL,
    appointment_date  DATE NOT NULL,
    start_time        TIME NOT NULL,
    end_time          TIME NOT NULL,
    appointment_type  ENUM('online','walk-in') NOT NULL DEFAULT 'online',
    status             ENUM('pending','confirmed','pending_operation','completed','cancelled')
                        NOT NULL DEFAULT 'pending',
    booked_by         INT NOT NULL,          -- user_id: patient (self) or receptionist (walk-in)
    confirmed_by      INT NULL,              -- receptionist user_id
    cancel_reason     VARCHAR(255) NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id),
    FOREIGN KEY (dentist_id) REFERENCES employees(employee_id),
    FOREIGN KEY (service_id) REFERENCES services(service_id),
    FOREIGN KEY (booked_by) REFERENCES users(user_id),
    FOREIGN KEY (confirmed_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE INDEX idx_appt_date ON appointments (appointment_date);
CREATE INDEX idx_appt_dentist_date ON appointments (dentist_id, appointment_date);
CREATE INDEX idx_appt_status ON appointments (status);

-- =====================================================================
-- 7. BILLING (manual payment only -- no online payment gateway)
-- =====================================================================

CREATE TABLE billing (
    billing_id         INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id     INT NOT NULL UNIQUE,
    patient_id         INT NOT NULL,
    dentist_id         INT NOT NULL,
    service_id         INT NOT NULL,
    amount             DECIMAL(10,2) NOT NULL,
    reference_number   VARCHAR(50) NOT NULL UNIQUE,  -- manual transaction reference recorded by receptionist
    payment_status     ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    billing_date       DATE NOT NULL,
    processed_by       INT NOT NULL,   -- receptionist user_id
    paid_at            TIMESTAMP NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id),
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id),
    FOREIGN KEY (dentist_id) REFERENCES employees(employee_id),
    FOREIGN KEY (service_id) REFERENCES services(service_id),
    FOREIGN KEY (processed_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 8. LOGIN THROTTLING
-- Failed login attempts, used by func/login_throttle.php to lock out
-- brute-force guessing. Deliberately has no foreign key to users: we
-- also want to record attempts against usernames that don't exist.
-- =====================================================================

CREATE TABLE login_attempts (
    attempt_id   INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,   -- 45 chars fits a full IPv6 address
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_login_attempts_ip_time ON login_attempts (ip_address, attempted_at);
CREATE INDEX idx_login_attempts_user    ON login_attempts (username, ip_address);

CREATE INDEX idx_billing_date ON billing (billing_date);
CREATE INDEX idx_billing_status ON billing (payment_status);

-- =====================================================================
-- 8. REPORTING VIEWS (Sales / Services / Patient reports)
-- =====================================================================

CREATE VIEW vw_sales_report AS
SELECT
    DATE_FORMAT(b.billing_date, '%Y-%m') AS report_month,
    COUNT(b.billing_id)                  AS total_transactions,
    SUM(b.amount)                        AS total_revenue
FROM billing b
JOIN appointments a ON b.appointment_id = a.appointment_id
WHERE a.status = 'completed' AND b.payment_status = 'paid'
GROUP BY DATE_FORMAT(b.billing_date, '%Y-%m')
ORDER BY report_month DESC;

-- Services Report: ranked breakdown of most/least booked services
CREATE VIEW vw_services_report AS
SELECT
    s.service_id,
    s.service_name,
    s.category,
    COUNT(a.appointment_id) AS times_booked,
    SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS times_completed
FROM services s
LEFT JOIN appointments a ON a.service_id = s.service_id
GROUP BY s.service_id, s.service_name, s.category
ORDER BY times_booked DESC;

-- Patient Report: appointment list, defaults to the past 30 days
CREATE VIEW vw_patient_report AS
SELECT
    a.appointment_id,
    p.patient_id,
    CONCAT(p.first_name, ' ', p.last_name)   AS patient_name,
    CONCAT(e.first_name, ' ', e.last_name)   AS dentist_name,
    s.service_name,
    a.appointment_date,
    a.start_time,
    a.status
FROM appointments a
JOIN patients p  ON a.patient_id = p.patient_id
JOIN employees e ON a.dentist_id = e.employee_id
JOIN services s  ON a.service_id = s.service_id
WHERE a.appointment_date >= CURDATE() - INTERVAL 30 DAY
ORDER BY a.appointment_date DESC, a.start_time DESC;

-- =====================================================================
-- 9. AUTOMATED HOUSEKEEPING (requires MySQL Event Scheduler = ON)
--    In phpMyAdmin: run  SET GLOBAL event_scheduler = ON;
-- =====================================================================

DELIMITER $$

CREATE EVENT IF NOT EXISTS ev_purge_deleted_services
ON SCHEDULE EVERY 1 DAY
DO
BEGIN
    INSERT INTO services_archive
        (original_service_id, service_name, description, category, duration_minutes, price, deleted_at)
    SELECT service_id, service_name, description, category, duration_minutes, price, deleted_at
    FROM services
    WHERE deleted_at IS NOT NULL AND deleted_at <= NOW() - INTERVAL 30 DAY;

    DELETE FROM services
    WHERE deleted_at IS NOT NULL AND deleted_at <= NOW() - INTERVAL 30 DAY;
END$$

DELIMITER ;

-- =====================================================================
-- 10. SEED DATA (default admin + sample services)
-- =====================================================================

INSERT INTO users (username, password_hash, email, role_id, account_status)
VALUES ('admin', 'CHANGE_ME_HASH', 'admin@alberbadental.com',
        (SELECT role_id FROM roles WHERE role_name = 'Administrator'), 'active');

-- Sample services
INSERT INTO services (service_name, description, category, duration_minutes, price, created_by)
VALUES
('Oral Prophylaxis (Cleaning)', 'Routine teeth cleaning and polishing.', 'Preventive', 45, 800.00,
    (SELECT user_id FROM users WHERE username = 'admin')),
('Tooth Extraction', 'Simple tooth extraction procedure.', 'Surgical', 30, 1500.00,
    (SELECT user_id FROM users WHERE username = 'admin')),
('Orthodontic Consultation', 'Initial braces consultation and assessment.', 'Orthodontics', 30, 500.00,
    (SELECT user_id FROM users WHERE username = 'admin')),
('Teeth Whitening', 'In-clinic professional teeth whitening.', 'Cosmetic', 60, 3500.00,
    (SELECT user_id FROM users WHERE username = 'admin'));

SET FOREIGN_KEY_CHECKS = 1;

UPDATE users
SET password_hash = '$2b$10$9GmBkOsTYEnekFQD6zXMMO7OFQ0qIt/MDNg3QwUe/ywKwoKYeRFOy'
WHERE username = 'admin';