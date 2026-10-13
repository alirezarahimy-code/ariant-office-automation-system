<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

$days = activity_log_retention_days();
$deleted = activity_logs_prune($days);
activity_log([
    'action_code' => 'activity_logs_pruned',
    'action_label' => 'پاک‌سازی خودکار لاگ‌های قدیمی‌تر از ' . $days . ' روز',
    'module' => 'cron',
    'meta' => ['days' => $days, 'deleted' => $deleted],
]);
fwrite(STDOUT, 'Activity log prune complete. Deleted ' . $deleted . ' rows older than ' . $days . " days.\n");
