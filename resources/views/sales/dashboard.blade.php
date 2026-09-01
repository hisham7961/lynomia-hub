@extends('layouts.app')
@section('title', 'لوحة المبيعات')
@section('content')
@php
    $c = $d['counts'];
    $money = fn ($map) => empty($map) ? '—' : collect($map)->map(fn ($v, $k) => number_format($v, 3) . ' ' . $k)->implode(' · ');
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>التحليلات</span><span aria-hidden="true">‹</span><b>لوحة المبيعات</b></nav>
        <h2>💼 لوحة المبيعات والعروض</h2>
        <div class="sub">صحّةُ خطّ العروض والتحويل والربحية — مجمَّعةً بالعملة (لا تحويلَ عملات)</div>
    </div>
    <a class="btn ghost sm" href="{{ route('m.index', 'quotes') }}">🧾 كل العروض</a>
</div>

<div class="cards">
    <div class="stat"><span class="ico">📝</span><b>{{ $c['draft'] }}</b><span>مسودة/مراجعة</span></div>
    <div class="stat"><span class="ico">📨</span><b>{{ $c['sent'] }}</b><span>مُرسلة/تفاوض</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ $c['accepted'] }}</b><span>مقبولة</span></div>
    <div class="stat"><span class="ico">🚫</span><b>{{ $c['lost'] }}</b><span>مرفوضة/منتهية</span></div>
    <div class="stat"><span class="ico">⏳</span><b class="{{ $c['expiring'] ? 'txt-bad' : '' }}">{{ $c['expiring'] }}</b><span>تنتهي خلال ٧ أيام</span></div>
    <div class="stat"><span class="ico">🎯</span><b>{{ $d['winRate'] !== null ? $d['winRate'] . '٪' : '—' }}</b><span>معدّل الفوز</span></div>
    <div class="stat"><span class="ico">🚀</span><b>{{ $d['convRate'] !== null ? $d['convRate'] . '٪' : '—' }}</b><span>تحوّلت لمشاريع</span></div>
    @if ($internal)
        <div class="stat"><span class="ico">📊</span><b>{{ $d['avgMargin'] !== null ? $d['avgMargin'] . '٪' : '—' }}</b><span>متوسّط الهامش</span></div>
    @endif
    <div class="stat"><span class="ico">🏷️</span><b>{{ $d['avgDisc'] !== null ? $d['avgDisc'] . '٪' : '—' }}</b><span>متوسّط الخصم</span></div>
    <div class="stat"><span class="ico">⏱️</span><b>{{ $d['avgDays'] !== null ? $d['avgDays'] : '—' }}</b><span>أيام حتى القبول</span></div>
</div>

<div class="card">
    <h3 class="cardtitle">💰 القيمة بالعملة</h3>
    <div class="tblwrap"><table>
        <thead><tr><th>المرحلة</th><th>القيمة</th></tr></thead>
        <tbody>
            <tr><td>مسودة/مراجعة</td><td class="mono">{{ $money($d['valueByCur']['draft']) }}</td></tr>
            <tr><td>مُرسلة/تفاوض</td><td class="mono">{{ $money($d['valueByCur']['sent']) }}</td></tr>
            <tr><td><b>مقبولة</b></td><td class="mono"><b>{{ $money($d['valueByCur']['accepted']) }}</b></td></tr>
            <tr><td>إيرادٌ شهريّ متكرّر MRR (مقبول)</td><td class="mono">{{ $money($d['recurringByCur']['mrr']) }}</td></tr>
            <tr><td>إيرادٌ سنويّ متكرّر ARR (مقبول)</td><td class="mono">{{ $money($d['recurringByCur']['arr']) }}</td></tr>
        </tbody>
    </table></div>
</div>

<div class="kids">
    <div class="card">
        <h3 class="cardtitle">🔭 تنبّؤ خطّ الأنابيب <span class="bdg g">{{ $d['pipeline']['open'] ?? 0 }} فرصة مفتوحة</span></h3>
        @php $pl = $d['pipeline']; @endphp
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">القيمة الخام</div><b class="mono">{{ number_format($pl['raw'] ?? 0, 3) }} {{ $pl['cur'] ?? '' }}</b></div>
            <div><div class="sub">المرجَّح (×احتمال)</div><b class="mono">{{ number_format($pl['weighted'] ?? 0, 3) }} {{ $pl['cur'] ?? '' }}</b></div>
        </div>
        <div class="sub" style="margin-top:6px">من مراحل العملاء المفتوحة × احتمال الإغلاق — بلا محرّك تحويل عملات.</div>
    </div>

    <div class="card">
        <h3 class="cardtitle">🥇 أعلى المسؤولين (مقبول)</h3>
        @if (! empty($d['byOwner']))
            <div class="tblwrap"><table>
                <thead><tr><th>المسؤول</th><th>عدد</th><th>القيمة</th></tr></thead>
                <tbody>
                @foreach ($d['byOwner'] as $o)
                    <tr><td>{{ $o['name'] }}</td><td class="mono">{{ $o['count'] }}</td>
                        <td class="mono">{{ number_format($o['value'], 3) }} {{ $o['cur'] }}{{ $o['mixed'] ? ' (مختلطة)' : '' }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else<div class="empty">لا عروضٌ مقبولةٌ بعد</div>@endif
    </div>

    <div class="card">
        <h3 class="cardtitle">📉 أسباب الخسارة</h3>
        @if (! empty($d['lostReasons']))
            <div class="tblwrap"><table>
                <thead><tr><th>السبب</th><th>عدد</th></tr></thead>
                <tbody>
                @foreach ($d['lostReasons'] as $r)
                    <tr><td>{{ $r['reason'] }}</td><td class="mono">{{ $r['n'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else<div class="empty">لا خسائرَ مسجَّلةٌ بسببها بعد</div>@endif
    </div>
</div>
@endsection
