{{-- جسمُ «تقرير الأداء (ذكاء اصطناعي)» — يُضمَّن في تبويب الملفّ الشامل وفي صفحة التقرير.
     المدخل: $pf (PerformanceAccess::panel) · $emp · $pfAction (رابطُ مبدّل الفترة) · $pfHidden (حقولٌ مخفيّة للمبدّل).
     الحقائقُ أرقامٌ حسبها الخادم؛ والسردُ مولَّدٌ فيُهرَّب — لا HTML من النموذج، والرمزان [E] و[PRn] يُستبدلان
     هنا وحدَه (الاسمُ لم يغادر الخادم). --}}
@php
    $pfRow = $pf['row'];
    $pfF = (array) ($pfRow?->facts ?? []);
    $pfN = (array) ($pfRow?->narrative ?? []);
    $pfText = fn ($s) => \App\Support\Ai\Reports\EmployeePerformance::text((string) $s, (string) $emp->name, $pf['projects']);
    $pfNum = fn ($v, $suffix = '') => $v === null ? '—' : ((is_float($v) ? number_format($v, 1) : $v) . $suffix);
@endphp
@if ($pf['masked'])
    @include('partials.empty', ['text' => 'تقريرُ الأداء محجوبٌ عنك: بعضُ الحقول التي يُبنى منها (آخرُ تقييم أداء أو حقولُ التقارير اليوميّة) محجوبةٌ في دورك.', 'icon' => '🔒'])
@elseif ($pfRow === null)
    @include('partials.empty', ['text' => $pf['enabled']
        ? 'لم يُولَّد تقريرُ أداءٍ لهذا الموظّف بعد — يُولَّد في الجولة اليوميّة بعد أن يمضي من الفترة يومٌ كامل.'
        : 'تقريرُ الأداء بالذكاء مطفأ — ' . $pf['why'], 'icon' => '🤖'])
@else
    <form method="get" action="{{ $pfAction }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:8px">
        @foreach (($pfHidden ?? []) as $pfK => $pfV)<input type="hidden" name="{{ $pfK }}" value="{{ $pfV }}">@endforeach
        <label class="sub">الفترة
            <select name="period" class="in" data-submit-on-change="filled">
                @foreach ($pf['rows'] as $pfOpt)
                    <option value="{{ $pfOpt->period }}" @selected($pfOpt->period === $pfRow->period)>{{ $pfOpt->period }}{{ $pfOpt->status === 'failed' ? ' (أخفق)' : '' }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn ghost xs">عرض</button>
    </form>

    <div class="sub" style="margin-bottom:8px">
        <span class="bdg i">🤖 مولَّدٌ بالذكاء الاصطناعي</span>
        · {{ \App\Support\Ai\Reports\PerformancePeriod::label(['kind' => $pfRow->period_kind, 'key' => $pfRow->period,
            'from' => optional($pfRow->period_from)->toDateString(), 'to' => optional($pfRow->period_to)->toDateString()]) }}
        @if ($pfRow->as_of) · الحقائقُ حتى <span class="mono">{{ $pfRow->as_of->toDateString() }}</span>@endif
        @if (! ($pfF['period']['complete'] ?? true))<span class="bdg wn">فترةٌ جارية</span>@endif
        @if ($pfRow->generated_at) · آخرُ توليد <span class="mono">{{ $pfRow->generated_at->format('Y-m-d H:i') }}</span>@endif
        @if ($pfRow->model) · النموذج <span class="mono">{{ $pfRow->model }}</span>@endif
        @if ($pfRow->status === 'failed')<span class="bdg bad" title="المعروضُ آخرُ ما نجح">آخرُ تحديثٍ أخفق ({{ $pfRow->error_code }})</span>@endif
    </div>

    @if ($pfN !== [])
        @if (($pfN['summary'] ?? '') !== '')
            <p style="margin:6px 0 10px"><b>الخلاصة:</b> {{ $pfText($pfN['summary']) }}</p>
        @endif
        @foreach (\App\Support\Ai\Reports\EmployeePerformance::SECTIONS as $pfKey => $pfLabel)
            @php $pfItems = (array) ($pfN[$pfKey] ?? []); @endphp
            <div style="margin:8px 0">
                <b>{{ $pfLabel }}</b>
                @if ($pfItems === [])
                    <div class="sub">— لا شيء</div>
                @else
                    <ul style="margin:4px 0;padding-inline-start:20px">
                        @foreach ($pfItems as $pfItem)<li>{{ $pfText($pfItem) }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @else
        <div class="sub" style="margin:6px 0"><span class="bdg bad">لا سرد</span> تعذّر توليدُ السرد لهذه الفترة ({{ $pfRow->error_code ?: '—' }}) — يُعاد في الجولة التالية. الأرقامُ أدناه حسبها الخادم.</div>
    @endif

    @if ($pfF !== [])
        @php
            $pfR = (array) ($pfF['reports'] ?? []); $pfA = (array) ($pfF['attendance'] ?? []); $pfW = (array) ($pfF['work'] ?? []);
            $pfT = $pfF['tasks'] ?? null; $pfL = $pfF['leaves'] ?? null; $pfC = $pfF['custody'] ?? null;
            $pfK2 = $pfF['tickets'] ?? null; $pfX = $pfF['findings'] ?? null;
        @endphp
        <h4 style="margin:14px 0 6px">📊 الحقائق (محسوبةٌ في الخادم لا بالذكاء)</h4>
        <div class="tblwrap"><table class="mini" data-performance-facts>
            <tr><td><b>التقارير اليوميّة</b></td>
                <td>مقدَّم <b>{{ $pfNum($pfR['submitted'] ?? null) }}</b> · ناقص <b>{{ $pfNum($pfR['missing'] ?? null) }}</b>
                    · متأخّر {{ $pfNum($pfR['late'] ?? null) }} · معذور {{ $pfNum($pfR['excused'] ?? null) }}
                    · أيّامُ عمل {{ $pfNum($pfR['workdays'] ?? null) }} · الامتثال <b>{{ $pfNum($pfR['compliance_pct'] ?? null, '٪') }}</b></td></tr>
            <tr><td><b>مراجعةُ التقارير</b></td>
                <td>مقبول {{ $pfNum($pfR['review']['accepted'] ?? null) }} · يحتاج تنقيحاً <b>{{ $pfNum($pfR['review']['needs_revision'] ?? null) }}</b>
                    · بانتظار {{ $pfNum($pfR['review']['pending'] ?? null) }} · بنود {{ $pfNum($pfR['items'] ?? null) }}</td></tr>
            <tr><td><b>الحضور</b></td>
                <td>حاضر <b>{{ $pfNum($pfA['present'] ?? null) }}</b> · غائب <b>{{ $pfNum($pfA['absent'] ?? null) }}</b>
                    · متأخّر {{ $pfNum($pfA['late'] ?? null) }} · إجازة {{ $pfNum($pfA['leave'] ?? null) }}
                    · معذور {{ $pfNum($pfA['excused'] ?? null) }} · ميدانيّ/عن بعد {{ $pfNum($pfA['field'] ?? null) }}
                    · ساعاتُ الحضور {{ $pfNum($pfA['hours'] ?? null) }}</td></tr>
            <tr><td><b>العمل والمشاريع</b></td>
                <td>ساعاتٌ مُبلَّغة <b>{{ $pfNum($pfW['reported_hours'] ?? null) }}</b> · مشاريع {{ $pfNum($pfW['projects_count'] ?? null) }}
                    · عملٌ داخليّ {{ $pfNum($pfW['internal_hours'] ?? null) }} ساعة
                    @foreach ((array) ($pfW['projects'] ?? []) as $pfP)
                        <div class="sub">{{ $pf['projects'][$pfP['code']] ?? 'مشروع' }} — {{ $pfNum($pfP['hours']) }} ساعة · {{ $pfP['reports'] }} بنداً</div>
                    @endforeach</td></tr>
            @if (is_array($pfT))
                <tr><td><b>المهامّ</b></td>
                    <td>أُسندت {{ $pfT['assigned'] }} · أُنجزت <b>{{ $pfT['completed'] }}</b> · في الموعد {{ $pfNum($pfT['on_time_pct'], '٪') }}
                        · فات موعدُها <b>{{ $pfT['overdue'] }}</b></td></tr>
            @endif
            @if (is_array($pfL))
                <tr><td><b>الإجازات</b></td>
                    <td>أيّامٌ معتمدة {{ $pfL['approved_days'] }}
                        @foreach ((array) ($pfL['by_type'] ?? []) as $pfType => $pfDays)<span class="sub">· {{ $pfType }} {{ $pfDays }}</span>@endforeach
                        · معلّقة {{ $pfL['pending'] }} · مرفوضة {{ $pfL['rejected'] }}</td></tr>
            @endif
            @if (is_array($pfC))
                <tr><td><b>العهدة</b></td><td>أصولٌ بيده الآن {{ $pfC['assets_held'] }} · حركاتُ عهدةٍ في الفترة {{ $pfC['moves'] }}</td></tr>
            @endif
            @if (is_array($pfK2))
                <tr><td><b>التذاكر والمشكلات</b></td>
                    <td>تذاكر: أُسندت {{ $pfK2['tickets_assigned'] }} · حُلّت {{ $pfK2['tickets_resolved'] }}
                        · مشكلات: أُسندت {{ $pfK2['issues_assigned'] }} · أُغلقت {{ $pfK2['issues_resolved'] }}</td></tr>
            @endif
            @if (is_array($pfX))
                <tr><td><b>نتائجُ المدقّق</b></td>
                    <td>{{ $pfX['total'] }} نتيجة (مهمّة {{ $pfX['high'] }} · متوسّطة {{ $pfX['medium'] }} · اطّلاع {{ $pfX['info'] }}) · مفتوحة {{ $pfX['open'] }}</td></tr>
            @endif
        </table></div>
    @endif
    <div class="sub" style="margin-top:8px">سردٌ آليٌّ فوق أرقامٍ حسبها النظام — قد يخطئ في التقدير؛ الأرقامُ والتقاريرُ نفسُها هي المرجع،
        ولا يراه الموظّفُ نفسُه.</div>
@endif
