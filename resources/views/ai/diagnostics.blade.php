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

{{-- ═══ التصالحُ مع البوّابة (W9 · §١٧) ═══
     قاعدتان مستقلّتان تفترقان، والاستعادةُ الجزئيّةُ أوضحُ أبوابِ الافتراق:
     نماذجُ في سلسلةِ توجيهٍ لا وجودَ لها عند البوّابة. --}}
<div class="card">
    <h3>التصالحُ مع البوّابة</h3>
    <div class="sub mut">
        نموذجٌ في Hub بلا نظيرٍ عند البوّابةِ يُوسَم <b>يتيماً</b> ويُستبعَد من
        التوجيهِ تلقائيّاً. <b>والتعافي آليٌّ كالوسم</b>: ما عاد يظهر يُرفَع عنه
        الوسمُ في التصالحِ التالي. <b>ولا يُستورَد نموذجٌ ولا يُحذَف صفّ.</b>
    </div>

    <div class="row" style="gap:6px;flex-wrap:wrap;margin-top:8px">
        <form method="POST" action="{{ route('ai.reconcile') }}">@csrf
            <button class="btn sm">🔍 معاينة <span class="mut">(لا تكتب)</span></button>
        </form>
        @if ($manage ?? true)
            <form method="POST" action="{{ route('ai.reconcile') }}">@csrf
                <input type="hidden" name="apply" value="1">
                <button class="btn sm">✍️ صالِح واكتب الوسم</button>
            </form>
        @endif
    </div>

    @if (! empty($recon))
        <div class="sub" style="margin-top:10px">
            <span class="bdg {{ $recon['applied'] ? 'ok' : 'wn' }}">
                {{ $recon['applied'] ? 'كُتب الوسم' : 'معاينةٌ — لم يُكتَب شيء' }}
            </span>
            <span class="mut mono ltr">{{ $recon['checked_at'] }}</span>
        </div>
        @if (! empty($recon['error']))
            <div class="sub"><b>{!! e($recon['error']) !!}</b></div>
        @endif

        <div class="cards">
            <div class="stat"><span class="ico bdg">🗂️</span>
                <b>{{ $recon['counts']['hub_models'] }} عندنا · {{ $recon['counts']['live_models'] }} تُعلنها البوّابة</b>
                <span>والمرجعُ ما تُعلنه هي لا ما نظنّه نحن.</span></div>
            <div class="stat"><span class="ico bdg {{ $recon['counts']['orphaned'] ? 'wn' : 'ok' }}">👻</span>
                <b>{{ $recon['counts']['orphaned'] }} يتيماً</b>
                <span>لا نظيرَ لها عند البوّابة — تُستبعَد من التوجيه.</span></div>
            <div class="stat"><span class="ico bdg ok">↩️</span>
                <b>{{ $recon['counts']['restored'] }} عاد</b>
                <span>ظهر ثانيةً فرُفع عنه الوسم.</span></div>
            <div class="stat"><span class="ico bdg {{ $recon['counts']['unregistered'] ? 'wn' : '' }}">➕</span>
                <b>{{ $recon['counts']['unregistered'] }} غيرُ مُسجَّلٍ عندنا</b>
                <span>باعتمادِنا عند البوّابةِ ولا صفَّ له — <b>يُبلَّغ ولا يُستورَد</b>.</span></div>
        </div>

        @foreach ([['orphaned', 'يتيمٌ'], ['restored', 'عاد'],
                   ['unregistered', 'غيرُ مُسجَّلٍ عندنا'], ['credential_gone', 'اعتمادٌ بلا أثرٍ في الخزنة']] as $pair)
            @continue (empty($recon[$pair[0]]))
            <div class="sub mut">
                <b>{{ $pair[1] }}:</b>
                @foreach ($recon[$pair[0]] as $n)<span class="mono ltr">{{ $n }}</span>@if(! $loop->last) · @endif @endforeach
            </div>
        @endforeach
    @endif
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
