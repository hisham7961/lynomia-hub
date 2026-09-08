{{-- نظرةٌ عامّة (spec §7/§56): مؤشّراتٌ حقيقيّة + حالةُ التهيئة الصادقة (تكشف ما يحتاج
     ضبطاً) + بطاقةُ الجاهزية. قيمٌ حقيقيّةٌ فقط — لا بيانات وهميّة. --}}
@php
    $tone = fn ($s) => \App\Support\MobilePlatform::TONE[$s] ?? 'g';
    $lbl  = fn ($s) => \App\Support\MobilePlatform::LABEL[$s] ?? $s;
    $R = \App\Support\MobilePlatform::READY;
    $NC = \App\Support\MobilePlatform::NOT_CONFIGURED;
@endphp

@include('partials.cc.kpis', ['items' => [
    ['label' => 'مسارات الـMobile API', 'value' => $ov['api']['route_count'], 'tone' => 'ok',
        'sub' => 'العقد v' . $ov['api']['version']],
    ['label' => 'جلساتٌ نشطة', 'value' => number_format($ov['sess']['active']), 'tone' => 'ok',
        'sub' => number_format($ov['sess']['recent']) . ' استُعملت آخرَ ٧ أيام'],
    ['label' => 'تنصيباتٌ مسجّلة', 'value' => number_format($ov['inst']['total']),
        'sub' => 'iOS ' . $ov['inst']['ios'] . ' · Android ' . $ov['inst']['android']],
    ['label' => 'رموزُ دفعٍ حيّة', 'value' => number_format($ov['push']['tokens_active']),
        'tone' => $ov['push']['configured'] ? 'ok' : '', 'sub' => $lbl($ov['push']['state'])],
]])

{{-- حالةُ التهيئة — قابلةٌ للفعل (§56): كلُّ سطرٍ حالتُه الصادقة ورابطُ ضبطِه --}}
<div class="card kid wide">
    <h3>🚦 حالةُ التهيئة</h3>
    <table class="mini">
        <tr><th>القدرة</th><th>الحالة</th><th></th></tr>
        <tr>
            <td>الـMobile API</td>
            <td><span class="bdg {{ $tone($R) }}">{{ $lbl($R) }}</span></td>
            <td class="acts"><a class="btn ghost xs" href="{{ $ov['api']['openapi_url'] }}">عرض OpenAPI ←</a></td>
        </tr>
        <tr>
            <td>الدفع (Push)</td>
            <td><span class="bdg {{ $tone($ov['push']['state']) }}">{{ $lbl($ov['push']['state']) }}</span>
                @if ($ov['push']['configured'])<span class="sub"> · {{ $ov['push']['driver'] }}</span>@endif</td>
            <td class="acts">
                @unless ($ov['push']['configured'])<a class="btn ghost xs" href="{{ route('settings.edit') }}#mobile.push_driver">هيّئ ←</a>@endunless
            </td>
        </tr>
        <tr>
            <td>روابطُ iOS العميقة (Universal Links)</td>
            <td><span class="bdg {{ $tone($ov['dl']['apple']['state']) }}">{{ $lbl($ov['dl']['apple']['state']) }}</span></td>
            <td class="acts">@unless ($ov['dl']['apple']['configured'])<a class="btn ghost xs" href="{{ route('settings.edit') }}#mobile.dl_apple_team_id">هيّئ ←</a>@endunless</td>
        </tr>
        <tr>
            <td>روابطُ Android العميقة (App Links)</td>
            <td><span class="bdg {{ $tone($ov['dl']['android']['state']) }}">{{ $lbl($ov['dl']['android']['state']) }}</span></td>
            <td class="acts">@unless ($ov['dl']['android']['configured'])<a class="btn ghost xs" href="{{ route('settings.edit') }}#mobile.dl_android_fingerprints">هيّئ ←</a>@endunless</td>
        </tr>
        <tr>
            <td>بوّابةُ الإصدار</td>
            <td><span class="bdg {{ $ov['ver']['gate_active'] ? $tone($R) : $tone($NC) }}">{{ $ov['ver']['gate_active'] ? $lbl($R) : $lbl($NC) }}</span>
                @if ($ov['ver']['ios']['invalid'] || $ov['ver']['android']['invalid'])<span class="bdg bad">ضبطٌ باطل</span>@endif</td>
            <td class="acts">@unless ($ov['ver']['gate_active'])<a class="btn ghost xs" href="{{ route('settings.edit') }}#mobile.min_version_ios">هيّئ ←</a>@endunless</td>
        </tr>
        <tr>
            <td>الجلساتُ النشطة</td>
            <td><b>{{ number_format($ov['sess']['active']) }}</b></td>
            <td class="acts"><span class="sub">تُدار في تبويب الأجهزة (طورٌ لاحق)</span></td>
        </tr>
    </table>
</div>

{{-- بطاقةُ الجاهزية (§8): حالةُ كلِّ قدرة — مُنفَّذ/جاهز/غير مُهيّأ (لا READY مُزيّف) --}}
<div class="card kid wide">
    <h3>✅ بطاقةُ جاهزيّةِ المنصّة</h3>
    <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(220px,100%),1fr))">
        @foreach ($scorecard as $row)
            <div class="stat" style="align-items:flex-start">
                <span>{{ $row['label'] }}</span>
                <span class="bdg {{ $tone($row['state']) }}">{{ $lbl($row['state']) }}</span>
            </div>
        @endforeach
    </div>
    <p class="sub" style="margin-top:8px">«مُنفَّذ» = القدرةُ مبنيّةٌ في الكود؛ «غير مُهيّأ» = تحتاج ضبطاً خارجيّاً قبل الإطلاق (لا عُطل).</p>
</div>
