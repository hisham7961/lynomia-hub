{{-- صفحةُ الارتباط: الربحيةُ من مصادرها، ومشاريعُه وعقودُه، وموعدُ تجديده —
     لا رقمَ ربحٍ يُكتب باليد. تتوقع $row (الارتباط). --}}
@php
    $egPl = \App\Support\Engagements::pl($row);
    $egProjects = \App\Models\Project::whereNull('deleted_at')->where('engagement_id', $row->id)
        ->orderBy('name')->get(['id', 'name', 'status', 'progress']);
    $egContracts = \App\Models\Contract::whereNull('deleted_at')
        ->where(fn ($q) => $q->where('engagement_id', $row->id)->orWhere('id', $row->contract_id))
        ->orderByDesc('date_start')->orderBy('id')->get(['id', 'title', 'status', 'value', 'date_end']);
    $egCur = $row->currency ?: setting('app.currency', 'د.ك');
    $egRenew = $row->renewal ? now()->startOfDay()->diffInDays($row->renewal, false) : null;
    $egSeesMoney = ! hub_masked('engagements', 'revenue');
@endphp

@if ($egSeesMoney)
    <div class="card">
        <h3 class="cardtitle">💰 ربحية الارتباط
            @if ($egPl['margin'] !== null)
                <span class="bdg {{ $egPl['margin'] >= 25 ? 'ok' : ($egPl['margin'] >= 0 ? 'wn' : 'bad') }}">هامش {{ $egPl['margin'] }}٪</span>
            @endif
            @if ($egPl['mixed'])<span class="bdg wn" title="عملاتٌ مختلطة — الأرقام تقريبية">عملات مختلطة</span>@endif
        </h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">المفوتَر (مشاريعه)</div><b class="mono">{{ number_format($egPl['revenue'], 2) }} {{ $egCur }}</b>
                <div class="sub">حُصّل {{ number_format($egPl['collected'], 2) }}</div></div>
            <div><div class="sub">التكلفة (ساعات + بنية + مشتريات)</div><b class="mono">{{ number_format($egPl['cost'], 2) }} {{ $egCur }}</b>
                <div class="sub">{{ number_format($egPl['hours'], 1) }} ساعة فريق</div></div>
            <div><div class="sub">الربح</div>
                <b class="mono" style="color:var({{ $egPl['profit'] >= 0 ? '--ok' : '--bad' }}, inherit)">{{ number_format($egPl['profit'], 2) }} {{ $egCur }}</b></div>
            @if ($egPl['contract'] > 0)
                <div><div class="sub">القيمة التعاقدية</div><b class="mono">{{ number_format($egPl['contract'], 2) }} {{ $egCur }}</b>
                    @if ($egPl['revenue'] < $egPl['contract'])
                        <div class="sub">بقي {{ number_format($egPl['contract'] - $egPl['revenue'], 2) }} غير مفوتَر</div>
                    @endif</div>
            @endif
            @if ($egPl['extraUnbilled'] > 0)
                <div><div class="sub">مشترياتٌ للعميل لم تُفوتر</div>
                    <b class="mono" style="color:var(--wn, inherit)">{{ number_format($egPl['extraUnbilled'], 2) }}</b></div>
            @endif
        </div>
        <div class="sub" style="margin-top:8px">
            الأرقامُ من مصادرها: فواتيرُ مشاريعه المالية، وساعاتُ المهام بأجورها، والسيرفرات
            والأدوات والمشتريات — لا خانةَ ربحٍ تُملأ باليد. هذه أرقامٌ داخلية لا تصل عميلاً.
        </div>
    </div>
@endif

@if ($egRenew !== null && $row->status !== 'منتهٍ' && $row->status !== 'ملغى')
    <div class="card" @if ($egRenew <= 30) style="border-color:var(--wn)" @endif>
        <h3 class="cardtitle">🔄 التجديد</h3>
        <div class="sub">
            موعدُ التجديد <b class="mono">{{ $row->renewal->toDateString() }}</b> —
            @if ($egRenew < 0) <span class="bdg bad">فات بـ{{ abs((int) $egRenew) }} يوماً</span>
            @elseif ($egRenew <= 30) <span class="bdg wn">بعد {{ (int) $egRenew }} يوماً</span>
            @else بعد {{ (int) $egRenew }} يوماً @endif
            — والارتباطُ الذي يُجدَّد قبل شهرٍ يُفاوَض من قوة، لا من عتبة انقطاع الخدمة.
        </div>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">🗂️ مشاريع الارتباط <span class="bdg g">{{ $egProjects->count() }}</span>
        @if (hub_can(auth()->user(), 'projects', 'a'))
            <a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('m.create', 'projects') }}">＋ مشروع</a>
        @endif
    </h3>
    @if ($egProjects->isEmpty())
        <div class="sub" style="padding:6px 0 10px">لا مشاريع بعد — اربط المشروعَ من حقل «الارتباط» في نموذجه.</div>
    @else
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>المشروع</th><th>الحالة</th><th>الإنجاز المحسوب</th></tr></thead>
            <tbody>
            @foreach ($egProjects as $p)
                @php $egProg = hub_progress($p->id)['pct'] ?? null; @endphp
                <tr>
                    <td><a href="{{ route('m.show', ['projects', $p->id]) }}"><b>{{ $p->name }}</b></a></td>
                    <td><span class="bdg {{ hub_tone($p->status) }}">{{ $p->status ?: '—' }}</span></td>
                    <td>
                        @if ($egProg !== null)
                            <div class="pbar" style="max-width:160px"><span style="width:{{ (int) $egProg }}%"></span></div>
                            <span class="sub mono">{{ (int) $egProg }}٪</span>
                        @else — @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</div>

@if ($egContracts->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">📜 عقود الارتباط <span class="bdg g">{{ $egContracts->count() }}</span></h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>العقد</th><th>الحالة</th><th>القيمة</th><th>ينتهي</th></tr></thead>
            <tbody>
            @foreach ($egContracts as $c)
                <tr>
                    <td><a href="{{ route('m.show', ['contracts', $c->id]) }}">{{ \Illuminate\Support\Str::limit($c->title, 50) }}</a></td>
                    <td><span class="bdg {{ hub_tone($c->status) }}">{{ $c->status ?: '—' }}</span></td>
                    <td class="mono">{{ $c->value ? number_format((float) $c->value, 2) : '—' }}</td>
                    <td class="mono">{{ $c->date_end ? substr((string) $c->date_end, 0, 10) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

@if (trim((string) $row->client_note) !== '')
    <div class="card">
        <h3 class="cardtitle">🗣️ ما يُشارك مع العميل</h3>
        <div style="white-space:pre-wrap">{{ $row->client_note }}</div>
        <div class="sub" style="margin-top:6px">هذا الحقلُ وحدَه صالحٌ للمشاركة — الملاحظاتُ الداخلية في حقلها ولا تختلط به.</div>
    </div>
@endif
