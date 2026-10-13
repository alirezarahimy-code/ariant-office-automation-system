<?php
declare(strict_types=1);
/** @var array $user */
/** @var string $title */

include __DIR__ . '/../layout/header.php';
?>

<div class="create-ticket">
    <h1>ثبت تیکت جدید</h1>
    
    <form method="post" action="index.php?page=tickets/create" class="form">
        <div class="form-group">
            <label for="subject">عنوان تیکت</label>
            <input type="text" id="subject" name="subject" required class="form-control">
        </div>
        
        <div class="form-group">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="5" required class="form-control"></textarea>
        </div>
        
        <div class="form-group">
            <label for="priority">اولویت</label>
            <select id="priority" name="priority" class="form-control">
                <option value="normal">عادی</option>
                <option value="urgent">فوری</option>
                <option value="critical">حیاتی</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="category">دسته‌بندی</label>
            <select id="category" name="category" class="form-control">
                <option value="">انتخاب کنید</option>
            </select>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">ثبت تیکت</button>
            <a href="index.php?page=tickets" class="btn">انصراف</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
