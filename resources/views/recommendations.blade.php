@extends('layouts.app')
@section('title', 'مركز التوصيات')
@section('content')
@php $c = $r['counts']; @endphp
<div class="hero">
    <div>
        <h2>💡 مركز التوصيات</h2>
        <div class="sub">
            ماذا يستحق تدخّلك الآن؟ توصيات مجموعة من كل محرّكات النظام — التكلفة والقدرات
            وصحة المشاريع والجودة والتحصيل والانتهاءات. <b>كلها من بياناتك المسجَّلة، وكل توصية بسببها بالأرقام.</b>
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('recs', ['fresh' => 1]) }}">↻ تحديث</a>
</div>

@include('partials.lens', ['lensModules' => ['services', 'fin']])

<div class="cards">
    <div class="stat"><span class="ico">🔴</span><b class="{{ $c['حرج'] ? 'txt-bad' : '' }}">{{ $c['حرج'] }}</b><span>حرجة</span></div>
    <div class="stat"><span class="ico">🟠</span><b>{{ $c['مهم'] }}</b><span>مهمة</span></div>
    <div class="stat"><span class="ico">🔵</span><b>{{ $c['اطّلاع'] }}</b><span>للاطّلاع</span></div>
</div>

@php $tone = ['حرج' => 'bad', 'مهم' => 'wn', 'اطّلاع' => '']; @endphp
<div class="cards" style="grid-template-columns:1fr">
@forelse ($r['items'] as $it)
    <div class="card" style="border-inline-start:3px solid {{ $it['sev'] === 'حرج' ? 'var(--bad)' : ($it['sev'] === 'مهم' ? 'var(--wn)' : 'var(--brd)') }}">
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
            <span style="font-size:18px" aria-hidden="true">{{ $it['ico'] }}</span>
            <div style="min-width:0;flex:1">
                <b>{{ $it['title'] }}</b>
                <span class="bdg {{ $tone[$it['sev']] }}">{{ $it['sev'] }}</span>
                <div class="sub" style="margin-top:3px">{{ $it['why'] }}</div>
            </div>
            <a class="btn ghost sm" href="{{ $it['url'] }}">{{ $it['action'] }} ←</a>
        </div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">✅</span>
        لا توصيات الآن — لا خدمات تحت الماء ولا فريق فوق طاقته ولا مشاريع متعثرة ولا مستحقات متأخرة.
        <div class="sub" style="margin-top:6px">أو أن بياناتك غير مكتملة بعد — كلما سجّلت أكثر، دقّت التوصيات.</div>
    </div></div>
@endforelse
</div>

<div class="card" style="margin-top:12px">
    <h3>من أين تأتي هذه التوصيات؟</h3>
    <div class="sub" style="line-height:2">
        <b>الخدمات الخاسرة</b> من تحليل التكلفة · <b>الفريق فوق طاقته</b> من لوحة القدرات ·
        <b>المشاريع المتعثرة</b> من درجة الصحة (دون ٥٥) · <b>التطبيقات</b> من الأخطاء الحرجة ومعدل التراجع ·
        <b>المستحقات</b> من الفواتير المتأخرة غير المسدَّدة · <b>الانتهاءات</b> خلال ٧ أيام.<br>
        ⚠️ التوصية غياباً ليست شهادة سلامة — قد يعني أن مصدرها لم يُسجَّل بعد. كلما اكتملت بياناتك، اكتملت الصورة.
    </div>
</div>
@endsection
