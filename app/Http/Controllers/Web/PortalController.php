<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * بوابة الموظف: «بوابتي» للمستخدم الحالي، و«الملف الشامل» لمن يملك عرض HR.
 * تجميع ٣٦٠°: الملف الوظيفي + المهام + الإجازات + الحضور + السجل + العهدة.
 */
class PortalController extends Controller
{
    /** بوابتي — بيانات المستخدم الحالي نفسه (لا تتطلب صلاحية HR) */
    public function me()
    {
        // ترتيبٌ حاسم: ربطٌ مزدوج (موظفان بنفس user_id، يُتيحه النموذج العام)
        // كان first() بلا orderBy يعرض بيانات زميلٍ بالقرعة بين المحرّكين
        $emp = Employee::where('user_id', auth()->id())->whereNull('deleted_at')
            ->orderBy('id')->first();
        $inbox = \App\Support\Inbox::items(auth()->user());

        return view('portal.me', ['emp' => $emp, 'self' => true,
            'inbox' => $inbox, 'buckets' => \App\Support\Inbox::summary($inbox),
        ] + $this->bundle($emp, auth()->id()));
    }

    /** الملف الشامل لموظف — لمن يملك عرض وحدة HR */
    public function employee(string $id)
    {
        $u = auth()->user();
        // حدُّ حساب العميل يعلو على المصفوفة (§79): الملفُّ 360 سطحٌ داخليٌّ بحت — حسابُ
        // عميلٍ مُساءُ الضبط (مصفوفتُه مُنِحت hr:v) لا يبلغه، ٤٠٤ لا نُثبت وجودَ ما لا يخصّه.
        // المسارُ في PortalGuard::NAME_ALLOW «portal.*» فلا يردّه الوسيط — فالحرسُ هنا صراحةً.
        abort_if(hub_is_client($u), 404);
        abort_unless(hub_can($u, 'hr', 'v'), 403, 'عرض ملفات الموظفين يتطلب صلاحية الموارد البشرية');
        // النطاق يسري كما في كل قارئ: ملفٌّ خارج شركتي أو مشاريعي = ٤٠٤ لا ٢٠٠
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);

        // (WP-F.4 · §28) الملفُّ ٣٦٠ تبويباتٌ **محروسةٌ خادميّاً**: كلُّ تبويبٍ مرتبطٌ
        // بوحدةٍ يحرسها hub_can — و`?tab=` مصنوعٌ باليد لوحدةٍ لا يملكها القارئُ يُردّ
        // ٤٠٣ (لا مجرَّدَ إخفاءٍ في الشريط). سككٌ قائمةٌ فقط تظهر (لا بطاقةٌ زائفة §82).
        $tabs = $this->emp360Tabs($u);
        $req  = request()->query('tab');
        $tab  = (is_string($req) && $req !== '') ? $req : $tabs[0]['key'];
        // تبويبٌ لا وجود له في السجلّ أصلاً (سكّةٌ لم تُبنَ) → ٤٠٤ لا لوحةٌ صامتة
        abort_unless(array_key_exists($tab, $this->emp360Catalog()), 404);
        // تبويبٌ معروفٌ لكنّ القارئَ لا يملك وحدتَه → ٤٠٣ (الحرسُ فوق الشريط)
        abort_unless(collect($tabs)->contains('key', $tab), 403, 'لا تملك عرضَ هذا التبويب');

        // (الكيان 360 · §6/§17) نموذجُ الموظف 360: شريطُ نظرةٍ يُجمّع العلاقاتِ المصرَّحة،
        // وتاريخُ محطةٍ/عهدةٍ ونشاطٌ منطَّق — تكميلٌ للملفّ القائم لا محرّكٌ ثانٍ.
        $e360 = new \App\Support\Employee360;

        $data = ['emp' => $emp, 'self' => false, 'tab360' => $tab, 'tabs360' => $tabs,
                 'ov360' => $e360->overview($emp, $u)]
            + $this->bundle($emp, $emp->user_id)
            + $this->workProfile($emp);

        // تبويبُ المحطة (F.1) يُحمَّل عند فتحه وحدَه — مقاعدُ الموظف بـcurrent_employee_id
        if ($tab === 'station') {
            $data['stations'] = $this->stationsFor($u, $emp->user_id);
            $data['stationHist'] = $e360->stationHistory($emp, $u);   // §9 تاريخُ المحطات
        }
        // العهدةُ والأجهزة (§11): تاريخُ عهدةِ الأصولِ بحساب الموظف
        if ($tab === 'assets') $data['custodyHist'] = $e360->custodyHistory($emp, $u);
        // الملفُّ والعمل (§17): نشاطٌ تشغيليٌّ منطَّقٌ (محطة/عهدة) — لا مسحٌ شامل
        if ($tab === 'profile') $data['empActivity'] = $e360->activity($emp, $u);
        // التقاريرُ اليوميّة (§21/§61): تاريخُ الحضورِ والتقريرِ والأثرِ المحتسَب مقيَّداً
        if ($tab === 'reports') $data['reportHistory'] = $e360->dailyReports($emp, $u);
        // (الطور G · WP-G.2) تبويبُ الاتصالات يُحمَّل عند فتحه — خطوطُ الموظف بـemployee_id
        if ($tab === 'telecom') $data['phones'] = $this->phonesFor($u, $emp->id);
        // (الطور M · WP-M.3) تبويبُ الأنظمة — سيرفراتُ الموظف بحافّة H.1 (servers.hr_id)
        if ($tab === 'systems') $data['servers'] = $this->serversFor($u, $emp->id);
        // (الطور J · WP-J.3) تبويبُ أمنِ النقاط — أجهزةُ الموظف بحسابه (employee_id مرجعُ users)
        if ($tab === 'endpoint') $data['endpointDevices'] = $this->endpointDevicesFor($u, $emp->user_id);

        return view('portal.employee', $data);
    }

    /**
     * (WP-F.4 · §28) سجلُّ تبويبات الملفّ ٣٦٠: مفتاحٌ ⟵ [الوحدةُ الحارسة, التسمية].
     * **سككٌ قائمةٌ فقط** — تبويباتُ الاتصالات/الأنظمة/أمنِ النقاط (الأطوار G/J)
     * تُضاف عند وصولِ سككها لا قبل (لا بطاقةٌ زائفة §82). ترتيبُ الإدراج = ترتيبُ
     * العرض، و`profile` أوّلاً فهو الافتراضيُّ (الصفحةُ تتطلب hr:v أصلاً).
     */
    protected function emp360Catalog(): array
    {
        return [
            'profile' => ['mod' => 'hr',       'label' => '🗂️ الملف والعمل'],
            // (الحضور × التقرير · §21/§61) تقاريرُ الموظّف اليوميّة — حضورٌ وتقريرٌ وأثرٌ
            // محتسَبٌ يوماً بيوم، من المُحلِّلِ المركزيّ. يحرسه `updates:v` كسائر التبويبات.
            'reports' => ['mod' => 'updates',  'label' => '📝 التقارير اليومية'],
            'assets'  => ['mod' => 'assets',   'label' => '💻 العهدة والأجهزة'],
            'station' => ['mod' => 'stations', 'label' => '🪑 المحطة'],
            // (Work OS · الطور G · WP-G.2 · §28) سكّةُ الاتصالات وصلت: خطوطُ الموظف
            // (SIM/eSIM) تُضيء تبويبَها هنا — لا بطاقةٌ زائفةٌ قبل السكّة (§82).
            'telecom' => ['mod' => 'phones',   'label' => '📡 الاتصالات'],
            // (Work OS · الطور M · WP-M.3 · §28) سكّةُ الأنظمة وصلت في H.1
            // (حافّةُ `servers.hr_id`) — وبهذا التبويب اكتمل السجلُّ: سبعُ سككٍ
            // حقيقيّةٍ بصفرِ بطاقاتٍ زائفة (§82). يحرسه `servers:v` كسائر التبويبات.
            'systems' => ['mod' => 'servers',  'label' => '🖥️ الأنظمة'],
            // (Work OS · الطور J · WP-J.3 · §28/§43) سكّةُ النقاط الطرفية وصلت:
            // أجهزةُ الموظف المسجَّلة ووضعيّتُها **الصادقة** (C15) — التبويبُ حقيقيٌّ
            // الآن لا بطاقةٌ زائفة، ويحرسه `endpoints:v` كسائر التبويبات.
            'endpoint' => ['mod' => 'endpoints', 'label' => '🛡️ أمن النقاط'],
            'wallet'  => ['mod' => 'custody',  'label' => '💰 العهدة المالية'],
        ];
    }

    /** التبويباتُ المسموحةُ للقارئ — كلُّ ما يملك وحدتَه (hub_can) من السجلّ */
    protected function emp360Tabs($u): array
    {
        $out = [];
        foreach ($this->emp360Catalog() as $key => $t) {
            if (hub_can($u, $t['mod'], 'v')) $out[] = ['key' => $key, 'label' => $t['label']];
        }

        return $out;
    }

    /**
     * (WP-F.4 · §28 · F.1) مقاعدُ الموظف الآن — منطَّقةٌ بالشركة كأيّ قارئ، بترتيبٍ
     * حتميّ (C13). القراءةُ فقط؛ الإسنادُ/الإخلاءُ يمرّان بـ`StationController` المقفل.
     */
    protected function stationsFor($u, ?string $userId)
    {
        if (! $userId || ! hub_can($u, 'stations', 'v')) return collect();

        return hub_scope(DB::table('stations')->whereNull('deleted_at'), 'stations')
            ->where('current_employee_id', $userId)
            ->orderBy('code')->orderBy('id')
            ->limit(20)->get(['id', 'code', 'facility', 'zone', 'room', 'desk', 'type', 'dept', 'status']);
    }

    /**
     * (WP-G.2 · §22/§28) خطوطُ الاتصالات المُخصَّصةُ للموظف — منطَّقةٌ بالشركة كأيّ
     * قارئ (نطاقٌ لكلّ ابن: خطُّ شركةٍ أجنبيةٍ لا يبلغ مديراً معزولاً)، وبترتيبٍ حتميّ
     * (الأحدثُ أعلى ثم `id` — لا قرعةَ ترتيب). القراءةُ فقط؛ لا سرّ (pin/puk) يُنتقى
     * أصلاً — أعمدةُ العرضِ حرّة، والأسرارُ تُكشف عبر شاشةِ السجل ومسارِ revealSecret.
     */
    protected function phonesFor($u, ?string $empId)
    {
        if (! $empId || ! hub_can($u, 'phones', 'v')) return collect();

        // iccid هويّةُ الشريحة التقنيّة: تُنتقى لتُعرض خلف hub_field_mode في العرض
        // (WP-M.3 — نظيرُ سيريال العهدة)، أمّا pin/puk فلا يُنتقيان أصلاً.
        return hub_scope(DB::table('phone_numbers')->whereNull('deleted_at'), 'phones')
            ->where('employee_id', $empId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(20)->get(['id', 'number', 'line_type', 'carrier', 'iccid', 'msisdn', 'status', 'expiry']);
    }

    /**
     * (WP-M.3 · §28/§37) سيرفراتُ الموظف المسؤول — حافّةُ البنية `servers.hr_id`
     * التي أرستها H.1: طرفاها محروسان بالبناء (الصفحةُ `hr:v` والتبويبُ `servers:v`)
     * فقاعدةُ «الحافّةُ لمن يملك طرفَيها» مستوفاةٌ قبل حلِّ اسمِ سيرفرٍ واحد.
     * منطَّقةٌ بالشركة كأيّ قارئ وبترتيبٍ حتميّ (name ثم id — لا قرعةَ إدراج).
     * القراءةُ فقط، وأعمدةُ العرض حرّةٌ عدا IP (خلف hub_field_mode في العرض) —
     * ولا يُنتقى `vault_id` (اعتمادُ الدخول) بحالٍ: كشفُه سكّةُ الخزنة وحدَها.
     */
    protected function serversFor($u, ?string $empId)
    {
        if (! $empId || ! hub_can($u, 'servers', 'v')) return collect();

        return hub_scope(DB::table('servers')->whereNull('deleted_at'), 'servers')
            ->where('hr_id', $empId)
            ->orderBy('name')->orderBy('id')
            ->limit(20)->get(['id', 'name', 'provider', 'type', 'ip', 'os', 'status', 'expiry']);
    }

    /**
     * (WP-J.3 · §28/§43) أجهزةُ النقاط الطرفية المسجَّلةُ بحساب الموظف — منطَّقةٌ
     * بالشركة كأيّ قارئ وبترتيبٍ حتميّ (hostname ثم id — لا قرعةَ إدراج). القراءةُ
     * فقط؛ الأوامرُ والتسجيلُ بمساراتها المقفلة (step-up للخطيرين). الهويّةُ
     * التقنية (السيريال من `hw` وهويّةُ الوكيل) يحجبها field-mode في العرض —
     * مفتاحُ الحقل `hw` (فحقولُ السجل المقفولة locked تعود 'ro' لا 'hide').
     */
    protected function endpointDevicesFor($u, ?string $userId)
    {
        if (! $userId || ! hub_can($u, 'endpoints', 'v')) return collect();

        return hub_scope(DB::table('endpoint_devices')->whereNull('deleted_at'), 'endpoints')
            ->where('employee_id', $userId)
            ->orderBy('hostname')->orderBy('id')
            ->limit(20)->get(['id', 'hostname', 'os', 'device_uuid', 'hw', 'agent_version',
                              'status', 'posture', 'last_heartbeat_at']);
    }

    /**
     * **ملفُّ العمل** (WP-7.3 · spec §5.3 · §5.8) — ثلاثُ بطاقاتٍ منفصلةُ الطبيعة
     * عمداً، ولكلٍّ حارسُها:
     *
     *  • **العمل** (`ExecutionStats::person`): مهامٌّ ومنجَزٌ والتزامٌ وتذاكرُ
     *    ومشاريعُ واعتمادات — **القارئُ الواحد** الذي تقرأ منه نظرةُ القوى
     *    العاملة ولوحُ الأداء، بقارئٍ ممرَّرٍ فيسري `hub_can` لكل وحدةٍ و
     *    `hub_scope` لكل صفّ. الحارس: `hr:v` أعلاه + صلاحيةُ كل وحدةٍ داخله.
     *  • **النشاط** (`personActivity` + `personTimeline`): أوّلُ وآخرُ ظهورٍ من
     *    نبضة الجلسة، وأفعالٌ ذاتُ معنى، وخطٌّ زمنيٌّ من عملٍ حقيقيّ — **بلا
     *    زياراتِ صفحاتٍ خام**. الحارس: `hub_monitor()` (ق١: القراءةُ للمراقب).
     *  • **الأمن** (`Risk::activity`): بطاقةٌ **منفصلةٌ تماماً** للمالك وحدَه —
     *    الدرجةُ الأمنية لا تُخلط بأرقام الأداء بحال (WP-7.1 · spec §5.1).
     */
    protected function workProfile(?Employee $emp): array
    {
        $u = auth()->user();
        $range = hub_range(request(), '30d');
        $uid = $emp && $emp->user_id ? (string) $emp->user_id : null;

        $out = ['wRange' => $range, 'work' => null, 'act' => null, 'wTl' => [],
                'sec' => null, 'rating' => null];

        // «تقييم المدير» حقلُ ملفٍّ وظيفيّ تحكمه صلاحيةُ الحقل — العيبُ نفسُه
        // كان في لوح الأداء (peopleKpis) فأُصلح هناك وهنا بالقاعدة الواحدة
        if ($emp && hub_field_mode($u, 'hr', 'perf') !== 'hide') $out['rating'] = $emp->perf;

        // حسابٌ غير مربوطٍ بالملفّ: لا أرقامَ عملٍ له — حالةٌ فارغةٌ صادقة لا أصفار
        if ($uid === null) return $out;

        $out['work'] = \App\Support\ExecutionStats::person($uid, $range, $u);

        if (hub_monitor($u)) {
            $out['act'] = \App\Support\ExecutionStats::personActivity($uid, $range, $u);
            $out['wTl'] = \App\Support\ExecutionStats::personTimeline($uid, $range, $u);
        }

        // الأمنُ للمالك وحدَه وفي بطاقةٍ لا تلامس بطاقاتِ العمل
        if (hub_is_owner($u) && ($su = \App\Models\User::find($uid))) {
            $out['sec'] = \App\Support\Risk::activity($su, $range);
        }

        return $out;
    }

    /* ────────── تجميع البيانات ────────── */

    /** كل ما يخص الموظف: مهامه (عبر حسابه)، إجازاته، حضوره، سجله، عهدته */
    protected function bundle(?Employee $emp, ?string $userId): array
    {
        $out = [
            'tasks' => collect(), 'openTasks' => 0,
            'leaves' => collect(), 'attend' => collect(),
            'attMonth' => ['days' => 0, 'hours' => 0.0],
            'log' => collect(), 'assets' => collect(),
            'mustRead' => collect(),
            'approvals' => collect(), 'tickets' => collect(),
            'meetings' => collect(), 'decisions' => collect(),
        ];

        // كل قسمٍ خلف وحدته: شاشةٌ مقاسةٌ بصلاحية HR كانت تعرض الإجازات والحضور
        // والسجل التأديبي والعهدة لمن لا يملك أيّاً من وحداتها
        $u = auth()->user();
        $out['may'] = [
            'leaves' => hub_can($u, 'leaves', 'v'), 'attend' => hub_can($u, 'attend', 'v'),
            'hrlog' => hub_can($u, 'hrlog', 'v'), 'assets' => hub_can($u, 'assets', 'v'),
            'tasks' => hub_can($u, 'tasks', 'v'), 'kb' => hub_can($u, 'kb', 'v'),
        ];

        if ($userId) {
            // «مفتوحة» من التعريف الموحَّد — مصدرها hub_closed_states لا مفردات محلية
            $open = fn ($q) => hub_open_scope($q);

            if ($out['may']['tasks']) {
                $mine = fn () => $open(hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                    ->where('assignee_id', $userId));
                $out['tasks'] = $mine()->orderByRaw('due IS NULL, due')
                    ->limit(10)->get(['id', 'title', 'status', 'due', 'progress', 'priority', 'project_id']);
                $out['openTasks'] = $mine()->count();
            }

            if ($out['may']['assets']) $out['assets'] = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
                ->where('holder_id', $userId)->orderBy('name')->orderBy('id')
                ->limit(12)->get(['id', 'name', 'type', 'tag', 'serial', 'status']);

            /* ── الصندوق الموحد: كل ما ينتظر تصرفي ── */

            // موافقات بانتظاري: أنا المعتمد أو ضمن سلسلة الاعتماد، ولم تُحسم
            $out['approvals'] = ! hub_can($u, 'approvals', 'v') ? collect()
                : hub_scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')
                ->where(fn ($w) => $w->where('approver_id', $userId)->orWhere('chain', 'LIKE', '%"' . $userId . '"%'))
                ->tap(fn ($q) => hub_open_scope($q, 'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة']))
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'type', 'amount', 'currency', 'due', 'status']);

            // تذاكري المفتوحة
            $out['tickets'] = ! hub_can($u, 'tickets', 'v') ? collect()
                : hub_scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                ->where('assignee_id', $userId)
                ->tap(fn ($q) => hub_open_scope($q))
                ->orderByDesc('created_at')->limit(8)
                ->get(['id', 'subject', 'customer', 'priority', 'status']);

            // اجتماعاتي القادمة: أنا من المشاركين
            $out['meetings'] = ! hub_can($u, 'meetings', 'v') ? collect()
                : hub_scope(DB::table('meetings')->whereNull('deleted_at'), 'meetings')
                ->where('parts', 'LIKE', '%"' . $userId . '"%')
                ->where('dt', '>=', now()->startOfDay())
                ->orderBy('dt')->limit(6)
                ->get(['id', 'title', 'dt', 'link']);

            // قرارات أنفّذها ولم تُنجز
            $out['decisions'] = ! hub_can($u, 'decisions', 'v') ? collect()
                : hub_scope(DB::table('decisions')->whereNull('deleted_at'), 'decisions')
                ->where('exec_id', $userId)
                ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر'])
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'due', 'status']);
        }

        if ($emp && $out['may']['leaves']) {
            $out['leaves'] = DB::table('leave_requests')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date_from')
                ->limit(6)->get(['id', 'type', 'date_from', 'date_to', 'days', 'status']);

        }

        if ($emp && $out['may']['attend']) {
            $out['attend'] = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(7)->get(['id', 'date', 'time_in', 'time_out', 'hours', 'status']);

            $m = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
                ->selectRaw('COUNT(*) as d, COALESCE(SUM(hours),0) as h')->first();
            $out['attMonth'] = ['days' => (int) ($m->d ?? 0), 'hours' => (float) ($m->h ?? 0)];

        }

        if ($emp && $out['may']['hrlog']) {
            $out['log'] = DB::table('employee_records')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(8)->get(['id', 'kind', 'title', 'date', 'expiry', 'status']);
        }

        // مقالاتٌ «يجب قراءتها» — خلف وحدة المعرفة ونطاقها
        try {
            if ($out['may']['kb']) {
                $out['mustRead'] = hub_scope(DB::table('kb_articles')->whereNull('deleted_at'), 'kb')
                    ->where('must_read', 1)->orderByDesc('created_at')
                    ->limit(5)->get(['id', 'title', 'cat']);
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
