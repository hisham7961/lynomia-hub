@extends('layouts.app')
@section('title', 'تشخيص الذكاء الاصطناعي')
@section('content')

{{-- ═══ التشخيص — أينَ انقطع الخيط (المرحلة ٢ · W8 · §١١) ═══
     «401» رسالةٌ صحيحةٌ عديمةُ النفع: أمفتاحُ البوّابةِ خاطئٌ أم اعتمادُ
     المزوّدِ عندها؟ الرمزُ واحدٌ والإصلاحُ مختلفٌ تماماً. --}}

@php
    $tone = ['ok' => ['ok', '✅'], 'broken' => ['', '⛔'], 'unknown' => ['wn', '❔']];
@endphp

<div class="hero">
    <div>
        <h2>🩺 التشخيص</h2>
        <div class="sub">
            أربعُ حلقاتٍ مرتّبةٍ بالاعتماد — <b>وأوّلُ مقطوعةٍ تُوقِف القراءة</b>.
            فلا معنى لفحصِ اعتمادٍ ما دامت البوّابةُ لا تردّ.
        </div>
    </div>
</div>

@include('ai._sections')

{{-- ═══ شجرةُ القرار ═══ --}}
<div class="card">
    <h3>سلسلةُ الاعتماد</h3>

    @foreach ($chain as $link)
        @php([$t, $icon] = $tone[$link['state']] ?? ['wn', '❔'])
        <div class="row" style="border-top:1px solid var(--line);padding:10px 0;gap:10px;flex-wrap:wrap;align-items:flex-start">
            <span class="bdg {{ $t }}">{{ $icon }}
                {{ $link['state'] === 'ok' ? 'سليم' : ($link['state'] === 'broken' ? 'مقطوع' : 'غيرُ معلوم') }}</span>
            <div style="flex:1;min-width:240px">
                <b>{{ $link['label'] }}</b>
                <div class="sub mut">{!! e($link['why']) !!}</div>
                @if ($link['fix'])
                    <div class="sub"><b>الإصلاح:</b> {{ $link['fix'] }}</div>
                @endif
            </div>
            @if ($link['halts'])
                <span class="bdg wn">تقف القراءةُ هنا</span>
            @endif
            @if ($link['route'] && $link['state'] !== 'ok')
                <a class="btn sm" href="{{ route($link['route']) }}">افتح</a>
            @endif
        </div>
    @endforeach
</div>

{{-- ═══ آخرُ الفحوص — بعد المُطهِّرِ عند مصدرِها ═══ --}}
<div class="card">
    <h3>آخرُ الفحوص <span class="mut">({{ count($probes) }})</span></h3>
    <div class="sub mut">
        <b>كلُّ رسالةِ خطأٍ هنا مرّت بمُطهِّرِ الأسرارِ عند كتابتِها</b> — ولا
        تُطمَس في العرضِ مرّةً ثانية.
    </div>

    @forelse ($probes as $p)
        <div class="row" style="border-top:1px solid var(--line);padding:8px 0;gap:8px;flex-wrap:wrap;align-items:flex-start">
            <span class="bdg {{ $p['up'] === true ? 'ok' : ($p['up'] === false ? '' : 'wn') }}">
                {{ $p['up'] === true ? 'ناجح' : ($p['up'] === false ? 'فاشل' : 'لم يُجرَّب') }}
            </span>
            <span class="mut" style="width:70px">{{ $p['scope'] }}</span>
            <b style="flex:1;min-width:180px">{{ $p['name'] }}</b>
            <span class="mut mono ltr">{{ $p['at'] ?? '—' }}</span>
            @if ($p['ms'] !== null)<span class="mut mono ltr">{{ $p['ms'] }}ms</span>@endif
            @if ($p['error'])
                <div style="width:100%" class="sub mut mono ltr">{{ $p['error'] }}</div>
            @endif
        </div>
    @empty
        <div class="empty">
            <b>لا فحصَ نُفِّذ بعد.</b>
            <div class="sub">افحص البوّابةَ من الإعدادات، ثمّ افحص اعتمادَ مزوّدٍ ونموذجاً.</div>
        </div>
    @endforelse
</div>

@endsection
