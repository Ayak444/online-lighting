SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS statistics;
DROP TABLE IF EXISTS notification_deliveries;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS user_notification_preferences;
DROP TABLE IF EXISTS scheduled_jobs;
DROP TABLE IF EXISTS feedbacks;
DROP TABLE IF EXISTS articles;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS lamp_service_periods;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS carts;
DROP TABLE IF EXISTS annual_flow_rules;
DROP TABLE IF EXISTS annual_flow_entries;
DROP TABLE IF EXISTS lamp_positions;
DROP TABLE IF EXISTS lantern_types;
DROP TABLE IF EXISTS dependents;
DROP TABLE IF EXISTS phone_login_codes;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS oauth_states;
DROP TABLE IF EXISTS auth_identities;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female', 'other', 'unspecified') NOT NULL DEFAULT 'unspecified',
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    birthday DATE NULL,
    birth_clock_time TIME NULL,
    lunar_birthday VARCHAR(50) NULL,
    zodiac VARCHAR(20) NULL,
    annual_reminder VARCHAR(255) NULL,
    auth_provider ENUM('local', 'google', 'line') NOT NULL DEFAULT 'local',
    provider_id VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_provider (auth_provider, provider_id),
    KEY idx_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    role_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_identities (
    identity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('google', 'line') NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    provider_email VARCHAR(255) NULL,
    display_name VARCHAR(150) NULL,
    profile_json TEXT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (identity_id),
    UNIQUE KEY uq_auth_identities_provider_user (provider, provider_user_id),
    UNIQUE KEY uq_auth_identities_user_provider (user_id, provider),
    CONSTRAINT fk_auth_identities_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_states (
    state_hash CHAR(64) NOT NULL,
    provider ENUM('google', 'line') NOT NULL,
    flow_mode ENUM('login', 'link') NOT NULL DEFAULT 'login',
    user_id BIGINT UNSIGNED NULL,
    redirect_path VARCHAR(255) NOT NULL DEFAULT 'profile.php',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (state_hash),
    KEY idx_oauth_states_provider_expires (provider, expires_at),
    KEY idx_oauth_states_user_id (user_id),
    CONSTRAINT fk_oauth_states_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE phone_login_codes (
    code_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(30) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requested_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code_id),
    KEY idx_phone_login_codes_phone_expiry (phone, expires_at, verified_at),
    KEY idx_phone_login_codes_user_id (user_id),
    CONSTRAINT fk_phone_login_codes_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reset_id),
    UNIQUE KEY uq_password_resets_token_hash (token_hash),
    KEY idx_password_resets_user_expires (user_id, expires_at, used_at),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_roles_role
        FOREIGN KEY (role_id) REFERENCES roles (role_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dependents (
    dependent_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    is_self_profile TINYINT(1) NULL DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    relationship VARCHAR(50) NULL,
    gender ENUM('male', 'female', 'other', 'unspecified') NOT NULL DEFAULT 'unspecified',
    phone VARCHAR(30) NULL,
    national_id VARCHAR(20) NULL,
    birthday DATE NULL,
    birth_clock_time TIME NULL,
    birth_time VARCHAR(50) NULL,
    lunar_birthday VARCHAR(50) NULL,
    zodiac VARCHAR(20) NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (dependent_id),
    UNIQUE KEY uq_dependents_user_self (user_id, is_self_profile),
    KEY idx_dependents_user_id (user_id),
    CONSTRAINT fk_dependents_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lantern_types (
    type_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    blessing_description TEXT NULL,
    recommended_for TEXT NULL,
    not_recommended_for TEXT NULL,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    image_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (type_id),
    UNIQUE KEY uq_lantern_types_slug (slug),
    KEY idx_lantern_types_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lamp_positions (
    position_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_id BIGINT UNSIGNED NOT NULL,
    position_code VARCHAR(50) NOT NULL,
    area VARCHAR(50) NULL,
    row_no INT NULL,
    col_no INT NULL,
    status ENUM('available', 'occupied', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    occupied_until DATE NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id),
    UNIQUE KEY uq_lamp_positions_code (position_code),
    KEY idx_lamp_positions_type_status (type_id, status),
    CONSTRAINT fk_lamp_positions_lantern_type
        FOREIGN KEY (type_id) REFERENCES lantern_types (type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE annual_flow_rules (
    rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_year SMALLINT UNSIGNED NOT NULL,
    type_id BIGINT UNSIGNED NOT NULL,
    rule_name VARCHAR(120) NOT NULL,
    match_field ENUM('always', 'zodiac', 'birth_time', 'lunar_birthday') NOT NULL DEFAULT 'always',
    match_values VARCHAR(255) NULL,
    recommendation_reason VARCHAR(255) NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_id),
    KEY idx_annual_flow_rules_year_active (service_year, is_active, priority),
    KEY idx_annual_flow_rules_type_id (type_id),
    CONSTRAINT fk_annual_flow_rules_lantern_type
        FOREIGN KEY (type_id) REFERENCES lantern_types (type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE annual_flow_entries (
    entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_year SMALLINT UNSIGNED NOT NULL,
    zodiac_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    zodiac_branch VARCHAR(10) NOT NULL,
    zodiac_animal VARCHAR(10) NOT NULL,
    zodiac_label VARCHAR(30) NOT NULL,
    flow_notice TEXT NOT NULL,
    appendix TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id),
    UNIQUE KEY uq_annual_flow_entries_year_zodiac (service_year, zodiac_animal),
    KEY idx_annual_flow_entries_year_active (service_year, is_active, zodiac_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE carts (
    cart_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cart_id),
    UNIQUE KEY uq_carts_user_id (user_id),
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
    cart_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id BIGINT UNSIGNED NOT NULL,
    type_id BIGINT UNSIGNED NOT NULL,
    dependent_id BIGINT UNSIGNED NOT NULL,
    target_period_id BIGINT UNSIGNED NULL,
    renewal_source_detail_id BIGINT UNSIGNED NULL,
    preferred_position_id BIGINT UNSIGNED NULL,
    prayer_wish VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cart_item_id),
    KEY idx_cart_items_cart_id (cart_id),
    KEY idx_cart_items_type_id (type_id),
    KEY idx_cart_items_dependent_id (dependent_id),
    KEY idx_cart_items_target_period (target_period_id),
    KEY idx_cart_items_renewal_source (renewal_source_detail_id),
    KEY idx_cart_items_preferred_position (preferred_position_id),
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts (cart_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cart_items_lantern_type
        FOREIGN KEY (type_id) REFERENCES lantern_types (type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cart_items_dependent
        FOREIGN KEY (dependent_id) REFERENCES dependents (dependent_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cart_items_target_period
        FOREIGN KEY (target_period_id) REFERENCES lamp_service_periods (period_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_cart_items_preferred_position
        FOREIGN KEY (preferred_position_id) REFERENCES lamp_positions (position_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(30) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    service_period_id BIGINT UNSIGNED NULL,
    service_year SMALLINT UNSIGNED NULL,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
    order_status ENUM('pending_payment', 'paid', 'assigned', 'completed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
    review_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    note VARCHAR(255) NULL,
    paid_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_orders_order_number (order_number),
    KEY idx_orders_user_id (user_id),
    KEY idx_orders_service_period_id (service_period_id),
    KEY idx_orders_service_year (service_year),
    KEY idx_orders_status (order_status, payment_status, review_status),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_orders_service_period
        FOREIGN KEY (service_period_id) REFERENCES lamp_service_periods (period_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    type_id BIGINT UNSIGNED NOT NULL,
    dependent_id BIGINT UNSIGNED NULL,
    position_id BIGINT UNSIGNED NULL,
    lantern_name_snapshot VARCHAR(100) NOT NULL,
    dependent_name_snapshot VARCHAR(100) NOT NULL,
    price_snapshot DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    renewal_source_detail_id BIGINT UNSIGNED NULL,
    blessing_start_date DATE NULL,
    blessing_end_date DATE NULL,
    assigned_at DATETIME NULL,
    item_status ENUM('pending', 'assigned', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (detail_id),
    KEY idx_order_items_order_id (order_id),
    KEY idx_order_items_type_id (type_id),
    KEY idx_order_items_dependent_id (dependent_id),
    KEY idx_order_items_position_id (position_id),
    KEY idx_order_items_renewal_source (renewal_source_detail_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_lantern_type
        FOREIGN KEY (type_id) REFERENCES lantern_types (type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_dependent
        FOREIGN KEY (dependent_id) REFERENCES dependents (dependent_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_position
        FOREIGN KEY (position_id) REFERENCES lamp_positions (position_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_renewal_source
        FOREIGN KEY (renewal_source_detail_id) REFERENCES order_items (detail_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lamp_reservations (
    reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_item_id BIGINT UNSIGNED NULL,
    order_item_id BIGINT UNSIGNED NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    status ENUM('active', 'expired', 'converted', 'cancelled') NOT NULL DEFAULT 'active',
    converted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_id),
    KEY idx_lamp_reservations_cart_status (cart_item_id, status, expires_at),
    KEY idx_lamp_reservations_order_item (order_item_id),
    KEY idx_lamp_reservations_position_status (position_id, status, expires_at),
    KEY idx_lamp_reservations_user_status (user_id, status, expires_at),
    CONSTRAINT fk_lamp_reservations_cart_item
        FOREIGN KEY (cart_item_id) REFERENCES cart_items (cart_item_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lamp_reservations_order_item
        FOREIGN KEY (order_item_id) REFERENCES order_items (detail_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lamp_reservations_position
        FOREIGN KEY (position_id) REFERENCES lamp_positions (position_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lamp_reservations_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    payment_method ENUM('bank_transfer', 'credit_card', 'mobile_payment', 'convenience_store', 'cash', 'mock') NOT NULL DEFAULT 'bank_transfer',
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('pending', 'confirmed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    transaction_no VARCHAR(100) NULL,
    payer_name VARCHAR(100) NULL,
    paid_at DATETIME NULL,
    raw_payload TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_payments_order_id (order_id),
    KEY idx_payments_status (payment_status),
    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    invoice_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    invoice_status ENUM('issued', 'voided') NOT NULL DEFAULT 'issued',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (invoice_id),
    UNIQUE KEY uq_invoices_order_id (order_id),
    UNIQUE KEY uq_invoices_invoice_number (invoice_number),
    CONSTRAINT fk_invoices_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE articles (
    article_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    author_id BIGINT UNSIGNED NULL,
    article_type ENUM('announcement', 'blog', 'temple_history', 'deity_intro', 'lantern_intro') NOT NULL DEFAULT 'announcement',
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (article_id),
    KEY idx_articles_author_id (author_id),
    KEY idx_articles_type_status (article_type, status),
    CONSTRAINT fk_articles_author
        FOREIGN KEY (author_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_jobs (
    job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NULL,
    job_name VARCHAR(100) NOT NULL,
    job_type ENUM('expiration_reminder', 'event_broadcast', 'custom') NOT NULL DEFAULT 'expiration_reminder',
    trigger_time DATETIME NULL,
    cron_expression VARCHAR(100) NULL,
    target_channel ENUM('email', 'sms', 'line', 'system') NOT NULL DEFAULT 'email',
    subject VARCHAR(150) NULL,
    content TEXT NULL,
    status ENUM('active', 'paused', 'finished') NOT NULL DEFAULT 'active',
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (job_id),
    KEY idx_scheduled_jobs_created_by (created_by),
    KEY idx_scheduled_jobs_status_next_run (status, next_run_at),
    CONSTRAINT fk_scheduled_jobs_created_by
        FOREIGN KEY (created_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_notification_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    email_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
    line_enabled TINYINT(1) NOT NULL DEFAULT 0,
    system_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_notification_preferences_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    notify_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NULL,
    channel ENUM('email', 'sms', 'line', 'system') NOT NULL DEFAULT 'email',
    subject VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'read') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (notify_id),
    KEY idx_notifications_user_id (user_id),
    KEY idx_notifications_job_id (job_id),
    KEY idx_notifications_order_id (order_id),
    KEY idx_notifications_invoice_id (invoice_id),
    KEY idx_notifications_status (status),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_job
        FOREIGN KEY (job_id) REFERENCES scheduled_jobs (job_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (invoice_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notify_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('email', 'sms', 'line', 'system') NOT NULL,
    recipient VARCHAR(255) NULL,
    provider VARCHAR(80) NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('sent', 'failed', 'skipped') NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    provider_response TEXT NULL,
    error_message VARCHAR(255) NULL,
    next_retry_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_notification_delivery_attempt (notify_id, attempt_no),
    KEY idx_notification_deliveries_notify_id (notify_id),
    KEY idx_notification_deliveries_status_retry (status, next_retry_at),
    CONSTRAINT fk_notification_deliveries_notification
        FOREIGN KEY (notify_id) REFERENCES notifications (notify_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedbacks (
    feedback_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    category ENUM('qa', 'bug', 'order', 'other') NOT NULL DEFAULT 'qa',
    subject VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('open', 'processing', 'closed') NOT NULL DEFAULT 'open',
    admin_reply TEXT NULL,
    replied_by BIGINT UNSIGNED NULL,
    replied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (feedback_id),
    KEY idx_feedbacks_user_id (user_id),
    KEY idx_feedbacks_status (status),
    KEY idx_feedbacks_replied_by (replied_by),
    CONSTRAINT fk_feedbacks_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_feedbacks_replied_by
        FOREIGN KEY (replied_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE statistics (
    statistic_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    generated_by BIGINT UNSIGNED NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_revenue DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    total_lantern_count INT UNSIGNED NOT NULL DEFAULT 0,
    count_by_type_json TEXT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (statistic_id),
    KEY idx_statistics_generated_by (generated_by),
    KEY idx_statistics_period (period_start, period_end),
    CONSTRAINT fk_statistics_generated_by
        FOREIGN KEY (generated_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    table_name VARCHAR(80) NULL,
    record_id VARCHAR(80) NULL,
    before_data TEXT NULL,
    after_data TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_audit_logs_user_id (user_id),
    KEY idx_audit_logs_table_record (table_name, record_id),
    KEY idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
