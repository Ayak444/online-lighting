SET NAMES utf8mb4;

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

SET @step12_taisui_id = (SELECT type_id FROM lantern_types WHERE slug = 'taisui' LIMIT 1);
SET @step12_guangming_id = (SELECT type_id FROM lantern_types WHERE slug = 'guangming' LIMIT 1);
SET @step12_wenchang_id = (SELECT type_id FROM lantern_types WHERE slug = 'wenchang' LIMIT 1);

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
) VALUES
(
    YEAR(CURRENT_DATE()),
    @step12_taisui_id,
    'annual zodiac taisui recommendation',
    'zodiac',
    '馬,鼠,牛,兔,horse,rat,ox,rabbit',
    'The annual rule marks this birth sign for extra Taisui attention.',
    10,
    1
),
(
    YEAR(CURRENT_DATE()),
    @step12_guangming_id,
    'annual baseline renewal recommendation',
    'always',
    NULL,
    'Keep Guangming Lantern as the baseline renewal suggestion for the active service year.',
    30,
    1
),
(
    YEAR(CURRENT_DATE()),
    @step12_wenchang_id,
    'birth hour Wenchang example',
    'birth_time',
    '申時,shen hour',
    'Example rule: admins can add special birth-hour recommendations when the yearly flow table requires them.',
    60,
    1
);
