<?php
declare(strict_types=1);
/** @var array $user */
/** @var array $ticket */
/** @var string $title */

include __DIR__ . '/../layout/header.php';
?>

<div class="ticket-detail">
    <div class="page-header">
        <h1><?= htmlspecialchars($ticket['subject']) ?></h1>
        <a href="index.php?page=tickets" class="btn">بازگشت به لیست</a>
    </div>
    
    <div class="ticket-meta">
        <span class="badge <?= htmlspecialchars($ticket['status']) ?>">
            وضعیت: <?= htmlspecialchars($ticket['status']) ?>
        </span>
        <span class="badge <?= htmlspecialchars($ticket['priority']) ?>">
            اولویت: <?= htmlspecialchars($ticket['priority']) ?>
        </span>
    </div>
    
    <div class="ticket-description">
        <h3>توضیحات</h3>
        <p><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
    </div>
    
    <div class="ticket-actions">
        <a href="index.php?page=tickets/<?= htmlspecialchars($ticket['id']) ?>/reply" class="btn btn-primary">پاسخ به تیکت</a>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
