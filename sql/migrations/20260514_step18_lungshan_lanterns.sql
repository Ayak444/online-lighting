SET NAMES utf8mb4;

UPDATE lantern_types
SET name = '聖母殿光明燈',
    description = '安奉於聖母殿，祈求元辰光彩、事業順利，適合一般年度祈福。',
    blessing_description = '依龍山寺點燈說明，聖母殿光明燈祈願元辰光彩、事業順利。',
    recommended_for = '一般信眾、上班族、祈求整體運勢與事業順利者。',
    not_recommended_for = NULL,
    price = 600.00,
    sort_order = 1,
    is_active = 1
WHERE slug = 'guangming';

UPDATE lantern_types
SET name = '文昌殿光明燈',
    description = '安奉於文昌殿，祈求智慧增長、功名及第、學業進步。',
    blessing_description = '依龍山寺點燈說明，文昌殿光明燈祈願智慧增長、功名及第。',
    recommended_for = '學生、考生、準備證照、公職考試或希望工作升遷順利者。',
    not_recommended_for = NULL,
    price = 600.00,
    sort_order = 2,
    is_active = 1
WHERE slug = 'wenchang';

UPDATE lantern_types
SET description = '安奉於華佗廳、文昌殿，祈求元辰光彩、消災延壽、身體健康。',
    blessing_description = '依龍山寺點燈說明，藥師燈祈願元辰光彩、消災延壽。',
    recommended_for = '為自己、長輩或親友祈求身體康寧、遠離病痛者。',
    price = 600.00,
    sort_order = 3,
    is_active = 1
WHERE slug = 'yaoshi';

UPDATE lantern_types
SET description = '安奉於關聖帝君殿，祈求財源廣進、生意興隆。',
    blessing_description = '依龍山寺點燈說明，財神燈祈願財源廣進、生意興隆。',
    recommended_for = '經商、創業、業務工作、投資理財或祈求財運順利者。',
    price = 600.00,
    sort_order = 4,
    is_active = 1
WHERE slug = 'caishen';

INSERT INTO lantern_types (
    slug,
    name,
    description,
    blessing_description,
    recommended_for,
    not_recommended_for,
    price,
    image_path,
    sort_order,
    is_active
) VALUES (
    'jixiang',
    '吉祥燈',
    '安奉於關聖帝君殿，祈求圓滿吉祥、常保安康。',
    '依龍山寺點燈說明，吉祥燈祈願圓滿吉祥、常保安康。',
    '祈求生活平順、家庭和樂、常保安康、萬事吉祥者。',
    NULL,
    600.00,
    'assets/images/lantern-jixiang.png',
    5,
    1
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    blessing_description = VALUES(blessing_description),
    recommended_for = VALUES(recommended_for),
    not_recommended_for = VALUES(not_recommended_for),
    price = VALUES(price),
    image_path = VALUES(image_path),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active);

UPDATE lantern_types
SET description = '安奉於太歲廳，祈求流年平順、化解太歲沖煞。',
    blessing_description = '依龍山寺點燈說明，安太歲祈願流年平順、消災解厄。',
    recommended_for = '當年度犯太歲、沖太歲，或希望化解流年不順者。',
    not_recommended_for = NULL,
    price = 600.00,
    sort_order = 6,
    is_active = 1
WHERE slug = 'taisui';

INSERT INTO lantern_types (
    slug,
    name,
    description,
    blessing_description,
    recommended_for,
    not_recommended_for,
    price,
    image_path,
    sort_order,
    is_active
) VALUES (
    'pingan',
    '平安燈',
    '懸掛於寺內外，祈求闔家平安、吉祥納福；通常以戶為單位報名。',
    '依龍山寺點燈說明，平安燈祈願闔家平安、吉祥納福。',
    '為家庭、同住家人或全戶祈求出入平安者。',
    NULL,
    600.00,
    'assets/images/lantern-pingan.png',
    7,
    1
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    blessing_description = VALUES(blessing_description),
    recommended_for = VALUES(recommended_for),
    not_recommended_for = VALUES(not_recommended_for),
    price = VALUES(price),
    image_path = VALUES(image_path),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active);

UPDATE lantern_types
SET is_active = 0
WHERE slug NOT IN ('guangming', 'wenchang', 'yaoshi', 'caishen', 'jixiang', 'taisui', 'pingan');

UPDATE annual_flow_rules afr
INNER JOIN lantern_types lt ON lt.type_id = afr.type_id
SET afr.recommendation_reason = REPLACE(afr.recommendation_reason, '光明燈', '聖母殿光明燈')
WHERE lt.slug = 'guangming'
  AND afr.recommendation_reason NOT LIKE '%聖母殿光明燈%';

UPDATE annual_flow_rules afr
INNER JOIN lantern_types lt ON lt.type_id = afr.type_id
SET afr.recommendation_reason = REPLACE(afr.recommendation_reason, '文昌燈', '文昌殿光明燈')
WHERE lt.slug = 'wenchang'
  AND afr.recommendation_reason NOT LIKE '%文昌殿光明燈%';

SET @jixiang_id = (SELECT type_id FROM lantern_types WHERE slug = 'jixiang');
SET @pingan_id = (SELECT type_id FROM lantern_types WHERE slug = 'pingan');

INSERT IGNORE INTO lamp_positions (type_id, position_code, area, row_no, col_no, status) VALUES
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
