SET NAMES utf8mb4;

UPDATE lamp_service_periods
SET blessing_start_date = '2026-02-16',
    blessing_end_date = '2027-01-22',
    notes = '2026 年度依龍山寺慣例：除夕開燈，農曆十二月十五謝燈。'
WHERE service_year = 2026;

UPDATE order_items oi
INNER JOIN orders o ON o.order_id = oi.order_id
SET oi.blessing_start_date = CASE
        WHEN o.payment_status = 'paid' OR oi.item_status IN ('assigned', 'completed')
            THEN DATE(COALESCE(o.paid_at, oi.assigned_at, o.created_at))
        ELSE NULL
    END,
    oi.blessing_end_date = '2027-01-22'
WHERE o.service_year = 2026;

UPDATE lamp_positions lp
INNER JOIN order_items oi ON oi.position_id = lp.position_id
INNER JOIN orders o ON o.order_id = oi.order_id
SET lp.occupied_until = oi.blessing_end_date
WHERE o.service_year = 2026
  AND oi.blessing_end_date IS NOT NULL;

UPDATE notifications
SET content = REPLACE(content, '2026-12-31', '2027-01-22')
WHERE content LIKE '%2026-12-31%';
