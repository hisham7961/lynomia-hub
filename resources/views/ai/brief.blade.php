@extends('layouts.app')
@section('title', 'موجز الأسبوع')
@section('content')
<div class="hero">
    <div>
        <h2>🗓️ موجز الأسبوع <span class="bdg i">🤖 ذكاء اصطناعي</span></h2>
        <div class="sub">خمسةُ أمورٍ على الأكثر تحتاج قرارَك هذا الأسبوع — مختارةٌ من تنبيهات النظام واقتراحات الذكاء والتزاماتٍ مُصعَّدة
            ومؤشّراتٍ خارج الهدف ومخاطرِ المشاريع. يُبنى صباحَ كلِّ أحد.</div>
    </div>
    <form method="POST" action="{{ route('ai.brief.refresh') }}">@csrf
        <button class="btn ghost sm">🔄 أعد بناءه الآن</button>
    </form>
</div>

@if (! $enabled)
    <div class="card"><span class="bdg wn">الجدولةُ مطفأة</span> <span class="sub">فعّل ai.exec_brief ليُبنى وحدَه كلَّ أحد — والزرُّ يعمل الآن على كلِّ حال.</span></div>
@endif

@if (! $brief)
    <div class="card">@include('partials.empty', ['text' => 'لم يُبنَ موجزٌ بعد.', 'icon' => '🗓️'])</div>
@else
    @if ($brief->status !== 'ok')<div class="card"><span class="bdg wn">آخرُ محاولةٍ تعذّرت</span> <span class="sub">{{ $brief->error_code }}</span></div>@endif
    <div class="sub" style="margin:6px 0">أسبوع {{ $brief->week }} · من {{ $brief->signals }} تنبيهاً · بُني {{ $brief->generated_at }}</div>
    @foreach ($items as $i => $it)
        <div class="card">
            <h3 class="cardtitle">{{ $i + 1 }}. {{ $it['title'] }}
                @if (! empty($it['sev']))<span class="bdg {{ $it['sev'] === 'حرج' ? 'bad' : 'wn' }}">{{ $it['sev'] }}</span>@endif</h3>
            @if (! empty($it['why']))<div class="sub">لماذا الآن: {{ $it['why'] }}</div>@endif
            @if (! empty($it['decision']))<div><b>القرارُ المطلوب:</b> {{ $it['decision'] }}</div>@endif
            @if (! empty($it['url']))<a class="btn ghost xs" href="{{ $it['url'] }}">افتح ↗</a>@endif
        </div>
    @endforeach
@endif
@endsection
