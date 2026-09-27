/* Lynomia Hub — أفعالُ الواجهة المُعلَنة بسمات data-* (بند الدَّين #12 · FE-03)

   كانت الواجهةُ تحمل نحو تسعين معالجَ حدثٍ في السمات (onclick/onchange/onsubmit)
   وروابطَ `javascript:` — وكلُّها «سكربتٌ مضمَّن» تحجبه سياسةُ CSP الصارمة
   (`script-src-attr 'none'`). نُقلت هنا مستمِعاتٍ مفوَّضةً على `document`: القالبُ
   يُعلن **ماذا** بسمةٍ تحمل بياناتٍ لا شيفرة، وهذا الملفُّ وحده يعرف **كيف**.

   · مفوَّضةٌ على `document` ⇒ تعمل كما هي على ما تُبدّله htmx (الجدول والنافذة).
   · لا تعتمد على app.js: الصفحاتُ المستقلّة (الطباعة، التوقيع، الخطأ) تحمّل هذا وحده.
   · النصوصُ في السمات بيانات: تُقرأ بـdataset وتُكتب بـtextContent — لا تصير HTML.
   · أيُّ قالبٍ يعود إلى `on…=` أو `javascript:` تُسقطه حارسةُ `CspInlineHandlersGuardTest`.

   السمات:
     data-submit-on-change[="filled"]  يُرسل نموذجَه عند التغيير (filled: إن لم تكن القيمةُ فارغة)
     data-confirm-native="نص"          (نموذج) نافذةُ confirm الأصلية قبل الإرسال
     data-amount-invalid / data-amount-confirm  (نموذج) فحصُ حقل amount ثمّ تأكيدٌ يحمل {amount}
     data-save-query                   (نموذج) يكتب سلسلةَ استعلام الصفحة في حقل query
     data-print                        طباعةُ الصفحة
     data-copy="نص" | data-copy-from="#id"  نسخٌ للحافظة؛ data-copied / data-copy-failed نصُّ الزرّ بعده
     data-close-modal · data-theme-toggle
     data-body-toggle="صنف" · data-body-remove="صنف"
     data-remove-target="#id" · data-hide-target="#id" · data-open-target="#details"
     data-check-all="اسم الحقل" + data-check-state="1|0"   (داخل النموذج الحاوي)
     data-file-name                    (حقل ملف) يكتب اسمَ المختار في ‎.filename المجاور
     data-reveal="url"                 كشفُ سرٍّ من الخادم بجانب [data-secmask] (وإخفاؤه)
     data-set-value="#id" + data-value يضبط قيمةَ حقلٍ ثمّ يمضي الرابط
     data-require-field="اسم" + data-require-msg   زرُّ إرسالٍ لا يمضي والحقلُ فارغ
     data-prompt-field="اسم" + data-prompt          يسأل نصاً ويضعه في الحقل، والإلغاءُ يوقف
     data-fill-into="محدِّد" + data-fill-from="محدِّد"  ينسخ نصاً إلى حقلٍ في البطاقة
     data-row-filter="اسمُ سمة"         مرشِّحٌ فوريٌّ على العناصر الحاملة للسمة
     data-toggle-display="#id" + data-hide-value    يُخفي هدفاً حين تساوي القيمةُ المعطاة
     data-no-contextmenu               (body) يمنع القائمةَ السياقية
     data-go-back                      رجوعٌ في السجلّ
     data-reload-after-request         (htmx) يعيد تحميلَ الصفحة بعد اكتمال طلبه */
(function () {
  'use strict';
  /* بلا حارسِ «حُمِّل سابقاً» على window: `document.open()` (كتابةُ المستند العائد بعد
     الرفع) يمحو مستمِعاتِ المستند ويُبقي window — فالحارسُ كان سيترك الصفحةَ بلا أفعال */

  function closest(t, sel) { return t && t.closest ? t.closest(sel) : null; }
  function field(form, name) {
    /* بالاستعلام لا بـ`form[name]`: خاصيّاتُ النموذج تُظلَّل بحقولٍ تحمل أسماءها */
    return form ? form.querySelector('[name="' + String(name).replace(/"/g, '\\"') + '"]') : null;
  }
  function submitForm(form) { HTMLFormElement.prototype.submit.call(form); }

  /* ── التغيير ── */
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.matches) return;

    if (t.matches('[data-submit-on-change]') && t.form) {
      if (t.getAttribute('data-submit-on-change') === 'filled' && !t.value) return;
      submitForm(t.form);
      return;
    }
    if (t.matches('input[type=file][data-file-name]')) {
      var n = t.parentNode.querySelector('.filename');
      if (n) n.textContent = t.files && t.files.length ? t.files[0].name : (t.dataset.empty || '');
      return;
    }
    if (t.matches('[data-toggle-display]')) {
      var box = document.querySelector(t.getAttribute('data-toggle-display'));
      if (box) box.style.display = t.value === t.getAttribute('data-hide-value') ? 'none' : '';
    }
  });

  /* ── الإدخال ── */
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.matches || !t.matches('[data-row-filter]')) return;
    var attr = t.getAttribute('data-row-filter');
    if (!/^[a-z][a-z0-9-]*$/.test(attr)) return;
    var q = t.value.trim().toLowerCase();
    document.querySelectorAll('[' + attr + ']').forEach(function (r) {
      r.style.display = (!q || (r.getAttribute(attr) || '').indexOf(q) > -1) ? '' : 'none';
    });
  });

  /* ── الإرسال: أسبقُ المستمِعات (رأسماليٌّ ومحمَّلٌ قبل app.js) — الإلغاءُ هنا يوقف
     حارسَ الإرسال المزدوج والرفعَ بعده، فلا يبقى نموذجٌ مقفولاً بعد «إلغاء» ── */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    function stop() { e.preventDefault(); e.stopImmediatePropagation(); }

    if (f.hasAttribute('data-save-query')) {
      var q = field(f, 'query');
      if (q) q.value = location.search.replace(/^\?/, '');
    }
    if (f.hasAttribute('data-amount-confirm')) {
      var inp = field(f, 'amount');
      var a = parseFloat(inp ? inp.value : '');
      if (!isFinite(a) || a <= 0) { window.alert(f.getAttribute('data-amount-invalid') || ''); stop(); return; }
      if (!window.confirm(f.getAttribute('data-amount-confirm').split('{amount}').join(a.toFixed(3)))) { stop(); return; }
    }
    if (f.hasAttribute('data-confirm-native') && !window.confirm(f.getAttribute('data-confirm-native'))) { stop(); }
  }, true);

  /* ── النقر ── */
  document.addEventListener('click', function (e) {
    var t = e.target, el;

    if ((el = closest(t, '[data-print]'))) { window.print(); return; }

    if ((el = closest(t, '[data-copy],[data-copy-from]'))) {
      var src = el.getAttribute('data-copy-from'), txt;
      if (src) { var s = document.querySelector(src); txt = s ? s.innerText : ''; }
      else txt = el.getAttribute('data-copy') || '';
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () {
        if (el.hasAttribute('data-copied')) el.textContent = el.getAttribute('data-copied');
      }, function () {
        if (el.hasAttribute('data-copy-failed')) el.textContent = el.getAttribute('data-copy-failed');
      });
      return;
    }

    if (closest(t, '[data-close-modal]')) { if (window.Hub && Hub.closeModal) Hub.closeModal(); return; }
    if (closest(t, '[data-theme-toggle]')) { if (window.Hub && Hub.theme) Hub.theme(); return; }

    if ((el = closest(t, '[data-body-toggle]'))) { document.body.classList.toggle(el.getAttribute('data-body-toggle')); return; }
    if ((el = closest(t, '[data-body-remove]'))) { document.body.classList.remove(el.getAttribute('data-body-remove')); return; }

    if ((el = closest(t, '[data-remove-target]'))) {
      var rm = document.querySelector(el.getAttribute('data-remove-target'));
      if (rm) rm.remove();
      return;
    }
    if ((el = closest(t, '[data-hide-target]'))) {
      var hd = document.querySelector(el.getAttribute('data-hide-target'));
      if (hd) hd.hidden = true;
      return;
    }
    if ((el = closest(t, '[data-open-target]'))) {
      var od = document.querySelector(el.getAttribute('data-open-target'));
      if (od) { od.open = true; od.scrollIntoView({ behavior: 'smooth' }); }
      return;
    }

    if ((el = closest(t, '[data-check-all]'))) {
      var on = el.getAttribute('data-check-state') === '1', fm = el.closest('form');
      if (fm) fm.querySelectorAll('input[name="' + el.getAttribute('data-check-all').replace(/"/g, '') + '"]')
        .forEach(function (c) { c.checked = on; });
      return;
    }

    if ((el = closest(t, 'button[data-reveal]'))) { reveal(el); return; }

    if ((el = closest(t, '[data-set-value]'))) {
      var sv = document.querySelector(el.getAttribute('data-set-value'));
      if (sv) sv.value = el.getAttribute('data-value') || '';
      return;                                   // الرابطُ يمضي إلى وجهته
    }

    if ((el = closest(t, '[data-require-field]'))) {
      var rq = field(el.form, el.getAttribute('data-require-field'));
      if (rq && !rq.value.trim()) {
        e.preventDefault();
        rq.focus();
        window.alert(el.getAttribute('data-require-msg') || '');
      }
      return;
    }

    if ((el = closest(t, '[data-prompt-field]'))) {
      var ans = window.prompt(el.getAttribute('data-prompt') || '');
      if (!ans) { e.preventDefault(); return; }
      var pf = field(el.form, el.getAttribute('data-prompt-field'));
      if (pf) pf.value = ans;
      return;
    }

    if ((el = closest(t, '[data-fill-into]'))) {
      var card = el.closest('.card'), from = el.parentNode.querySelector(el.getAttribute('data-fill-from'));
      var into = card ? card.querySelector(el.getAttribute('data-fill-into')) : null;
      if (into && from) { into.value = from.textContent; into.focus(); }
      return;
    }

    if (closest(t, '[data-go-back]')) { e.preventDefault(); history.back(); }
  });

  /* كشفُ السرّ: يُجلب من الخادم عند الطلب فيُفرض تخويلُ الخزنة ويُسجَّل «عرضٌ حساس» */
  function reveal(b) {
    var m = b.parentNode.querySelector('[data-secmask]');
    if (!m) return;
    if (b.dataset.open) { m.textContent = '••••••'; delete b.dataset.open; b.textContent = 'إظهار'; return; }
    b.disabled = true;
    var tok = document.querySelector('meta[name=csrf-token]');
    fetch(b.dataset.reveal, { method: 'POST', headers: { 'X-CSRF-TOKEN': tok ? tok.content : '', 'Accept': 'application/json' } })
      .then(function (r) {
        // ٤٢٨ = يتطلّب تأكيدَ الهوية: نُحوّل لشاشة التصعيد ثمّ يعود للكشف
        if (r.status === 428) { return r.json().then(function (j) { if (j && j.url) { window.location = j.url; } throw 428; }); }
        if (!r.ok) throw r.status;
        return r.json();
      })
      .then(function (j) { m.textContent = j.v || '—'; b.dataset.open = '1'; b.textContent = 'إخفاء'; b.disabled = false; })
      .catch(function (s) { if (s === 428) return; b.textContent = s === 403 ? 'غير مخوّل' : 'تعذّر الكشف'; b.disabled = false; });
  }

  document.addEventListener('contextmenu', function (e) {
    if (document.body && document.body.hasAttribute('data-no-contextmenu')) e.preventDefault();
  });

  document.addEventListener('htmx:afterRequest', function (e) {
    var elt = e.detail && e.detail.elt;
    if (elt && elt.closest && elt.closest('[data-reload-after-request]')) location.reload();
  });
})();
