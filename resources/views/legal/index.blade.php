@extends('layouts.app')
@section('title', 'المركز القانوني')
@section('content')
<div class="hero">
    <div>
        <h2>⚖️ المركز القانوني</h2>
        <div class="sub">العقود والرخص والعلامات والتأمين والالتزامات — والتجديدات قبل فوات أوانها</div>
    </div>
    @if (hub_can(auth()->user(), 'contracts', 'a'))
        <a class="btn p sm" href="{{ route('m.create', 'contracts') }}">＋ مستند قانوني</a>
    @endif
</div>

<div class="cards">
    <div class="stat"><span class="ico">📜</span><b>{{ $kpi['active'] }}</b><span>مستندات سارية</span></div>
    <div class="stat"><span class="ico">⏳</span><b class="{{ $kpi['soon'] ? 'txt-bad' : '' }}">{{ $kpi['soon'] }}</b><span>تنتهي خلال ٦٠ يوماً</span></div>
    <div class="stat"><span class="ico">🚨</span><b class="{{ $kpi['overdue'] ? 'txt-bad' : '' }}">{{ $kpi['overdue'] }}</b><span>تجاوزت النهاية بلا تجديد</span></div>
    <div class="stat"><span class="ico">💼</span><b>{{ number_format($kpi['value'], 0) }}</b><span>قيمة الساري ({{ $currency }})</span></div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🧭 التوزيع بالأنواع</h3>
        @include('partials.chart_donut', ['slices' => $types])
    </div>

    <div class="card kid wide">
        <h3>⏳ يستحق التجديد <span class="bdg wn">{{ $expiring->count() }}</span></h3>
        <table class="mini">
            @forelse ($expiring as $c)
                <tr>
                    <td><a href="{{ route('m.show', ['contracts', $c->id]) }}">{{ \Illuminate\Support\Str::limit($c->title, 40) }}</a>
                        <div class="sub">{{ $c->type }}{{ $c->party ? ' · ' . $c->party : '' }}{{ $c->value ? ' · ' . number_format((float) $c->value, 0) . ' ' . $c->currency : '' }}{{ $c->renewal ? ' · التجديد: ' . $c->renewal : '' }}</div></td>
                    <td style="width:1%;white-space:nowrap">
                        @if ($c->days < 0)<span class="bdg bad">متجاوز بـ{{ abs($c->days) }} يوم</span>
                        @elseif ($c->days <= 14)<span class="bdg bad">{{ $c->days }} يوم</span>
                        @else<span class="bdg wn">{{ $c->days }} يوم</span>@endif
                    </td>
                    <td style="width:1%">
                        @if (hub_can(auth()->user(), 'contracts', 'e'))
                            <a class="btn ghost xs" href="{{ route('m.edit', ['contracts', $c->id]) }}">تجديد/تعديل</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:16px;text-align:center">لا مستندات تقترب من النهاية 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid wide">
        <h3>📋 التزامات مسجلة</h3>
        <table class="mini">
            @forelse ($obligations as $o)
                <tr>
                    <td style="width:30%"><a href="{{ route('m.show', ['contracts', $o->id]) }}">{{ \Illuminate\Support\Str::limit($o->title, 32) }}</a>
                        <div class="sub">{{ $o->type }}</div></td>
                    <td class="sub">{{ \Illuminate\Support\Str::limit($o->obligations, 160) }}</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:16px;text-align:center">لا التزامات مسجلة — سجّلها في حقل «الالتزامات» بكل عقد</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
