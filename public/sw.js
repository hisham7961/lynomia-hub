/* Lynomia Hub — عامل الخدمة: عمل بلا اتصال واتصال ضعيف
   - الأصول الثابتة: من الكاش فوراً + تحديثٌ بالخلفية (stale-while-revalidate) —
     مع بصمة ?v=النسخة في روابط الأصول، فكل إصدارٍ رابطٌ جديد يفوّت الكاش القديم.
     كان «كاش أولاً» جامداً: المستخدم يرى الشكل القديم حتى يضغط Ctrl+Shift+R.
   - التنقل بين الصفحات: الشبكة أولاً بمهلة، وإلا آخر نسخة محفوظة، وإلا صفحة «بلا اتصال»
   - لا يخبئ أبداً: API، الملفات الخاصة، الأسرار، مراكز الإدارة */
var VER = 'hub-v2.337';   // رفعُ اسم الخبيئة يُسقط ما خُبِّئ بالمنطق المعطوب
var STATIC = ['/offline', '/css/app.css', '/css/fonts.css', '/js/app.js', '/js/htmx.min.js',
  // الجوهري من الخطوط يُخبأ مسبقاً (عربي + لاتيني)، وبقية الأطقم يخبئها مسار /fonts/ عند أول استعمال
  '/fonts/plexarabic-400-arabic.woff2', '/fonts/plexarabic-400-latin.woff2',
  '/fonts/plexarabic-500-arabic.woff2', '/fonts/plexarabic-500-latin.woff2',
  '/fonts/plexarabic-600-arabic.woff2', '/fonts/plexarabic-600-latin.woff2',
  '/fonts/plexarabic-700-arabic.woff2', '/fonts/plexarabic-700-latin.woff2',
  '/fonts/plexmono-400-latin.woff2', '/fonts/plexmono-500-latin.woff2',
  '/fonts/tajawal-400-arabic.woff2', '/fonts/tajawal-400-latin.woff2',
  '/fonts/tajawal-500-arabic.woff2', '/fonts/tajawal-500-latin.woff2',
  '/fonts/tajawal-700-arabic.woff2', '/fonts/tajawal-700-latin.woff2',
  '/fonts/tajawal-800-arabic.woff2', '/fonts/tajawal-800-latin.woff2'];
// /attachments/ ضمن الممنوع: معاينة PDF داخل iframe تُعد تنقلاً فكانت ستُخبأ — ملفات خاصة لا تُخبأ أبداً
var NEVER = ['/api/', '/files/', '/attachments/', '/m/vault', '/admin/', '/apps/quoteflow', '/jslog', '/logout'];

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

/**
 * **لا تخبئةَ لصفحةٍ مسجَّلةِ الدخول.**
 *
 * أُضيف هذا الحارسُ في v2.323 لكنّه وُضع في **فرع الأصول الثابتة** أعلاه —
 * و`/css/` و`/js/` و`/fonts/` لا تحمل وسمَ الجلسة أصلاً ولا تحمل بياناتِ
 * أحد. أمّا فرعُ التنقّل، وهو الذي وُصف العيبُ فيه حرفياً، فبقي يخبّئ **كلَّ**
 * استجابةٍ ناجحة: لوحةُ التحكم والرواتبُ والمالية والعقود تُقدَّم من الخبيئة
 * **بعد الخروج** ولمن يفتح المتصفّح بعده على جهازٍ مشترك. و`NEVER` تحمي
 * `/admin/` و`/m/vault` وحدهما.
 *
 * الخادمُ يَسِم استجاباتِ المسجَّلين بـ`X-Lyn-Auth: 1` (‏`SecurityHeaders`)،
 * فلا يُخبَّأ إلا ما لا يحملها — وما خُبِّئ قبل الدخول يُسقَط عند أوّل
 * استجابةٍ موسومة لنفس العنوان، وإلا بقيت نسخةٌ قديمةٌ تُقدَّم بعد الخروج.
 */
function keepOffline(req, res) {
  if (!res || !res.ok) return;
  var authed = res.headers && res.headers.get('X-Lyn-Auth') === '1';
  if (authed) { caches.open(VER).then(function (c) { c.delete(req); }); return; }
  var copy = res.clone();
  caches.open(VER).then(function (c) { c.put(req, copy); });
}


self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || never(url)) return;

  // أصول ثابتة: من الكاش فوراً + إعادة جلبٍ بالخلفية تُحدّث الكاش للزيارة التالية
  if (url.pathname.indexOf('/css/') === 0 || url.pathname.indexOf('/js/') === 0 || url.pathname.indexOf('/fonts/') === 0) {
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
        keepOffline(req, res);          // شاشةُ مسجَّلٍ لا تُخبَّأ — انظر أعلاه
        resolve(res);
      }).catch(function () { if (!done) { done = true; clearTimeout(t); fallback(); } });
    }));
  }
});
