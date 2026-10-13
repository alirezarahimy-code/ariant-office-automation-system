<?php
declare(strict_types=1);
/** @var array $user */
/** @var array $ticket */
/** @var string $title */

include __DIR__ . '/../layout/header.php';
?>

<div class="ticket-reply">
    <h1>پاسخ به تیکت #<?= htmlspecialchars($ticket['id']) ?></h1>
    
    <div class="ticket-info">
        <h2><?= htmlspecialchars($ticket['subject']) ?></h2>
        <p><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
    </div>
    
    <form method="post" action="index.php?page=tickets/<?= htmlspecialchars($ticket['id']) ?>/reply" class="form">
        <div class="form-group">
            <label for="reply">پاسخ شما</label>
            <textarea id="reply" name="reply" rows="5" required class="form-control"></textarea>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">ارسال پاسخ</button>
            <a href="index.php?page=tickets/<?= htmlspecialchars($ticket['id']) ?>" class="btn">انصراف</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
