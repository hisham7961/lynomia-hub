<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\KpiDef;
use App\Models\Ticket;
use App\Support\CeoBoard;
use App\Support\DataQuality;
use App\Support\ExecutionStats;
use App\Support\KpiCentre;
use App\Support\OkrCentre;
use App\Support\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مركزُ الجودة والإنجاز (WP-8.1 · spec §6 · §6.1 · §12 · §47).
 *
 * **مركزٌ واحدٌ بتبويبات فوق المحرّكات القائمة — لا محرّكَ ثانٍ ولا مسارَ ثانٍ.**
 * التبويبُ معاملُ رابطٍ (`?tab=`) على `quality.index` نفسِه، فالروابطُ المحفوظة
 * والإشاراتُ المرجعية تبقى صالحة، وشريطُ الإدارة لا يتغيّر.
 *
 * ═══ من أين يأتي كلُّ رقم (spec §6.1: لا درجةَ مركّبةٌ بلا رياضيات) ═══
 * النظرةُ التنفيذية ثمانيةُ أرقام، **كلٌّ من محرّكه وحدَه** ولا واحدَ منها
 * يُحسب هنا:
 *   ① جودةُ البيانات٪ ← `DataQuality::scan()['totals']['score']`
 *   ② إنجازُ العمل٪   ← `hub_kpis()` (مؤشّرُ المهامّ المبذور — لا عدٌّ ثانٍ للمهامّ)
 *   ③ الالتزام٪       ← `ExecutionStats::executionSummary()['on_time']`
 *   ④ المتأخّر        ← `ExecutionStats::executionSummary()['overdue']`
 *   ⑤ مؤشّراتٌ على الهدف ← `KpiCentre::summary()`
 *   ⑥ تقدّمُ OKR       ← `hub_okr_board()['avg']`
 *   ⑦ مشكلاتٌ حرجة    ← `CeoBoard::risks()` (يقرأ بـ`hub_read` فيجمع النطاقَ والصلاحية)
 *   ⑧ اتّجاهُ التحسّن  ← `DataQuality::history()['delta']`
 * **ولا رقمَ تاسعٌ يجمعها**: «درجةُ صحّةٍ عامّة» بلا رياضياتٍ موثَّقة رقمٌ
 * مخترع، ومن يقرؤه لا يعرف ما الذي يُصلحه ليرفعه.
 *
 * ═══ الحارس ═══
 * تبويبُ «البيانات» يبقى **للمالك وحدَه** كما كان: مسحُ الجودة غيرُ منطَّق،
 * يُخبَّأ تحت مفتاحٍ عامّ (`dq:scan`)، ويعرض **أسماءَ سجلاتٍ من كلّ الوحدات**
 * في عيّناته. وبقيةُ التبويبات أرقامُ منشأةٍ مجمَّعة ⇒ `hub_monitor()` +
 * `hub_org_analytics_guard()` (فالحسابُ المعزول على شركاتٍ أو عملاء لا يُطعَم
 * مجموعَ غيره).
 */
class QualityController extends Controller
{
    /** التبويبات: المفتاح ⇒ التسمية. المصدرُ الواحد للشريط وللتحقّق من المعامل */
    public const TABS = [
        'overview'  => '🎯 النظرة التنفيذية',
        'data'      => '🧹 جودة البيانات',
        'execution' => '🚀 التنفيذ',
        'kpi'       => '📊 المؤشّرات',
        'okr'       => '🎯 الأهداف',
        'trends'    => '📈 الاتّجاهات',
        'actions'   => '🛠 المعالجة',
    ];

    /** تبويباتٌ للمالك وحدَه — بياناتُها غيرُ منطَّقة بالبناء */
    public const OWNER_TABS = ['data'];

    /** المدى الافتراضي لأرقام التنفيذ — شهرٌ يكفي لتظهر دورةُ عملٍ كاملة */
    public const RANGE = '30d';

    /** مهلةُ خبيئة النظرة التنفيذية — تُبطَل فوراً بختم الجداول لا بالمهلة وحدها */
    public const TTL = 300;

    /** سقفُ طابور SLA في تبويب المعالجة — يُعلَن للقارئ ولا يُقرأ الجزءُ كأنه الكلّ */
    public const SLA_QUEUE_CAP = 40;

    /** سقفُ العملاء المفحوصين في كشف التكرار — معلَنٌ لا مبتلَع */
    public const DUP_SCAN_CAP = 50000;

    /** أصنافُ التشابه: المفتاحُ ⇒ الحقلُ الذي طابق. مصدرٌ واحدٌ للشاشة وللقيد */
    public const DUP_BY = ['norm' => 'الاسم', 'email' => 'البريد', 'phone' => 'الهاتف'];

    /**
     * حقولُ الأساسي التي تُملأ من المدموجين إن كانت فارغة (لا استبدالَ لقيمةٍ
     * موجودة) — بأسمائها، فالمعاينةُ تقول **أيَّ حقلٍ** ستملأ لا «ستُنقل قيم».
     */
    public const FILL_COLS = ['contact' => 'مسؤول التواصل', 'email' => 'البريد', 'phone' => 'الهاتف',
        'country' => 'الدولة', 'company_id' => 'الشركة', 'owner_id' => 'المسؤول',
        'source' => 'المصدر', 'notes' => 'ملاحظات'];

    /* ────────── الحارس ────────── */

    /** حارسُ الدمج والقراءة غيرِ المنطَّقة: المالكُ وحدَه (كما كان قبل التبويبات) */
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز جودة البيانات للمالكين فقط');
    }

    /**
     * حارسُ التبويب. «البيانات» للمالك، وسائرُها لحاملِ راية المتابعة **غيرِ
     * المعزول** — والترتيبُ مقصود: الرايةُ أولاً ثم العزل، فرسالةُ الرفض تقول
     * السببَ الأدقّ لمن يملك الراية ولا يملك سعةَ النظر.
     */
    protected function tabGate(string $tab): void
    {
        if (in_array($tab, self::OWNER_TABS, true)) {
            $this->gate();

            return;
        }

        abort_unless(hub_monitor(), 403,
            'مركزُ الجودة والإنجاز لمن يحمل صلاحية المتابعة أو للمالك');
        hub_org_analytics_guard();
    }

    /** التبويبُ المطلوب — وما لا يُعرَف يرتدّ للنظرة التنفيذية بلا سقوط */
    protected function tab(Request $r): string
    {
        $t = trim(hub_str($r->query('tab')));

        return isset(self::TABS[$t]) ? $t : 'overview';
    }

    /* ────────── الشاشة ────────── */

    public function index(Request $r)
    {
        $tab = $this->tab($r);
        $this->tabGate($tab);
        $range = hub_range($r, self::RANGE);

        $d = ['tab' => $tab, 'tabs' => self::TABS, 'range' => $range, 'ttl' => self::TTL];

        return view('admin.quality', $d + match ($tab) {
            'data'      => $this->dataTab($r),
            'execution' => $this->cached('execution', $range->key(), fn () => ['ex' => ExecutionStats::center($range)],
                ['tasks', 'projects', 'tickets', 'work_updates', 'comments', 'metric_points']),
            'kpi'       => $this->cached('kpi', '', fn () => $this->kpiTab(), ['kpi_defs', 'metric_points']),
            'okr'       => $this->cached('okr', '', fn () => ['okr' => OkrCentre::board()],
                ['objectives', 'key_results', 'projects']),
            'trends'    => $this->cached('trends', '', fn () => $this->trendsTab(), ['metric_points']),
            // القوائمُ تُخبَّأ (بمفتاحٍ لكل مستخدم)، و**الصلاحيةُ تُقرأ حيّةً**:
            // زرُّ فعلٍ لا يُخبَّأ خلف حالةِ صلاحيةٍ قد تتغيّر قبل انتهاء المهلة.
            'actions'   => $this->cached('actions', '', fn () => $this->actionsTab(),
                ['kpi_defs', 'objectives', 'key_results', 'tickets', 'comments', 'tasks', 'metric_points'])
                + ['canRemediate' => hub_monitor() && hub_can(auth()->user(), 'tasks', 'a')],
            default     => $this->cached('overview', $range->key(), fn () => ['ov' => $this->overview($range)],
                ['tasks', 'tickets', 'kpi_defs', 'objectives', 'key_results', 'issues',
                 'incidents', 'metric_points']),
        });
    }

    /**
     * غلافُ تخبئةٍ موحَّد للتبويبات المحسوبة: `hub_screen` القائمة ببصمة النطاق
     * وختم الجداول (`?fresh=1` يُبطلها)، و`stamped` كي يُعرَض «آخر حساب» صادقاً
     * في `partials/cc/freshness` — لا دالّةَ تخبئةٍ ثانية ولا ختمٌ يدويّ.
     *
     * و`$key` مفتاحُ المدى **للتبويبات التي تقرؤه وحدَها**: تبويبٌ لا يتغيّر
     * بالكبسولة لا يُخزَّن ستَّ نسخٍ منه — نسخةٌ لكل كبسولةٍ لا يقرؤها أحد.
     */
    protected function cached(string $tab, string $key, \Closure $fn, array $tables): array
    {
        $s = hub_screen('quality:' . $tab . ($key !== '' ? ':' . $key : ''), self::TTL, $fn, $tables, true);

        return $s['data'] + ['at' => $s['at']];
    }

    /* ────────── ① النظرةُ التنفيذية (§6.1) ────────── */

    /**
     * ثمانيةُ أرقامٍ من ثمانية محرّكات. **لا رقمَ يُحسب في هذه الدالّة**: كلُّ
     * سطرٍ نقلٌ من مخرَج محرّكه، وما لا يقيسه محرّكُه يبقى `null` — و«لا قياس»
     * ليس صفراً (صفرٌ مكانَ قياسٍ لم يقع يرسم انهياراً لم يحدث).
     */
    protected function overview(TimeRange $range): array
    {
        $scan  = DataQuality::scan();
        $x     = ExecutionStats::executionSummary($range);
        $kRows = KpiCentre::rows();
        $kSum  = KpiCentre::summary($kRows);
        $board = hub_okr_board();
        $risks = CeoBoard::risks();
        $hist  = DataQuality::history();

        return [
            'quality' => ['score' => $scan['totals']['score'], 'defects' => $scan['totals']['defects'],
                          'checks' => $scan['totals']['checks'], 'at' => $scan['totals']['at']],
            'completion' => $this->completion($kRows),
            'ontime' => $x['on_time'],
            'overdue' => $x['overdue'],
            'open' => $x['open'],
            'kpis' => ['total' => $kSum['total'], 'on' => $kSum['on'], 'warn' => $kSum['warn'],
                       'off' => $kSum['off'], 'dead' => $kSum['dead'], 'nodata' => $kSum['nodata']],
            'okr' => ['avg' => $board['avg'], 'measured' => $board['measured'],
                      'n' => $board['n'], 'behind' => $board['behind']],
            'risks' => ['listed' => count($risks),
                        'critical' => count(array_filter($risks, fn ($v) => ($v['tone'] ?? '') === 'bad'))],
            'improve' => ['delta' => $hist['delta'], 'fixed' => $hist['fixed'],
                          'points' => count($hist['points']), 'series' => $hist['points']],
            'range' => $range,
        ];
    }

    /**
     * ② إنجازُ العمل٪ — **قيمةُ مؤشّرٍ من `hub_kpis`، لا عدٌّ ثانٍ للمهامّ.**
     *
     * والمؤشّرُ يُميَّز **بشكل معادلته لا باسمه**: نسبةٌ مئوية بين عدّتَي مهامٍّ،
     * بسطُها حالةُ إغلاقٍ يعرفها `hub_closed_scope` ومقامُها بلا فلتر. فتغييرُ
     * التسمية لا يفقد البطاقة مصدرَها (سابقةُ `HubKpisStarter::RENAMED`).
     *
     * و`gap` هي المصارحة اللازمة: محرّكُ المعادلات يرشّح **حالةً واحدة**
     * (`hub_kpi_metric`: `where(status, ?)`) بينما سجلُّ المهامّ يعرف أكثرَ من
     * حالةِ إغلاق («منجزة» و«مكتملة» و«ملغاة» — قاموسُ `hub_closed_scope`).
     * فالمؤشّرُ المبذور يقيس **جزءاً** من المغلق، وهذا يُقال على البطاقة نصّاً
     * بدل أن يُبتلع: نسبةٌ ناقصةٌ تبدو كاملةً أخطرُ من رقمٍ غائب.
     */
    protected function completion(array $kRows): array
    {
        $empty = ['kpi' => null, 'value' => null, 'gap' => []];
        if (! \Illuminate\Support\Facades\Schema::hasTable('kpi_defs')) return $empty;

        $def = KpiDef::when(hub_has_col('kpi_defs', 'active'), fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('id')->get(['id', 'formula'])
            ->first(fn ($k) => $this->isCompletionFormula((array) $k->formula));
        if (! $def) return $empty;

        $row = collect($kRows)->firstWhere('id', $def->id);
        if (! $row) return $empty;

        return ['kpi' => $row, 'value' => $row['value'],
                'gap' => $this->closedGap((array) $def->formula)];
    }

    /** شكلُ معادلة «نسبةُ إنجاز المهامّ»: (مهامٌّ بحالةِ إغلاق) ÷ (كلُّ المهامّ) × ١٠٠ */
    protected function isCompletionFormula(array $f): bool
    {
        $a = $f['a'] ?? null;
        $b = $f['b'] ?? null;
        if (! is_array($a) || ! is_array($b)) return false;
        if (($f['combine'] ?? '') !== 'ratio_pct') return false;
        if (hub_str($a['module'] ?? '') !== 'tasks' || hub_str($b['module'] ?? '') !== 'tasks') return false;
        if ((hub_str($a['agg'] ?? 'count') ?: 'count') !== 'count') return false;
        if ((hub_str($b['agg'] ?? 'count') ?: 'count') !== 'count') return false;
        if (trim(hub_str($b['st'] ?? '')) !== '') return false;

        return in_array(trim(hub_str($a['st'] ?? '')), hub_closed_states(), true);
    }

    /**
     * حالاتُ الإغلاق التي **يفوتُها** فلترُ المؤشّر — بمفردات `hub_closed_scope`
     * نفسِها: خياراتُ حالةِ الوحدة ∩ قاموسُ المغلق، ناقصاً الحالةَ التي يعدّها.
     */
    protected function closedGap(array $formula): array
    {
        $def = hub_mod(hub_str($formula['a']['module'] ?? ''));
        if (! $def) return [];
        $skey = (string) ($def['status'] ?? '');
        if ($skey === '') return [];

        $opts = (array) (collect($def['fields'])->firstWhere('key', $skey)['options'] ?? []);
        $closed = array_values(array_intersect($opts, hub_closed_states()));

        return array_values(array_diff($closed, [trim(hub_str($formula['a']['st'] ?? ''))]));
    }

    /* ────────── ② تبويبُ البيانات (WP-8.2 + كشفُ التكرار القائم) ────────── */

    protected function dataTab(Request $r): array
    {
        // ١) عملاءُ يُرجَّح تكرارهم — كشفٌ **مخبَّأ** (انظر `duplicates`)
        $groups = $this->duplicates();

        // ٢) المسح المشتقّ من سجل الوحدات — كل وحدةٍ وكل حقل، لا ثلاث وحدات
        $scan = DataQuality::scan((bool) $r->query('fresh'));

        return [
            'groups'  => $groups,
            'checks'  => $scan['checks'],
            'byMod'   => $scan['byModule'],
            'totals'  => $scan['totals'],
            'history' => DataQuality::history(),
            'dqTrend' => DataQuality::moduleTrend(),
            'clean'   => empty($groups) && empty($scan['checks']),
        ];
    }

    /* ────────── ③ تبويبُ المؤشّرات (WP-8.5) ────────── */

    protected function kpiTab(): array
    {
        $rows = KpiCentre::rows();

        return ['kpiRows' => $rows, 'kpiSum' => KpiCentre::summary($rows),
                'kpiOff' => KpiCentre::offTarget($rows)];
    }

    /* ────────── ④ تبويبُ الاتّجاهات (§6.11 · §6.12) ────────── */

    /** الاتّجاهُ يُقرأ من اللقطات وحدَها — وبلا لقطتين لا وسم، ولا خطُّ صفرٍ كاذب */
    protected function trendsTab(): array
    {
        return [
            'history'   => DataQuality::history(),
            'dqTrend'   => DataQuality::moduleTrend(),
            'exHistory' => ExecutionStats::history(),
            'qSeries'   => hub_metric_series('quality', 'org', 'score', 60),
        ];
    }

    /* ────────── ⑤ تبويبُ المعالجة (§6.13) ────────── */

    /**
     * أفعالُ التحسين: كلُّ نتيجةٍ هنا لها زرٌّ يفتح **مهمّةً** في نظام المهامّ
     * (`RemediationController`) — لا جدولَ «إجراءاتٍ تصحيحية» ثانياً.
     *
     * ونتائجُ الجودة **للمالك وحدَه** هنا أيضاً: أسماءُ الوحدات والقواعد تأتي من
     * مسحٍ غيرِ منطَّق، فحارسُها حارسُ تبويبها نفسُه (ونظيرُه في
     * `RemediationController::fromQuality`).
     */
    protected function actionsTab(): array
    {
        $rows = KpiCentre::rows();
        $board = OkrCentre::board();

        return [
            'actKpi' => KpiCentre::offTarget($rows),
            'actOkr' => $board['attention'] ?? [],
            'actSla' => $this->slaBreaches(),
            'actQuality' => hub_is_owner()
                ? array_slice(DataQuality::scan()['checks'] ?? [], 0, 20)
                : [],
        ];
    }

    /**
     * تذاكرُ خرقت SLA — بالمحرّك القائم `hub_sla` (سياسةُ الإعدادات نفسُها التي
     * تقرؤها لوحةُ الدعم) لا بعتبةٍ ثانيةٍ تُكتب هنا. والطابورُ **مقصوصٌ معلَناً**
     * (`SLA_QUEUE_CAP`) وأوّلُ ردٍّ يُقرأ لكلّ العيّنة باستعلامٍ واحد لا واحدٍ
     * لكل تذكرة.
     */
    protected function slaBreaches(): array
    {
        if (! hub_can(auth()->user(), 'tickets', 'v')) return [];

        $open = hub_open_scope(hub_scope(Ticket::query()->whereNull('deleted_at'), 'tickets'))
            ->orderBy('created_at')->orderBy('id')->limit(self::SLA_QUEUE_CAP)->get();
        if ($open->isEmpty()) return [];

        $firsts = DB::table('comments')->where('module', 'tickets')
            ->whereIn('record_id', $open->pluck('id'))->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
            ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
            ->pluck('at', 'record_id');

        $out = [];
        foreach ($open as $t) {
            $s = hub_sla($t, $firsts[$t->id] ?? null);
            if (! $s['respLate'] && ! $s['resLate']) continue;
            $out[] = ['t' => $t, 'sla' => $s];
        }

        return $out;
    }

    /* ────────── ⑥ التكرار: كشفٌ مخبَّأ ودمجٌ مدقَّق (WP-8.3 · §6.4 · §31) ────────── */

    /**
     * مجموعاتُ التكرار المكتشَفة — **مرجعُ الحقيقة للعرض وللدمج معاً.**
     *
     * كان الكشفُ يقع داخل `dataTab` عند **كل فتحةٍ** للتبويب: خمسون ألفَ صفٍّ
     * إلى الذاكرة وثلاثُ عمليات تجميع، ولو لم يتغيّر عميلٌ واحد. وهو الآن خلف
     * `hub_screen` ببصمة النطاق و**ختمِ جدول العملاء**: أيُّ كتابةٍ على `clients`
     * (والدمجُ نفسُه منها) تُبطل المفتاح فوراً، فلا رقمٌ بائتٌ ولا حسابٌ مكرَّر.
     *
     * والمخرَجُ **مصفوفاتٌ صرفة** لا نماذجَ ولا `stdClass`: قيمةٌ تُخبَّأ تُسلسَل،
     * فالشكلُ الصريح يبقى قابلاً للقراءة بعد إعادة الجلب، ويُقارَن به المدخل.
     *
     * @return array<string, array{by:string, match:string, ids:array<int,string>, rows:array}>
     */
    protected function duplicates(): array
    {
        return hub_screen('quality:dup', self::TTL, function () {
            // صفوف خام لا نماذج: تحميل النماذج كان ~٢.٦ كيلوبايت للصف (٥٠ ميجابايت لعشرين ألف عميل)
            $clients = collect(DB::table('clients')->whereNull('deleted_at')
                ->orderBy('created_at')->orderBy('id')
                ->limit(self::DUP_SCAN_CAP)->get(['id', 'name', 'email', 'phone', 'created_at']));

            $groups = [];
            foreach (['norm' => fn ($c) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $c->name))),
                      'email' => fn ($c) => mb_strtolower(trim((string) $c->email)),
                      'phone' => fn ($c) => preg_replace('/\D+/', '', (string) $c->phone)] as $kind => $fn) {
                foreach ($clients->groupBy($fn) as $key => $g) {
                    // مفتاحُ الهاتف أرقامٌ صرفة فيصير `int` في مصفوفة PHP — القصرُ لازم
                    if ((string) $key === '' || $g->count() < 2) continue;
                    $ids = $g->pluck('id')->sort()->values()->all();
                    $groups[implode(',', $ids)] = [
                        'by' => $kind, 'match' => (string) $key, 'ids' => $ids,
                        'rows' => $g->map(fn ($c) => [
                            'id' => (string) $c->id, 'name' => (string) $c->name,
                            'email' => (string) $c->email, 'phone' => (string) $c->phone,
                            'created_at' => (string) $c->created_at,
                        ])->values()->all(),
                    ];
                }
            }
            ksort($groups);   // ترتيبٌ صريح: القرعةُ تُغيّر ما يراه القارئ بين فتحةٍ وأخرى

            return $groups;
        }, ['clients']);
    }

    /**
     * أهدافُ المراجع إلى «عملاء»: كلُّ حقلٍ مرجعيٍّ في سجل الوحدات + المرفقاتُ
     * الحيّة المشيرة بـ(وحدة + معرّف) خارجَه. **حلقةٌ واحدة** تخدم العدَّ في
     * المعاينة والنقلَ في التنفيذ — فما تَعِدُ به المعاينةُ هو ما يقع بالضبط.
     *
     * والتدقيقُ والإصدارات مستثنيان عمداً: تاريخٌ يبقى ملتصقاً بالسجل الأصلي.
     *
     * @return array<int, array{table:string, col:string, label:string, mod:?string}>
     */
    protected function refTargets(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;

        $out = [];
        foreach (config('hub.modules') as $mk => $md) {
            foreach ((array) ($md['fields'] ?? []) as $f) {
                if (($f['ref'] ?? null) !== 'clients' || ($f['type'] ?? '') !== 'ref') continue;
                $out[] = ['table' => (string) $md['table'], 'col' => (string) $f['col'],
                          'label' => (string) ($md['label'] ?? $mk), 'mod' => null];
            }
        }
        $out[] = ['table' => 'comments', 'col' => 'record_id', 'label' => 'تعليقات', 'mod' => 'clients'];
        $out[] = ['table' => 'inbox_documents', 'col' => 'record_id', 'label' => 'وثائق واردة', 'mod' => 'clients'];

        return $memo = $out;
    }

    /** استعلامُ هدفٍ مرجعيّ — المفتاحُ متعدّد الأشكال يحتاج قيدَ الوحدة معه */
    protected function refQuery(array $t)
    {
        $q = DB::table($t['table']);
        if ($t['mod'] !== null) $q->where('module', $t['mod']);

        return $q;
    }

    /**
     * عددُ المراجع **لكل مرشَّح** — نفسُ حلقة `refTargets` بـ`COUNT` بدل
     * `UPDATE`، واستعلامٌ واحدٌ لكل هدفٍ لا واحدٌ لكل مرشَّح.
     *
     * ولا مرشّحَ لـ`deleted_at` هنا: النقلُ في التنفيذ لا يرشّح به كذلك، فلو
     * رشّحت المعاينةُ وحدَها لَوعَدت برقمٍ أصغرَ ممّا ستحرّكه.
     *
     * @return array<string, array{total:int, by:array<string,int>}>
     */
    protected function refCounts(array $ids): array
    {
        $out = array_fill_keys($ids, ['total' => 0, 'by' => []]);
        foreach ($this->refTargets() as $t) {
            $rows = $this->refQuery($t)->whereIn($t['col'], $ids)
                ->select([$t['col'] . ' as rid', DB::raw('COUNT(*) as n')])
                ->groupBy($t['col'])->get();
            foreach ($rows as $row) {
                $rid = (string) $row->rid;
                if (! isset($out[$rid]) || (int) $row->n === 0) continue;
                $out[$rid]['total'] += (int) $row->n;
                $out[$rid]['by'][$t['label']] = ($out[$rid]['by'][$t['label']] ?? 0) + (int) $row->n;
            }
        }

        return $out;
    }

    /**
     * حلُّ طلبِ دمجٍ إلى (الباقي · المدموجون · المجموعةُ التي يخرجان منها).
     *
     * **الكشفُ هو المرجع لا الطلب.** كان `ids` قائمةً حرّةً تُحلّ بـ`findOrFail`
     * غيرِ منطَّق: أيُّ معرّفِ عميلٍ في القاعدة — لا صلةَ له بأيّ تكرارٍ مكتشَف —
     * يُبتلع في سجلٍّ آخر فتُعاد إشاراتُه ويُحذف (فجوةُ §23.5). فالآن يُشترط أن
     * تسع **مجموعةٌ واحدةٌ مكتشَفة** كلَّ المعرّفات الممرَّرة، وإلا سقط الطلبُ
     * كلُّه قبل أن يُكتب حرف — لا دمجَ جزئيّ.
     *
     * @return array{keep: Client, dupes: \Illuminate\Support\Collection, group: array}
     */
    protected function resolveMerge(Request $r): array
    {
        $d = $r->validate(['keep' => ['required', 'uuid'], 'ids' => ['required', 'string', 'max:2000']]);

        $ids = collect(explode(',', $d['ids']))->map(fn ($x) => trim($x))
            ->filter(fn ($x) => $x !== '')->push($d['keep'])->unique()->sort()->values()->all();

        $group = null;
        foreach ($this->duplicates() as $g) {
            if (! array_diff($ids, $g['ids'])) { $group = $g; break; }
        }
        abort_if($group === null, 422,
            'هذه السجلات ليست مجموعةَ تكرارٍ مكتشَفة — أعد الحساب وادمج من القائمة المعروضة');

        $keep = Client::whereNull('deleted_at')->find($d['keep']);
        abort_if(! $keep, 422, 'السجل الأساسي لم يعد موجوداً — أعد الحساب');

        $dupes = Client::whereNull('deleted_at')
            ->whereIn('id', array_values(array_diff($ids, [$keep->id])))
            ->orderBy('created_at')->orderBy('id')->get();
        abort_if($dupes->isEmpty(), 422, 'لا سجلات لدمجها');

        return ['keep' => $keep, 'dupes' => $dupes, 'group' => $group];
    }

    /**
     * **معاينةُ الدمج — تقرأ ولا تكتب** (spec §6.4 · §31).
     *
     * الدمجُ فعلٌ لا رجعةَ فيه بنقرةٍ واحدة، وكان يقع بلا أن يرى أحدٌ ما سيتحرّك.
     * المعاينةُ تُجري نفسَ الحلقة بـ`COUNT` وتردّ: سببَ الترجيح والحقلَ المطابق،
     * ومقارنةً جنباً إلى جنب، وعددَ المراجع لكل مرشَّح موزّعاً على وحداته،
     * والفراغاتِ التي ستُملأ ومن أين. ولا كتابةَ واحدة — ولا قيدَ تدقيق: قيدُ
     * «دمج» عن معاينةٍ لم تدمج يكذب على من يقرأ السجلّ.
     */
    public function preview(Request $r)
    {
        $this->gate();
        ['keep' => $keep, 'dupes' => $dupes, 'group' => $group] = $this->resolveMerge($r);

        $ids = array_merge([(string) $keep->id], $dupes->pluck('id')->map(fn ($x) => (string) $x)->all());
        $counts = $this->refCounts($ids);

        $rows = [];
        foreach ($group['rows'] as $row) {                    // بترتيب الكشف نفسِه
            if (! in_array($row['id'], $ids, true)) continue;
            $rows[] = $row + [
                'role' => $row['id'] === (string) $keep->id ? 'keep' : 'dupe',
                'refs' => $counts[$row['id']]['total'] ?? 0,
                'byMod' => $counts[$row['id']]['by'] ?? [],
            ];
        }

        // الفراغاتُ التي ستُملأ — بالحقل ومصدرِه. وقيمةُ عمودِ معرّفٍ لا تُعرض
        // خاماً: uuid على الشاشة ليس معلومةً لأحد، ووجودُ التغيير هو الخبر.
        $fill = [];
        foreach (self::FILL_COLS as $col => $label) {
            if (! blank($keep->{$col})) continue;
            $src = $dupes->first(fn ($x) => filled($x->{$col}));
            if (! $src) continue;
            $fill[] = ['label' => $label, 'from' => hub_fit((string) $src->name, 60),
                       'value' => str_ends_with($col, '_id') ? null : hub_fit((string) $src->{$col}, 80)];
        }

        return back()->with('mergePreview', [
            'keep' => (string) $keep->id, 'keepName' => (string) $keep->name, 'ids' => $ids,
            'by' => $group['by'], 'byLabel' => self::DUP_BY[$group['by']] ?? $group['by'],
            'match' => $group['match'], 'rows' => $rows, 'fill' => $fill,
            'moving' => array_sum(array_map(fn ($x) => $counts[$x]['total'] ?? 0,
                $dupes->pluck('id')->map(fn ($x) => (string) $x)->all())),
        ]);
    }

    /**
     * **تنفيذُ الدمج — مدقَّقٌ وقابلٌ للتراجع** (spec §6.4 · §31 · §23.5).
     *
     * يبقى الأساسي، وتُعاد إليه كلُّ الإشارات، وتُملأ فراغاته من المدموجين، ثم
     * يُحذف المدموجون **ناعماً** (سلةُ المحذوفات) بعد أن يقول كلٌّ منهم في
     * `meta.merged_into/merged_at` أين ذاب — فسجلٌّ يختفي بلا أثرٍ يقول أين ذهب
     * هو بيانٌ ضائع لا بيانٌ منظَّف.
     *
     * وقيدُ تدقيقٍ **واحدٌ صريح** («دمج عملاء») يحمل المدموجين وأسماءهم والمنقولَ
     * موزّعاً على وحداته وسببَ الترجيح، و`request_id` يصله بطلبه (يضعه
     * `hub_audit`). قبل هذا لم يكن للدمج قيدٌ إطلاقاً: خمسةَ عشرَ جدولاً تتغيّر
     * مراجعُها وسجلاتٌ تُحذف، ولا سطرَ يقول من فعل ولا بمن.
     */
    public function merge(Request $r)
    {
        $this->gate();
        ['keep' => $keep, 'dupes' => $dupes, 'group' => $group] = $this->resolveMerge($r);

        $moved = 0;
        $byMod = [];
        $names = [];
        DB::transaction(function () use ($keep, $dupes, $group, &$moved, &$byMod, &$names) {
            foreach ($dupes as $dup) {
                $names[] = hub_fit((string) $dup->name, 60);

                // إعادةُ توجيه المراجع — نفسُ أهداف المعاينة، بالترتيب نفسِه
                foreach ($this->refTargets() as $t) {
                    $n = (int) $this->refQuery($t)->where($t['col'], $dup->id)
                        ->update([$t['col'] => $keep->id]);
                    if (! $n) continue;
                    // كتابةٌ خام لا تُطلق أحداث Eloquent — يُرفع ختم الجدول يدوياً
                    hub_data_bump($t['table']);
                    $moved += $n;
                    $byMod[$t['label']] = ($byMod[$t['label']] ?? 0) + $n;
                }

                // ملء فراغات الأساسي من المدموج (لا استبدال لقيم موجودة)
                foreach (array_keys(self::FILL_COLS) as $col) {
                    if (blank($keep->{$col}) && filled($dup->{$col})) $keep->{$col} = $dup->{$col};
                }

                // أثرُ الوجهة على الصفّ نفسِه — من يفتح المدموج يعرف أين يتابع
                $dup->forceFill(['meta' => array_merge((array) $dup->meta, [
                    'merged_into' => (string) $keep->id, 'merged_at' => now()->toIso8601String(),
                ])])->save();

                $dup->delete();   // ناعم — يمر بالتدقيق ويمكن استرجاعه من السلة
            }
            $keep->save();

            hub_audit('دمج عملاء', 'clients', $keep->id, $keep->name, [
                'before' => ['merged' => $dupes->pluck('id')->map(fn ($x) => (string) $x)->all(),
                             'names' => $names],
                'after'  => ['into' => (string) $keep->id, 'refs_moved' => $moved,
                             'by' => $group['by'], 'match' => $group['match'], 'moved_by' => $byMod],
            ]);
        });

        return back()->with('ok', "دُمجت {$dupes->count()} نسخة في «{$keep->name}» وأُعيد توجيه {$moved} إشارة — النسخ المدموجة في سلة المحذوفات");
    }
}
