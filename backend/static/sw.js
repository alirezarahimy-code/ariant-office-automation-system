// سرویس‌ورکر PWA — کش ساده پوسته برای کار آفلاین پایه
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { self.clients.claim(); });
