@extends('layouts.app')
@section('title', 'كتالوج العهد')
@section('content')
<div class="hero">
    <div>
        <h2>🏷️ كتالوج العهد</h2>
        <div class="sub">
            الأصنافُ أولاً بكودها الأساسي، ثم ما في كل صنف، ثم تفاصيلُ العنصر —
            وكلُّ عهدةٍ بكودٍ يُطبَع على ملصقها.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('assets.life') }}">💼 دورة الحياة</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'assets') }}">📋 الجدول الكامل</a>
        @if (hub_can(auth()->user(), 'assets', 'a'))
            <a class="btn p sm" href="{{ route('m.create', 'assets') }}">＋ عهدة جديدة</a>
        @endif
    </div>
</div>

@php
    $tot = collect($cats);
    $val = $tot->pluck('value')->filter(fn ($v) => $v !== null)->sum();
@endphp
<div class="cards">
    <div class="stat"><span class="ico">🗂️</span><b>{{ number_format($tot->count()) }}</b><span>صنفاً مستعملاً</span></div>
    <div class="stat"><span class="ico">📦</span><b>{{ number_format($tot->sum('n')) }}</b><span>عهدة مسجَّلة</span></div>
    <div class="stat"><span class="ico">🤲</span><b>{{ number_format($tot->sum('held')) }}</b><span>بيد موظفين</span></div>
    <div class="stat"><span class="ico">🟢</span><b>{{ number_format($tot->sum('free')) }}</b><span>متاحة بلا حائز</span></div>
    @if ($tot->contains(fn ($c) => $c['value'] !== null))
        <div class="stat"><span class="ico">💰</span><b>{{ number_format($val, 0) }}</b><span>قيمة الشراء ({{ $cur }})</span></div>
    @endif
</div>

{{-- ما خرج بتصريحٍ ولم يعد: التصريحُ بلا متابعةٍ ورقةٌ لا حارس --}}
@if (count($overdue))
<div class="card" style="border-color:var(--bad)">
    <h3 class="cardtitle">⏰ خرجت بتصريحٍ ولم تعد <span class="bdg bad">{{ count($overdue) }}</span></h3>
    <table class="tbl">
        <thead><tr><th>العهدة</th><th>التصريح</th><th>الجهة</th><th>موعد العودة</th><th></th></tr></thead>
        <tbody>
        @foreach ($overdue as $o)
            <tr>
                <td><a href="{{ route('m.show', ['assets', $o['assetId']]) }}"><b>{{ $o['asset'] }}</b></a>
                    <div class="sub">{{ $o['action'] }}</div></td>
                <td class="mono">{{ $o['permit'] }}</td>
                <td>{{ $o['to'] }}</td>
                <td class="mono">{{ $o['due'] }}</td>
                <td class="acts"><span class="bdg bad">متأخرة {{ $o['late'] }} يوماً</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@forelse ($cats as $c)
    @if ($loop->first)<div class="catgrid">@endif
    <a class="catcard" href="{{ route('custody.category', $c['code']) }}">
        <span class="ci" aria-hidden="true">{{ $c['icon'] }}</span>
        <div class="cn">
            <b>{{ $c['name'] }}</b>
            <span class="mono sub">{{ $c['code'] }}</span>
        </div>
        <div class="cq"><b>{{ number_format($c['n']) }}</b><span class="sub">عهدة</span></div>
        <div class="cm">
            <span class="bdg g">🤲 {{ number_format($c['held']) }} بيد موظف</span>
            <span class="bdg {{ $c['free'] ? 'ok' : 'g' }}">🟢 {{ number_format($c['free']) }} متاحة</span>
            @if ($c['value'] !== null && $c['value'] > 0)
                <span class="bdg">💰 {{ number_format($c['value'], 0) }} {{ $cur }}</span>
            @endif
        </div>
    </a>
    @if ($loop->last)</div>@endif
@empty
    @include('partials.empty', [
        'icon' => '🏷️',
        'text' => 'لا عهدة مسجَّلة بعد — أضف أولى العهد ويولّد النظام كودها وملصقها تلقائياً.',
        'cta' => hub_can(auth()->user(), 'assets', 'a') ? route('m.create', 'assets') : null,
        'ctaLabel' => '＋ عهدة جديدة',
    ])
@endforelse

<style>
.catgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.catcard{display:grid;grid-template-columns:auto 1fr auto;grid-template-areas:"ci cn cq" "cm cm cm";
    gap:4px 12px;align-items:center;background:var(--cd);border:1px solid var(--ln);border-radius:var(--r);
    padding:15px 16px;transition:box-shadow .2s,transform .2s,border-color .2s}
.catcard:hover{box-shadow:var(--shh);transform:translateY(-2px);border-color:color-mix(in srgb,var(--p) 35%,var(--ln))}
.catcard .ci{grid-area:ci;font-size:24px}
.catcard .cn{grid-area:cn;display:flex;flex-direction:column;gap:2px;min-width:0}
.catcard .cn b{font-size:15px}
.catcard .cq{grid-area:cq;text-align:center;line-height:1.1}
.catcard .cq b{font-size:21px;font-variant-numeric:tabular-nums}
.catcard .cm{grid-area:cm;display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
</style>
@endsection
