ALTER TABLE lamp_positions
    MODIFY status ENUM('available', 'occupied', 'maintenance', 'retired') NOT NULL DEFAULT 'available';
