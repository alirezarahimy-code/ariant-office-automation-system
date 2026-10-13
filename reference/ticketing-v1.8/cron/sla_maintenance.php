<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

$query = db()->query('SELECT id, subject, due_at FROM tickets WHERE due_at IS NOT NULL AND status NOT IN ("resolved", "closed") AND due_at <= DATE_ADD(NOW(), INTERVAL 60 MINUTE) ORDER BY due_at LIMIT 1000');
$claim = db()->prepare('INSERT IGNORE INTO ticket_sla_alerts (ticket_id, alert_type, due_at) VALUES (?, ?, ?)');
$alerts = 0;
$now = new DateTimeImmutable('now');

foreach ($query->fetchAll() as $ticket) {
    $dueAt = new DateTimeImmutable((string) $ticket['due_at']);
    $type = $dueAt <= $now ? 'overdue' : 'due_soon';
    db()->beginTransaction();
    try {
        $claim->execute([(int) $ticket['id'], $type, $ticket['due_at']]);
        if ($claim->rowCount() === 1) {
            $title = $type === 'overdue' ? 'تیکت از SLA عبور کرد' : 'مهلت SLA تیکت نزدیک است';
            $body = $type === 'overdue'
                ? 'تیکت ' . ticket_number((int) $ticket['id']) . ' از مهلت SLA عبور کرده است.'
                : 'مهلت تیکت ' . ticket_number((int) $ticket['id']) . ' در کمتر از یک ساعت آینده است.';
            notify_ticket_staff((int) $ticket['id'], 'sla_' . $type, $title, $body);
            $alerts++;
        }
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('ITSM SLA maintenance failed for ticket ' . (int) $ticket['id'] . ': ' . $exception->getMessage());
    }
}

$olaAlerts = 0;
try {
    $olaQuery = db()->query('SELECT id, first_response_at, ola_response_due_at, ola_due_at, ola_escalation_1_at, ola_escalation_2_at FROM tickets WHERE status NOT IN ("resolved", "closed") AND (ola_response_due_at IS NOT NULL OR ola_due_at IS NOT NULL OR ola_escalation_1_at IS NOT NULL OR ola_escalation_2_at IS NOT NULL) ORDER BY id LIMIT 2000');
    $olaClaim = db()->prepare('INSERT IGNORE INTO ticket_ola_alerts (ticket_id, alert_type, due_at) VALUES (?, ?, ?)');
    foreach ($olaQuery->fetchAll() as $ticket) {
        $candidates = [];
        if (empty($ticket['first_response_at']) && !empty($ticket['ola_response_due_at'])) {
            $candidates['response_overdue'] = $ticket['ola_response_due_at'];
        }
        if (!empty($ticket['ola_due_at'])) {
            $candidates['resolution_overdue'] = $ticket['ola_due_at'];
        }
        if (!empty($ticket['ola_escalation_1_at'])) {
            $candidates['escalation_1'] = $ticket['ola_escalation_1_at'];
        }
        if (!empty($ticket['ola_escalation_2_at'])) {
            $candidates['escalation_2'] = $ticket['ola_escalation_2_at'];
        }
        foreach ($candidates as $type => $dueAtText) {
            $dueAt = new DateTimeImmutable((string) $dueAtText);
            if ($dueAt > $now) {
                continue;
            }
            db()->beginTransaction();
            try {
                $olaClaim->execute([(int) $ticket['id'], $type, $dueAtText]);
                if ($olaClaim->rowCount() === 1) {
                    $number = ticket_number((int) $ticket['id']);
                    $labels = [
                        'response_overdue' => ['پاسخ OLA عقب افتاد', 'مهلت پاسخ داخلی تیکت ' . $number . ' گذشته است.'],
                        'resolution_overdue' => ['حل OLA عقب افتاد', 'مهلت حل داخلی تیکت ' . $number . ' گذشته است.'],
                        'escalation_1' => ['Escalation سطح اول', 'تیکت ' . $number . ' به سطح اول پیگیری داخلی رسید.'],
                        'escalation_2' => ['Escalation سطح دوم', 'تیکت ' . $number . ' به سطح دوم پیگیری مدیریتی رسید.'],
                    ][$type];
                    notify_ticket_staff((int) $ticket['id'], 'ola_' . $type, $labels[0], $labels[1]);
                    if ($type === 'escalation_2') {
                        $admins = db()->query('SELECT id FROM users WHERE role = "admin" AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($admins as $adminId) {
                            notify_user((int) $adminId, 'ola_escalation_2', $labels[0], $labels[1], (int) $ticket['id']);
                        }
                    }
                    $olaAlerts++;
                }
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                error_log('ITSM OLA maintenance failed for ticket ' . (int) $ticket['id'] . ': ' . $exception->getMessage());
            }
        }
    }
} catch (Throwable $exception) {
    error_log('ITSM OLA maintenance unavailable: ' . $exception->getMessage());
}

fwrite(STDOUT, "SLA alerts created: {$alerts}; OLA alerts created: {$olaAlerts}\n");
