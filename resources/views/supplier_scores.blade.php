@extends('layouts.app')
@section('title', 'تقييم الموردين')
@section('content')
@php $t = $d['totals']; @endphp
<div class="hero">
    <div>
        <h2>🏅 تقييم الموردين</h2>
        <div class="sub">
            بطاقة أداء لكل مورد من <b>سجل مشترياتك الفعلي</b>: الالتزام بالمواعيد، الإنفاق،
            المرتجعات، والأوامر المفتوحة — الأكثر إنفاقاً أولاً حيث القرار أهم.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('supplierscores', ['fresh' => 1]) }}">↻ تحديث</a>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🏢</span><b>{{ number_format($t['suppliers']) }}</b><span>مورد</span></div>
    <div class="stat"><span class="ico">💰</span><b>{{ number_format($t['spend'], 1) }}</b><span>إجمالي الإنفاق</span></div>
    <div class="stat"><span class="ico">⚠️</span><b class="{{ $t['atRisk'] ? 'txt-bad' : '' }}">{{ $t['atRisk'] }}</b><span>درجتهم دون ٥٠</span></div>
    <div class="stat"><span class="ico">➖</span><b>{{ $t['noHistory'] }}</b><span>بلا سجل مشتريات</span></div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th>المورد</th><th>التقييم اليدوي</th><th>أوامر</th><th>إنفاق</th>
            <th>مستلمة</th><th>الالتزام بالموعد</th><th>مرتجعات</th><th>مفتوحة</th><th>غير مسدَّد</th><th>الدرجة</th>
        </tr></thead>
        <tbody>
        @forelse ($d['rows'] as $r)
            <tr>
                <td><a href="{{ route('m.show', ['suppliers', $r['id']]) }}"><b>{{ $r['name'] }}</b></a>
                    <div class="sub">{{ $r['cat'] ?: '—' }}</div></td>
                <td class="sub">{{ $r['stars'] ? str_repeat('⭐', $r['stars']) : '—' }}</td>
                <td><a href="{{ route('m.index', ['purchases', 'f' => ['supplierId' => $r['id']]]) }}">{{ $r['orders'] }}</a></td>
                <td class="sub">{{ number_format($r['spend'], 1) }}</td>
                <td class="sub">{{ $r['received'] }}</td>
                <td>
                    @if ($r['onTimeRate'] === null)<span class="sub">لا مواعيد</span>
                    @else<span class="bdg {{ $r['onTimeRate'] >= 85 ? 'ok' : ($r['onTimeRate'] >= 60 ? 'wn' : 'bad') }}">{{ $r['onTimeRate'] }}٪</span>
                        <span class="sub">من {{ $r['onTimeBase'] }}</span>@endif
                </td>
                <td>@if ($r['returned'])<span class="bdg bad">{{ $r['returned'] }}</span>@else<span class="sub">—</span>@endif</td>
                <td class="sub">{{ $r['open'] ?: '—' }}</td>
                <td>@if ($r['unpaid'])<span class="bdg wn">{{ $r['unpaid'] }}</span>@else<span class="sub">—</span>@endif</td>
                <td>
                    @if ($r['score'] === null)<span class="sub">بلا سجل</span>
                    @else<span class="bdg {{ $r['score'] >= 75 ? 'ok' : ($r['score'] >= 50 ? 'wn' : 'bad') }}"><b>{{ $r['score'] }}</b></span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="10" class="empty"><span class="big">🏅</span>لا موردين مسجَّلين</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف تُحسب الدرجة؟</h3>
    <div class="sub" style="line-height:2">
        <b>الدرجة (٠-١٠٠)</b> = الالتزام بالموعد ×٦٠٪ + (١ − نسبة المرتجعات) ×٤٠٪ — ولا درجة لمن بلا سجل مشتريات (لا نخترع رقماً).<br>
        ⚠️ <b>«الالتزام بالموعد» تقدير لا قياس دقيق</b>: لا يوجد عمود تاريخ استلام صريح، فنقارن تاريخ آخر تحديث للأمر المستلم بموعد تسليمه المتوقع.<br>
        <b>التقييم اليدوي</b> من حقل «التقييم» في ملف المورد — رأيك أنت، مستقلٌّ عن الأرقام. اجمع الاثنين لقرار أعدل.
    </div>
</div>
@endsection
