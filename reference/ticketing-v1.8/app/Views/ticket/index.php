<?php
declare(strict_types=1);
/** @var array $user */
/** @var string $title */

include __DIR__ . '/../layout/header.php';
?>

<div class="tickets">
    <div class="page-header">
        <h1>تیکت‌ها</h1>
        <a href="index.php?page=new-ticket" class="btn btn-primary">ثبت تیکت جدید</a>
    </div>
    
    <div class="ticket-filters">
        <form method="get" action="index.php">
            <input type="hidden" name="page" value="tickets">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="new">جدید</option>
                <option value="open">باز</option>
                <option value="closed">بسته شده</option>
            </select>
            <select name="priority">
                <option value="">همه اولویت‌ها</option>
                <option value="low">کم</option>
                <option value="medium">متوسط</option>
                <option value="high">زیاد</option>
            </select>
            <button type="submit" class="btn">فیلتر</button>
        </form>
    </div>
    
    <div class="ticket-list">
        <p class="empty-state">لیست تیکت‌ها اینجا نمایش داده می‌شود.</p>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
