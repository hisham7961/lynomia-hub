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

  /* ── الاختصارات ── */
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName);
    // Ctrl+K صار يركز البحث الشامل — طريق واحد للوصول لكل شيء
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); var g = $('#gq'); if (g) g.focus(); return; }
    if (e.key === 'Escape') { Hub.closeModal(); return; }
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

/* ═ v2.4 → v2.112 — البحث الشامل بوصفه لوحة أوامر: Ctrl+K و/ للفتح، ↑↓ للتنقل، Enter للتنفيذ ═ */
(function () {
  'use strict';
  var q = document.getElementById('gq');
  if (!q) return;
  var box = function () { return document.getElementById('gsr'); };

  function items() { return box() ? [].slice.call(box().querySelectorAll('a.gitem')) : []; }
  function selected() { return box() ? box().querySelector('a.gitem.sel') : null; }
  function move(dir) {
    var list = items();
    if (!list.length) return;
    var cur = selected(), i = cur ? list.indexOf(cur) : -1;
    if (cur) cur.classList.remove('sel');
    var next = list[(i + dir + list.length) % list.length];
    next.classList.add('sel');
    next.scrollIntoView({ block: 'nearest' });
    q.setAttribute('aria-activedescendant', next.id || (next.id = 'gi' + list.indexOf(next)));
  }
  function close() { if (box()) box().innerHTML = ''; q.setAttribute('aria-expanded', 'false'); }

  document.body.addEventListener('htmx:afterSwap', function (e) {
    if (e.target && e.target.id === 'gsr') q.setAttribute('aria-expanded', box().innerHTML.trim() ? 'true' : 'false');
  });

  document.addEventListener('keydown', function (e) {
    if (e.target === q) {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
      if (e.key === 'Enter') {
        var sel = selected();
        if (sel) { location.href = sel.href; return; }
        var t = q.value.trim();
        if (t.length >= 2) location.href = q.dataset.url + '?q=' + encodeURIComponent(t);
        return;
      }
      if (e.key === 'Escape') { close(); q.blur(); return; }
    }
    /* Ctrl+K / ⌘K يعمل من أي مكان — حتى داخل الحقول (عادة راسخة من Slack وLinear وNotion) */
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); q.focus(); q.select(); return; }
    if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
    if (e.key === '/') { e.preventDefault(); q.focus(); q.select(); }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.gsearch')) close();
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

/* ═ القائمة الجانبية: تذكُّر ما فتحتَه وأين كنت — فلا «يختفي المكان» بعد التنقل ═ */
(function () {
  var nav = document.querySelector('.sidebar');
  if (!nav) return;
  var KEY = 'lyn_nav_open', SK = 'lyn_nav_scroll';
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {}

  nav.querySelectorAll('details[data-nav]').forEach(function (d) {
    var k = d.dataset.nav;
    // المحفوظ يُطبَّق — إلا أن المجموعة النشطة (فيها صفحتك الحالية) لا تُغلق أبداً
    if (saved[k] === 1) d.open = true;
    if (saved[k] === 0 && !d.classList.contains('act')) d.open = false;
    d.addEventListener('toggle', function () {
      saved[k] = d.open ? 1 : 0;
      try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
    });
  });

  // موضع التمرير يعود كما تركته، ثم يُضمن ظهور العنصر النشط في مرمى العين
  var sc = parseInt(sessionStorage.getItem(SK) || '-1', 10);
  if (sc >= 0) nav.scrollTop = sc;
  var on = nav.querySelector('.ni.on');
  if (on) {
    var r = on.getBoundingClientRect(), nr = nav.getBoundingClientRect();
    if (r.top < nr.top + 40 || r.bottom > nr.bottom - 10) on.scrollIntoView({ block: 'center' });
  }
  nav.addEventListener('scroll', function () {
    try { sessionStorage.setItem(SK, String(nav.scrollTop)); } catch (e) {}
  }, { passive: true });
})();

/* ═ التأكيد داخل الصفحة: ضغطتان على الزر نفسه — لا منبثقة متصفح بعد اليوم ═
   الضغطة الأولى تحوّل الزر لسؤال تحذيري ٦ ثوانٍ، والثانية خلالها تنفّذ. */
(function () {
  var ARM_MS = 6000;

  function disarm(btn) {
    if (!btn.dataset.armed) return;
    delete btn.dataset.armed;
    btn.classList.remove('confirming');
    if (btn.dataset.oldHtml !== undefined) { btn.innerHTML = btn.dataset.oldHtml; delete btn.dataset.oldHtml; }
    clearTimeout(btn._disarmT);
  }

  // true = مسلَّح فمرِّر التنفيذ · false = سُلِّح الآن فأوقف
  function arm(btn, msg) {
    if (btn.dataset.armed) { disarm(btn); return true; }
    btn.dataset.armed = '1';
    btn.dataset.oldHtml = btn.innerHTML;
    btn.classList.add('confirming');
    btn.innerHTML = '⚠️ ' + msg + ' <b>اضغط للتأكيد</b>';
    btn._disarmT = setTimeout(function () { disarm(btn); }, ARM_MS);
    return false;
  }

  // نماذج تحمل data-confirm: التسليح على زر الإرسال الفعلي
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.dataset || !f.dataset.confirm) return;
    var btn = e.submitter || f.querySelector('button[type=submit],button:not([type]),input[type=submit]');
    if (!btn) return;                      // بلا زر ظاهر: التمرير خيرٌ من قفل النموذج
    if (!arm(btn, f.dataset.confirm)) {
      e.preventDefault();
      // حارس الإرسال المزدوج سبقنا فقفل النموذج وعطّل أزراره — الضغطة الأولى
      // لم تُرسل شيئاً، ففكّ قفله وأعد تفعيل الأزرار كي تعمل ضغطة التأكيد
      delete f.dataset.busy;
      setTimeout(function () {
        f.querySelectorAll('button,input[type=submit]').forEach(function (b) {
          b.disabled = false; b.classList.remove('busy');
        });
      }, 0);
    }
  }, true);

  // عناصر تحمل data-confirm بنفسها (زر يشير لنموذج خارجي بسمة form، أو رابط)
  document.addEventListener('click', function (e) {
    var el = e.target.closest('button[data-confirm],a[data-confirm]');
    if (!el) return;
    if (!arm(el, el.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);
})();

/* ═ تصفية رقائق الاختيار المتعدد — بحثٌ فوري داخل صندوق الرقائق ═ */
document.addEventListener('input', function (e) {
  if (!e.target.matches || !e.target.matches('[data-chipfilter]')) return;
  var q = e.target.value.trim();
  e.target.closest('[data-chips]').querySelectorAll('.chip').forEach(function (c) {
    c.style.display = !q || c.textContent.indexOf(q) >= 0 ? '' : 'none';
  });
});

/* ═ حارس المغادرة: نموذج POST كُتب فيه ولم يُرسل — المتصفح يسأل قبل ضياعه ═
   (المسودات المحلية تبقى شبكة أمانٍ ثانية إن غادر رغم التحذير) */
(function () {
  var dirty = false, submitting = false;
  document.addEventListener('input', function (e) {
    var f = e.target && e.target.form;
    if (f && (f.method || '').toLowerCase() === 'post') dirty = true;
  });
  // غير رأسمالي (bubble): يعمل بعد محرك التأكيد — الضغطة المسلِّحة الممنوعة لا تُحسب إرسالاً
  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented) submitting = true;
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });
})();

/* ═ الحيوية: الأرقام تعدّ صعوداً وأشرطة التقدم تمتلئ أمام العين ═ */
(function () {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // عدّ تصاعدي لأرقام بطاقات الإحصاء — الأرقام الصِرفة فقط، وبنفس تنسيق الفواصل
  document.querySelectorAll('.stat b, .kpi b').forEach(function (el) {
    var raw = (el.textContent || '').trim();
    if (!/^[\d,]+$/.test(raw)) return;
    var target = parseInt(raw.replace(/,/g, ''), 10);
    if (!isFinite(target) || target <= 0 || target > 5000000) return;
    var t0 = null, DUR = 650;
    function step(ts) {
      if (!t0) t0 = ts;
      var k = Math.min(1, (ts - t0) / DUR);
      k = 1 - Math.pow(1 - k, 3);                       // تباطؤ في النهاية
      el.textContent = Math.round(target * k).toLocaleString('en-US');
      if (k < 1) requestAnimationFrame(step);
    }
    el.textContent = '0';
    requestAnimationFrame(step);
  });

  // أشرطة التقدم وأعمدة المخططات: تُصفَّر ثم تنساب لهدفها (الانتقال معرف في CSS)
  document.querySelectorAll('.pbar span').forEach(function (s) {
    var w = s.style.width;
    if (!w) return;
    s.style.width = '0';
    requestAnimationFrame(function () { requestAnimationFrame(function () { s.style.width = w; }); });
  });
  document.querySelectorAll('.cbar').forEach(function (s) {
    var h = s.style.height;
    if (!h) return;
    s.style.height = '0';
    requestAnimationFrame(function () { requestAnimationFrame(function () { s.style.height = h; }); });
  });
})();

/* ═ v2.114 — الإجراءات الجماعية: تحديد صفوف ← شريط طافٍ (حالة/تصدير/حذف) ═ */
(function () {
  'use strict';
  function bar() { return document.getElementById('bulkbar'); }
  function rows() { return [].slice.call(document.querySelectorAll('input.brow')); }
  function checked() { return rows().filter(function (c) { return c.checked; }); }

  function sync() {
    var b = bar();
    if (!b) return;
    var n = checked().length;
    b.hidden = n === 0;
    var cnt = document.getElementById('bulkn');
    if (cnt) cnt.textContent = n;
    var all = document.getElementById('ballsel');
    if (all) {
      all.checked = n > 0 && n === rows().length;
      all.indeterminate = n > 0 && n < rows().length;
    }
  }

  document.addEventListener('change', function (e) {
    if (e.target.id === 'ballsel') {
      rows().forEach(function (c) { c.checked = e.target.checked; });
      sync(); return;
    }
    if (e.target.classList && e.target.classList.contains('brow')) sync();
  });

  document.addEventListener('click', function (e) {
    if (e.target.id === 'bulkclear') {
      rows().forEach(function (c) { c.checked = false; });
      sync();
    }
  });

  /* المعرفات تُحقن لحظة الإرسال فقط — فلا حالة خفية تتقادم مع تبديل htmx للجدول */
  document.addEventListener('submit', function (e) {
    var b = bar();
    if (!b || e.target !== b) return;
    b.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
    checked().forEach(function (c) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value;
      b.appendChild(i);
    });
  }, true);
})();

/* ═ v2.116 — باني الفلاتر المتقدم: صفوف (حقل/عامل/قيمة) تُبنى محلياً وتُرسل fl[i][…] ═ */
(function () {
  'use strict';
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function boot(box) {
    if (box.dataset.ready) return;
    box.dataset.ready = '1';
    var FIELDS = JSON.parse(box.dataset.fields);
    var OPS = JSON.parse(box.dataset.ops);
    var LBL = JSON.parse(box.dataset.lbl);
    var rows = box.querySelector('.advrows');
    var n = 0;

    function fieldOf(k) { for (var i = 0; i < FIELDS.length; i++) if (FIELDS[i].k === k) return FIELDS[i]; return FIELDS[0]; }

    function addRow(init) {
      init = init || {};
      var i = n++;
      var d = document.createElement('div');
      d.className = 'advrow';
      d.innerHTML = '<select class="inp" name="fl[' + i + '][f]" aria-label="الحقل"></select>' +
        '<select class="inp" name="fl[' + i + '][o]" aria-label="العامل"></select>' +
        '<span class="advval"></span>' +
        '<button class="btn ghost xs" type="button" data-advrm aria-label="حذف الشرط">✕</button>';
      var fs = d.children[0], os = d.children[1], vs = d.children[2];
      FIELDS.forEach(function (x) { fs.add(new Option(x.l, x.k, false, x.k === init.f)); });

      function fillOps(sel) {
        os.innerHTML = '';
        (OPS[fieldOf(fs.value).t] || []).forEach(function (op) { os.add(new Option(LBL[op] || op, op, false, op === sel)); });
      }
      function fillVal(val) {
        var x = fieldOf(fs.value), op = os.value;
        if (op === 'empty' || op === 'nempty') { vs.innerHTML = ''; return; }
        if (x.t === 'sel') {
          vs.innerHTML = '<select class="inp" name="fl[' + i + '][v]">' +
            x.o.map(function (o) { return '<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>';
        } else if (x.t === 'bool') {
          vs.innerHTML = '<select class="inp" name="fl[' + i + '][v]">' +
            '<option value="1"' + (val === '1' ? ' selected' : '') + '>نعم</option>' +
            '<option value="0"' + (val === '0' ? ' selected' : '') + '>لا</option></select>';
        } else {
          var t = (x.t === 'date' || x.t === 'dt') ? 'date' : ((x.t === 'num' || x.t === 'big') ? 'number' : 'text');
          vs.innerHTML = '<input class="inp" type="' + t + '" name="fl[' + i + '][v]" value="' + esc(val || '') + '"' +
            (t === 'number' ? ' step="any"' : '') + (t === 'text' ? ' placeholder="القيمة…"' : '') + '>';
        }
      }
      fs.addEventListener('change', function () { fillOps(); fillVal(''); });
      os.addEventListener('change', function () { fillVal(vs.querySelector('[name]') ? vs.querySelector('[name]').value : ''); });
      fillOps(init.o); fillVal(init.v || '');
      rows.appendChild(d);
    }

    (JSON.parse(box.dataset.init || '[]') || []).forEach(function (c) { if (c && c.f) addRow(c); });
    if (!rows.children.length) addRow();

    box.addEventListener('click', function (e) {
      if (e.target.closest('[data-advadd]')) { addRow(); return; }
      var rm = e.target.closest('[data-advrm]');
      if (rm) { rm.closest('.advrow').remove(); if (!rows.children.length) addRow(); }
    });
  }

  function scan() { var b = document.querySelector('[data-advfl]'); if (b) boot(b); }
  scan();
  document.addEventListener('htmx:afterSettle', scan);
})();

/* ═ v2.119 — CLM م3: محرر البنود، رقائق المتغيرات، المعاينة الحية، ومعالج الإرسال ═ */
(function () {
  'use strict';
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var lastField = null;
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('#blocks textarea, #blocks input[data-h], #varszone textarea')) lastField = e.target;
  });

  /* — محرر البنود (صفحة تحرير القالب) — */
  var wrap = document.getElementById('blocks');
  if (wrap) {
    var rows = [];
    function row(b) {
      var d = document.createElement('div');
      d.className = 'blockrow';
      d.innerHTML =
        '<div class="bhead">' +
          '<input class="inp" data-h placeholder="عنوان البند (اختياري)" value="' + esc(b.h || '') + '">' +
          '<label class="chk sub" title="ابدأ صفحة جديدة عند الطباعة قبل هذا البند"><input type="checkbox" data-br' + (b.break ? ' checked' : '') + '> فاصل صفحة</label>' +
          '<span class="spacer"></span>' +
          '<button class="btn ghost xs" type="button" data-up title="أعلى">↑</button>' +
          '<button class="btn ghost xs" type="button" data-dn title="أسفل">↓</button>' +
          '<button class="btn ghost xs dn" type="button" data-rm aria-label="حذف البند">✕</button>' +
        '</div>' +
        '<textarea class="inp" rows="5" placeholder="نص البند — أدرج المتغيرات من القائمة الجانبية">' + esc(b.body || '') + '</textarea>';
      return d;
    }
    (JSON.parse(wrap.dataset.init || '[]')).forEach(function (b) { wrap.appendChild(row(b)); });
    if (!wrap.children.length) wrap.appendChild(row({}));

    document.getElementById('addblock') && document.getElementById('addblock').addEventListener('click', function () {
      var d = row({}); wrap.appendChild(d); d.querySelector('textarea').focus();
    });
    wrap.addEventListener('click', function (e) {
      var r = e.target.closest('.blockrow'); if (!r) return;
      if (e.target.closest('[data-rm]')) { r.remove(); if (!wrap.children.length) wrap.appendChild(row({})); }
      if (e.target.closest('[data-up]') && r.previousElementSibling) wrap.insertBefore(r, r.previousElementSibling);
      if (e.target.closest('[data-dn]') && r.nextElementSibling) wrap.insertBefore(r.nextElementSibling, r);
    });

    function collect() {
      return [].map.call(wrap.querySelectorAll('.blockrow'), function (r) {
        return { h: r.querySelector('[data-h]').value.trim(),
                 body: r.querySelector('textarea').value,
                 break: r.querySelector('[data-br]').checked };
      }).filter(function (b) { return b.h || b.body.trim(); });
    }
    var form = document.getElementById('tplform');
    form && form.addEventListener('submit', function () {
      document.getElementById('blocksjson').value = JSON.stringify(collect());
    });

    /* معاينة القالب: تسطيح محلي ثم عرضٌ خادمي (نفس مسار الإنشاء تماماً) */
    var pv = document.getElementById('tplpreview');
    pv && pv.addEventListener('click', function () {
      var flat = collect().map(function (b) { return (b.h ? b.h + '\n' : '') + b.body.trim(); }).join('\n\n');
      var fd = new FormData();
      fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
      fd.append('free_body', flat);
      fetch('/esign/preview', { method: 'POST', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          document.getElementById('pvbody').innerHTML = html;
          document.getElementById('pvmodal').hidden = false;
        });
    });
  }

  /* — رقائق المتغيرات: نقرة تُدرج {الاسم} عند المؤشر — */
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('.varchip');
    if (!chip) return;
    var t = lastField || document.querySelector('#blocks textarea, #varszone textarea');
    if (!t) return;
    var ins = '{' + chip.dataset.k + '}';
    var s = t.selectionStart || 0, epos = t.selectionEnd || 0;
    t.value = t.value.slice(0, s) + ins + t.value.slice(epos);
    t.focus(); t.selectionStart = t.selectionEnd = s + ins.length;
  });
  var vs = document.getElementById('varsearch');
  vs && vs.addEventListener('input', function () {
    var q = vs.value.trim();
    document.querySelectorAll('#varlist .varchip').forEach(function (c) {
      c.style.display = !q || c.textContent.indexOf(q) >= 0 || c.dataset.k.indexOf(q) >= 0 ? '' : 'none';
    });
  });

  /* — نموذج الإرسال: متغيرات موسومة تنجو من تبديل القالب + معاينة + معالج خطوات — */
  var ez = document.getElementById('varszone');
  if (ez) {
    var reg = JSON.parse(ez.dataset.reg || '{}');
    var saved = {};   // قيم المستخدم — تبقى عند تبديل القالب
    document.addEventListener('input', function (e) {
      if (e.target.name && e.target.name.indexOf('vars[') === 0) {
        saved[e.target.name.slice(5, -1)] = e.target.value;
      }
    });
    var sel = document.getElementById('tplsel');
    var freeHtml = ez.innerHTML;   // النص الحر الأصلي (بقيمة old إن وجدت) يعود عند إلغاء القالب
    function rebuild() {
      var opt = sel && sel.selectedOptions[0];
      var vars = sel && sel.value && opt && opt.dataset.vars ? JSON.parse(opt.dataset.vars) : null;
      if (!vars) {
        ez.innerHTML = freeHtml;
        return;
      }
      ez.innerHTML = vars.map(function (v) {
        var d = reg[v] || {};
        var lbl = d.label || v.replace(/_/g, ' ');
        return '<div class="fld">' +
          '<label>' + esc(lbl) + (d.req ? ' <b class="req">*</b>' : '') +
            (d.desc ? ' <span class="sub">· ' + esc(d.desc) + '</span>' : '') + '</label>' +
          '<input class="inp" name="vars[' + esc(v) + ']" value="' + esc(saved[v] || '') + '"' +
          ' placeholder="' + esc(d.src ? 'تلقائي من السجل المربوط إن وُجد' : (d.ex ? 'مثال: ' + d.ex : '')) + '">' +
        '</div>';
      }).join('');
    }
    if (sel) { sel.addEventListener('change', rebuild); if (sel.value) rebuild(); }

    /* معاينة قبل الإنشاء — بنفس محرك الحل الخادمي */
    var pvb = document.getElementById('esignpreview');
    pvb && pvb.addEventListener('click', function () {
      var form = document.getElementById('esignform');
      var fd = new FormData(form);
      fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
      fetch('/esign/preview', { method: 'POST', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          document.getElementById('pvbody').innerHTML = html;
          document.getElementById('pvmodal').hidden = false;
        });
    });

    /* معالج الخطوات: تنقّل خفيف بنفس الصفحة — بلا JS تظهر كل الأقسام تباعاً */
    var steps = [].slice.call(document.querySelectorAll('[data-wstep]'));
    if (steps.length) {
      var cur = 0;
      function show(i) {
        cur = Math.max(0, Math.min(i, steps.length - 1));
        steps.forEach(function (s, j) { s.hidden = j !== cur; });
        document.querySelectorAll('.wchip').forEach(function (c, j) {
          c.classList.toggle('on', j === cur); c.classList.toggle('done', j < cur);
        });
        var prev = document.getElementById('wprev'), next = document.getElementById('wnext'),
            send = document.getElementById('wsend');
        if (prev) prev.hidden = cur === 0;
        if (next) next.hidden = cur === steps.length - 1;
        if (send) send.hidden = cur !== steps.length - 1;
      }
      document.getElementById('wnext') && document.getElementById('wnext').addEventListener('click', function () { show(cur + 1); });
      document.getElementById('wprev') && document.getElementById('wprev').addEventListener('click', function () { show(cur - 1); });
      document.querySelectorAll('.wchip').forEach(function (c, j) { c.addEventListener('click', function () { show(j); }); });
      show(0);
    }
  }
})();

/* ═ v2.120 — صفوف الموقّعين المتعددين في نموذج الإرسال ═ */
(function () {
  'use strict';
  var rows = document.getElementById('signerrows');
  if (!rows) return;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function addRow() {
    var d = document.createElement('div');
    d.className = 'signerrow';
    d.innerHTML =
      '<input class="inp" data-sname placeholder="اسم الموقّع" style="flex:1;min-width:140px">' +
      '<input class="inp ltr" data-semail type="email" placeholder="email@example.com" style="flex:1;min-width:160px">' +
      '<select class="inp" data-srole style="width:auto"><option>موقّع</option><option>شاهد</option><option>مستلم نسخة</option></select>' +
      '<button class="btn ghost xs" type="button" data-srm aria-label="حذف الموقّع">✕</button>';
    rows.appendChild(d);
  }
  document.getElementById('addsigner').addEventListener('click', function () { addRow(); });
  rows.addEventListener('click', function (e) {
    if (e.target.closest('[data-srm]')) e.target.closest('.signerrow').remove();
  });
  var form = document.getElementById('esignform');
  function collectSigners() {
    return [].map.call(rows.querySelectorAll('.signerrow'), function (r) {
      return { name: r.querySelector('[data-sname]').value.trim(),
               email: r.querySelector('[data-semail]').value.trim(),
               role: r.querySelector('[data-srole]').value };
    }).filter(function (s) { return s.name; });
  }
  /* كلمة السر إلزامية متصفحياً فقط حين لا موقّعين مستقلين */
  function syncPass() {
    var pi = document.getElementById('passinput');
    if (pi) pi.required = collectSigners().length === 0;
  }
  document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('.signerrow')) syncPass(); });
  rows.addEventListener('click', syncPass);
  syncPass();
  form && form.addEventListener('submit', function () {
    var list = collectSigners();
    document.getElementById('signersjson').value = list.length ? JSON.stringify(list) : '';
  });
})();

/* ═ v2.123 — CLM م7: إدراج بنود المكتبة عند المؤشر (نسخٌ بالقيمة كالرقائق) ═ */
(function () {
  'use strict';
  var data = document.getElementById('clausedata');
  if (!data) return;
  var clauses = [];
  try { clauses = JSON.parse(data.textContent || '[]'); } catch (e) { /* مكتبة فارغة */ }
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('.clausechip');
    if (!chip) return;
    var cl = clauses[parseInt(chip.dataset.i, 10)];
    if (!cl) return;
    // نُدرج في آخر حقل نصي لمسه المستخدم في المحرر — وإلا آخر بند في القائمة
    var fields = document.querySelectorAll('#blocks textarea');
    var t = (document.activeElement && document.activeElement.matches && document.activeElement.matches('#blocks textarea'))
      ? document.activeElement : (fields.length ? fields[fields.length - 1] : null);
    if (!t) return;
    var ins = (t.value.trim() ? '\n\n' : '') + cl.name + '\n' + cl.body;
    var s = t.selectionEnd || t.value.length;
    t.value = t.value.slice(0, s) + ins + t.value.slice(s);
    t.focus(); t.selectionStart = t.selectionEnd = s + ins.length;
    t.dispatchEvent(new Event('input', { bubbles: true }));
  });
})();
