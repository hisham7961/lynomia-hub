@extends('layouts.app')
@section('title', 'الامتثال وأثره')
@section('content')
<div class="hero">
    <div>
        <h2>⚖️ الامتثال وأثره</h2>
        <div class="sub">
            «متأخر» كلمةٌ لا ثمن لها — وهنا يُقال ماذا يقع فعلاً، ومتى يبدأ العمل لا متى يحلّ الموعد.
        </div>
    </div>
    @if (hub_can(auth()->user(), 'compliance', 'a'))
        <a class="btn ghost sm" href="{{ route('m.create', 'compliance') }}">＋ بند التزام</a>
    @endif
</div>

@if ($pulse)
<div class="cards">
    <div class="stat"><span class="ico">📋</span><b>{{ number_format($pulse['n']) }}</b><span>بند التزام</span></div>
    <div class="stat"><span class="ico">🔴</span><b class="{{ $pulse['late'] ? 'txt-bad' : '' }}">{{ $pulse['late'] }}</b><span>متأخر</span></div>
    <div class="stat"><span class="ico">🟡</span><b>{{ $pulse['soon'] }}</b><span>يستحق خلال ٩٠ يوماً</span></div>
    <div class="stat"><span class="ico">💵</span><b>{{ number_format($pulse['fees90'], 0) }}</b>
        <span>رسوم التسعين يوماً ({{ $pulse['cur'] }})</span></div>
</div>
<div class="card" style="margin-bottom:12px">
    <div class="sub">
        رسوم التجديد القادمة تدفّقٌ نقديّ يُخطَّط له — لا مفاجأةٌ تُطلب في يومها.
    </div>
</div>
@endif

{{-- الغائب أخطر من المتأخر: ما لا سجلَّ له لا يحمرّ صفُّه لأنه لا صفَّ له --}}
@if (count($missing))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">🕳️ التزاماتٌ متوقَّعة ولا سجلَّ لها</h3>
    <div class="sub" style="margin-bottom:8px">
        هذه أخطر من المتأخر: <b>ما لا سجلَّ له لا يُفحَص ولا يُنبَّه عنه</b> — فالشركة تبدو سليمة
        لأن لا صفَّ لها يحمرّ.
    </div>
    <table class="mini">
        @foreach ($missing as $m)
            <tr>
                <td><b>{{ $m['company'] }}</b></td>
                <td class="acts">
                    @foreach ($m['gaps'] as $g)<span class="bdg bad">{{ $g }}</span>@endforeach
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- الرادار: ما بدأ وقتُ العمل عليه --}}
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">🎯 ما بدأ وقتُ العمل عليه <span class="bdg {{ collect($radar)->where('tone','bad')->count() ? 'bad' : '' }}">{{ count($radar) }}</span></h3>
    <div class="sub" style="margin-bottom:8px">
        الظهور هنا يبدأ من <b>مهلة إجراء النوع</b> لا من تاريخ استحقاقه — من ينتبه يوم الاستحقاق تأخّر فعلاً.
    </div>
    @forelse ($radar as $r)
        <div class="cmrow">
            <div style="min-width:0;flex:1">
                <a href="{{ route('m.show', ['compliance', $r['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($r['title'], 66) }}</b></a>
                <div class="sub" style="font-size:12px">
                    <span class="bdg {{ $r['tone'] }}">{{ $r['act'] }}</span>
                    {{ $r['kind'] }}@if ($r['authority']) · {{ $r['authority'] }}@endif
                    @if ($r['company']) · {{ $r['company'] }}@endif
                    · {{ $r['owner'] ? 'المسؤول: ' . $r['owner'] : '⚠️ بلا مالك' }}
                    @if ($r['fee']) · رسوم {{ number_format($r['fee'], 0) }}@endif
                </div>
                {{-- الأثر: هذا ما يجعل التاريخ قراراً --}}
                <div class="sub" style="font-size:12px;margin-top:2px">💥 {{ $r['impact'] }}</div>
            </div>
        </div>
    @empty
        <div class="empty" style="padding:24px 12px"><span class="big">✅</span>
            لا بندَ دخل مهلة إجرائه — والرادار يبدأ قبل الموعد بمهلة كل نوع.</div>
    @endforelse
</div>

{{-- ثغرات السجل نفسه --}}
@if (count($gaps))
<div class="card">
    <h3 class="cardtitle">🧩 ثغراتٌ في السجل نفسه <span class="bdg">{{ count($gaps) }}</span></h3>
    @foreach ($gaps as $g)
        <div class="cmrow">
            <span style="flex:none;font-size:16px;width:24px;text-align:center">{{ $g['icon'] }}</span>
            <div style="min-width:0;flex:1">
                <a href="{{ route('m.show', ['compliance', $g['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($g['title'], 66) }}</b></a>
                <div class="sub" style="font-size:12px">
                    <span class="bdg {{ $g['tone'] }}">{{ $g['kind'] }}</span> {{ $g['why'] }}
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

<style>
.cmrow { display:flex; gap:9px; align-items:flex-start; padding:10px 4px; border-bottom:1px solid var(--ln) }
.cmrow:last-child { border-bottom:0 }
</style>
@endsection
