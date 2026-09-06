@extends('layouts.app')
@section('title', 'نظرة القوى العاملة')
@section('content')
{{-- WP-7.2 · spec §46: ما أُنجز، ما تأخّر، الالتزام، اختلال التوزيع — أرقامُ تنفيذٍ
     لا مراقبةٌ شخصية: لا زيارات صفحات، ولا ترتيبَ موظفين بالنشاط، ولا درجاتٍ أمنية. --}}
@php
    /* (§49 · §25 — لا طريقَ مسدود) هذه الشاشةُ تُقرأ لحاملِ راية المراقبة، وأرقامُها
       أرقامُ منشأةٍ يحقّ له. أمّا **الوجهات** فلكلٍّ حارسُها: `activity.index` للمالك
       وحدَه، و`support` بـ`tickets:v`، و`workforce.team` بـ`hr:v`، وصفحاتُ الوحدات
       بمصفوفة القارئ. فالرقمُ يبقى ويسقط الرابطُ وحدَه لمن لا يفتحه — لا رقمَ
       يُحجب (ذلك كذبٌ)، ولا رابطٌ يَعِد ببابٍ يردّه ٤٠٣. */
    $wfU = auth()->user();
    $wfMod = fn (string $m, ...$args) => hub_can($wfU, $m, 'v') ? route(...$args) : null;
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الفريق</span><span aria-hidden="true">‹</span><b>نظرة القوى العاملة</b></nav>
        <h2>🧭 نظرة القوى العاملة</h2>
        <div class="sub">ما أُنجز وما تأخّر وأين اختلالُ التوزيع — أرقامُ تنفيذٍ من المهام والتذاكر والاعتمادات، لا مراقبةٌ شخصية</div>
    </div>
    <div class="crow" style="margin-top:0">
        <a class="btn ghost sm" href="{{ route('capacity') }}">📊 القدرات والاستغلال</a>
        @if (hub_can($wfU, 'hr', 'v'))<a class="btn ghost sm" href="{{ route('workforce.team') }}">👥 فريقي اليوم</a>@endif
    </div>
</div>

@include('partials.timerange', ['range' => $range])

@php
    $wfCmp = $x['completed'];
    $wfOt = $x['on_time'];
    $wfSla = $x['sla_breaches'];
    $wfRisk = $x['projects_at_risk'];
@endphp
@include('partials.cc.kpis', ['items' => [
    ['label' => 'نشطون اليوم', 'value' => $x['active_today'], 'tone' => 'g',
     'hint' => 'مستخدمون لهم نبضةُ جلسةٍ منذ منتصف الليل — لقطةُ اليوم لا النافذة',
     'url' => hub_is_owner() ? route('activity.index') : null],
    ['label' => 'أُنجز في النافذة', 'value' => (int) $wfCmp['cur'], 'tone' => 'ok',
     'sub' => $wfCmp['pct'] === null ? 'النافذة السابقة: ' . (int) ($wfCmp['prev'] ?? 0)
            : ($wfCmp['pct'] >= 0 ? '▲' : '▼') . ' ' . abs($wfCmp['pct']) . '٪ عن النافذة السابقة (' . (int) $wfCmp['prev'] . ')',
     'hint' => 'من ختم الإنجاز completed_at — تعديلٌ لاحقٌ على مهمةٍ منجزة لا يغيّر الرقم',
     'url' => $wfMod('tasks', 'm.index', 'tasks')],
    ['label' => 'متأخّرة الآن', 'value' => $x['overdue'], 'tone' => $x['overdue'] ? 'bad' : 'ok',
     'hint' => 'مهامٌ مفتوحةٌ فات موعدُها — لقطةُ اللحظة', 'url' => $wfMod('tasks', 'm.index', 'tasks')],
    ['label' => 'الالتزام بالموعد', 'value' => $wfOt['pct'] === null ? '—' : $wfOt['pct'] . '٪',
     'tone' => $wfOt['pct'] === null ? 'g' : ($wfOt['pct'] >= 80 ? 'ok' : ($wfOt['pct'] >= 50 ? 'wn' : 'bad')),
     'sub' => $wfOt['with_due'] ? $wfOt['on_time'] . ' في الموعد من ' . $wfOt['with_due'] . ' منجزةٍ لها موعد'
            : 'لا منجزاتٍ لها موعدٌ في النافذة',
     'hint' => 'يومُ الإنجاز (من completed_at) داخل يوم الموعد أو قبله'],
    ['label' => 'تذاكر مفتوحة', 'value' => $x['open_tickets'], 'tone' => 'g',
     'url' => $wfMod('tickets', 'support')],
    ['label' => 'خرق SLA في النافذة', 'value' => $wfSla['n'], 'tone' => $wfSla['n'] ? 'bad' : 'ok',
     'sub' => 'من ' . $wfSla['of'] . ' تذكرة في النافذة' . ($wfSla['capped'] ? ' (عيّنة بسقف ' . \App\Support\ExecutionStats::SLA_SAMPLE_CAP . ')' : ''),
     'url' => $wfMod('tickets', 'support')],
    ['label' => 'مشاريع في خطر', 'value' => $wfRisk['n'], 'tone' => $wfRisk['n'] ? 'bad' : 'ok',
     'sub' => 'صحةٌ دون ٥٥ من ' . $wfRisk['of'] . ' مشروعٍ مفتوح' . ($wfRisk['capped'] ? ' (بسقف ' . \App\Support\ExecutionStats::HEALTH_SAMPLE_CAP . ')' : ''),
     'url' => $wfMod('projects', 'm.index', 'projects')],
    ['label' => 'اعتمادات معلّقة', 'value' => $x['pending_approvals'], 'tone' => $x['pending_approvals'] ? 'wn' : 'ok',
     'url' => $wfMod('approvals', 'm.index', 'approvals')],
]])

<div class="card">
    <h3>📈 اتجاه الإنجاز <span class="sub">مهامٌ أُنجزت لكل يومٍ في النافذة — النافذةُ الأطول من ٤٠ يوماً تُجمع حزماً متساوية</span></h3>
    @include('partials.cc.trend', ['series' => $x['trend'], 'unit' => 'مهمة',
        'empty' => 'لا مهامَّ أُنجزت في هذه النافذة'])
</div>

<div class="card pad0" style="margin-top:12px">
    <div style="padding:14px 14px 0">
        <h3 style="margin:0">🏷️ لوح الأقسام <span class="sub">القسمُ من ملفّ الموظف (employees.dept) عبر ربط الحساب — لا من حقل القسم الحرّ على المهمة</span></h3>
    </div>
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            @include('partials.cc.th', ['col' => 'dept', 'label' => 'القسم', 'default' => 'open'])
            @include('partials.cc.th', ['col' => 'heads', 'label' => 'موظفون', 'default' => 'open'])
            @include('partials.cc.th', ['col' => 'open', 'label' => 'مهام مفتوحة', 'default' => 'open'])
            @include('partials.cc.th', ['col' => 'overdue', 'label' => 'متأخّرة', 'default' => 'open'])
            @include('partials.cc.th', ['col' => 'done', 'label' => 'أُنجز في النافذة', 'default' => 'open'])
            @include('partials.cc.th', ['col' => 'ontime', 'label' => 'الالتزام', 'default' => 'open'])
        </tr></thead>
        <tbody>
        @forelse ($x['departments'] as $wfD)
            <tr>
                <td><b>{{ $wfD['dept'] !== '' ? $wfD['dept'] : 'بلا قسم' }}</b></td>
                <td class="sub">{{ $wfD['heads'] }}</td>
                <td>{{ $wfD['open'] }}</td>
                <td class="{{ $wfD['overdue'] ? 'txt-bad' : 'sub' }}">{{ $wfD['overdue'] }}</td>
                <td>{{ $wfD['done'] }}</td>
                <td>@if ($wfD['on_time_pct'] === null)<span class="sub">—</span>@else
                    <span class="bdg {{ $wfD['on_time_pct'] >= 80 ? 'ok' : ($wfD['on_time_pct'] >= 50 ? 'wn' : 'bad') }}">{{ $wfD['on_time_pct'] }}٪</span>@endif</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 6, 'icon' => '🏷️',
                'text' => 'لا مهامَّ مرتبطةً بموظفين لهم حساباتٌ مربوطة — اربط الحسابات من الملفات الوظيفية ليظهر اللوح'])
        @endforelse
        </tbody>
    </table>
    </div>
</div>

@php $wfL = $x['load']; @endphp
<div class="card" style="margin-top:12px">
    <h3>⚖️ ميزان الحمل <span class="sub">من لوحة القدرات على النافذة نفسها ({{ $wfL['from'] }} ← {{ $wfL['to'] }}) — إشاراتُ اختلالٍ لا ترتيبُ جدارة</span></h3>
    <div class="cards">
        <div class="stat"><span class="ico">🔥</span><b class="{{ count($wfL['over']) ? 'txt-bad' : '' }}">{{ count($wfL['over']) }}</b><span>فوق الطاقة (حمل > ١٠٠٪)</span></div>
        <div class="stat"><span class="ico">🌤️</span><b>{{ count($wfL['idle']) }}</b><span>بلا تكليفٍ مفتوح</span></div>
        <div class="stat"><span class="ico">⏰</span><b class="{{ count($wfL['heavy_overdue']) ? 'txt-bad' : '' }}">{{ count($wfL['heavy_overdue']) }}</b><span>تأخّرٌ كثيف ({{ \App\Support\ExecutionStats::HEAVY_OVERDUE_MIN }}+ مهامَّ فائتة)</span></div>
        <div class="stat"><span class="ico">📏</span><b>{{ $wfL['spread'] ? $wfL['spread']['gap'] . '٪' : '—' }}</b><span>تشتّت التوزيع (أعلى حملٍ − أدناه)</span></div>
    </div>

    @if (count($wfL['over']) || count($wfL['heavy_overdue']) || count($wfL['idle']))
        <div class="crow" style="flex-wrap:wrap;gap:16px;align-items:flex-start">
            @if (count($wfL['over']))
                <div>
                    <div class="sub"><b>فوق طاقتهم:</b></div>
                    @foreach (array_slice($wfL['over'], 0, 5) as $wfO)
                        <div class="sub">🔥 @if (hub_can($wfU, 'hr', 'v'))<a href="{{ route('m.show', ['hr', $wfO['id']]) }}">{{ $wfO['name'] }}</a>@else{{ $wfO['name'] }}@endif — حمله {{ $wfO['load'] }}٪</div>
                    @endforeach
                </div>
            @endif
            @if (count($wfL['heavy_overdue']))
                <div>
                    <div class="sub"><b>تأخّرٌ كثيف:</b></div>
                    @foreach (array_slice($wfL['heavy_overdue'], 0, 5) as $wfH)
                        <div class="sub">⏰ {{ $wfH['name'] }} — {{ $wfH['n'] }} مهامَّ فائتةُ الموعد</div>
                    @endforeach
                </div>
            @endif
            @if (count($wfL['idle']))
                <div>
                    <div class="sub"><b>بلا تكليفٍ مفتوح:</b></div>
                    @foreach (array_slice($wfL['idle'], 0, 5) as $wfI)
                        <div class="sub">🌤️ @if (hub_can($wfU, 'hr', 'v'))<a href="{{ route('m.show', ['hr', $wfI['id']]) }}">{{ $wfI['name'] }}</a>@else{{ $wfI['name'] }}@endif</div>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        @include('partials.empty', ['icon' => '⚖️', 'text' => 'لا اختلالَ ظاهرٌ في التوزيع على هذه النافذة'])
    @endif
    @if ($wfL['unlinked'])
        <div class="sub" style="margin-top:8px">⚠️ {{ $wfL['unlinked'] }} موظفاً بلا حساب مستخدمٍ مربوط — لا تُحتسب مهامُهم في الميزان. اربطهم من ملفاتهم الوظيفية.</div>
    @endif
</div>

{{-- ── Control Plane: Phase 7 (WP-7.4) — الاختناقات ── --}}
@php
    $bnW = $bn['waiting'];
    $bnS = $bn['stalled'];
    $bnR = $bn['reopened'];
    $bnSig = $bn['signals'];
    // ساعاتٌ للقراءة: دون يومين تُعرض ساعاتٍ، وفوقهما أياماً — و«—» حين لا قياس
    $bnH = fn ($h) => $h === null ? '—'
        : ($h >= 48 ? number_format($h / 24, 1) . ' يوماً' : number_format($h, 1) . ' ساعة');
    $bnSigN = count($bnSig['proj.stalled']) + count($bnSig['proj.blockers']) + count($bnSig['sla.breach']);
@endphp
<div class="card" style="margin-top:12px">
    <h3>🚧 أين الاختناق؟ <span class="sub">مراحلُ انتظارٍ ومكوثُ حالاتٍ ومعوّقاتٌ بلَّغها الفريقُ بنفسِه — من السجلات القائمة، لا مراقبةٌ شخصية</span></h3>

    <div class="cards">
        <div class="stat"><span class="ico">🗳️</span><b class="{{ $bnW['approvals']['pending'] ? 'txt-bad' : '' }}">{{ $bnW['approvals']['pending'] }}</b>
            <span>اعتمادات تنتظر الحسم{{ $bnW['approvals']['oldest_days'] !== null ? ' — أقدمُها منذ ' . $bnW['approvals']['oldest_days'] . ' يوماً' : '' }}</span></div>
        <div class="stat"><span class="ico">⏱️</span><b>{{ $bnH($bnW['approvals']['avg_h']) }}</b>
            <span>متوسط انتظار الاعتماد ({{ $bnW['approvals']['decided_n'] }} حُسم في النافذة{{ $bnW['approvals']['capped'] ? ' — عيّنة بسقف ' . \App\Support\ExecutionStats::WAIT_SAMPLE_CAP : '' }})</span></div>
        <div class="stat"><span class="ico">📮</span><b>{{ $bnW['tickets_waiting']['n'] }}</b>
            <span>تذاكر «بانتظار العميل»{{ $bnW['tickets_waiting']['oldest_days'] !== null ? ' — أقدمُها بلا تحديث منذ ' . $bnW['tickets_waiting']['oldest_days'] . ' يوماً' : '' }}</span></div>
        <div class="stat"><span class="ico">⏸️</span><b>{{ $bnW['tasks_paused']['n'] }}</b>
            <span>مهام «متوقفة»{{ $bnW['tasks_paused']['oldest_days'] !== null ? ' — أقدمُها بلا تحديث منذ ' . $bnW['tasks_paused']['oldest_days'] . ' يوماً' : '' }}</span></div>
        <div class="stat"><span class="ico">🕸️</span><b class="{{ $bnS['n'] ? 'txt-bad' : '' }}">{{ $bnS['n'] }}</b>
            <span>مهام راكدة (مفتوحة بلا مساسٍ منذ {{ $bnS['threshold_days'] }}+ أيام)</span></div>
        <div class="stat"><span class="ico">🔁</span><b class="{{ $bnR['n'] ? 'txt-bad' : '' }}">{{ $bnR['total'] }}</b>
            <span>مرات إعادة فتح تذاكر (على {{ $bnR['n'] }} تذكرة{{ $bnR['capped'] ? ' — عيّنة بسقف ' . \App\Support\ExecutionStats::REOPEN_SAMPLE_CAP : '' }})</span></div>
    </div>

    <div class="crow" style="flex-wrap:wrap;gap:16px;align-items:flex-start;margin-top:10px">
        @if (count($bnS['rows']))
            <div>
                <div class="sub"><b>الأقدمُ ركوداً:</b></div>
                @foreach ($bnS['rows'] as $bnT)
                    <div class="sub">🕸️ @if (hub_can($wfU, 'tasks', 'v'))<a href="{{ route('m.show', ['tasks', $bnT['id']]) }}">{{ $bnT['title'] }}</a>@else{{ $bnT['title'] }}@endif
                        — {{ $bnT['days'] }} يوماً بلا مساس{{ $bnT['assignee'] ? ' (' . $bnT['assignee'] . ')' : '' }}</div>
                @endforeach
            </div>
        @endif
        @if (count($bnR['rows']))
            <div>
                <div class="sub"><b>الأكثرُ ارتداداً:</b></div>
                @foreach ($bnR['rows'] as $bnRt)
                    <div class="sub">🔁 @if (hub_can($wfU, 'tickets', 'v'))<a href="{{ route('m.show', ['tickets', $bnRt['id']]) }}">{{ $bnRt['subject'] }}</a>@else{{ $bnRt['subject'] }}@endif — أُعيد فتحُها {{ $bnRt['n'] }} مرة</div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<div class="card pad0" style="margin-top:12px">
    <div style="padding:14px 14px 0">
        <h3 style="margin:0">⏳ مكوث الحالات <span class="sub">من قيود التدقيق في النافذة ({{ $bn['dwell']['sample'] }} قيدَ تغييرِ حالة{{ $bn['dwell']['capped'] ? ' — عيّنة بسقف ' . \App\Support\ExecutionStats::DWELL_SAMPLE_CAP . ' لكل وحدة' : '' }}) — الحالةُ المنتهية لا يُعدّ لها مكوثٌ مفتوح</span></h3>
    </div>
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr><th>الوحدة</th><th>الحالة</th><th>سجلّات</th><th>متوسط المكوث</th><th>أطول مكوث</th></tr></thead>
        <tbody>
        @forelse (array_slice($bn['dwell']['rows'], 0, 10) as $bnD)
            <tr>
                <td class="sub">{{ ['tasks' => 'المهام', 'tickets' => 'التذاكر'][$bnD['module']] ?? $bnD['module'] }}</td>
                <td><b>{{ $bnD['status'] }}</b>@if ($bnD['open']) <span class="sub">({{ $bnD['open'] }} فيها الآن)</span>@endif</td>
                <td>{{ $bnD['n'] }}</td>
                <td>{{ $bnH($bnD['avg_h']) }}</td>
                <td class="sub">{{ $bnH($bnD['max_h']) }}</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 5, 'icon' => '⏳',
                'text' => 'لا تغييراتِ حالةٍ مسجَّلةً في هذه النافذة — المكوثُ يُقاس من قيود التدقيق حين تقع'])
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>🚧 أكثر المعوّقات تكراراً <span class="sub">ممّا كتبه الفريقُ نفسُه: بندُ «ما المشكلات؟» في تقارير العمل وسببُ التأخير على المهام — تجميعٌ بالنصّ لا استنتاجٌ يدّعي ما لم يُكتب</span></h3>
    @if (count($bn['blockers']['rows']))
        @foreach ($bn['blockers']['rows'] as $bnB)
            <div class="sub" style="margin:4px 0">
                <span class="bdg {{ $bnB['n'] >= 3 ? 'bad' : ($bnB['n'] >= 2 ? 'wn' : 'g') }}">×{{ $bnB['n'] }}</span>
                {{ $bnB['text'] }} <span class="sub">— {{ $bnB['source'] }}</span>
            </div>
        @endforeach
        @if ($bn['blockers']['total'] > count($bn['blockers']['rows']))
            <div class="sub" style="margin-top:6px">و{{ $bn['blockers']['total'] - count($bn['blockers']['rows']) }} معوّقاً آخر أقلَّ تكراراً في النافذة.</div>
        @endif
    @else
        @include('partials.empty', ['icon' => '🚧', 'text' => 'لا معوّقاتٍ مدوَّنةً في هذه النافذة — تظهر هنا حين يذكرها الفريق في تقارير العمل أو أسباب التأخير'])
    @endif
</div>

<div class="card" style="margin-top:12px">
    <h3>📡 إشارات مركز الفعل <span class="sub">تُقرأ من مركز الفعل نفسِه لا تُحسب مرّتين — ما تراه هنا هو ما يراه هناك بالمفتاح الواحد</span></h3>
    @if ($bnSigN)
        <div class="crow" style="flex-wrap:wrap;gap:16px;align-items:flex-start">
            @foreach ([['proj.stalled', '🕸️', 'مشاريع راكدة'], ['proj.blockers', '🚧', 'حواجب مبلَّغة'], ['sla.breach', '⏱️', 'خرق SLA']] as [$bnK, $bnI, $bnL])
                @if (count($bnSig[$bnK]))
                    <div>
                        <div class="sub"><b>{{ $bnI }} {{ $bnL }} ({{ count($bnSig[$bnK]) }}):</b></div>
                        @foreach (array_slice($bnSig[$bnK], 0, 5) as $bnE)
                            <div class="sub"><span class="bdg {{ $bnE['sev'] === 'حرج' ? 'bad' : 'wn' }}">{{ $bnE['sev'] }}</span>
                                @if ($bnE['url'])<a href="{{ $bnE['url'] }}">{{ $bnE['title'] }}</a>@else{{ $bnE['title'] }}@endif</div>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @else
        @include('partials.empty', ['icon' => '📡', 'text' => 'لا إشاراتِ ركودٍ أو حواجبَ أو خرقِ SLA حيّةً الآن في مركز الفعل'])
    @endif
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف تقرأ هذه الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>أُنجز</b> و<b>الالتزام</b> من ختم الإنجاز (<span class="mono ltr">completed_at</span>) الذي يُسجَّل لحظةَ دخول المهمة حالةَ الإنجاز — فتعديلُ مهمةٍ منجزةٍ لاحقاً لا يقلب التاريخ.<br>
        <b>المفتوح/المنتهي</b> بقاموس الحالات الموحّد (<span class="mono ltr">hub_open_scope</span>) — الرقمُ هنا هو نفسُه في كل الشاشات.<br>
        <b>خرق SLA</b> بسياسة الدعم نفسِها (أولوية ← مهلة استجابةٍ وحلّ)، على تذاكر النافذة بسقفِ عيّنةٍ معلَن.<br>
        <b>ميزان الحمل</b> من لوحة القدرات: الحملُ = الساعاتُ المقدَّرة للمهام المفتوحة ÷ المتاح، والمتاحُ يخصم العطلَ والإجازات المعتمدة.<br>
        <b>مكوث الحالات</b> من قيود التدقيق داخل النافذة: كلُّ قيدٍ يحمل حالةً هو لحظةُ دخولها، والمكوثُ ما بين قيدين — والفاصلُ الأخير يُقاس حتى الآن إلا في حالةٍ منتهية. والعيّنةُ مسقوفةٌ <b>لكل وحدةٍ على حدة</b> كي لا تبتلع وحدةٌ نشطةٌ السقفَ فتختفي أختُها من الجدول بلا كلمة.<br>
        <b>المعوّقات</b> نصوصُ الفريق حرفياً (تقاريرُ العمل وأسبابُ التأخير — الأسبابُ بتاريخ آخرِ تحديثٍ لأن العمود بلا ختمِ وقتٍ خاص)، و<b>إعادةُ الفتح</b> عدّادٌ تراكميّ يُختم لحظةَ الارتداد.<br>
        <b>إشارات مركز الفعل</b> (مشاريعُ راكدة، حواجبُ، خرقُ SLA) تُقرأ من محرّكها القائم كما هي — فلا يقول مركزُ الفعل شيئاً وهذه الشاشةُ شيئاً آخر.<br>
        🚫 لا تدخل زياراتُ الصفحات ولا أيُّ درجةٍ أمنية في هذه الأرقام، ولا يُرتَّب موظفٌ بنشاطه الشخصي — هذه شاشةُ تنفيذٍ لا مراقبة.
    </div>
</div>
@endsection
