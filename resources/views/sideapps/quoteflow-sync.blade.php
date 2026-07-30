<script>
/* غلاف مزامنة الخادم — يُحقن بعد سكربت التطبيق فيلتفّ على saveAll العالمية.
   كل حفظٍ يُدفع للخادم مؤجَّلاً؛ والمغادرة تدفع فوراً (Beacon). الحالة الفارغة
   (زر التصفير في التطبيق يمسح المفاتيح ثم يعيد التحميل) تمسح نسخة الخادم أيضاً. */
(function () {
  var KEYS = @json($keys);
  var URL = @json($url), TOKEN = @json($token);
  var dirty = false, timer = null;

  function collect() {
    var d = {};
    KEYS.forEach(function (k) { var v = localStorage.getItem(k); if (v !== null) d[k] = v; });
    return d;
  }

  function badge(ok) {
    var b = document.getElementById('qfsync');
    if (!b) {
      b = document.createElement('div'); b.id = 'qfsync';
      b.style.cssText = 'position:fixed;bottom:10px;inset-inline-start:10px;z-index:9999;font-size:11px;' +
        'padding:4px 10px;border-radius:99px;font-family:inherit;box-shadow:0 2px 8px rgba(0,0,0,.15);transition:opacity .4s';
      document.body.appendChild(b);
    }
    b.textContent = ok ? '☁️ محفوظ على الخادم' : '⚠️ تعذّر الحفظ — أعد المحاولة';
    b.style.background = ok ? '#0E7C66' : '#B3261E'; b.style.color = '#fff'; b.style.opacity = '1';
    if (ok) setTimeout(function () { b.style.opacity = '0'; }, 1800);
  }

  function push() {
    dirty = false;
    fetch(URL, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN },
      body: JSON.stringify({ data: collect() })
    }).then(function (r) { if (!r.ok) throw 0; badge(true); })
      .catch(function () { dirty = true; badge(false); });
  }

  var orig = window.saveAll;
  if (typeof orig === 'function') {
    window.saveAll = function () {
      var out = orig.apply(this, arguments);
      dirty = true;
      clearTimeout(timer); timer = setTimeout(push, 900);
      return out;
    };
  }

  // المغادرة أو إخفاء التبويب: دفعٌ فوري — يشمل حالة التصفير (خريطة فارغة تمسح الخادم)
  function flush() {
    var payload = JSON.stringify({ _token: TOKEN, data: collect() });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(URL, new Blob([payload], { type: 'application/json' }));
    }
  }
  window.addEventListener('pagehide', function () { if (dirty) flush(); });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden' && dirty) flush();
  });
  // زر التصفير يمسح المفاتيح ثم يعيد التحميل فوراً — نلتقطه لحظة المغادرة
  window.addEventListener('pagehide', function () {
    if (Object.keys(collect()).length === 0) flush();
  });
})();
</script>
