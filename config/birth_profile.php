<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function birth_profile_lunar_info(): array
{
    return [
        0x04bd8, 0x04ae0, 0x0a570, 0x054d5, 0x0d260, 0x0d950, 0x16554, 0x056a0, 0x09ad0, 0x055d2,
        0x04ae0, 0x0a5b6, 0x0a4d0, 0x0d250, 0x1d255, 0x0b540, 0x0d6a0, 0x0ada2, 0x095b0, 0x14977,
        0x04970, 0x0a4b0, 0x0b4b5, 0x06a50, 0x06d40, 0x1ab54, 0x02b60, 0x09570, 0x052f2, 0x04970,
        0x06566, 0x0d4a0, 0x0ea50, 0x06e95, 0x05ad0, 0x02b60, 0x186e3, 0x092e0, 0x1c8d7, 0x0c950,
        0x0d4a0, 0x1d8a6, 0x0b550, 0x056a0, 0x1a5b4, 0x025d0, 0x092d0, 0x0d2b2, 0x0a950, 0x0b557,
        0x06ca0, 0x0b550, 0x15355, 0x04da0, 0x0a5d0, 0x14573, 0x052d0, 0x0a9a8, 0x0e950, 0x06aa0,
        0x0aea6, 0x0ab50, 0x04b60, 0x0aae4, 0x0a570, 0x05260, 0x0f263, 0x0d950, 0x05b57, 0x056a0,
        0x096d0, 0x04dd5, 0x04ad0, 0x0a4d0, 0x0d4d4, 0x0d250, 0x0d558, 0x0b540, 0x0b6a0, 0x195a6,
        0x095b0, 0x049b0, 0x0a974, 0x0a4b0, 0x0b27a, 0x06a50, 0x06d40, 0x0af46, 0x0ab60, 0x09570,
        0x04af5, 0x04970, 0x064b0, 0x074a3, 0x0ea50, 0x06b58, 0x05ac0, 0x0ab60, 0x096d5, 0x092e0,
        0x0c960, 0x0d954, 0x0d4a0, 0x0da50, 0x07552, 0x056a0, 0x0abb7, 0x025d0, 0x092d0, 0x0cab5,
        0x0a950, 0x0b4a0, 0x0baa4, 0x0ad50, 0x055d9, 0x04ba0, 0x0a5b0, 0x15176, 0x052b0, 0x0a930,
        0x07954, 0x06aa0, 0x0ad50, 0x05b52, 0x04b60, 0x0a6e6, 0x0a4e0, 0x0d260, 0x0ea65, 0x0d530,
        0x05aa0, 0x076a3, 0x096d0, 0x04bd7, 0x04ad0, 0x0a4d0, 0x1d0b6, 0x0d250, 0x0d520, 0x0dd45,
        0x0b5a0, 0x056d0, 0x055b2, 0x049b0, 0x0a577, 0x0a4b0, 0x0aa50, 0x1b255, 0x06d20, 0x0ada0,
        0x14b63, 0x09370, 0x049f8, 0x04970, 0x064b0, 0x168a6, 0x0ea50, 0x06b20, 0x1a6c4, 0x0aae0,
        0x0a2e0, 0x0d2e3, 0x0c960, 0x0d557, 0x0d4a0, 0x0da50, 0x05d55, 0x056a0, 0x0a6d0, 0x055d4,
        0x052d0, 0x0a9b8, 0x0a950, 0x0b4a0, 0x0b6a6, 0x0ad50, 0x055a0, 0x0aba4, 0x0a5b0, 0x052b0,
        0x0b273, 0x06930, 0x07337, 0x06aa0, 0x0ad50, 0x14b55, 0x04b60, 0x0a570, 0x054e4, 0x0d160,
        0x0e968, 0x0d520, 0x0daa0, 0x16aa6, 0x056d0, 0x04ae0, 0x0a9d4, 0x0a2d0, 0x0d150, 0x0f252,
        0x0d520,
    ];
}

function birth_profile_lunar_year_days(int $year): int
{
    $info = birth_profile_lunar_info()[$year - 1900] ?? 0;
    $sum = 348;

    for ($mask = 0x8000; $mask > 0x8; $mask >>= 1) {
        if (($info & $mask) !== 0) {
            $sum++;
        }
    }

    return $sum + birth_profile_lunar_leap_days($year);
}

function birth_profile_lunar_leap_month(int $year): int
{
    return (birth_profile_lunar_info()[$year - 1900] ?? 0) & 0xf;
}

function birth_profile_lunar_leap_days(int $year): int
{
    $leapMonth = birth_profile_lunar_leap_month($year);
    if ($leapMonth === 0) {
        return 0;
    }

    return ((birth_profile_lunar_info()[$year - 1900] & 0x10000) !== 0) ? 30 : 29;
}

function birth_profile_lunar_month_days(int $year, int $month): int
{
    return ((birth_profile_lunar_info()[$year - 1900] & (0x10000 >> $month)) !== 0) ? 30 : 29;
}

function birth_profile_solar_to_lunar(string $birthday): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthday);
    $baseDate = DateTimeImmutable::createFromFormat('!Y-m-d', '1900-01-31');
    $maxDate = DateTimeImmutable::createFromFormat('!Y-m-d', '2100-12-31');
    if (!$date || !$baseDate || !$maxDate || $date < $baseDate || $date > $maxDate) {
        return null;
    }

    $offset = (int) $baseDate->diff($date)->format('%r%a');
    $year = 1900;
    while ($year <= 2100) {
        $days = birth_profile_lunar_year_days($year);
        if ($offset < $days) {
            break;
        }

        $offset -= $days;
        $year++;
    }

    $month = 1;
    $isLeap = false;
    $leapMonth = birth_profile_lunar_leap_month($year);

    while ($month <= 12) {
        $monthDays = $isLeap
            ? birth_profile_lunar_leap_days($year)
            : birth_profile_lunar_month_days($year, $month);

        if ($offset < $monthDays) {
            break;
        }

        $offset -= $monthDays;
        if ($leapMonth === $month && !$isLeap) {
            $isLeap = true;
        } else {
            $isLeap = false;
            $month++;
        }
    }

    return [
        'year' => $year,
        'month' => $month,
        'day' => $offset + 1,
        'is_leap_month' => $isLeap,
    ];
}

function birth_profile_lunar_date_label(?array $lunar): ?string
{
    if ($lunar === null) {
        return null;
    }

    $months = [1 => '正月', 2 => '二月', 3 => '三月', 4 => '四月', 5 => '五月', 6 => '六月', 7 => '七月', 8 => '八月', 9 => '九月', 10 => '十月', 11 => '冬月', 12 => '臘月'];
    $prefixes = ['', '初', '十', '廿', '卅'];
    $days = [10 => '初十', 20 => '二十', 30 => '三十'];
    $day = (int) $lunar['day'];
    $dayLabel = $days[$day] ?? ($prefixes[intdiv($day, 10) + 1] . ['十', '一', '二', '三', '四', '五', '六', '七', '八', '九'][$day % 10]);
    $monthLabel = ((bool) $lunar['is_leap_month'] ? '閏' : '') . ($months[(int) $lunar['month']] ?? ((string) $lunar['month'] . '月'));

    return $lunar['year'] . ' 年 ' . $monthLabel . $dayLabel;
}

function birth_profile_zodiac_for_lunar_year(?int $lunarYear): ?string
{
    if ($lunarYear === null) {
        return null;
    }

    $animals = ['鼠', '牛', '虎', '兔', '龍', '蛇', '馬', '羊', '猴', '雞', '狗', '豬'];
    return $animals[($lunarYear - 4) % 12] ?? null;
}

function birth_profile_hour_branch(?string $clockTime): ?string
{
    if ($clockTime === null || trim($clockTime) === '') {
        return null;
    }

    if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', trim($clockTime), $matches)) {
        return null;
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    if ($hour > 23 || $minute > 59) {
        return null;
    }

    $branches = ['子時', '丑時', '寅時', '卯時', '辰時', '巳時', '午時', '未時', '申時', '酉時', '戌時', '亥時'];
    $index = $hour === 23 ? 0 : intdiv($hour + 1, 2);

    return $branches[$index] ?? null;
}

function birth_profile_derive(?string $birthday, ?string $clockTime): array
{
    $lunar = $birthday ? birth_profile_solar_to_lunar($birthday) : null;

    return [
        'lunar_birthday' => birth_profile_lunar_date_label($lunar),
        'zodiac' => birth_profile_zodiac_for_lunar_year($lunar['year'] ?? null),
        'birth_time' => birth_profile_hour_branch($clockTime),
    ];
}

function sync_self_dependent(int $userId, array $userData): int
{
    $derived = birth_profile_derive($userData['birthday'] ?? null, $userData['birth_clock_time'] ?? null);
    $payload = [
        'name' => (string) ($userData['name'] ?? ''),
        'gender' => (string) ($userData['gender'] ?? 'unspecified'),
        'phone' => $userData['phone'] ?? null,
        'birthday' => $userData['birthday'] ?? null,
        'birth_clock_time' => $userData['birth_clock_time'] ?? null,
        'birth_time' => $derived['birth_time'],
        'lunar_birthday' => $derived['lunar_birthday'],
        'zodiac' => $derived['zodiac'],
    ];

    $stmt = db()->prepare(
        'SELECT dependent_id
         FROM dependents
         WHERE user_id = ?
           AND is_self_profile = 1
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $dependentId = $stmt->fetchColumn();

    if ($dependentId !== false) {
        $stmt = db()->prepare(
            'UPDATE dependents
             SET name = ?, relationship = "本人", gender = ?, phone = ?, birthday = ?, birth_clock_time = ?,
                 birth_time = ?, lunar_birthday = ?, zodiac = ?, note = "由會員資料同步"
             WHERE dependent_id = ?'
        );
        $stmt->execute([
            $payload['name'],
            $payload['gender'],
            $payload['phone'],
            $payload['birthday'],
            $payload['birth_clock_time'],
            $payload['birth_time'],
            $payload['lunar_birthday'],
            $payload['zodiac'],
            (int) $dependentId,
        ]);

        return (int) $dependentId;
    }

    $stmt = db()->prepare(
        'INSERT INTO dependents (
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
         ) VALUES (?, 1, ?, "本人", ?, ?, ?, ?, ?, ?, ?, "由會員資料同步")'
    );
    $stmt->execute([
        $userId,
        $payload['name'],
        $payload['gender'],
        $payload['phone'],
        $payload['birthday'],
        $payload['birth_clock_time'],
        $payload['birth_time'],
        $payload['lunar_birthday'],
        $payload['zodiac'],
    ]);

    return (int) db()->lastInsertId();
}
