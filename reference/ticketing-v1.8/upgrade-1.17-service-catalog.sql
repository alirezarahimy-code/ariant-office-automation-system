-- Upgrade 1.17: کاتالوگ خدمت با گزینه‌های دقیق‌تر و ساده‌سازی فرم ثبت تیکت
-- فرم ثبت تیکت اکنون یک انتخاب «نوع خدمت» دارد؛ حوزه خدمت، دسته‌بندی، اولویت و نوع درخواست
-- به‌صورت خودکار از روی همین انتخاب تعیین می‌شوند. اجرای این فایل اختیاری است (نصب تازه از install.php پر می‌شود).

INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
VALUES
('خرابی یا کندی رایانه', 'IT-COMPUTER', 'کندی، هنگ کردن یا خطای عملکرد رایانه.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('رایانه روشن نمی‌شود', 'IT-BOOT', 'عدم روشن شدن یا بوت نشدن سیستم.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('صفحه آبی یا ری‌استارت مکرر', 'IT-BLUE', 'خطای صفحه آبی، ری‌استارت یا خاموشی ناگهانی.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('خرابی قطعه سخت‌افزاری', 'IT-HARDWARE', 'مشکوک به خرابی هارد، RAM، پاور یا مادربرد.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('نصب یا بازیابی ویندوز', 'IT-PROFILE', 'نصب مجدد یا بازیابی سیستم‌عامل.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('نصب درایور تجهیزات', 'IT-DRIVER', 'نصب درایور پرینتر، اسکنر یا سایر تجهیزات.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('نصب نرم‌افزار سازمانی', 'IT-SOFTWARE', 'نصب نرم‌افزارهای مورد تأیید سازمان.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('خطای نرم‌افزار نصب‌شده', 'IT-SOFT-ERROR', 'خطا، بسته شدن یا کار نکردن نرم‌افزار.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('مشکل پرینتر یا اسکنر', 'IT-PRINTER', 'چاپ نکردن، خطای چاپ یا اسکن.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'incident'),
('کیبورد، ماوس و مانیتور', 'IT-PERIPHERAL', 'خرابی یا تعویض تجهیزات جانبی.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'incident'),
('اختلال شبکه یا اینترنت', 'IT-NET', 'قطعی، کندی یا خطای اتصال شبکه و اینترنت.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'incident'),
('اشتراک فایل و پرینتر شبکه', 'IT-SHARE', 'دسترسی به فایل یا پرینتر اشتراکی.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('حساب کاربری و رمز عبور', 'IT-ACCOUNT', 'ساخت حساب، تغییر یا بازیابی رمز.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'request'),
('دسترسی به سامانه داخلی', 'IT-ACCESS', 'دسترسی یا اصلاح سطح دسترسی سامانه‌ها.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'request'),
('پست الکترونیک سازمانی', 'IT-EMAIL', 'ساخت، بازیابی یا خطای ایمیل سازمانی.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'request'),
('بازیابی فایل یا پشتیبان‌گیری', 'IT-BACKUP', 'بازیابی اطلاعات پاک‌شده یا پشتیبان‌گیری.', 'it', 'urgent', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('نصب و راه‌اندازی رایانه جدید', 'IT-INSTALL-PC', 'تحویل و راه‌اندازی سیستم جدید.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('جابه‌جایی رایانه', 'IT-MOVE', 'انتقال سیستم یا تغییر محل استقرار.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('ثبت یا اصلاح شناسنامه فنی', 'IT-INVENTORY', 'تکمیل شناسنامه و مشخصات فنی سیستم.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 1, 'request'),
('سایر خدمات فناوری اطلاعات', 'IT-OTHER', 'موارد دیگر مرتبط با فناوری اطلاعات.', 'it', 'normal', (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), 0, 'request'),
('تجهیزات و فضای اداری', 'SUP-OFFICE', 'رسیدگی به تجهیزات عمومی یا فضای کار.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('برق و تأسیسات', 'SUP-ELEC', 'خرابی برق، پریز، روشنایی یا تأسیسات.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'incident'),
('سرمایش و گرمایش', 'SUP-COOLING', 'خرابی کولر، اسپلیت یا سیستم گرمایش.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'incident'),
('اثاثیه و مبلمان اداری', 'SUP-FURN', 'تعمیر یا جابه‌جایی میز و صندلی.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('نظافت و خدمات', 'SUP-CLEAN', 'درخواست نظافت یا خدمات عمومی.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('لوازم‌التحریر و ملزومات', 'SUP-STATIONERY', 'درخواست لوازم‌التحریر و ملزومات اداری.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('اصلاح یا تأیید رکورد رسانه', 'SUP-CDDVD', 'درخواست اصلاح رکورد CD/DVD.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('خدمات عمومی سازمان', 'SUP-GENERAL', 'سایر خدمات عمومی داخلی سازمان.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request'),
('سایر خدمات پشتیبانی', 'SUP-OTHER', 'موارد دیگر مرتبط با پشتیبانی.', 'support', 'normal', (SELECT id FROM handling_units WHERE code = 'support' LIMIT 1), 0, 'request')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    service_group = VALUES(service_group),
    default_priority = VALUES(default_priority),
    handling_unit_id = VALUES(handling_unit_id),
    requires_asset = VALUES(requires_asset),
    default_ticket_type = VALUES(default_ticket_type),
    is_active = 1;
