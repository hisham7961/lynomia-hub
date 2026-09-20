@extends('layouts.app')
@section('title', 'نماذج الذكاء الاصطناعي')
@section('content')

{{-- ═══ النماذجُ عبر المزوّدين (المرحلة ٢ · W8 · §١١) ═══
     شاشةُ المزوّدِ الواحدِ تخدم الدورة؛ وهذه تخدم السؤالَ العرضيّ: «أيُّ
     نموذجٍ عندي يقرأ صورةً وبأيِّ كلفة؟» — ولا يُجاب بفتحِ خمسِ شاشات. --}}

@php
    $badge = function ($fact) {
        $v = $fact['v'] ?? null;
        if ($v === true)  return ['ok', 'مدعومة'];
        if ($v === false) return ['', 'غيرُ مدعومة'];
        return ['wn', 'غيرُ معروفة'];
    };
@endphp

<div class="hero">
    <div>
        <h2>🧠 النماذج</h2>
        <div class="sub">
            كلُّ ما اسْتُورد من كلِّ مزوّد. <b>و«غيرُ معروفة» ليست «غيرُ مدعومة»</b> —
            والفرقُ هو ما يمنع إغلاقَ بابٍ مفتوح.
        </div>
    </div>
</div>

@include('ai._sections')

{{-- ═══ الترشيح — قائمةٌ بيضاءُ لا مُدخلٌ حرّ ═══ --}}
<div class="card">
    <form method="GET" action="{{ route('ai.models.all') }}" class="row" style="gap:8px;flex-wrap:wrap">
        <select name="provider" style="min-width:180px">
            <option value="">كلُّ المزوّدين</option>
            @foreach ($providers as $p)
                <option value="{{ $p->id }}" @selected($provider === (string) $p->id)>{{ $p->label }}</option>
            @endforeach
        </select>
        <select name="cap" style="min-width:180px">
            <option value="">أيُّ قدرة</option>
            @foreach ($capabilities as $c)
                <option value="{{ $c }}" @selected($cap === $c)>{{ $c }}</option>
            @endforeach
        </select>
        <select name="state" style="min-width:160px">
            <option value="">أيُّ حالة</option>
            <option value="enabled"  @selected($state === 'enabled')>مُفعَّل</option>
            <option value="disabled" @selected($state === 'disabled')>مُعطَّل</option>
            <option value="gone"     @selected($state === 'gone')>أُزيل من المنبع</option>
        </select>
        <button class="btn sm">🔎 رشِّح</button>
        @if ($cap || $state || $provider)
            <a class="btn sm" href="{{ route('ai.models.all') }}">مسحُ الترشيح</a>
        @endif
    </form>
    @if ($cap !== '')
        <div class="sub mut">
            الترشيحُ بالقدرةِ يُظهر <b>المُثبَتَ وحدَه</b>: نموذجٌ قدرتُه «غيرُ معروفة»
            لا يُعَدّ داعماً لها — <b>والمجهولُ لا يمرّ عند التنفيذ</b>.
        </div>
    @endif
</div>

<div class="card">
    <h3>النماذج <span class="mut">({{ $models->count() }})</span></h3>

    @forelse ($models as $m)
        <div class="row" style="border-top:1px solid var(--line);padding:10px 0;gap:10px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:1;min-width:240px">
                <b class="mono ltr">{{ $m->litellm_model_name }}</b>
                <span class="mut">{{ $m->provider?->label ?? '—' }}</span>
                <div class="sub">
                    <span class="bdg {{ $m->enabled ? 'ok' : '' }}">{{ $m->enabled ? 'مُفعَّل' : 'مُعطَّل' }}</span>
                    @if (mb_strtoupper((string) $m->health) === 'UNAVAILABLE')
                        <span class="bdg wn">أُزيل من المنبع</span>
                    @endif
                    @php($ctx = ((array) $m->limits)['context_window'] ?? null)
                    @if (is_array($ctx) && ($ctx['v'] ?? null) !== null)
                        <span class="mut mono ltr">نافذة {{ number_format((int) $ctx['v']) }}</span>
                    @else
                        <span class="mut">نافذةٌ غيرُ معروفة</span>
                    @endif
                    @php($in = ((array) $m->pricing)['input_per_1k'] ?? null)
                    @if (is_array($in) && is_numeric($in['v'] ?? null))
                        <span class="mut mono ltr" title="تقديريّ">{{ rtrim(rtrim(number_format((float) $in['v'], 6), '0'), '.') }} USD/1k <b>تقديريّ</b></span>
                    @else
                        <span class="mut">سعرٌ غيرُ معروف</span>
                    @endif
                </div>
                <div class="sub mut">
                    @foreach ((array) $m->capabilities as $k => $fact)
                        @continue (! is_array($fact) || ($fact['v'] ?? null) !== true)
                        <span class="bdg ok">{{ $k }}</span>
                    @endforeach
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                @if ($m->provider)
                    <a class="btn sm" href="{{ route('ai.models.index', $m->provider) }}">
                        {{ $manage ? '⚙️ تهيئة' : '👁️ تفصيل' }}
                    </a>
                @endif
            </div>
        </div>
    @empty
        <div class="empty">
            <b>{{ ($cap || $state || $provider) ? 'لا نموذجَ يطابق الترشيح.' : 'لا نموذجَ مُسجَّلٌ بعد.' }}</b>
            <div class="sub">
                @if ($cap || $state || $provider)
                    وسّع الترشيحَ — أو أثبِت القدرةَ بفاحصِ المستوى E إن كانت «غيرَ معروفة».
                @else
                    ابدأ من مزوّدٍ واكتشف نماذجَه، ثمّ اخترها وفعّلها.
                @endif
            </div>
            <div style="margin-top:8px"><a class="btn sm" href="{{ route('ai.providers.index') }}">🔌 إلى المزوّدين</a></div>
        </div>
    @endforelse
</div>

@endsection
