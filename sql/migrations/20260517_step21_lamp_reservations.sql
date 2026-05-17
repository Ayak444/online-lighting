SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lamp_reservations (
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

UPDATE lamp_reservations
SET status = 'expired'
WHERE status = 'active'
  AND expires_at <= NOW();
