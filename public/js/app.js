/* Lynomia Hub v2.2 — طبقة الذكاء والتفاعل */
(function () {
  'use strict';
  var Hub = window.Hub = {};
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  /* ── توست ورسائل ── */
  function flashInit() {
    $$('#flash .flash.ok').forEach(function (f) {
      if (f._t) return; f._t = 1;
      /* ٧ ثوانٍ لا ٣ — القراءة البطيئة ومكبّر الشاشة يحتاجان وقتاً، ورسائل الخطأ لا تختفي أصلاً */
      setTimeout(function () { f.classList.add('hide'); setTimeout(function () { f.remove(); }, 600); }, 7000);
    });
  }
  Hub.toast = function (msg, bad) {
    var d = document.createElement('div');
    d.className = 'flash ' + (bad ? 'bad' : 'ok'); d.textContent = msg;
    $('#flash').appendChild(d); flashInit();
  };

  /* ── النافذة المنبثقة ── */
  Hub.modal = function (url) {
    var m = $('#modal'); m.hidden = false; document.body.classList.add('lock');
    $('#modalbody').innerHTML = '<div class="sub" style="padding:30px;text-align:center">… جارٍ التحميل</div>';
    htmx.ajax('GET', url, { target: '#modalbody', swap: 'innerHTML' });
    return false;
  };
  Hub.closeModal = function () { $('#modal').hidden = true; $('#modalbody').innerHTML = ''; document.body.classList.remove('lock'); };

  /* بعد أي تبديل: نجاح الحفظ يُفرغ النموذج ⇒ أغلق النافذة */
  document.addEventListener('htmx:afterSwap', function () {
    var m = $('#modal');
    if (!m.hidden && !$('#modalbody form')) Hub.closeModal();
    enhance($('#modalbody'));
  });
  document.addEventListener('htmx:afterSettle', function () { flashInit(); });

  /* ── شريط التقدم العلوي ── */
  var tl = null;
  document.addEventListener('htmx:beforeRequest', function () { var b = $('#topload'); b.style.width = '68%'; b.style.opacity = 1; clearTimeout(tl); });
  document.addEventListener('htmx:afterSettle', function () { var b = $('#topload'); b.style.width = '100%'; tl = setTimeout(function () { b.style.opacity = 0; b.style.width = '0'; }, 350); });

  /* ── لوحة الأوامر Ctrl+K ── */
  var NAV = [];
  try { NAV = JSON.parse($('#navdata').textContent); } catch (e) {}
  /* أسماء الوحدات صارت قابلة للتخصيص (nav.names) فتصل إلى هنا كنص مستخدم —
     تُهرَّب قبل بناء innerHTML كي لا يُحقن وسم عبر تسمية بديلة */
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function palRender(q) {
    q = (q || '').trim();
    var list = $('#pallist'), out = '';
    NAV.filter(function (i) { return !q || i.t.indexOf(q) > -1; }).slice(0, 9).forEach(function (i) {
      out += '<div class="palitem"><a href="' + esc(i.u) + '">' + esc(i.t) + '</a>' +
             (i.n ? '<button type="button" class="btn ghost xs" onclick="Hub.closeP();Hub.modal(\'' + esc(i.n) + '\')">＋ جديد</button>' : '') + '</div>';
    });
    list.innerHTML = out || '<div class="palitem sub">لا نتائج</div>';
  }
  Hub.palette = function () { $('#palette').hidden = false; var i = $('#palq'); i.value = ''; palRender(''); i.focus(); };
  Hub.closeP = function () { $('#palette').hidden = true; };
  document.addEventListener('input', function (e) { if (e.target.id === 'palq') palRender(e.target.value); });
  document.addEventListener('keydown', function (e) {
    if (e.target.id === 'palq' && e.key === 'Enter') { var a = $('#pallist a'); if (a) location.href = a.href; }
  });

  /* ── الاختصارات ── */
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName);
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); Hub.palette(); return; }
    if (e.key === 'Escape') { Hub.closeP(); Hub.closeModal(); return; }
    if (typing) return;
    if (e.key === 'n') { var b = $('#newbtn'); if (b) { e.preventDefault(); b.click(); } }
  });

  /* ── فلترة القوائم الطويلة داخل النماذج ── */
  function enhance(root) {
    $$('select.inp:not([multiple]):not([data-cf])', root || document).forEach(function (sel) {
      if (sel.options.length < 14) return;
      sel.dataset.cf = 1;
      var f = document.createElement('input');
      f.className = 'inp cfilter'; f.placeholder = '⌕ صفِّ الخيارات…';
      sel.parentNode.insertBefore(f, sel);
      f.addEventListener('input', function () {
        var t = f.value.trim();
        Array.prototype.forEach.call(sel.options, function (o) { o.hidden = t && o.text.indexOf(t) === -1 && o.value !== ''; });
      });
    });
  }

  /* ── نسخ بالنقر في صفحة العرض ── */
  document.addEventListener('click', function (e) {
    var m = e.target.closest('.detail .mono');
    if (!m || e.target.closest('a')) return;
    var t = m.textContent.trim(); if (!t || t === '—' || t.indexOf('•') === 0) return;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { Hub.toast('نُسخ ✓'); }, function () {});
  });

  /* ── آخر ما فتحت ── */
  var KEY = 'lyn_recent';
  function recents() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  var mk = $('[data-recent]');
  if (mk) {
    var list = recents().filter(function (r) { return r.u !== location.pathname; });
    list.unshift({ t: mk.dataset.title, u: location.pathname });
    localStorage.setItem(KEY, JSON.stringify(list.slice(0, 7)));
  }
  var rb = $('#recentbox');
  if (rb) {
    var rs = recents();
    if (rs.length) { rb.hidden = false; $('#recentlist').innerHTML = rs.map(function (r) { return '<a href="' + r.u + '">' + r.t.replace(/</g, '&lt;') + '</a>'; }).join(''); }
  }

  flashInit(); enhance();
})();

/* ═ v2.3 ═ */
(function () {
  'use strict';
  var Hub = window.Hub;
  Hub.theme = function () {
    var d = document.documentElement.dataset.theme === 'dark';
    if (d) { delete document.documentElement.dataset.theme; localStorage.setItem('lyn_theme', 'light'); }
    else { document.documentElement.dataset.theme = 'dark'; localStorage.setItem('lyn_theme', 'dark'); }
  };
  var kb = document.querySelector('[data-kanban]');
  if (kb && kb.dataset.can === '1') {
    var dragging = null;
    kb.addEventListener('dragstart', function (e) {
      var c = e.target.closest('.kcard'); if (!c) return;
      dragging = c; c.classList.add('drag');
      e.dataTransfer.effectAllowed = 'move';
    });
    kb.addEventListener('dragend', function () { if (dragging) dragging.classList.remove('drag'); dragging = null; });
    kb.addEventListener('dragover', function (e) {
      var col = e.target.closest('.kcol'); if (!col || !dragging) return;
      e.preventDefault(); col.classList.add('over');
    });
    kb.addEventListener('dragleave', function (e) {
      var col = e.target.closest('.kcol'); if (col) col.classList.remove('over');
    });
    kb.addEventListener('drop', function (e) {
      var col = e.target.closest('.kcol'); if (!col || !dragging) return;
      e.preventDefault(); col.classList.remove('over');
      var card = dragging, from = card.closest('.kcol'), st = col.dataset.status;
      if (from === col) return;
      var url = kb.dataset.url.replace('__ID__', card.dataset.id);
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
        body: JSON.stringify({ status: st })
      }).then(function (r) {
        if (!r.ok) throw 0;
        col.querySelector('.kbody').prepend(card);
        [from, col].forEach(function (c) { c.querySelector('.kcount').textContent = c.querySelectorAll('.kcard').length; });
        Hub.toast('نُقل إلى «' + st + '» ✓');
      }).catch(function () { Hub.toast('تعذّر النقل — تحقق من الاتصال', 1); });
    });
  }
})();

/* ═ v2.4 ═ */
(function(){document.addEventListener('click',function(e){if(!e.target.closest('.bell')){var b=document.getElementById('bellbox');if(b)b.innerHTML='';}});})();

/* ═ v2.4 — البحث الشامل ═ */
(function () {
  'use strict';
  var q = document.getElementById('gq');
  if (!q) return;
  document.addEventListener('keydown', function (e) {
    if (e.target === q && e.key === 'Enter') {
      var t = q.value.trim();
      if (t.length >= 2) location.href = q.dataset.url + '?q=' + encodeURIComponent(t);
      return;
    }
    if (e.target === q && e.key === 'Escape') { document.getElementById('gsr').innerHTML = ''; q.blur(); return; }
    if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
    if (e.key === '/') { e.preventDefault(); q.focus(); }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.gsearch')) { var b = document.getElementById('gsr'); if (b) b.innerHTML = ''; }
  });
})();

/* ═ v2.24 — حماية الإرسال المزدوج + حالة تحميل ═ */
(function () {
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement) || f.hasAttribute('data-resubmit')) return;
    if (f.dataset.busy) { e.preventDefault(); return; }
    f.dataset.busy = '1';
    var btns = f.querySelectorAll('button[type="submit"],button:not([type])');
    setTimeout(function () { btns.forEach(function (b) { b.disabled = true; b.classList.add('busy'); }); }, 0);
    setTimeout(function () {           /* فك القفل احتياطاً عند فشل الشبكة */
      delete f.dataset.busy;
      btns.forEach(function (b) { b.disabled = false; b.classList.remove('busy'); });
    }, 8000);
  }, true);
  window.addEventListener('pageshow', function () {   /* عودة بزر الرجوع (bfcache) */
    document.querySelectorAll('form[data-busy]').forEach(function (f) { delete f.dataset.busy; });
    document.querySelectorAll('button.busy').forEach(function (b) { b.disabled = false; b.classList.remove('busy'); });
  });
})();

/* ═ v2.26 — التقاط أخطاء المتصفح لمركز الأخطاء ═ */
(function () {
  var sent = {};
  function ship(msg, src, line) {
    var key = msg + '|' + src + '|' + line;
    if (sent[key]) return; sent[key] = 1;                 /* مرة لكل جلسة صفحة */
    try {
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (!meta || !navigator.sendBeacon) return;
      var fd = new FormData();
      fd.append('_token', meta.content);
      fd.append('message', String(msg).slice(0, 400));
      fd.append('source', String(src || '').slice(0, 250));
      fd.append('line', line || 0);
      navigator.sendBeacon('/jslog', fd);
    } catch (e) {}
  }
  window.addEventListener('error', function (e) { ship(e.message, e.filename, e.lineno); });
  window.addEventListener('unhandledrejection', function (e) {
    ship('Promise: ' + (e.reason && e.reason.message ? e.reason.message : e.reason), location.pathname, 0);
  });
})();

/* ═ v2.33 — PWA: عامل الخدمة + مسودات النماذج المحلية ═ */
(function () {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  }

  // مسودات: أي نموذج data-draft يُحفظ محلياً أثناء الكتابة ويُسترجع عند العودة
  var form = document.querySelector('form[data-draft]');
  if (!form) return;
  var key = 'lyn_draft:' + form.getAttribute('data-draft');

  function fields() {
    return Array.prototype.filter.call(form.elements, function (el) {
      return el.name && el.name.indexOf('_') !== 0 && ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(el.tagName) >= 0
        && ['file', 'password', 'hidden', 'submit', 'button'].indexOf(el.type) < 0;
    });
  }

  var t;
  form.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      var d = {};
      fields().forEach(function (el) {
        if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) d[el.name] = el.value; }
        else if (el.value !== '') d[el.name] = el.value;
      });
      try { localStorage.setItem(key, JSON.stringify({ at: Date.now(), d: d })); } catch (e) {}
    }, 700);
  });

  form.addEventListener('submit', function () { try { localStorage.removeItem(key); } catch (e) {} });

  var saved;
  try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}
  if (!saved || !saved.d || !Object.keys(saved.d).length) return;
  if (Date.now() - saved.at > 7 * 86400000) { localStorage.removeItem(key); return; }

  var bar = document.createElement('div');
  bar.className = 'flash wn';
  bar.style.display = 'flex'; bar.style.gap = '10px'; bar.style.alignItems = 'center';
  var mins = Math.round((Date.now() - saved.at) / 60000);
  bar.innerHTML = '<span>📝 وجدنا مسودة غير محفوظة لهذا النموذج (' +
    (mins < 60 ? 'قبل ' + (mins || 1) + ' دقيقة' : 'قبل ' + Math.round(mins / 60) + ' ساعة') + ')</span>' +
    '<button type="button" class="btn sm" data-restore>استرجاعها</button>' +
    '<button type="button" class="btn ghost sm" data-discard>تجاهل</button>';
  form.parentNode.insertBefore(bar, form);

  bar.querySelector('[data-restore]').addEventListener('click', function () {
    fields().forEach(function (el) {
      if (!(el.name in saved.d)) return;
      if (el.type === 'checkbox' || el.type === 'radio') el.checked = (el.value === saved.d[el.name]);
      else el.value = saved.d[el.name];
    });
    bar.remove();
  });
  bar.querySelector('[data-discard]').addEventListener('click', function () {
    try { localStorage.removeItem(key); } catch (e) {}
    bar.remove();
  });
})();

/* باني اللوحات: إعادة ترتيب الودجات سحباً، وبأزرار أعلى/أسفل لمن لا يسحب.
   الترتيب يُقرأ من ترتيب حقول order[] في الصفحة، فلا حقل موضعٍ يُحرَّر بيد أحد. */
(function () {
  var list = document.getElementById('wlist');
  if (!list) return;

  var dragged = null;

  function items() {
    return Array.prototype.slice.call(list.querySelectorAll('.witem'));
  }

  function markDirty() {
    var f = document.getElementById('layoutform');
    if (f) f.classList.add('dirty');
  }

  list.addEventListener('dragstart', function (e) {
    var li = e.target.closest ? e.target.closest('.witem') : null;
    if (!li) return;
    dragged = li;
    li.classList.add('drag');
    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (x) {}
  });

  list.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('drag');
    items().forEach(function (i) { i.classList.remove('over'); });
    dragged = null;
  });

  list.addEventListener('dragover', function (e) {
    if (!dragged) return;
    e.preventDefault();
    var li = e.target.closest ? e.target.closest('.witem') : null;
    if (!li || li === dragged) return;
    items().forEach(function (i) { if (i !== li) i.classList.remove('over'); });
    li.classList.add('over');

    // النصف الأعلى ⇒ قبله، والأسفل ⇒ بعده
    var r = li.getBoundingClientRect();
    var before = (e.clientY - r.top) < r.height / 2;
    list.insertBefore(dragged, before ? li : li.nextSibling);
  });

  list.addEventListener('drop', function (e) {
    e.preventDefault();
    items().forEach(function (i) { i.classList.remove('over'); });
    markDirty();
  });

  // بديلٌ يعمل باللمس ولوحة المفاتيح — السحب وحده يُقصي من لا يستطيعه
  list.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-move]') : null;
    if (!b) return;
    var li = b.closest('.witem');
    if (!li) return;
    if (b.getAttribute('data-move') === 'up' && li.previousElementSibling) {
      list.insertBefore(li, li.previousElementSibling);
    } else if (b.getAttribute('data-move') === 'down' && li.nextElementSibling) {
      list.insertBefore(li.nextElementSibling, li);
    }
    markDirty();
    b.focus();
  });

  list.addEventListener('change', markDirty);
})();
