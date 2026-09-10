{{-- السجلُّ الأمنيّ الموحَّد: تصنيفٌ قانونيّ واحد فوق التدقيق ورادار المنع — لا جدولَ ثانٍ --}}
<div class="card" id="secevents">
    <div class="crow" style="justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <h3 style="margin:0">🧾 السجل الأمني الموحّد <span class="sub">(٧ أيام)</span></h3>
        <form method="GET" class="crow" style="gap:6px">
            <label class="vh" for="ev">تصفية بالحدث</label>
            <select class="inp" id="ev" name="ev" onchange="this.form.submit()">
                <option value="">كل الأحداث ({{ array_sum($eventCounts) }})</option>
                @foreach (\App\Support\SecurityEvents::CODES as $code => [$label, $sev])
                    <option value="{{ $code }}" @selected($eventCode === $code)>{{ $label }} · {{ $code }}@if (isset($eventCounts[$code])) ({{ $eventCounts[$code] }})@endif</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="sub" style="margin:4px 0 10px">
        من فعل ماذا أمنياً — دخولٌ وفشلُه، وكشفُ سرّ، وتغييرُ دورٍ أو سياسة، وسكُّ مفتاح، ومنعُ وصول — بكودٍ آليٍّ ثابتٍ يُصفّى به ويُربط بمعرّف الطلب. المصدر: سلسلة التدقيق المختومة ورادار المنع.
    </div>
    <div class="crow" style="gap:6px;flex-wrap:wrap;margin-bottom:8px">
        @foreach (array_slice($eventCounts, 0, 8, true) as $code => $n)
            @php [$lbl, $sev] = \App\Support\SecurityEvents::CODES[$code]; @endphp
            <a class="bdg {{ \App\Support\SecurityEvents::SEVERITY_TONE[$sev] }}" href="{{ route('security.index', ['ev' => $code]) }}#secevents" title="{{ $code }}">{{ $lbl }} {{ $n }}</a>
        @endforeach
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الحدث</th><th>من</th><th>ماذا</th><th>من أين</th><th>متى</th><th class="vh">التفصيل</th></tr></thead>
        <tbody>
        @forelse ($events as $e)
            <tr>
                <td><span class="bdg {{ $e['tone'] }}" title="{{ $e['severity'] }}">{{ $e['label'] }}</span><div class="sub mono ltr" style="font-size:10px">{{ $e['code'] }}</div></td>
                <td>{{ $e['user'] ?? ($e['user_id'] ? 'مستخدم محذوف' : 'زائرٌ غير مستخدم') }}</td>
                <td style="max-width:320px">{{ $e['name'] }}@if ($e['module'])<div class="sub">{{ hub_mod($e['module'])['label'] ?? $e['module'] }}</div>@endif
                    @if (! empty($e['request_id']))<div class="sub mono ltr" style="font-size:10px" title="معرّف الطلب">{{ $e['request_id'] }}</div>@endif</td>
                <td class="mono ltr sub">{{ $e['ip'] ?: '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Carbon::parse($e['at'])->diffForHumans() }}</td>
                {{-- §42.9 اكتشافٌ سياقيّ: صفُّ الحدث ← تفصيلُه القائم (security.event). لا مركزَ ثانٍ ولا بندَ شريط — الحارسُ يُعاد فحصُه في المتحكّم (مطموسٌ ومنطَّق). --}}
                <td>@if (! empty($e['id']))<a class="btn ghost xs" href="{{ route('security.event', [$e['source'], $e['id']]) }}" title="تفصيل الحدث الأمنيّ">عرض</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="sub" style="padding:12px;text-align:center">لا أحداث أمنية{{ $eventCode ? ' من هذا النوع' : '' }} خلال ٧ أيام.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
