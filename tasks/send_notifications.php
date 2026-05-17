<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/notification_delivery.php';

$limit = isset($argv[1]) ? max(1, min(200, (int) $argv[1])) : 50;
$results = notification_process_pending($limit);

if ($results === []) {
    $results[] = 'No pending notification deliveries.';
}

header('Content-Type: text/plain; charset=UTF-8');
echo implode(PHP_EOL, $results) . PHP_EOL;
