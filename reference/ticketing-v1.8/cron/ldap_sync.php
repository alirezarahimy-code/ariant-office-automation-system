<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}
if (!ldap_cfg('enabled', false) || !function_exists('ldap_connect')) {
    fwrite(STDOUT, "LDAP sync skipped: LDAP is disabled or unavailable.\n");
    exit(0);
}

try {
    $result = ldap_sync_all_users();
    activity_log([
        'action_code' => 'ldap_sync',
        'action_label' => 'همگام‌سازی کاربران دامین',
        'module' => 'cron',
        'meta' => $result,
    ]);
    fwrite(STDOUT, 'LDAP sync complete. Created: ' . $result['created'] . '; updated: ' . $result['updated'] . '; disabled: ' . $result['disabled'] . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'LDAP sync failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
