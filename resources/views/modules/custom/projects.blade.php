{{-- سياقُ العميل ونشاطُ الأسبوع — قبل العدسات: لمن هذا المشروع وماذا جرى فيه --}}
@php
    $pcClient = $row->client_id ? \App\Models\Client::whereNull('deleted_at')->find($row->client_id) : null;
    $pcEng = $row->engagement_id ? \App\Models\Engagement::whereNull('deleted_at')->find($row->engagement_id) : null;
    $pcLogs = \Illuminate\Support\Facades\DB::table('work_updates')->whereNull('deleted_at')
        ->where('project_id', $row->id)
        ->where('work_date', '>=', now()->subDays(7)->toDateString())
        ->get(['created_by', 'hours', 'problems', 'work_date']);
    $pcPeople = hub_ref_labels('users', $pcLogs->pluck('created_by')->filter()->unique()->values()->all());
    $pcBlockers = $pcLogs->pluck('problems')->filter(fn ($p) => trim((string) $p) !== '');
    $pcBaseline = ((array) $row->meta)['baseline'] ?? null;
@endphp
@if ($pcBaseline)
    {{-- خطُّ الأساس التجاريّ: العرضُ المقبول الذي وُلد منه المشروع — لا يتغيّر بالتعديل --}}
    <div class="card">
        <h3 class="cardtitle">📐 خطُّ الأساس التجاريّ <span class="bdg g">من عرضٍ مقبول</span></h3>
        <div class="crow">
            @if (! empty($pcBaseline['quote_id']))
                <a class="chip" href="{{ route('m.show', ['quotes', $pcBaseline['quote_id']]) }}">🧾 {{ $pcBaseline['quote_no'] ?? 'العرض' }}</a>
            @endif
            <span class="chip">القيمة المعتمدة: <b class="mono">{{ number_format((float) ($pcBaseline['amount'] ?? 0), 3) }} {{ $pcBaseline['currency'] ?? '' }}</b></span>
            @if (! empty($pcBaseline['accepted_at']))<span class="sub">قُبل {{ \Illuminate\Support\Str::limit(str_replace('T', ' ', $pcBaseline['accepted_at']), 16, '') }}</span>@endif
        </div>
        <div class="sub" style="margin-top:6px">هذا النطاقُ والمبلغُ الأصليّان — أيُّ تغييرٍ لاحقٍ يُدار بإدارة التغيير لا بتعديل العرض المقبول.</div>
    </div>
    @php
        $pcCOs = $pcBaseline['change_orders'] ?? [];
        $pcOrig = (float) ($pcBaseline['amount'] ?? 0);
        $pcCOsum = collect($pcCOs)->sum(fn ($c) => (float) ($c['value_delta'] ?? 0));
        $pcCur = round($pcOrig + $pcCOsum, 3);
        $pcDaysSum = collect($pcCOs)->sum(fn ($c) => (int) ($c['timeline_days'] ?? 0));
        $pcShowInternal = hub_field_mode(auth()->user(), 'projects', 'budget') !== 'hide';
    @endphp
    @if (! empty($pcCOs))
        {{-- التطوّرُ التجاريّ: الأصل + أوامرُ التغيير المطبَّقة = القيمة الحالية --}}
        <div class="card">
            <h3 class="cardtitle">📈 التطوّرُ التجاريّ للمشروع <span class="bdg g">{{ count($pcCOs) }} أمر تغيير</span></h3>
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <div><div class="sub">القيمة الأصلية</div><b class="mono">{{ number_format($pcOrig, 3) }} {{ $pcBaseline['currency'] ?? '' }}</b></div>
                <div><div class="sub">أوامرُ التغيير المطبَّقة</div><b class="mono {{ $pcCOsum >= 0 ? '' : 'txt-bad' }}">{{ $pcCOsum >= 0 ? '+' : '' }}{{ number_format($pcCOsum, 3) }}</b></div>
                <div><div class="sub">القيمة الحالية TCV</div><b class="mono">{{ number_format($pcCur, 3) }} {{ $pcBaseline['currency'] ?? '' }}</b></div>
                @if ($pcDaysSum)<div><div class="sub">أثرُ الجدول</div><b class="mono">{{ $pcDaysSum > 0 ? '+' : '' }}{{ $pcDaysSum }} يوماً</b></div>@endif
            </div>
            <div class="tblwrap" style="margin-top:8px"><table>
                <thead><tr><th>أمر التغيير</th><th>القيمة</th><th>الجدول</th><th>طُبّق</th></tr></thead>
                <tbody>
                @foreach ($pcCOs as $c)
                    <tr>
                        <td>@if (! empty($c['co_id']))<a class="chip" href="{{ route('m.show', ['changeorders', $c['co_id']]) }}">📋 {{ $c['co_no'] ?? 'أمر' }}</a>@else {{ $c['co_no'] ?? '—' }} @endif</td>
                        <td class="mono">{{ (float) ($c['value_delta'] ?? 0) >= 0 ? '+' : '' }}{{ number_format((float) ($c['value_delta'] ?? 0), 3) }}</td>
                        <td class="mono">{{ (int) ($c['timeline_days'] ?? 0) ? ((int) $c['timeline_days'] . ' يوماً') : '—' }}</td>
                        <td class="sub">{{ ! empty($c['applied_at']) ? \Illuminate\Support\Str::limit(str_replace('T', ' ', $c['applied_at']), 16, '') : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
@endif
@if ($pcClient)
    <div class="card">
        <h3 class="cardtitle">🤝 مشروعُ عميل</h3>
        {{-- فتاتُ السياق: عميل / ارتباط / مشروع — فلا يُعدَّل سجلُّ عميلٍ بظنّه داخلياً --}}
        <div class="crow">
            <a class="chip" href="{{ route('m.show', ['clients', $pcClient->id]) }}">👤 {{ $pcClient->name }}</a>
            @if ($pcEng)<span class="sub">/</span>
                <a class="chip" href="{{ route('m.show', ['engagements', $pcEng->id]) }}">🤝 {{ $pcEng->name }}</a>@endif
            <span class="sub">/</span>
            <span class="chip">🗂️ {{ \Illuminate\Support\Str::limit($row->name, 30) }}</span>
        </div>
        <div class="sub" style="margin-top:6px">كلُّ ما هنا يدخل ربحيةَ الارتباط وتقاريرَ العميل —
            والملاحظاتُ الداخلية تبقى داخلية.</div>
    </div>
@endif
@if ($pcLogs->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">📅 نشاط آخر ٧ أيام
            <span class="bdg g">{{ $pcLogs->count() }} بنداً</span>
            @if ($pcBlockers->isNotEmpty())<span class="bdg bad">🚧 {{ $pcBlockers->count() }} عائقاً</span>@endif
        </h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">موظفون نشطون</div><b>{{ $pcLogs->pluck('created_by')->unique()->count() }}</b>
                <div class="sub">{{ \Illuminate\Support\Str::limit(collect($pcPeople)->values()->implode('، '), 60) }}</div></div>
            <div><div class="sub">ساعات مسجَّلة</div><b class="mono">{{ number_format((float) $pcLogs->sum('hours'), 1) }}</b></div>
            <div><div class="sub">آخر بند</div><b class="mono">{{ substr((string) $pcLogs->max('work_date'), 0, 10) }}</b></div>
        </div>
        @if ($pcBlockers->isNotEmpty())
            <div class="sub" style="margin-top:8px;color:var(--bad, inherit)">
                أحدث عائق: {{ \Illuminate\Support\Str::limit($pcBlockers->last(), 120) }}
            </div>
        @endif
        <a class="btn ghost xs" style="margin-top:8px" href="{{ route('m.index', 'updates') }}">📝 كل بنود العمل</a>
    </div>
@endif

{{-- عدسات المشروع: كل لوحةٍ تحليلية مفتوحةً على هذا المشروع وحده --}}
@php
    $plLenses = array_values(array_filter([
        hub_monitor() ? ['capacity', '📊', 'القدرات والموارد', 'حصّته من طاقة الفريق'] : null,
        hub_monitor() ? ['recs', '💡', 'التوصيات', 'ما يستحق التحرك فيه'] : null,
        hub_monitor() ? ['impact', '🕸️', 'خريطة الأثر', 'ما يسقط إن سقط عنصر'] : null,
        hub_monitor() ? ['appquality', '🧪', 'جودة البرمجيات', 'أخطاؤه وأعطاله ونشره'] : null,
        hub_can(auth()->user(), 'contracts', 'v') ? ['legal', '⚖️', 'القانوني', 'عقوده والتزاماته'] : null,
        hub_can(auth()->user(), 'ideas', 'v') ? ['innovation', '💡', 'الابتكار', 'أفكارٌ تطوّره'] : null,
    ]));
@endphp
@if ($plLenses)
<div class="card">
    <h3 class="cardtitle">🔭 عدسات هذا المشروع</h3>
    <div class="sub" style="margin-bottom:8px">
        اللوحات التحليلية كانت تعرض المنشأة كلها مجموعةً — هذه كلٌّ منها محصورةً بهذا المشروع.
    </div>
    <div class="crow">
        @foreach ($plLenses as [$r, $ico, $label, $why])
            <a class="chip" href="{{ route($r, ['p' => $row->id]) }}" title="{{ $why }}">{{ $ico }} {{ $label }}</a>
        @endforeach
    </div>
</div>
@endif
