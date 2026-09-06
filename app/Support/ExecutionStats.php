<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * قارئُ التنفيذ الواحد (WP-7.2 · critic #1): إسقاطان لا عدّادان —
 * `::org()` تستهلكه نظرةُ القوى العاملة (الطور ٧)، و`::person()`/`::people()`
 * تستهلكهما بطاقةُ الموظف (`portal.employee`) ولوحُ الأداء
 * (`PerformanceController::peopleKpis`) ولوحاتُ الطور ٨. **لا يُنشأ عدّادُ KPI
 * سادسٌ باسمٍ آخر** — مصدرُ الأرقام واحد (`tasks`, `tickets`, `hub_sla`)
 * ونافذتُه واحدة (`TimeRange`)، فالرقمُ الذي تراه شاشةُ المنشأة هو نفسُه الذي
 * تراه بطاقةُ الموظف وهو نفسُه الذي يراه لوحُ الأداء (كانت ثلاثَ نسخٍ متداخلةً
 * تختلف في تعريف «أُنجز» نفسِه، فتقول الشاشتان رقمين لموظفٍ واحد).
 *
 * قواعدُ صدقٍ ثابتة:
 *  - «أُنجز» و«في الموعد» من **`tasks.completed_at`** (يختمه ModuleController عند
 *    دخول حالة الإنجاز) لا من `updated_at` — تعديلٌ لاحقٌ على مهمةٍ منجزة لا
 *    يُفسد تاريخَ إنجازها ولا يقلب نسبةَ الالتزام.
 *  - المفتوح/المنتهي عبر `hub_open_scope`/`hub_closed_scope` — لا قوائمَ حالاتٍ
 *    حرفيةً جديدة. (مفردةُ «حُسمت» الخاصة بالموافقات وحدَها ثابتةٌ هنا كي لا
 *    تولد نسخةٌ رابعة — النسختان القائمتان في Inbox وCeoBoard مرشّحتان للتوحيد عليها.)
 *  - **لا مراقبةَ شخصية** (spec §5): لا قراءةَ لجدول زيارات الصفحات إطلاقاً
 *    (يحرسه فحصُ مصدرٍ في WorkforceOverviewTest)، ولا ترتيبَ موظفٍ بعدد
 *    زياراته، ولا خلطَ لأي درجةٍ أمنية بأرقام الأداء.
 *  - النافذةُ الحالية تُقارَن بنافذةٍ سابقةٍ مساويةٍ متجاورة (spec §36 —
 *    `TimeRange::prev()` هي صورةُ `hub_window_pair` العامة) عبر `hub_compare`.
 */
class ExecutionStats
{
    /**
     * حالاتُ «حُسمت» الخاصةُ بالموافقات — دلالةٌ محليةٌ تُمرَّر لـ`hub_open_scope`
     * كي لا تُحسب الموافقةُ الموافَقُ عليها «معلّقة» (المفردةُ نفسُها في Inbox::approvals
     * وCeoBoard::awaitingCalc حرفياً — التوحيدُ عليها هنا يمنع انزلاقَ نسخةٍ رابعة).
     */
    public const APPROVAL_DECIDED = ['موافق', 'موافقة', 'معتمد', 'معتمدة'];

    /** سقفُ عيّنة SLA وسقفُ المشاريع المفحوصةِ الصحةَ — يُعلَنان في نص المنهجية */
    public const SLA_SAMPLE_CAP = 500;
    public const HEALTH_SAMPLE_CAP = 40;

    /** عتبةُ «تأخّرٌ كثيف»: موظفٌ عليه هذا العددُ فأكثر من المهام الفائتةِ موعدَها */
    public const HEAVY_OVERDUE_MIN = 3;

    // ── Control Plane: Phase 8 (WP-8.4) — ثوابتُ تحليلات التنفيذ ──
    /**
     * مفردةُ التوقّف في وحدة المهامّ (`config/hub.php`) — **مكتوبةٌ مرّةً واحدة**:
     * يقرؤها عدّادُ «متوقّف» في §6.5 وبطاقةُ الانتظار في قارئ الاختناقات معاً،
     * فلا تنزلق نسخةٌ عن أختها إن تغيّرت المفردة.
     */
    public const PAUSED_STATUS = 'متوقفة';

    /** سقفُ صفوف جدول المشاريع (§6.6) وسقفُ عيّنة زمن الدورة (§6.7) — يُعلَنان للقارئ */
    public const PROJECTS_TABLE_CAP = 60;
    public const CYCLE_SAMPLE_CAP = 500;

    /** مقاييسُ لقطة التنفيذ اليومية (§6.12) — بهذا الترتيب في العرض */
    public const SNAPSHOT_METRICS = ['completion_pct', 'ontime_pct', 'overdue', 'open_issues'];

    /** نافذةُ اللقطة اليومية: نسبتا الإنجاز والالتزام تُقاسان على شهرٍ لا على يوم */
    public const SNAPSHOT_RANGE = '30d';

    /**
     * **قاعدةُ خطر المشروع مكتوبةً لا مضمَرة** (§6.1: لا درجةَ مركّبةٌ بلا رياضياتٍ
     * موثَّقة): الخطرُ عددُ الأعلام المرصودة في الصفّ نفسِه، وكلُّ علَمٍ يساوي
     * استعلامَه المرجعيّ في العمود المجاور — فما من رقمٍ مخترعٍ يختبئ خلف لون.
     */
    public const RISK_RULE = 'خطرُ المشروع عددُ الأعلام المرصودة في صفّه: مهامُّ متأخّرة · مهامُّ متوقفة · معوّقاتٌ مبلَّغة. بلا علَمٍ ⇒ معلوماتي، وعلَمٌ ⇒ متوسط، وعلَمان ⇒ مرتفع، وثلاثةٌ ⇒ حرج. لا درجةَ مركّبةٌ ولا وزنَ مخترع.';

    /** خريطةُ عدد الأعلام إلى درجة `Severity` — الرياضياتُ في RISK_RULE حرفياً */
    protected const RISK_LEVELS = [0 => 'info', 1 => 'medium', 2 => 'high', 3 => 'critical'];

    // ── Control Plane: Phase 7 (WP-7.3) — ثوابتُ إسقاط الأشخاص ──
    /** سقفُ عيّنة قياس زمن الحل وعيّنة الاعتمادات المعلّقة في القراءة الجَماعية */
    public const PEOPLE_SAMPLE_CAP = 2000;

    /**
     * **الأفعالُ ذاتُ المعنى** (spec §5.3): عملٌ غيَّر بياناتٍ — إضافةٌ وتعديلٌ
     * وحذفٌ وتصدير. قائمةُ **سماحٍ** لا قائمةَ منع: فالدخولُ والخروجُ و«العرضُ
     * الحسّاس» خارجَها **بالبنية** لا باستثناءٍ قد يُنسى — لا يُقاس موظفٌ بعدد
     * دخولاته (spec §5.5)، ولا يُكشف اطّلاعُه على حقلٍ سرّي في بطاقة إنتاجية
     * (spec §5.1: الأمنُ لا يُخلط بالأداء). المطابقةُ على الفعل كاملاً أو
     * ببادئته متبوعةً بمسافة («تصدير كبير»، «حذف مرفق»).
     */
    public const MEANINGFUL_ACTIONS = ['إضافة', 'تعديل', 'حذف', 'تصدير'];

    // ── Control Plane: Phase 7 (WP-7.4) — ثوابتُ قارئ الاختناقات ──
    /** عتبةُ ركود المهمة بالأيام — عتبةُ إشارة «مشروعٌ راكد» في مركز الفعل نفسُها */
    public const STALL_DAYS = 7;
    /** سقفُ قيود التدقيق المقروءة لمكوث الحالة **لكل وحدة** — يُعلَن في نص المنهجية */
    public const DWELL_SAMPLE_CAP = 2000;
    /** سقفُ التذاكر المقروءةِ عدّادَ ارتدادها — يُعلَن في نص المنهجية كذلك */
    public const REOPEN_SAMPLE_CAP = 200;
    /** سقفُ عيّنة الانتظار (اعتماداتٌ محسومة) وعيّنةِ نصوص المعوّقات */
    public const WAIT_SAMPLE_CAP = 500;

    /**
     * إسقاطُ المنشأة: عدّاداتُ التنفيذ كلُّها بتجميعٍ في القاعدة.
     * تُستدعى خلف `hub_monitor()` + `hub_org_analytics_guard()` — أرقامُها تجمع
     * عبر كل الشركات، فالحسابُ المعزول يُصَدّ في المتحكم قبل الوصول هنا.
     */
    public static function org(TimeRange $r, $user = null): array
    {
        $prev = $r->prev();
        $today = now()->toDateString();

        // ١) نشِطٌ اليوم — نبضةُ الجلسة (sessions_log.last_seen_at تُحدَّث مع كل
        //    طلب عبر SessionSentry) لا زياراتُ الصفحات: النبضةُ حضورٌ، والزياراتُ لا.
        $activeToday = (int) DB::table('sessions_log')
            ->where('last_seen_at', '>=', now()->startOfDay())
            ->distinct()->count('user_id');

        // ٢+٤) أُنجز في النافذة + الالتزامُ بالموعد — من completed_at حصراً،
        //    بتعريفٍ **واحدٍ مشترك** (`doneAgg`/`onTimePct`) تقرؤه هذه الشاشةُ
        //    ولوحُ التنفيذ (§6.5) معاً فلا يتباعد رقمان لمعنىً واحد.
        $dCur = self::doneAgg($r);
        $dPrev = self::doneAgg($prev);

        // ٣) متأخّرٌ الآن — لقطةُ اللحظة بتعريفٍ واحدٍ مشترك كذلك
        $overdue = self::overdueNow($today);

        // ٥) تذاكرُ مفتوحةٌ الآن
        $openTickets = (int) hub_open_scope(DB::table('tickets')->whereNull('deleted_at'))->count();

        // ٦) خرقُ SLA — تذاكرُ النافذة تُقاس بسياسة hub_sla نفسِها (أولُ ردٍّ غير
        //    داخليّ باستعلامٍ واحد — نمطُ SupportController). بسقفِ عيّنةٍ معلَن
        //    وترتيبٍ حتميّ كي لا يتبدل المعدود بين المحرّكين.
        $tk = DB::table('tickets')->whereNull('deleted_at')
            ->tap(fn ($q) => $r->apply($q))
            ->orderBy('created_at')->orderBy('id')->limit(self::SLA_SAMPLE_CAP)
            ->get(['id', 'created_at', 'updated_at', 'priority', 'status', 'meta']);
        $firsts = $tk->isEmpty() ? collect() : DB::table('comments')
            ->where('module', 'tickets')->whereIn('record_id', $tk->pluck('id'))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
            ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
            ->pluck('at', 'record_id');
        $slaBreaches = 0;
        foreach ($tk as $t) {
            $s = hub_sla($t, $firsts[$t->id] ?? null);
            if ($s['respLate'] || $s['resLate']) $slaBreaches++;
        }

        // ٧) مشاريعُ في خطر — صحةُ hub_project_health دون ٥٥ (عتبةُ ActionCenter
        //    نفسُها — لا عتبةَ ثانية). اختيارُ الأربعين حتميٌّ بـorderBy('id').
        $projects = hub_open_scope(DB::table('projects')->whereNull('deleted_at'))
            ->orderBy('id')->limit(self::HEALTH_SAMPLE_CAP)->get(['id', 'name']);
        $atRisk = [];
        foreach ($projects as $p) {
            $h = hub_project_health($p->id);
            if (($h['score'] ?? 100) < 55) {
                $atRisk[] = ['id' => $p->id, 'name' => $p->name, 'score' => (int) $h['score']];
            }
        }
        usort($atRisk, fn ($a, $b) => [$a['score'], $a['id']] <=> [$b['score'], $b['id']]);

        // ٨) اعتماداتٌ معلّقةٌ الآن
        $pendingApprovals = (int) hub_open_scope(
            DB::table('approvals')->whereNull('deleted_at'), 'status', self::APPROVAL_DECIDED)->count();

        return [
            'range' => $r,
            'active_today' => $activeToday,
            'completed' => hub_compare((float) $dCur->n, (float) $dPrev->n),
            'on_time' => [
                'pct' => self::onTimePct($dCur), 'prev_pct' => self::onTimePct($dPrev),
                'on_time' => (int) $dCur->on_time, 'with_due' => (int) $dCur->with_due,
            ],
            'overdue' => $overdue,
            'open_tickets' => $openTickets,
            'sla_breaches' => ['n' => $slaBreaches, 'of' => $tk->count(),
                'capped' => $tk->count() >= self::SLA_SAMPLE_CAP],
            'projects_at_risk' => ['rows' => $atRisk, 'n' => count($atRisk),
                'of' => $projects->count(), 'capped' => $projects->count() >= self::HEALTH_SAMPLE_CAP],
            'pending_approvals' => $pendingApprovals,
            'trend' => self::completionTrend($r),
            'departments' => self::departments($r, $today),
            'load' => self::loadBalance($r, $today),
        ];
    }

    /**
     * إسقاطُ الموظف الواحد (يستهلكه ملفُّ العمل والطور ٨) — الأرقامُ نفسُها
     * محصورةً بمهامه وتذاكره وما ينتظر اعتمادَه، بنفس قواعد الصدق: completed_at
     * للإنجاز، وhub_open_scope للمفتوح. `$userId` هو users.id (المهامُ تُسند إليه).
     *
     * وهي **قشرةٌ على `people()`** لا حسابٌ ثانٍ: بطاقةُ الموظف الواحد ولوحُ
     * الأداء يمرّان بالشيفرة نفسِها حرفياً، فلا ينزلق تعريفٌ عن نظيره.
     */
    public static function person(string $userId, TimeRange $r, $reader = null): array
    {
        return self::people([$userId], $r, $reader)[$userId] ?? self::blankPerson($r, $reader);
    }

    /**
     * إسقاطُ الأشخاص **دفعةً واحدة** (WP-7.3): الأرقامُ نفسُها لعشرات الموظفين
     * بثمانية استعلاماتٍ مُجمَّعةٍ في القاعدة لا باستعلامٍ لكل موظف — لوحُ الأداء
     * كان يستعلم ثلاث مرّاتٍ لكلٍّ من ثلاثين (٩٠ استعلاماً في فتحةٍ واحدة).
     *
     * **القارئ (`$reader`) يقرّر ما يُعَدّ:** إن مُرِّر، مرّ كلُّ استعلامٍ بـ
     * `hub_can` (وحدةٌ لا يراها ⇒ `null` لا صفرٌ كاذب) و`hub_scope` (شركةٌ أو
     * مشروعٌ خارج نطاقه لا يُعَدّ له). و`null` تعني إسقاطَ المنشأة كاملةً —
     * تُستدعى خلف `hub_org_analytics_guard()` وحدَها كما في `org()`.
     */
    public static function people(array $userIds, TimeRange $r, $reader = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $userIds), fn ($v) => $v !== '')));
        if (! $ids) return [];

        $may = fn (string $m) => $reader === null || hub_can($reader, $m, 'v');
        $scope = fn ($q, string $m) => $reader === null ? $q : hub_scope($q, $m, $reader);
        $today = now()->toDateString();

        $mayTasks = $may('tasks');
        $mayTickets = $may('tickets');
        $mayAppr = $may('approvals');
        $mayProj = $may('projects');

        $out = [];
        foreach ($ids as $id) $out[$id] = self::blankPerson($r, $reader);

        /* ── المهام: أُنجز والالتزام من ختم الإنجاز، والمفتوح/المتأخّر لقطةَ اللحظة ── */
        if ($mayTasks) {
            $rows = $scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                ->whereIn('assignee_id', $ids)->whereNotNull('completed_at')
                ->tap(fn ($q) => $r->apply($q, 'completed_at'))
                ->selectRaw('assignee_id, COUNT(*) n,
                    COALESCE(SUM(CASE WHEN due IS NOT NULL THEN 1 ELSE 0 END), 0) with_due,
                    COALESCE(SUM(CASE WHEN due IS NOT NULL AND DATE(completed_at) <= due THEN 1 ELSE 0 END), 0) on_time')
                ->groupBy('assignee_id')->get();
            foreach ($rows as $x) {
                $k = (string) $x->assignee_id;
                if (! isset($out[$k])) continue;
                $out[$k]['completed'] = (int) $x->n;
                $out[$k]['on_time'] = [
                    'pct' => (int) $x->with_due > 0
                        ? (int) round((int) $x->on_time * 100 / (int) $x->with_due) : null,
                    'on_time' => (int) $x->on_time, 'with_due' => (int) $x->with_due,
                ];
            }

            // «مشاريعُ يعمل عليها» تُشتقّ من المهام المفتوحة نفسِها — عمودٌ إضافيّ
            // في التجميع القائم لا استعلامٌ ثالث، ولا يُطلب لمن لا يرى المشاريع
            $sel = 'assignee_id, COUNT(*) open_n,
                COALESCE(SUM(CASE WHEN due IS NOT NULL AND due < ? THEN 1 ELSE 0 END), 0) late_n'
                . ($mayProj ? ', COUNT(DISTINCT project_id) proj_n' : '');
            $rows = hub_open_scope($scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                    ->whereIn('assignee_id', $ids))
                ->selectRaw($sel, [$today])->groupBy('assignee_id')->get();
            foreach ($rows as $x) {
                $k = (string) $x->assignee_id;
                if (! isset($out[$k])) continue;
                $out[$k]['open'] = (int) $x->open_n;
                $out[$k]['overdue'] = (int) $x->late_n;
                if ($mayProj) $out[$k]['projects_open'] = (int) $x->proj_n;
            }
        }

        /* ── التذاكر: المفتوحُ الآن، والمحلولُ في النافذة، وزمنُ الحل ── */
        if ($mayTickets) {
            $mine = fn () => $scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                ->whereIn('assignee_id', $ids);

            foreach (hub_open_scope($mine())->selectRaw('assignee_id, COUNT(*) n')
                        ->groupBy('assignee_id')->get() as $x) {
                $k = (string) $x->assignee_id;
                if (isset($out[$k])) $out[$k]['tickets_open'] = (int) $x->n;
            }

            // «حُلّت» من قاموس الحالات المنتهية الموحَّد لا من قائمةٍ حرفيةٍ رابعة:
            // مفرداتُ وحدة التذاكر المعلَنة تتقاطع معه عند «تم الحل/مغلقة» بالضبط.
            $closed = fn () => hub_closed_scope($mine())->tap(fn ($q) => $r->apply($q, 'updated_at'));
            foreach ($closed()->selectRaw('assignee_id, COUNT(*) n')
                        ->groupBy('assignee_id')->get() as $x) {
                $k = (string) $x->assignee_id;
                if (isset($out[$k])) $out[$k]['tickets_resolved'] = (int) $x->n;
            }

            // زمنُ الحل: الفرقُ بين لحظتين لا تحسبه لهجةٌ واحدةٌ على المحرّكين،
            // فعيّنةٌ **مسقوفةٌ معلَنة** بترتيبٍ حتميّ ثم حسابٌ في PHP. ونهايةُ
            // القياس ختمُ `meta.resolved_at` إن وُجد وإلا آخرُ تعديل — كما تقرؤه
            // شاشةُ الأداء اليوم حرفياً، فلا يتحرّك رقمٌ منشورٌ بالتوحيد.
            $rows = $closed()->orderBy('updated_at')->orderBy('id')->limit(self::PEOPLE_SAMPLE_CAP)
                ->get(['assignee_id', 'created_at', 'updated_at', 'meta']);
            $capped = $rows->count() >= self::PEOPLE_SAMPLE_CAP;
            $acc = [];
            foreach ($rows as $t) {
                $meta = json_decode((string) $t->meta, true) ?: [];
                $end = $meta['resolved_at'] ?? $t->updated_at;
                $h = abs(\Illuminate\Support\Carbon::parse($end)
                        ->diffInMinutes(\Illuminate\Support\Carbon::parse($t->created_at))) / 60;
                $k = (string) $t->assignee_id;
                $acc[$k] ??= ['n' => 0, 'sum' => 0.0];
                $acc[$k]['n']++;
                $acc[$k]['sum'] += $h;
            }
            foreach ($acc as $k => $a) {
                if (! isset($out[$k])) continue;
                $out[$k]['resolution'] = ['avg_h' => round($a['sum'] / $a['n'], 1),
                    'of' => $a['n'], 'capped' => $capped];
            }
        }

        /* ── الاعتمادات: ما حسمه بيده، وما ينتظر حسمَه الآن ── */
        if ($mayAppr) {
            // «حُسم» من `decided_by`/`decided_at` اللذين يختمهما مسارُ الحسم وحدَه
            // (الحالةُ حقلٌ مقفولٌ سجلّياً فلا تُزوَّر من نموذج CRUD)
            foreach ($scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')
                        ->whereIn('decided_by', $ids)->tap(fn ($q) => $r->apply($q, 'decided_at'))
                        ->selectRaw('decided_by, COUNT(*) n')->groupBy('decided_by')->get() as $x) {
                $k = (string) $x->decided_by;
                if (isset($out[$k])) $out[$k]['approvals_decided'] = (int) $x->n;
            }

            // المنتظِرُ حسمَه: معتمدٌ مباشرٌ **أو** ضمن سلسلة — والسلسلةُ نصُّ JSON
            // فلا تُجمَّع في القاعدة: عيّنةٌ مسقوفةٌ بترتيبٍ حتميّ ثم مطابقةٌ في PHP.
            $pend = hub_open_scope($scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals'),
                    'status', self::APPROVAL_DECIDED)
                ->where(function ($w) use ($ids) {
                    $w->whereIn('approver_id', $ids);
                    foreach ($ids as $i) $w->orWhere('chain', 'LIKE', '%"' . $i . '"%');
                })
                ->orderBy('created_at')->orderBy('id')->limit(self::PEOPLE_SAMPLE_CAP)
                ->get(['approver_id', 'chain']);
            $apprCapped = $pend->count() >= self::PEOPLE_SAMPLE_CAP;
            foreach ($ids as $i) {
                $out[$i]['approvals_capped'] = $apprCapped;
                $out[$i]['approvals_waiting'] = $pend->filter(fn ($a) => (string) $a->approver_id === $i
                    || str_contains((string) $a->chain, '"' . $i . '"'))->count();
            }
        }

        /* ── المشاريعُ المفتوحةُ التي يديرها ── */
        if ($mayProj) {
            foreach (hub_open_scope($scope(DB::table('projects')->whereNull('deleted_at'), 'projects'))
                        ->whereIn('manager_id', $ids)
                        ->selectRaw('manager_id, COUNT(*) n')->groupBy('manager_id')->get() as $x) {
                $k = (string) $x->manager_id;
                if (isset($out[$k])) $out[$k]['projects_managed'] = (int) $x->n;
            }
        }

        return $out;
    }

    /**
     * الشكلُ الصفريّ لإسقاط الشخص — **الحالةُ الفارغةُ الصادقة**: وحدةٌ لا يراها
     * القارئ تُعيد `null` لا صفراً (صفرٌ يقول «لا عمل»، وnull تقول «لا أرى»).
     */
    protected static function blankPerson(TimeRange $r, $reader = null): array
    {
        $may = fn (string $m) => $reader === null || hub_can($reader, $m, 'v');
        $t = $may('tasks'); $k = $may('tickets'); $a = $may('approvals'); $p = $may('projects');

        return [
            'range' => $r,
            'may' => ['tasks' => $t, 'tickets' => $k, 'approvals' => $a, 'projects' => $p],
            'open' => $t ? 0 : null,
            'overdue' => $t ? 0 : null,
            'completed' => $t ? 0 : null,
            'on_time' => ['pct' => null, 'on_time' => 0, 'with_due' => 0],
            'projects_open' => $t && $p ? 0 : null,
            'projects_managed' => $p ? 0 : null,
            'tickets_open' => $k ? 0 : null,
            'tickets_resolved' => $k ? 0 : null,
            'resolution' => ['avg_h' => null, 'of' => 0, 'capped' => false],
            'approvals_waiting' => $a ? 0 : null,
            'approvals_decided' => $a ? 0 : null,
            'approvals_capped' => false,
        ];
    }

    /**
     * **نشاطُ الموظف داخل النظام** (spec §5.3) — عملٌ لا مراقبة: أوّلُ وآخرُ ظهورٍ
     * وأيامٌ نشطة من **نبضة الجلسة** (`sessions_log`) لا من زيارات الصفحات (لا
     * يقرأ هذا الصنفُ جدولَ الزيارات إطلاقاً — يحرسه فحصُ مصدر)، و«الأفعالُ ذاتُ
     * المعنى» من التدقيق عبر `Audit::scopedQuery` — **نطاقُ التدقيق الموحّد**
     * (الوحدةُ والمشروعُ والشركةُ والعميل) لا مرشِّحٌ ثانٍ يُكتب هنا.
     *
     * ولا درجةَ أمنيةَ هنا بحال: تلك في `Risk::activity` وحدها ببطاقةٍ منفصلة
     * (WP-7.1 · spec §5.1) — فلا يُخلط خطرٌ أمنيٌّ برقم إنتاجية.
     */
    public static function personActivity(string $userId, TimeRange $r, $reader = null): array
    {
        $reader = $reader ?? auth()->user();

        // أوّلُ ظهورٍ من بدء الجلسات في النافذة، وآخرُه وأيامُه من النبضة —
        // كلٌّ بعموده كي لا تُنسب لحظةٌ خارج النافذة إلى داخلها
        $first = DB::table('sessions_log')->where('user_id', $userId)
            ->tap(fn ($q) => $r->apply($q, 'started_at'))->min('started_at');
        $seen = DB::table('sessions_log')->where('user_id', $userId)
            ->tap(fn ($q) => $r->apply($q, 'last_seen_at'))
            ->selectRaw('MAX(last_seen_at) last_at, COUNT(DISTINCT DATE(last_seen_at)) days')->first();

        $base = fn () => self::meaningfulAudits(
            Audit::scopedQuery($reader)->where('audits.user_id', $userId)
                ->tap(fn ($q) => $r->apply($q, 'audits.created_at')));

        return [
            'range' => $r,
            'first_at' => $first,
            'last_at' => $seen->last_at ?? null,
            'active_days' => (int) ($seen->days ?? 0),
            'actions' => [
                'n' => (int) $base()->count(),
                // أكثرُ الوحدات عملاً — الأكثرُ أولاً واسمُ الوحدة فاصلٌ حتميّ
                'rows' => $base()->selectRaw('audits.module m, COUNT(*) n')->groupBy('audits.module')
                    ->orderByRaw('COUNT(*) DESC')->orderBy('audits.module')->limit(8)->get()
                    ->map(fn ($x) => ['module' => (string) $x->m, 'n' => (int) $x->n])->all(),
            ],
        ];
    }

    /**
     * ترشيحُ «الأفعال ذات المعنى» — قائمةُ سماحٍ على الفعل مع اشتراط وحدةٍ مسمّاة:
     * قيدٌ بلا وحدةٍ عملٌ نظاميّ لا عملُ موظفٍ على بيانات. والدخولُ والاطّلاعُ
     * الحسّاس خارجَ القائمة بالبنية (لا استثناءٌ يُنسى).
     */
    protected static function meaningfulAudits($q)
    {
        return $q->whereNotNull('audits.module')->where(function ($w) {
            foreach (self::MEANINGFUL_ACTIONS as $verb) {
                $w->orWhere('audits.action', $verb)->orWhere('audits.action', 'LIKE', $verb . ' %');
            }
        });
    }

    /**
     * **الخطُّ الزمنيّ الشخصيّ** (spec §5.3 · §5.8): ما فعله الموظفُ من عملٍ
     * حقيقيّ — مهمّةٌ أُنجزت، وتذكرةٌ حُلّت، واعتمادٌ حُسم، وتعليقٌ كُتب، وقيدُ
     * تدقيقٍ ذو معنى — كلٌّ خلف صلاحية وحدته ونطاقها عند القارئ.
     *
     * **والزياراتُ الخام لا تُصبّ هنا أبداً** (spec §5.8): أثرُ التنقّل بين
     * الصفحات ليس عملاً، وسكبُه في ملفِّ موظفٍ مراقبةٌ شخصية لا قياسُ إنتاجية.
     * من أرادَه فمركزُ النشاط للمالك وحدَه، مطويّاً هناك.
     */
    public static function personTimeline(string $userId, TimeRange $r, $reader = null, int $limit = 20): array
    {
        $reader = $reader ?? auth()->user();
        $may = fn (string $m) => $reader === null || hub_can($reader, $m, 'v');
        $scope = fn ($q, string $m) => $reader === null ? $q : hub_scope($q, $m, $reader);

        $ev = [];
        $add = function ($at, string $ico, string $label, string $title, ?string $url = null) use (&$ev) {
            if (! $at) return;
            $ev[] = ['at' => (string) $at, 'ico' => $ico, 'label' => $label,
                     'title' => (string) $title, 'url' => $url];
        };

        if ($may('tasks')) {
            foreach ($scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                        ->where('assignee_id', $userId)->whereNotNull('completed_at')
                        ->tap(fn ($q) => $r->apply($q, 'completed_at'))
                        ->orderByDesc('completed_at')->orderByDesc('id')->limit($limit)
                        ->get(['id', 'title', 'completed_at']) as $t) {
                $add($t->completed_at, '✅', 'مهمة أُنجزت', $t->title, route('m.show', ['tasks', $t->id]));
            }
        }

        if ($may('tickets')) {
            foreach (hub_closed_scope($scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                        ->where('assignee_id', $userId))
                        ->tap(fn ($q) => $r->apply($q, 'updated_at'))
                        ->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)
                        ->get(['id', 'subject', 'updated_at']) as $t) {
                $add($t->updated_at, '🎫', 'تذكرة حُلّت', $t->subject, route('m.show', ['tickets', $t->id]));
            }
        }

        if ($may('approvals')) {
            foreach ($scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')
                        ->where('decided_by', $userId)->whereNotNull('decided_at')
                        ->tap(fn ($q) => $r->apply($q, 'decided_at'))
                        ->orderByDesc('decided_at')->orderByDesc('id')->limit($limit)
                        ->get(['id', 'title', 'status', 'decided_at']) as $a) {
                $add($a->decided_at, '🔏', 'اعتماد حُسم',
                    trim($a->title . ($a->status ? ' — ' . $a->status : '')),
                    route('m.show', ['approvals', $a->id]));
            }
        }

        // التعليقاتُ: على وحداتٍ يراها القارئ وحدَها (التعليقُ يحمل مفتاحَ وحدته)
        $visible = array_values(array_filter(array_keys(hub_modules()),
            fn ($m) => $reader === null || hub_can($reader, $m, 'v')));
        if ($visible) {
            foreach (DB::table('comments')->whereNull('deleted_at')
                        ->where('user_id', $userId)->whereIn('module', $visible)
                        ->tap(fn ($q) => $r->apply($q))
                        ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
                        ->get(['id', 'module', 'record_id', 'body', 'created_at']) as $c) {
                $add($c->created_at, '💬', 'تعليق',
                    \Illuminate\Support\Str::limit((string) $c->body, 90),
                    $c->record_id ? route('m.show', [$c->module, $c->record_id]) : null);
            }
        }

        // قيودُ التدقيق ذاتُ المعنى — بنطاق التدقيق الموحّد لا بمرشِّحٍ ثانٍ
        $icons = ['إضافة' => '🌱', 'تعديل' => '✏️', 'حذف' => '🗑️', 'تصدير' => '📤'];
        foreach (self::meaningfulAudits(
                    Audit::scopedQuery($reader)->where('audits.user_id', $userId)
                        ->tap(fn ($q) => $r->apply($q, 'audits.created_at')))
                    ->orderByDesc('audits.created_at')->orderByDesc('audits.id')->limit($limit)
                    ->get(['audits.action', 'audits.module', 'audits.record_id',
                           'audits.name', 'audits.created_at']) as $a) {
            $verb = explode(' ', trim((string) $a->action))[0];
            $add($a->created_at, $icons[$verb] ?? '📌', (string) $a->action,
                (string) ($a->name ?: hub_mod($a->module)['label'] ?? ''),
                $a->record_id && hub_mod($a->module) ? route('m.show', [$a->module, $a->record_id]) : null);
        }

        // الأحدثُ أولاً، والتسميةُ فالعنوانُ فاصلان حتميّان عند تساوي اللحظة
        usort($ev, fn ($x, $y) => [$y['at'], $x['label'], $x['title']] <=> [$x['at'], $y['label'], $y['title']]);

        return array_slice($ev, 0, $limit);
    }

    /**
     * اتجاهُ الإنجاز: مهامٌ أُنجزت لكل يومٍ في النافذة — تجميعٌ واحدٌ في القاعدة
     * ثم ملءُ الأيام الصامتة بصفرٍ **حقيقيّ** (يومٌ بلا إنجازٍ صفرٌ صادق — بخلاف
     * مقياسٍ لم يُقَس). نافذةٌ أطولُ من ٤٠ يوماً تُضَمّ أيامُها حزماً متساويةً
     * كي لا يقصّ الرسمُ أولَها (hub_metric_spark يأخذ آخر ٤٠ نقطة فقط).
     */
    protected static function completionTrend(TimeRange $r): array
    {
        $byDay = DB::table('tasks')->whereNull('deleted_at')
            ->whereNotNull('completed_at')->tap(fn ($q) => $r->apply($q, 'completed_at'))
            ->selectRaw('DATE(completed_at) d, COUNT(*) n')
            ->groupBy('d')->orderBy('d')->pluck('n', 'd');

        $days = [];
        for ($d = $r->from->copy()->startOfDay(); $d->lt($r->to); $d->addDay()) {
            $days[] = ['at' => $d->copy(), 'value' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }
        if (count($days) <= 40) return $days;

        // حزمُ أيامٍ متساوية: القيمةُ مجموعُ الحزمة والتاريخُ أولُ أيامها
        $per = (int) ceil(count($days) / 40);
        $out = [];
        foreach (array_chunk($days, $per) as $chunk) {
            $out[] = ['at' => $chunk[0]['at'], 'value' => array_sum(array_column($chunk, 'value'))];
        }

        return $out;
    }

    /**
     * لوحُ الأقسام — القسمُ من **ملفّ الموظف** (`employees.dept` عبر
     * `employees.user_id`) لا من `tasks.dept`: قائمتاهما مختلفتان، وعمودُ المهمة
     * إدخالٌ حرٌّ لا يُطابق الهيكل. تجميعان اثنان في القاعدة + عدُّ الرؤوس.
     */
    protected static function departments(TimeRange $r, string $today): array
    {
        $join = fn () => DB::table('tasks')
            ->join('employees', 'employees.user_id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')->whereNull('employees.deleted_at');

        // المفتوحُ والمتأخّرُ لكل قسم — sum(case) لا استعلامَين لكل قسم
        $open = hub_open_scope($join(), 'tasks.status')
            ->selectRaw("COALESCE(employees.dept, '') d, COUNT(*) open_n,
                COALESCE(SUM(CASE WHEN tasks.due IS NOT NULL AND tasks.due < ? THEN 1 ELSE 0 END), 0) late_n", [$today])
            ->groupBy('d')->get()->keyBy('d');

        // المنجَزُ في النافذة والتزامُه — من completed_at حصراً
        $done = $join()
            ->whereNotNull('tasks.completed_at')->tap(fn ($q) => $r->apply($q, 'tasks.completed_at'))
            ->selectRaw("COALESCE(employees.dept, '') d, COUNT(*) done_n,
                COALESCE(SUM(CASE WHEN tasks.due IS NOT NULL THEN 1 ELSE 0 END), 0) with_due,
                COALESCE(SUM(CASE WHEN tasks.due IS NOT NULL AND DATE(tasks.completed_at) <= tasks.due THEN 1 ELSE 0 END), 0) on_time")
            ->groupBy('d')->get()->keyBy('d');

        // رؤوسُ القسم — الموظفون على رأس العمل (مرشِّحُ hub_capacity نفسُه)
        $heads = DB::table('employees')->whereNull('deleted_at')
            ->whereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف'])
            ->selectRaw("COALESCE(dept, '') d, COUNT(*) n")->groupBy('d')->pluck('n', 'd');

        $rows = [];
        foreach (collect($heads->keys())->merge($open->keys())->merge($done->keys())->unique() as $d) {
            $dn = $done[$d] ?? null;
            $rows[] = [
                'dept' => (string) $d,
                'heads' => (int) ($heads[$d] ?? 0),
                'open' => (int) ($open[$d]->open_n ?? 0),
                'overdue' => (int) ($open[$d]->late_n ?? 0),
                'done' => (int) ($dn->done_n ?? 0),
                'on_time_pct' => $dn && (int) $dn->with_due > 0
                    ? (int) round((int) $dn->on_time * 100 / (int) $dn->with_due) : null,
            ];
        }
        // ترتيبٌ حتميٌّ افتراضيّ: الأكثرُ مهامّاً مفتوحةً أولاً ثم اسمُ القسم
        usort($rows, fn ($a, $b) => [$b['open'], $a['dept']] <=> [$a['open'], $b['dept']]);

        return $rows;
    }

    /**
     * ميزانُ الحِمل — من صفوف `hub_capacity` نفسِها (لا حسابَ طاقةٍ ثانٍ):
     * فوق الطاقة، وبلا تكليف، وتأخّرٌ كثيف، وتشتّتُ التوزيع — إشاراتُ اختلالٍ
     * لا ترتيبَ جدارة. ولا أثرَ لعدد الزيارات هنا إطلاقاً (spec §5.5).
     */
    protected static function loadBalance(TimeRange $r, string $today): array
    {
        $cap = hub_capacity($r->from->toDateString(), $r->to->toDateString());
        $rows = collect($cap['rows']);

        // تأخّرٌ كثيف: مهامٌ مفتوحةٌ فائتةُ الموعد لكل مكلَّف — تجميعٌ واحد
        $lateBy = hub_open_scope(DB::table('tasks')->whereNull('deleted_at'))
            ->whereNotNull('assignee_id')->whereNotNull('due')->where('due', '<', $today)
            ->selectRaw('assignee_id, COUNT(*) n')->groupBy('assignee_id')
            ->orderByRaw('COUNT(*) DESC')->orderBy('assignee_id')->get();
        // hub_capacity لا يُخرج user_id في صفوفه — الاسمُ من ملفات الموظفين مباشرة
        $emp = DB::table('employees')->whereNull('deleted_at')->whereNotNull('user_id')
            ->whereIn('user_id', $lateBy->pluck('assignee_id'))
            ->orderBy('id')->get(['user_id', 'name'])->keyBy('user_id');
        $heavy = $lateBy->filter(fn ($x) => (int) $x->n >= self::HEAVY_OVERDUE_MIN)
            ->map(fn ($x) => ['name' => $emp->get($x->assignee_id)->name ?? 'حسابٌ بلا ملفّ موظف',
                'n' => (int) $x->n])->values()->all();

        $loads = $rows->pluck('load')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v);

        return [
            'from' => $cap['from'], 'to' => $cap['to'],
            'over' => $rows->filter(fn ($x) => ($x['load'] ?? 0) > 100)
                ->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name'], 'load' => (int) $x['load']])
                ->values()->all(),
            'idle' => $rows->filter(fn ($x) => ($x['linked'] ?? false) && ($x['tasks'] ?? 0) === 0)
                ->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name']])->values()->all(),
            'heavy_overdue' => $heavy,
            'unlinked' => (int) ($cap['totals']['unlinked'] ?? 0),
            'spread' => $loads->isEmpty() ? null
                : ['max' => $loads->max(), 'min' => $loads->min(), 'gap' => $loads->max() - $loads->min()],
        ];
    }

    // ════════ Control Plane: Phase 7 (WP-7.4) — قارئُ الاختناقات ════════

    /**
     * أين الاختناق؟ (spec §5.7) — قراءةٌ واحدة تجمع ما هو **موجودٌ فعلاً**:
     * مراحلُ الانتظار من الأعمدة القائمة (اعتمادٌ `created_at→decided_at`،
     * تذاكرُ «بانتظار العميل»، مهامُّ «متوقفة»)، ومكوثُ الحالة من قيود التدقيق
     * (`audits.after.status`)، وأكثرُ المعوّقات تكراراً من `work_updates.problems`
     * و`tasks.late_reason`، ومهامُّ راكدة، وإعادةُ الفتح من عدّاد `meta.reopened`
     * الذي يختمه ModuleController — وإشاراتُ مركز الفعل (`proj.stalled`،
     * `proj.blockers`، `sla.breach`) **تُقرأ كما هي لا يُعاد حسابُها** فتتّفق
     * الشاشتان على المفتاح الواحد. لا جدولَ جديداً ولا محرّكَ إشاراتٍ ثانياً.
     */
    public static function bottlenecks(TimeRange $r): array
    {
        return [
            'waiting'  => self::waitingStages($r),
            'dwell'    => self::statusDwell($r),
            'blockers' => self::topBlockers($r),
            'stalled'  => self::stalledTasks(),
            'reopened' => self::reopenedTickets(),
            'signals'  => self::actionSignals(),
        ];
    }

    /**
     * مراحلُ الانتظار — من الأعمدة القائمة لا من عدّادٍ جديد:
     * الاعتمادُ ينتظر من إنشائه حتى حسمه (`decided_at` يختمه مسارُ الاعتماد
     * وحدَه)، والتذكرةُ «بانتظار العميل» والمهمةُ «متوقفة» انتظارٌ باسمه —
     * عمرُهما من آخر تحديثٍ (لحظةُ دخول الحالة تقريباً، والمكوثُ الدقيق أدناه).
     */
    protected static function waitingStages(TimeRange $r): array
    {
        // اعتماداتٌ حُسمت في النافذة: الانتظارُ = decided_at − created_at،
        // بعيّنةٍ مسقوفةٍ معلَنة وترتيبٍ حتميّ (الأقدمُ حسماً أولاً ثم id)
        $decided = DB::table('approvals')->whereNull('deleted_at')
            ->whereNotNull('decided_at')->tap(fn ($q) => $r->apply($q, 'decided_at'))
            ->orderBy('decided_at')->orderBy('id')->limit(self::WAIT_SAMPLE_CAP)
            ->get(['created_at', 'decided_at']);
        $sum = 0.0; $max = 0.0;
        foreach ($decided as $a) {
            $h = max(0, \Illuminate\Support\Carbon::parse($a->created_at)
                ->diffInSeconds(\Illuminate\Support\Carbon::parse($a->decided_at))) / 3600;
            $sum += $h;
            if ($h > $max) $max = $h;
        }

        // المعلّقُ الآن + عمرُ أقدمِه — لقطةُ اللحظة لا نافذة
        $pendingQ = fn () => hub_open_scope(
            DB::table('approvals')->whereNull('deleted_at'), 'status', self::APPROVAL_DECIDED);
        $pending = (int) $pendingQ()->count();
        $oldestAt = $pendingQ()->min('created_at');

        $ageDays = fn ($at) => $at === null ? null
            : (int) \Illuminate\Support\Carbon::parse($at)->diffInDays(now());

        // تذاكرُ «بانتظار العميل» ومهامُّ «متوقفة» الآن — الحالتان مفردتا وحدتيهما
        // في سجل الوحدات (config/hub.php) لا قائمةٌ حرّةٌ جديدة
        $tw = DB::table('tickets')->whereNull('deleted_at')->where('status', 'بانتظار العميل');
        $tp = DB::table('tasks')->whereNull('deleted_at')->where('status', self::PAUSED_STATUS);

        return [
            'approvals' => [
                'decided_n' => $decided->count(),
                'avg_h' => $decided->isEmpty() ? null : round($sum / $decided->count(), 1),
                'max_h' => $decided->isEmpty() ? null : round($max, 1),
                'capped' => $decided->count() >= self::WAIT_SAMPLE_CAP,
                'pending' => $pending,
                'oldest_days' => $ageDays($oldestAt),
            ],
            'tickets_waiting' => ['n' => (int) (clone $tw)->count(),
                'oldest_days' => $ageDays((clone $tw)->min('updated_at'))],
            'tasks_paused' => ['n' => (int) (clone $tp)->count(),
                'oldest_days' => $ageDays((clone $tp)->min('updated_at'))],
        ];
    }

    /**
     * مكوثُ الحالة من قيود التدقيق — بلا عمودٍ ولا جدولٍ جديد: كلُّ قيدٍ يحمل
     * `after.status` هو لحظةُ **دخول** السجل تلك الحالةَ (الإنشاءُ يحملها كاملةً
     * والتعديلُ عند تغيّرها فقط)، فالمكوثُ فاصلُ ما بين قيدين متتاليين على السجل
     * نفسِه، والأخيرُ يُقاس مفتوحاً حتى الآن — إلا حالةً **منتهية** (قاموسُ
     * `hub_closed_states`): سجلٌّ يرتاح في «منجزة» ليس منتظِراً، وعدُّه يجعل
     * أقدمَ منجزةٍ «أطولَ اختناق». النافذةُ محدودةٌ وسقفُ العيّنة معلَن،
     * والاستعلامُ على فهرس (module, created_at) القائم، وJSON path يعمل على
     * المحرّكين (json_extract / JSON_EXTRACT).
     *
     * **والسقفُ لكل وحدةٍ على حدة، لا سقفٌ واحدٌ مشترك.** كان استعلاماً واحداً
     * مرتَّباً بالوحدة ثم مسقوفاً، فأكثرُ الوحدتين قيوداً يبتلع السقفَ كلَّه
     * و**تختفي الأخرى من الجدول بلا كلمة**: منشأةٌ نشطةٌ على المهامّ لا ترى
     * اختناقَ تذاكرها أبداً، والشاشةُ تقول «لا شيء» وهي لم تنظر. الغيابُ
     * الصامتُ أسوأُ من الرقم الناقص — فاستعلامٌ لكلِّ وحدةٍ بسقفها (اثنان،
     * كلاهما على الفهرس نفسِه) وترتيبٌ حتميٌّ داخل كلٍّ.
     */
    protected static function statusDwell(TimeRange $r): array
    {
        $rows = collect();
        $capped = false;
        foreach (['tasks', 'tickets'] as $mod) {
            $part = DB::table('audits')
                ->where('module', $mod)
                ->whereNotNull('after->status')
                ->tap(fn ($q) => $r->apply($q))
                // ترتيبٌ حتميٌّ كامل: تسلسلُ كل سجلٍ زمنياً وid فاصلاً لقيدين في الثانية نفسها
                ->orderBy('record_id')->orderBy('created_at')->orderBy('id')
                ->limit(self::DWELL_SAMPLE_CAP)
                ->get(['module', 'record_id', 'created_at', 'after']);
            $capped = $capped || $part->count() >= self::DWELL_SAMPLE_CAP;
            $rows = $rows->concat($part);
        }

        $agg = [];
        $now = now();
        $closed = hub_closed_states();
        foreach ($rows->groupBy(fn ($a) => $a->module . '|' . $a->record_id) as $seq) {
            $seq = $seq->values();
            foreach ($seq as $i => $row) {
                $st = trim((string) ((json_decode((string) $row->after, true) ?: [])['status'] ?? ''));
                if ($st === '') continue;
                $isLast = ! isset($seq[$i + 1]);
                // الفاصلُ الأخير مفتوحٌ حتى الآن — إلا حالةً منتهيةً فلا انتظارَ فيها
                if ($isLast && in_array($st, $closed, true)) continue;
                $from = \Illuminate\Support\Carbon::parse($row->created_at);
                $to = $isLast ? $now : \Illuminate\Support\Carbon::parse($seq[$i + 1]->created_at);
                $h = max(0, $from->diffInSeconds($to)) / 3600;

                $k = $row->module . '|' . $st;
                $agg[$k] ??= ['module' => $row->module, 'status' => $st,
                    'n' => 0, 'sum' => 0.0, 'max' => 0.0, 'open' => 0];
                $agg[$k]['n']++;
                $agg[$k]['sum'] += $h;
                if ($h > $agg[$k]['max']) $agg[$k]['max'] = $h;
                if ($isLast) $agg[$k]['open']++;
            }
        }

        $out = [];
        foreach ($agg as $a) {
            $out[] = ['module' => $a['module'], 'status' => $a['status'], 'n' => $a['n'],
                'open' => $a['open'], 'avg_h' => round($a['sum'] / $a['n'], 1), 'max_h' => round($a['max'], 1)];
        }
        // الأطولُ مكوثاً أولاً — والاسمُ فاصلٌ حتميّ عند التساوي
        usort($out, fn ($a, $b) => [$b['avg_h'], $a['module'], $a['status']] <=> [$a['avg_h'], $b['module'], $b['status']]);

        return ['rows' => $out, 'sample' => $rows->count(), 'capped' => $capped];
    }

    /**
     * أكثرُ المعوّقات تكراراً — من النصوص التي كتبها الفريقُ فعلاً:
     * `work_updates.problems` (بندُ «ما المشكلات؟» في التقرير اليومي) و
     * `tasks.late_reason` (سببُ التأخير على المهمة). نصٌّ حرٌّ فيُطبَّع تطبيعاً
     * خفيفاً (قصُّ المسافات وضمُّها) ثم يُجمَع بالتطابق — لا استنتاجَ دلاليّاً
     * يدّعي ما لم يُكتب. العيّنةُ مسقوفةٌ والأحدثُ أولاً بترتيبٍ حتميّ.
     */
    protected static function topBlockers(TimeRange $r): array
    {
        $counts = [];
        $push = function ($text, string $src) use (&$counts) {
            $t = trim(preg_replace('/\s+/u', ' ', (string) $text));
            if ($t === '') return;
            $t = mb_substr($t, 0, 140);
            $k = $src . '|' . $t;
            $counts[$k] ??= ['text' => $t, 'source' => $src, 'n' => 0];
            $counts[$k]['n']++;
        };

        $capped = false;
        if (\Illuminate\Support\Facades\Schema::hasTable('work_updates')) {
            $wu = DB::table('work_updates')->whereNull('deleted_at')
                ->whereNotNull('problems')->whereRaw("TRIM(problems) <> ''")
                ->tap(fn ($q) => $r->apply($q))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::WAIT_SAMPLE_CAP)
                ->pluck('problems');
            $capped = $capped || $wu->count() >= self::WAIT_SAMPLE_CAP;
            foreach ($wu as $p) $push($p, 'تقارير العمل');
        }

        // أسبابُ تأخيرٍ دُوِّنت أو حُدِّثت في النافذة (العمودُ بلا ختمِ وقتٍ خاص —
        // updated_at أصدقُ المتاح، ويُصرَّح بذلك في نص المنهجية)
        $lr = DB::table('tasks')->whereNull('deleted_at')
            ->whereNotNull('late_reason')->whereRaw("TRIM(late_reason) <> ''")
            ->tap(fn ($q) => $r->apply($q, 'updated_at'))
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::WAIT_SAMPLE_CAP)
            ->pluck('late_reason');
        $capped = $capped || $lr->count() >= self::WAIT_SAMPLE_CAP;
        foreach ($lr as $p) $push($p, 'أسباب تأخير المهام');

        $rows = array_values($counts);
        // الأكثرُ تكراراً أولاً ثم النصُّ فالمصدرُ فاصلَين حتميَّين
        usort($rows, fn ($a, $b) => [$b['n'], $a['text'], $a['source']] <=> [$a['n'], $b['text'], $b['source']]);

        return ['rows' => array_slice($rows, 0, 8), 'total' => count($rows), 'capped' => $capped];
    }

    /**
     * مهامُّ راكدة — مفتوحةٌ (قاموسُ `hub_open_scope`) ولم تُمَسّ منذ العتبة.
     * «متوقفة» تُستثنى: لها بطاقةُ انتظارٍ باسمها أعلاه فلا تُعَدّ مرتين.
     * لقطةُ اللحظة لا نافذة — الراكدُ راكدٌ أياً كانت الكبسولة المختارة.
     */
    protected static function stalledTasks(): array
    {
        $thr = now()->subDays(self::STALL_DAYS);
        $q = fn () => hub_open_scope(DB::table('tasks')->whereNull('deleted_at'))
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '<>', self::PAUSED_STATUS))
            ->where('updated_at', '<', $thr);

        $n = (int) $q()->count();
        $rows = $q()->orderBy('updated_at')->orderBy('id')->limit(8)
            ->get(['id', 'title', 'assignee_id', 'updated_at']);
        $names = hub_ref_labels('users', $rows->pluck('assignee_id')->filter()->all());

        return [
            'n' => $n,
            'threshold_days' => self::STALL_DAYS,
            'rows' => $rows->map(fn ($t) => [
                'id' => $t->id, 'title' => (string) $t->title,
                'assignee' => $t->assignee_id ? ($names[$t->assignee_id] ?? '—') : null,
                'days' => (int) \Illuminate\Support\Carbon::parse($t->updated_at)->diffInDays(now()),
            ])->values()->all(),
        ];
    }

    /**
     * إعادةُ الفتح — من عدّاد `meta.reopened` الذي يختمه ModuleController لحظةَ
     * مسحِ `meta.resolved_at` (كان المسحُ صامتاً فلا أثرَ للارتداد إطلاقاً).
     * لقطةُ اللحظة: العدّادُ تراكميٌّ بلا ختمِ وقتٍ لكل ارتداد — ويُصرَّح بذلك.
     *
     * والعدّادُ في `meta` نصّاً فلا يُجمَع في القاعدة بلهجةٍ واحدةٍ على المحرّكين:
     * عيّنةٌ مسقوفةٌ **مُعلَنة** (`capped`) كأخواتها في هذا القارئ — «٣ مرات على
     * ٣ تذاكر» تُقرأ حصراً وهي عيّنة، والرقمُ الناقصُ الذي يبدو كاملاً أخطرُ من
     * الغائب لأنّ قارئَه لا يعرف أن يشكّ فيه.
     */
    protected static function reopenedTickets(): array
    {
        $rows = DB::table('tickets')->whereNull('deleted_at')
            ->whereNotNull('meta->reopened')
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::REOPEN_SAMPLE_CAP)
            ->get(['id', 'subject', 'meta']);

        $list = [];
        $total = 0;
        foreach ($rows as $t) {
            $c = (int) ((json_decode((string) $t->meta, true) ?: [])['reopened'] ?? 0);
            if ($c < 1) continue;
            $total += $c;
            $list[] = ['id' => $t->id, 'subject' => (string) $t->subject, 'n' => $c];
        }
        usort($list, fn ($a, $b) => [$b['n'], $a['id']] <=> [$a['n'], $b['id']]);

        return ['n' => count($list), 'total' => $total, 'rows' => array_slice($list, 0, 5),
            'capped' => $rows->count() >= self::REOPEN_SAMPLE_CAP];
    }

    /**
     * إشاراتُ مركز الفعل القائمة — **تُقرأ ولا يُعاد حسابُها** (فتتّفق الشاشتان
     * على المفتاح الواحد): مشاريعُ راكدة وحواجبُ مبلَّغة وخرقُ SLA من
     * `ActionCenter::liveByKey` (المنتِجُ منطَّقٌ بـhub_scope/hub_can أصلاً،
     * وهذه الشاشةُ خلف حارس المنشأة فقارئُها يرى الصفَّ الكامل).
     */
    protected static function actionSignals(): array
    {
        $out = ['proj.stalled' => [], 'proj.blockers' => [], 'sla.breach' => []];
        try {
            foreach (ActionCenter::liveByKey() as $key => $s) {
                foreach (array_keys($out) as $prefix) {
                    if (str_starts_with((string) $key, $prefix . ':')) {
                        $out[$prefix][] = ['key' => $key, 'sev' => $s['sev'] ?? '',
                            'title' => (string) ($s['title'] ?? ''), 'why' => (string) ($s['why'] ?? ''),
                            'url' => $s['url'] ?? null];
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $out;
    }

    // ════════ Control Plane: Phase 8 (WP-8.4) — تحليلاتُ التنفيذ ════════

    /**
     * **لوحُ التنفيذ كاملاً** (§6.5–§6.8 + §6.12): قراءةٌ واحدة يستهلكها تبويبُ
     * «التنفيذ» في مركز الجودة — عدّاداتُ العمل، وجدولُ المشاريع بالجملة،
     * وتدفّقُ المهامّ، وجودةُ التذاكر، واتّجاهُ اللقطة اليومية.
     *
     * تُستدعى خلف `hub_monitor()` + `hub_org_analytics_guard()` كإسقاط المنشأة
     * (`org`) حرفياً: أرقامُها تجمع عبر الشركات كلِّها بلا تنطيق، فالحسابُ
     * المعزول يُصَدّ في المتحكّم قبل الوصول هنا.
     */
    public static function center(TimeRange $r): array
    {
        return [
            'range' => $r,
            'summary' => self::executionSummary($r),
            'projects' => self::projectExecution($r),
            'flow' => self::taskFlow($r),
            'tickets' => self::ticketQuality($r),
            'history' => self::history(),
        ];
    }

    /**
     * §6.5 — عدّاداتُ التنفيذ: مخطّطٌ ومنجَزٌ ومفتوحٌ ومتأخّرٌ ومتوقّفٌ
     * والإنجاز٪ والالتزام٪ والإنتاجية. **كلُّ رقمٍ يساوي استعلامَه المرجعيّ**
     * ولا رقمَ مركّباً بلا رياضيات (§6.1):
     *
     *  - **المخطَّط** = المهامُّ التي يقع موعدُها (`due`) داخل أيام النافذة —
     *    خطّةُ النافذة كما كُتبت، بأي حالةٍ كانت. و**الإنجاز٪** يُقاس على هذه
     *    المجموعة نفسِها (كم منها خُتم إنجازُه) لا بقسمةِ مجموعتين مختلفتين
     *    على بعضهما — فالنسبةُ تعني ما تقول.
     *  - **المنجَز** = ما خُتم `completed_at` داخل النافذة (تعريفُ `org` نفسُه).
     *  - **المفتوح/المتأخّر/المتوقّف** لقطاتُ اللحظة لا نوافذ: الراكدُ اليومَ
     *    راكدٌ أيّاً كانت الكبسولة المختارة.
     *  - **الإنتاجية** = المنجَزُ ÷ أيام النافذة (كسريّةً) — لا استقراءَ ولا تنعيم.
     */
    public static function executionSummary(TimeRange $r): array
    {
        $today = now()->toDateString();
        [$lo, $hi] = self::dueBounds($r);

        // المخطَّط وما أُنجز منه — تجميعٌ واحد على فهرس `tasks(due)`
        $plan = DB::table('tasks')->whereNull('deleted_at')
            ->whereNotNull('due')->where('due', '>=', $lo)->where('due', '<', $hi)
            ->selectRaw('COUNT(*) n,
                COALESCE(SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END), 0) done')
            ->first();
        $planN = (int) ($plan->n ?? 0);
        $planDone = (int) ($plan->done ?? 0);

        $d = self::doneAgg($r);
        $completed = (int) $d->n;

        return [
            'range' => $r,
            // بلا خطّةٍ لا نسبةَ إنجاز: `null` تقول «لا قياس»، والصفرُ يقول «فشلٌ تامّ»
            'planned' => ['n' => $planN, 'done' => $planDone,
                'pct' => $planN > 0 ? (int) round($planDone * 100 / $planN) : null],
            'completed' => $completed,
            'open' => (int) self::openTasks()->count(),
            'overdue' => self::overdueNow($today),
            'blocked' => (int) DB::table('tasks')->whereNull('deleted_at')
                ->where('status', self::PAUSED_STATUS)->count(),
            'on_time' => ['pct' => self::onTimePct($d),
                'on_time' => (int) $d->on_time, 'with_due' => (int) $d->with_due],
            'throughput' => self::throughput($completed, $r),
        ];
    }

    /**
     * §6.6 — جدولُ تنفيذ المشاريع **بتجميعٍ بالجملة**: أربعةُ استعلاماتٍ للجدول
     * كلِّه مهما كثرت المشاريع. و`hub_project_health` **لا تُستدعى هنا**: سبعةُ
     * استعلاماتٍ لكل صفٍّ تعني مئتين لثلاثين مشروعاً — والصحّةُ المركّبة لها
     * مكانُها في بطاقة المشروع لا في جدولٍ عريض.
     *
     * وعمودُ المعوّقات من تجميع `work_updates.problems` القائم (ما كتبه الفريقُ
     * فعلاً) لا من استنتاجٍ دلاليّ. والخطرُ أعلامٌ معدودةٌ بقاعدةٍ مكتوبة
     * (`RISK_RULE`) لا درجةٌ مخترعة.
     */
    public static function projectExecution(TimeRange $r): array
    {
        $today = now()->toDateString();
        $empty = ['rows' => [], 'n' => 0, 'of' => 0, 'capped' => false,
                  'cap' => self::PROJECTS_TABLE_CAP, 'risk_rule' => self::RISK_RULE];

        // ١) المشاريعُ المفتوحة — عيّنةٌ **معلَنةُ المعنى** (أحدثُها) بترتيبٍ حتميّ
        $projects = hub_open_scope(DB::table('projects')->whereNull('deleted_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::PROJECTS_TABLE_CAP)
            ->get(['id', 'name', 'status', 'progress', 'manager_id']);
        if ($projects->isEmpty()) return $empty;
        $ids = $projects->pluck('id')->all();

        // ٢) تجميعُ المهامّ لكل مشروع في استعلامٍ واحد: الإجمالي، والمنجَزُ في
        //    النافذة، والمتأخّرُ الآن، والمتوقّفُ الآن. «المفتوح» داخل CASE يُبنى
        //    من قاموس hub_closed_states نفسِه لا من قائمةٍ حرفيةٍ ثانية.
        $closed = hub_closed_states();
        $openExpr = 'status IS NULL OR status NOT IN (' . implode(',', array_fill(0, count($closed), '?')) . ')';
        $tasks = DB::table('tasks')->whereNull('deleted_at')->whereIn('project_id', $ids)
            ->selectRaw(
                'project_id, COUNT(*) n,
                 COALESCE(SUM(CASE WHEN completed_at IS NOT NULL AND completed_at >= ? AND completed_at < ? THEN 1 ELSE 0 END), 0) done_n,
                 COALESCE(SUM(CASE WHEN (' . $openExpr . ') AND due IS NOT NULL AND due < ? THEN 1 ELSE 0 END), 0) late_n,
                 COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) paused_n',
                array_merge([$r->from->toDateTimeString(), $r->to->toDateTimeString()],
                    $closed, [$today, self::PAUSED_STATUS]))
            ->groupBy('project_id')->get()->keyBy('project_id');

        // ٣) المعوّقاتُ المبلَّغة في النافذة — تجميعُ `work_updates.problems` القائم
        $blockers = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('work_updates')) {
            $blockers = DB::table('work_updates')->whereNull('deleted_at')
                ->whereIn('project_id', $ids)
                ->whereNotNull('problems')->whereRaw("TRIM(problems) <> ''")
                ->tap(fn ($q) => $r->apply($q))
                ->selectRaw('project_id, COUNT(*) n')->groupBy('project_id')->pluck('n', 'project_id');
        }

        // ٤) أسماءُ المديرين دفعةً واحدة (لا استعلامَ لكل صف)
        $owners = hub_ref_labels('users', $projects->pluck('manager_id')->filter()->all());

        $rows = [];
        foreach ($projects as $p) {
            $t = $tasks[$p->id] ?? null;
            $late = (int) ($t->late_n ?? 0);
            $paused = (int) ($t->paused_n ?? 0);
            $blk = (int) ($blockers[$p->id] ?? 0);

            $flags = [];
            if ($late > 0) $flags[] = 'مهامُّ متأخّرة';
            if ($paused > 0) $flags[] = 'مهامُّ متوقفة';
            if ($blk > 0) $flags[] = 'معوّقاتٌ مبلَّغة';

            $rows[] = [
                'id' => $p->id,
                'name' => (string) $p->name,
                'owner' => $p->manager_id ? ($owners[$p->manager_id] ?? null) : null,
                'status' => $p->status !== null ? (string) $p->status : null,
                'progress' => $p->progress !== null ? (float) $p->progress : null,
                'tasks' => (int) ($t->n ?? 0),
                'completed' => (int) ($t->done_n ?? 0),
                'overdue' => $late,
                'blocked' => $paused,
                'blockers' => $blk,
                'risk' => ['flags' => $flags, 'n' => count($flags),
                           'sev' => self::RISK_LEVELS[count($flags)] ?? 'critical'],
            ];
        }

        // الأشدُّ أولاً، والاسمُ فالمعرّفُ فاصلان حتميّان — لا قرعةَ محرّك
        usort($rows, fn ($a, $b) => [
            Severity::RANK[$b['risk']['sev']], $b['overdue'], $b['blockers'], $a['name'], $a['id'],
        ] <=> [
            Severity::RANK[$a['risk']['sev']], $a['overdue'], $a['blockers'], $b['name'], $b['id'],
        ]);

        return ['rows' => $rows, 'n' => count($rows), 'of' => $projects->count(),
                'capped' => $projects->count() >= self::PROJECTS_TABLE_CAP,
                'cap' => self::PROJECTS_TABLE_CAP, 'risk_rule' => self::RISK_RULE];
    }

    /**
     * §6.7 — تدفّقُ المهامّ: أُنشئت، أُنجزت، أُعيد فتحُها، متأخّرة، زمنُ الدورة،
     * الإنتاجية. وزمنُ الدورة بشكل `Delivery::leadTime`: **وسيطٌ بجانب المتوسّط**
     * (مهمّةٌ عالقةٌ سنةً تسحب المتوسّطَ وحدَه) مع وسمِ عيّنةٍ معلَن — فما من رقمٍ
     * جزئيٍّ يُقرأ كأنه الكلّ.
     */
    public static function taskFlow(TimeRange $r): array
    {
        $d = self::doneAgg($r);
        $completed = (int) $d->n;

        return [
            'range' => $r,
            'created' => (int) DB::table('tasks')->whereNull('deleted_at')
                ->tap(fn ($q) => $r->apply($q))->count(),
            'completed' => $completed,
            'reopened' => self::reopenedTasks($r),
            'overdue' => self::overdueNow(now()->toDateString()),
            'cycle' => self::cycleTime($r),
            'on_time' => ['pct' => self::onTimePct($d),
                'on_time' => (int) $d->on_time, 'with_due' => (int) $d->with_due],
            'throughput' => self::throughput($completed, $r),
        ];
    }

    /**
     * §6.8 — جودةُ التذاكر: فُتحت، حُلّت، الالتزام بـSLA٪، متوسّطُ أوّل ردٍّ
     * ومتوسّطُ الحل، وإعادةُ الفتح. المهلُ من `hub_sla` (سياسةُ الإعدادات نفسُها
     * التي تقرؤها لوحةُ الدعم) لا من عتبةٍ ثانيةٍ تُكتب هنا، وأوّلُ ردٍّ **غيرُ
     * داخليّ** باستعلامٍ واحد لكل العيّنة.
     *
     * ولحظةُ الحل ختمُ `meta.resolved_at` حين وجوده وإلا آخرُ تعديل — وهو
     * التقريبُ الذي تقرؤه الشاشاتُ اليوم حرفياً، ويُصرَّح به لا يُخفى.
     *
     * و**إعادةُ الفتح لقطةٌ تراكمية لا نافذة**: عدّادُ `meta.reopened` بلا ختمِ
     * وقتٍ لكل ارتداد، فيُقرأ كما هو (`reopenedTickets`) ولا يُنسَب إلى النافذة
     * زوراً — ونسبتُه إلى مدىً لم يُقَس فيه أسوأُ من غيابه.
     */
    public static function ticketQuality(TimeRange $r): array
    {
        $closedQ = fn () => hub_closed_scope(DB::table('tickets')->whereNull('deleted_at'))
            ->tap(fn ($q) => $r->apply($q, 'updated_at'));

        $rows = $closedQ()->orderBy('updated_at')->orderBy('id')->limit(self::SLA_SAMPLE_CAP)
            ->get(['id', 'created_at', 'updated_at', 'priority', 'status', 'meta']);

        $firsts = $rows->isEmpty() ? collect() : DB::table('comments')
            ->where('module', 'tickets')->whereIn('record_id', $rows->pluck('id'))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
            ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
            ->pluck('at', 'record_id');

        $resp = [];
        $res = [];
        $met = 0;
        foreach ($rows as $t) {
            $s = hub_sla($t, $firsts[$t->id] ?? null);
            $created = \Illuminate\Support\Carbon::parse($t->created_at);
            if ($s['respAt']) $resp[] = abs($s['respAt']->diffInMinutes($created)) / 60;
            if ($s['resAt']) {
                $res[] = abs($s['resAt']->diffInMinutes($created)) / 60;
                if (! $s['resLate']) $met++;
            }
        }

        return [
            'range' => $r,
            'opened' => (int) DB::table('tickets')->whereNull('deleted_at')
                ->tap(fn ($q) => $r->apply($q))->count(),
            'resolved' => (int) $closedQ()->count(),
            // الالتزام يُقاس على ما له لحظةُ حلٍّ فعلية — لا على ما لم يُقَس بعد
            'sla' => ['pct' => $res ? (int) round($met * 100 / count($res)) : null,
                'met' => $met, 'of' => count($res)],
            'response' => self::hoursStat($resp),
            'resolution' => self::hoursStat($res),
            'reopened' => self::reopenedTickets(),
            'sample' => $rows->count(),
            'capped' => $rows->count() >= self::SLA_SAMPLE_CAP,
            'cap' => self::SLA_SAMPLE_CAP,
        ];
    }

    /**
     * §6.12 — لقطةُ التنفيذ اليومية داخل `hub:quality-snapshot` القائم (لا أمرَ
     * مجدولٌ ثانٍ ولا جدولَ جديد): `('execution','org',<metric>)` في
     * `metric_points`. والنقطةُ معرَّفةٌ بـ(وحدة، سجل، مقياس، لحظة) فإعادةُ
     * التشغيل في اليوم نفسِه **تُحدِّث** الصفَّ ولا تُنشئ ثانياً.
     *
     * و**ما لا يُقاس لا يُكتب صفراً**: بلا مهامٍّ مخطَّطةٍ لا نسبةَ إنجاز —
     * والصفرُ المكتوبُ مكانَ «لا قياس» يرسم في السلسلة انهياراً لم يحدث.
     *
     * وتُعيد القراءةَ التي كتبتها كي يطبعها الأمرُ بلا حسابٍ ثانٍ.
     */
    public static function snapshot(?string $at = null, ?TimeRange $r = null): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return [];

        $t = $at ? \Illuminate\Support\Carbon::parse($at)->startOfDay() : now()->startOfDay();
        // نافذةٌ ثابتةٌ لا تقرأ معاملات الطلب — اللقطةُ لا تتبدّل بكبسولةِ شاشة
        $r = $r ?? TimeRange::fromRequest(new \Illuminate\Http\Request(), self::SNAPSHOT_RANGE);
        $x = self::executionSummary($r);

        $put = function (string $metric, ?float $v) use ($t) {
            if ($v === null) return;
            hub_metric_put('execution', 'org', $metric, $v, $t, 'auto');
        };

        $put('completion_pct', $x['planned']['pct'] === null ? null : (float) $x['planned']['pct']);
        $put('ontime_pct', $x['on_time']['pct'] === null ? null : (float) $x['on_time']['pct']);
        $put('overdue', (float) $x['overdue']);
        $put('open_issues', (float) self::openIssues());

        return $x;
    }

    /**
     * تاريخُ التنفيذ من اللقطات — نقاطٌ مرتّبةٌ وفرقٌ بين طرفيها، بشكل
     * `DataQuality::history`. **الفارغُ يبقى فارغاً**: قائمةُ نقاطٍ خالية
     * و`delta = null` (نقطةٌ واحدة لا تصنع اتّجاهاً) — لا سلسلةَ أصفارٍ تُرسم
     * هبوطاً لم يقع. واستعلامٌ واحدٌ للمقاييس الأربعة لا أربعة.
     */
    public static function history(int $days = 60): array
    {
        $out = ['days' => $days, 'metrics' => []];
        foreach (self::SNAPSHOT_METRICS as $m) {
            $out['metrics'][$m] = ['points' => [], 'first' => null, 'last' => null, 'delta' => null];
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return $out;

        $rows = DB::table('metric_points')->where('module', 'execution')->where('record_id', 'org')
            ->whereIn('metric', self::SNAPSHOT_METRICS)
            ->where('at', '>=', now()->subDays($days))
            ->orderBy('metric')->orderBy('at')->orderBy('id')
            ->get(['metric', 'value', 'at']);

        foreach ($rows as $p) {
            if (! isset($out['metrics'][$p->metric])) continue;
            $out['metrics'][$p->metric]['points'][] = ['at' => $p->at, 'value' => (float) $p->value];
        }
        foreach (self::SNAPSHOT_METRICS as $m) {
            $pts = $out['metrics'][$m]['points'];
            $n = count($pts);
            if (! $n) continue;
            $out['metrics'][$m]['first'] = $pts[0]['value'];
            $out['metrics'][$m]['last'] = $pts[$n - 1]['value'];
            $out['metrics'][$m]['delta'] = $n > 1 ? round($pts[$n - 1]['value'] - $pts[0]['value'], 2) : null;
        }

        return $out;
    }

    /* ────────── تعريفاتُ الصدق المشتركة (يقرؤها `org` ولوحُ التنفيذ معاً) ────────── */

    /**
     * أُنجز في النافذة والتزامُه — من `completed_at` حصراً. «في الموعد» = يومُ
     * الإنجاز <= الموعد (المقارنةُ بالتاريخ لا باللحظة — الموعدُ يومٌ كامل)،
     * و`DATE()` تعمل على المحرّكين معاً.
     */
    protected static function doneAgg(TimeRange $w)
    {
        return DB::table('tasks')
            ->whereNull('deleted_at')->whereNotNull('completed_at')
            ->tap(fn ($q) => $w->apply($q, 'completed_at'))
            ->selectRaw('COUNT(*) n,
                COALESCE(SUM(CASE WHEN due IS NOT NULL THEN 1 ELSE 0 END), 0) with_due,
                COALESCE(SUM(CASE WHEN due IS NOT NULL AND DATE(completed_at) <= due THEN 1 ELSE 0 END), 0) on_time')
            ->first();
    }

    /** نسبةُ الالتزام من تجميع `doneAgg` — بلا مهامَّ ذاتِ موعدٍ لا نسبة (لا ١٠٠٪ مجانية) */
    protected static function onTimePct($agg): ?int
    {
        return (int) $agg->with_due > 0
            ? (int) round((int) $agg->on_time * 100 / (int) $agg->with_due) : null;
    }

    /** المهامُّ المفتوحةُ الآن — قاموسُ `hub_open_scope` لا قائمةُ حالاتٍ حرفية */
    protected static function openTasks()
    {
        return hub_open_scope(DB::table('tasks')->whereNull('deleted_at'));
    }

    /**
     * المتأخّرُ الآن — مفتوحةٌ فات موعدُها (لقطةُ اللحظة لا نافذة): المدى
     * `< اليوم` لا `whereDate` على عمودٍ مفهرَس، والملغاةُ خارجةٌ بالقاموس.
     */
    protected static function overdueNow(string $today): int
    {
        return (int) self::openTasks()->whereNotNull('due')->where('due', '<', $today)->count();
    }

    /**
     * حدُّ **أيام** النافذة لعمود تاريخٍ (`due`): [أوّلُ يومٍ، اليومُ التالي
     * لآخرِ يوم) — مدىً سارغابل على فهرس `tasks(due)` بلا `whereDate`. والحدُّ
     * الأعلى للنافذة حصريٌّ (`< to`) فيُؤخذ يومُ آخرِ لحظةٍ فيها لا يومُ `to`:
     * وإلا لدخل في «المخطَّط» يومٌ كاملٌ خارج النافذة.
     */
    protected static function dueBounds(TimeRange $r): array
    {
        return [$r->from->toDateString(),
                $r->to->copy()->subSecond()->startOfDay()->addDay()->toDateString()];
    }

    /** الإنتاجية: المنجَزُ ÷ أيام النافذة — قسمةٌ صريحة لا استقراء */
    protected static function throughput(int $n, TimeRange $r): array
    {
        $days = max(0.0001, $r->days());

        return ['n' => $n, 'days' => round($days, 2), 'per_day' => round($n / $days, 2),
                'per_week' => round($n / $days * 7, 2)];
    }

    /**
     * إعادةُ فتح المهامّ — **من تاريخ التدقيق لا من عمودٍ جديد**: قيدٌ ينقل
     * الحالةَ من منتهيةٍ (`hub_closed_states`) إلى غيرِ منتهية هو إعادةُ فتحٍ
     * بعينها. (لتذاكرِ الدعم عدّادٌ صريح `meta.reopened` يختمه ModuleController؛
     * وللمهامّ لا عمودَ اليوم — فالحقيقةُ المتاحةُ هي الأثر، وتُقرأ كما هي.)
     */
    protected static function reopenedTasks(TimeRange $r): array
    {
        $closed = hub_closed_states();
        $n = (int) DB::table('audits')->where('module', 'tasks')
            ->whereNotNull('before->status')->whereNotNull('after->status')
            ->whereIn('before->status', $closed)->whereNotIn('after->status', $closed)
            ->tap(fn ($q) => $r->apply($q))
            ->count();

        return ['n' => $n, 'source' => 'audits'];
    }

    /**
     * زمنُ الدورة أياماً (من الإنشاء إلى ختم الإنجاز) — عيّنةٌ **محدّدةُ المعنى**
     * كما في `Delivery::leadTimeCalc`: أحدثُ ما أُنجز، بترتيبٍ حتميّ وسقفٍ معلَن،
     * ووسيطٌ بجانب المتوسّط. وسجلٌّ متناقضٌ (أُنجز قبل أن يُنشأ) يُتجاهَل ولا يشوّه.
     */
    protected static function cycleTime(TimeRange $r): array
    {
        $rows = DB::table('tasks')->whereNull('deleted_at')->whereNotNull('completed_at')
            ->tap(fn ($q) => $r->apply($q, 'completed_at'))
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(self::CYCLE_SAMPLE_CAP)
            ->get(['created_at', 'completed_at']);

        $days = [];
        foreach ($rows as $t) {
            if (! $t->created_at) continue;
            $d = \Illuminate\Support\Carbon::parse($t->created_at)
                ->diffInSeconds(\Illuminate\Support\Carbon::parse($t->completed_at)) / 86400;
            if ($d < 0) continue;
            $days[] = $d;
        }
        sort($days);
        $n = count($days);
        $out = ['n' => $n, 'avg' => null, 'median' => null, 'best' => null, 'worst' => null,
                'capped' => $rows->count() >= self::CYCLE_SAMPLE_CAP, 'cap' => self::CYCLE_SAMPLE_CAP];
        if (! $n) return $out;

        $median = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;

        return array_merge($out, [
            'avg' => round(array_sum($days) / $n, 1), 'median' => round($median, 1),
            'best' => round($days[0], 1), 'worst' => round($days[$n - 1], 1),
        ]);
    }

    /** متوسّطٌ ووسيطٌ بالساعات لقائمةِ فوارق — والفارغُ `null` لا صفر */
    protected static function hoursStat(array $hours): array
    {
        $n = count($hours);
        if (! $n) return ['avg_h' => null, 'median_h' => null, 'n' => 0];
        sort($hours);
        $median = $n % 2 ? $hours[intdiv($n, 2)] : ($hours[$n / 2 - 1] + $hours[$n / 2]) / 2;

        return ['avg_h' => round(array_sum($hours) / $n, 1), 'median_h' => round($median, 1), 'n' => $n];
    }

    /** المشاكلُ والمخاطرُ المفتوحةُ الآن (§6.12 «open issues») — بقاموس الحالات نفسِه */
    protected static function openIssues(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('issues')) return 0;

        return (int) hub_open_scope(DB::table('issues')->whereNull('deleted_at'))->count();
    }
}
