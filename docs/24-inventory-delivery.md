# ۲۴ — زیرسامانه انبار: مطالعه شکاف و تحویل

> وضعیت: **نرم‌افزار کامل و اجراشده** — همه قابلیت‌ها با کلید روشن/خاموش (۲۷ کلید `inventory.*`)؛
> مرجع رفتاری: سپیدار، راهکاران، اسپاد، فکرگستر (برآیند).
> روش کار مانند دبیرخانه: نیازسنجی کامل → ممیزی شکاف → ساخت بدون وقفه.

## ۱. نتیجه ممیزی (چک‌لیست ~۷۰ مورد)

| گروه | تعداد | پیش از کار | پس از کار |
|------|-------|-----------|-----------|
| اطلاعات پایه | ۱۶ | ⚠/❌ | ✅ همه |
| اسناد | ۱۶ | ⚠/❌ | ✅ همه |
| انبارگردانی | ۷ | ⚠ | ✅ همه |
| قیمت‌گذاری | ۷ | ❌ | ✅ همه |
| گزارش‌ها | ۱۲ | ⚠/❌ | ✅ همه |
| دسترسی | ۴ | ❌ | ✅ همه |
| ابزار/یکپارچه | ۸ | ⚠/❌ | ✅ نرم‌افزاری |

سه شکاف مادر (پیش از این تحویل):

1. **سند چندردیفی** — `StockMove` تک‌قلمی بود.
2. **کارت کالای حرفه‌ای** — گروه/مشخصات/تصویر/بارکد/چندواحدی/سریال/انقضا/محل استقرار/نقطه سفارش نبود.
3. **انبارگردانی حرفه‌ای** — تگ/چندمرحله/اکسل/مغایرت نبود.

## ۲. مدل‌های تحویل‌شده

| مدل | نقش |
|-----|-----|
| `StockDocument` + `StockDocumentLine` | سند چندردیفی (سربرگ+ردیف): رسید/حواله/انتقال/تعدیل؛ وضعیت موقت/قطعی/ابطال‌شده؛ شماره سالانه؛ سند برگشتی (`reverses`) |
| `StockDocSequence` | شمارنده شماره‌گذاری سالانه (رس-1404-0001) |
| `StockMove` | رویداد انبار (کاردکس) — با پیوند document/line؛ همه محاسبات موجودی از اینجاست |
| `ItemGroup` | گروه درختی با `path` (زیردرخت) |
| `Item` (ارتقا) | + گروه، شرح، تصویر، بارکد، خدماتی، فعال، حداکثر/نقطه سفارش، سریال/انقضا، روش قیمت‌گذاری (میانگین/FIFO/LIFO)، محل استقرار، آخرین قیمت خرید |
| `ItemUnit` | چندواحدی با ضریب تبدیل به واحد پایه |
| `ItemSpec` | مشخصات فنی |
| `SerialNumber` | سریال (ورود با رسید/خروج با حواله) |
| `WarehouseLocation` | محل استقرار (قفسه/راهرو) |
| `Warehouse.users` | انباردار هر انبار (دسترسی به تفکیک کاربر) |
| `StockLayer` | لایه‌های هزینه FIFO/LIFO |
| `PriceList` + `PriceListItem` | لیست قیمت (چندقیمتی) |
| `StockCount`/`StockCountLine` (ارتقا) | شمارش دومرحله + موجودی دفتری snapshot |
| `CountTag` | تگ چاپی با بارکد |
| `InventoryPeriod` | دوره مالی انبار (باز/بسته) |

مهاجرت داده `0005_wrap_legacy_moves`: رویدادهای قدیمی بدون سند → سند قطعی گروه‌بندی‌شده (ردیابی کامل).

## ۳. سرویس‌ها (نقطه ورود یکتا)

`create_document` / `add_line` / `confirm_document` / `cancel_document` / `create_return_document` / `next_document_number`
→ لایه سازگاری `receive` / `issue` / `transfer` (تک‌قلمی، همان رفتار قبلی روی اسناد).

قیمت‌گذاری: `weighted_avg_price` / `fifo_value` / `inventory_value` / `set_item_price` / `apply_batch_pricing`.
انبارگردانی: `apply_count` (سند اصلاحی خودکار) / `generate_count_tags` / `import_count_rows`.
گزارش: `item_card` / `movement_report` / `low_stock_items` / `no_movement_items` / `expiry_report` / `serial_report` / `export_accounting_rows` / `visible_warehouses`.

## ۴. نماها

`/inventory/` — کالاها | `/inventory/dashboard/` | `/inventory/docs/` (فهرست+فیلتر) | `/inventory/docs/new/` (چندردیفی)
`/inventory/docs/<id>/` (قطعی/ابطال/برگشتی/چاپ) | `/inventory/count/` (تگ/مرحله/اکسل/اعمال)
`/inventory/prices/` | `/inventory/reports/{no-movement,expiry,serials}/` | `/inventory/labels/` (برچسب بارکد)
`/inventory/warehouses/` (انبارداران) | `/inventory/periods/` | خروجی‌های `.xlsx` (موجودی/ریزگردش/حسابداری/قالب شمارش)

## ۵. کلیدهای قابلیت (۲۷)

`documents, doc_numbering, doc_temp, doc_cancel, doc_return, item_groups, item_specs, item_image, item_barcode, multi_unit, serial_expiry, locations, min_max, price_lists, fifo, batch_pricing, count_tags, count_stages, count_excel, count_auto_docs, warehouse_users, low_stock_alert, no_movement, excel_reports, barcode_labels, dashboard, financial_period`

قانون: اجزا همیشه هستند؛ سازمان هر کدام را روشن/خاموش می‌کند (غیرفعال ≫ نبودن).

## ۶. دسترسی‌ها

- `inventory.manage_inventory` — مدیریت انبار (ثبت/قطعی/ابطال/انبارگردانی)
- `inventory.view_inventory_value` — مشاهده ارزش ریالی (تفکیک تعدادی/ریالی مانند سپیدار)
- انباردار هر انبار: `Warehouse.users` خالی = بدون محدودیت؛ پر = فقط همان کاربران (+ مدیران)

## ۷. موارد زیرساختی (باقی‌مانده مانند فاکس‌سرور)

- اتصال مستقیم ماژول‌های بومی بازرگانی/حسابداری (الان: خروجی اکسل اسناد = پل نرم)
- اسکنر/چاپگر لیبل سخت‌افزاری (نرم‌افزار برچسب Code39 آماده است)
- موبایل انبار اختصاصی (PWA + ریسپانسیو موجود)

## ۸. تأیید

- ۸۷+۳ آزمون اختصاصی انبار + مجموعه کامل سامانه: **۳۹۸ آزمون سبز**
- اجرای زنده با داده نمایشی (`seed_inventory_demo`) و ورود خودکار دمو
