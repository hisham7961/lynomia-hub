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

  /* ── النافذة المنبثقة ──
     v2.129: إدارة تركيز كاملة — يدخل الحوار عند الفتح، يُحبس فيه بـTab،
     ويعود لمُطلقه عند الإغلاق (كان يبقى خلف الحوار فيتوه مستخدم الكيبورد) */
  var modalOpener = null;
  Hub.modal = function (url) {
    var m = $('#modal'); m.hidden = false; document.body.classList.add('lock');
    modalOpener = document.activeElement;
    /* v2.132: هيكل عظمي بدل نص التحميل — الصفحة تَعِد بالشكل قبل الوصول */
    $('#modalbody').innerHTML = '<div class="skelrow" aria-hidden="true"><div class="skel" style="width:40%;height:18px"></div><div class="skel"></div><div class="skel" style="width:85%"></div><div class="skel" style="height:38px;margin-top:6px"></div></div>';
    htmx.ajax('GET', url, { target: '#modalbody', swap: 'innerHTML' });
    var c = m.querySelector('.mclose'); if (c) c.focus();
    return false;
  };
  Hub.closeModal = function () {
    $('#modal').hidden = true; $('#modalbody').innerHTML = ''; document.body.classList.remove('lock');
    if (modalOpener && modalOpener.focus) { try { modalOpener.focus(); } catch (e) {} modalOpener = null; }
  };
  // فخ Tab: الدوران داخل الحوار المفتوح لا خلفه
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') return;
    var m = $('#modal');
    if (!m || m.hidden) return;
    var f = [].slice.call(m.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'))
      .filter(function (el) { return el.offsetParent !== null; });
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!m.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  });

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
  /* فشل الطلب (4xx/5xx أو انقطاع) لا يطلق afterSettle — كان الشريط يعلق على
     68٪ وحالة loading على الجدول للأبد، فيبدو النظام «يعمل» وهو فاشل */
  ['htmx:responseError', 'htmx:sendError'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      var b = $('#topload'); if (b) { b.style.opacity = 0; b.style.width = '0'; }
      var z = $('#tblzone'); if (z) z.classList.remove('loading');
      Hub.toast(ev === 'htmx:sendError' ? 'انقطع الاتصال — أعد المحاولة' : 'تعذّر تحميل المحتوى', 1);
    });
  });

  /* ── عدّاد تقدّم النقل: رفعٌ بنسبةٍ حقيقية، وتنزيلٌ يقول متى بدأ ──

     رفعُ غيغابايتٍ عبر نموذجٍ عاديّ يعني شاشةً بيضاء ودائرةَ تحميلٍ في التبويب
     بلا رقمٍ واحد: لا يُعرف أوصل عشرةَ بالمئة أم تسعين، ولا إن كان الاتصال قد
     مات أصلاً — فيُعاد الرفع من الصفر ظنّاً. النموذجُ يُرسَل هنا بـXHR،
     و`upload.onprogress` يعطي البايتاتِ الحقيقية: نسبةٌ وحجمٌ وسرعةٌ وزمنٌ
     متبقٍّ وزرُّ إلغاء. وعند الفراغ يتبع المتصفحُ التحويلةَ كما لو أُرسل عادياً.

     والتنزيلُ لا يُقاس من الصفحة (المتصفح يملكه)، لكن **انتظارَ التحضير** يُقاس:
     الخادمُ يختم كعكةً عند بدء البثّ، فتُخفى اللوحةُ لحظةَ وصول أول بايت. */
  var XF = {
    box: null,
    open: function (title) {
      if (!XF.box) {
        XF.box = document.createElement('div');
        XF.box.className = 'xfer';
        XF.box.innerHTML = '<div class="xrow"><b class="xtitle"></b><button type="button" class="xcancel" '
          + 'aria-label="إلغاء">✕</button></div><div class="xbar"><span></span></div>'
          + '<div class="xmeta sub"></div>';
        document.body.appendChild(XF.box);
      }
      XF.box.querySelector('.xtitle').textContent = title;
      XF.box.querySelector('.xbar span').style.width = '0%';
      XF.box.querySelector('.xmeta').textContent = 'يُحضَّر…';
      XF.box.hidden = false;
      XF.box.classList.remove('done');
      return XF.box;
    },
    close: function () { if (XF.box) XF.box.hidden = true; },
    /* النسبة والحجم والسرعة والزمن المتبقّي — الأرقامُ وحدها تُطمئن */
    paint: function (loaded, total, t0) {
      if (!XF.box) return;
      var pct = total ? Math.min(100, Math.round(loaded / total * 100)) : null;
      XF.box.querySelector('.xbar span').style.width = (pct === null ? 30 : pct) + '%';
      XF.box.querySelector('.xbar').classList.toggle('indet', pct === null);
      var sec = Math.max(0.001, (Date.now() - t0) / 1000);
      var rate = loaded / sec;
      var left = (total && rate > 0) ? Math.round((total - loaded) / rate) : null;
      XF.box.querySelector('.xmeta').textContent =
        (pct === null ? '' : pct + '٪ · ')
        + XF.size(loaded) + (total ? ' من ' + XF.size(total) : '')
        + ' · ' + XF.size(rate) + '/ث'
        + (left !== null ? ' · يتبقّى ' + XF.clock(left) : '');
    },
    size: function (b) {
      var u = ['ب', 'ك.ب', 'م.ب', 'ج.ب'], i = 0;
      while (b >= 1024 && i < 3) { b /= 1024; i++; }
      return (b >= 100 || i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
    },
    clock: function (s) {
      return s < 60 ? s + ' ث' : (s < 3600 ? Math.round(s / 60) + ' د' : (s / 3600).toFixed(1) + ' س');
    }
  };
  Hub.transfer = XF;

  /* حدودُ هذا الخادم: [سقفُ النظام, عتبةُ التقطيع, سقفُ الإعداد] بالكيلوبايت */
  function upLimits() {
    var m = document.querySelector('meta[name="hub-upload"]');
    var v = (m ? m.content : '').split(',').map(function (x) { return parseInt(x, 10) || 0; });
    return { kb: v[0] || 0, chunkAt: v[1] || 0, appKb: v[2] || 0 };
  }
  function csrf() {
    var m = document.querySelector('meta[name=csrf-token]');
    return m ? m.content : '';
  }
  function rid() {
    var s = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', o = '';
    for (var i = 0; i < 32; i++) o += s.charAt(Math.floor(Math.random() * s.length));
    return o;
  }

  /* رفعُ ملفٍ واحدٍ **مقطَّعاً**: قطعٌ أصغرُ من سقف الطلب الواحد، بالترتيب.
     يُعيد وعداً برمزٍ يُستهلك في النموذج. onbit(bytes) للتقدّم التراكمي. */
  function chunkUpload(file, chunkBytes, onbit) {
    var uid = rid(), i = 0, n = Math.max(1, Math.ceil(file.size / chunkBytes));
    return new Promise(function (resolve, reject) {
      function step() {
        if (i >= n) {
          var fdF = new FormData();
          fdF.append('uid', uid); fdF.append('n', String(n));
          fetch('/uploads/finish', { method: 'POST', body: fdF, credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (j) { j && j.ok ? resolve({ token: j.token, name: file.name }) : reject(j && j.msg); })
            .catch(reject);
          return;
        }
        var from = i * chunkBytes, blob = file.slice(from, Math.min(file.size, from + chunkBytes));
        var fd = new FormData();
        fd.append('uid', uid); fd.append('i', String(i)); fd.append('chunk', blob, 'part');
        fetch('/uploads/chunk', { method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (j) {
            if (!j || !j.ok) { reject(j && j.msg); return; }
            if (onbit) onbit(blob.size);
            i++; step();
          }).catch(reject);
      }
      step();
    });
  }

  /* الرفع: أيّ نموذج multipart فيه ملفٌ مختار — إلا ما وُسم data-noxhr */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || f.hasAttribute('data-noxhr')) return;
    if ((f.getAttribute('enctype') || '').indexOf('multipart') === -1) return;
    if (!window.FormData || !window.XMLHttpRequest) return;
    /* htmx يملك نماذجه — لا يُختطف نموذجٌ يديره غيرنا */
    if (f.hasAttribute('hx-post') || f.hasAttribute('hx-put')) return;

    var picked = 0, total = 0;
    [].forEach.call(f.querySelectorAll('input[type=file]'), function (i) {
      for (var k = 0; i.files && k < i.files.length; k++) { picked++; total += i.files[k].size; }
    });
    if (!picked) return;                       // نموذجٌ بلا ملفٍ مختار: أرسله كما هو

    e.preventDefault();
    var fd = new FormData(f);
    var btn = f.querySelector('button[type=submit],button:not([type])');
    if (btn) btn.disabled = true;

    /* **ما يتجاوز سقفَ الطلب الواحد يُرفع مقطَّعاً**: الطلبُ الكامل يُرفض على
       بوابة PHP قبل أن يصل التطبيق، فلا رسالةَ ولا نصفَ رفعة. القطعُ تصل
       وتُجمَّع على الخادم، ثم يُرسَل النموذجُ برموزها بدل الملفات. */
    var lim = upLimits();
    var chunkBytes = Math.max(256 * 1024, (lim.chunkAt || 4096) * 1024);
    var big = [];
    [].forEach.call(f.querySelectorAll('input[type=file]'), function (inp) {
      if (!inp.files || !inp.files.length) return;
      for (var k = 0; k < inp.files.length; k++) {
        if (inp.files[k].size > chunkBytes) big.push({ inp: inp, file: inp.files[k] });
      }
    });

    if (big.length) {
      var t0c = Date.now(), done = 0;
      XF.open('⬆ يُرفع ' + picked + (picked > 1 ? ' ملفات' : ' ملف') + ' (مقطَّعاً)…');
      XF.paint(0, total, t0c);

      /* الملفاتُ الكبيرة تُنزع من النموذج ويحلّ محلَّها رمزُ رفعتها */
      var names = {};
      big.forEach(function (b) { names[b.inp.name] = true; });
      Object.keys(names).forEach(function (nm) { fd.delete(nm); });

      var chain = Promise.resolve(), slot = 0;
      big.forEach(function (b) {
        chain = chain.then(function () {
          return chunkUpload(b.file, chunkBytes, function (bits) { done += bits; XF.paint(done, total, t0c); })
            .then(function (res) {
              /* حقلٌ متعدد (files[]) ← دفعة، وحقلٌ مفرد ← باسم حقله.
                 **الفهرسُ صريحٌ لا `[]`**: كلُّ `[]` تفتح عنصراً جديداً في PHP،
                 فيفترق الرمزُ عن اسمه ويصير لكلٍّ منهما صفٌّ وحده. */
              if (b.inp.name.indexOf('[]') > -1) {
                fd.append('_chunks[' + slot + '][token]', res.token);
                fd.append('_chunks[' + slot + '][name]', res.name);
                slot++;
              } else {
                fd.append('_chunk_' + b.inp.name + '[token]', res.token);
                fd.append('_chunk_' + b.inp.name + '[name]', res.name);
              }
            });
        });
      });

      chain.then(function () { send(fd); }, function (msg) {
        XF.close(); if (btn) btn.disabled = false;
        Hub.toast(typeof msg === 'string' && msg ? msg : 'تعذّر رفع الملف الكبير — أعد المحاولة', 1);
      });
      return;
    }

    send(fd);

    function send(payload) {
    var xhr = new XMLHttpRequest();
    var t0 = Date.now();
    var box = XF.open('⬆ يُرسَل ' + picked + (picked > 1 ? ' ملفات' : ' ملف') + '…');
    box.querySelector('.xcancel').onclick = function () { xhr.abort(); };

    xhr.upload.onprogress = function (ev) { XF.paint(ev.loaded, ev.lengthComputable ? ev.total : total, t0); };
    xhr.upload.onload = function () {
      /* اكتمل الإرسال ولم يردّ الخادم بعد: معالجةُ ملفٍ كبيرٍ تأخذ وقتها */
      XF.paint(total, total, t0);
      if (XF.box) XF.box.querySelector('.xmeta').textContent = 'وصل كاملاً — يُعالَج على الخادم…';
    };
    xhr.onload = function () {
      XF.close();
      if (btn) btn.disabled = false;
      /* التحويلةُ تُتبَع تلقائياً — نمضي إلى وجهتها كما يفعل النموذج العادي */
      if (xhr.status >= 200 && xhr.status < 400) { window.location = xhr.responseURL || location.href; return; }
      if (xhr.status === 413) { Hub.toast('الملف أكبر من سقف الخادم — راجع «أقصى حجم للملف المرفوع»', 1); return; }
      /* خطأُ تحقّقٍ أو غيره: أعد الإرسال عادياً كي تظهر الرسائل في مكانها */
      f.setAttribute('data-noxhr', '1');
      f.submit();
    };
    xhr.onerror = function () { XF.close(); if (btn) btn.disabled = false; Hub.toast('انقطع الاتصال أثناء الرفع — أعد المحاولة', 1); };
    xhr.onabort = function () { XF.close(); if (btn) btn.disabled = false; Hub.toast('أُلغي الرفع'); };

    xhr.open('POST', f.getAttribute('action') || location.href, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(payload);
    }
  }, true);

  /* التنزيل: لوحةُ «يُحضَّر» تُخفى لحظة بدء البثّ (كعكةٌ يختمها الخادم) */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.hasAttribute('data-nodl') || a.target === '_blank') return;
    var href = a.getAttribute('href') || '';
    if (!/([?&]dl=1|\/dl$|\/zip$|\/export)/.test(href)) return;

    var t = 'd' + Date.now() + Math.floor(Math.random() * 1000);
    a.href = href + (href.indexOf('?') === -1 ? '?' : '&') + 'dlt=' + t;
    var t0 = Date.now();
    XF.open('⬇ يُحضَّر الملف…');
    XF.paint(0, 0, t0);

    /* الكعكةُ لا تصل إلا مع ترويسات الرد — أي لحظةَ بدء البثّ فعلاً */
    var iv = setInterval(function () {
      if (document.cookie.indexOf('hub_dl=') !== -1) {
        clearInterval(iv);
        document.cookie = 'hub_dl=; Max-Age=0; path=/';
        if (XF.box) XF.box.querySelector('.xmeta').textContent = 'بدأ التنزيل — يكمله المتصفح';
        setTimeout(XF.close, 1600);
      } else if (Date.now() - t0 > 120000) {      // دقيقتان بلا بثّ: لا نُبقي لوحةً معلّقة
        clearInterval(iv); XF.close();
      }
    }, 400);
  });

  /* ── الاختصارات ── */
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName);
    // v2.125: معالج Ctrl+K هنا حُذف — لوحة الأوامر (v2.112) تعالجه بمعالجها الأغنى وحدها
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
  /* **مقرونةٌ بصاحب الجلسة**: كانت بمفتاحٍ واحد، فعناوينُ ما فتحه الموظف تظهر
     لمن يسجّل بعده على الجهاز نفسِه — وهي تصف بياناتٍ قد لا يراها. */
  var KEY = 'lyn_recent:' + (document.body.dataset.uid || '-');
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
        if (!r.ok) {
          /* رسالة الخادم الدقيقة لا «تحقق من الاتصال» المضللة: 403 «حقل الحالة
             غير قابل للكتابة بصلاحيتك» و422 «حالة غير معرّفة» و«يمر بالموافقات»
             كلها كانت تُطمس بتشخيصِ اتصالٍ لا علاقة له */
          return r.json().catch(function () { return {}; }).then(function (d) {
            throw (d && d.message) ? d.message : 0;
          });
        }
        col.querySelector('.kbody').prepend(card);
        [from, col].forEach(function (c) { c.querySelector('.kcount').textContent = c.querySelectorAll('.kcard').length; });
        Hub.toast('نُقل إلى «' + st + '» ✓');
      }).catch(function (err) {
        Hub.toast(typeof err === 'string' && err ? err : 'تعذّر النقل — تحقق من الاتصال', 1);
      });
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
    if (cur) cur.setAttribute('aria-selected', 'false');
    next.classList.add('sel');
    next.setAttribute('aria-selected', 'true');
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
    /* فكُّ القفل احتياطاً عند فشل الشبكة — **ولا يُفكّ ما دامت الصفحة تُغادَر**.
       ثماني ثوانٍ سقفٌ عاديٌّ لرفع مرفقٍ أو تقريرٍ ثقيل، فكان القفلُ ينفكّ
       والطلبُ في الطريق: نقرةٌ ثانية تُنشئ السجلَ مرتين. الفكُّ يُلغى إن بدأت
       الصفحةُ بالانتقال فعلاً (‏`beforeunload`)، ويبقى شبكةَ أمانٍ لمن لم يصل. */
    var unlock = setTimeout(function () {
      delete f.dataset.busy;
      btns.forEach(function (b) { b.disabled = false; b.classList.remove('busy'); });
    }, 20000);
    window.addEventListener('beforeunload', function () { clearTimeout(unlock); }, { once: true });
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
    // و`form.elements` تُظلَّل بحقلٍ اسمه `elements` كما تُظلَّل `method` —
    // فالاستعلامُ المباشر أمتنُ من خاصيةٍ يملك المحتوى أن يدهسها
    return Array.prototype.filter.call(form.querySelectorAll('input, select, textarea'), function (el) {
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
    /* v2.210: كان `innerHTML = '⚠️ ' + msg` — وسمةُ data-confirm تحمل في أربعةَ
       عشرَ موضعاً اسمَ سجلٍّ **يكتبه المستخدم** (اسم مرفق، عنوان سياسة، اسم قالب).
       الهروبُ في Blade يحمي السمة، ثم يعيدها المتصفح نصّاً خاماً في dataset،
       فتُحقن HTML هنا: سجلٌّ اسمُه <img src=x onerror=…> ينفّذ عند أول ضغطةِ حذف.
       بناءُ العقد نصّاً لا مصدراً — التسمية وحدها لا تصير شيفرة. */
    btn.textContent = '⚠️ ' + msg + ' ';
    var b = document.createElement('b');
    b.textContent = 'اضغط للتأكيد';
    btn.appendChild(b);
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
    if (!f) return;
    /*
     * **الخاصية تُظلَّل بحقلٍ يحمل اسمها.**
     *
     * `form.method` كانت تُقرأ خاصيةً — وDOM يجعل عناصر النموذج خصائصَ عليه
     * باسمها، فحقلٌ اسمه `method` (وهو موجود: «طريقة الدفع» في المالية
     * والمشتريات) **يُظلّل** الخاصيةَ المبنية فتُعيد العنصرَ لا النصّ، فيرمي
     * `toLowerCase` استثناءً في كل ضغطة مفتاح. وأثرُه ليس ضجيجاً في الكونسول:
     * المستمعُ ينقطع قبل `dirty = true`، **فحارسُ المغادرة يُطفأ على تلك الشاشة
     * بعينها** — يكتب المستخدم مستنداً ماليّاً كاملاً ثم يغادر فلا يُسأل ولا
     * يُحذَّر، ويضيع ما كتب صامتاً. وهو بالضبط ما وُضع الحارسُ لأجله.
     *
     * السمةُ لا تُظلَّل — فتُقرأ منها.
     */
    var method = (f.getAttribute('method') || 'get').toLowerCase();
    // v2.128: نماذج الفلترة والبحث (data-noguard) لا تحبس المغادرة — لا بيانات تضيع فيها
    if (method === 'post' && !f.hasAttribute('data-noguard')) dirty = true;
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
  document.querySelectorAll('.stat b').forEach(function (el) {   /* v2.125: ‎.kpi كان هدفاً ميتاً */
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

/* ═ v2.132 — حيوية مدركة: خفوت منطقة الجدول أثناء جلب htmx (بحثاً وفلترةً وترقيماً) ═ */
(function () {
  'use strict';
  document.addEventListener('htmx:beforeRequest', function (e) {
    var z = document.getElementById('tblzone');
    var t = e.detail && e.detail.target;
    if (z && t && (t.id === 'tblzone' || z.contains(t))) z.classList.add('loading');
  });
  document.addEventListener('htmx:afterSettle', function () {
    var z = document.getElementById('tblzone');
    if (z) z.classList.remove('loading');
  });
})();

// ═ تنبيهاتٌ أشدُّ لفتاً: نبضةُ عدٍّ، عنوانُ تبويبٍ يحمل العدد، ووميضُ إطارِ
//   الشاشة كل N دقيقة (افتراضياً ١٠) ما دام هناك غيرُ مقروء — لينتبه لِما لم يُحلّ.
//   الوميض مضبوطٌ بالوقت الحقيقيّ عبر sessionStorage فلا يتكرّر مع كل تنقّل.
(function () {
  var bell = document.querySelector('.bell[data-count-url]');
  if (!bell) return;
  var url = bell.getAttribute('data-count-url');
  var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
  var flashMin = 10;

  function paintBadge(n) {
    var badge = document.getElementById('bellbadge');
    if (badge) {
      var dot = badge.querySelector('.nbdg');
      if (n > 0) {
        if (!dot) { dot = document.createElement('span'); dot.className = 'nbdg'; badge.appendChild(dot); }
        dot.textContent = n > 99 ? '99+' : String(n);
      } else if (dot) { dot.remove(); }
    }
    document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle;
  }

  function flashScreen() {
    var o = document.getElementById('screenflash');
    if (!o) {
      o = document.createElement('div'); o.id = 'screenflash';
      o.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9998;'
        + 'border:6px solid var(--wn,#e0a800);border-radius:4px;'
        + 'box-shadow:inset 0 0 44px rgba(224,168,0,.55);opacity:0;transition:opacity .22s';
      document.body.appendChild(o);
    }
    var blinks = 0;
    var iv = setInterval(function () {
      o.style.opacity = o.style.opacity === '1' ? '0' : '1';
      if (++blinks >= 6) { clearInterval(iv); o.style.opacity = '0'; }
    }, 340);
    try { if (navigator.vibrate) navigator.vibrate([130, 80, 130]); } catch (e) {}
  }

  function tick() {
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        flashMin = d.flashMin || 10;
        var n = d.unread || 0;
        paintBadge(n);
        if (n > 0) {
          var last = parseInt(sessionStorage.getItem('nflash') || '0', 10);
          var now = Date.now();
          if (now - last >= flashMin * 60000) { sessionStorage.setItem('nflash', String(now)); flashScreen(); }
        }
      }).catch(function () {});
  }

  setInterval(tick, 60000);   // نبضةٌ كل دقيقة تُحدِّث العدّ والعنوان
  setTimeout(tick, 4000);     // أول قراءةٍ بعد استقرار الصفحة
})();
