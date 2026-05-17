SET @guangming_id = (SELECT type_id FROM lantern_types WHERE slug = 'guangming' LIMIT 1);
SET @taisui_id = (SELECT type_id FROM lantern_types WHERE slug = 'taisui' LIMIT 1);
SET @wenchang_id = (SELECT type_id FROM lantern_types WHERE slug = 'wenchang' LIMIT 1);
SET @caishen_id = (SELECT type_id FROM lantern_types WHERE slug = 'caishen' LIMIT 1);
SET @yaoshi_id = (SELECT type_id FROM lantern_types WHERE slug = 'yaoshi' LIMIT 1);

UPDATE annual_flow_rules
SET is_active = 0
WHERE service_year = YEAR(CURRENT_DATE)
  AND rule_name NOT LIKE 'FLOW_TABLE_%'
  AND (
      (match_field = 'always' AND type_id = @guangming_id)
      OR (match_field = 'birth_time' AND type_id = @wenchang_id AND match_values LIKE '%申時%')
      OR (match_field = 'zodiac' AND type_id = @taisui_id AND priority = 10)
  );

DROP TEMPORARY TABLE IF EXISTS tmp_annual_flow_import;
CREATE TEMPORARY TABLE tmp_annual_flow_import (
    service_year SMALLINT UNSIGNED NOT NULL,
    zodiac CHAR(1) NOT NULL,
    system_slugs VARCHAR(120) NOT NULL,
    PRIMARY KEY (service_year, zodiac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_annual_flow_import (service_year, zodiac, system_slugs) VALUES
(2026, '鼠', 'taisui,guangming,wenchang,yaoshi'),
(2026, '牛', 'taisui,caishen,yaoshi'),
(2026, '虎', 'guangming,wenchang,caishen'),
(2026, '兔', 'guangming,wenchang'),
(2026, '龍', 'guangming,caishen,yaoshi'),
(2026, '蛇', 'guangming,caishen'),
(2026, '馬', 'taisui,guangming,caishen,yaoshi'),
(2026, '羊', 'guangming,wenchang,caishen'),
(2026, '猴', 'guangming,caishen,yaoshi'),
(2026, '雞', 'taisui,caishen,yaoshi'),
(2026, '狗', 'guangming,caishen'),
(2026, '豬', 'guangming,caishen,yaoshi'),
(2025, '鼠', 'guangming,caishen'),
(2025, '牛', 'taisui,guangming,caishen,yaoshi'),
(2025, '虎', 'taisui,guangming,caishen'),
(2025, '兔', 'guangming,wenchang,caishen'),
(2025, '龍', 'guangming,yaoshi'),
(2025, '蛇', 'taisui,guangming,wenchang,yaoshi'),
(2025, '馬', 'guangming,wenchang'),
(2025, '羊', 'guangming,wenchang'),
(2025, '猴', 'taisui,guangming'),
(2025, '雞', 'taisui,guangming,caishen'),
(2025, '狗', 'guangming'),
(2025, '豬', 'taisui,guangming,caishen,yaoshi'),
(2024, '鼠', 'caishen,yaoshi'),
(2024, '牛', 'taisui,guangming,yaoshi'),
(2024, '虎', 'guangming,caishen,yaoshi'),
(2024, '兔', 'taisui,caishen,yaoshi'),
(2024, '龍', 'taisui,guangming,caishen,yaoshi'),
(2024, '蛇', 'wenchang,caishen'),
(2024, '馬', 'guangming,wenchang,yaoshi'),
(2024, '羊', 'taisui,guangming'),
(2024, '猴', 'wenchang,caishen,yaoshi'),
(2024, '雞', 'taisui,guangming,caishen'),
(2024, '狗', 'taisui,guangming,caishen'),
(2024, '豬', 'guangming,wenchang,caishen'),
(2023, '鼠', 'taisui,guangming,yaoshi'),
(2023, '牛', 'guangming,wenchang,caishen'),
(2023, '虎', 'guangming,caishen'),
(2023, '兔', 'taisui,guangming,yaoshi'),
(2023, '龍', 'taisui,guangming,caishen,yaoshi'),
(2023, '蛇', 'guangming,caishen,yaoshi'),
(2023, '馬', 'taisui,guangming,wenchang'),
(2023, '羊', 'guangming,caishen'),
(2023, '猴', 'taisui,guangming,yaoshi'),
(2023, '雞', 'taisui,guangming'),
(2023, '狗', 'guangming,wenchang,yaoshi'),
(2023, '豬', 'taisui,guangming,caishen');

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
)
SELECT
    source_rows.service_year,
    @taisui_id,
    CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_taisui'),
    'zodiac',
    source_rows.zodiac,
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議優先考慮安太歲。'),
    10,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('taisui', source_rows.system_slugs) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM annual_flow_rules existing_rule
      WHERE existing_rule.rule_name = CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_taisui')
  );

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
)
SELECT
    source_rows.service_year,
    @guangming_id,
    CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_guangming'),
    'zodiac',
    source_rows.zodiac,
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮光明燈。'),
    20,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('guangming', source_rows.system_slugs) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM annual_flow_rules existing_rule
      WHERE existing_rule.rule_name = CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_guangming')
  );

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
)
SELECT
    source_rows.service_year,
    @wenchang_id,
    CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_wenchang'),
    'zodiac',
    source_rows.zodiac,
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮文昌燈。'),
    30,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('wenchang', source_rows.system_slugs) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM annual_flow_rules existing_rule
      WHERE existing_rule.rule_name = CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_wenchang')
  );

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
)
SELECT
    source_rows.service_year,
    @caishen_id,
    CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_caishen'),
    'zodiac',
    source_rows.zodiac,
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮財神燈。'),
    40,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('caishen', source_rows.system_slugs) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM annual_flow_rules existing_rule
      WHERE existing_rule.rule_name = CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_caishen')
  );

INSERT INTO annual_flow_rules (
    service_year,
    type_id,
    rule_name,
    match_field,
    match_values,
    recommendation_reason,
    priority,
    is_active
)
SELECT
    source_rows.service_year,
    @yaoshi_id,
    CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_yaoshi'),
    'zodiac',
    source_rows.zodiac,
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮藥師燈。'),
    50,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('yaoshi', source_rows.system_slugs) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM annual_flow_rules existing_rule
      WHERE existing_rule.rule_name = CONCAT('FLOW_TABLE_', source_rows.service_year, '_', source_rows.zodiac, '_yaoshi')
  );

DROP TEMPORARY TABLE IF EXISTS tmp_annual_flow_import;
