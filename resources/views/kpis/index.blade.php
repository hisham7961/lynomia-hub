@extends('layouts.app')
@section('title', 'باني مؤشرات KPI')
@section('content')
<div class="hero">
    <div>
        <h2>📈 باني مؤشرات KPI</h2>
        <div class="sub">
            عرّف مؤشراتك أنت — <b>عدد أو مجموع أو متوسط</b> فوق أي وحدة بفلتر حالة، أو <b>نسبة بين مقياسين</b>.
            القيم محسوبة حيّاً من بياناتك، بلا كود ولا صيغ حرة.
        </div>
    </div>
</div>

{{-- المؤشرات المحسوبة --}}
@if (count($kpis))
    <div class="cards">
        @foreach ($kpis as $k)
            <div class="stat" style="position:relative">
                <span class="ico">📊</span>
                <b class="{{ $k['tone'] === 'bad' ? 'txt-bad' : '' }}">
                    {{ $k['value'] === null ? '—' : rtrim(rtrim(number_format($k['value'], 1), '0'), '.') }}{{ $k['unit'] ? ' ' . $k['unit'] : '' }}
                </b>
                <span>{{ $k['name'] }}</span>
                @if ($k['target'] !== null)
                    <span class="bdg {{ $k['tone'] ?: 'g' }}" style="margin-top:4px">
                        الهدف {{ rtrim(rtrim(number_format($k['target'], 1), '0'), '.') }} {{ $k['good'] === 'up' ? '↑' : '↓' }}
                    </span>
                @endif
                <form method="POST" action="{{ route('kpis.destroy', $k['id']) }}" style="position:absolute;top:6px;inset-inline-end:6px"
                      onsubmit="return confirm('حذف المؤشر «{{ $k['name'] }}»؟')">
                    @csrf @method('DELETE')<button class="btn ghost xs" aria-label="حذف المؤشر {{ $k['name'] }}">✕</button>
                </form>
            </div>
        @endforeach
    </div>
@else
    <div class="card"><div class="sub" style="padding:16px;text-align:center">لا مؤشرات بعد — ابنِ أول مؤشر أدناه 👇</div></div>
@endif

{{-- الباني --}}
<div class="card" style="margin-top:12px">
    <h3>➕ مؤشر جديد</h3>
    <form method="POST" action="{{ route('kpis.store') }}" id="kpiform">
        @csrf
        <div class="fg">
            <div class="fld"><label for="k-name">اسم المؤشر <b class="req">*</b></label>
                <input class="inp" id="k-name" name="name" required maxlength="190" placeholder="نسبة تحصيل الفواتير"></div>
            <div class="fld"><label for="k-unit">وحدة العرض</label>
                <input class="inp" id="k-unit" name="unit" maxlength="30" placeholder="٪ / د.ك / عدد"></div>
            <div class="fld"><label for="k-target">الهدف (اختياري)</label>
                <input class="inp ltr" id="k-target" name="target" type="number" step="any"></div>
            <div class="fld"><label for="k-good">الأفضل</label>
                <select class="inp" id="k-good" name="good"><option value="up">الأعلى أفضل ↑</option><option value="down">الأقل أفضل ↓</option></select></div>
        </div>

        @php
            $metricFields = function ($p) use ($catalog) {
                return ['p' => $p, 'catalog' => $catalog];
            };
        @endphp

        <h4 style="margin:12px 0 6px">المقياس الأول <b class="req">*</b></h4>
        @include('kpis._metric', ['p' => 'a', 'catalog' => $catalog, 'required' => true])

        <h4 style="margin:12px 0 6px">العملية</h4>
        <select class="inp" name="combine" id="k-combine" onchange="document.getElementById('bwrap').style.display=this.value==='none'?'none':''" style="max-width:320px">
            <option value="none">لا شيء — المقياس الأول وحده</option>
            <option value="ratio_pct">نسبة مئوية: الأول ÷ الثاني × ١٠٠</option>
            <option value="ratio">نسبة: الأول ÷ الثاني</option>
            <option value="diff">الفرق: الأول − الثاني</option>
            <option value="sum">المجموع: الأول + الثاني</option>
        </select>

        <div id="bwrap" style="display:none">
            <h4 style="margin:12px 0 6px">المقياس الثاني</h4>
            @include('kpis._metric', ['p' => 'b', 'catalog' => $catalog, 'required' => false])
        </div>

        <div class="toolbar" style="margin-top:12px"><button class="btn p">💾 أضف المؤشر</button></div>
        @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
    </form>
</div>

<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        <b>مثال:</b> «نسبة تحصيل الفواتير» = مجموع «المدفوع» في المالية ÷ مجموع «الإجمالي» × ١٠٠، الوحدة ٪، الهدف ٩٠، الأعلى أفضل.<br>
        <b>مثال:</b> «مشاريع قيد التنفيذ» = عدد المشاريع بحالة «قيد التنفيذ».<br>
        ⚠️ القيمة تحترم صلاحياتك ونطاق مشاريعك — كل مستخدم يرى المؤشر محسوباً على ما يخصه.
    </div>
</div>
@endsection
