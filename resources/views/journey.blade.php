@extends('layouts.app')
@section('title', 'رحلة العميل — ' . $client->name)
@section('content')
<div class="toolbar">
    <a class="btn ghost sm" href="{{ route('m.show', ['clients', $client->id]) }}">→ ملف العميل</a>
</div>
<div class="hero">
    <div>
        <h2>🧭 رحلة {{ $client->name }} {{ $client->vip ? '👑' : '' }}</h2>
        <div class="sub">
            @if ($client->vip)<span class="bdg wn">عميل VIP</span>@endif
            @if ($client->stage)<span class="bdg">{{ $client->stage }}</span>@endif
            @if ($client->value)<span class="bdg">فرصة {{ number_format((float) $client->value) }}</span>@endif
            @if ($client->prob !== null)<span class="bdg">إغلاق {{ (int) $client->prob }}٪</span>@endif
        </div>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🧾</span><b>{{ collect($events)->where('label', 'عرض سعر')->count() }}</b><span>عروض سعر</span></div>
    <div class="stat"><span class="ico">📜</span><b>{{ collect($events)->where('label', 'عقد')->count() }}</b><span>عقود</span></div>
    <div class="stat"><span class="ico">💵</span><b>{{ number_format($fin['total'], 1) }}</b><span>إجمالي فواتيره</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($fin['paid'], 1) }}</b><span>حصّلناه منه</span></div>
    <div class="stat"><span class="ico">⏳</span><b class="{{ $fin['total'] - $fin['paid'] > 0 ? 'txt-bad' : '' }}">{{ number_format($fin['total'] - $fin['paid'], 1) }}</b><span>غير محصّل</span></div>
</div>

<div class="card">
    @forelse ($events as $e)
        <div style="display:flex;gap:12px;padding:9px 0;border-inline-start:2px solid var(--brd);margin-inline-start:8px;padding-inline-start:16px;position:relative">
            <span style="position:absolute;inset-inline-start:-11px;top:12px;background:var(--cd);line-height:1" aria-hidden="true">{{ $e['ico'] }}</span>
            <div style="min-width:0;flex:1">
                <span class="sub">{{ $e['label'] }}</span>
                @if ($e['status'])<span class="bdg {{ hub_tone($e['status']) }}">{{ $e['status'] }}</span>@endif
                <div>@if ($e['url'])<a href="{{ $e['url'] }}"><b>{{ $e['title'] }}</b></a>@else<b>{{ $e['title'] }}</b>@endif</div>
            </div>
            <span class="mono sub" style="white-space:nowrap">{{ substr($e['at'], 0, 10) }}</span>
        </div>
    @empty
        <div class="empty"><span class="big">🧭</span>لا أحداث بعد — العروض والعقود والاجتماعات والفواتير ستُبنى هنا تلقائياً</div>
    @endforelse
</div>

<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        <b>من أين تُبنى الرحلة؟</b> العروض والعقود والاجتماعات والقرارات المرتبطة بالعميل مرجعياً، وتعليقات فريقك على ملفه.<br>
        💵 <b>المستندات المالية:</b>
        {{ $fin['linked'] }} مرتبط بالعميل مرجعياً
        @if ($fin['byName'])
            و<b>{{ $fin['byName'] }} مطابَق باسم الطرف نصياً</b> — الربط النصي يكسره تغيير الاسم
            ويخلط عميلين متشابهي الاسم، فاختر العميل في حقل «العميل (مرتبط)» على تلك المستندات.
        @else
            — كلها مرتبطة مرجعياً.
        @endif
    </div>
</div>
@endsection
