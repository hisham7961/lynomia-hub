@extends('layouts.app')
@section('title', 'مركز الذكاء الاصطناعي')
@section('content')

{{-- ═══ نظرة — القسمُ الأوّلُ (المرحلة ٢ · W8 · §١١) ═══
     تجيب عن سؤالٍ واحد: **أيعمل؟ وإن لم يعمل فما الخطوةُ التالية؟**
     ولذلك ليست لوحةَ أرقامٍ تُزيّن. --}}

@php
    $ladder = [
        'NOT_CONFIGURED' => ['', 'غيرُ مُهيَّأ'],
        'DISABLED'       => ['wn', 'مُطفأ'],
        'READY'          => ['wn', 'جاهزٌ ولم يُختبر توليدٌ بعد'],
        'ENABLED'        => ['ok', 'يعمل — وتوليدٌ فعليٌّ تحقّق'],
        'DEVELOPMENT'    => ['wn', 'قيدَ التطوير'],
        'UNKNOWN'        => ['wn', 'غيرُ معلوم'],
    ];
    [$tone, $word] = $ladder[$snap['status']] ?? ['wn', $snap['status']];
    $sev = ['blocker' => ['', '⛔'], 'warn' => ['wn', '⚠️'], 'info' => ['', 'ℹ️']];
@endphp

<div class="hero">
    <div>
        <h2>🤖 مركز الذكاء الاصطناعي</h2>
        <div class="sub">
            بوّابةُ النماذجِ والمزوّدون والنماذجُ والتوجيه — <b>وحالةُ كلٍّ منها
            كما هي لا كما نتمنّاها</b>.
        </div>
    </div>
</div>

@include('ai._sections')

{{-- ═══ سلّمُ الجاهزيّة — من سجلِّ القدراتِ لا سلّمٌ ثانٍ ═══ --}}
<div class="cards">
    <div class="stat"><span class="ico bdg {{ $tone }}">{{ $tone === 'ok' ? '✅' : '◻️' }}</span>
        <b>{{ $word }}</b>
        <span>{!! e($snap['reason']) !!}</span></div>
    <div class="stat"><span class="ico bdg">🔌</span>
        <b>{{ $snap['counts']['providers_enabled'] }} / {{ $snap['counts']['providers'] }} مزوّداً مُشغَّلاً</b>
        <span>ومنها {{ $snap['counts']['providers_verified'] }} اعتماداً مُتحقَّقاً.</span></div>
    <div class="stat"><span class="ico bdg">🧠</span>
        <b>{{ $snap['counts']['models_enabled'] }} / {{ $snap['counts']['models'] }} نموذجاً مُفعَّلاً</b>
        <span><b>والاستيرادُ لا يُفعِّل</b> — التفعيلُ قرارٌ صريح.</span></div>
    <div class="stat"><span class="ico bdg">🎯</span>
        <b>{{ $snap['counts']['profiles_ready'] }} / {{ $snap['counts']['profiles'] }} غرضاً جاهزاً</b>
        <span>الجاهزُ ما سلسلتُه فيها نموذجٌ صالحٌ — لا ما هو موجود.</span></div>
</div>

{{-- ═══ الخطوةُ التاليةُ الواحدة — لا «لا توجد بيانات» ═══ --}}
@if ($snap['next'] !== null)
    <div class="card">
        <h3>الخطوةُ التالية</h3>
        <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <b>{{ $snap['next']['title'] }}</b>
            @if ($snap['next']['route'])
                <a class="btn sm" href="{{ route($snap['next']['route']) }}">اذهب →</a>
            @endif
        </div>
    </div>
@endif

{{--
    ═══ مسارُ قبولِ الإنتاج — ثماني درجاتٍ **حالتُها مقروءةٌ لا مُؤشَّرة** ═══

    والخطوةُ التاليةُ أعلاه تقول «ما الآن»، وهذا يقول **«كم بقي»**. ومالكٌ
    يوشك أن يُدخل اعتماداً مدفوعاً يستحقّ أن يرى الطريقَ كاملاً قبل أن يخطو.
--}}
<div class="card">
    <h3>مسارُ التشغيلِ الحقيقيّ <span class="mut">(من الواجهةِ وحدَها — بلا طرفيّةٍ ولا شيفرة)</span></h3>
    <div class="row" style="flex-wrap:wrap;gap:8px;margin-top:8px">
        @foreach ($path as $stepRow)
            <div class="row" style="gap:6px;align-items:center;border:1px solid var(--line);
                        border-radius:8px;padding:6px 10px">
                <span class="bdg {{ $stepRow['done'] ? 'ok' : 'wn' }}">{{ $stepRow['done'] ? '✓' : '•' }}</span>
                <span class="mono ltr mut">{{ $stepRow['key'] }}</span>
                @if ($stepRow['route'] && ! $stepRow['done'])
                    <a href="{{ route($stepRow['route']) }}">{{ $stepRow['title'] }}</a>
                @else
                    <span>{{ $stepRow['title'] }}</span>
                @endif
            </div>
        @endforeach
    </div>
</div>

{{-- ═══ يحتاج انتباهاً — مرتَّباً بالشدّةِ لا بالزمن ═══ --}}
<div class="card">
    <h3>يحتاج انتباهاً <span class="mut">({{ count($snap['attention']) }})</span></h3>

    @forelse ($snap['attention'] as $a)
        @php([$tone2, $icon] = $sev[$a['severity']] ?? ['wn', '•'])
        <div class="row" style="border-top:1px solid var(--line);padding:10px 0;gap:10px;flex-wrap:wrap;align-items:flex-start">
            <span class="bdg {{ $tone2 }}">{{ $icon }}
                {{ $a['severity'] === 'blocker' ? 'مانع' : ($a['severity'] === 'warn' ? 'تحذير' : 'معلومة') }}</span>
            <div style="flex:1;min-width:240px">
                <b>{{ $a['title'] }}</b>
                <div class="sub mut">{{ $a['why'] }}</div>
            </div>
            @if ($a['route'])
                <a class="btn sm" href="{{ route($a['route']) }}">أصلِح</a>
            @endif
        </div>
    @empty
        <div class="empty">
            <b>لا شيءَ يحتاج انتباهاً الآن.</b>
            <div class="sub">البوّابةُ والمزوّدون والنماذجُ والأغراضُ في حالٍ متّسق.</div>
        </div>
    @endforelse
</div>

@endsection
