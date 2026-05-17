SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS birth_clock_time TIME NULL AFTER birthday;

ALTER TABLE dependents
    ADD COLUMN IF NOT EXISTS is_self_profile TINYINT(1) NULL DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS birth_clock_time TIME NULL AFTER birthday;

UPDATE dependents d
INNER JOIN (
    SELECT user_id, MIN(dependent_id) AS dependent_id
    FROM dependents
    WHERE is_self_profile IS NULL
      AND relationship IN ('本人', '自己', 'Self')
    GROUP BY user_id
) chosen
    ON chosen.dependent_id = d.dependent_id
LEFT JOIN dependents existing_self
    ON existing_self.user_id = d.user_id
   AND existing_self.is_self_profile = 1
SET d.is_self_profile = 1,
    d.relationship = '本人',
    d.note = COALESCE(d.note, '由會員資料同步')
WHERE existing_self.dependent_id IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_dependents_user_self
    ON dependents (user_id, is_self_profile);

INSERT INTO dependents (
    user_id,
    is_self_profile,
    name,
    relationship,
    gender,
    phone,
    birthday,
    birth_clock_time,
    birth_time,
    lunar_birthday,
    zodiac,
    note
)
SELECT
    u.user_id,
    1,
    u.name,
    '本人',
    u.gender,
    u.phone,
    u.birthday,
    u.birth_clock_time,
    NULL,
    u.lunar_birthday,
    u.zodiac,
    '由會員資料同步'
FROM users u
LEFT JOIN dependents d
    ON d.user_id = u.user_id
   AND d.is_self_profile = 1
WHERE d.dependent_id IS NULL;
