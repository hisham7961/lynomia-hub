{{-- (WP-2.6) الاعتماديات الخارجية + تاريخ التوافر — من الإعداد الفعليّ وسلسلة القياس.
     البطاقات من OpsController::dependencyCards على نموذج الصحّة الممرَّر ($health) —
     لا نداءَ Health::check ثانياً؛ والتوافر مجمَّعٌ في القاعدة (Uptime::history) لا
     سلسلةَ ٣٠ يوماً تُجلب إلى PHP. كل قارئٍ محميٌّ فسقوطُه لا يُسقط الشاشة. --}}
@php
    // (WP-2.7) البطاقاتُ خلف hub_screen المختومة ٦٠ ثانية: قراءةُ سجلّ التكاملات
    // وطوابعِها كانت تتكرّر بكل فتحة — والطزاجةُ تُقال بسطر cc/freshness أدناه
    $depW = hub_screen('ops.deps', 60,
        fn () => rescue(fn () => \App\Http\Controllers\Web\OpsController::dependencyCards($health), [], false),
        [], true);
    $depCards = $depW['data'];
    $upTargets = rescue(fn () => \App\Support\Uptime::enabled(), [], false);
    $upRange = hub_range(request(), '30d');
@endphp

<h3 class="secttl">🔌 الاعتماديات — من يقف تحت كل قدرة</h3>
<div class="kids">
    @forelse ($depCards as $c)
        <div class="card kid" id="{{ $c['id'] }}">
            <h3>{{ $c['icon'] }} {{ $c['name'] }}
                <span class="bdg {{ $c['tone'] }}">{{ $c['health'] }}</span></h3>
            {{-- (WP-2.7 · critic #14) .tblwrap لكل جداول مركز التشغيل --}}
            <div class="tblwrap"><table class="mini">
                <tr><td>النوع</td><td class="acts sub">{{ $c['type'] }}</td></tr>
                <tr><td>زمن آخر نداء</td>
                    <td class="acts">@if ($c['ms'] !== null)<span class="mono ltr">{{ $c['ms'] }}<small> ms</small></span>@else<span class="sub">لم يُقَس بعد</span>@endif</td></tr>
                <tr><td>آخر نجاح</td>
                    <td class="acts sub">{{ $c['ok_at'] ? \Illuminate\Support\Carbon::parse($c['ok_at'])->diffForHumans() : 'لم يُسجَّل بعد' }}</td></tr>
                <tr><td>آخر فشل</td>
                    <td class="acts sub">{{ $c['fail_at'] ? \Illuminate\Support\Carbon::parse($c['fail_at'])->diffForHumans() : 'لا فشلَ مسجَّلاً' }}</td></tr>
            </table></div>
            @if (! empty($c['error']))<div class="sub" style="margin-top:4px">⚠️ {{ $c['error'] }}</div>
            @elseif (! empty($c['why']))<div class="sub" style="margin-top:4px">{{ $c['why'] }}</div>@endif
        </div>
    @empty
        @include('partials.empty', ['text' => 'لا اعتمادياتَ مضبوطةً بعد — تُضبط التكاملات من مركز التكامل', 'icon' => '🔌'])
    @endforelse
</div>
@include('partials.cc.freshness', ['at' => $depW['at'], 'ttl' => 60])

<div class="card">
    <h3 class="cardtitle">📡 تاريخ التوافر <span class="sub">· {{ $upRange->label() }} — مجمَّعٌ في القاعدة، وفتراتُ الانقطاع تتابعُ فحوصٍ فاشلة</span></h3>
    @if (! $upTargets)
        @include('partials.empty', ['text' => 'لا أهدافَ مراقبةً بعد — فعِّل «المراقبة الحيّة» على سيرفر أو موقع من بطاقته، وسيبدأ القياس من الآن', 'icon' => '📡'])
    @else
        @foreach ($upTargets as [$upMk, $upRow])
            @php $upH = \App\Support\Uptime::history($upMk, (string) $upRow->id, $upRange); @endphp
            <div style="margin-bottom:10px">
                <b>{{ $upRow->name ?? $upRow->id }}</b>
                @if ($upH['live'] === true)<span class="bdg ok">يعمل الآن</span>
                @elseif ($upH['live'] === false)<span class="bdg bad">لا يستجيب</span>@endif
                @if (! $upH['checks'])
                    <div class="sub">لا توجد بيانات تاريخية كافية في هذا المدى — سيبدأ القياس من الآن</div>
                @else
                    <span class="sub">
                        · التوافر <b class="{{ $upH['pct'] >= 99 ? '' : 'txt-bad' }}">{{ $upH['pct'] }}٪</b>
                        · متوسط الاستجابة <span class="mono ltr">{{ $upH['ms'] ?? '—' }}</span> ms
                        · {{ $upH['checks'] }} فحصاً ({{ $upH['down'] }} فاشلاً)
                        · آخر فحص {{ $upH['last'] ? $upH['last']->diffForHumans() : '—' }}
                    </span>
                    @if ($upH['outages'])
                        <div class="tblwrap" style="margin-top:6px"><table class="tbl">
                            <thead><tr><th scope="col">بداية الانقطاع</th><th scope="col">آخر فحص فاشل</th><th scope="col">فحوص فاشلة متتالية</th><th scope="col">الحالة</th></tr></thead>
                            <tbody>
                            @foreach ($upH['outages'] as $o)
                                <tr>
                                    <td>{{ $o['from']->format('Y-m-d H:i') }}</td>
                                    <td>{{ $o['to']->format('Y-m-d H:i') }}</td>
                                    <td>{{ $o['checks'] }}</td>
                                    <td>@if ($o['open'])<span class="bdg bad">جارٍ الآن</span>@else<span class="bdg ok">تعافى</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @else
                        <div class="sub">لا انقطاعات في هذا المدى ✅</div>
                    @endif
                @endif
            </div>
        @endforeach
    @endif
</div>
