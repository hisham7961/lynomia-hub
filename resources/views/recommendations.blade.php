@extends('layouts.app')
@section('title', 'مركز التوصيات')
@section('content')
@php
    $c = $ac['counts'];
    $tone = ['حرج' => 'bad', 'مهم' => 'wn', 'اطّلاع' => ''];
    $stateLabel = ['ack' => 'مُقَرّة', 'open' => '', 'snoozed' => 'مؤجّلة', 'dismissed' => 'مرفوضة'];
@endphp
<div class="hero">
    <div>
        <h2>💡 مركز التوصيات</h2>
        <div class="sub">
            ماذا يستحق تدخّلك الآن؟ إشاراتٌ مجموعةٌ من كل محرّكات النظام — التكلفة والقدرات
            وصحة المشاريع والجودة والتحصيل والانتهاءات والعروض التي لم تُحوَّل والعُهد المتأخرة.
            <b>كلها من بياناتك المسجَّلة، وكل إشارةٍ بسببها بالأرقام — تُقِرّها أو تؤجّلها أو ترفضها.</b>
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('recs', ['fresh' => 1]) }}">↻ تحديث</a>
</div>

@include('partials.lens', ['lensModules' => ['services', 'fin']])

<div class="cards">
    <div class="stat"><span class="ico">🔴</span><b class="{{ $c['حرج'] ? 'txt-bad' : '' }}">{{ $c['حرج'] }}</b><span>حرجة</span></div>
    <div class="stat"><span class="ico">🟠</span><b>{{ $c['مهم'] }}</b><span>مهمة</span></div>
    <div class="stat"><span class="ico">🔵</span><b>{{ $c['اطّلاع'] }}</b><span>للاطّلاع</span></div>
    <div class="stat"><span class="ico">💤</span><b>{{ $ac['snoozed'] }}</b><span>مؤجّلة</span></div>
    <div class="stat"><span class="ico">📥</span><b>{{ $ac['awaiting']['count'] }}</b><span>تنتظرني</span></div>
</div>

<div class="cards" style="grid-template-columns:1fr">
@forelse ($ac['signals'] as $it)
    <div class="card" style="border-inline-start:3px solid {{ $it['sev'] === 'حرج' ? 'var(--bad)' : ($it['sev'] === 'مهم' ? 'var(--wn)' : 'var(--brd)') }}">
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
            <span style="font-size:18px" aria-hidden="true">{{ $it['ico'] }}</span>
            <div style="min-width:0;flex:1">
                <b>{{ $it['title'] }}</b>
                <span class="bdg {{ $tone[$it['sev']] }}">{{ $it['sev'] }}</span>
                @if (($it['state'] ?? 'open') === 'ack')<span class="bdg">✔️ مُقَرّة</span>@endif
                <div class="sub" style="margin-top:3px">{{ $it['why'] }}</div>
            </div>
            <a class="btn ghost sm" href="{{ $it['url'] }}">{{ $it['action'] }} ←</a>
        </div>
        @if (! empty($it['can_act']))
            <div class="crow" style="margin-top:8px;gap:6px;flex-wrap:wrap">
                @if (($it['state'] ?? 'open') !== 'ack')
                    <form method="POST" action="{{ route('recs.act') }}" class="inline">@csrf
                        <input type="hidden" name="skey" value="{{ $it['key'] }}"><input type="hidden" name="do" value="ack">
                        <button class="btn ghost sm">✔️ أقرّ</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('recs.act') }}" class="inline">@csrf
                    <input type="hidden" name="skey" value="{{ $it['key'] }}"><input type="hidden" name="do" value="snooze">
                    <input type="hidden" name="until" value="{{ now()->addDay()->toDateString() }}">
                    <button class="btn ghost sm">💤 أجّل يوماً</button>
                </form>
                <form method="POST" action="{{ route('recs.act') }}" class="inline">@csrf
                    <input type="hidden" name="skey" value="{{ $it['key'] }}"><input type="hidden" name="do" value="snooze">
                    <input type="hidden" name="until" value="{{ now()->addDays(7)->toDateString() }}">
                    <button class="btn ghost sm">💤 أسبوعاً</button>
                </form>
                <form method="POST" action="{{ route('recs.act') }}" class="inline">@csrf
                    <input type="hidden" name="skey" value="{{ $it['key'] }}"><input type="hidden" name="do" value="dismiss">
                    <button class="btn ghost sm" data-confirm="إخفاءُ هذه الإشارة؟ تعود إن تكرّر شرطُها.">🚫 أخفِ</button>
                </form>
            </div>
        @endif
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">✅</span>
        لا توصيات الآن — لا خدمات تحت الماء ولا فريق فوق طاقته ولا مشاريع متعثرة ولا مستحقات متأخرة
        ولا عروضٌ مقبولةٌ معلّقة ولا عُهدٌ متأخرة.
        <div class="sub" style="margin-top:6px">أو أن بياناتك غير مكتملة بعد — كلما سجّلت أكثر، دقّت الإشارات.</div>
    </div></div>
@endforelse
</div>

@if (! empty($ac['awaiting']['items']))
    <div class="card" style="margin-top:12px">
        <h3 class="cardtitle">📥 ما ينتظر تصرّفي ({{ $ac['awaiting']['count'] }})</h3>
        <div class="sub" style="margin-bottom:6px">من الصندوق الموحّد — موافقاتٌ وإقراراتٌ ومهامٌ والتزاماتٌ عليك، مرتَّبةً بالإلحاح.</div>
        <div class="cards" style="grid-template-columns:1fr">
            @foreach ($ac['awaiting']['items'] as $w)
                <a class="chip" href="{{ $w['url'] ?? '#' }}">{{ $w['icon'] ?? '•' }} {{ $w['title'] ?? '—' }}@if (! empty($w['due'])) · {{ substr((string) $w['due'], 0, 10) }}@endif</a>
            @endforeach
        </div>
    </div>
@endif

<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">من أين تأتي هذه الإشارات؟</h3>
    <div class="sub" style="line-height:2">
        <b>الخدمات الخاسرة</b> من تحليل التكلفة · <b>الفريق فوق طاقته</b> من لوحة القدرات ·
        <b>المشاريع المتعثرة</b> من درجة الصحة (دون ٥٥) · <b>التطبيقات</b> من الأخطاء الحرجة ومعدل التراجع ·
        <b>المستحقات</b> من الفواتير المتأخرة · <b>الانتهاءات</b> خلال ٧ أيام ·
        <b>العروض غير المحوَّلة</b> من CPQ · <b>العُهد المتأخرة</b> من سجل التصاريح.<br>
        التصرّفُ لا يُخفي الحقيقة: <b>الإقرار</b> يعني «رأيتُها» لا «حُلَّت»، و<b>التأجيل</b> يُعيدها في موعدها،
        و<b>الإخفاء</b> يُسكتها حتى يتكرّر شرطُها. وإذا زال السببُ اختفت الإشارةُ وحدها.<br>
        ⚠️ غيابُ الإشارة ليس شهادةَ سلامة — قد يعني أن مصدرها لم يُسجَّل بعد.
    </div>
</div>
@endsection
