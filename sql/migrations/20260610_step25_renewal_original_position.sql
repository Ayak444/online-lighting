ALTER TABLE cart_items
    ADD COLUMN target_period_id BIGINT UNSIGNED NULL AFTER dependent_id,
    ADD COLUMN renewal_source_detail_id BIGINT UNSIGNED NULL AFTER target_period_id,
    ADD COLUMN preferred_position_id BIGINT UNSIGNED NULL AFTER renewal_source_detail_id,
    ADD KEY idx_cart_items_target_period (target_period_id),
    ADD KEY idx_cart_items_renewal_source (renewal_source_detail_id),
    ADD KEY idx_cart_items_preferred_position (preferred_position_id);

ALTER TABLE cart_items
    ADD CONSTRAINT fk_cart_items_target_period
        FOREIGN KEY (target_period_id) REFERENCES lamp_service_periods (period_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_cart_items_renewal_source
        FOREIGN KEY (renewal_source_detail_id) REFERENCES order_items (detail_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_cart_items_preferred_position
        FOREIGN KEY (preferred_position_id) REFERENCES lamp_positions (position_id)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE order_items
    ADD COLUMN renewal_source_detail_id BIGINT UNSIGNED NULL AFTER price_snapshot,
    ADD KEY idx_order_items_renewal_source (renewal_source_detail_id);

ALTER TABLE order_items
    ADD CONSTRAINT fk_order_items_renewal_source
        FOREIGN KEY (renewal_source_detail_id) REFERENCES order_items (detail_id)
        ON DELETE SET NULL ON UPDATE CASCADE;
