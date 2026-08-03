@extends('layouts.app')
@section('title', $role ? 'تعديل دور' : 'دور جديد')
@section('content')
@include('partials.pagehead', ['icon' => '🎭', 'title' => $role ? 'تعديل دور: ' . $role->name : 'دور جديد',
    'crumb' => 'الأدوار والصلاحيات', 'crumbUrl' => route('roles.index'), 'back' => route('roles.index'), 'backLabel' => 'الأدوار',
    'sub' => 'ابدأ من قالبٍ أو من دورٍ قائم، ثم اضبط ما تحتاجه وحده — لا حاجة للمرور على كل وحدة'])

@php
    // **ما بنيتَه يبقى بعد خطأ التحقّق**: الصفحة كانت تُعاد من قاعدة البيانات
    // لا من `old()`، فاسمٌ فارغ يمحو مئة نقرةٍ على المصفوفة والرايات والقيود.
    $mx = $role ? (is_array($role->matrix) ? $role->matrix : (json_decode($role->matrix ?? '[]', true) ?: [])) : [];
    $rfFlags = $role ? (is_array($role->flags) ? $role->flags : (json_decode($role->flags ?? '[]', true) ?: [])) : [];
    $fr = $role ? (is_array($role->field_rules) ? $role->field_rules : (json_decode($role->field_rules ?? '[]', true) ?: [])) : [];
    if (old('matrix_submitted')) $mx = (array) old('matrix', []);
    if (old('flags_submitted')) $rfFlags = (array) old('flags', []);
    if (old('fr_submitted')) {
        $fr = collect((array) old('fr', []))->map(fn ($f) => array_filter((array) $f,
            fn ($m) => in_array($m, ['ro', 'hide'], true)))->filter()->all();
    }
    $risky = \App\Http\Controllers\Web\RoleController::RISKY_FLAGS;
    $modCount = collect($groups)->sum(fn ($g) => count($g['items']));
@endphp

<form method="POST" action="{{ $role ? route('roles.update', $role) : route('roles.store') }}" id="roleform">
    @csrf @if($role)@method('PUT')@endif

    <div class="card">
        <div class="fg">
            <div class="fld"><label for="rf-name">اسم الدور <b class="req">*</b></label>
                <input class="inp" id="rf-name" name="name" value="{{ old('name', $role?->name) }}" required></div>
            <div class="fld"><label for="rf-scope">النطاق</label>
                <select class="inp" id="rf-scope" name="scope">
                    <option value="all" @selected(old('scope', $role?->scope) === 'all')>كل النظام</option>
                    <option value="proj" @selected(old('scope', $role?->scope ?? 'proj') === 'proj')>حسب المشروع — يرى مشاريعه وسجلاتها فقط</option>
                </select>
            </div>
        </div>

        @unless ($role)
            {{-- قالبٌ جاهز: بدايةٌ لا قيد — يملأ الشبكة ثم تُعدَّل بحرية --}}
            <h3 style="margin-top:14px">🧩 قالب جاهز <span class="sub">(اختياري — يملأ المصفوفة والرايات، ثم عدّل ما تشاء)</span></h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;margin-top:6px">
                @foreach ($templates as $tk => $t)
                    <label style="display:block;padding:9px 11px;border:1px solid var(--ln);border-radius:11px;cursor:pointer">
                        <span style="display:flex;gap:7px;align-items:center">
                            <input type="radio" name="template" value="{{ $tk }}" @checked(old('template') === $tk)>
                            <b style="font-size:13px">{{ $t['label'] }}</b>
                        </span>
                        <span class="sub" style="display:block;margin-top:4px;font-size:12px;line-height:1.75">{{ $t['why'] }}</span>
                    </label>
                @endforeach
                <label style="display:block;padding:9px 11px;border:1px dashed var(--ln);border-radius:11px;cursor:pointer">
                    <span style="display:flex;gap:7px;align-items:center">
                        <input type="radio" name="template" value="" @checked(! old('template'))>
                        <b style="font-size:13px">✏️ من الصفر</b>
                    </span>
                    <span class="sub" style="display:block;margin-top:4px;font-size:12px">لا شيء مُحدَّد — اضبط المصفوفة بنفسك.</span>
                </label>
            </div>
            <div class="sub" style="margin-top:6px">القالب يسري عند الحفظ إن تركت المصفوفة فارغة؛ وإن أشّرت أي مربّعٍ أدناه فاختيارك يعلو عليه.</div>

            @if (count($others))
                <div class="sub" style="margin-top:8px">أو ابدأ من دورٍ قائم: انسخه من
                    <a href="{{ route('roles.index') }}">قائمة الأدوار</a> (زرّ «استنسخ») ثم عدّل النسخة.</div>
            @endif
        @endunless
    </div>

    {{-- الرايات العامة --}}
    <div class="card" style="margin-top:12px">
        <h3>🚩 الصلاحيات العامة <span class="sub">(خارج مصفوفة الوحدات)</span></h3>
        <div class="sub" style="margin-bottom:8px">
            لا تُشتق من الوحدات: من يعتمد الطلبات، ومن يرى سجل التدقيق واللوحات،
            ومن يكشف أسرار الخزنة أو ينسخها، ومن يُصدّر البيانات.
            <br><b>ملاحظة:</b> «نسخ السرّ للحافظة» ليس حاجزاً دون الكشف — منفذ الجلب واحد
            والنصّ يصل جهاز المستخدم في الحالين، والفرق أن الشاشة لا تعرضه. امنحه بحذر الكشف نفسه.
        </div>
        {{-- علامةٌ صريحة: بها يُفرَّق «أُلغيت كلها» عن «لم يُرسَل القسم» --}}
        <input type="hidden" name="flags_submitted" value="1">
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            @foreach (\App\Http\Controllers\Web\RoleController::FLAGS as $fk => $flabel)
                @php $isRisky = in_array($fk, $risky, true); @endphp
                <label class="chip" style="cursor:pointer;{{ $isRisky ? 'border-color:var(--wn)' : '' }}" for="rf-flag-{{ $fk }}">
                    <input type="checkbox" id="rf-flag-{{ $fk }}" name="flags[{{ $fk }}]" value="1"
                           aria-label="{{ $flabel }}" @checked(! empty($rfFlags[$fk]))>
                    {{ $isRisky ? '⚠️ ' : '' }}{{ $flabel }}
                </label>
            @endforeach
        </div>
        <div class="sub" style="margin-top:6px">⚠️ الموسومة تمنح وصولاً واسعاً خارج نطاق الدور — «إدارة المستخدمين» خاصةً تصنع حسابات وتبدّل أدوارها.</div>
    </div>

    {{-- المصفوفة: مجمَّعةٌ كما في الشريط الجانبي، بأدوات جماعية وبحث --}}
    <div class="card" style="margin-top:12px">
        <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">🔐 مصفوفة الصلاحيات <span class="sub">({{ $modCount }} وحدة في {{ count($groups) }} مجموعات)</span></h3>
            <span class="bdg" id="mxcount">—</span>
        </div>
        <div class="crow" style="gap:8px;flex-wrap:wrap;margin:8px 0">
            <input class="inp" id="mxq" placeholder="🔎 ابحث في الوحدات" autocomplete="off" style="flex:1;min-width:200px">
            @foreach ($ops as $op => $lbl)
                <button type="button" class="btn ghost sm" data-col="{{ $op }}">كل «{{ $lbl }}»</button>
            @endforeach
            <button type="button" class="btn ghost sm" data-col="none">✕ امسح الكل</button>
        </div>
        <div class="sub" style="margin-bottom:8px">
            تأشير «إضافة» أو «تعديل» أو «حذف» يُفعّل «عرض» تلقائياً عند الحفظ — دورٌ يكتب ولا يرى لا يعمل.
        </div>

        @foreach ($groups as $gLabel => $g)
            @php $gSet = collect($g['items'])->filter(fn ($m, $k) => ! empty($mx[$k]))->count(); @endphp
            <details class="mxgrp" data-g="{{ $gLabel }}" {{ $gSet ? 'open' : '' }} style="margin-bottom:8px;border:1px solid var(--ln);border-radius:11px;padding:8px 10px">
                <summary style="cursor:pointer;font-weight:700">
                    {{ $g['icon'] }} {{ $gLabel }}
                    <span class="sub">({{ count($g['items']) }})</span>
                    <span class="bdg {{ $gSet ? 'ok' : '' }}" data-gcount="{{ $gLabel }}">{{ $gSet }} مُفعَّلة</span>
                </summary>
                <div class="crow" style="gap:6px;flex-wrap:wrap;margin:8px 0">
                    <button type="button" class="btn ghost xs" data-grp="{{ $gLabel }}" data-set="v">عرض للكل</button>
                    <button type="button" class="btn ghost xs" data-grp="{{ $gLabel }}" data-set="vae">عرض وإضافة وتعديل</button>
                    <button type="button" class="btn ghost xs" data-grp="{{ $gLabel }}" data-set="vaed">كل شيء</button>
                    <button type="button" class="btn ghost xs" data-grp="{{ $gLabel }}" data-set="">لا شيء</button>
                </div>
                <div class="tblwrap"><table class="tbl matrix">
                    <thead><tr><th>الوحدة</th>@foreach ($ops as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($g['items'] as $mk => $md)
                        <tr class="mxrow" data-q="{{ mb_strtolower($md['label'] . ' ' . $mk) }}">
                            <td>{{ $md['label'] }}
                                <button type="button" class="btn ghost xs" data-row-toggle aria-label="تبديل كل صلاحيات {{ $md['label'] }}">الكل</button>
                            </td>
                            @foreach ($ops as $op => $l)
                                <td class="c"><input type="checkbox" class="mxbox" data-op="{{ $op }}"
                                    name="matrix[{{ $mk }}][{{ $op }}]" value="1"
                                    aria-label="{{ $l }} — {{ $md['label'] }}" @checked(!empty($mx[$mk][$op]))></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </details>
        @endforeach
        <div class="sub" id="mxnone" style="display:none">لا وحدة تطابق بحثك.</div>
    </div>

    {{-- صلاحيات الحقول: مطويّةٌ كلها، وتُفتح المضبوطة وحدها --}}
    <div class="card" style="margin-top:12px">
        <h3>🔬 صلاحيات مستوى الحقل <span class="sub">(اختياري — عادي · قراءة فقط · مخفي تماماً)</span></h3>
        <div class="sub" style="margin-bottom:8px">
            مثال: أخفِ «الراتب» في ملفات الموظفين عن هذا الدور — يختفي من الجداول والصفحات والتصدير والـAPI،
            ولا يُكتب حتى لو حُقن في الطلب. المالك يرى كل شيءٍ دائماً.
            @php $frCount = collect($fr)->sum(fn ($f) => count(array_filter((array) $f))); @endphp
            @if ($frCount)<b>القيود المضبوطة الآن: {{ $frCount }}.</b>@endif
        </div>
        {{-- **لا قائمةَ لكل حقلٍ في النظام**: القائمة المنسدلة تُرسَل دائماً ولو
             كانت «عادي»، و١٢٢٠ منها تتجاوز سقف PHP الافتراضي (max_input_vars=1000)
             فيُقصّ الطلب صمتاً وتُمحى القيود الواقعة في الذيل. تُعرض القيود
             القائمة وحدها، وتُضاف الجديدة من مُنتقٍ لا يزيد الطلب إلا بما يُضبط. --}}
        <input type="hidden" name="fr_submitted" value="1">

        <div id="frlist">
            @foreach ($fr as $mk => $fields)
                @php $md = hub_mod((string) $mk); @endphp
                @if ($md)
                    @foreach ((array) $fields as $fk => $mode)
                        @php $fld = collect($md['fields'] ?? [])->firstWhere('key', $fk); @endphp
                        @if ($fld && in_array($mode, ['ro', 'hide'], true))
                            <div class="crow frrow" style="gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;border-top:1px solid var(--ln)">
                                <b style="font-size:13px">{{ $md['label'] }}</b>
                                <span class="sub">›</span>
                                <span>{{ $fld['label'] }}</span>
                                <select class="inp" name="fr[{{ $mk }}][{{ $fk }}]" style="max-width:150px">
                                    <option value="ro" @selected($mode === 'ro')>قراءة فقط</option>
                                    <option value="hide" @selected($mode === 'hide')>مخفي</option>
                                    <option value="">أزِل القيد</option>
                                </select>
                            </div>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>
        @if (! $frCount)<div class="sub" id="frempty">لا قيود مضبوطة — كل الحقول عادية لهذا الدور.</div>@endif

        <div class="crow" style="gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--ln)">
            <select class="inp" id="fr-mod" style="max-width:200px"><option value="">＋ اختر وحدة…</option>
                @foreach ($groups as $gLabel => $g)
                    <optgroup label="{{ $gLabel }}">
                        @foreach ($g['items'] as $mk => $md)<option value="{{ $mk }}">{{ $md['label'] }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <select class="inp" id="fr-field" style="max-width:220px" disabled><option value="">الحقل…</option></select>
            <select class="inp" id="fr-mode" style="max-width:150px">
                <option value="ro">قراءة فقط</option><option value="hide">مخفي</option>
            </select>
            <button type="button" class="btn ghost sm" id="fr-add">أضِف القيد</button>
        </div>
        @php
            // كتالوج المُنتقي: بيانٌ لا مدخلات — لا يزيد حجم الطلب بحرف
            $frCatalog = [];
            foreach ($groups as $g) {
                foreach ($g['items'] as $ck => $cm) {
                    $frCatalog[$ck] = ['label' => $cm['label'], 'fields' => array_map(
                        fn ($f) => ['k' => $f['key'], 'l' => $f['label']], (array) ($cm['fields'] ?? []))];
                }
            }
        @endphp
        <script type="application/json" id="fr-catalog">@json($frCatalog)</script>
    </div>

    <input type="hidden" name="matrix_submitted" value="1">
    <div class="formfoot"><button class="btn p">حفظ الدور</button><a class="btn ghost" href="{{ route('roles.index') }}">إلغاء</a></div>
</form>

<script>
(function () {
    var form = document.getElementById('roleform');
    if (! form) return;
    var q = document.getElementById('mxq'), none = document.getElementById('mxnone'),
        count = document.getElementById('mxcount');

    function recount() {
        var mods = {}, n = 0;
        form.querySelectorAll('.mxbox:checked').forEach(function (b) {
            var m = b.name.slice(7, b.name.indexOf(']'));
            if (! mods[m]) { mods[m] = 1; n++; }
        });
        count.textContent = n + ' وحدة مُفعَّلة';
        form.querySelectorAll('.mxgrp').forEach(function (g) {
            var c = 0;
            g.querySelectorAll('.mxrow').forEach(function (r) {
                if (r.querySelector('.mxbox:checked')) c++;
            });
            var b = g.querySelector('[data-gcount]');
            if (b) { b.textContent = c + ' مُفعَّلة'; b.className = 'bdg' + (c ? ' ok' : ''); }
        });
    }

    // الكتابة تستلزم العرض — في الشاشة كما في الحفظ، فلا يرى المستخدم حالةً لا تعمل
    form.addEventListener('change', function (e) {
        var b = e.target;
        if (b.classList && b.classList.contains('mxbox')) {
            if (b.checked && b.dataset.op !== 'v') {
                var v = b.closest('tr').querySelector('.mxbox[data-op="v"]');
                if (v) v.checked = true;
            }
            if (! b.checked && b.dataset.op === 'v') {
                b.closest('tr').querySelectorAll('.mxbox').forEach(function (x) { x.checked = false; });
            }
            recount();
        }
    });

    form.addEventListener('click', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-row-toggle')) {
            var boxes = t.closest('tr').querySelectorAll('.mxbox'),
                all = Array.prototype.every.call(boxes, function (b) { return b.checked; });
            boxes.forEach(function (b) { b.checked = ! all; });
            recount(); e.preventDefault();
        }
        if (t.dataset && t.dataset.col) {
            var op = t.dataset.col;
            form.querySelectorAll('.mxrow').forEach(function (r) {
                if (r.style.display === 'none') return;
                r.querySelectorAll('.mxbox').forEach(function (b) {
                    if (op === 'none') b.checked = false;
                    else if (b.dataset.op === op) b.checked = true;
                    else if (op !== 'v' && b.dataset.op === 'v') b.checked = true;
                });
            });
            recount(); e.preventDefault();
        }
        if (t.dataset && t.dataset.grp !== undefined && t.dataset.set !== undefined) {
            var set = t.dataset.set;
            t.closest('.mxgrp').querySelectorAll('.mxrow').forEach(function (r) {
                r.querySelectorAll('.mxbox').forEach(function (b) {
                    b.checked = set.indexOf(b.dataset.op) > -1;
                });
            });
            recount(); e.preventDefault();
        }
    });

    function filter(input, sel, hideEmptyGroups) {
        var t = (input.value || '').trim().toLowerCase(), shown = 0;
        document.querySelectorAll(sel).forEach(function (row) {
            var ok = ! t || row.dataset.q.indexOf(t) > -1;
            row.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        if (hideEmptyGroups) {
            document.querySelectorAll('.mxgrp').forEach(function (g) {
                var vis = Array.prototype.some.call(g.querySelectorAll('.mxrow'),
                    function (r) { return r.style.display !== 'none'; });
                g.style.display = vis ? '' : 'none';
                if (t && vis) g.open = true;
            });
            none.style.display = shown ? 'none' : '';
        }
    }
    q.addEventListener('input', function () { filter(q, '.mxrow', true); });
    recount();

    // مُنتقي قيود الحقول: يبني صفّاً واحداً لكل قيدٍ يُضبط — فلا يحمل الطلب
    // إلا ما ضُبط فعلاً بدل قائمةٍ لكل حقلٍ في النظام
    var cat = {}, catEl = document.getElementById('fr-catalog');
    try { cat = JSON.parse(catEl.textContent); } catch (e) {}
    var mSel = document.getElementById('fr-mod'), fSel = document.getElementById('fr-field'),
        modeSel = document.getElementById('fr-mode'), addBtn = document.getElementById('fr-add'),
        list = document.getElementById('frlist'), empty = document.getElementById('frempty');

    if (mSel) mSel.addEventListener('change', function () {
        var m = cat[mSel.value];
        fSel.innerHTML = '<option value="">الحقل…</option>';
        fSel.disabled = ! m;
        if (! m) return;
        m.fields.forEach(function (f) {
            var o = document.createElement('option'); o.value = f.k; o.textContent = f.l; fSel.appendChild(o);
        });
    });

    if (addBtn) addBtn.addEventListener('click', function () {
        var mk = mSel.value, fk = fSel.value, mode = modeSel.value;
        if (! mk || ! fk) return;
        if (list.querySelector('[name="fr[' + mk + '][' + fk + ']"]')) return;
        var m = cat[mk], f = m.fields.find(function (x) { return x.k === fk; });
        var row = document.createElement('div');
        row.className = 'crow frrow';
        row.style.cssText = 'gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;border-top:1px solid var(--ln)';
        row.innerHTML = '<b style="font-size:13px"></b><span class="sub">›</span><span></span>'
            + '<select class="inp" name="fr[' + mk + '][' + fk + ']" style="max-width:150px">'
            + '<option value="ro">قراءة فقط</option><option value="hide">مخفي</option>'
            + '<option value="">أزِل القيد</option></select>';
        row.querySelector('b').textContent = m.label;
        row.querySelectorAll('span')[1].textContent = f.l;
        row.querySelector('select').value = mode;
        list.appendChild(row);
        if (empty) empty.style.display = 'none';
        fSel.value = '';
    });
})();
</script>
@endsection
