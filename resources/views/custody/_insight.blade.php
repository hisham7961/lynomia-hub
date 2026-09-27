{{--
    تحليلُ صنف العهدة — الحقائقُ الحتميّةُ أوّلاً (بنطاق القارئ وحقوله · CustodyFacts)،
    ثم تحليلُ الذكاء (CustodyInsights) لمن يرى الصنفَ كلَّه، موسوماً بأنّه مولَّدٌ آليّاً مع وقته ونموذجه.
    لا معالجَ مضمَّناً ولا سكربت: نموذجُ POST عاديّ (CSP).
--}}
@php
    $f = $facts;
    $pct = fn ($n, $d) => $d > 0 ? round($n * 100 / $d) : 0;
@endphp
<section class="card" aria-labelledby="ci-h">
    <h3 class="cardtitle" id="ci-h">📊 تحليل الذكاء الاصطناعي — {{ $cat['name'] }}</h3>

    <h4 class="sub" style="margin:4px 0 8px">الحقائق (محسوبةٌ مباشرةً من السجلّ — بلا ذكاء)</h4>
    <div class="cards">
        <div class="stat"><span class="ico">📦</span><b>{{ number_format($f['total']) }}</b><span>عهدة في الصنف</span></div>
        <div class="stat"><span class="ico">🟢</span><b>{{ number_format($f['active']) }}</b><span>في الخدمة</span></div>
        @isset($f['holders'])
            <div class="stat"><span class="ico">🤲</span><b>{{ number_format($f['holders']['held']) }}</b><span>بيد موظفين</span></div>
            <div class="stat"><span class="ico">🗄️</span><b>{{ number_format($f['holders']['unheld']) }}</b><span>بلا حائز</span></div>
        @endisset
        @isset($f['repair'])
            <div class="stat"><span class="ico">🛠️</span><b>{{ number_format($f['repair']['n']) }}</b><span>في الإصلاح أو معطوبة</span></div>
        @endisset
        @isset($f['warranty'])
            <div class="stat"><span class="ico">⏳</span><b>{{ number_format($f['warranty']['expiring']) }}</b><span>ضمانٌ ينتهي خلال {{ $f['warranty']['days'] }} يوماً</span></div>
            <div class="stat"><span class="ico">⌛</span><b>{{ number_format($f['warranty']['expired']) }}</b><span>ضمانٌ منتهٍ</span></div>
        @endisset
    </div>
    @if ($f['truncated'])
        <p class="sub">حُسبت الحقائقُ على أوّل {{ number_format(\App\Support\Assets\CustodyFacts::ROW_CAP) }} عهدةٍ بالكود.</p>
    @endif

    <div class="fg" style="align-items:flex-start">
        @isset($f['status'])
            <div class="fld">
                <b>حسب الحالة</b>
                <ul>
                    @foreach ($f['status'] as $s => $n)
                        <li><span class="bdg {{ hub_tone($s) }}">{{ $s }}</span> {{ number_format($n) }}</li>
                    @endforeach
                </ul>
            </div>
        @endisset
        @if (! empty($f['holders']['top']))
            <div class="fld">
                <b>أكثر الحائزين عهداً</b>
                <ul>
                    @foreach ($f['holders']['top'] as $h)
                        <li>{{ $h['name'] }} — {{ $h['n'] }} ({{ $pct($h['n'], $f['active']) }}٪)</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (! empty($f['stations']['top']))
            <div class="fld">
                <b>حسب المحطة</b>
                <ul>
                    @foreach ($f['stations']['top'] as $s)<li>{{ $s['name'] }} — {{ $s['n'] }}</li>@endforeach
                    <li class="sub">بلا محطة — {{ $f['stations']['none'] }}</li>
                </ul>
            </div>
        @endif
        @if (! empty($f['projects']['top']))
            <div class="fld">
                <b>حسب المشروع</b>
                <ul>
                    @foreach ($f['projects']['top'] as $p)<li>{{ $p['name'] }} — {{ $p['n'] }}</li>@endforeach
                    <li class="sub">بلا مشروع — {{ $f['projects']['none'] }}</li>
                </ul>
            </div>
        @endif
        @isset($f['age'])
            <div class="fld">
                <b>العمر (من تاريخ الشراء)</b>
                <ul>
                    @foreach ($f['age']['buckets'] as $b => $n)<li>{{ $b }} — {{ $n }}</li>@endforeach
                    @if ($f['age']['oldest'])<li class="sub">الأقدم: <span class="mono">{{ $f['age']['oldest'] }}</span></li>@endif
                </ul>
            </div>
        @endisset
        <div class="fld">
            <b>المواصفات الناقصة</b>
            <ul>
                <li>بلا أيِّ مواصفات — {{ $f['specs']['empty'] }}</li>
                @foreach (array_slice($f['specs']['missing'], 0, 6, true) as $l => $n)<li>{{ $l }} — {{ $n }}</li>@endforeach
            </ul>
        </div>
    </div>

    @if (! empty($f['warranty']['items']) || ! empty($f['repair']['items']))
        <div class="fg" style="align-items:flex-start">
            @if (! empty($f['warranty']['items']))
                <div class="fld">
                    <b>ضمانٌ يوشك أن ينتهي</b>
                    <ul>
                        @foreach ($f['warranty']['items'] as $i)
                            <li><a class="mono" href="{{ route('m.show', ['assets', $i['id']]) }}">{{ $i['code'] }}</a> {{ $i['name'] }} — <span class="mono">{{ $i['note'] }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (! empty($f['repair']['items']))
                <div class="fld">
                    <b>في الإصلاح أو معطوبة</b>
                    <ul>
                        @foreach ($f['repair']['items'] as $i)
                            <li><a class="mono" href="{{ route('m.show', ['assets', $i['id']]) }}">{{ $i['code'] }}</a> {{ $i['name'] }} — {{ $i['note'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    @if (! empty($f['moves']))
        <b>آخر الحركات</b>
        <ul>
            @foreach ($f['moves'] as $m)
                <li><span class="mono">{{ $m['at'] }}</span> · {{ $m['action'] }} · <a class="mono" href="{{ route('m.show', ['assets', $m['assetId']]) }}">{{ $m['asset'] }}</a> · {{ $m['who'] }}</li>
            @endforeach
        </ul>
    @endif

    <hr>
    @if (! $insight['show'])
        @if ($insight['note'])<p class="sub">🤖 {{ $insight['note'] }}</p>@endif
    @else
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <h4 style="margin:0">🤖 التحليل الآلي</h4>
            <span class="bdg">مولَّدٌ بالذكاء الاصطناعي — راجِعه قبل القرار</span>
            @if ($insight['at'])
                <span class="sub">آخر تحديث: <span class="mono">{{ $insight['at'] }}</span>@if ($insight['model']) · النموذج: <span class="mono" dir="ltr">{{ $insight['model'] }}</span>@endif</span>
            @endif
            <div class="spacer"></div>
            @if ($insight['canRefresh'] && in_array($insight['state'], ['ok', 'failed', 'empty'], true))
                <form method="POST" action="{{ route('custody.insight', $code) }}">
                    @csrf
                    <button class="btn ghost sm">🔄 تحديث التحليل</button>
                </form>
            @endif
        </div>

        @if ($insight['state'] === 'off')
            <p class="sub">⏸️ التحليلُ الآليّ مطفأ — {{ $insight['why'] }}</p>
        @elseif ($insight['state'] === 'unconfigured')
            <p class="sub">⚙️ غيرُ مهيّأ: {{ $insight['why'] }}</p>
        @elseif ($insight['state'] === 'empty')
            <p class="sub">لم يُولَّد تحليلٌ لهذا الصنف بعد — يُولَّد في الدورة اليوميّة أو بزرّ «تحديث التحليل».</p>
        @endif
        @if ($insight['failedAt'])
            <p class="flash bad">⚠️ فشلت آخرُ محاولةٍ ({{ $insight['failedAt'] }}): {{ $insight['error'] }}@if ($insight['analysis']) — المعروضُ التحليلُ السابق.@endif</p>
        @endif

        @if ($insight['analysis'])
            @php $an = $insight['analysis']; @endphp
            <p><b>الحالة العامة:</b> {{ $an['summary'] }}</p>
            @foreach (['risks' => '⚠️ المخاطر', 'recommendations' => '✅ توصيات', 'data_gaps' => '📝 ملاحظات عن البيانات الناقصة'] as $k => $label)
                @if (! empty($an[$k]))
                    <b>{{ $label }}</b>
                    <ul>
                        @foreach ($an[$k] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                @endif
            @endforeach
        @endif
    @endif
</section>
