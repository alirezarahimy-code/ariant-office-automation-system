<?php
declare(strict_types=1);
/** @var array $user */
/** @var string $title */

// Use layout
include __DIR__ . '/../layout/header.php';
?>

<div class="dashboard">
    <h1>داشبورد</h1>
    <p>خوش آمدید، <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></p>
    
    <div class="dashboard-grid">
        <div class="card">
            <h3>تیکت‌های من</h3>
            <p class="count">۰</p>
            <a href="index.php?page=tickets" class="btn">مشاهده</a>
        </div>
        <div class="card">
            <h3>اعلان‌ها</h3>
            <p class="count">۰</p>
            <a href="index.php?page=notifications" class="btn">مشاهده</a>
        </div>
        <div class="card">
            <h3>گزارش‌ها</h3>
            <p class="count">۰</p>
            <a href="index.php?page=reports" class="btn">مشاهده</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
