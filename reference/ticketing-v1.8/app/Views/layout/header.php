<?php
declare(strict_types=1);
/** @var string $title */
/** @var array $user */
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="سامانه پشتیبانی سازمان">
    <title><?= htmlspecialchars($title ?? 'سامانه پشتیبانی') ?> | سامانه پشتیبانی سازمان</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/custom.css">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="topbar-inner">
                <a class="brand" href="index.php">
                    <span class="brand-mark">پ</span>
                    <span>
                        <strong>سامانه پشتیبانی</strong>
                        <small>مرکز خدمات و پشتیبانی</small>
                    </span>
                </a>
                <?php if (!empty($user)): ?>
                <div class="header-tools">
                    <form class="global-search header-search" method="get" action="index.php">
                        <input type="hidden" name="page" value="search">
                        <input name="q" placeholder="جست‌وجوی تیکت، دارایی یا دانش‌نامه">
                    </form>
                    <a class="profile-shortcut" href="index.php?page=profile">
                        پروفایل: <?= htmlspecialchars($user['username']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($user)): ?>
            <nav class="navbar" aria-label="ناوبری اصلی">
                <ul class="menu">
                    <li><a href="index.php">داشبورد</a></li>
                    <li><a href="index.php?page=tickets">تیکت‌ها</a></li>
                    <li><a href="index.php?page=reports">گزارش‌ها</a></li>
                    <li><a href="index.php?page=settings">تنظیمات</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </header>
        <div class="content-wrap">
