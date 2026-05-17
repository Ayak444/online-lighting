<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/birth_profile.php';

$users = db()->query(
    'SELECT user_id, name, gender, phone, birthday, birth_clock_time
     FROM users
     ORDER BY user_id ASC'
)->fetchAll();

$updatedUsers = 0;
foreach ($users as $user) {
    $derived = birth_profile_derive($user['birthday'] ?? null, $user['birth_clock_time'] ?? null);
    $stmt = db()->prepare(
        'UPDATE users
         SET lunar_birthday = ?, zodiac = ?
         WHERE user_id = ?'
    );
    $stmt->execute([
        $derived['lunar_birthday'],
        $derived['zodiac'],
        (int) $user['user_id'],
    ]);

    sync_self_dependent((int) $user['user_id'], $user);
    $updatedUsers++;
}

$dependents = db()->query(
    'SELECT dependent_id, birthday, birth_clock_time
     FROM dependents
     WHERE is_self_profile IS NULL
     ORDER BY dependent_id ASC'
)->fetchAll();

$updatedDependents = 0;
foreach ($dependents as $dependent) {
    $derived = birth_profile_derive($dependent['birthday'] ?? null, $dependent['birth_clock_time'] ?? null);
    $stmt = db()->prepare(
        'UPDATE dependents
         SET lunar_birthday = ?, zodiac = ?, birth_time = ?
         WHERE dependent_id = ?'
    );
    $stmt->execute([
        $derived['lunar_birthday'],
        $derived['zodiac'],
        $derived['birth_time'],
        (int) $dependent['dependent_id'],
    ]);
    $updatedDependents++;
}

header('Content-Type: text/plain; charset=UTF-8');
echo 'Updated users: ' . $updatedUsers . PHP_EOL;
echo 'Updated dependents: ' . $updatedDependents . PHP_EOL;
