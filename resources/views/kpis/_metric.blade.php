{{-- مقياس واحد — يتوقع: $p (بادئة a|b) $catalog، و$sel اختيارياً للتعديل --}}
@php
    $sel = $sel ?? [];
    $selMod = (string) ($sel['module'] ?? '');
    // خيارات العمود والحالة تُطبع من الخادم عند التعديل: لولا ذلك لبقيت القوائم
    // فارغةً حتى يلمس المستخدم قائمة الوحدة، فيُحفظ المؤشر بمقياسٍ منزوع العمود
    $selCat = $catalog[$selMod] ?? ['nums' => [], 'states' => []];
@endphp
<div class="fg">
    <div class="fld">
        <label for="{{ $p }}-agg">الحساب</label>
        <select class="inp" id="{{ $p }}-agg" name="{{ $p }}_agg">
            @foreach (['count' => 'عدد السجلات', 'sum' => 'مجموع عمود', 'avg' => 'متوسط عمود'] as $v => $lbl)
                <option value="{{ $v }}" @selected(($sel['agg'] ?? '') === $v)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-module">الوحدة</label>
        <select class="inp" id="{{ $p }}-module" name="{{ $p }}_module" data-kpimod="{{ $p }}" onchange="kpiSync('{{ $p }}')">
            <option value="">— اختر وحدة —</option>
            @foreach ($catalog as $mk => $c)
                <option value="{{ $mk }}" @selected($selMod === $mk)>{{ $c['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-col">العمود (للمجموع/المتوسط)</label>
        <select class="inp" id="{{ $p }}-col" name="{{ $p }}_col">
            <option value="">—</option>
            @foreach ($selCat['nums'] ?? [] as $n)
                <option value="{{ $n['key'] }}" @selected(($sel['col'] ?? '') === $n['key'])>{{ $n['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-st">فلتر الحالة (اختياري)</label>
        <select class="inp" id="{{ $p }}-st" name="{{ $p }}_st">
            <option value="">كل الحالات</option>
            @foreach ($selCat['states'] ?? [] as $s)
                <option value="{{ $s }}" @selected(($sel['st'] ?? '') === $s)>{{ $s }}</option>
            @endforeach
        </select>
    </div>
</div>

<script>
window.KPICAT = window.KPICAT || @json(collect($catalog)->map(fn ($c) => ['nums' => $c['nums'], 'states' => $c['states']]));
function kpiSync(p) {
    var mod = document.getElementById(p + '-module').value;
    var col = document.getElementById(p + '-col'), st = document.getElementById(p + '-st');
    col.innerHTML = '<option value="">—</option>';
    st.innerHTML = '<option value="">كل الحالات</option>';
    var c = window.KPICAT[mod];
    if (!c) return;
    (c.nums || []).forEach(function (n) { col.insertAdjacentHTML('beforeend', '<option value="' + n.key + '">' + n.label + '</option>'); });
    (c.states || []).forEach(function (s) { st.insertAdjacentHTML('beforeend', '<option value="' + s + '">' + s + '</option>'); });
}
</script>
