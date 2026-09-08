{{-- التشغيل والصحّة (spec §37): فحوصٌ حقيقيّة — جوهريّةٌ تحسم الإجماليّ، وخارجيّةٌ
     تقول NOT_CONFIGURED صدقاً (لا تُحوَّل شرطُ إطلاقٍ إلى عُطلِ نظام). --}}
@php
    $tone = fn ($s) => \App\Support\MobilePlatform::TONE[$s] ?? 'g';
    $lbl  = fn ($s) => \App\Support\MobilePlatform::LABEL[$s] ?? $s;
@endphp

<div class="cards">
    <div class="stat">
        <span class="ico" aria-hidden="true">🩺</span>
        <b class="{{ $tone($health['status']) === 'bad' ? 'txt-bad' : '' }}">{{ $lbl($health['status']) }}</b>
        <span>الحالةُ الإجماليّة (المكوّناتُ الجوهريّة)</span>
    </div>
</div>

<div class="card kid wide">
    <h3>⚙️ مكوّناتٌ جوهريّة</h3>
    <table class="mini">
        <tr><th>المكوّن</th><th>الحالة</th><th>السبب</th></tr>
        @foreach ($health['core'] as $c)
            <tr>
                <td>{{ $c['label'] }}</td>
                <td><span class="bdg {{ $tone($c['status']) }}">{{ $lbl($c['status']) }}</span></td>
                <td class="sub">{{ $c['why'] }}</td>
            </tr>
        @endforeach
    </table>
</div>

<div class="card kid wide">
    <h3>🌐 تبعيّاتٌ خارجيّة (قبل الإطلاق)</h3>
    <table class="mini">
        <tr><th>التبعيّة</th><th>الحالة</th><th>ملاحظة</th></tr>
        @foreach ($health['external'] as $c)
            <tr>
                <td>{{ $c['label'] }}</td>
                <td><span class="bdg {{ $tone($c['status']) }}">{{ $lbl($c['status']) }}</span></td>
                <td class="sub">{{ $c['why'] }}</td>
            </tr>
        @endforeach
    </table>
    <p class="sub" style="margin-top:8px">«غير مُهيّأ» هنا شرطُ إطلاقٍ خارجيٌّ لا عُطل — لا يُهبِط الحالةَ الإجماليّة.</p>
</div>
