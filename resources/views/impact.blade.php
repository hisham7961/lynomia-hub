@extends('layouts.app')
@section('title', 'خريطة الأثر')
@section('content')
<div class="hero">
    <div>
        <h2>🕸️ خريطة الأثر</h2>
        <div class="sub">
            سجل الاعتماديات مقلوباً: بدل «على ماذا يعتمد نظامي» — <b>ماذا يسقط إن سقط هذا العنصر</b>.
            العناصر مرتبة بالأخطر: الحرجة أولاً ثم الأكثر اعتماداً عليها.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('m.index', 'deps') }}">سجل الاعتماديات ←</a>
</div>

@include('partials.lens', ['lensModules' => ['deps']])

@if ($spof)
    <div class="flash bad" style="margin-bottom:12px">
        ⚠️ <b>{{ $spof }}</b> عنصراً يمثّل نقطة فشل مفردة — حرج أو يعتمد عليه أكثر من نظام. راجع بدائلها قبل أن تختبرك الحياة.
    </div>
@endif

<div class="cards" style="grid-template-columns:1fr">
@forelse ($nodes as $n)
    @php $tone = $n['crit'] >= 4 ? 'bad' : ($n['crit'] >= 3 ? 'wn' : ''); @endphp
    <div class="card">
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;justify-content:space-between">
            <div>
                <b style="font-size:15px">{{ $n['name'] }}</b>
                @foreach ($n['types'] ?: ['غير محدد'] as $ty)<span class="bdg">{{ $ty }}</span>@endforeach
                @if ($n['critLabel'])<span class="bdg {{ $tone }}">{{ $n['critLabel'] }}</span>@endif
                @if ($n['rto'] !== null)<span class="bdg">تعافٍ مقبول {{ $n['rto'] }} ساعة</span>@endif
            </div>
            <span class="sub">{{ count($n['dependents']) }} نظام يعتمد عليه</span>
        </div>

        @if ($n['dependents'])
            <div style="margin-top:8px">
                <span class="sub">يسقط معه:</span>
                @foreach ($n['dependents'] as $d)<span class="bdg {{ $tone }}">⬇ {{ $d }}</span>@endforeach
            </div>
        @else
            <div class="sub" style="margin-top:8px">لم يُحدَّد أي نظام يعتمد عليه — أكمل السجل ليصح الأثر</div>
        @endif

        @if ($n['impacts'])
            <div class="sub" style="margin-top:8px"><b>الأثر:</b> {{ \Illuminate\Support\Str::limit(implode(' · ', array_unique($n['impacts'])), 240) }}</div>
        @endif

        <div class="sub" style="margin-top:6px">
            <b>البديل:</b> {{ $n['fallback'] ? \Illuminate\Support\Str::limit($n['fallback'], 160) : '⚠️ لا بديل مسجَّل — هذه أخطر من العطل نفسه' }}
        </div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">🕸️</span>
        لا اعتماديات مسجَّلة بعد — سجّل ما يعتمد عليه كل نظام في وحدة «سجل الاعتماديات» لتُبنى الخريطة تلقائياً
    </div></div>
@endforelse
</div>
@endsection
