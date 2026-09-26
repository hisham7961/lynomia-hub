<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\CommentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** متحكم عام واحد يخدم كل الوحدات — سجل الوحدات config/hub.php يقود كل شيء */
class ModuleController extends Controller
{
    /** حل الوحدة: التعريف + صنف الموديل، مع فرض الصلاحية */
    protected function resolve(string $module, string $op): array
    {
        $def = hub_mod($module);
        abort_if(! $def || $module === 'users', 404);           // users لها صفحتها الإدارية الخاصة
        abort_unless(hub_can(auth()->user(), $module, $op), 403, 'لا تملك صلاحية على هذه الوحدة');

        // Permissions 360 · 12.1 — أسطولُ النقاطِ الطرفيّة بوّابتُه واحدة: بابُ الوحدةِ
        // العامُّ يشترط ما يشترطه المركزُ نفسُه (مالك/secOps)، فلا يلتفُّ `endpoints:v`
        // في المصفوفةِ على حارسِ EndpointCentre (هجرةُ grant_secops صانت الأدوارَ القائمة).
        if ($module === 'endpoints') {
            abort_unless(hub_fleet_ok(), 403,   // سلطةٌ واحدةٌ يشاركها /api/v1 — F-02
                'أسطولُ النقاطِ الطرفيّة للمالكِ أو حاملِ مجموعةِ الأمن (secOps)');
        }

        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }

    /** مفوِّضٌ — المنطقُ في `ModuleQuery::buildQuery` (docs/REORG_PLAN.md §R6) */
    protected function buildQuery(Request $r, array $def, string $class, bool &$trash = false, array &$filters = []): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Support\Platform\Modules\ModuleQuery::buildQuery($r, $def, $class, $trash, $filters);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleQuery::chipLabel` (docs/REORG_PLAN.md §R6) */
    protected function chipLabel(string $ref, string $id): string
    {
        return \App\Support\Platform\Modules\ModuleQuery::chipLabel($ref, $id);
    }

    /** عوامل الفلاتر المتقدمة المسموحة لكل نوع حقل — ما خرج عنها يُتجاهل بصمت */
    public const FL_OPS = \App\Support\Platform\Modules\ModuleQuery::FL_OPS;   // انتقل (docs/REORG_PLAN.md §R6)

    /** تسميات العوامل للرقائق والواجهة */
    public const FL_LABELS = \App\Support\Platform\Modules\ModuleQuery::FL_LABELS;   // انتقل (docs/REORG_PLAN.md §R6)

    /** مفوِّضٌ — المنطقُ في `ModuleQuery::applyAdvancedFilters` (docs/REORG_PLAN.md §R6) */
    protected function applyAdvancedFilters(Request $r, array $def, $fields, $q, array &$filters): void
    {
        \App\Support\Platform\Modules\ModuleQuery::applyAdvancedFilters($r, $def, $fields, $q, $filters);
    }

    public function index(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $def['key'] = $module;

        // عروض المستخدم المحفوظة لهذه الوحدة — استعلام واحد يخدم التحويل والقائمة معاً
        $views = \App\Models\SavedView::where('user_id', auth()->id())
            ->where('module', $module)->orderByDesc('is_default')->orderBy('name')->get();

        // زيارة عارية + عرض افتراضي محفوظ → يُطبق تلقائياً (view= يمنع الدوران)
        if (! $r->query() && ($dv = $views->firstWhere('is_default', true))) {
            return redirect($dv->url());
        }

        $trash = false; $filters = [];
        $q = $this->buildQuery($r, $def, $class, $trash, $filters);
        $fields = collect($def['fields']);

        // فرز بأي عمود من أعمدة الوحدة
        $sortKey = $r->input('s');
        $sf      = $sortKey ? $fields->firstWhere('key', $sortKey) : null;
        // الترتيب بعمودٍ **مخفيّ** إفشاءٌ بلا عرض: «من أعلى راتباً» يُقرأ من
        // الترتيب وحده. أمّا «قراءة فقط» فقيمتُه ظاهرةٌ في الجدول أصلاً ولا
        // شيءَ يُستدَلّ — كان `!== ''` يشمله فيُلغى فرزُ عمودٍ مرئيٍّ بصمت.
        if ($sf && hub_field_mode(auth()->user(), $module, (string) $sf['key']) === 'hide') {
            $sf = null;
            $sortKey = null;
        }
        $dir     = $r->input('d') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sf['col'] ?? 'created_at', $sf ? $dir : 'desc');
        // فاصل تعادلٍ حاسم: عمودُ الفرز قد تتساوى قيمُه (created_at بدقّة الثانية،
        // إدراجٌ دفعيّ) فتُقرع الصفحاتُ على MySQL 8 — تخطٍّ أو تكرارٌ صامت. id
        // (المفتاح) يمنح ترتيباً كليّاً ثابتاً على المحرّكين.
        $q->orderBy('id', $sf ? $dir : 'desc');

        $rows = $q->paginate(25)->withQueryString();

        [$columns, $labels] = $this->columnsAndLabels($def, $rows->items());
        $statusOptions = $this->statusOptions($def);

        // العرض المطبَّق الآن (القائمة جُلبت أعلاه بلا استعلام ثانٍ)
        $activeView = ($vid = $r->query('view')) ? $views->firstWhere('id', $vid) : null;

        return view('modules.index', [
            'module' => $module, 'def' => $def, 'rows' => $rows,
            'columns' => $columns, 'labels' => $labels,
            'statusOptions' => $statusOptions, 'trash' => $trash,
            'filters' => $filters, 'sortKey' => $sortKey, 'sortDir' => $dir,
            'views' => $views, 'activeView' => $activeView,
            'allFields' => hub_visible_fields(auth()->user(), $module, $def),
        ]);
    }

    public function create(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'a');

        // تعبئة مسبقة من الرابط (زر ＋ داخل عمود الكانبان مثلاً يمرر الحالة):
        // تُقبل مفاتيح حقول الوحدة فقط وبقيم نصية — والتحقق الكامل يبقى عند الحفظ
        $prefill = [];
        foreach ($def['fields'] ?? [] as $f) {
            $v = $r->query($f['key']);
            if (is_string($v) && $v !== '') $prefill[$f['key']] = $v;
        }

        // **والاختيارُ من واحدٍ ليس اختياراً** (M-F6): حسابٌ معزولٌ على شركةٍ
        // واحدةٍ يفرض عليه الخادمُ ذكرَها، فتُنتقى له سلفاً بدل أن يُسأل عمّا
        // جوابُه محسومٌ — ولا يُكتَب شيءٌ لا يفرضه الحارسُ أصلاً. والرابطُ أولى:
        // ما جاء في العنوانِ لا يُطمَس (السطرُ يتخطّى المفتاحَ الموجود).
        if (($cids = hub_company_ids()) !== null && count($cids) === 1
            && ($mk = (string) ($def['key'] ?? '')) !== 'companies') {
            $cf = collect($def['fields'] ?? [])->first(fn ($f) => ($f['type'] ?? '') === 'ref'
                && ($f['ref'] ?? '') === 'companies' && empty($f['multi']));
            if ($cf && ! array_key_exists($cf['key'], $prefill)
                && hub_field_mode(auth()->user(), $mk, (string) $cf['key']) === '') {
                $prefill[$cf['key']] = (string) $cids[0];
            }
        }

        /*
         * **الساعاتُ تصل مهمّتَها حين يُقترَح حقلُها** (v2.558 · قرارُ المالك).
         *
         * القياسُ على شهرِ المحاكاة كان صريحاً: **٥٨٠ تقريرَ عملٍ فيها ٤١٥٤٫٧٨
         * ساعة، ومربوطٌ منها بمهمّة: صفر.** والأنبوبُ سليمٌ منذ v2.545 (كان
         * `increment` على عمودٍ يقبل العدم فينتج `NULL`)، لكنّ الحقلَ اختياريٌّ
         * ولا يُملأ — فـ`act_h` صفرٌ في **كلِّ** المهامِّ المئةِ والإحدى والعشرين،
         * وكلفةُ العمالةِ صفرٌ في كلِّ مشروع، و«الالتزامُ بالميزانية» يمنح ١٠٠
         * لمن تجاوزها.
         *
         * والعلاجُ **اقتراحٌ لا إلزام** (القرار): تُنتقى للكاتبِ أحدثُ مهمّةٍ
         * مفتوحةٍ مُسنَدةٍ إليه، فيؤكّدها بنقرةٍ أو يغيّرها أو يمسحها — ولا
         * يُمنَع الحفظُ أبداً. وهو نظيرُ M-F6 أعلاه حرفاً بحرف: ما جوابُه
         * شبهُ محسومٍ يُقترَح بدل أن يُسأل عنه كلَّ يوم.
         *
         * والحرّاسُ القائمةُ تسري كما هي: `hub_scope` على المهامّ (فلا تُقترَح
         * مهمّةٌ خارجَ نطاقِه)، و`hub_field_mode` (فحقلٌ محجوبٌ لا يُملأ خلفَ
         * ظهرِ الحاجب)، والرابطُ أولى من الاقتراح.
         */
        if (($mk = (string) ($def['key'] ?? '')) === 'updates'
            && auth()->id() && hub_has_assignee_col('tasks')) {
            $tf = collect($def['fields'] ?? [])->first(fn ($f) => ($f['type'] ?? '') === 'ref'
                && ($f['ref'] ?? '') === 'tasks' && empty($f['multi']));
            if ($tf && ! array_key_exists($tf['key'], $prefill)
                && hub_field_mode(auth()->user(), $mk, (string) $tf['key']) === ''
                && hub_can(auth()->user(), 'tasks', 'v')) {
                try {
                    $tt = hub_mod('tasks')['table'] ?? 'tasks';
                    $cand = hub_scope(\Illuminate\Support\Facades\DB::table($tt)
                            ->whereNull('deleted_at'), 'tasks')
                        ->where('assignee_id', (string) auth()->id())
                        ->whereNotIn('status', hub_closed_states())
                        // ترتيبٌ حتميّ: الأحدثُ تحديثاً ثمّ `id` فاصلاً — لا قرعةَ
                        // بين المحرّكين عند تساوي الطابع (درسُ CLAUDE.md).
                        ->orderByDesc('updated_at')->orderByDesc('id')
                        ->value('id');
                    if ($cand) $prefill[$tf['key']] = (string) $cand;
                } catch (\Throwable $e) {}   // اقتراحٌ لا يُسقط النموذجَ إن تعذّر
            }
        }

        /*
         * **«⎘ نسخ كسجل جديد» كان يَعِد ولا يفعل** (الجولة 3): الزرُّ في صفحة
         * السجلّ يمرّر `?from=<id>`، و`from` ليس مفتاحَ حقلٍ فتُهمله الحلقةُ أعلاه
         * بصمت — فيُفتح نموذجٌ **فارغٌ تماماً**. أثبته وكيلان مستقلّان (مديرةُ
         * المشاريع ومستخدمٌ محترف)، وكلاهما كان ينسخ مشروعاً بيده حقلاً حقلاً.
         *
         * والنسخُ يمرّ بالحرّاس القائمة لا بحارسٍ ثانٍ ينحرف:
         *  ١) `findScoped` — قارئُ `show()` نفسُه: لا يُنسخ ما لا يُقرأ (تنطيقٌ
         *     وصلاحيّة)، وسجلٌّ خارجَ النطاق يُردّ كما يُردّ في العرض.
         *  ٢) **الفريدُ لا يُنسخ**: حقلٌ موسومٌ `unique` (رقمُ مستندٍ، رقمٌ تسلسليّ)
         *     نسخُه يصنع تصادماً أو سجلّاً كاذبَ الهويّة — يُترك فارغاً ليُملأ.
         *  ٣) **المحجوبُ لا يُسرَّب**: حقلٌ `hub_field_mode` تُخفيه أو تُقنّعه لا
         *     يُنسَخ — فالنسخُ لا يكون بابَ كشفٍ خلفيّاً لما لا يراه الناسخ.
         *  ٤) الطلبُ الصريحُ يغلب المنسوخ: ما جاء في الرابط يبقى فوق قيمةِ المصدر.
         */
        $from = (string) $r->query('from', '');
        if ($from !== '') {
            $src = $this->findScoped($class, $module, $from);
            foreach ($def['fields'] ?? [] as $f) {
                $k = $f['key'];
                if (array_key_exists($k, $prefill)) continue;              // الرابطُ أولى
                if (! empty($f['unique'])) continue;                       // الهويّةُ لا تُستنسخ
                // مفرداتُ `hub_field_mode`: '' (قابلٌ للتحرير) · 'ro' · 'hide'.
                // المخفيُّ لا يُسرَّب، والقراءةُ-فقط لا تُزرع في نموذجِ إنشاءٍ
                // لا يملك صاحبُه كتابتَها — فلا يُملأ حقلٌ سيُرفَض عند الحفظ.
                if (hub_field_mode(auth()->user(), $module, $k) !== '') continue;
                $v = $src->{$f['col'] ?? $k} ?? null;
                if ($v === null || $v === '' || is_array($v)) continue;
                $prefill[$k] = $v instanceof \DateTimeInterface
                    ? $v->format(($f['type'] ?? '') === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s')
                    : (string) $v;
            }
        }

        return view('modules.form', [
            'module' => $module, 'def' => $def, 'row' => null,
            'refOptions' => $this->refOptions($def), 'prefill' => $prefill,
        ]);
    }

    public function store(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'a');
        $r->validate($this->rules($def), [], $this->attrs($def));
        $this->guardProject($r, $module);
        $this->guardCompany($r, $module);
        $this->guardClient($r, $module);
        $this->guardAccountRequest($r, $module);

        $m = new $class;
        $this->fill($def, $r, $m);

        $this->stampAuthor($m, $module);
        $this->inheritCompany($m, $module);
        $this->inheritProject($m, $module);
        $this->inheritClient($m, $module);
        $this->applyDocumentAudience($r, $module, $m);   // WP-B.5 — مشاركةُ وثيقةٍ مع عميل

        // مستخدم محدود ينشئ مشروعاً: نضمن بقاءه ضمن نطاقه (مديراً أو عضواً)
        if ($module === 'projects' && hub_scoped(auth()->user())) {
            $me  = auth()->id();
            $mem = collect(is_array($m->members) ? $m->members : (json_decode($m->members ?? '[]', true) ?: []));
            if ($m->manager_id !== $me && ! $mem->contains($me)) {
                $m->members = $mem->push($me)->values()->all();
            }
            \Illuminate\Support\Facades\Cache::forget("user:{$me}:projects");
        }

        // مهمةٌ تُنشأ منجزةً (إدخالٌ رجعيّ/استيراد) تُختم كما لو انتقلت (الجولة 1 · F13):
        // كان الختمُ على التحوّل وحدَه، فبقيت «أُنجزت: 0» في لوحات الأداء لبياناتٍ منجزةٍ فعلاً
        $this->stampTaskCompletion($module, $m, null);
        $m->save();
        $this->notifyAssignee($def, $module, $m);
        $this->bustProgress($module, $m);
        \App\Support\Platform\FlowRunner::fire('created', $module, $m);

        /*
         * **موظفٌ جديد بحسابٍ جديد**: الربط بحسابٍ قائم يقع تلقائياً في نموذج
         * Employee (البريد هو الهوية)، وفتحُ حسابٍ جديد قرارٌ صريح يُطلب من
         * النموذج — بدورٍ مختار وكلمةِ مرورٍ مؤقتة تُعرض مرةً واحدة.
         */
        if ($module === 'hr' && $r->boolean('_make_account')) {
            $to = fn (string $key, string $msg) => redirect()
                ->route('m.show', [$module, $m->id])->with($key, $msg);

            // البريدُ وجد حسابَه فرُبط تلقائياً عند الإنشاء — لا حسابَ جديد، ويُقال ذلك
            if ($m->user_id) {
                return $to('ok', 'أُضيف الموظف ورُبط بحسابه القائم على هذا البريد'
                    . ' — لم يُنشأ حسابٌ جديد ولا كلمةُ مرورٍ مؤقتة.');
            }

            $res = \App\Support\Workforce\Staff::makeAccountResult($m, hub_str($r->input('_account_role')));
            if ($res['temp'] !== null) {
                return $to('ok', 'أُضيف الموظف وأُنشئ حسابه')
                    ->with('temp_password', $res['temp'])
                    ->with('temp_password_for', (string) $m->email);
            }

            // **ولا مآلَ صامت**: كلُّ ما لم يُنشأ يُقال سببُه
            return $to('err', match ($res['outcome']) {
                'linked'  => 'أُضيف الموظف ورُبط بحسابه القائم على هذا البريد — لم يُنشأ حسابٌ جديد.',
                'taken'   => 'أُضيف الموظف ولم يُفتح له حساب: لهذا البريد حسابٌ مرتبطٌ بملفٍّ وظيفيٍّ آخر،'
                           . ' ولا يُقتسَم حساب. راجع شاشة المستخدمين أو استعمل بريداً آخر.',
                'no_email' => 'أُضيف الموظف ولم يُفتح له حساب: لا بريد إلكتروني — والبريد هو هوية الدخول.',
                default   => 'أُضيف الموظف ولم يُفتح له حساب — راجع شاشة المستخدمين.',
            });
        }

        // عقدٌ غير موقّع → تحويله لمسار التوقيع الإلكتروني: يُضبط على «قيد التوقيع»
        // ويُنقل المستخدم لإنشاء طلب التوقيع مربوطاً بهذا العقد، مُهيَّأ سلفاً.
        if ($module === 'contracts' && $r->boolean('to_esign') && hub_can(auth()->user(), 'contracts', 'a')) {
            // v2.117: حفظٌ حقيقي لا saveQuietly — الانقلاب يُدقَّق ويُصدَر وتلتقطه المسارات
            $m->status = 'قيد التوقيع';
            $m->save();
            \App\Support\Platform\FlowRunner::fire('status', $module, $m, 'قيد التوقيع');

            return redirect()->route('esign.index', [
                'contract' => $m->id,
                'title'    => 'توقيع: ' . \Illuminate\Support\Str::limit((string) $m->title, 80),
            ])->with('ok', 'أُنشئ العقد بحالة «قيد التوقيع» — جهّز طلب التوقيع الآن وأرسله للطرف الآخر');
        }

        // «حفظ وإضافة آخر» — يبقيك في نموذج الإضافة للإدخال المتتابع
        if ($r->input('_stay')) {
            return redirect()->route('m.create', $module)->with('ok', 'أُضيف «' . \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? ''), 40) . '» — أدخل التالي');
        }

        // تطبيقٌ جديد يُستقبل في مركزه لا في قائمة الوحدة: اللقطاتُ المتعددة
        // والوصفُ يُرفعان من هناك، ولا سبيل إليهما قبل وجود السجل — فالرمي على
        // القائمة كان يترك «أضف تطبيقاً» بلا طريقٍ ظاهرٍ لصوره.
        if ($module === 'apps' && hub_can(auth()->user(), 'apps', 'v')) {
            return redirect(route('apps.center', $m->id) . '#shots')
                ->with('ok', 'أُضيف التطبيق — ارفع لقطات المتجر (عدة صور معاً) وأكمل وصفه من هنا');
        }

        return redirect()->route('m.index', $module)->with('ok', 'أُضيف السجل بنجاح');
    }

    public function show(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $row = $this->findScoped($class, $module, $id, 'with');
        [, $labels] = $this->columnsAndLabels($def, [$row], all: true);

        // الأسرار لا تُزرع في HTML — تُكشف عبر revealSecret ويُسجَّل «عرض حساس»
        // عند كل كشفٍ فعلي لا عند مجرد فتح الصفحة (كان يسم الفاتح كأنه كاشف)

        // السجلات المرتبطة: كل وحدة تشير لهذا السجل بحقل مرجعي
        $children = hub_related($module, $row->id);

        // آخر الإصدارات المحفوظة
        $versions = \App\Models\RecordVersion::where('module', $module)->where('record_id', $row->id)
            ->orderByDesc('version')->limit(10)->get();
        $verUsers = \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', $versions->pluck('changed_by')->filter())->pluck('name', 'id');

        // التعليقات على السجل + أسماء المستخدمين للمنشن
        $comments = CommentController::forRecord($module, $row->id);
        $cUsers   = CommentController::userNames();

        // المرفقات الشاملة
        [$attachments, $aUsers] = AttachmentController::forRecord($module, $row->id);

        // الخط الزمني الموحَّد — يدمج التدقيق والتعليقات والمرفقات والإصدارات
        $timeline = hub_timeline($module, $row->id);

        // عزلُ العميل (محقّق C1 — الحارسُ يغلب المصفوفة): حسابُ العميل — ولو مُنِح دورُه
        // صلاحيّةَ عرضِ وحدةٍ من قائمة PortalGuard البيضاء (projects/engagements/fin)
        // بالخطأ — لا يرى على الشاشة الداخليّة أثراً داخليّاً: لا مرفقاتٍ (ملفّاتٌ داخليّة)،
        // ولا إصداراتٍ (تاريخُ تحريرٍ داخليّ)، ولا خطاً زمنيّاً (يدمج التدقيق)، ولا تعليقاتِ
        // السجلّ الداخليّة. سطحُ العميلِ الصحيحُ بوّابتُه (/portal): وثائقُ audience المُنطَّقة
        // وغرفةُ العميل. الأبناءُ (children) مُرشَّحون audience سلفاً في hub_related (الطور D).
        if (hub_is_client(auth()->user())) {
            $attachments = collect();
            $aUsers      = collect();
            $versions    = collect();
            $verUsers    = collect();
            $comments    = collect();
            $cUsers      = [];
            $timeline    = [];
        }

        return view('modules.show', compact('module', 'def', 'row', 'labels', 'children', 'versions', 'verUsers', 'comments', 'cUsers', 'attachments', 'aUsers', 'timeline'));
    }

    /**
     * كشف سرّ عبر الخادم: القيمة لا تُطبع في مصدر الصفحة إطلاقاً — تُطلب هنا عند
     * الضغط على «إظهار»، فيُفرض علم الأسرار وقائمة «المستخدمين المخولين» (الخزنة)
     * ويُسجَّل «عرض حساس» عند كل كشفٍ فعلي باسم السر وحقله.
     */
    public function revealSecret(string $module, string $id, string $field)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $row = $this->findScoped($class, $module, $id);
        $u = auth()->user();

        abort_unless(hub_copy_secrets($u), 403, 'ليست لديك صلاحية رؤية الأسرار');

        $f = collect($def['fields'])->firstWhere('key', $field);
        abort_unless($f && ($f['type'] ?? '') === 'sec', 404);
        abort_if(hub_field_mode($u, $module, $field) === 'hide', 403);

        // «المستخدمون المخولون»: قائمة غير فارغة تحصر الكشف بأهلها — والمالك محصّن
        $allowed = array_values(array_filter(array_map('strval', (array) ($row->allowed_ids ?? []))));
        if ($allowed && ! hub_is_owner($u) && ! in_array((string) $u->id, $allowed, true)) {
            abort(403, 'هذا السر محصور بقائمة مخولين لست منهم');
        }

        // تصعيدُ المصادقة قبل الكشف. عامّاً: قابلٌ للضبط (مطفأٌ افتراضاً كي لا
        // يعطّل كشفاً متكرراً مشروعاً؛ يُشعَل للمنشآت التي تريد إعادة تحقّقٍ قبل كل
        // سرّ). وحقلٌ يعلن `stepup` (كـ PUK الذي يفكّ قفلَ الشريحةِ نهائياً —
        // Work OS · الطور G · §22) **يفرضه دائماً** بمعزلٍ عن المفتاح العامّ.
        // يُحسب قبل كتابةِ أثرِ «عرض حساس» فلا يُختم كشفٌ لم يقع.
        $needStepup = ! empty($f['stepup']) || (string) setting('security.stepup_secrets', '0') === '1';
        if ($needStepup && ($resp = hub_require_stepup())) {
            return $resp;
        }

        hub_audit('عرض حساس', $module, $row->id,
            (string) ($row->{hub_display_col($module)} ?? $row->id) . ' — ' . ($f['label'] ?? $field));

        // **حزمةُ استجابة**: كشفُ سرٍّ من الخزنة حدثٌ دلاليّ (vault.revealed) —
        // تعمل عليه التدفقات. يُطلق بالوحدة الفعلية فلا يُصدر إلا لـvault
        // (config('hub.events') لا يعرّف الحدثَ لغيرها)، وفشلُه لا يُفشل الكشف.
        try { \App\Support\Platform\FlowRunner::fire('revealed', $module, $row); } catch (\Throwable $e) { report($e); }

        return response()->json(['v' => (string) ($row->{$f['col']} ?? '')]);
    }

    public function edit(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $row = $this->findScoped($class, $module, $id);

        return view('modules.form', [
            'module' => $module, 'def' => $def, 'row' => $row,
            'refOptions' => $this->refOptions($def, $row),
        ]);
    }

    public function update(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $r->validate($this->rules($def, creating: false), [], $this->attrs($def));
        $this->guardProject($r, $module);
        $this->guardCompany($r, $module);
        $this->guardClient($r, $module);

        $m = $this->findScoped($class, $module, $id);

        // §30: تقريرُ العملِ المقبولُ لا يعيد الموظفُ كتابتَه صامتاً — يُعيده المدير/HR
        // للمراجعة أولاً (بأثرٍ مدقَّق). المالكُ/مسؤولُ الموارد البشرية غيرُ مقفولين.
        if ($module === 'updates' && $m instanceof \App\Models\WorkUpdate
            && \App\Support\Workforce\ReportReview::isLockedForEditor($m, auth()->user())) {
            return back()->withInput()->with('err',
                'هذا التقريرُ اعتمده المدير — لا يُعدَّل بعد الاعتماد. اطلب من مديرك إعادةَ فتحِه للتنقيح.');
        }

        /*
         * القفل التفاؤلي: عمود `version` كان يزيد ولا يُقارَن، والنموذج لا يبعث
         * نسخةً — فكاتبان على السجل نفسه، والثاني يدهس الأول **بلا إشارة**
         * وكلاهما يرى «حُفظت التعديلات». من يبعث نسخةً قديمة يُردّ برسالةٍ
         * تقول ماذا حدث؛ ومن لا يبعث نسخةً (API قديم أو سكربت) لا يُمنع.
         */
        $seen = $r->input('_version');
        if ($seen !== null && $seen !== '' && (int) $seen !== (int) $m->version) {
            return back()->withInput()->withErrors(['_version' =>
                'عدّل شخصٌ آخر هذا السجل بينما كنت تحرّره — افتحه من جديد وراجع تغييرك قبل الحفظ.']);
        }

        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            return $this->queueApproval($def, $module, 'e', $m, $r);
        }
        if ($module === 'projects' && hub_scoped(auth()->user())) {
            \Illuminate\Support\Facades\Cache::forget('user:' . auth()->id() . ':projects');
        }
        $prevAssignee = ($af = $this->assigneeField($def)) ? $m->{$af['col']} : null;
        $prevStatus = ($sc = hub_status_col($module)) ? $m->{$sc} : null;
        $this->fill($def, $r, $m);
        $this->applyDocumentAudience($r, $module, $m);   // WP-B.5 — مشاركةُ وثيقةٍ مع عميل
        $this->stampTaskCompletion($module, $m, $prevStatus === null ? null : (string) $prevStatus);
        $m->save();
        $this->notifyAssignee($def, $module, $m, $prevAssignee);
        $this->bustProgress($module, $m);
        \App\Support\Platform\FlowRunner::fire('updated', $module, $m);
        if ($sc && (string) $m->{$sc} !== (string) $prevStatus) {
            \App\Support\Platform\FlowRunner::fire('status', $module, $m, (string) $m->{$sc});
        }

        // (الجولة 1 · F14) مستندٌ ماليّ أُعيد اشتقاقُ حالته من المدفوع الفعليّ أثناء
        // الحفظ (إجماليٌّ تغيّر على مستندٍ مدفوع، أو «مدفوعة» زُرعت بلا مدفوع):
        // التصحيحُ لا يقع بصمت — الرسالةُ تقول ما جرى ولماذا.
        $okMsg = 'حُفظت التعديلات';
        if ($m instanceof \App\Models\FinDocument && $m->stateRederived) {
            $okMsg .= ' — وأُعيد اشتقاقُ حالة المستند من المدفوع الفعليّ: «' . $m->stateRederived['from']
                . '» ← «' . $m->stateRederived['to'] . '» (المدفوع ' . number_format((float) $m->paid, 2)
                . ' من إجمالي ' . number_format((float) $m->total, 2) . ')';
        }

        return redirect()->route('m.index', $module)->with('ok', $okMsg);
    }

    public function destroy(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $m = $this->findScoped($class, $module, $id);
        if (hub_needs_approval(auth()->user(), $module, 'd')) {
            return $this->queueApproval($def, $module, 'd', $m, request());
        }
        $m->delete();
        $this->bustDerivedCache($module, $m);   // حذفٌ يغيّر نسبة الإنجاز والربحية — أبطلهما

        // «تراجع» بجانب رسالة النجاح (الجولة 1 · F8): النقرةُ الطائشة تُصحَّح من مكانها
        return redirect()->route('m.index', $module)->with('ok', 'نُقل السجل إلى السلة')
            ->with('undo', route('m.restore', [$module, $m->id]));
    }

    public function restore(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $m = $this->findScoped($class, $module, $id, 'only');
        $this->performRestore($module, $m);

        return redirect()->route('m.index', [$module, 'trash' => 1])->with('ok', 'استُعيد السجل');
    }

    /**
     * **جوهرُ الاستعادة من السلة** — يُعاد استعمالُه من الويب وسطح الجوال (الطور D · F2):
     * استعادةٌ ثم إبطالُ الحساب المشتقّ (الاستعادةُ تعيد السجل للحساب).
     */
    public function performRestore(string $module, Model $m): void
    {
        $m->restore();
        $this->bustDerivedCache($module, $m);

        /*
         * **ما أغلقه الحذفُ يفتحه الاسترجاع** (الجولة 2 · G6): حذفُ الملفِّ
         * الوظيفيّ يُغلق حسابَ صاحبِه فوراً (`Employee::deleted` ⟵ `closeAccount`)،
         * ولم يكن للاسترجاع نظيرٌ — فالسجلُّ يعود والموظّفُ يبقى محروماً من الدخول
         * بلا أن يقول له أحدٌ لماذا (رصدها وكيلُ محاكاةِ التعيين: نقرةُ حذفٍ طائشة
         * ثم استرجاعٌ «ناجح» وحسابٌ موقوف). نفسُ سكّةِ العودة المستعملةِ حين تعود
         * حالةُ الملفِّ للعمل — لا مسارَ إعادةِ تفعيلٍ ثانٍ.
         */
        if ($m instanceof \App\Models\Employee
            && in_array((string) $m->status, \App\Support\Workforce\Staff::OPEN, true)) {
            \App\Support\Workforce\Staff::announceReturn($m);
        }
    }

    /**
     * **جوهرُ استعادةِ نسخةٍ سابقة** — يُعاد استعمالُه من الويب وسطح الجوال (الطور D · F2):
     * لقطةٌ خارجُ نطاقِ المستعيد لا تُعاد (نفسُ حرّاسِ التعديل)، ثم استعادةُ النسخة.
     *
     * يعيد رسالةَ خطأٍ (لقطةٌ خارج النطاق) أو null للنجاح؛ ويضع في `$restored` هل وُجدت
     * النسخةُ فعلاً (بوّابةُ الموافقة تُحسم في المُنادي قبله · F3).
     */
    public function performRestoreVersion(string $module, Model $row, int $version, bool &$restored): ?string
    {
        if ($why = $this->snapshotScopeError($module, $row, $version)) {
            $restored = false;

            return $why;
        }
        $restored = (bool) $row->restoreVersion($version);

        return null;
    }

    /** كانبان عام: أعمدة من خيارات الحالة، أو من القيم الفعلية إن لم تُعرّف */
    public function board(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $def['key'] = $module;
        $statusCol = hub_status_col($module);
        abort_unless($statusCol, 404, 'هذه الوحدة بلا حقل حالة');

        $options = $this->statusOptions($def);
        if (! $options) {
            // ترتيبٌ صريح: distinct بلا orderBy كان يعيد الأعمدة بترتيبٍ يقترعه المحرّك
            $options = hub_scope($class::whereNotNull($statusCol), $module)
                ->distinct()->orderBy($statusCol)->limit(8)->pluck($statusCol)->all();
        }
        abort_unless($options, 404, 'لا حالات معرّفة بعد — أضف سجلات أولاً');

        $trash = false; $filters = [];
        $rows = $this->buildQuery($r->merge(['status' => null]), $def, $class, $trash, $filters)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(400)->get();

        $disp = hub_display_col($module);
        $cols = [];
        foreach ($options as $o) $cols[$o] = [];
        foreach ($rows as $row) {
            $st = (string) ($row->{$statusCol} ?? '');
            // حالةٌ خارج أعمدة اللوحة (خيارٌ أُزيل من الإعداد، قيمة قديمة، فراغ):
            // كانت تُتخطى بصمت — سجلٌّ ظاهر في القائمة مختفٍ من اللوحة ولا أحد
            // يعلم أن لديه بطاقات ضائعة. عمودُ «غير مصنّفة» يُظهرها ليُصحَّح حالها.
            if (! isset($cols[$st])) { $cols['⚠ غير مصنّفة'][] = $row; continue; }
            $cols[$st][] = $row;
        }

        // إثراء البطاقات من تعريف الوحدة نفسه — يعمل لأي وحدة لها حالة دون تخصيص:
        // المسؤول (أول مرجع مفرد لمستخدم)، الاستحقاق (أول حقل تاريخ استحقاقي)،
        // الأولوية (حقل sel باسم priority)، والمرجع الأب (أول مرجع مفرد غير المستخدمين)
        $fields = collect($def['fields'] ?? []);
        $assigneeF = $fields->first(fn ($f) => ($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'users' && empty($f['multi']));
        $dueF = $fields->first(fn ($f) => ($f['type'] ?? '') === 'date' && preg_match('/due|end|deadline/i', $f['col']))
            ?? $fields->first(fn ($f) => ($f['type'] ?? '') === 'date');
        $prioF = $fields->first(fn ($f) => ($f['key'] ?? '') === 'priority' && ($f['type'] ?? '') === 'sel');
        // مرجع البطاقة الأب: أول مرجعٍ غير بشري **له قيم فعلاً** — مرجعٌ ثانوي
        // فارغ (كقرار المهمة) كان يحجب المرجع الحقيقي فتفقد البطاقة سياقها
        $refCands = $fields->filter(fn ($f) => ($f['type'] ?? '') === 'ref'
            && ($f['ref'] ?? '') !== 'users' && empty($f['multi']))->values();
        $refF = $refCands->first(fn ($f) => $rows->contains(fn ($r) => filled($r->{$f['col']} ?? null)))
             ?? $refCands->first();

        // قفل الحقل يسري على بطاقات اللوحة كما على القائمة والصفحة والتصدير: الحقل
        // المحجوب ('hide') لا يُثري البطاقة — وإلا سرّبت اللوحة (شاشةٌ موازية) ما
        // تُخفيه القائمة عبر hub_visible_fields.
        $fmHide = fn ($f) => $f && hub_field_mode(auth()->user(), $module, (string) ($f['key'] ?? '')) === 'hide';
        if ($fmHide($assigneeF)) $assigneeF = null;
        if ($fmHide($dueF)) $dueF = null;
        if ($fmHide($prioF)) $prioF = null;
        if ($fmHide($refF)) $refF = null;

        $assigneeNames = $assigneeF ? hub_ref_labels('users', $rows->pluck($assigneeF['col'])->all()) : [];
        $refNames = $refF ? hub_ref_labels($refF['ref'], $rows->pluck($refF['col'])->all()) : [];

        return view('modules.board', compact(
            'module', 'def', 'cols', 'disp', 'statusCol',
            'assigneeF', 'dueF', 'prioF', 'refF', 'assigneeNames', 'refNames'
        ));
    }

    /** تغيير حالة سجل (سحب وإفلات الكانبان) */
    /**
     * (WP-1.4) ختمُ إنجاز المهمة — «نسبةُ الالتزام» كانت ستُحسب من `updated_at`
     * فأيُّ تعديلٍ لاحقٍ على مهمةٍ منجزة يُفسد تاريخَ إنجازها. يُختم `completed_at`
     * عند دخول حالة الإنجاز («منجزة»/«مكتملة» — الإلغاءُ ليس إنجازاً) ويُمحى عند
     * مغادرتها؛ للمهام وحدها، وبحارس العمود كي لا يسقط الحفظ قبل الهجرة.
     */
    protected function stampTaskCompletion(string $module, Model $m, ?string $prev): void
    {
        if ($module !== 'tasks' || ! hub_has_col('tasks', 'completed_at')) return;
        $col = hub_status_col('tasks') ?: 'status';
        $done = ['منجزة', 'مكتملة'];
        $isDone = in_array((string) $m->{$col}, $done, true);
        $wasDone = in_array((string) $prev, $done, true);
        if ($isDone && ! $wasDone) $m->completed_at = now();
        elseif (! $isDone && $wasDone) $m->completed_at = null;
    }

    public function setStatus(Request $r, string $module, string $id)
    {
        // (الجولة 1 · F27) **تنطيقٌ ذاتيّ للمهام**: المسنَدُ إليه يحرّك حالةَ مهمّته
        // وتقدّمَها ولو لم يحمل `tasks:e` العامّة — سجلّي أنا أحرّكه، كالإجازات.
        // الواجهةُ كانت جاهزة (بطاقةُ `data-mine` تُسحب وtoast يعرض رسالةَ الخادم —
        // v2.496) والخادمُ كان يردّ ٤٠٣ قبل أن يعرف لمن البطاقة. البوّابةُ النهائيّة
        // بعد جلبِ السجلّ المنطَّق: البطاقةُ ليست له ⇒ ٤٠٣ برسالةٍ لا صمتَ عودة.
        $u = auth()->user();
        $selfScope = $module === 'tasks' && ! hub_can($u, $module, 'e');
        [$def, $class] = $this->resolve($module, $selfScope ? 'v' : 'e');

        // سحبُ البطاقة تعديلُ حالةٍ كأي تعديل: التعديل الفردي يصفّ طلباً،
        // والجماعي والاستعادة يُردّان — وكان السحب وحده يكتب مباشرةً.
        // القاعدة التي يلتفّ عليها سحبُ إصبعٍ ليست قاعدة.
        if ($msg = hub_block_if_queued($module, 'e')) abort(422, $msg);
        /*
         * **العمود لا المفتاح.** `$def['status']` مفتاحُ الحقل، وقد يخالف عمودَه
         * (`files`: `docStatus` ↔ `doc_status`). فسحبُ بطاقةٍ في لوحة كانبان على
         * وحدةٍ كهذه يكتب في عمودٍ لا وجود له: على MySQL `Unknown column` وخطأ
         * ٥٠٠، وعلى SQLite كتابةٌ صامتةٌ في لا شيء — البطاقةُ تعود مكانها والمستخدم
         * لا يفهم لماذا. أُصلح توأمُه في الإجراء الجماعي بـv2.208 وبقي هذا.
         */
        $statusCol = hub_status_col($module);
        abort_unless($statusCol, 404);

        $m = $this->findScoped($class, $module, $id);
        if ($selfScope) {
            $mine = (string) ($m->assignee_id ?? '');
            abort_unless($mine !== '' && $mine === (string) $u->id, 403,
                'تغييرُ حالة المهمة يتطلب صلاحيةَ تعديل المهام — أو أن تكون المهمةُ مسنَدةً إليك');
        }

        // (F27) تقدّمُ المهمة يُحدَّث مع حالتها في الحفظة نفسها (0–100) — اختياريّ،
        // ويحترم قناعَ الحقل كأيّ كتابة (حقلٌ «قراءة فقط» لدوره لا يُكتب بجرّة إصبع)
        if ($module === 'tasks' && $r->filled('progress')) {
            abort_if(hub_field_mode($u, 'tasks', 'progress') !== '', 403,
                'حقل نسبة الإنجاز غير قابل للكتابة بصلاحيتك');
            $p = hub_num($r->input('progress'));
            abort_if($p === null || $p < 0 || $p > 100, 422, 'نسبةُ الإنجاز عددٌ بين 0 و100');
            $m->progress = $p;
        }

        // جوهرُ الانتقال مشترَكٌ مع سطح الجوال (الطور D · Critic F2): الحرّاسُ نفسُها
        // (قناعُ الحقل، الخياراتُ المعرَّفة، status_via_action، requires) ثم الختمُ والحفظ.
        $this->applyStatusTransition($def, $module, $m, hub_str($r->input('status')));

        return response()->json(['ok' => 1]);
    }

    /**
     * **جوهرُ انتقالِ الحالة** — يُعاد استعمالُه من الويب (`setStatus`/السحب) ومن سطح
     * الجوال (الطور D · تنفيذُ الإجراءات · Critic F2): الحرّاسُ (قناعُ الحقل، الخياراتُ
     * المعرَّفة، `status_via_action`، `requires`) ثم الختمُ والحفظُ وإطلاقُ حدثِ الحالة.
     *
     * **بوّابةُ الموافقة تُحسم قبل هذا الجوهر (F3):** الويبُ عبر `hub_block_if_queued`
     * (٤٢٢)، والجوالُ عبر تصفيفِ طلبٍ (`ApprovalService::submit`) — فالجوهرُ انتقالٌ صافٍ
     * لا يعرف الموافقات. يعيد `['changed', 'from', 'to']`.
     */
    public function applyStatusTransition(array $def, string $module, Model $m, string $newStatus): array
    {
        $statusCol = hub_status_col($module);
        abort_unless($statusCol, 404);

        // قناعُ الحقل: عمودُ حالةٍ «قراءة فقط» لدور المستخدم لا يُكتب بجرّة إصبع
        $statusField = hub_status_field($module);
        $statusKey = (string) ($statusField['key'] ?? $statusCol);
        abort_if(hub_field_mode(auth()->user(), $module, $statusKey) !== '', 403,
            'حقل الحالة غير قابل للكتابة بصلاحيتك');

        // خيارٌ غيرُ معرَّفٍ لا يُزرع (فلا يُعدّ في إحصاء ولا يُطلق أتمتة)
        $options = (array) ($statusField['options'] ?? []);
        abort_if($options && ! in_array($newStatus, $options, true), 422, 'حالة غير معرَّفة في هذه الوحدة');
        // حالةٌ يعلنها السجلّ «تُشتقّ من فعل» (status_via_action) لا تُكتب مباشرةً: كانت «مدفوعة»
        // تُزرع بلا مبلغٍ مدفوع فتُطلق invoice.paid على فاتورةٍ لم تُدفع (ARCH-03, v2.399)
        if ($why = ($def['status_via_action'][$newStatus] ?? null)) abort(422, $why);

        // **قيمةُ القرارِ لا تُكتب من بابِ الحالةِ المباشر** (مجلس الخبراء · الخبير ١٤):
        // سحبُ البطاقةِ في كانبان — ونظيرُه في الجوّال — كان يعتمد الإجازةَ ويخصم
        // الرصيد. والحارسُ هنا لأنّ هذا **الجوهرُ المشترك** بين البابين.
        \App\Support\Platform\DecisionFields::guardStatusWrite($module, $m, $newStatus);

        $prevStatus = $m->{$statusCol};
        $m->{$statusCol} = $newStatus;
        // ── Control Plane: Phase 6 (WP-6.1) ── بوّابةُ «الحالة تتطلب حقولاً» بعد الضبط
        $this->guardStatusRequires($def, $m);
        $this->stampTaskCompletion($module, $m, $prevStatus === null ? null : (string) $prevStatus);
        $m->save();
        $this->bustProgress($module, $m);

        $changed = (string) $m->{$statusCol} !== (string) $prevStatus;
        if ($changed) {
            \App\Support\Platform\FlowRunner::fire('status', $module, $m, (string) $m->{$statusCol});
        }

        return ['changed' => $changed, 'from' => $prevStatus, 'to' => (string) $m->{$statusCol}];
    }

    // ── Control Plane: Phase 6 (WP-6.1) ──
    /**
     * بوّابةُ «الحالة تتطلّب حقولاً» (§8.5) — مفتاحُ `requires` في سجلّ الوحدة
     * (بجوار نمط `status_via_action`) لا فرعٌ لكل وحدةٍ في المتحكّم:
     *
     *   'requires' => ['مغلق بتقرير' => ['when' => ['severity' => ['حرج','عالي']],
     *                                    'fields' => ['rootCause','steps','prevention'], 'why' => '…']]
     *
     * تُفحص **حالةُ النموذج بعد التعبئة** لا الطلبُ وحده — فالحقلُ المطلوب قد
     * يكون محفوظاً سلفاً على السجل. تُستدعى من `fill()` (تحديثُ الويب والـAPI
     * والإنشاء وتنفيذُ الموافقة — كلُّها تمرّ به) ومن `setStatus` (السحب) ومن
     * حلقة `bulk(do=status)` — فالحارسُ الذي يُطبَّق في بابٍ ويُنسى في آخر
     * ليس حارساً بل قناعةٌ كاذبة.
     */
    protected function guardStatusRequires(array $def, Model $m): void
    {
        if ($rule = $this->statusRequiresRule($def, $m)) {
            abort(422, $rule['why'] . ' — الناقص: ' . implode('، ', $rule['fields']));
        }
    }

    /**
     * **قاعدةُ «الحالة تتطلّب حقولاً» مُقيَّمةً على السجل** — جوهرٌ مقروءٌ استُخرج من
     * `guardStatusRequires` (Mobile Readiness · الطور D · Critic F2): يعيد الحقولَ
     * الناقصةَ و«لماذا» إن كانت قاعدةُ `requires` لحالةِ السجل **الحاليّة** تنطبق
     * وتنقصها حقول؛ وإلا `null`. يُستدعى من:
     *   · `guardStatusRequires` (الويبُ والـAPI والسحبُ والجماعيّ) — يُجهض ٤٢٢ به،
     *   · معاينةُ إجراءاتِ الجوال (D.2) — تُسقط من الـallowlist انتقالاً محظوراً
     *     (على نسخةٍ مستنسخةٍ حالتُها الهدف) **بلا تنفيذٍ ولا إجهاض**.
     * فلا يُكرَّر منطقُ القاعدة في سطحين. الرسالةُ والإجهاضُ يبقيان حرفاً بحرف.
     *
     * @return array{fields:array<int,string>,why:string}|null
     */
    protected function statusRequiresRule(array $def, Model $m): ?array
    {
        $reqs = (array) ($def['requires'] ?? []);
        if (! $reqs) return null;

        $fields = collect($def['fields'] ?? []);
        $col = fn (string $key) => $fields->firstWhere('key', $key)['col'] ?? $key;

        $rule = $reqs[(string) ($m->{$col((string) ($def['status'] ?? 'status'))} ?? '')] ?? null;
        if (! $rule) return null;

        // شرطُ الانطباق (when): البوّابة لمن تلزمه وحده — الشدّةُ المنخفضة تمرّ
        foreach ((array) ($rule['when'] ?? []) as $k => $vals) {
            if (! in_array((string) ($m->{$col($k)} ?? ''), (array) $vals, true)) return null;
        }

        $missing = [];
        foreach ((array) ($rule['fields'] ?? []) as $k) {
            if (trim((string) ($m->{$col($k)} ?? '')) === '') {
                $missing[] = (string) ($fields->firstWhere('key', $k)['label'] ?? $k);
            }
        }

        return $missing
            ? ['fields' => $missing, 'why' => (string) ($rule['why'] ?? 'هذه الحالة تتطلب حقولاً قبل بلوغها')]
            : null;
    }

    /** تصدير CSV بنفس فلاتر القائمة الحالية (BOM ليقرأ Excel العربية) */
    public function export(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $def['key'] = $module;

        $trash = false; $filters = [];
        $rows = $this->buildQuery($r, $def, $class, $trash, $filters)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5000)->get();

        if ($resp = $this->exportBelt($module, $rows->count(), 'سجل (CSV)', $def)) return $resp;

        // البتر لا يكون صامتاً: من صدّر قائمةً أكبر من السقف يعلم أنها قُصّت
        return $this->streamCsv($module, $def, $rows, $rows->count() >= 5000);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleExport::exportBelt` (docs/REORG_PLAN.md §R6) */
    protected function exportBelt(string $module, int $count, string $unitLabel, array $def = [])
    {
        return \App\Support\Platform\Modules\ModuleExport::exportBelt($module, $count, $unitLabel, $def);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleExport::exportOutsideWorkHours` (docs/REORG_PLAN.md §R6) */
    protected function exportOutsideWorkHours(): bool
    {
        return \App\Support\Platform\Modules\ModuleExport::exportOutsideWorkHours();
    }

    /** مفوِّضٌ — المنطقُ في `ModuleExport::exportColumnsInclude` (docs/REORG_PLAN.md §R6) */
    protected function exportColumnsInclude(array $def, string $key): bool
    {
        return \App\Support\Platform\Modules\ModuleExport::exportColumnsInclude($def, $key);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleExport::streamCsv` (docs/REORG_PLAN.md §R6) */
    protected function streamCsv(string $module, array $def, $rows, bool $truncated = false)
    {
        return \App\Support\Platform\Modules\ModuleExport::streamCsv($module, $def, $rows, $truncated);
    }

    /**
     * إجراءات جماعية على تحديد من القائمة — كل سجل يمر بنفس بوابات مساره الفردي:
     * النطاق يُطبَّق فيُتخطى ما خرج عنه بصمت (تحديدٌ مختلط لا يفشل كله)، والحالة
     * تُطلق مسارات العمل لكل سجل كما لو غُيّرت يدوياً، والحذف يُرفض كلياً لمن
     * حذفُه مشروط بالموافقات (فالتوثيق الفردي أصل لا يُلتف عليه بالجملة).
     */
    public function bulk(Request $r, string $module)
    {
        $do = hub_str($r->input('do'));
        $ids = array_slice(array_values(array_filter((array) $r->input('ids', []), 'is_string')), 0, 200);
        abort_unless(count($ids) > 0, 400, 'لم تُحدد سجلات');

        if ($do === 'export') {
            [$def, $class] = $this->resolve($module, 'v');
            $def['key'] = $module;
            $rows = hub_scope($class::query(), $module)->whereIn('id', $ids)
                ->orderByDesc('created_at')->orderByDesc('id')->get();
            // نفسُ حزام export(): تجميدُ الطوارئ وعتبةُ التصعيد ووسمُ التدقيق —
            // «تصدير المحدد» تصديرٌ كاملٌ لا استثناءَ له من المفتاح
            if ($resp = $this->exportBelt($module, $rows->count(), 'سجل محدد (CSV جماعي)', $def)) return $resp;

            return $this->streamCsv($module, $def, $rows);
        }

        if ($do === 'status') {
            [$def, $class] = $this->resolve($module, 'e');
            /*
             * ثلاثةُ حرّاسٍ كانت في المسار الفردي وغابت هنا — والحارسُ الذي
             * يُطبَّق في بابٍ ويُنسى في آخر ليس حارساً بل قناعةٌ كاذبة:
             *
             *   · **العمود لا المفتاح**: `$def['status']` مفتاحُ الحقل، وقد
             *     يخالف عمودَه (`docStatus` ↔ `doc_status`) — على MySQL:
             *     Unknown column وخطأ ٥٠٠، وعلى SQLite كتابةٌ صامتة في لا شيء.
             *   · **وضعُ الحقل**: من حالتُه «قراءة فقط» يُمنع فرداً ويكتب جملةً.
             *   · **الموافقات**: تعديلُه يمرّ بالطابور فرداً، ويمرّ هنا بلا
             *     طلبٍ ولا توثيق — والالتفافُ بالجملة أوسعُ أثراً من الفرد.
             */
            $statusCol = hub_status_col($module);
            abort_unless($statusCol, 404, 'هذه الوحدة بلا حقل حالة');

            $sf = hub_status_field($module);
            $fm = hub_field_mode(auth()->user(), $module, (string) ($sf['key'] ?? 'status'));
            abort_if($fm !== '', 403, 'حقل الحالة ليس لك تعديله في هذه الوحدة');

            if (hub_needs_approval(auth()->user(), $module, 'e')) {
                return back()->with('err',
                    'تعديلُك يمر بالموافقات — غيّر الحالة فردياً ليُوثَّق كل طلب على حدة');
            }

            $to = hub_str($r->input('status'));
            $opts = $this->statusOptions($def);
            abort_unless($to !== '' && (! $opts || in_array($to, $opts, true)), 422, 'حالة غير معروفة');

            $n = 0; $failed = [];
            foreach ($ids as $id) {
                $m = hub_scope($class::query(), $module)->whereKey($id)->first();
                if (! $m || (string) $m->{$statusCol} === $to) continue;
                $m->{$statusCol} = $to;
                try {
                    // نفسُ حارسِ البابِ الفرديّ — **والالتفافُ بالجملة أوسعُ أثراً
                    // من الفرد**؛ داخلَ `try` فيُنسب الرفضُ لسجلِّه ولا يقطع الدفعة
                    \App\Support\Platform\DecisionFields::guardStatusWrite($module, $m, $to);
                    // ── Control Plane: Phase 6 (WP-6.1) ── البوّابة داخل try: رفضُها
                    // رفضُ سجلٍّ يُنسب لصاحبه (refusal) ولا يقطع الدفعة
                    $this->guardStatusRequires($def, $m);
                    $m->save();
                } catch (\Throwable $e) {
                    $failed[] = $this->refusal($m, $e, $module);   // رفضُ حارسٍ خطأُ سجلٍّ لا خطأُ دفعة
                    continue;
                }
                $this->bustProgress($module, $m);
                \App\Support\Platform\FlowRunner::fire('status', $module, $m, $to);
                $n++;
            }

            return $this->bulkResult($n, $failed, "غُيّرت حالة {$n} من السجلات إلى «{$to}»");
        }

        if ($do === 'delete') {
            [$def, $class] = $this->resolve($module, 'd');
            if (hub_needs_approval(auth()->user(), $module, 'd')) {
                return back()->with('err', 'حذفُك يمر بالموافقات — احذف السجلات فردياً ليُوثَّق كل طلب على حدة');
            }
            $n = 0; $failed = [];
            foreach ($ids as $id) {
                $m = hub_scope($class::query(), $module)->whereKey($id)->first();
                if (! $m) continue;
                try {
                    $m->delete();
                } catch (\Throwable $e) {
                    $failed[] = $this->refusal($m, $e, $module);
                    continue;
                }
                $n++;
            }

            return $this->bulkResult($n, $failed, "نُقل {$n} من السجلات إلى السلة");
        }

        abort(400, 'إجراء غير معروف');
    }

    /**
     * سببُ امتناع سجلٍّ في إجراءٍ جماعيّ — مُسمّى بصاحبه.
     *
     * حرّاسُ النماذج ترمي `ValidationException` أو `abort()`، وكلاهما في المسار
     * الفرديّ رسالةٌ صحيحة. أمّا في الحلقة فالإلقاءُ **يقطعها**، فتذهب السجلاتُ
     * السابقةُ وتنجو التاليةُ ولا يُقال أيٌّ من أيّ. فيُلتقط الرفضُ هنا ويُنسب
     * إلى سجلِّه بالاسم لا بالمعرّف الخام.
     *
     * وما ليس رفضاً مقصوداً (عطلُ قاعدةٍ مثلاً) يُسجَّل في مركز الأخطاء ولا
     * تُعرض تفاصيلُه: الرسالةُ الخام قد تحمل جزءَ استعلامٍ أو قيمةَ عمود.
     */
    protected function refusal(Model $m, \Throwable $e, string $module): string
    {
        $name = hub_str($m->{hub_ref_display($module)} ?? null) ?: (string) $m->getKey();
        $name = Str::limit($name, 40);

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $why = collect($e->errors())->flatten()->first() ?: $e->getMessage();
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $why = $e->getMessage();
        } else {
            // بالرسالة الآمنة (v2.399): الخامُ يحمل قيمَ الأعمدة والاستعلامَ — ويُدفع إشعاراً للمالكين
            \App\Support\Ops\ErrorLog::capture('bulk', \App\Support\Ops\ErrorLog::safeMessage($e), $e->getFile(), $e->getLine());
            $why = 'عطلٌ غير متوقع — سُجّل في مركز الأخطاء';
        }

        return '«' . $name . '»: ' . Str::limit(hub_str($why), 120);
    }

    /** حصيلةُ إجراءٍ جماعيّ: ما وقع وما امتنع، في رسالةٍ واحدة صادقة */
    protected function bulkResult(int $n, array $failed, string $okMsg)
    {
        if (! $failed) return back()->with('ok', $okMsg);

        $head = count($failed) . ' تعذّر';
        $why  = implode('؛ ', array_slice($failed, 0, 3)) . (count($failed) > 3 ? ' …' : '');

        // نجاحٌ جزئيّ يُعرض نجاحاً **وتحذيراً** معاً: إخفاءُ أحدهما يجعل
        // المستخدم يظنّ أنّ شيئاً لم يقع، أو أنّ كلَّ شيءٍ وقع
        return $n > 0
            ? back()->with('ok', $okMsg)->with('warn', $head . ': ' . $why)
            : back()->with('err', $head . ': ' . $why);
    }

    public function restoreVersion(string $module, string $id, int $version)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $row = $this->findScoped($class, $module, $id);

        // الاستعادةُ تعديلٌ كامل — فتمرّ بطابور الموافقات كما يمرّ نموذج التعديل،
        // وإلا صارت البابَ الذي يُكتب منه ما يُمنع كتابتُه من الباب المجاور
        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            return back()->with('err',
                'تعديلُك يمر بالموافقات — لا تُستعاد النسخ مباشرةً؛ افتح السجل وقدّم التعديل ليُوثَّق');
        }

        // (v2.399) لقطةٌ قديمة قد تحمل شركةً/عميلاً/مشروعاً خارج نطاق المستعيد — الاستعادةُ
        // لا تُخرج السجلَّ من نطاقه: حارسُ الشركة والمشروع نفسُه عبر السكّة المشتركة (الطور D · F2).
        $restored = false;
        if ($why = $this->performRestoreVersion($module, $row, $version, $restored)) {
            return back()->with('err', $why);
        }
        abort_unless($restored, 422, 'النسخة غير موجودة');

        return back()->with('ok', "استُعيدت النسخة $version وحُفظت كنسخة جديدة");
    }

    /** هل تُعيد النسخةُ أعمدةَ العزل إلى قيمٍ خارج نطاق المستخدم؟ — رسالةٌ أو null */
    protected function snapshotScopeError(string $module, Model $row, int $version): ?string
    {
        $u = auth()->user();
        if (! $u || hub_is_owner($u) || ! method_exists($row, 'versions')) return null;
        $v = $row->versions()->where('version', $version)->first();
        if (! $v) return null;
        $snap = (array) $v->snapshot;
        $checks = [
            [hub_company_col($module), hub_company_ids($u), 'شركةٍ'],
            [hub_client_col($module), hub_client_ids($u), 'عميلٍ'],
            [hub_project_col($module), hub_scoped($u) ? $u->visibleProjectIds() : null, 'مشروعٍ'],
        ];
        foreach ($checks as [$col, $allowed, $label]) {
            if (! $col || $allowed === null) continue;
            $val = hub_str($snap[$col] ?? null);
            if ($val !== '' && ! in_array($val, array_map('strval', $allowed), true)) {
                return "هذه النسخة تُعيد السجلَّ إلى {$label} خارج نطاقك — لا تُستعاد من حسابك";
            }
        }

        return null;
    }

    /* ────────── أدوات داخلية ────────── */

    /**
     * العمليات المحمية: بدل التنفيذ يُصفّ طلب موافقة بحمولة التعديل، ويُشعَر المعتمدون.
     *
     * المنطقُ (التقاطُ الحمولة المنقّاة + إنشاءُ الطلب + الإشعار) انتقل إلى السكّة
     * المشتركة `ApprovalService::submit` (Mobile Readiness · الطور D · Critic F3) —
     * يستدعيها الويبُ هنا فيعيد التوجيهَ كما كان، وتستدعيها الكتابةُ المحمية في الجوال
     * فتعيد `APPROVAL_REQUIRED` بوجهةِ اعتماداتٍ بدل «نفّذها من الواجهة» المسدودة.
     */
    protected function queueApproval(array $def, string $module, string $op, Model $m, Request $r)
    {
        \App\Support\Platform\ApprovalService::submit($def, $module, $op, $m, $r);

        return redirect()->route('m.index', $module)
            ->with('ok', 'هذه العملية محمية — أُرسل طلب الموافقة للمعتمدين وسيصلك إشعار بالقرار');
    }

    /**
     * **إعادةُ حمولةِ موافقةٍ معتمدة على السجل** بمحرّك `fill` نفسِه — سِنُّ عرضٍ عامٌّ
     * يستدعيه `ApprovalService::decide` (Mobile Readiness · الطور D · Critic F2). لا
     * نسخَ لمنطق التعبئة: الكتابةُ محصورةٌ بمفاتيح الحمولة (المعتمِد وافق على **هذه**
     * التغييرات)، ويُبطَل المشتقُّ إن تغيّر شيءٌ فعلاً. يعيد هل تغيّر السجلُّ حقّاً.
     */
    public function applyApprovedPayload(array $def, string $module, Model $m, array $payload): bool
    {
        $req = Request::create('/', 'POST', $payload);
        $this->fill($def, $req, $m, array_keys($payload));
        // لا يُقال «نُفّذ» وشيءٌ لم يُنفَّذ: حمولةٌ تطابق الحاضر تُحفظ بلا أثر
        $did = $m->isDirty();
        if ($did) {
            $m->save();
            $this->bustProgress($module, $m);
        }

        return $did;
    }

    /** مفوِّضٌ — المنطقُ في `ModuleCache::bustDerivedCache` (docs/REORG_PLAN.md §R6) */
    protected function bustDerivedCache(string $module, Model $m): void
    {
        \App\Support\Platform\Modules\ModuleCache::bustDerivedCache($module, $m);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleCache::bustProgress` (docs/REORG_PLAN.md §R6) */
    protected function bustProgress(string $module, Model $m): void
    {
        \App\Support\Platform\Modules\ModuleCache::bustProgress($module, $m);
    }

    /** حقل المسؤول (assigneeId → users) إن وُجد في الوحدة */
    /**
     * حقل المسؤول الذي يستحق إشعار الإسناد — كان يشترط المفتاح assigneeId حرفياً
     * فلا يُبلَّغ منفّذ القرار (execId) ولا مدير الإجازة (mgrId) ولا مقابِل
     * المرشح (interviewer) أبداً. أول مرجعِ مستخدمين مفرد من القائمة يفوز.
     */
    protected function assigneeField(array $def): ?array
    {
        foreach (['assigneeId', 'execId', 'mgrId', 'interviewer'] as $key) {
            $f = collect($def['fields'])->firstWhere('key', $key);
            if ($f && ($f['ref'] ?? '') === 'users' && empty($f['multi'])) return $f;
        }

        return null;
    }

    /** إشعار داخلي للمسؤول عند إسناده سجلاً (مهمة/تذكرة/ميزة…) — لا إشعار لمن أسند لنفسه */
    protected function notifyAssignee(array $def, string $module, Model $m, ?string $prev = null): void
    {
        $f = $this->assigneeField($def);
        if (! $f) return;
        $to = $m->{$f['col']} ?? null;
        if (! $to || $to === $prev || $to === auth()->id()) return;

        hub_notify($to, 'assign',
            'أُسند إليك في ' . $def['label'] . ': '
            . \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? ''), 60)
            . ' — بواسطة ' . auth()->user()->name,
            $module, $m->id);

        /*
         * **إسنادٌ لأعمى عن وحدتِه** (الجولة 2 · G14): قَبِل النظامُ صامتاً إسنادَ
         * تذكرةٍ وقيادةَ حادثةٍ لمن لا يملك رؤيةَ وحدتِهما، فوصله إشعارٌ مقنَّعٌ
         * «سجلٌّ في وحدةٍ لا تراها» — فلا هو يعرف ما أُسند إليه ولا المُسنِدُ يعرف
         * أنّ إسنادَه ذهب سدىً (رصدها وكيلا محاكاةِ التعيين والحادثة). الإسنادُ
         * يمضي (قرارُ المُسنِد لا يُلغى)، لكنّ المُسنِدَ يُصارَح ليمنحَ الصلاحيّة.
         */
        if (($target = \App\Models\User::find($to)) && ! hub_can($target, $module, 'v')) {
            session()->flash('warn', trim((string) $target->name) . ' لا يملك رؤيةَ «' . $def['label']
                . '» — أُسند إليه ووصله الإشعار، لكنّه لن يفتح السجلّ حتى تُمنح له رؤيةُ الوحدة.');
        }
    }

    /** إيجاد سجل داخل نطاق المستخدم — الوصول المباشر بالرابط لسجل خارج النطاق = 404 */
    protected function findScoped(string $class, string $module, string $id, string $trash = 'none'): Model
    {
        $q = match ($trash) {
            'with'  => $class::withTrashed(),
            'only'  => $class::onlyTrashed(),
            default => $class::query(),
        };

        return hub_scope($q, $module)->findOrFail($id);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::guardProject` (docs/REORG_PLAN.md §R6) */
    protected function guardProject(Request $r, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::guardProject($r, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::stampAuthor` (docs/REORG_PLAN.md §R6) */
    protected function stampAuthor(Model $m, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::stampAuthor($m, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::inheritCompany` (docs/REORG_PLAN.md §R6) */
    protected function inheritCompany(Model $m, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::inheritCompany($m, $module);
    }

    /**
     * ونظيرُها للمشروع (v2.314): `guardProject` يحرس ما **في النموذج**، لكن
     * وحداتٍ كثيرة لها عمود `project_id` بلا حقلِ مشروعٍ في نموذجها (قاعدة
     * المعرفة، والمهام، وغيرها). فمستخدمُ `scope='proj'` كان يُنشئ فيها سجلاً
     * بـ`project_id = NULL` ثمّ يُقصيه `hub_scope` **فوراً**: يفتحه فيرى ٤٠٤،
     * ولا يظهر في قائمته — سجلٌّ يتيمٌ على منشئه نفسه، بلا كلمةٍ تقول لماذا.
     * يرثُ أولَ مشاريعه المرئية، بنفس منطق وراثة الشركة أعلاه.
     */
    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::inheritClient` (docs/REORG_PLAN.md §R6) */
    protected function inheritClient(Model $m, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::inheritClient($m, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::applyDocumentAudience` (docs/REORG_PLAN.md §R6) */
    protected function applyDocumentAudience(Request $r, string $module, Model $m): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::applyDocumentAudience($r, $module, $m);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::inheritProject` (docs/REORG_PLAN.md §R6) */
    protected function inheritProject(Model $m, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::inheritProject($m, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::guardClient` (docs/REORG_PLAN.md §R6) */
    protected function guardClient(Request $r, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::guardClient($r, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::guardCompany` (docs/REORG_PLAN.md §R6) */
    protected function guardCompany(Request $r, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::guardCompany($r, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleTenancy::guardAccountRequest` (docs/REORG_PLAN.md §R6) */
    protected function guardAccountRequest(Request $r, string $module): void
    {
        \App\Support\Platform\Modules\ModuleTenancy::guardAccountRequest($r, $module);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleExport::columnsAndLabels` (docs/REORG_PLAN.md §R6) */
    protected function columnsAndLabels(array $def, array $rows, bool $all = false): array
    {
        return \App\Support\Platform\Modules\ModuleExport::columnsAndLabels($def, $rows, $all);
    }

    /** خيارات حالة الوحدة (إن وُجد عمود حالة) */
    protected function statusOptions(array $def): array
    {
        // البحث كان بـ`col` والقيمة **مفتاح**: في الوثائق `docStatus` مقابل
        // `doc_status` فلا يُطابَق شيء وتعود القائمة فارغة — فلا فلتر حالةٍ
        // في الشاشة ولا تغييرَ جماعي، بلا رسالةٍ تقول لماذا
        return hub_status_field($def['key'] ?? '')['options'] ?? [];
    }

    /** خيارات كل الحقول المرجعية للنموذج — مع ضمان ظهور قيم السجل الحالية */
    protected function refOptions(array $def, $row = null): array
    {
        $out = [];
        foreach (collect($def['fields'])->where('type', 'ref') as $f) {
            $cur = $row?->{$f['col']} ?? null;
            if (is_string($cur) && ! empty($f['multi'])) $cur = json_decode($cur, true) ?: [];
            // (الطور H · WP-H.1) حافّةُ بنيةٍ (`edge`) والقارئُ لا يملك وحدةَ طرفها
            // الآخر: لا تعدادَ لخياراتها في النموذج (تعدادُ أكوادِ المحطات تسريبُ
            // وجود) — تبقى القيمةُ الحاليّةُ وحدَها مقنَّعةً «—» كي لا يُفرَّغ الرابطُ
            // القائم صامتاً عند الحفظ (درسُ hub_ref_options عن القيمة خارج الحدّ).
            if (! empty($f['edge']) && ! hub_can(auth()->user(), (string) $f['ref'], 'v')) {
                $out[$f['key']] = array_fill_keys(
                    array_map('strval', array_filter((array) $cur)), '—');
                continue;
            }
            // **القارئُ المنطَّق الواحد** (v2.399): كان هذا الموضع يعيد بناء مرشّحَي المشاريع
            // والشركات بيده ويُغفل العملاء — فالمعزولُ على عميلٍ يرى أسماءَ كل العملاء في
            // القوائم المنسدلة. `hub_ref_options_scoped` يطبّق الثلاثة معاً في مكانٍ واحد.
            $out[$f['key']] = hub_ref_options_scoped($f['ref'], $cur);
        }
        return $out;
    }

    /** مفوِّضٌ — المنطقُ في `ModuleValidation::rules` (docs/REORG_PLAN.md §R6) */
    protected function rules(array $def, bool $creating = true): array
    {
        return \App\Support\Platform\Modules\ModuleValidation::rules($def, $creating);
    }

    /** مفوِّضٌ — المنطقُ في `ModuleValidation::attrs` (docs/REORG_PLAN.md §R6) */
    protected function attrs(array $def): array
    {
        return \App\Support\Platform\Modules\ModuleValidation::attrs($def);
    }

    /** تعبئة الموديل من الطلب حسب نوع كل حقل */
    /**
     * @param ?array $only حصرُ الكتابة بمفاتيحَ بعينها — لإعادة تشغيل حمولة
     *   موافقةٍ معتمَدة: المعتمِد وافق على **تلك** التغييرات لا على تفريغ ما
     *   عداها. وبغير الحصر يُكتب null فوق كل حقلٍ غاب عن الحمولة.
     */
    protected function fill(array $def, Request $r, Model $m, ?array $only = null): void
    {
        // حقولُ القرارِ تُلتقط قبل التعبئة لتُقارن بعدها (انظر `DecisionFields`)
        $decision = \App\Support\Platform\DecisionFields::capture((string) ($def['key'] ?? ''), $def, $m);

        foreach ($def['fields'] as $f) {
            $k = $f['key']; $c = $f['col']; $t = $f['type'];

            if ($only !== null && ! in_array($k, $only, true)) continue;

            // حقل مخفي أو قراءة فقط لدور المستخدم: لا يُكتب أبداً (حتى لو حُقن في الطلب)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $k) !== '') continue;

            if (in_array($t, ['file', 'img'], true)) {
                if ($r->hasFile($k)) {
                    $m->{$c} = $r->file($k)->store('hub', 'local');   // خاص — يُخدم عبر بوابة الملفات المصادَق عليها
                    $this->stampFileName($m, $def, $c, $r->file($k));
                }
                continue;
            }
            if ($t === 'bool') { $m->{$c} = $r->boolean($k); continue; }
            if ($t === 'tags') {
                // **الغيابُ ليس تفريغاً**: طلبٌ لا يحمل المفتاح إطلاقاً (API أو
                // نموذجٌ جزئي) كان يمحو وسومَ السجل — والحزامُ هنا يُبقيها
                if (! $r->has($k)) continue;
                $v = trim(hub_str($r->input($k)));
                $arr = $v === '' ? null : array_values(array_filter(array_map('trim', preg_split('/[,،]/u', $v))));
                $m->{$c} = $arr === null ? null : ($m->hasCast($c) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($t === 'ref' && ! empty($f['multi'])) {
                // نصوصٌ فقط: مصفوفةٌ متداخلة كانت تُخزَّن ثم تقتل **عرض السجل
                // وتعديله معاً** (`strval` على مصفوفة) — فيستحيل إصلاحه من الواجهة
                $arr = array_values(array_filter((array) $r->input($k, []),
                    fn ($x) => is_string($x) && $x !== ''));
                $m->{$c} = ! $arr ? null : ($m->hasCast($c) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if (in_array($t, ['num', 'big'], true)) {
                // `1e400` يمرّ من `numeric` ويصير INF، فيُكتب ثم يفشل ترميز قيد
                // التدقيق — فيقع خطأٌ **بعد** أن يكون التغيير قد وقع
                $m->{$c} = hub_num($r->input($k));
                continue;
            }

            $v = $r->input($k);
            if ($t === 'sec' && ($v === null || $v === '')) continue;   // إبقاء السرّ القديم
            // تاريخٌ وصل طابعَ لحظةٍ بمنطقةٍ زمنية (صيغةُ API القديمة `…T21:00:00Z`): يومُه في منطقة النظام (v2.399)
            if ($t === 'date' && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v)) {
                try { $v = \Illuminate\Support\Carbon::parse($v)->setTimezone(config('app.timezone'))->toDateString(); } catch (\Throwable $e) {}
            }
            $m->{$c} = ($v === '' ? null : $v);
        }

        // الحقول المخصصة → عمود custom (مع إبقاء المفاتيح غير المعرفة كما هي)
        $cfs = hub_custom_fields($def['key'] ?? null);
        if ($cfs) {
            $custom = (array) ($m->custom ?? []);
            foreach ($cfs as $cf) {
                $key = $cf['key'];
                $v = match ($cf['type'] ?? 'text') {
                    'bool'  => $r->boolean('custom.' . $key),
                    'num'   => ($x = $r->input('custom.' . $key)) === null || $x === '' ? null : (float) $x,
                    default => ($x = $r->input('custom.' . $key)) === '' ? null : $x,
                };
                if ($v === null) unset($custom[$key]);
                else $custom[$key] = $v;
            }
            $m->custom = $custom ?: null;
        }

        /*
         * **حقلُ القرارِ يُردّ لمن لا يملك البتّ** (مجلس الخبراء · الخبير ١٤).
         *
         * بعد التعبئةِ لا داخلَها: السلطةُ تُقاس على السجلِّ **مكتملاً** — فـ`emp_id`
         * و`mgr_id` هما ما يحدّد أصاحبُ الطلبِ يبتّ في طلبِ نفسِه أم مديرُه، وهما
         * لا يُعرفان قبل أن تُملأ بقيّةُ الحقول. والحجّةُ كاملةً في `DecisionFields`.
         */
        \App\Support\Platform\DecisionFields::enforce((string) ($def['key'] ?? ''), $m, $decision);

        \App\Support\Apps\AppsProjects::inherit($def, $m);

        // ── Control Plane: Phase 6 (WP-6.1) ── بوّابةُ «الحالة تتطلب حقولاً» على
        // حالة النموذج **بعد** التعبئة — تغطّي التحديث (ويب وAPI وPATCH) والإنشاء
        // وتنفيذَ الموافقة معاً لأنها كلَّها تمرّ من هنا؛ السحبُ والجماعي يستدعيانها بأنفسهما
        $this->guardStatusRequires($def, $m);
    }

    /**
     * **ختمُ اسم الملف الأصليّ** مع رفعه في حقل وحدة.
     *
     * `store()` تحفظ الملف باسمٍ مولَّدٍ عشوائياً (حمايةً من التخمين) وتُسقط
     * اسمَه الأصليّ تماماً — فمن نزّل «الهوية البصرية» لاحقاً وجد
     * `9f3c…e1.png` في تنزيلاته. الاسمُ يُختم هنا في `meta.files.{عمود}`
     * فتقرؤه بوابةُ الملفات عند التنزيل وتعيده كما رُفع. عمودُ `meta` قائمٌ في
     * جداول الوحدات، ومن لا يملكه لا يُكتب له شيء — والتنزيل يبقى عاملاً باسمٍ
     * مشتقٍّ من السجل وحقله.
     */
    protected function stampFileName(Model $m, array $def, string $col, $file): void
    {
        $table = (string) ($def['table'] ?? '');
        if ($table === '' || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'meta')) return;

        $meta = $m->meta;
        $arr = is_array($meta) ? $meta : (json_decode((string) $meta, true) ?: []);
        $arr['files'][$col] = [
            'name' => \Illuminate\Support\Str::limit((string) $file->getClientOriginalName(), 190, ''),
            'size' => (int) $file->getSize(),
            'at'   => now()->toDateTimeString(),
        ];

        // النموذجُ قد يصبّ meta مصفوفةً وقد لا يفعل — ترميزٌ مزدوجٌ يفسد العمود
        $m->meta = $m->hasCast('meta') ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE);
    }
}
