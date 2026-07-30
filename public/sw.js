/* Lynomia Hub — عامل الخدمة: عمل بلا اتصال واتصال ضعيف
   - الأصول الثابتة: من الكاش فوراً + تحديثٌ بالخلفية (stale-while-revalidate) —
     مع بصمة ?v=النسخة في روابط الأصول، فكل إصدارٍ رابطٌ جديد يفوّت الكاش القديم.
     كان «كاش أولاً» جامداً: المستخدم يرى الشكل القديم حتى يضغط Ctrl+Shift+R.
   - التنقل بين الصفحات: الشبكة أولاً بمهلة، وإلا آخر نسخة محفوظة، وإلا صفحة «بلا اتصال»
   - لا يخبئ أبداً: API، الملفات الخاصة، الأسرار، مراكز الإدارة */
var VER = 'hub-v2.99';
var STATIC = ['/offline', '/css/app.css', '/js/app.js', '/js/htmx.min.js'];
var NEVER = ['/api/', '/files/', '/m/vault', '/admin/', '/apps/quoteflow', '/jslog', '/logout'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VER).then(function (c) { return c.addAll(STATIC); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VER; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function never(url) {
  return NEVER.some(function (p) { return url.pathname.indexOf(p) === 0; });
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || never(url)) return;

  // أصول ثابتة: من الكاش فوراً + إعادة جلبٍ بالخلفية تُحدّث الكاش للزيارة التالية
  if (url.pathname.indexOf('/css/') === 0 || url.pathname.indexOf('/js/') === 0) {
    e.respondWith(caches.match(req).then(function (hit) {
      var refresh = fetch(req).then(function (res) {
        if (res && res.ok) {
          var copy = res.clone();
          caches.open(VER).then(function (c) { c.put(req, copy); });
        }
        return res;
      }).catch(function () { return hit; });
      return hit || refresh;
    }));
    return;
  }

  // تنقل: شبكة أولاً بمهلة ٦ ثوانٍ ← آخر نسخة ← صفحة بلا اتصال
  if (req.mode === 'navigate') {
    e.respondWith(new Promise(function (resolve) {
      var done = false;
      var t = setTimeout(function () { if (!done) { done = true; fallback(); } }, 6000);
      function fallback() {
        caches.match(req).then(function (hit) {
          resolve(hit || caches.match('/offline'));
        });
      }
      fetch(req).then(function (res) {
        if (done) return;
        done = true; clearTimeout(t);
        if (res && res.ok) {
          var copy = res.clone();
          caches.open(VER).then(function (c) { c.put(req, copy); });
        }
        resolve(res);
      }).catch(function () { if (!done) { done = true; clearTimeout(t); fallback(); } });
    }));
  }
});
