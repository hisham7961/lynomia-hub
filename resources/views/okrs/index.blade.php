@extends('layouts.app')
@section('title', 'الأهداف والنتائج')
@section('content')
@php
    $srcLabels = hub_kr_sources();
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>اللوحات</span><span aria-hidden="true">‹</span><b>الأهداف والنتائج</b></nav>
        <h2>🎯 الأهداف والنتائج (OKR)</h2>
        <div class="sub">
            النسبة <b>محسوبةٌ</b> من النتائج الرئيسية لا مكتوبة، ومقارَنةٌ بالإيقاع المتوقَّع من الفترة —
            «٣٠٪» وحدها لا تقول أمتقدّمون نحن أم متأخرون.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_can(auth()->user(), 'okrs', 'e'))
            <form method="POST" action="{{ route('okrs.refresh') }}" class="inline">
                @csrf<button class="btn ghost sm">🔄 حدّث القيم الآلية</button>
            </form>
        @endif
        <a class="btn ghost sm" href="{{ route('m.index', 'okrs') }}">جدول الأهداف ←</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'krs') }}">النتائج الرئيسية ←</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🎯</span><b>{{ $b['n'] }}</b><span>هدفاً · {{ $b['measured'] }} مقيساً</span></div>
    <div class="stat"><span class="ico">📊</span><b>{{ $b['avg'] !== null ? $b['avg'] . '٪' : '—' }}</b>
        <span>متوسط الإنجاز المحسوب</span></div>
    <div class="stat"><span class="ico">🐌</span><b class="{{ $b['behind'] ? 'txt-bad' : '' }}">{{ $b['behind'] }}</b>
        <span>متأخر عن إيقاعه</span></div>
    <div class="stat"><span class="ico">🚀</span><b>{{ $b['ahead'] }}</b><span>متقدّم على إيقاعه</span></div>
    @if ($b['unmeasured'])
        <div class="stat"><span class="ico">❔</span><b class="txt-bad">{{ $b['unmeasured'] }}</b>
            <span>بلا نتائج رئيسية — لا يُقاس</span></div>
    @endif
    <div class="stat"><span class="ico">🤖</span><b>{{ $b['auto'] }}</b><span>هدفاً يقرأ قيمه آلياً</span></div>
</div>

@forelse ($b['rows'] as $r)
    @php $o = $r['o']; $p = $r['p']; @endphp
    <div class="card" @if (($p['tone'] ?? '') === 'bad') style="border-inline-start:4px solid var(--bad,#c0392b)" @endif>
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
            <div style="min-width:0;flex:1">
                <h3 class="cardtitle" style="margin-bottom:4px">
                    <a href="{{ route('m.show', ['okrs', $o->id]) }}">{{ $o->title }}</a>
                </h3>
                <div class="sub">
                    {{ $o->level ?: '—' }}{{ $o->period ? ' · ' . $o->period : '' }}
                    @if ($o->due) · ينتهي {{ substr((string) $o->due, 0, 10) }}
                        @if (($p['daysLeft'] ?? null) !== null)
                            <span class="{{ $p['daysLeft'] < 0 ? 'txt-bad' : '' }}">({{ $p['daysLeft'] < 0 ? 'متأخر ' . abs($p['daysLeft']) . ' يوماً' : 'بقي ' . $p['daysLeft'] . ' يوماً' }})</span>
                        @endif
                    @endif
                </div>
            </div>
            <div style="text-align:center;min-width:110px">
                <div style="font-size:26px;font-weight:700" class="{{ ($p['tone'] ?? '') === 'bad' ? 'txt-bad' : '' }}">
                    {{ $p && $p['pct'] !== null ? $p['pct'] . '٪' : '—' }}</div>
                @if ($p && ($p['pace'] ?? ''))
                    <span class="bdg {{ $p['tone'] }}">{{ $p['pace'] }}</span>
                @endif
            </div>
        </div>

        @if (! $p)
            <div class="sub" style="margin-top:8px;color:var(--wn)">
                ⚠️ <b>بلا نتائج رئيسية</b> — الهدف بلا KR لا يُقاس، وأي نسبةٍ عليه ادّعاء.
                @if (hub_can(auth()->user(), 'krs', 'a'))
                    <a href="{{ route('m.create', 'krs') }}">أضف نتيجةً رئيسية</a> بقيمة بدايةٍ وهدف.
                @else
                    اطلب ممّن يملك إضافةَ النتائج الرئيسية أن يضيف واحدةً بقيمة بدايةٍ وهدف.
                @endif
            </div>
        @else
            {{-- شريطٌ مزدوج: المُنجز، وخطُّ المتوقَّع فوقه --}}
            <div style="position:relative;margin:10px 0 4px">
                <div class="pbar big"><span style="width:{{ max(0, min(100, $p['pct'] ?? 0)) }}%"></span></div>
                @if (($p['expected'] ?? null) !== null)
                    <div title="المتوقَّع في هذا الوقت من الفترة: {{ $p['expected'] }}٪"
                         style="position:absolute;top:-3px;bottom:-3px;inset-inline-start:{{ min(100, $p['expected']) }}%;
                                width:2px;background:var(--tx);opacity:.55"></div>
                @endif
            </div>
            <div class="sub" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <span>
                    @if (($p['expected'] ?? null) !== null)
                        المتوقَّع الآن <b>{{ $p['expected'] }}٪</b>
                        @if (($p['gap'] ?? null) !== null)
                            · الفجوة <b class="{{ $p['gap'] < 0 ? 'txt-bad' : '' }}">{{ $p['gap'] > 0 ? '+' : '' }}{{ $p['gap'] }}</b>
                        @endif
                    @else
                        لا فترة محدَّدة (البداية والاستحقاق) — فلا يُحسب الإيقاع
                    @endif
                </span>
                <span>{{ $p['measured'] }}/{{ $p['count'] }} نتيجة مقيسة{{ $p['autoCount'] ? ' · ' . $p['autoCount'] . ' آلية' : '' }}</span>
            </div>

            <div class="tblwrap" style="margin-top:10px"><table class="tbl">
                <thead><tr><th>النتيجة الرئيسية</th><th>من ← إلى</th><th>الآن</th><th>التقدّم</th><th>المصدر</th></tr></thead>
                <tbody>
                @foreach ($p['krs'] as $kr)
                    <tr>
                        <td><a href="{{ route('m.show', ['krs', $kr['id']]) }}">{{ $kr['title'] }}</a>
                            @if (($kr['weight'] ?? 1) != 1)<span class="bdg g">وزن {{ $num($kr['weight']) }}</span>@endif</td>
                        <td class="mono sub">{{ $num($kr['start']) }} ← {{ $num($kr['target']) }} {{ $kr['unit'] }}</td>
                        <td class="mono"><b>{{ $num($kr['current']) }}</b></td>
                        <td style="min-width:120px">
                            @if ($kr['pct'] === null)
                                <span class="sub">بلا هدفٍ أو قيمة</span>
                            @else
                                <div class="pbar sm"><span style="width:{{ $kr['pct'] }}%"></span></div>
                                <span class="sub">{{ $kr['pct'] }}٪</span>
                            @endif
                        </td>
                        <td>
                            @if ($kr['auto'])
                                <span class="bdg ok">🤖 {{ $srcLabels[$kr['source']] ?? $kr['source'] }}</span>
                                <div class="sub">{{ $kr['readAt'] ? 'قُرئت ' . \Illuminate\Support\Carbon::parse($kr['readAt'])->diffForHumans() : 'لم تُقرأ بعد' }}</div>
                            @else
                                <span class="bdg wn">✍️ يدوي</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">🎯</span>
        لا أهداف بعد —
        @if (hub_can(auth()->user(), 'okrs', 'a'))
            <a href="{{ route('m.create', 'okrs') }}">ابدأ بهدفٍ واحد</a> ثم أضف له نتائج رئيسية قابلة للقياس.
        @else
            اطلب ممّن يملك إضافةَ الأهداف أن يبدأ بهدفٍ واحد ثم نتائجَ رئيسية قابلة للقياس.
        @endif
    </div></div>
@endforelse

<div class="card">
    <h3 class="cardtitle">كيف تُحسب هذه الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>تقدّم النتيجة</b> = (الحالية − البداية) ÷ (الهدف − البداية)، ويقبل الاتجاه النازل (هدفٌ أقل من البداية).<br>
        <b>تقدّم الهدف</b> = متوسط نتائجه <b>موزوناً</b> بأوزانها — ويُكتب على الهدف تلقائياً فتبقى القوائم صادقة.<br>
        <b>المتوقَّع</b> = ما مضى من الفترة (البداية ← الاستحقاق). و<b>الإيقاع</b>: فجوةٌ فوق ‎+١٠ «متقدّم»،
        وفوق ‎−٥ «على المسار»، ودونها «متأخر» — الهامش لأن التقلّب اليومي ليس تعثّراً.<br>
        <b>المصدر الآلي</b> يغلب أي كتابةٍ يدوية على النتيجة — وإلا فما فائدة الأتمتة إن نقضتها آخر لمسةِ يد.
    </div>
</div>
@endsection
