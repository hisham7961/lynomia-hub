{{-- مقياس واحد — يتوقع: $p (بادئة a|b) $catalog --}}
<div class="fg">
    <div class="fld">
        <label for="{{ $p }}-agg">الحساب</label>
        <select class="inp" id="{{ $p }}-agg" name="{{ $p }}_agg">
            <option value="count">عدد السجلات</option>
            <option value="sum">مجموع عمود</option>
            <option value="avg">متوسط عمود</option>
        </select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-module">الوحدة</label>
        <select class="inp" id="{{ $p }}-module" name="{{ $p }}_module" data-kpimod="{{ $p }}" onchange="kpiSync('{{ $p }}')">
            <option value="">— اختر وحدة —</option>
            @foreach ($catalog as $mk => $c)
                <option value="{{ $mk }}">{{ $c['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-col">العمود (للمجموع/المتوسط)</label>
        <select class="inp" id="{{ $p }}-col" name="{{ $p }}_col"><option value="">—</option></select>
    </div>
    <div class="fld">
        <label for="{{ $p }}-st">فلتر الحالة (اختياري)</label>
        <select class="inp" id="{{ $p }}-st" name="{{ $p }}_st"><option value="">كل الحالات</option></select>
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
