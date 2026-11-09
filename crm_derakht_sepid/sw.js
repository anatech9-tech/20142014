/* Service Worker پایدار — صفحات PHP هرگز کش نمی‌شوند */
const SW_VERSION = 'derakhtsepid-v1';
const STATIC_CACHE = SW_VERSION + '-static';
const PRECACHE = [
  'assets/css/style.css',
  'assets/js/jalali_datepicker.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/images/logo-96.png',
  'manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => Promise.all(PRECACHE.map(url =>
        fetch(new Request(url, {cache:'reload'}))
          .then(response => { if (response.ok) return cache.put(url, response); })
          .catch(() => null)
      )))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== STATIC_CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // §fix v22 — صفحات PHP و همهٔ ناوبری‌ها: دیگر respondWith نداریم.
  // علت: درخواست‌های navigate حالت redirect:'manual' دارند؛ وقتی صفحه‌ای مثل
  // api/switch_workshop.php با 302 جواب می‌داد، پاسخِ ریدایرکت‌شده برای چنین
  // درخواستی ممنوع است («a redirected response was used for a request whose
  // redirect mode is not follow») و ناوبری شکست می‌خورد — کاربر به‌جای صفحه،
  // خطا/کد خام می‌دید و باید برمی‌گشت. حالا مرورگر خودش ناوبری را انجام می‌دهد
  // (ریدایرکت و نوار آدرس همیشه درست کار می‌کند) و تازگی صفحات با هدر
  // Cache-Control: no-store سمت سرور تضمین می‌شود.
  if (req.mode === 'navigate' || url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
    return;
  }

  // فایل‌های ظاهری و تصاویر: کش با fallback شبکه.
  event.respondWith(
    caches.match(req).then(cached => cached || fetch(req).then(response => {
      // پاسخ‌های ریدایرکت‌شده کش نمی‌شوند (بدنهٔ نهایی زیر URL اشتباه ذخیره می‌شود)
      if (response.ok && !response.redirected) {
        const copy=response.clone();
        caches.open(STATIC_CACHE).then(cache => cache.put(req,copy));
      }
      return response;
    }))
  );
});

self.addEventListener('push', event => {
  let data={title:'کارگاه',body:'اعلان جدید'};
  try { if(event.data) data=event.data.json(); } catch(e) {}
  event.waitUntil(self.registration.showNotification(data.title || 'کارگاه', {
    body:data.body || '', icon:'assets/icons/icon-192.png', badge:'assets/icons/icon-192.png', dir:'rtl', lang:'fa'
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list =>
    list.length ? list[0].focus() : self.clients.openWindow('index.php?page=dashboard')
  ));
});
