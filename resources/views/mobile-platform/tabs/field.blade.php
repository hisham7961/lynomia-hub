{{-- الملفات والماسح والتتبّع (§40–42): حالةٌ وتجميعٌ فقط — **لا مراقبة**. لا أسماءَ ملفاتٍ،
     ولا صورَ مسحٍ، و**لا نقاطَ موقعٍ ولا مساراتٍ قطّ** (تجميعٌ وموافقةٌ لا إحداثيّات). --}}
@php
    $ok = fn ($b) => $b ? '<span class="bdg ok">مُنفَّذ</span>' : '<span class="bdg bad">غائب</span>';
@endphp

@include('partials.cc.kpis', ['items' => [
    ['label' => 'الملفات', 'value' => $files['implemented'] ? 'مُنفَّذة' : 'غائبة', 'tone' => $files['implemented'] ? 'ok' : 'bad',
        'sub' => number_format($files['av']['total']) . ' مرفقاً (كلُّ الوحدات)'],
    ['label' => 'مصابٌ محجوب', 'value' => number_format($files['av']['infected']),
        'tone' => $files['av']['infected'] > 0 ? 'bad' : 'ok', 'sub' => 'يُحجب ٤٢٣ تلقائيّاً'],
    ['label' => 'الماسح', 'value' => $scanner['implemented'] ? 'مُنفَّذ' : 'غائب', 'tone' => $scanner['implemented'] ? 'ok' : 'bad',
        'sub' => 'حلُّ الهويّة'],
    ['label' => 'جلساتُ تتبّعٍ نشطة', 'value' => number_format($tracking['sessions']['active']),
        'sub' => 'بموافقةٍ حصراً'],
]])

{{-- الملفات (§40) — قدرةٌ + فحصُ فيروساتٍ تجميعاً (لا أسماء) --}}
<div class="card kid wide">
    <h3>📎 الملفات</h3>
    <table class="mini">
        <tr><td>القدرة (رفعٌ مُقطَّع + تنزيلٌ مُصرَّح)</td><td>{!! $ok($files['implemented']) !!}</td></tr>
        <tr><td>قرصٌ خاصٌّ فقط</td><td>@if ($files['private_only'])<span class="bdg ok">نعم</span>@else<span class="bdg wn">لا</span>@endif <span class="sub">لا رابطٌ عامٌّ — توقيعٌ مؤقّت</span></td></tr>
        <tr><td>حجبُ المصاب</td><td>@if ($files['infected_blocked'])<span class="bdg ok">نعم (٤٢٣)</span>@endif <span class="sub">عبر AttachmentService القائم</span></td></tr>
    </table>
    <p class="sub" style="margin-top:8px">موقفُ فحصِ الفيروسات (تجميعٌ لكلِّ الوحدات — لا أسماءَ ملفاتٍ ولا محتوى):</p>
    <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr))">
        <div class="stat"><span>سليم</span><b>{{ number_format($files['av']['clean']) }}</b></div>
        <div class="stat"><span>معلّق</span><b>{{ number_format($files['av']['pending']) }}</b></div>
        <div class="stat"><span>مصاب</span><b class="{{ $files['av']['infected'] > 0 ? 'txt-bad' : '' }}">{{ number_format($files['av']['infected']) }}</b></div>
        <div class="stat"><span>خطأُ فحص</span><b>{{ number_format($files['av']['error']) }}</b></div>
    </div>
</div>

{{-- الماسح (§41) — قدرةٌ فقط، لا صورَ ولا مراقبة --}}
<div class="card kid wide">
    <h3>🔍 الماسح (حلُّ الهويّة)</h3>
    <table class="mini">
        <tr><td>القدرة (identity/resolve)</td><td>{!! $ok($scanner['implemented']) !!}</td></tr>
        <tr><td>يُعيد استعمال</td><td class="sub">{{ $scanner['reuses'] }}</td></tr>
    </table>
    <p class="sub" style="margin-top:8px">يحلّ الهويّةَ من مسحٍ (باركود/رمز) — <b>لا يخزّن صوراً ولا يراقب</b>.</p>
</div>

{{-- التتبّع (§42) — تجميعٌ وموافقةٌ فقط، لا نقاطَ ولا مساراتٍ قطّ --}}
<div class="card kid wide">
    <h3>📍 تتبّعُ الموقع الميدانيّ</h3>
    <table class="mini">
        <tr><td>القدرة (بدء/نقاط/إنهاء)</td><td>{!! $ok($tracking['implemented']) !!}</td></tr>
        <tr><td>الموافقةُ شرطٌ</td><td>@if ($tracking['consent_required'])<span class="bdg ok">نعم — لا تتبّعَ بلا إقرار</span>@endif</td></tr>
    </table>
    <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr))">
        <div class="stat"><span>جلساتٌ نشطة</span><b>{{ number_format($tracking['sessions']['active']) }}</b></div>
        <div class="stat"><span>جلساتٌ منتهية</span><b>{{ number_format($tracking['sessions']['ended']) }}</b></div>
        <div class="stat"><span>بموافقة</span><b>{{ number_format($tracking['consent']['with']) }}</b></div>
        <div class="stat"><span>بلا موافقة</span><b class="{{ $tracking['consent']['without'] > 0 ? 'txt-bad' : '' }}">{{ number_format($tracking['consent']['without']) }}</b></div>
    </div>
    @if ($tracking['consent']['without'] > 0)
        <p class="sub" style="margin-top:8px"><span class="bdg bad">شذوذ</span> جلساتٌ بلا ختمِ موافقة — يجب أن تكون صفراً (لا تتبّعَ بلا إقرار).</p>
    @endif
    <p class="sub" style="margin-top:8px">🔒 <b>لا مراقبة:</b> تُعرَض الأعدادُ والموافقةُ فقط — <b>لا إحداثيّاتٍ ولا مساراتٍ ولا نقاطَ موقعٍ قطّ</b>. تفاصيلُ المسار في مكانها المُصرَّح، لا هنا.</p>
</div>
