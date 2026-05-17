SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
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

INSERT INTO user_notification_preferences (
    user_id,
    email_enabled,
    sms_enabled,
    line_enabled,
    system_enabled
)
SELECT
    u.user_id,
    1,
    0,
    0,
    1
FROM users u
LEFT JOIN user_notification_preferences unp ON unp.user_id = u.user_id
WHERE unp.user_id IS NULL;

CREATE TABLE IF NOT EXISTS notification_deliveries (
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
