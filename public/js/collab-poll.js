/*
 * مركزُ التواصل — الاستطلاعُ التدريجيّ (§38/§39).
 *
 * يجلب الرسائلَ الجديدةَ منذ مؤشّرٍ (keyset) لا الخيطَ كلَّه في كلِّ نبضة. عقدُ
 * الأحداثِ نفسُه الذي سيبثّه websocket لاحقاً (message.created/deleted) — فحين
 * يُضاف البثُّ لا يتغيّر عقدُ العميل (§38). **تدهورٌ رشيق:** إن سقط الطلبُ يبقى
 * كلُّ شيءٍ يعمل، والصفحةُ تُظهر الرسائلَ كاملةً عند التحديث اليدويّ («لا تُعطَّل
 * المحادثةُ لأن الاتصال سقط»).
 *
 * الإطارُ يُعلن نفسَه بسمات على عنصرٍ حاوٍ:
 *   data-collab-poll · data-poll-url · data-cursor · data-target (id) · data-kind (channel|dm)
 */
(function () {
  'use strict';

  var INTERVAL = 7000;   // نبضةٌ كلَّ ٧ ثوانٍ — استضافةٌ عاديّة بلا عمليّةٍ دائمة
  var MAX_ERRORS = 5;    // بعد أخطاءٍ متتالية نكفّ (تدهورٌ رشيق، لا نبضٌ أعمى)

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function appendChannel(target, ev) {
    if (document.getElementById('c-' + ev.id)) return; // موجودٌ سلفاً (لا تكرار)
    var el = document.createElement('div');
    el.className = 'cmt';
    el.id = 'c-' + ev.id;
    el.style.cssText = 'padding:8px 6px;border-top:1px solid var(--brd)';
    el.innerHTML = '<b>' + esc(ev.author || '') + '</b> '
      + '<span style="white-space:pre-wrap;word-break:break-word">' + esc(ev.body) + '</span>'
      + (ev.edited ? ' <span class="sub" style="font-size:11px">(عُدّل)</span>' : '');
    target.appendChild(el);
  }

  function appendDm(target, ev) {
    var id = 'dm-' + ev.id;
    var existing = document.getElementById(id);
    if (ev.deleted) {
      if (existing) existing.innerHTML = '<div class="sub" style="padding:7px 12px;border:1px dashed var(--ln);border-radius:14px;font-size:12.5px">🚫 حُذفت رسالة</div>';
      return;
    }
    if (existing) return;
    var el = document.createElement('div');
    el.className = 'msgw';
    el.id = id;
    el.style.cssText = 'max-width:76%;margin-top:7px;' + (ev.mine ? 'align-self:flex-end' : 'align-self:flex-start');
    el.innerHTML = '<div style="padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.85;'
      + (ev.mine ? 'background:var(--p);color:#fff' : 'background:var(--pss)') + '">' + esc(ev.body) + '</div>';
    target.appendChild(el);
  }

  function renderTyping(box, names) {
    if (!box) return;
    if (!names || !names.length) { box.textContent = ''; box.hidden = true; return; }
    var t = names.length === 1 ? (names[0] + ' يكتب…')
      : (names.length === 2 ? (names[0] + ' و' + names[1] + ' يكتبان…')
      : (names.length + ' أشخاص يكتبون…'));
    box.textContent = '✍️ ' + t; box.hidden = false;
  }

  function wireComposer(host) {
    var typeUrl = host.getAttribute('data-typing-url');
    var csrf = host.getAttribute('data-csrf');
    var sel = host.getAttribute('data-composer');
    if (!typeUrl || !sel) return;
    var box = sel && document.querySelector(sel);
    if (!box) return;
    var last = 0;
    box.addEventListener('input', function () {
      var now = Date.now();
      if (now - last < 3000) return;   // مضبوطُ المعدّل: نبضةٌ كلَّ ٣ ثوانٍ على الأكثر
      last = now;
      fetch(typeUrl, { method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrf || '', 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
    });
  }

  function start(host) {
    var url = host.getAttribute('data-poll-url');
    var kind = host.getAttribute('data-kind');
    var target = document.getElementById(host.getAttribute('data-target'));
    if (!url || !target) return;

    var typingBox = document.getElementById(host.getAttribute('data-typing-box') || '');
    wireComposer(host);

    var cursor = host.getAttribute('data-cursor') || '';
    var errors = 0;

    function tick() {
      if (document.hidden) return;   // لا نبضَ لتبويبٍ مخفيّ (توفيرٌ)
      fetch(url + '?cursor=' + encodeURIComponent(cursor), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      }).then(function (data) {
        errors = 0;
        if (data && data.cursor) cursor = data.cursor;
        var added = false;
        (data && data.events || []).forEach(function (ev) {
          if (kind === 'dm') appendDm(target, ev);
          else appendChannel(target, ev);
          added = true;
        });
        renderTyping(typingBox, data && data.typing);
        if (added && kind === 'dm') target.scrollTop = target.scrollHeight;
      }).catch(function () {
        if (++errors >= MAX_ERRORS) clearInterval(timer);   // تدهورٌ رشيق
      });
    }

    var timer = setInterval(tick, INTERVAL);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var hosts = document.querySelectorAll('[data-collab-poll]');
    for (var i = 0; i < hosts.length; i++) start(hosts[i]);
  });
})();
