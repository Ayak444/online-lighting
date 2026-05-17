SET NAMES utf8mb4;

CREATE TABLE lamp_service_periods (
    period_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NULL,
    service_year SMALLINT UNSIGNED NOT NULL,
    registration_open_at DATETIME NULL,
    blessing_start_date DATE NOT NULL,
    blessing_end_date DATE NOT NULL,
    reminder_days_before SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    status ENUM('draft', 'active', 'closed') NOT NULL DEFAULT 'draft',
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (period_id),
    UNIQUE KEY uq_lamp_service_periods_year (service_year),
    KEY idx_lamp_service_periods_status_dates (status, blessing_start_date, blessing_end_date),
    CONSTRAINT fk_lamp_service_periods_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN service_period_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN service_year SMALLINT UNSIGNED NULL AFTER service_period_id,
    ADD KEY idx_orders_service_period_id (service_period_id),
    ADD KEY idx_orders_service_year (service_year),
    ADD CONSTRAINT fk_orders_service_period
        FOREIGN KEY (service_period_id) REFERENCES lamp_service_periods (period_id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE scheduled_jobs
    ADD COLUMN subject VARCHAR(150) NULL AFTER target_channel,
    ADD COLUMN content TEXT NULL AFTER subject;

SET @step11_admin_user_id = (
    SELECT ur.user_id
    FROM user_roles ur
    INNER JOIN roles r ON r.role_id = ur.role_id
    WHERE r.role_name = 'admin'
    ORDER BY ur.user_id
    LIMIT 1
);

INSERT INTO lamp_service_periods (
    created_by,
    service_year,
    registration_open_at,
    blessing_start_date,
    blessing_end_date,
    reminder_days_before,
    status,
    notes
)
SELECT
    @step11_admin_user_id,
    YEAR(CURRENT_DATE()),
    STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE()) - 1, '-12-01 10:00:00'), '%Y-%m-%d %H:%i:%s'),
    CASE
        WHEN YEAR(CURRENT_DATE()) = 2026 THEN DATE('2026-02-16')
        ELSE STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE()), '-01-01'), '%Y-%m-%d')
    END,
    CASE
        WHEN YEAR(CURRENT_DATE()) = 2026 THEN DATE('2027-01-22')
        ELSE STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE()), '-12-31'), '%Y-%m-%d')
    END,
    30,
    'active',
    'default annual period'
WHERE NOT EXISTS (
    SELECT 1
    FROM lamp_service_periods
    WHERE status = 'active'
);
