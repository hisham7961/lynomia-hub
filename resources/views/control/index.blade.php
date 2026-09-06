@extends('layouts.app')
@section('title', 'نظرة التحكّم')
@section('content')
{{-- نظرةُ التحكّم (WP-10.1/10.2 · spec §32 · §33 · §12).
     **مفترقُ طرقٍ لا لوحةٌ عملاقة**: ستُّ بطاقاتٍ قارئةٍ فقط تحيل إلى مراكزها،
     وتحتها صفُّ «يستدعي تدخّلك». لا تفصيلَ يُكرَّر هنا، ولا رقمَ يُحسب هنا —
     وكلُّ ما لا يقيسه محرّكُه يُكتب «—» لا صفراً. --}}
@php
    use App\Support\AttentionQueue;
    $ccOrder = ['security', 'operations', 'errors', 'audit', 'quality', 'execution'];
    $ccShown = collect($ccOrder)->map(fn ($k) => $cards[$k] ?? null)
        ->filter(fn ($c) => $c && ! empty($c['visible']))->values();
    $ccSevTone = ['حرج' => 'bad', 'مهم' => 'wn', 'اطّلاع' => 'i'];
    // العدّادُ **من الصفّ المعروض** لا من الصفّ الكامل: شارةٌ تقول «٧ مهمّة» فوق
    // قائمةٍ فيها اثنتان تُفقد الرقمَ معناه — والصفُّ هنا مُصفّىً بحكم التصميم.
    $ccCount = fn (string $sev) => count(array_filter($queue, fn ($s) => ($s['sev'] ?? '') === $sev));
@endphp

@component('partials.pagehead', ['icon' => '🎛️', 'title' => 'نظرة التحكّم', 'crumb' => 'النظام',
    'sub' => 'ستُّ بطاقاتٍ تقول أين تقف، وصفٌّ واحدٌ يقول بمَ تبدأ — والتفصيلُ في مركزه لا هنا.'])
    <a class="btn ghost sm" href="{{ route('recs') }}">💡 مركز التوصيات ←</a>
    <a class="btn ghost sm" href="{{ request()->url() . '?' . http_build_query(collect(request()->query())->filter(fn ($v) => ! is_array($v))->all() + ['fresh' => 1]) }}">🔄 أعد الحساب</a>
@endcomponent

{{-- ═══ البطاقاتُ الستّ ═══ كلُّ بطاقةٍ رابطٌ إلى مركزها، وقيمتُها من قارئه --}}
@include('partials.cc.kpis', ['items' => $ccShown->map(fn ($c) => [
    'label' => $c['icon'] . ' ' . $c['label'],
    'value' => $c['value'],
    'tone'  => $c['tone'] ?? '',
    'sub'   => $c['sub'] ?? '',
    'url'   => $c['url'] ?? null,
    'hint'  => $c['hint'] ?? null,
])->all()])

<div style="margin:8px 0">@include('partials.cc.freshness', ['at' => $at, 'ttl' => $ttl])</div>

@if ($ccShown->count() < 6)
    <div class="sub" style="margin-bottom:10px">
        بطاقاتٌ محجوبةٌ عنك: مراكزُ التشغيل والأخطاء للمالك، والتدقيقُ لحاملِ رايته — ولا تُحسَب أصلاً لمن لا يراها.
    </div>
@endif

{{-- ═══ صفُّ «يستدعي تدخّلك» (WP-10.2 · §33) ═══
     إشاراتُ النظام كلُّها والحرجُ وحدَه من التجاريّ. **قراءةٌ هنا وتصرّفٌ هناك**:
     الإقرارُ والتأجيل سكّةٌ واحدةٌ في مركز التوصيات (recs.act) — فلا سطحَ فعلٍ ثانٍ. --}}
<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">
        🚨 يستدعي تدخّلك
        @if ($ccCount('حرج') > 0)<span class="bdg bad">{{ $ccCount('حرج') }} حرج</span>@endif
        @if ($ccCount('مهم') > 0)<span class="bdg wn">{{ $ccCount('مهم') }} مهم</span>@endif
        @if ($ccCount('اطّلاع') > 0)<span class="bdg i">{{ $ccCount('اطّلاع') }} للاطّلاع</span>@endif
    </h3>
    <div class="sub" style="margin-bottom:8px">
        حالةُ النظام كلُّها (أمنٌ وتشغيلٌ وأخطاءٌ وتدقيقٌ وجودةٌ وتنفيذٌ وتنبيهات)
        <b>والحرجُ وحدَه</b> من إشارات العمل — وما دون ذلك مكانُه
        <a href="{{ route('recs') }}">مركز التوصيات</a>، وهناك يقع الإقرارُ والتأجيل.
    </div>

    {{-- (§29) قائمةٌ مُعلَنةٌ للتقنيات المساعدة: البنودُ divs بحكم التنسيق،
         فتُعلَن قائمةً بـrole بدل أن تُقرأ نصّاً متّصلاً بلا حدود. --}}
    <div class="cards" style="grid-template-columns:1fr" role="list" aria-label="بنودُ التدخّل">
    @forelse ($queue as $it)
        <div class="crow" role="listitem" style="gap:10px;flex-wrap:wrap;align-items:flex-start;padding:8px 0;border-block-start:1px solid var(--brd)">
            <span style="font-size:18px" aria-hidden="true">{{ $it['ico'] ?? '•' }}</span>
            <div style="min-width:0;flex:1">
                <b>{{ $it['title'] }}</b>
                <span class="bdg {{ $ccSevTone[$it['sev']] ?? '' }}">{{ $it['sev'] }}</span>
                @if (! empty($it['type']))
                    <span class="bdg g">{{ AttentionQueue::TYPE_LABELS[$it['type']] ?? $it['type'] }}</span>
                @endif
                @if (($it['state'] ?? 'open') === 'ack')<span class="bdg">✔️ مُقَرّة</span>@endif
                <div class="sub" style="margin-top:3px">{{ $it['why'] }}</div>
                @if (! empty($it['fix']))
                    <div class="sub" style="margin-top:3px"><b>التوصية:</b> {{ $it['fix'] }}</div>
                @endif
                {{-- «رُصد» و«المسؤول» يُكتبان إن وُجدا فقط: نموذجُ الصحّة لقطةٌ بلا
                     ذاكرةِ «منذ متى»، والتنبيهُ لا يُسنَد إلى شخص — و«الآن» أو
                     مسؤولٌ مفترَضٌ في مكان الغياب كذبٌ صغير يُبنى عليه قرار. --}}
                <div class="sub" style="margin-top:3px">
                    @if (! empty($it['detected']))
                        رُصد <span title="{{ $it['detected'] }}">{{ \Illuminate\Support\Carbon::parse($it['detected'])->diffForHumans() }}</span> ·
                    @else
                        لا تاريخَ رصدٍ محفوظ ·
                    @endif
                    @if (! empty($it['owner']))المسؤول: {{ $it['owner'] }} · @endif
                    <bdi class="mono ltr">{{ $it['key'] }}</bdi>
                </div>
            </div>
            <a class="btn ghost sm" href="{{ $it['url'] }}">{{ $it['action'] ?? 'افتح' }} ←</a>
        </div>
    @empty
        @include('partials.empty', ['icon' => '✅', 'text' =>
            'لا شيءَ يستدعي تدخّلك الآن — لا نتيجةَ أمنٍ حرجة، ولا مكوّنَ نظامٍ ساقطاً، ولا عطلاً حرجاً مفتوحاً، ولا سلسلةَ تدقيقٍ مكسورة، ولا نقصَ جودةٍ حرجاً، ولا هدفاً حرجاً متأخّراً.'])
        <div class="sub">⚠️ وغيابُ الإشارة ليس شهادةَ سلامة: قد يكون مصدرُها لم يُسجَّل بعد — بطاقةٌ تقول «—» أعلاه تعني قياساً لم يبدأ.</div>
    @endforelse
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">من أين يأتي كلُّ رقمٍ هنا؟</h3>
    <div class="sub" style="line-height:2">
        <b>الأمن</b> من <bdi class="mono ltr">SecurityPosture::summary</bdi> و<bdi class="mono ltr">SecurityFindings::openCounts</bdi> ·
        <b>التشغيل</b> من <bdi class="mono ltr">Health::check</bdi> (الحالةُ نفسُها التي يقرؤها <bdi class="mono ltr">/healthz</bdi>) والحوادثُ المفتوحة ·
        <b>الأخطاء</b> من <bdi class="mono ltr">ErrorStats::cards</bdi> ·
        <b>التدقيق</b> من آخر صفٍّ في تاريخ التحقّق، وعند غيابه من ذيل السلسلة — <b>ولا فحصَ سلسلةٍ كاملاً في طلب</b> ·
        <b>الجودة</b> من <bdi class="mono ltr">DataQuality::scan</bdi> ·
        <b>التنفيذ</b> من <bdi class="mono ltr">ExecutionStats::executionSummary</bdi>.<br>
        لا رقمَ يُحسب في هذه الصفحة، ولا رقمَ مركّبٌ يجمعها: من يقرأ درجةً مركّبةً لا يعرف ما الذي يُصلحه ليرفعها.
        والصفحةُ مخبّأةٌ {{ $ttl }} ثانية لأن فحصَ الصحّة وحدَه عشراتُ الاستعلامات — و«آخرُ حساب» مكتوبٌ أعلاه لا مُخفى.
    </div>
</div>
@endsection
