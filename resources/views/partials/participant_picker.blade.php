{{-- ═══════════ منتقي المشاركين (Participant Picker · DEFECT B) ═══════════
     بديلٌ صالحٌ للاستعمال عن `<select multiple>` الغامض: الجميعُ يبدأ **غيرَ محدَّد**،
     يبحث المستخدمُ ويختار **صراحةً** بمربّعات اختيار، فتظهر رقائقُ المحدَّدين وعدُّهم،
     ولكلٍّ إزالةٌ صريحة، ويُلغى التحديدُ كلُّه بنقرة. غيرُ المحدَّدين يبقَون **خارجَ** المجموعة.

     تدرّجٌ آمن (بلا جافاسكربت): قائمةُ مربّعاتِ اختيارٍ مُعنونةٌ صالحةٌ للاستعمال وحدَها —
     كلُّ موظّفٍ صفٌّ يُنقَر فيُحدَّد. البحثُ والرقائقُ والعدُّ والإلغاء تحسيناتٌ تدريجيّة.

     المعامِلات:
       $candidates : iterable  (id => "الاسم")  أو  (id => ['name'=>, 'sub'=>])
       $field      : اسمُ الحقل (افتراضي participants[])
       $pickerId   : مُعرِّفٌ فريدٌ في الصفحة (افتراضي 'pp')
       $selected   : معرّفاتٌ محدَّدةٌ سلفاً (افتراضي [] — الأصلُ لا شيء)
       $label      : عنوانُ المنتقي (افتراضي 'اختيار المشاركين')
       $emptyHint  : نصُّ «لا مرشّحين» حين تكون القائمةُ فارغة
--}}
@php
    $field     = $field     ?? 'participants[]';
    $pickerId  = $pickerId  ?? 'pp';
    $selected  = array_map('strval', (array) ($selected ?? []));
    $label     = $label     ?? 'اختيار المشاركين';
    $emptyHint = $emptyHint ?? 'لا زملاءَ متاحين للإضافة.';
    $ppCount   = 0;
@endphp

<div class="pp" id="{{ $pickerId }}" data-pp>
    <label class="lbl" for="{{ $pickerId }}-q">{{ $label }}</label>

    @if (! count($candidates))
        <div class="sub pp-none">{{ $emptyHint }}</div>
    @else
        <div class="pp-search">
            <input type="search" id="{{ $pickerId }}-q" class="inp pp-q" data-pp-q autocomplete="off"
                   placeholder="🔎 ابحث عن موظف بالاسم أو المسمّى…" role="searchbox"
                   aria-controls="{{ $pickerId }}-list" aria-label="البحث عن موظف">
        </div>

        <div class="pp-bar">
            <span class="pp-count" data-pp-count aria-live="polite">المحدَّدون: <b>{{ count($selected) }}</b></span>
            <button type="button" class="lnk sub pp-clear" data-pp-clear @unless(count($selected)) hidden @endunless>إلغاء التحديد</button>
        </div>

        {{-- رقائقُ المحدَّدين — تُبنى بالجافاسكربت من المربّعات المحدَّدة (تحسينٌ تدريجيّ) --}}
        <div class="pp-chips" data-pp-chips aria-live="polite"></div>

        <ul class="pp-list" id="{{ $pickerId }}-list" data-pp-list role="listbox" aria-multiselectable="true" aria-label="{{ $label }}">
            @foreach ($candidates as $id => $c)
                @php
                    $nm  = is_array($c) ? ($c['name'] ?? '') : $c;
                    $sub = is_array($c) ? trim((string) ($c['sub'] ?? '')) : '';
                    $ini = mb_substr($nm !== '' ? $nm : '؟', 0, 1);
                    $sel = in_array((string) $id, $selected, true);
                    $rid = $pickerId . '-cb-' . $loop->index;
                    $hay = mb_strtolower(trim($nm . ' ' . $sub));
                @endphp
                <li class="pp-row" role="option" aria-selected="{{ $sel ? 'true' : 'false' }}" data-pp-row data-name="{{ $hay }}">
                    <label class="pp-lab" for="{{ $rid }}">
                        <input type="checkbox" id="{{ $rid }}" class="pp-cb" data-pp-cb
                               name="{{ $field }}" value="{{ $id }}" @checked($sel)>
                        <span class="pp-ava" aria-hidden="true">{{ $ini }}</span>
                        <span class="pp-meta">
                            <span class="pp-nm">{{ $nm }}</span>
                            @if ($sub !== '')<span class="pp-sub">{{ $sub }}</span>@endif
                        </span>
                        <span class="pp-tick" aria-hidden="true">✓</span>
                    </label>
                </li>
            @endforeach
        </ul>
        <div class="pp-empty" data-pp-empty hidden>لا نتائجَ مطابقةً — جرّب اسماً آخر.</div>
    @endif
</div>

@once
<style>
.pp { margin-top:4px }
.pp-none { padding:10px 2px }
.pp-search { margin-top:4px }
.pp-q { width:100% }
.pp-bar { display:flex; align-items:center; justify-content:space-between; gap:8px; margin:7px 0 4px }
.pp-count { font-size:12.5px; color:var(--sb) }
.pp-count b { color:var(--tx) }
.pp-clear { font-size:12px; background:none; border:0; cursor:pointer; padding:0 }
.pp-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px }
.pp-chips:empty { display:none }
.pp-chip { display:inline-flex; align-items:center; gap:6px; padding:3px 6px 3px 9px; border-radius:99px;
           background:var(--pss); border:1px solid var(--ln); font-size:12px; color:inherit; cursor:default }
.pp-chip button { border:0; background:none; cursor:pointer; font-size:13px; line-height:1; color:var(--sb); padding:0 2px }
.pp-chip button:hover { color:var(--bad) }
.pp-list { list-style:none; margin:0; padding:0; max-height:230px; overflow:auto; border:1px solid var(--ln); border-radius:10px }
.pp-row { border-top:1px solid var(--ln) }
.pp-row:first-child { border-top:0 }
.pp-lab { display:flex; align-items:center; gap:9px; padding:7px 10px; cursor:pointer; margin:0 }
.pp-lab:hover { background:var(--pss) }
.pp-cb { flex:none; width:16px; height:16px; accent-color:var(--p); cursor:pointer }
.pp-ava { flex:none; width:26px; height:26px; border-radius:50%; background:var(--pss); border:1px solid var(--ln);
          display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:600 }
.pp-meta { flex:1; min-width:0; display:flex; flex-direction:column; line-height:1.35 }
.pp-nm { font-size:13.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.pp-sub { font-size:11px; color:var(--sb); overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.pp-tick { flex:none; opacity:0; color:var(--ok); font-weight:700 }
.pp-row[aria-selected="true"] .pp-tick { opacity:1 }
.pp-row[aria-selected="true"] .pp-lab { background:var(--pss) }
.pp-empty { padding:12px 10px; font-size:12.5px; color:var(--sb); text-align:center }
</style>
<script>
/* منتقي المشاركين — يعمل على أيِّ عددٍ من المنتقيات في الصفحة، بلا اعتماديّة.
   يُبقي aria-selected والعدَّ والرقائقَ متّسقةً مع المربّعات (مصدرُ الحقيقة). */
(function () {
    function initPicker(root) {
        if (root.dataset.ppReady) return;
        root.dataset.ppReady = '1';
        var list   = root.querySelector('[data-pp-list]');
        if (!list) return;
        var q      = root.querySelector('[data-pp-q]');
        var chips  = root.querySelector('[data-pp-chips]');
        var countB = root.querySelector('[data-pp-count] b');
        var clear  = root.querySelector('[data-pp-clear]');
        var empty  = root.querySelector('[data-pp-empty]');
        var cbs    = Array.prototype.slice.call(root.querySelectorAll('[data-pp-cb]'));

        function refresh() {
            var sel = cbs.filter(function (c) { return c.checked; });
            if (countB) countB.textContent = String(sel.length);
            if (clear)  clear.hidden = sel.length === 0;
            cbs.forEach(function (c) {
                var row = c.closest('[data-pp-row]');
                if (row) row.setAttribute('aria-selected', c.checked ? 'true' : 'false');
            });
            if (chips) {
                chips.textContent = '';
                sel.forEach(function (c) {
                    var row = c.closest('[data-pp-row]');
                    var nm  = row ? (row.querySelector('.pp-nm') || {}).textContent : c.value;
                    var chip = document.createElement('span');
                    chip.className = 'pp-chip';
                    chip.appendChild(document.createTextNode(nm || c.value));
                    var x = document.createElement('button');
                    x.type = 'button';
                    x.setAttribute('aria-label', 'إزالة ' + (nm || ''));
                    x.textContent = '✕';
                    x.addEventListener('click', function () { c.checked = false; refresh(); });
                    chip.appendChild(x);
                    chips.appendChild(chip);
                });
            }
        }
        function filter() {
            var term = (q.value || '').trim().toLowerCase();
            var any = false;
            cbs.forEach(function (c) {
                var row = c.closest('[data-pp-row]');
                if (!row) return;
                var hit = term === '' || (row.getAttribute('data-name') || '').indexOf(term) !== -1;
                row.hidden = !hit;
                if (hit) any = true;
            });
            if (empty) empty.hidden = any;
        }
        cbs.forEach(function (c) { c.addEventListener('change', refresh); });
        if (q) q.addEventListener('input', filter);
        if (clear) clear.addEventListener('click', function () {
            cbs.forEach(function (c) { c.checked = false; }); refresh();
        });
        refresh();
    }
    function boot() {
        document.querySelectorAll('[data-pp]').forEach(initPicker);
    }
    if (document.readyState !== 'loading') boot();
    else document.addEventListener('DOMContentLoaded', boot);
    // منتقياتٌ تُحقَن لاحقاً (لوحاتُ الإنشاء في المركز) — نُصدِّر المُهيّئ
    window.HubPicker = { boot: boot, init: initPicker };
})();
</script>
@endonce
