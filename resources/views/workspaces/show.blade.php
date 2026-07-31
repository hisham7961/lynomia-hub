@extends('layouts.app')
@section('title', $ws['label'])
@section('content')
{{-- صفحة مساحة العمل المركزية (CTO م2): المجال كله في شاشة واحدة --}}
@component('partials.pagehead', ['icon' => $ws['icon'], 'title' => $ws['label'],
    'crumb' => 'مساحات العمل', 'sub' => $ws['desc']])
    @foreach (collect($ws['modules'])->take(1) as $mk)
        @if (hub_can(auth()->user(), $mk, 'a'))
            <a class="btn p sm" href="{{ route('m.create', $mk) }}">＋ {{ hub_mod($mk)['label'] }}</a>
        @endif
    @endforeach
@endcomponent

{{-- شريط تنقل بين المساحات — سياقٌ دائم بلا عودة للقائمة --}}
<div class="toolbar" style="gap:6px">
    @foreach ($all as $k => $w)
        <a class="btn {{ $k === $ws['key'] ? 'p' : 'ghost' }} xs" href="{{ route('workspace', $k) }}">{{ $w['icon'] }} {{ $w['label'] }}</a>
    @endforeach
</div>

{{-- مؤشرات حقيقية: مجاميع محسوبة فعلاً لا نسب مزعومة --}}
@php
    $total = collect($cards)->sum('count');
    $week = collect($cards)->sum('week');
@endphp
<div class="cards">
    <div class="stat"><span class="ico" aria-hidden="true">{{ $ws['icon'] }}</span><b>{{ number_format($total) }}</b><span>سجلاً في المساحة</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">🆕</span><b>{{ number_format($week) }}</b><span>أُضيف آخر ٧ أيام</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">⏳</span><b class="{{ $expiry->count() ? 'txt-bad' : '' }}">{{ $expiry->count() }}</b><span>يستحق أو ينتهي قريباً</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">🗂</span><b>{{ count($ws['modules']) }}</b><span>وحدة نشطة</span></div>
</div>

<div class="kids">
    {{-- بطاقات الوحدات: عدّاد + جديد الأسبوع + دخول وإنشاء --}}
    <div class="card kid wide">
        <h3>🗂 وحدات المساحة</h3>
        <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(210px,100%),1fr))">
            @foreach ($ws['modules'] as $mk)
                @php $def = hub_mod($mk); $look = hub_mod_look($mk); $c = $cards[$mk] ?? null; @endphp
                <a href="{{ route('m.index', $mk) }}" class="stat" style="--st:{{ $look['color'] }};text-decoration:none">
                    <span class="ico" aria-hidden="true">{{ $look['icon'] }}</span>
                    <b>{{ number_format($c['count'] ?? 0) }}</b>
                    <span>{{ $def['label'] }}@if (($c['week'] ?? 0) > 0) · <b style="font-size:11px">+{{ $c['week'] }}</b> هذا الأسبوع @endif</span>
                </a>
            @endforeach
        </div>
        <div class="crow" style="margin-top:10px">
            @foreach ($ws['modules'] as $mk)
                @if (hub_can(auth()->user(), $mk, 'a'))
                    <a class="btn ghost xs" href="{{ route('m.create', $mk) }}">＋ {{ hub_mod($mk)['label'] }}</a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- مراكز المساحة ولوحاتها --}}
    @if (count($ws['centerLinks']))
        <div class="card kid">
            <h3>📊 مراكز المساحة</h3>
            @foreach ($ws['centerLinks'] as $c)
                <a class="btn ghost sm" style="display:flex;margin-bottom:6px;justify-content:flex-start" href="{{ route($c['route']) }}">{{ $c['label'] }}</a>
            @endforeach
        </div>
    @endif

    {{-- يستحق قريباً — من رادار الانتهاء الحقيقي --}}
    <div class="card kid">
        <h3>⏳ يستحق أو ينتهي قريباً</h3>
        <table class="mini">
            @forelse ($expiry as $i)
                <tr>
                    <td><a href="{{ route('m.show', [$i['module'], $i['id']]) }}">{{ \Illuminate\Support\Str::limit($i['name'], 34) }}</a>
                        <div class="sub">{{ $i['mlabel'] }} · {{ $i['flabel'] }}</div></td>
                    <td class="acts"><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'bad' : 'wn') }}">{{ $i['days'] < 0 ? 'متأخر ' . abs($i['days']) : $i['days'] }} يوم</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا استحقاقات قريبة في هذه المساحة</td></tr>
            @endforelse
        </table>
    </div>

    {{-- آخر النشاط في وحدات المساحة --}}
    <div class="card kid wide">
        <h3>🕘 آخر النشاط</h3>
        <table class="mini">
            @forelse ($activity as $a)
                <tr>
                    <td class="acts mono sub">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
                    <td>{{ $actors[$a->user_id] ?? '—' }} · <b>{{ $a->action }}</b>
                        <span class="sub">{{ hub_mod($a->module)['label'] ?? $a->module }}{{ $a->name ? ' — ' . $a->name : '' }}</span></td>
                    <td class="acts">
                        @if ($a->record_id && hub_mod($a->module))
                            <a class="btn ghost xs" href="{{ route('m.show', [$a->module, $a->record_id]) }}">فتح</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا نشاط مسجلاً بعد في وحدات هذه المساحة</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
