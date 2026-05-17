SET NAMES utf8mb4;

INSERT INTO roles (role_name, display_name) VALUES
('member', '一般會員'),
('staff', '工作人員'),
('admin', '系統管理員');

-- Default admin login for development:
-- email: admin@example.com
-- password: password
INSERT INTO users (
    email,
    password_hash,
    name,
    gender,
    phone,
    is_active
) VALUES (
    'admin@example.com',
    '$2y$10$ntOZ9NCI/D3X5E/xPjNXcOYfndGyNHgLNVs24UVJ0opIrkYtoCOMu',
    '系統管理員',
    'unspecified',
    '0900000000',
    1
);

SET @admin_user_id = LAST_INSERT_ID();
SET @member_role_id = (SELECT role_id FROM roles WHERE role_name = 'member');
SET @admin_role_id = (SELECT role_id FROM roles WHERE role_name = 'admin');

INSERT INTO user_roles (user_id, role_id) VALUES
(@admin_user_id, @member_role_id),
(@admin_user_id, @admin_role_id);

INSERT INTO dependents (
    user_id,
    is_self_profile,
    name,
    relationship,
    gender,
    phone,
    note
) VALUES (
    @admin_user_id,
    1,
    '系統管理員',
    '本人',
    'unspecified',
    '0900000000',
    '由會員資料同步'
);

INSERT INTO lamp_service_periods (
    created_by,
    service_year,
    registration_open_at,
    blessing_start_date,
    blessing_end_date,
    reminder_days_before,
    status,
    notes
) VALUES (
    @admin_user_id,
    YEAR(CURRENT_DATE),
    STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE) - 1, '-12-01 10:00:00'), '%Y-%m-%d %H:%i:%s'),
    CASE
        WHEN YEAR(CURRENT_DATE) = 2026 THEN DATE('2026-02-16')
        ELSE STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE), '-01-01'), '%Y-%m-%d')
    END,
    CASE
        WHEN YEAR(CURRENT_DATE) = 2026 THEN DATE('2027-01-22')
        ELSE STR_TO_DATE(CONCAT(YEAR(CURRENT_DATE), '-12-31'), '%Y-%m-%d')
    END,
    30,
    'active',
    '預設年度燈期，後台可依當年度廟方公告調整。'
);

INSERT INTO lantern_types (
    slug,
    name,
    description,
    blessing_description,
    recommended_for,
    not_recommended_for,
    price,
    image_path,
    sort_order
) VALUES
(
    'guangming',
    '聖母殿光明燈',
    '安奉於聖母殿，祈求元辰光彩、事業順利，適合一般年度祈福。',
    '依龍山寺點燈說明，聖母殿光明燈祈願元辰光彩、事業順利。',
    '一般信眾、上班族、祈求整體運勢與事業順利者。',
    NULL,
    600.00,
    'assets/images/lantern-guangming.png',
    1
),
(
    'taisui',
    '安太歲',
    '安奉於太歲廳，祈求流年平順、化解太歲沖煞。',
    '依龍山寺點燈說明，安太歲祈願流年平順、消災解厄。',
    '當年度犯太歲、沖太歲，或希望化解流年不順者。',
    NULL,
    600.00,
    'assets/images/lantern-taisui.png',
    6
),
(
    'wenchang',
    '文昌殿光明燈',
    '安奉於文昌殿，祈求智慧增長、功名及第、學業進步。',
    '依龍山寺點燈說明，文昌殿光明燈祈願智慧增長、功名及第。',
    '學生、考生、準備證照、公職考試或希望工作升遷順利者。',
    NULL,
    600.00,
    'assets/images/lantern-wenchang.png',
    2
),
(
    'caishen',
    '財神燈',
    '安奉於關聖帝君殿，祈求財源廣進、生意興隆。',
    '依龍山寺點燈說明，財神燈祈願財源廣進、生意興隆。',
    '經商、創業、業務工作、投資理財或祈求財運順利者。',
    NULL,
    600.00,
    'assets/images/lantern-caishen.png',
    4
),
(
    'yaoshi',
    '藥師燈',
    '安奉於華佗廳、文昌殿，祈求元辰光彩、消災延壽、身體健康。',
    '依龍山寺點燈說明，藥師燈祈願元辰光彩、消災延壽。',
    '為自己、長輩或親友祈求身體康寧、遠離病痛者。',
    NULL,
    600.00,
    'assets/images/lantern-yaoshi.png',
    3
),
(
    'jixiang',
    '吉祥燈',
    '安奉於關聖帝君殿，祈求圓滿吉祥、常保安康。',
    '依龍山寺點燈說明，吉祥燈祈願圓滿吉祥、常保安康。',
    '祈求生活平順、家庭和樂、常保安康、萬事吉祥者。',
    NULL,
    600.00,
    'assets/images/lantern-jixiang.png',
    5
),
(
    'pingan',
    '平安燈',
    '懸掛於寺內外，祈求闔家平安、吉祥納福；通常以戶為單位報名。',
    '依龍山寺點燈說明，平安燈祈願闔家平安、吉祥納福。',
    '為家庭、同住家人或全戶祈求出入平安者。',
    NULL,
    600.00,
    'assets/images/lantern-pingan.png',
    7
);

SET @guangming_id = (SELECT type_id FROM lantern_types WHERE slug = 'guangming');
SET @taisui_id = (SELECT type_id FROM lantern_types WHERE slug = 'taisui');
SET @wenchang_id = (SELECT type_id FROM lantern_types WHERE slug = 'wenchang');
SET @caishen_id = (SELECT type_id FROM lantern_types WHERE slug = 'caishen');
SET @yaoshi_id = (SELECT type_id FROM lantern_types WHERE slug = 'yaoshi');
SET @jixiang_id = (SELECT type_id FROM lantern_types WHERE slug = 'jixiang');
SET @pingan_id = (SELECT type_id FROM lantern_types WHERE slug = 'pingan');

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
    YEAR(CURRENT_DATE),
    @taisui_id,
    '年度犯太歲生肖建議',
    'zodiac',
    '馬,鼠,牛,兔',
    '依本年度流年表，該生肖屬需優先留意安太歲。',
    10,
    1
),
(
    YEAR(CURRENT_DATE),
    @guangming_id,
    '年度基礎補運建議',
    'always',
    NULL,
    '年度續燈時可優先保留光明燈，祈求元辰光彩、補運助氣。',
    30,
    1
),
(
    YEAR(CURRENT_DATE),
    @wenchang_id,
    '申時文昌加強建議',
    'birth_time',
    '申時',
    '示範規則：若廟方流年表需要依出生時辰追加建議，可由後台維護。',
    60,
    1
);

UPDATE annual_flow_rules
SET is_active = 0
WHERE service_year = YEAR(CURRENT_DATE)
  AND rule_name NOT LIKE 'FLOW_TABLE_%'
  AND (
      (match_field = 'always' AND type_id = @guangming_id)
      OR (match_field = 'birth_time' AND type_id = @wenchang_id AND match_values LIKE '%申時%')
      OR (match_field = 'zodiac' AND type_id = @taisui_id AND priority = 10)
  );

DELETE FROM annual_flow_rules
WHERE service_year <> 2026;

DELETE FROM annual_flow_rules
WHERE service_year = 2026
  AND rule_name LIKE 'FLOW_TABLE_%';

DELETE FROM annual_flow_entries
WHERE service_year <> 2026;

DELETE FROM annual_flow_entries
WHERE service_year = 2026;

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
(2026, 1, '子', '鼠', '子肖鼠', '沖太歲，整體變動較大。工作、居住、人際都可能有轉換，宜冷靜規劃、保守理財，感情上要多溝通，健康留意心腦血管與泌尿系統。', '建議：安太歲、光明燈、文昌燈、藥師燈', 1),
(2026, 2, '丑', '牛', '丑肖牛', '害太歲，容易遇到小人、人際疏離或團隊嫌隙。行事宜低調謹慎，避免擔保借貸，感情需建立信任，健康留意腸胃與皮膚。', '建議：安太歲、藥師燈、財神燈', 1),
(2026, 3, '寅', '虎', '寅肖虎', '三合貴人，運勢有助力，適合把握新機會與合作案。事業可得支持，財運有進展，感情人緣佳；健康留意肝火與應酬過多。', '建議：光明燈、文昌燈、財神燈', 1),
(2026, 4, '卯', '兔', '卯肖兔', '破太歲且桃花臨門，人緣變旺但也容易分心或有爛桃花。工作可借助人脈推進，財務以正財為主，健康留意眼睛與睡眠。', '建議：光明燈、文昌燈；來源另提月老姻緣燈、明心智慧燈', 1),
(2026, 5, '辰', '龍', '辰肖龍', '才華有展現機會，整體穩中有升，但火土焦躁，容易心急出錯。工作要注意細節與溝通，財運以專業所得為主，健康留意燥熱上火。', '建議：光明燈、財神燈、藥師燈', 1),
(2026, 6, '巳', '蛇', '巳肖蛇', '官印相生，事業與財運能見度高，有晉升掌權機會。收入可能增加但開銷也大，感情魅力提升，健康留意心臟負荷與熬夜。', '建議：光明燈、財神燈', 1),
(2026, 7, '午', '馬', '午肖馬', '值太歲，本命年壓力與波動明顯。事業可開新局但忌衝動，財運有機會也有風險，感情易起伏，健康特別留意心腦血管與意外紅傷。', '建議：安太歲、光明燈、財神燈、藥師燈', 1),
(2026, 8, '未', '羊', '未肖羊', '六合貴人，合作、人脈與貴人運佳。事業利簽約與團隊推進，財運可由合作而來，感情和諧，健康大致平順但仍需飲食均衡。', '建議：光明燈、文昌燈、財神燈；來源另提月老姻緣燈', 1),
(2026, 9, '申', '猴', '申肖猴', '壓力中成長，工作任務與競爭變重，但也能提升能力。財運宜靠主業穩定累積，不宜投機；感情需耐心，健康留意精神壓力與呼吸系統。', '建議：光明燈、財神燈、藥師燈；來源另提明心智慧燈', 1),
(2026, 10, '酉', '雞', '酉肖雞', '破太歲，人際與財務容易有破損或干擾。專案、合約、文件需反覆確認，感情上多忍讓，健康留意肺部、呼吸道與意外碰撞。', '建議：安太歲、藥師燈、財神燈；來源另提明心智慧燈', 1),
(2026, 11, '戌', '狗', '戌肖狗', '三合貴人，事業與人脈順暢，利於市場拓展與個人影響力。財運較旺，感情社交活躍，健康留意火氣過旺、失眠與心悸。', '建議：光明燈、財神燈；來源另提月老姻緣燈', 1),
(2026, 12, '亥', '豬', '亥肖豬', '暗藏財機，財運與事業有幕後機會或貴人相助，但不宜張揚。感情穩定發展，健康留意水火不平衡、腎臟泌尿與情緒調節。', '建議：光明燈、財神燈、藥師燈', 1);

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
(2026, '豬', 'guangming,caishen,yaoshi');

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
WHERE FIND_IN_SET('taisui', source_rows.system_slugs) > 0;

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
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮聖母殿光明燈。'),
    20,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('guangming', source_rows.system_slugs) > 0;

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
    CONCAT('依 ', source_rows.service_year, ' 年流年表，生肖', source_rows.zodiac, '建議考慮文昌殿光明燈。'),
    30,
    1
FROM tmp_annual_flow_import source_rows
WHERE FIND_IN_SET('wenchang', source_rows.system_slugs) > 0;

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
WHERE FIND_IN_SET('caishen', source_rows.system_slugs) > 0;

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
WHERE FIND_IN_SET('yaoshi', source_rows.system_slugs) > 0;

DROP TEMPORARY TABLE IF EXISTS tmp_annual_flow_import;

INSERT INTO lamp_positions (type_id, position_code, area, row_no, col_no, status) VALUES
(@guangming_id, 'GM-A-01', 'A', 1, 1, 'available'),
(@guangming_id, 'GM-A-02', 'A', 1, 2, 'available'),
(@guangming_id, 'GM-A-03', 'A', 1, 3, 'available'),
(@guangming_id, 'GM-A-04', 'A', 1, 4, 'available'),
(@guangming_id, 'GM-A-05', 'A', 1, 5, 'maintenance'),

(@taisui_id, 'TS-A-01', 'A', 1, 1, 'available'),
(@taisui_id, 'TS-A-02', 'A', 1, 2, 'available'),
(@taisui_id, 'TS-A-03', 'A', 1, 3, 'available'),
(@taisui_id, 'TS-A-04', 'A', 1, 4, 'available'),
(@taisui_id, 'TS-A-05', 'A', 1, 5, 'maintenance'),

(@wenchang_id, 'WC-A-01', 'A', 1, 1, 'available'),
(@wenchang_id, 'WC-A-02', 'A', 1, 2, 'available'),
(@wenchang_id, 'WC-A-03', 'A', 1, 3, 'available'),
(@wenchang_id, 'WC-A-04', 'A', 1, 4, 'available'),
(@wenchang_id, 'WC-A-05', 'A', 1, 5, 'maintenance'),

(@caishen_id, 'CS-A-01', 'A', 1, 1, 'available'),
(@caishen_id, 'CS-A-02', 'A', 1, 2, 'available'),
(@caishen_id, 'CS-A-03', 'A', 1, 3, 'available'),
(@caishen_id, 'CS-A-04', 'A', 1, 4, 'available'),
(@caishen_id, 'CS-A-05', 'A', 1, 5, 'maintenance'),

(@yaoshi_id, 'YS-A-01', 'A', 1, 1, 'available'),
(@yaoshi_id, 'YS-A-02', 'A', 1, 2, 'available'),
(@yaoshi_id, 'YS-A-03', 'A', 1, 3, 'available'),
(@yaoshi_id, 'YS-A-04', 'A', 1, 4, 'available'),
(@yaoshi_id, 'YS-A-05', 'A', 1, 5, 'maintenance'),

(@jixiang_id, 'JX-A-01', 'A', 1, 1, 'available'),
(@jixiang_id, 'JX-A-02', 'A', 1, 2, 'available'),
(@jixiang_id, 'JX-A-03', 'A', 1, 3, 'available'),
(@jixiang_id, 'JX-A-04', 'A', 1, 4, 'available'),
(@jixiang_id, 'JX-A-05', 'A', 1, 5, 'maintenance'),

(@pingan_id, 'PA-A-01', 'A', 1, 1, 'available'),
(@pingan_id, 'PA-A-02', 'A', 1, 2, 'available'),
(@pingan_id, 'PA-A-03', 'A', 1, 3, 'available'),
(@pingan_id, 'PA-A-04', 'A', 1, 4, 'available'),
(@pingan_id, 'PA-A-05', 'A', 1, 5, 'maintenance');

INSERT INTO articles (author_id, article_type, title, content, status, published_at) VALUES
(@admin_user_id, 'announcement', '線上點燈服務開放測試', '本系統目前為資料庫系統期末專案測試版本，可進行會員註冊、燈種瀏覽與訂單流程測試。', 'published', NOW()),
(@admin_user_id, 'temple_history', '廟宇文化介紹', '此處可放置廟宇歷史、神明介紹與民俗慶典內容，之後由後台文章管理功能維護。', 'published', NOW()),
(@admin_user_id, 'lantern_intro', '點燈流程說明', '選擇燈種與祈福對象後加入購物車，送出訂單並完成付款審核後，由管理員分配實體燈位。', 'published', NOW());

INSERT INTO scheduled_jobs (
    created_by,
    job_name,
    job_type,
    trigger_time,
    cron_expression,
    target_channel,
    subject,
    content,
    status,
    next_run_at
) VALUES
(
    @admin_user_id,
    '點燈到期提醒',
    'expiration_reminder',
    NULL,
    '0 9 * * *',
    'email',
    '年度點燈即將謝燈',
    '您的年度點燈服務即將進入謝燈日期，敬請留意廟方公告與後續續燈資訊。',
    'active',
    DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)
),
(
    @admin_user_id,
    '法會活動廣播',
    'event_broadcast',
    NULL,
    '0 10 * * 1',
    'line',
    '節慶祈福通知',
    '後台可將這筆排程作為節慶或法會公告的廣播模板。',
    'paused',
    DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
);

INSERT INTO notifications (
    user_id,
    job_id,
    channel,
    subject,
    content,
    status
) VALUES
(
    @admin_user_id,
    (SELECT job_id FROM scheduled_jobs WHERE job_name = '點燈到期提醒' LIMIT 1),
    'email',
    '通知中心測試',
    '這是一筆推播通知中心的初始測試資料，可用於後台通知管理畫面。',
    'pending'
);

INSERT INTO statistics (
    generated_by,
    period_start,
    period_end,
    total_revenue,
    total_lantern_count,
    count_by_type_json
) VALUES
(
    @admin_user_id,
    DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'),
    LAST_DAY(CURRENT_DATE),
    0.00,
    0,
    '{"光明燈":0,"安太歲":0,"文昌燈":0,"財神燈":0,"藥師燈":0}'
);
