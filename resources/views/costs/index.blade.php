@extends('layouts.app')
@section('title', 'التكاليف والربحية')
@section('content')
@php $m = fn ($v) => number_format((float) $v, 2); @endphp
<div class="hero">
    <div>
        <h2>💰 التكاليف الفعلية وربحية المشاريع</h2>
        <div class="sub">
            كل رقم مبنيّ على بياناتك: ساعات المهام المنجزة × أجر الساعة المشتق من الرواتب، وتكلفة السيرفرات
            والاشتراكات مطبّعة على مدة المشروع، والمشتريات والمصروفات المرتبطة — مقابل الفواتير الصادرة.
        </div>
    </div>
</div>

@if ($mixed ?? false)
    @include('partials._mixedcur', ['currency' => $currency,
        'what' => 'الإيرادُ والربحُ والهامش أدناه'])
@endif

<div class="cards">
    <div class="stat"><span class="ico">📈</span><b>{{ $m($tot['revenue']) }}</b><span>الإيراد المفوتر ({{ $currency }})</span></div>
    <div class="stat"><span class="ico">📉</span><b>{{ $m($tot['cost']) }}</b><span>إجمالي التكلفة</span></div>
    <div class="stat"><span class="ico">{{ $tot['profit'] >= 0 ? '✅' : '⚠️' }}</span>
        <b class="{{ $tot['profit'] < 0 ? 'txt-bad' : '' }}">{{ $m($tot['profit']) }}</b><span>الربح الإجمالي</span></div>
    <div class="stat"><span class="ico">🎯</span>
        <b class="{{ ($tot['margin'] ?? 0) < 20 ? 'txt-bad' : '' }}">{{ $tot['margin'] !== null ? $tot['margin'] . '٪' : '—' }}</b>
        <span>هامش الربح</span></div>
    <div class="stat"><span class="ico">⏱</span><b>{{ number_format($tot['hours'], 0) }}</b><span>ساعة عمل مسجَّلة</span></div>
    <div class="stat"><span class="ico">🐌</span><b class="{{ $tot['delay'] > 0 ? 'txt-bad' : '' }}">{{ $m($tot['delay']) }}</b><span>تكلفة التأخير التقديرية</span></div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th>المشروع</th><th>الإيراد</th><th>محصّل</th>
            <th>ساعات</th><th>سيرفرات</th><th>أدوات</th><th>خارجية</th>
            <th>التكلفة</th><th>الربح</th><th>الهامش</th><th>التأخير</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $x)
            @php $pl = $x['pl']; @endphp
            <tr>
                <td><a href="{{ route('costs.index', ['p' => $x['p']->id]) }}"><b>{{ $x['p']->name }}</b></a>
                    <div class="sub">{{ $x['p']->status }} · {{ $pl['months'] }} شهر</div></td>
                <td>{{ $m($pl['revenue']['invoiced']) }}</td>
                <td class="sub">{{ $m($pl['revenue']['collected']) }}
                    @if ($pl['revenue']['uncollected'] > 0)<div class="bdg wn">متبقٍ {{ $m($pl['revenue']['uncollected']) }}</div>@endif</td>
                <td class="sub">{{ $m($pl['cost']['hours']) }}<div class="sub">{{ $pl['hours']['logged'] }} س</div></td>
                <td class="sub">{{ $m($pl['cost']['servers']) }}</td>
                <td class="sub">{{ $m($pl['cost']['tools']) }}</td>
                <td class="sub">{{ $m($pl['cost']['external']) }}</td>
                <td><b>{{ $m($pl['cost']['total']) }}</b></td>
                <td><b class="{{ $pl['profit'] < 0 ? 'txt-bad' : '' }}">{{ $m($pl['profit']) }}</b></td>
                <td><span class="bdg {{ $pl['margin'] === null ? '' : ($pl['margin'] < 0 ? 'bad' : ($pl['margin'] < 20 ? 'wn' : 'ok')) }}">
                    {{ $pl['margin'] !== null ? $pl['margin'] . '٪' : '—' }}</span></td>
                <td class="sub">@if ($pl['delay']['days'])<span class="bdg bad">{{ $pl['delay']['days'] }} يوم · {{ $m($pl['delay']['cost']) }}</span>@else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="11" class="empty"><span class="big">💰</span>لا مشاريع بعد</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف تُحسب الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>أجر الساعة</b> = (الراتب + البدلات) ÷ ({{ $rates['days'] }} يوم عمل × {{ $rates['hours'] }} ساعات) —
        يُضبطان من الإعدادات <span class="mono ltr">cost.work_days</span> و<span class="mono ltr">cost.work_hours</span>.
        متوسط الفريق حالياً <b>{{ $rates['avg'] }}</b> {{ $currency }}/ساعة، ويُستخدم لمن لا راتب مسجَّل له.<br>
        <b>تكلفة الساعات</b> = مجموع الساعات <b>الفعلية</b> في مهام المشروع × أجر ساعة منفّذها.<br>
        <b>السيرفرات والأدوات</b> = تكلفة الدورة مطبّعة شهرياً × أشهر تشغيل المشروع (وما كان «مرة واحدة» يُحتسب كاملاً).<br>
        <b>الخارجية</b> = أوامر الشراء + المستندات المالية من نوع «مصروف» المرتبطة بالمشروع.<br>
        <b>تكلفة التأخير</b> = أيام التأخر عن الإطلاق المتوقع × متوسط الحرق اليومي للمشروع.<br>
        ⚠️ الأرقام تعتمد على تسجيلك للساعات الفعلية وربط المصروفات بالمشروع — ما لا يُسجَّل لا يُحتسب.
    </div>
</div>
@endsection
