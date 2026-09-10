{{-- مركزُ قيادةِ المشروع (Work OS · الطور D · WP-D.2 · §11) — البطاقاتُ المسطّحةُ
     صارت تبويباتٍ فوق القرّاء القائمين: النظرة · التسليم · الأساس التجاري · الغرف ·
     المالية · النشاط. **تجميعٌ لا إعادةُ حساب**: `hub_project_health`/`hub_project_pl`
     والغرفتان (D.1) وخطُّ الأساس (`meta.baseline`) تُقرأ كما هي، ولا محرّكَ ثانٍ.

     الحجبُ الصلب (§9): العميلُ (`account_type=client`) لا يبلغ إلا تبويبَ «النظرة»
     العميليَّ الآمن — لا تكلفةَ ولا هامشَ ولا cost_delta ولا بنيةَ (url/staging/git)
     ولا غرفةً داخلية. الحجبُ فوقَ `hub_field_mode` بـ`hub_is_client` لأن العميلَ عديمَ
     الدور يعيد field-mode له '' (لا حجب) فلا يكفي وحدَه. والداخليُّ المحدودُ يمرّ
     بـfield-mode: دورٌ يُخفي التكلفةَ/الميزانية لا يرى تبويبَ المالية ولا أرقامَه. --}}
@php
    use App\Http\Controllers\Web\CommentController;
    use App\Http\Controllers\Web\ConversationController;
    use App\Models\Conversation;

    $u = auth()->user();
    $isCli = hub_is_client($u);

    // رؤيةُ حقلٍ لهذا القارئ: العميلُ محجوبٌ صلباً، والداخليُّ يمرّ بـfield-mode.
    $pcSee = fn (string $key) => ! $isCli && hub_field_mode($u, 'projects', $key) !== 'hide';
    // الاقتصادُ الماليّ مرئيٌّ فقط حين يُرى عمودا حَملِه معاً (التكلفةُ والميزانية) —
    // منهما يُشتقُّ الهامشُ وcost_delta، فحجبُ أحدِهما يُسقط اللوحةَ كلَّها.
    $pcFin = $pcSee('cost') && $pcSee('budget');

    // ── القرّاءُ القائمون، كلٌّ مرّةً واحدة (مخبّأون فلا يتكرّر عبر التبويبات) ──
    $pcHealth = $isCli ? [] : hub_project_health($row->id);
    $pcPl = $pcFin ? hub_project_pl($row->id) : null;
    $pcNext = $isCli ? [] : \App\Support\NextAction::for('projects', $row);
    $pcBaseline = ((array) $row->meta)['baseline'] ?? null;
    $pcExternal = $row->isExternal();

    $pcClient = $row->client_id ? \App\Models\Client::whereNull('deleted_at')->find($row->client_id) : null;
    $pcEng = $row->engagement_id ? \App\Models\Engagement::whereNull('deleted_at')->find($row->engagement_id) : null;

    // نشاطُ آخرِ ٧ أيام — داخليٌّ (عوائقُ الفريق ليست للعميل)
    $pcLogs = $isCli ? collect() : \Illuminate\Support\Facades\DB::table('work_updates')->whereNull('deleted_at')
        ->where('project_id', $row->id)
        ->where('work_date', '>=', now()->subDays(7)->toDateString())
        ->orderByDesc('work_date')->orderByDesc('id')
        ->get(['id', 'created_by', 'task_id', 'done', 'hours', 'progress', 'problems', 'next', 'work_date', 'review_status', 'submitted_at']);
    $pcPeople = $pcLogs->isEmpty() ? [] : hub_ref_labels('users', $pcLogs->pluck('created_by')->filter()->unique()->values()->all());
    $pcBlockers = $pcLogs->pluck('problems')->filter(fn ($p) => trim((string) $p) !== '');
    // (§20) الخطُّ الزمنيّ للتقارير: يوم → موظّف → بنود — من نفسِ البنود، لا محرّكَ ثانٍ
    $pcTasks = $pcLogs->isEmpty() ? [] : hub_ref_labels('tasks', $pcLogs->pluck('task_id')->filter()->unique()->values()->all());
    $pcByDay = $pcLogs->groupBy(fn ($l) => substr((string) $l->work_date, 0, 10));

    // ── الحقولُ الداخليّةُ التي تُحجب عن العميل في بطاقةِ «البيانات» (اقتصادٌ وبنية) ──
    $pcCliHide = ['budget', 'cost', 'revExp', 'url', 'staging', 'git', 'prod', 'notes'];

    // ── التطوّرُ التجاريّ (أوامرُ التغيير) من لقطةِ الأساس ──
    $pcCOs = $pcBaseline['change_orders'] ?? [];
    $pcOrig = (float) ($pcBaseline['amount'] ?? 0);
    $pcCOsum = collect($pcCOs)->sum(fn ($c) => (float) ($c['value_delta'] ?? 0));
    $pcCur = round($pcOrig + $pcCOsum, 3);
    $pcDaysSum = collect($pcCOs)->sum(fn ($c) => (int) ($c['timeline_days'] ?? 0));

    // ── مجموعةُ التبويبات — للعميل «النظرة» وحدها ──
    $pcTabs = $isCli
        ? [['overview', '🗂️ النظرة']]
        : array_values(array_filter([
            ['overview', '🗂️ النظرة'],
            ['delivery', '🚦 التسليم'],
            ['assets', '🖥️ الأصول'],
            ['baseline', '📐 الأساس التجاري'],
            $pcExternal ? ['rooms', '💬 الغرف'] : null,
            $pcFin ? ['finance', '💰 المالية'] : null,
            ['activity', '📅 النشاط'],
        ]));
    $pcFirst = $pcTabs[0][0];

    // منسّقُ رقمٍ موجزٌ بعملة — يُستعمل في المالية والأساس
    $pcMoney = fn ($v, $cur = '') => number_format((float) $v, 3) . ($cur ? ' ' . $cur : '');
@endphp

{{-- قشرةُ التبويبات: كلُّ اللوحاتِ في DOM (القياسُ الرقابيُّ يقرأ المصدرَ لا العرض)،
     وسكربتٌ صغيرٌ يُظهر النشطةَ. بلا JS تظهر «النظرة» وحدَها — تدرّجٌ مقبول. --}}
<style>
    .cc-proj .ccpanel { display: none }
    .cc-proj .ccpanel.on { display: block }
    .cc-proj .tabs { margin-bottom: 4px }
</style>
<div class="cc-proj" data-cc-proj>
    <nav class="tabs" aria-label="أقسام المشروع">
        @foreach ($pcTabs as [$pcK, $pcLbl])
            <a class="tab {{ $pcK === $pcFirst ? 'on' : '' }}" href="#ccp-{{ $pcK }}"
               data-cctab="{{ $pcK }}" @if ($pcK === $pcFirst) aria-current="page" @endif>{{ $pcLbl }}</a>
        @endforeach
    </nav>

    {{-- ═══════════ ① النظرة ═══════════ --}}
    <section id="ccp-overview" data-ccpanel="overview" class="ccpanel {{ $pcFirst === 'overview' ? 'on' : '' }}">
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
                <div class="sub" style="margin-top:6px">حالةُ التسليمِ وتقدّمُه — والأرقامُ والملاحظاتُ الداخلية تبقى داخلية.</div>
            </div>
        @endif

        {{-- مؤشّراتٌ آمنة: صحةُ التسليم (داخليّ) · الإنجاز · الحالة · القيمة الحالية (مالية) --}}
        @include('partials.cc.kpis', ['items' => array_values(array_filter([
            ! $isCli ? ['label' => 'صحةُ التسليم', 'value' => $pcHealth['score'] ?? '—',
                        'tone' => $pcHealth['tone'] ?? 'g', 'sub' => $pcHealth['label'] ?? ''] : null,
            ['label' => 'الإنجاز', 'value' => $row->progress !== null ? number_format((float) $row->progress, 0) . '٪' : '—'],
            ['label' => 'الحالة', 'value' => $row->status ?: '—'],
            ($pcFin && $pcBaseline) ? ['label' => 'القيمة الحالية TCV',
                'value' => $pcMoney($pcCur, $pcBaseline['currency'] ?? ''), 'tone' => 'g'] : null,
        ]))])

        @if (! empty($pcNext))
            {{-- الفعلُ الأفضلُ التالي (محرّك NextAction) — داخليّ --}}
            <div class="card">
                <h3 class="cardtitle">🎯 الخطوة التالية</h3>
                <div class="crow" style="flex-wrap:wrap;gap:8px">
                    @foreach ($pcNext as $step)
                        <a class="chip" href="{{ $step['url'] }}" title="{{ $step['why'] }}">{{ $step['primary'] ? '⭐ ' : '' }}{{ $step['label'] }}</a>
                    @endforeach
                </div>
                <div class="sub" style="margin-top:6px">{{ $pcNext[0]['why'] }}</div>
            </div>
        @endif

        {{-- بطاقةُ البيانات: الحقولُ المعرَّفة — يحرسُها field-mode للداخليّ، ويُحجب عن
             العميلِ الاقتصادُ والبنية (لأن field-mode لا يحجب عن عديمِ الدور). --}}
        <div class="card" style="--mh:{{ hub_mod_look($module)['color'] }}">
            <h3 class="cardtitle">📋 البيانات</h3>
            <dl class="detail">
                @foreach ($def['fields'] as $f)
                    @continue(hub_field_mode($u, $module, $f['key']) === 'hide')
                    @continue($isCli && in_array($f['key'], $pcCliHide, true))
                    <div class="drow">
                        <dt>{{ $f['label'] }}</dt>
                        <dd>@include('partials._display', ['f' => $f, 'row' => $row, 'labels' => $labels, 'ctx' => 'show'])</dd>
                    </div>
                @endforeach
                @foreach (hub_custom_fields($module) as $cf)
                    @php $cv = data_get($row->custom, $cf['key']); @endphp
                    <div class="drow">
                        <dt>{{ $cf['label'] }} <span class="sub">· مخصص</span></dt>
                        <dd>
                            @if ($cv === null || $cv === '')—
                            @elseif (($cf['type'] ?? '') === 'bool'){{ $cv ? '✓ نعم' : 'لا' }}
                            @elseif (($cf['type'] ?? '') === 'ref'){{ hub_ref_labels($cf['ref'], [$cv])[$cv] ?? $cv }}
                            @else{{ $cv }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @unless ($isCli)
            @include('partials.dossier')
        @endunless
    </section>

    @unless ($isCli)
        {{-- ═══════════ ② التسليم (يقرأ hub_project_health + إشارةَ D.3) ═══════════ --}}
        <section id="ccp-delivery" data-ccpanel="delivery" class="ccpanel {{ $pcFirst === 'delivery' ? 'on' : '' }}">
            <div class="card">
                <h3 class="cardtitle">🚦 صحةُ التسليم
                    <span class="bdg {{ $pcHealth['tone'] ?? 'g' }}">{{ $pcHealth['score'] ?? '—' }} · {{ $pcHealth['label'] ?? '—' }}</span>
                    @if ($row->blocked || $row->hold_reason)
                        <span class="bdg bad">⛔ محجوبٌ داخلياً</span>
                    @endif
                </h3>
                @if ($row->hold_reason)
                    <div class="sub" style="color:var(--bad, inherit);margin-bottom:6px">سببُ الحجب: {{ \Illuminate\Support\Str::limit((string) $row->hold_reason, 160) }}</div>
                @endif
                {{-- عواملُ الصحة الستّة — كلٌّ من محرّكه بوزنه المعلن. عاملُ الميزانية
                     يُطوى عمّن لا يرى الميزانية (احترامُ field-mode ولو بنسبةٍ مشتقّة). --}}
                <div class="tblwrap"><table>
                    <thead><tr><th>العامل</th><th>الوزن</th><th>الدرجة</th><th>الملاحظة</th></tr></thead>
                    <tbody>
                    @foreach ($pcHealth['factors'] ?? [] as $fac)
                        @continue($fac['k'] === 'الالتزام بالميزانية' && ! $pcSee('budget'))
                        <tr>
                            <td>{{ $fac['k'] }}</td>
                            <td class="mono sub">{{ $fac['w'] }}٪</td>
                            <td><span class="bdg {{ $fac['s'] >= 80 ? 'ok' : ($fac['s'] >= 55 ? 'wn' : 'bad') }}">{{ $fac['s'] }}</span></td>
                            <td class="sub">{{ $fac['note'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div class="crow" style="margin-top:8px">
                    <a class="btn ghost xs" href="{{ route('delivery.psa') }}">🛰️ لوحة التسليم التشغيليّة</a>
                </div>
            </div>
            {{-- أبناءُ التسليم (مهامّ/تذاكر/مخاطر) — من $children المحسوبِ مرّةً في المتحكّم --}}
            @include('partials.record_list', ['children' => $children, 'ownerId' => $row->id])
        </section>

        {{-- ═══════════ الأصول (Project 360 · §19-23 · تخصيصٌ لا عهدة) ═══════════ --}}
        <section id="ccp-assets" data-ccpanel="assets" class="ccpanel {{ $pcFirst === 'assets' ? 'on' : '' }}">
            @include('partials.project_assets', ['row' => $row])
        </section>

        {{-- ═══════════ ③ الأساس التجاريّ (ChangeOrder وحدَه يطوّره) ═══════════ --}}
        <section id="ccp-baseline" data-ccpanel="baseline" class="ccpanel {{ $pcFirst === 'baseline' ? 'on' : '' }}">
            @if ($pcBaseline)
                <div class="card">
                    <h3 class="cardtitle">📐 خطُّ الأساس التجاريّ <span class="bdg g">من عرضٍ مقبول</span></h3>
                    <div class="crow">
                        @if (! empty($pcBaseline['quote_id']))
                            <a class="chip" href="{{ route('m.show', ['quotes', $pcBaseline['quote_id']]) }}">🧾 {{ $pcBaseline['quote_no'] ?? 'العرض' }}</a>
                        @endif
                        @if ($pcFin)
                            <span class="chip">القيمة المعتمدة: <b class="mono">{{ $pcMoney($pcBaseline['amount'] ?? 0, $pcBaseline['currency'] ?? '') }}</b></span>
                        @endif
                        @if (! empty($pcBaseline['accepted_at']))<span class="sub">قُبل {{ \Illuminate\Support\Str::limit(str_replace('T', ' ', $pcBaseline['accepted_at']), 16, '') }}</span>@endif
                    </div>
                    <div class="sub" style="margin-top:6px">النطاقُ والمبلغُ الأصليّان — أيُّ تغييرٍ لاحقٍ يُدار بإدارة التغيير لا بتعديل العرض المقبول.</div>
                </div>
                @if (! empty($pcCOs))
                    <div class="card">
                        <h3 class="cardtitle">📈 التطوّرُ التجاريّ للمشروع <span class="bdg g">{{ count($pcCOs) }} أمر تغيير</span></h3>
                        @if ($pcFin)
                            <div style="display:flex;gap:18px;flex-wrap:wrap">
                                <div><div class="sub">القيمة الأصلية</div><b class="mono">{{ $pcMoney($pcOrig, $pcBaseline['currency'] ?? '') }}</b></div>
                                <div><div class="sub">أوامرُ التغيير المطبَّقة</div><b class="mono {{ $pcCOsum >= 0 ? '' : 'txt-bad' }}">{{ $pcCOsum >= 0 ? '+' : '' }}{{ number_format($pcCOsum, 3) }}</b></div>
                                <div><div class="sub">القيمة الحالية TCV</div><b class="mono">{{ $pcMoney($pcCur, $pcBaseline['currency'] ?? '') }}</b></div>
                                @if ($pcDaysSum)<div><div class="sub">أثرُ الجدول</div><b class="mono">{{ $pcDaysSum > 0 ? '+' : '' }}{{ $pcDaysSum }} يوماً</b></div>@endif
                            </div>
                        @endif
                        <div class="tblwrap" style="margin-top:8px"><table>
                            <thead><tr><th>أمر التغيير</th>@if ($pcFin)<th>القيمة</th>@endif<th>الجدول</th><th>طُبّق</th></tr></thead>
                            <tbody>
                            @foreach ($pcCOs as $c)
                                <tr>
                                    <td>@if (! empty($c['co_id']))<a class="chip" href="{{ route('m.show', ['changeorders', $c['co_id']]) }}">📋 {{ $c['co_no'] ?? 'أمر' }}</a>@else {{ $c['co_no'] ?? '—' }} @endif</td>
                                    @if ($pcFin)<td class="mono">{{ (float) ($c['value_delta'] ?? 0) >= 0 ? '+' : '' }}{{ number_format((float) ($c['value_delta'] ?? 0), 3) }}</td>@endif
                                    <td class="mono">{{ (int) ($c['timeline_days'] ?? 0) ? ((int) $c['timeline_days'] . ' يوماً') : '—' }}</td>
                                    <td class="sub">{{ ! empty($c['applied_at']) ? \Illuminate\Support\Str::limit(str_replace('T', ' ', $c['applied_at']), 16, '') : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    </div>
                @endif
            @else
                @include('partials.empty', ['text' => 'لا خطَّ أساسٍ تجاريّ — هذا المشروعُ لم يُولَد من عرضٍ مقبول', 'icon' => '📐'])
            @endif
        </section>

        {{-- ═══════════ ④ الغرف (D.1 · الغرفتان الفيزيائيّتان) ═══════════ --}}
        @if ($pcExternal)
            <section id="ccp-rooms" data-ccpanel="rooms" class="ccpanel {{ $pcFirst === 'rooms' ? 'on' : '' }}">
                @php
                    $pcRooms = ConversationController::ensureProjectRooms($row);
                    $pcRoomUsers = CommentController::userNames();
                    $pcUid = (string) auth()->id();
                    $pcRoomList = [
                        ['internal', '🔒 الغرفة الداخلية',
                            'نقاشُ الفريق — لا يراه العميل (الأرقامُ والبنيةُ الداخليّة تبقى هنا)', 'var(--mut,#64748b)'],
                        ['client', '🤝 غرفة العميل', 'ما يُشارَك مع العميل ويراه في بوابته', 'var(--ok,#16a34a)'],
                    ];
                @endphp
                <div class="card">
                    <h3 class="cardtitle">💬 غرفتا المشروع
                        <span class="bdg">🔒 داخلية</span><span class="bdg g">🤝 عميل</span>
                    </h3>
                    <div class="sub">غرفتان منفصلتان فيزيائياً: نقاشُ الفريق الداخليّ لا يبلغ العميلَ أبداً،
                        وما يُكتب في غرفة العميل يراه في بوابته. الفصلُ حاويتان لا علامةٌ على الرسالة.</div>
                </div>
                @foreach ($pcRoomList as [$pcAud, $pcTitle, $pcWhy, $pcColor])
                    @php
                        $pcRoom = $pcRooms[$pcAud];
                        $pcMsgs = $pcRoom->rootMessages();   // §56: نافذةٌ محدودةٌ لأحدث رسائل الغرفة
                        $pcRole = Conversation::roleOf((string) $pcRoom->id, $pcUid);
                    @endphp
                    <div class="sub" style="margin:12px 0 -6px;font-weight:600;border-inline-start:3px solid {{ $pcColor }};padding-inline-start:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span>{{ $pcTitle }} <span class="sub" style="font-weight:400">— {{ $pcWhy }}</span></span>
                        {{-- §37 افتح الغرفةَ في مركزِ التواصلِ الموحّد (لا صفحاتٍ قديمة) --}}
                        <a class="btn ghost xs" href="{{ route('collab.center', ['c' => $pcRoom->id]) }}" style="margin-inline-start:auto">💬 افتح في مركز التواصل ⤢</a>
                    </div>
                    @include('partials.comments', [
                        'cModule'         => 'channel',
                        'cRecordId'       => (string) $pcRoom->id,
                        'cConversationId' => (string) $pcRoom->id,
                        'comments'        => $pcMsgs,
                        'users'           => $pcRoomUsers,
                        'cCanPost'        => Conversation::roleCanPost($pcRole),
                        'cChannelMod'     => Conversation::roleCanManage($pcRole),
                    ])
                @endforeach
            </section>
        @endif

        {{-- ═══════════ ⑤ المالية (يقرأ hub_project_pl — للداخليِّ المخوَّل فقط) ═══════════ --}}
        @if ($pcFin && $pcPl)
            <section id="ccp-finance" data-ccpanel="finance" class="ccpanel {{ $pcFirst === 'finance' ? 'on' : '' }}">
                @php $pcCurLbl = $pcPl['currency'] ?? ''; @endphp
                <div class="card">
                    <h3 class="cardtitle">💰 ربحيةُ المشروع
                        @if (! empty($pcPl['mixed']))<span class="bdg wn" title="فواتيرُ المشروع بعملتين — لا محرّكَ تحويل">عملاتٌ مختلطة</span>@endif
                    </h3>
                    @include('partials.cc.kpis', ['items' => [
                        ['label' => 'الإيراد المفوتر', 'value' => $pcMoney($pcPl['revenue']['invoiced'] ?? 0, $pcCurLbl),
                         'sub' => 'محصّل: ' . $pcMoney($pcPl['revenue']['collected'] ?? 0)],
                        ['label' => 'التكلفة الفعلية', 'value' => $pcMoney($pcPl['cost']['total'] ?? 0, $pcCurLbl),
                         'sub' => 'ساعات ' . $pcMoney($pcPl['cost']['hours'] ?? 0) . ' · خوادم ' . $pcMoney($pcPl['cost']['servers'] ?? 0)],
                        ['label' => 'الربح', 'value' => $pcMoney($pcPl['profit'] ?? 0, $pcCurLbl),
                         'tone' => ($pcPl['profit'] ?? 0) >= 0 ? 'ok' : 'bad'],
                        ['label' => 'الهامش', 'value' => $pcPl['margin'] !== null ? $pcPl['margin'] . '٪' : '—',
                         'tone' => ($pcPl['margin'] ?? 0) >= 0 ? 'ok' : 'bad'],
                    ]])
                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:8px">
                        <div><div class="sub">الميزانية المعتمدة</div><b class="mono">{{ $pcPl['budget'] !== null ? $pcMoney($pcPl['budget'], $pcCurLbl) : '—' }}</b></div>
                        @if ($pcPl['over'] !== null)
                            <div><div class="sub">فرقُ الميزانية (cost_delta)</div><b class="mono {{ $pcPl['over'] > 0 ? 'txt-bad' : '' }}">{{ $pcPl['over'] > 0 ? '+' : '' }}{{ number_format($pcPl['over'], 3) }}</b></div>
                        @endif
                        @if (! empty($pcPl['delay']['days']))
                            <div><div class="sub">تكلفةُ التأخير ({{ $pcPl['delay']['days'] }} يوماً)</div><b class="mono txt-bad">{{ $pcMoney($pcPl['delay']['cost'] ?? 0) }}</b></div>
                        @endif
                        <div><div class="sub">ساعاتٌ مسجَّلة</div><b class="mono">{{ number_format((float) ($pcPl['hours']['logged'] ?? 0), 1) }}</b></div>
                    </div>
                    <div class="sub" style="margin-top:8px">دلاءُ التكلفة الأربع (ساعاتٌ · خوادمُ · أدواتٌ · خدماتٌ خارجية) مقابلَ الإيراد المفوتر — كلٌّ من مصدره لا رقمٌ مركّب.</div>
                </div>
            </section>
        @endif

        {{-- ═══════════ ⑥ النشاط (سجلُّ العمل + الخطُّ الزمنيّ + نقاشُ السجلّ) ═══════════ --}}
        <section id="ccp-activity" data-ccpanel="activity" class="ccpanel {{ $pcFirst === 'activity' ? 'on' : '' }}">
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

                {{-- (§19/§20/§57) التقارير والتقدّم: يوم → موظّف → بنود — مَن عمل ماذا، على أيّ
                     مهمّة، كم ساعة، أيّ تقدّمٍ اقترح، وما العوائق. من WorkUpdate القائم لا محرّكٌ ثانٍ. --}}
                <div class="card">
                    <h3 class="cardtitle">📋 التقارير والتقدّم <span class="sub">— يوماً بيوم، موظفاً موظفاً</span></h3>
                    @foreach ($pcByDay as $day => $dayLogs)
                        <div style="margin:10px 0">
                            <div class="mono" style="font-weight:700">{{ $day }}</div>
                            @foreach ($dayLogs->groupBy('created_by') as $uid => $uLogs)
                                <div style="margin:6px 0 6px 4px;border-inline-start:2px solid var(--line,#eee);padding-inline-start:10px">
                                    <b>{{ $pcPeople[$uid] ?? '—' }}</b>
                                    <span class="sub">· {{ number_format((float) $uLogs->sum('hours'),1) }} ساعة · {{ $uLogs->count() }} بند</span>
                                    @foreach ($uLogs as $l)
                                        @php $rs = $l->review_status ?: 'pending_review'; @endphp
                                        <div style="margin-top:4px">
                                            ✅ {{ \Illuminate\Support\Str::limit($l->done, 140) }}
                                            @if ($l->task_id)<span class="sub">· {{ $pcTasks[$l->task_id] ?? 'مهمّة' }}</span>@endif
                                            @if ($l->progress !== null)<span class="bdg g">{{ (float)$l->progress }}٪</span>@endif
                                            <span class="bdg {{ $rs==='accepted'?'ok':($rs==='needs_revision'?'wn':'') }}">{{ ['pending_review'=>'بانتظار','accepted'=>'مقبول','needs_revision'=>'تنقيح'][$rs] ?? 'بانتظار' }}</span>
                                            @if (trim((string)$l->problems) !== '')<div class="sub" style="color:var(--bad,#c0392b)">🚧 {{ \Illuminate\Support\Str::limit($l->problems, 120) }}</div>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="sub">التقدّمُ المقترحُ يُراجعه المدير — لا يُكتب على المهمّة قسراً (سياسة المنشأة).
                        <a href="{{ route('reports.review') }}">مركز المراجعة ↗</a></div>
                </div>
            @endif
            @include('partials.timeline', ['timeline' => $timeline])
            @include('partials.comments', ['cModule' => $module, 'cRecordId' => $row->id, 'comments' => $comments, 'users' => $cUsers])
        </section>
    @endunless
</div>

{{-- عدسات المشروع: كل لوحةٍ تحليلية مفتوحةً على هذا المشروع وحده — للداخليّ المخوَّل --}}
@unless ($isCli)
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
@endunless

<script>
    // تبديلُ التبويبات بلا إعادةِ تحميل — كلُّ اللوحاتِ في DOM، نُظهر النشطةَ وحدَها.
    (function () {
        document.querySelectorAll('[data-cc-proj]').forEach(function (box) {
            var tabs = box.querySelectorAll('[data-cctab]');
            var panels = box.querySelectorAll('[data-ccpanel]');
            tabs.forEach(function (t) {
                t.addEventListener('click', function (e) {
                    e.preventDefault();
                    var key = t.getAttribute('data-cctab');
                    tabs.forEach(function (x) {
                        var on = x === t;
                        x.classList.toggle('on', on);
                        if (on) { x.setAttribute('aria-current', 'page'); } else { x.removeAttribute('aria-current'); }
                    });
                    panels.forEach(function (p) {
                        p.classList.toggle('on', p.getAttribute('data-ccpanel') === key);
                    });
                });
            });
        });
    })();
</script>
