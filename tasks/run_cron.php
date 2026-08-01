<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

function run_cron_task(string $label, string $path): string
{
    ob_start();
    try {
        require $path;
        $output = trim((string) ob_get_clean());

        return '[' . $label . ']' . PHP_EOL . ($output === '' ? 'No output.' : $output);
    } catch (Throwable $throwable) {
        $output = trim((string) ob_get_clean());

        return '[' . $label . ' failed]' . PHP_EOL
            . ($output === '' ? '' : $output . PHP_EOL)
            . $throwable->getMessage();
    }
}

$startedAt = date('Y-m-d H:i:s');
$results = [
    'Cron started at ' . $startedAt,
    run_cron_task('scheduled jobs', __DIR__ . '/run_scheduled_jobs.php'),
    run_cron_task('notification deliveries', __DIR__ . '/send_notifications.php'),
    'Cron finished at ' . date('Y-m-d H:i:s'),
];

echo implode(PHP_EOL . PHP_EOL, $results) . PHP_EOL;
