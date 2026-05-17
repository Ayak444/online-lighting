SET NAMES utf8mb4;

SET @flow_year = 2026;
SET @taisui_id = (SELECT type_id FROM lantern_types WHERE slug = 'taisui');
SET @guangming_id = (SELECT type_id FROM lantern_types WHERE slug = 'guangming');
SET @wenchang_id = (SELECT type_id FROM lantern_types WHERE slug = 'wenchang');
SET @caishen_id = (SELECT type_id FROM lantern_types WHERE slug = 'caishen');
SET @yaoshi_id = (SELECT type_id FROM lantern_types WHERE slug = 'yaoshi');

CREATE TABLE IF NOT EXISTS annual_flow_entries (
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

DELETE FROM annual_flow_rules
WHERE service_year <> @flow_year;

DELETE FROM annual_flow_rules
WHERE service_year = @flow_year
  AND rule_name LIKE 'FLOW_TABLE_%';

DELETE FROM annual_flow_entries
WHERE service_year <> @flow_year;

DELETE FROM annual_flow_entries
WHERE service_year = @flow_year;

INSERT INTO annual_flow_entries (
    service_year,
    zodiac_order,
    zodiac_branch,
    zodiac_animal,
    zodiac_label,
    flow_notice,
    appendix,
    is_active
) VALUES
(@flow_year, 1, '子', '鼠', '子肖鼠', '沖太歲，整體變動較大。工作、居住、人際都可能有轉換，宜冷靜規劃、保守理財，感情上要多溝通，健康留意心腦血管與泌尿系統。', '建議：安太歲、光明燈、文昌燈、藥師燈', 1),
(@flow_year, 2, '丑', '牛', '丑肖牛', '害太歲，容易遇到小人、人際疏離或團隊嫌隙。行事宜低調謹慎，避免擔保借貸，感情需建立信任，健康留意腸胃與皮膚。', '建議：安太歲、藥師燈、財神燈', 1),
(@flow_year, 3, '寅', '虎', '寅肖虎', '三合貴人，運勢有助力，適合把握新機會與合作案。事業可得支持，財運有進展，感情人緣佳；健康留意肝火與應酬過多。', '建議：光明燈、文昌燈、財神燈', 1),
(@flow_year, 4, '卯', '兔', '卯肖兔', '破太歲且桃花臨門，人緣變旺但也容易分心或有爛桃花。工作可借助人脈推進，財務以正財為主，健康留意眼睛與睡眠。', '建議：光明燈、文昌燈；來源另提月老姻緣燈、明心智慧燈', 1),
(@flow_year, 5, '辰', '龍', '辰肖龍', '才華有展現機會，整體穩中有升，但火土焦躁，容易心急出錯。工作要注意細節與溝通，財運以專業所得為主，健康留意燥熱上火。', '建議：光明燈、財神燈、藥師燈', 1),
(@flow_year, 6, '巳', '蛇', '巳肖蛇', '官印相生，事業與財運能見度高，有晉升掌權機會。收入可能增加但開銷也大，感情魅力提升，健康留意心臟負荷與熬夜。', '建議：光明燈、財神燈', 1),
(@flow_year, 7, '午', '馬', '午肖馬', '值太歲，本命年壓力與波動明顯。事業可開新局但忌衝動，財運有機會也有風險，感情易起伏，健康特別留意心腦血管與意外紅傷。', '建議：安太歲、光明燈、財神燈、藥師燈', 1),
(@flow_year, 8, '未', '羊', '未肖羊', '六合貴人，合作、人脈與貴人運佳。事業利簽約與團隊推進，財運可由合作而來，感情和諧，健康大致平順但仍需飲食均衡。', '建議：光明燈、文昌燈、財神燈；來源另提月老姻緣燈', 1),
(@flow_year, 9, '申', '猴', '申肖猴', '壓力中成長，工作任務與競爭變重，但也能提升能力。財運宜靠主業穩定累積，不宜投機；感情需耐心，健康留意精神壓力與呼吸系統。', '建議：光明燈、財神燈、藥師燈；來源另提明心智慧燈', 1),
(@flow_year, 10, '酉', '雞', '酉肖雞', '破太歲，人際與財務容易有破損或干擾。專案、合約、文件需反覆確認，感情上多忍讓，健康留意肺部、呼吸道與意外碰撞。', '建議：安太歲、藥師燈、財神燈；來源另提明心智慧燈', 1),
(@flow_year, 11, '戌', '狗', '戌肖狗', '三合貴人，事業與人脈順暢，利於市場拓展與個人影響力。財運較旺，感情社交活躍，健康留意火氣過旺、失眠與心悸。', '建議：光明燈、財神燈；來源另提月老姻緣燈', 1),
(@flow_year, 12, '亥', '豬', '亥肖豬', '暗藏財機，財運與事業有幕後機會或貴人相助，但不宜張揚。感情穩定發展，健康留意水火不平衡、腎臟泌尿與情緒調節。', '建議：光明燈、財神燈、藥師燈', 1);

CREATE TEMPORARY TABLE tmp_annual_flow_import (
    zodiac CHAR(1) NOT NULL,
    system_slugs VARCHAR(120) NOT NULL,
    PRIMARY KEY (zodiac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_annual_flow_import (zodiac, system_slugs) VALUES
('鼠', 'taisui,guangming,wenchang,yaoshi'),
('牛', 'taisui,caishen,yaoshi'),
('虎', 'guangming,wenchang,caishen'),
('兔', 'guangming,wenchang'),
('龍', 'guangming,caishen,yaoshi'),
('蛇', 'guangming,caishen'),
('馬', 'taisui,guangming,caishen,yaoshi'),
('羊', 'guangming,wenchang,caishen'),
('猴', 'guangming,caishen,yaoshi'),
('雞', 'taisui,caishen,yaoshi'),
('狗', 'guangming,caishen'),
('豬', 'guangming,caishen,yaoshi');

INSERT INTO annual_flow_rules (service_year, type_id, rule_name, match_field, match_values, recommendation_reason, priority, is_active)
SELECT @flow_year, @taisui_id, CONCAT('FLOW_TABLE_', @flow_year, '_', zodiac, '_taisui'), 'zodiac', zodiac, CONCAT('依 2026 丙午年流年表，生肖', zodiac, '建議優先考慮安太歲。'), 10, 1
FROM tmp_annual_flow_import
WHERE FIND_IN_SET('taisui', system_slugs) > 0;

INSERT INTO annual_flow_rules (service_year, type_id, rule_name, match_field, match_values, recommendation_reason, priority, is_active)
SELECT @flow_year, @guangming_id, CONCAT('FLOW_TABLE_', @flow_year, '_', zodiac, '_guangming'), 'zodiac', zodiac, CONCAT('依 2026 丙午年流年表，生肖', zodiac, '建議考慮聖母殿光明燈。'), 20, 1
FROM tmp_annual_flow_import
WHERE FIND_IN_SET('guangming', system_slugs) > 0;

INSERT INTO annual_flow_rules (service_year, type_id, rule_name, match_field, match_values, recommendation_reason, priority, is_active)
SELECT @flow_year, @wenchang_id, CONCAT('FLOW_TABLE_', @flow_year, '_', zodiac, '_wenchang'), 'zodiac', zodiac, CONCAT('依 2026 丙午年流年表，生肖', zodiac, '建議考慮文昌殿光明燈。'), 30, 1
FROM tmp_annual_flow_import
WHERE FIND_IN_SET('wenchang', system_slugs) > 0;

INSERT INTO annual_flow_rules (service_year, type_id, rule_name, match_field, match_values, recommendation_reason, priority, is_active)
SELECT @flow_year, @caishen_id, CONCAT('FLOW_TABLE_', @flow_year, '_', zodiac, '_caishen'), 'zodiac', zodiac, CONCAT('依 2026 丙午年流年表，生肖', zodiac, '建議考慮財神燈。'), 40, 1
FROM tmp_annual_flow_import
WHERE FIND_IN_SET('caishen', system_slugs) > 0;

INSERT INTO annual_flow_rules (service_year, type_id, rule_name, match_field, match_values, recommendation_reason, priority, is_active)
SELECT @flow_year, @yaoshi_id, CONCAT('FLOW_TABLE_', @flow_year, '_', zodiac, '_yaoshi'), 'zodiac', zodiac, CONCAT('依 2026 丙午年流年表，生肖', zodiac, '建議考慮藥師燈。'), 50, 1
FROM tmp_annual_flow_import
WHERE FIND_IN_SET('yaoshi', system_slugs) > 0;

DROP TEMPORARY TABLE IF EXISTS tmp_annual_flow_import;
