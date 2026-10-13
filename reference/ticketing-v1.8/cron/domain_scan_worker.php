<?php
declare(strict_types=1);

/**
 * Domain scan worker — بدون Browser / Panel
 * صف اسکن دامنه را از پایگاه داده برمی‌دارد و کامپیوترهای آنلاین را استخراج می‌کند.
 * اجرا:  php cron/domain_scan_worker.php --loop
 *        php cron/domain_scan_worker.php --run=12
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/inventory.php';
require dirname(__DIR__) . '/asset-profile.php';
require dirname(__DIR__) . '/domain-scan.php';

$loop = in_array('--loop', array_slice($argv, 1), true);
$runArg = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--run=') === 0) {
        $runArg = (int) substr($arg, 6);
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, min(500, (int) substr($arg, 8)));
    }
}
$limit = $limit ?? 5;

fwrite(STDOUT, '[domain-scan-worker] ' . date('c') . ' start loop=' . ($loop ? '1' : '0') . ' run=' . $runArg . PHP_EOL);

do {
    try {
        domain_scan_ensure_schema();
        $run = $runArg > 0 ? domain_scan_run($runArg) : domain_scan_latest_run();
        if ($run === null || (string) $run['status'] === 'finished') {
            if (!$loop) {
                fwrite(STDOUT, '[domain-scan-worker] no active run' . PHP_EOL);
                break;
            }
            sleep(10);
            continue;
        }
        $result = domain_scan_process((int) $run['id'], $limit, 0);
        fwrite(STDOUT, '[domain-scan-worker] run=' . (int) $run['id'] . ' processed=' . $result['processed'] . ' pending=' . $result['pending'] . PHP_EOL);
        if (!$loop && $result['pending'] === 0) {
            break;
        }
        if ($loop) {
            sleep(2);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, '[domain-scan-worker] ' . $exception->getMessage() . PHP_EOL);
        if (!$loop) {
            break;
        }
        sleep(10);
    }
} while ($loop);

fwrite(STDOUT, '[domain-scan-worker] done' . PHP_EOL);
