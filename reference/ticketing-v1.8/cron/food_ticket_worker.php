<?php
declare(strict_types=1);

/**
 * Background Worker — بدون Browser / Panel / Session
 * پیش‌نیاز روشن بودن: سرور + دستگاه حضور و غیاب (فایل منبع تردد) + فیش‌پرینتر + شبکه بین آن‌ها
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';
foreach (['food-ticket-runtime.php', 'food-ticket-engine.php'] as $extra) {
    $p = dirname(__DIR__) . '/' . $extra;
    if (is_file($p)) {
        require_once $p;
    }
}

$loop = in_array('--loop', array_slice($argv, 1), true);
$queueOnly = in_array('--queue-only', array_slice($argv, 1), true);

// --max-minutes=N : بعد از N دقیقه با کد ۰ خارج می‌شود تا wrapper دوباره آن را بالا بیاورد
// (چرخش فایل لاگ روزانه + جلوگیری از نشت حافظه). پیش‌فرض: بدون محدودیت.
$maxMinutes = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--max-minutes=(\d+)$/', (string) $arg, $m)) {
        $maxMinutes = max(0, (int) $m[1]);
    }
}
$startedAt = time();

$storageDir = dirname(__DIR__) . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0750, true);
}

// فقط یک worker زنده مجاز است (قفل تا پایان عمر پردازش نگه داشته می‌شود).
// کد خروج ۳ = نمونهٔ دیگری در حال اجراست؛ wrapper در این حالت حلقه را رها می‌کند.
$instanceLock = null;
if ($loop) {
    $instanceLock = @fopen($storageDir . '/food-worker-process.lock', 'c');
    if (!$instanceLock || !@flock($instanceLock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, '[food-ticket-worker] ' . date('c') . ' another worker instance is already running; exiting.' . PHP_EOL);
        exit(3);
    }
    @ftruncate($instanceLock, 0);
    @fwrite($instanceLock, (string) getmypid());
}

// حذف لاگ‌های قدیمی‌تر از ۱۴ روز
try {
    foreach ((array) glob($storageDir . '/food_ticket_logs/worker-*.log') as $oldLog) {
        if (is_file($oldLog) && filemtime($oldLog) < time() - 14 * 86400) {
            @unlink($oldLog);
        }
    }
} catch (Throwable) {
}

$heartbeatFile = $storageDir . '/food_worker.heartbeat';
$lastPrintedSig = '';
$lastPrintedAt = 0;

fwrite(STDOUT, '[food-ticket-worker] ' . date('c') . ' start loop=' . ($loop ? '1' : '0') . ' pid=' . getmypid() . ' max_minutes=' . $maxMinutes . PHP_EOL);

do {
    $cycle = ['processed' => 0, 'printed' => 0, 'errors' => 0, 'message' => ''];
    try {
        try {
            db(true);
            db()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $dbEx) {
            fwrite(STDERR, '[db-reconnect-wait] ' . $dbEx->getMessage() . PHP_EOL);
            if ($loop) {
                sleep(5);
                continue;
            }
            throw $dbEx;
        }

        $config = food_ticket_config(true);
        if (!(int) ($config['enabled'] ?? 0)) {
            $cycle['message'] = 'پردازشگر چاپ فیش غیرفعال است.';
        } else {
            if (!$queueOnly) {
                try {
                    $cycle = food_ticket_process_batch();
                } catch (Throwable $batchEx) {
                    fwrite(STDERR, '[batch] ' . $batchEx->getMessage() . PHP_EOL);
                    $cycle['errors'] = 1;
                    $cycle['message'] = $batchEx->getMessage();
                }
            }

            try {
                $q = food_ticket_process_print_queue_locked($config);
                $cycle['queue'] = $q;
                $cycle['printed'] = (int) ($cycle['printed'] ?? 0) + (int) ($q['printed'] ?? 0);
                $cycle['queue_printed'] = (int) ($cycle['queue_printed'] ?? 0) + (int) ($q['printed'] ?? 0);
                $cycle['errors'] = (int) ($cycle['errors'] ?? 0) + (int) ($q['failed'] ?? 0);
            } catch (Throwable $qEx) {
                fwrite(STDERR, '[queue] ' . $qEx->getMessage() . PHP_EOL);
                $cycle['errors'] = (int) ($cycle['errors'] ?? 0) + 1;
            }
        }

        try {
            food_ticket_worker_heartbeat($cycle);
        } catch (Throwable) {
        }

        @file_put_contents($heartbeatFile, date('c') . ' pid=' . getmypid() . PHP_EOL, LOCK_EX);

        // چرخه‌های خالی و تکراری هر ۲ ثانیه لاگ نمی‌شوند؛ فقط هنگام تغییر یا هر ۵ دقیقه یک بار.
        $json = (string) json_encode($cycle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = md5($json);
        if ($sig !== $lastPrintedSig || (time() - $lastPrintedAt) >= 300) {
            fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $json . PHP_EOL);
            $lastPrintedSig = $sig;
            $lastPrintedAt = time();
        }
    } catch (Throwable $fatal) {
        fwrite(STDERR, '[cycle] ' . $fatal->getMessage() . PHP_EOL);
        try {
            food_ticket_worker_heartbeat([], $fatal);
        } catch (Throwable) {
        }
        if (!$loop) {
            exit(1);
        }
        sleep(5);
        continue;
    }

    if ($loop) {
        if ($maxMinutes > 0 && (time() - $startedAt) >= $maxMinutes * 60) {
            fwrite(STDOUT, '[food-ticket-worker] ' . date('c') . ' max runtime reached; exiting for clean restart.' . PHP_EOL);
            break;
        }
        if (memory_get_usage(true) > 256 * 1024 * 1024) {
            fwrite(STDOUT, '[food-ticket-worker] ' . date('c') . ' memory limit reached; exiting for clean restart.' . PHP_EOL);
            break;
        }
        // در حال کار (تردد/چاپ در این چرخه): بعد از نیم ثانیه دوباره؛ بیکار: طبق poll_seconds (پیش‌فرض ۲ ثانیه).
        $busy = ((int) ($cycle['processed'] ?? 0) + (int) ($cycle['queued'] ?? 0) + (int) ($cycle['queue_printed'] ?? 0)) > 0;
        if ($busy) {
            usleep(500000);
        } else {
            $poll = 2;
            try {
                $poll = (int) (food_ticket_config()['poll_seconds'] ?? 2);
            } catch (Throwable) {
            }
            sleep(max(1, min(60, $poll)));
        }
    }
} while ($loop);

exit(0);
