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

INSERT INTO auth_identities (
    user_id,
    provider,
    provider_user_id,
    provider_email,
    display_name,
    profile_json,
    last_login_at
)
SELECT
    user_id,
    auth_provider,
    provider_id,
    email,
    name,
    NULL,
    NULL
FROM users
WHERE auth_provider IN ('google', 'line')
  AND provider_id IS NOT NULL
  AND provider_id <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM auth_identities existing_identity
      WHERE existing_identity.provider = users.auth_provider
        AND existing_identity.provider_user_id = users.provider_id
  );
