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

        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }

    /** استعلام القائمة الموحّد (بحث + حالة + فلاتر مراجع) — يخدم الفهرس والتصدير والكانبان */
    protected function buildQuery(Request $r, array $def, string $class, bool &$trash = false, array &$filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $trash = $r->boolean('trash') && hub_can(auth()->user(), $def['key'] ?? '', 'd');
        $q = $trash ? $class::onlyTrashed() : $class::query();
        $q = hub_scope($q, $def['key'] ?? '');          // نطاق المشاريع للحسابات المحدودة
        $q = hub_company_scope($q, $def['key'] ?? '');  // الشركة النشطة من الشريط العلوي

        if ($term = hub_str($r->input('q'))) $q->search($term);

        // فحص جودة (`?qc=`): يفتح **نفس** السجلات التي عدّها مركز الجودة —
        // فالنقص يُفتح لا يُقرأ. والمفتاح يُطابَق على قائمة الفحوص المشتقّة من
        // السجل، فمفتاحٌ مُلفَّق لا يبني قيداً ولا يوسّع القائمة.
        if ($qc = hub_str($r->input('qc'))) {
            $rule = \App\Support\DataQuality::rules($def['key'] ?? '')[$qc] ?? null;
            if ($rule) {
                $q = \App\Support\DataQuality::apply($q, (string) ($def['key'] ?? ''), (string) $qc);
                $filters[] = ['key' => 'qc', 'label' => 'فحص جودة', 'val' => $qc, 'name' => $rule['label']];
            }
        }

        // العمود الفيزيائي لا المفتاح — في الوثائق المفتاح docStatus والعمود doc_status
        $statusCol = hub_status_col($def['key'] ?? '');
        if ($statusCol && ($st = hub_str($r->input('status'))) !== '') $q->where($statusCol, $st);

        $fields = collect($def['fields']);
        foreach ((array) $r->input('f', []) as $fk => $fv) {
            if ($fv === '' || ! is_string($fv) || ! is_string($fk)) continue;

            $f = $fields->firstWhere('key', $fk);
            // حقلٌ محجوبٌ عن هذا المستخدم لا يُرشَّح به: الترشيح ثم رؤية النتائج
            // كاشفٌ لقيمته بالاستدلال — نفس حارس الفلاتر المتقدمة (applyAdvancedFilters).
            if ($f && hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $fk) === 'hide') continue;
            if ($f && ($f['type'] ?? '') === 'ref') {
                // المتعدد احتواءٌ في مصفوفة، والمفرد مساواة
                empty($f['multi'])
                    ? $q->where($f['col'], $fv)
                    : $q->whereJsonContains($f['col'], $fv);
                $filters[] = ['key' => $fk, 'label' => $f['label'], 'val' => $fv,
                              'name' => hub_ref_labels($f['ref'], [$fv])[$fv] ?? $fv];
                continue;
            }

            // أعمدة الربط الضمنية — قائمة بيضاء بعمودين لا غير. بلا هذا الحصر يصير
            // «عرض الكل» ترشيحاً على أي عمود يُسمّيه الرابط، وهو كاشفٌ للقيم بالاستدلال.
            $implicit = ['company_id' => 'companies', 'project_id' => 'projects'];
            if (isset($implicit[$fk]) && ($tbl = $def['table'] ?? null)
                && \Illuminate\Support\Facades\Schema::hasColumn($tbl, $fk)) {
                $q->where($fk, $fv);
                $filters[] = ['key' => $fk, 'label' => hub_mod($implicit[$fk])['label'] ?? $fk,
                              'val' => $fv,
                              'name' => hub_ref_labels($implicit[$fk], [$fv])[$fv] ?? $fv];
            }
        }

        $this->applyAdvancedFilters($r, $def, $fields, $q, $filters);

        return $q;
    }

    /** عوامل الفلاتر المتقدمة المسموحة لكل نوع حقل — ما خرج عنها يُتجاهل بصمت */
    public const FL_OPS = [
        'text' => ['has', 'eq', 'neq', 'empty', 'nempty'],
        'ta' => ['has', 'eq', 'neq', 'empty', 'nempty'],
        'url' => ['has', 'empty', 'nempty'],
        'sel' => ['eq', 'neq', 'empty', 'nempty'],
        'num' => ['eq', 'neq', 'gt', 'lt', 'empty', 'nempty'],
        'big' => ['eq', 'neq', 'gt', 'lt', 'empty', 'nempty'],
        'date' => ['eq', 'before', 'after', 'empty', 'nempty'],
        'dt' => ['eq', 'before', 'after', 'empty', 'nempty'],
        'bool' => ['eq'],
    ];

    /** تسميات العوامل للرقائق والواجهة */
    public const FL_LABELS = [
        'has' => 'يحوي', 'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<',
        'before' => 'قبل', 'after' => 'بعد', 'empty' => 'فارغ', 'nempty' => 'غير فارغ',
    ];

    /**
     * باني الفلاتر المتقدم (v2.116): شروط مركبة fl[i][f|o|v] بمنطق «و».
     * كل شرط يُصادَق ضد تعريف الوحدة: الحقل موجود، ونوعه قابل للترشيح (لا أسرار
     * ولا ملفات — الترشيح على عمود سرّي كاشفٌ للقيم بالاستدلال)، وحقول الصلاحية
     * المخفية عن المستخدم لا تُرشَّح، والعامل من القائمة البيضاء لنوعه. ما فشل
     * بصادقةٍ يُتجاهل بصمت فلا يكسر رابطاً محفوظاً قديماً.
     */
    protected function applyAdvancedFilters(Request $r, array $def, $fields, $q, array &$filters): void
    {
        foreach (array_slice((array) $r->input('fl', []), 0, 10, true) as $i => $cond) {
            if (! is_array($cond)) continue;
            $fk = (string) ($cond['f'] ?? '');
            $op = (string) ($cond['o'] ?? '');
            $fv = $cond['v'] ?? '';
            if (! is_string($fv)) continue;

            $f = $fields->firstWhere('key', $fk);
            if (! $f) continue;
            $t = $f['type'] ?? 'text';
            if (! isset(self::FL_OPS[$t]) || ! in_array($op, self::FL_OPS[$t], true)) continue;
            if (hub_field_mode(auth()->user(), $def['key'] ?? '', $fk) === 'hide') continue;

            $needsVal = ! in_array($op, ['empty', 'nempty'], true);
            if ($needsVal && $fv === '') continue;
            if (in_array($t, ['sel'], true) && $needsVal && ! in_array($fv, $f['options'] ?? [], true)) continue;
            if (in_array($t, ['num', 'big'], true) && $needsVal && ! is_numeric($fv)) continue;

            $col = $f['col'];
            match ($op) {
                'has' => $q->where($col, 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $fv) . '%'),
                'eq' => $t === 'bool'
                    ? $q->where($col, (bool) ((int) $fv))
                    : $q->where($col, in_array($t, ['num', 'big'], true) ? $fv + 0 : $fv),
                'neq' => $q->where($col, '!=', in_array($t, ['num', 'big'], true) ? $fv + 0 : $fv),
                'gt' => $q->where($col, '>', $fv + 0),
                'lt' => $q->where($col, '<', $fv + 0),
                'before' => $q->whereDate($col, '<', $fv),
                'after' => $q->whereDate($col, '>', $fv),
                // «فارغ» على رقمٍ أو تاريخ = NULL وحده: مقارنةُ '' تُصنّف الصفرَ
                // فارغاً على MySQL (يحوّل '' إلى 0) وتُعيد غيرَه على SQLite —
                // انقسامٌ صامت بين المحرّكين ونتيجةٌ خاطئة في الإنتاج
                'empty' => in_array($t, ['num', 'big', 'date', 'dt'], true)
                    ? $q->whereNull($col)
                    : $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
                'nempty' => in_array($t, ['num', 'big', 'date', 'dt'], true)
                    ? $q->whereNotNull($col)
                    : $q->whereNotNull($col)->where($col, '!=', ''),
            };

            // رقاقة الشرط مع رابط إزالته وحده — بقية الشروط والمعايير تبقى
            $qs = $r->query();
            unset($qs['fl'][$i], $qs['page']);
            $filters[] = [
                'key' => "fl:$i", 'label' => $f['label'],
                'val' => $fv, 'op' => self::FL_LABELS[$op],
                'name' => $needsVal ? ($t === 'bool' ? ((int) $fv ? 'نعم' : 'لا') : $fv) : '',
                'rmurl' => $r->url() . ($qs ? '?' . http_build_query($qs) : ''),
            ];
        }
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
        [$def] = $this->resolve($module, 'a');

        // تعبئة مسبقة من الرابط (زر ＋ داخل عمود الكانبان مثلاً يمرر الحالة):
        // تُقبل مفاتيح حقول الوحدة فقط وبقيم نصية — والتحقق الكامل يبقى عند الحفظ
        $prefill = [];
        foreach ($def['fields'] ?? [] as $f) {
            $v = $r->query($f['key']);
            if (is_string($v) && $v !== '') $prefill[$f['key']] = $v;
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
        $this->guardAccountRequest($r, $module);

        $m = new $class;
        $this->fill($def, $r, $m);

        $this->inheritCompany($m, $module);

        // مستخدم محدود ينشئ مشروعاً: نضمن بقاءه ضمن نطاقه (مديراً أو عضواً)
        if ($module === 'projects' && hub_scoped(auth()->user())) {
            $me  = auth()->id();
            $mem = collect(is_array($m->members) ? $m->members : (json_decode($m->members ?? '[]', true) ?: []));
            if ($m->manager_id !== $me && ! $mem->contains($me)) {
                $m->members = $mem->push($me)->values()->all();
            }
            \Illuminate\Support\Facades\Cache::forget("user:{$me}:projects");
        }

        $m->save();
        $this->notifyAssignee($def, $module, $m);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('created', $module, $m);

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

            $res = \App\Support\Staff::makeAccountResult($m, hub_str($r->input('_account_role')));
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
            \App\Support\FlowRunner::fire('status', $module, $m, 'قيد التوقيع');

            return redirect()->route('esign.index', [
                'contract' => $m->id,
                'title'    => 'توقيع: ' . \Illuminate\Support\Str::limit((string) $m->title, 80),
            ])->with('ok', 'أُنشئ العقد بحالة «قيد التوقيع» — جهّز طلب التوقيع الآن وأرسله للطرف الآخر');
        }

        // «حفظ وإضافة آخر» — يبقيك في نموذج الإضافة للإدخال المتتابع
        if ($r->input('_stay')) {
            return redirect()->route('m.create', $module)->with('ok', 'أُضيف «' . \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? ''), 40) . '» — أدخل التالي');
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

        hub_audit('عرض حساس', $module, $row->id,
            (string) ($row->{hub_display_col($module)} ?? $row->id) . ' — ' . ($f['label'] ?? $field));

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

        $m = $this->findScoped($class, $module, $id);

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
        $m->save();
        $this->notifyAssignee($def, $module, $m, $prevAssignee);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('updated', $module, $m);
        if ($sc && (string) $m->{$sc} !== (string) $prevStatus) {
            \App\Support\FlowRunner::fire('status', $module, $m, (string) $m->{$sc});
        }

        return redirect()->route('m.index', $module)->with('ok', 'حُفظت التعديلات');
    }

    public function destroy(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $m = $this->findScoped($class, $module, $id);
        if (hub_needs_approval(auth()->user(), $module, 'd')) {
            return $this->queueApproval($def, $module, 'd', $m, request());
        }
        $m->delete();

        return redirect()->route('m.index', $module)->with('ok', 'نُقل السجل إلى السلة');
    }

    public function restore(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $this->findScoped($class, $module, $id, 'only')->restore();

        return redirect()->route('m.index', [$module, 'trash' => 1])->with('ok', 'استُعيد السجل');
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
    public function setStatus(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');

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

        // السحب والإفلات كان يكتب عمود الحالة مباشرةً: لا فحصَ لصلاحية الحقل
        // (فيُكتب عمودٌ «قراءة فقط» بجرّة إصبع) ولا تحقّقَ من الخيارات المعرَّفة
        // (فتُزرع حالةٌ لا يعرفها السجل فلا تُعدّ في إحصاء ولا تُطلق أتمتة).
        $statusField = hub_status_field($module);
        $statusKey = (string) ($statusField['key'] ?? $statusCol);
        abort_if(hub_field_mode(auth()->user(), $module, $statusKey) !== '', 403,
            'حقل الحالة غير قابل للكتابة بصلاحيتك');

        $new = hub_str($r->input('status'));
        $options = (array) ($statusField['options'] ?? []);
        abort_if($options && ! in_array($new, $options, true), 422, 'حالة غير معرَّفة في هذه الوحدة');

        $prevStatus = $m->{$statusCol};
        $m->{$statusCol} = $new;
        $m->save();
        $this->bustProgress($module, $m);
        if ((string) $m->{$statusCol} !== (string) $prevStatus) {
            \App\Support\FlowRunner::fire('status', $module, $m, (string) $m->{$statusCol});
        }

        return response()->json(['ok' => 1]);
    }

    /** تصدير CSV بنفس فلاتر القائمة الحالية (BOM ليقرأ Excel العربية) */
    public function export(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        abort_unless(hub_exporter(), 403, 'التصدير يتطلب صلاحية');
        $def['key'] = $module;

        $trash = false; $filters = [];
        $rows = $this->buildQuery($r, $def, $class, $trash, $filters)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5000)->get();

        // بصمة التصدير في التدقيق — تُعرض في مركز الأمان
        hub_audit('تصدير', $module, null, $rows->count() . ' سجل (CSV)');

        // البتر لا يكون صامتاً: من صدّر قائمةً أكبر من السقف يعلم أنها قُصّت
        return $this->streamCsv($module, $def, $rows, $rows->count() >= 5000);
    }

    /** بث CSV بترويسة BOM (يقرأ Excel العربية) — تستعمله «تصدير القائمة» و«تصدير المحدد» */
    protected function streamCsv(string $module, array $def, $rows, bool $truncated = false)
    {
        [$columns, $labels] = $this->columnsAndLabels($def, $rows->all());

        return response()->streamDownload(function () use ($rows, $columns, $labels, $truncated) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // معامل escape الفارغ صراحةً: سلوك RFC 4180 كما يقرأ Excel، ويُسكت
            // تحذير PHP 8.4 «$escape الافتراضي سيتغير» المتكرر مع كل سطر
            fputcsv($out, array_column($columns, 'label'), ',', '"', '');
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $f) {
                    $v = $row->{$f['col']} ?? '';
                    if ($f['type'] === 'ref' && empty($f['multi'])) $v = $labels[$f['key']][$v] ?? $v;
                    elseif ($f['type'] === 'sec') $v = '••••';
                    elseif (is_array($v)) $v = implode('، ', $v);
                    elseif (is_string($v) && str_starts_with($v, '[')) { $d = json_decode($v, true); if (is_array($d)) $v = implode('، ', array_map(fn ($x) => is_scalar($x) ? $x : '', $d)); }
                    // تحييد حقن الصيغ: قيمة تبدأ بـ= + - @ أو تبويب تُنفَّذ صيغةً
                    // في Excel على جهاز المصدِّر (=HYPERLINK تسريب، =cmd تنفيذ) —
                    // فاصلة عليا بادئة تجعلها نصاً بريئاً كما تفعل جداول Google
                    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
                    $line[] = $v;
                }
                fputcsv($out, $line, ',', '"', '');
            }
            if ($truncated) {
                fputcsv($out, ['⚠ قُصّ التصدير عند ٥٠٠٠ صف — ضيّق الفلاتر وصدّر على دفعات'], ',', '"', '');
            }
            fclose($out);
        }, $module . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            abort_unless(hub_exporter(), 403, 'التصدير يتطلب صلاحية');
            $def['key'] = $module;
            $rows = hub_scope($class::query(), $module)->whereIn('id', $ids)->orderByDesc('created_at')->get();
            hub_audit('تصدير', $module, null, $rows->count() . ' سجل محدد (CSV جماعي)');

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

            $n = 0;
            foreach ($ids as $id) {
                $m = hub_scope($class::query(), $module)->whereKey($id)->first();
                if (! $m || (string) $m->{$statusCol} === $to) continue;
                $m->{$statusCol} = $to;
                $m->save();
                $this->bustProgress($module, $m);
                \App\Support\FlowRunner::fire('status', $module, $m, $to);
                $n++;
            }

            return back()->with('ok', "غُيّرت حالة {$n} من السجلات إلى «{$to}»");
        }

        if ($do === 'delete') {
            [$def, $class] = $this->resolve($module, 'd');
            if (hub_needs_approval(auth()->user(), $module, 'd')) {
                return back()->with('err', 'حذفُك يمر بالموافقات — احذف السجلات فردياً ليُوثَّق كل طلب على حدة');
            }
            $n = 0;
            foreach ($ids as $id) {
                $m = hub_scope($class::query(), $module)->whereKey($id)->first();
                if (! $m) continue;
                $m->delete();
                $n++;
            }

            return back()->with('ok', "نُقل {$n} من السجلات إلى السلة");
        }

        abort(400, 'إجراء غير معروف');
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

        abort_unless($row->restoreVersion($version), 422, 'النسخة غير موجودة');

        return back()->with('ok', "استُعيدت النسخة $version وحُفظت كنسخة جديدة");
    }

    /* ────────── أدوات داخلية ────────── */

    /**
     * العمليات المحمية: بدل التنفيذ يُصفّ طلب موافقة بحمولة التعديل،
     * ويُشعَر المعتمدون — الملفات المرفوعة لا تُؤجل (تُستثنى من الحمولة).
     */
    protected function queueApproval(array $def, string $module, string $op, Model $m, Request $r)
    {
        $payload = null;
        if ($op === 'e') {
            // **التنقية عند الالتقاط لا عند التنفيذ**: الحمولة كانت تُلتقط خاماً
            // ثم يعيد المعتمِد تشغيلها بصلاحياته هو — فمن لا يملك رؤية الراتب
            // يضبطه بوكيلٍ يوقّع بحسن نية. ما لا يملك الطالب كتابته لا يدخل
            // الطابور أصلاً، فلا يُوقَّع على ما لا يُرى.
            $u = auth()->user();
            $keys = collect($def['fields'])
                ->reject(fn ($f) => in_array($f['type'], ['file', 'img'], true))
                ->reject(fn ($f) => hub_field_mode($u, $module, (string) ($f['key'] ?? '')) !== '')
                ->pluck('key')->push('custom')->all();
            $payload = collect($r->only($keys))->filter(fn ($v) => $v !== null)->all();
        }

        $name = \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? $m->id), 60);
        $approvers = hub_approvers();

        $ap = \App\Models\Approval::create([
            'title'        => ($op === 'd' ? 'حذف ' : 'تعديل ') . $def['label'] . ': ' . $name,
            'type'         => 'عملية محمية',
            'reason'       => $r->input('_reason'),
            'due'          => now()->addDays(3)->toDateString(),
            'project_id'   => $m->project_id ?? null,
            'approver_id'  => $approvers[0] ?? null,
            'mod'          => $module,
            'record_id'    => $m->id,
            'op'           => $op,
            'payload'      => $payload,
            'requested_by' => auth()->id(),
            'status'       => 'معلّق',
            // نسخة السجل وقت الطلب — بها يُكشف عند الحسم أنّ أحداً عدّله في الطابور
            'meta'         => ['ver' => (int) ($m->version ?? 0)],
        ]);

        foreach ($approvers as $uid) {
            if ($uid === auth()->id()) continue;
            hub_notify($uid, 'approval',
                'طلب موافقة من ' . auth()->user()->name . ': ' . $ap->title, 'approvals', $ap->id);
        }

        return redirect()->route('m.index', $module)
            ->with('ok', 'هذه العملية محمية — أُرسل طلب الموافقة للمعتمدين وسيصلك إشعار بالقرار');
    }

    /** نسف كاش نسبة الإنجاز عند تغيّر مهمة أو بند خطة + ختم وقت حل التذاكر (SLA) */
    protected function bustProgress(string $module, Model $m): void
    {
        if (in_array($module, ['tasks', 'feats'], true) && ($pid = $m->project_id ?? null)) {
            \Illuminate\Support\Facades\Cache::forget('hub:progress:' . $pid);
        }

        // ربحية المشروع: أي مدخل من مدخلاتها يُبطل حسابها المخبأ فوراً
        if (in_array($module, ['tasks', 'fin', 'servers', 'subs', 'purchases', 'projects'], true)) {
            $pid = $module === 'projects' ? $m->id : ($m->project_id ?? null);
            if ($pid) \Illuminate\Support\Facades\Cache::forget('pl:' . $pid);
        }

        // أجور الساعة مشتقة من رواتب الملفات الوظيفية — تعديلها يُبطل الجدول كله
        if ($module === 'hr') \Illuminate\Support\Facades\Cache::forget('cost:rates');

        // حالة الصنف تُشتق من كميته وحدّه فور أي حفظ — نفد/منخفض/متاح
        if ($module === 'stock' && $m instanceof \App\Models\StockItem) hub_stock_sync($m);

        // الحقول المقيسة (متابعون، إعجابات، تحميلات، تقييم) تُسجَّل نقطةً في
        // السلسلة الزمنية مع كل حفظ — الحقل وحده يدهس ما قبله فلا يبقى نمو
        \App\Support\Metrics::capture($module, $m);

        // سياسةٌ أو مقالٌ إلزامي تغيّرت نسخته: الإقرارات القديمة تسقط ويُعاد
        // الإعلان. بلا هذا يبقى الجميع «مُقِرّين» بنسخةٍ ماتت — امتثالٌ كاذب.
        // دورة الإقرار (إسقاط عند تحديث النسخة + إعادة الإعلان) انتقلت إلى
        // النموذج نفسه — Policy::booted و KbArticle::booted — فتعمل أياً كان
        // مصدر التغيير لا من هذا المسار وحده.

        // مزامنة الأصل مع صيانته: قيد التنفيذ تضعه «صيانة»، والمكتملة تختم
        // «آخر صيانة» وتعيده «قيد الاستخدام» — كان الحقلان يدويين متناقضين
        if ($module === 'assetlog' && $m->asset_id && ($asset = \App\Models\Asset::find($m->asset_id))) {
            if ((string) $m->status === 'قيد التنفيذ' && $asset->status !== 'صيانة') {
                $asset->status = 'صيانة';
                $asset->saveQuietly();
            } elseif ((string) $m->status === 'مكتملة') {
                $asset->maint = $m->date;
                if ($asset->status === 'صيانة') $asset->status = 'قيد الاستخدام';
                $asset->saveQuietly();
            }
        }

        // إخلاء العهدة عند المغادرة: منتهية خدمته وبعهدته أصول ⟵ مهمة استرداد
        // واحدة (لا تتكرر) تسمّي الأصول — كانت العهدة تُنسى مع المغادر
        if ($module === 'hr' && (string) $m->status === 'منتهية خدمته' && $m->user_id) {
            $held = \App\Models\Asset::whereNull('deleted_at')
                ->where('holder_id', $m->user_id)->get(['id', 'name']);
            if ($held->isNotEmpty()) {
                $title = 'استرداد عهدة: ' . $m->name;
                $exists = \App\Models\Task::whereNull('deleted_at')->where('title', $title)
                    ->whereNotIn('status', ['منجزة', 'مكتملة', 'ملغاة'])->exists();
                if (! $exists) {
                    \App\Models\Task::create([
                        'title' => $title, 'status' => 'جديدة', 'priority' => 'عالية',
                        'company_id' => $m->company_id,
                        'description' => "الموظف منتهية خدمته وبعهدته:\n- "
                            . $held->pluck('name')->implode("\n- ")
                            . "\n\nاسترد الأصول ووثّق التسليم بإقرارٍ موقّع ثم حوّل حالتها إلى «متاح».",
                    ]);
                }
            }
        }

        if ($module === 'tickets') {
            $meta = (array) ($m->meta ?? []);
            $closed = in_array((string) $m->status, ['تم الحل', 'مغلقة'], true);
            if ($closed && empty($meta['resolved_at'])) {
                $meta['resolved_at'] = now()->toIso8601String();
                $m->meta = $meta;
                $m->saveQuietly();
            } elseif (! $closed && ! empty($meta['resolved_at'])) {
                unset($meta['resolved_at']);          // أُعيد فتحها
                $m->meta = $meta ?: null;
                $m->saveQuietly();
            }
        }
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

    /**
     * للحسابات المحدودة: يجب ربط السجل بأحد مشاريع المستخدم عند الإضافة أو التعديل.
     * يرمي ValidationException — تتحول redirect في الويب و422 JSON في الـ API.
     */
    protected function guardProject(Request $r, string $module): void
    {
        if (! hub_scoped(auth()->user())) return;
        if ($module === 'projects') return;                      // تُضبط عضويته تلقائياً في store
        $pf = hub_project_field($module);
        if (! $pf) return;

        $ids = auth()->user()->visibleProjectIds();
        $val = hub_str($r->input($pf['key']));
        if ($val === '' || ! in_array($val, $ids, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                [$pf['key'] => 'حسابك محدود النطاق — اختر مشروعاً من مشاريعك']);
        }
    }

    /**
     * وراثة الشركة النشطة لسجلٍ جديد في وحدةٍ لها عمود شركة بلا حقلٍ في نموذجها
     * (الخدمات، قواعد التنبيه، المهام...) — وإلا وُلِد بلا شركة فاختفى فوراً من
     * القائمة المفلترة بالشركة (whereIn يُقصي NULL). الويب يرث الشركة النشطة من
     * الشريط، والـAPI (بلا جلسة) يرث أولى شركات المستخدم المسموحة إن كان معزولاً.
     * يُستدعى من store وapiStore كليهما — فالمسارُ الآليّ لا يختلف عن اليدويّ.
     */
    protected function inheritCompany(Model $m, string $module): void
    {
        if ($module === 'companies' || ! ($ccol = hub_company_col($module)) || ! empty($m->{$ccol})) return;
        $cid = (string) session('hub.company', '');
        $allowed = hub_company_ids();
        if ($cid !== '' && ($allowed === null || in_array($cid, $allowed, true))) {
            $m->{$ccol} = $cid;
        } elseif ($allowed !== null && ! empty($allowed)) {
            // معزولٌ بلا شركة نشطة (وكلُّ نداءات API كذلك): يُنسب لأولى شركاته
            // المسموحة بدل أن يُحفَظ بلا شركة فيختفي من قوائمه فوراً
            $m->{$ccol} = $allowed[0];
        }
    }

    /** العزل الصارم: من له شركات مسموحة يربط السجل بإحداها فقط عند الإضافة أو التعديل */
    protected function guardCompany(Request $r, string $module): void
    {
        $ids = hub_company_ids();
        if ($ids === null || $module === 'companies') return;
        $cf = collect(hub_mod($module)['fields'] ?? [])
            ->first(fn ($f) => ($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'companies' && empty($f['multi']));
        if (! $cf) return;
        // حقل الشركة مخفيّ أو للقراءة لدور المستخدم: النموذج لا يرسله وfill لا يكتبه،
        // فالقيمة القائمة تبقى وhub_scope يفرض العزل قراءةً — لا تحبس الحفظ عبثاً.
        if (hub_field_mode(auth()->user(), $module, $cf['key']) !== '') return;

        $val = hub_str($r->input($cf['key']));
        if ($val === '' || ! in_array($val, $ids, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                [$cf['key'] => 'حسابك معزول على شركات محددة — اختر شركة من شركاتك']);
        }
    }

    /**
     * **طلبُ حسابٍ مع موظفٍ جديد: شروطُه تُفحص قبل الحفظ لا بعده.**
     *
     * البريد اختياريّ في نموذج الموظف، وفتحُ الحساب يحتاجه (البريدُ هوية
     * الدخول). فمن يضع علامة «🔑 افتح له حساب» بلا بريد كان يُحفظ موظفُه
     * ويُبتلع طلبُ حسابه **صامتاً** ويُقال له «أُضيف السجل بنجاح» — فيظنّ أنّ
     * للرجل حساباً ولا يكتشف خلافَ ذلك إلا حين لا يستطيع الدخول.
     *
     * والفحصُ قبل الحفظ لا بعده: **إمّا الطرفان معاً وإمّا لا شيء وسببٌ مكتوب**
     * — لا موظفٌ محفوظٌ ونصفُ طلبٍ ساقط.
     *
     * ويُتخطّى الفحصُ لمن لا يملك إدارة المستخدمين: الخيارُ لا يُعرض له أصلاً،
     * وإرسالُه مفتعلاً يُردّ في `Staff::makeAccount` بـ403 — ولا يُحرم من حفظ
     * ملفٍّ وظيفيّ هو مسموحٌ له أصلاً.
     */
    protected function guardAccountRequest(Request $r, string $module): void
    {
        if ($module !== 'hr' || ! $r->boolean('_make_account')) return;
        if (! hub_flag(auth()->user(), 'users')) return;

        $err = [];
        if (blank($r->input('email'))) {
            $err['email'] = 'فتحُ حسابٍ يحتاج بريداً إلكترونياً — البريد هو هوية الدخول.'
                . ' أضِف البريد، أو أزِل خيار «افتح له حساب نظام» وافتحه لاحقاً من شاشة المستخدمين.';
        }

        $roleId = hub_str($r->input('_account_role'));
        if ($roleId === '' || ! \App\Models\Role::find($roleId)) {
            $err['_account_role'] = 'اختر دورَ الحساب — الدور يحدّد ما يراه صاحبه وما يفعله.';
        }

        if ($err) throw \Illuminate\Validation\ValidationException::withMessages($err);
    }

    /** أعمدة الجدول (من تعريف الوحدة) + أسماء العرض للمراجع الظاهرة في الصفحة */
    protected function columnsAndLabels(array $def, array $rows, bool $all = false): array
    {
        // صلاحيات مستوى الحقل: المخفي عن دور المستخدم لا يظهر في جدول ولا صفحة ولا تصدير
        $fields = collect(hub_visible_fields(auth()->user(), (string) ($def['key'] ?? ''), $def));
        // أعمدة المستخدم المخصصة تتقدم على أعمدة السجل — وتُقاطَع مع المرئي لدوره
        $userCols = $all ? [] : array_values(array_intersect(
            (array) hub_pref('cols.' . ($def['key'] ?? ''), []), $fields->pluck('key')->all()));
        $keys   = $all ? $fields->pluck('key')->all()
            : ($userCols ?: ($def['columns'] ?? $fields->take(4)->pluck('key')->all()));
        $cols   = $fields->whereIn('key', $keys)->values()->all();

        $labels = [];
        foreach ($fields->where('type', 'ref') as $f) {
            if (! $all && ! in_array($f['key'], $keys, true)) continue;
            $ids = [];
            foreach ($rows as $row) {
                $v = $row->{$f['col']} ?? null;
                if (! $v) continue;
                if (! empty($f['multi'])) {
                    $arr = is_array($v) ? $v : (json_decode($v, true) ?: []);
                    $ids = array_merge($ids, $arr);
                } else {
                    $ids[] = $v;
                }
            }
            $labels[$f['key']] = hub_ref_labels($f['ref'], $ids);
        }

        return [$cols, $labels];
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
            $opts = hub_ref_options($f['ref'], $cur);
            if ($f['ref'] === 'projects' && hub_scoped(auth()->user())) {
                $opts = array_intersect_key($opts, array_flip(auth()->user()->visibleProjectIds()));
            }
            // العزل الصارم: أي مرجعٍ لوحدةٍ معزولة على الشركات (الشركات نفسها،
            // العملاء، الموردون…) تنحصر خياراته في شركات المستخدم المسموحة —
            // وإلا سرّبت القائمة أسماء سجلات شركات أجنبية وسمحت بالربط بها.
            if (($cids = hub_company_ids()) !== null && ($ccol = hub_company_col($f['ref']))) {
                $allowed = \Illuminate\Support\Facades\DB::table(hub_ref_table($f['ref']))
                    ->whereIn($ccol, $cids)->pluck('id')->map(fn ($v) => (string) $v)->all();
                $opts = array_intersect_key($opts, array_flip($allowed));
            }
            $out[$f['key']] = $opts;
        }
        return $out;
    }

    /** قواعد التحقق من تعريف الوحدة — الحقول السرية مطلوبة عند الإنشاء فقط (التعديل الفارغ يُبقي القديم) */
    protected function rules(array $def, bool $creating = true): array
    {
        $rules = [];
        foreach ($def['fields'] as $f) {
            // حقل ممنوع على الدور لا يُتحقق منه (وإلا استحال الحفظ بحقل إلزامي مخفي)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $f['key']) !== '') continue;

            $required = ! empty($f['required']) && ($creating || ($f['type'] ?? '') !== 'sec');
            $r = [$required ? 'required' : 'nullable'];
            $r[] = match ($f['type']) {
                'num', 'big' => 'numeric',
                'date', 'dt' => 'date',
                'file', 'img' => 'file',
                // كانت تسقط للنص: صندوق المتصفح يمر بـ«1» صدفةً، لكن الـ API
                // بقيمة منطقية true يُرفض برسالة «يجب أن يكون نصاً».
                'bool' => 'boolean',
                default => 'string',
            };
            if ($f['type'] === 'ref' && ($t = hub_ref_table($f['ref']))) {
                $r = empty($f['multi'])
                    ? [$r[0], "exists:$t,id"]
                    : [$r[0], 'array'];
            }
            if (in_array($f['type'], ['file', 'img'], true)) {
                // امتداداتُ التنفيذ والترميز محظورة: SVG/HTML تحمل سكربتاً يعمل
                // بأصل التطبيق إن فُتحت، وPHP قنبلةٌ إن لمسها الخادم يوماً.
                // البوابة تخدم الغريب تنزيلاً قسرياً — وهذا حزامُ الأمان الثاني.
                $r = [$r[0], 'file', 'max:' . (int) setting('files.max_kb', 512000),
                    function ($attr, $file, $fail) {
                        $ext = strtolower((string) $file->getClientOriginalExtension());
                        if (in_array($ext, ['php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'mjs'], true)) {
                            $fail('هذا النوع من الملفات لا يُرفع — قد يحمل شيفرةً تنفيذية. حوّله إلى PDF أو صورة.');
                        }
                    }];
            }

            // سقفُ الطول من **عرض العمود نفسه**: كان الحقل النصّي يُتحقّق منه
            // كـ`string` بلا حدّ، وSQLite لا يفرض طول varchar فتمرّ الحزمة،
            // ثم يرفض MySQL القيمة في الإنتاج بـ22001. الرفضُ برسالةٍ للمستخدم
            // خيرٌ من خمسمئةٍ بعد أن يكون السجل قد كُتب.
            if (in_array($f['type'], ['text', 'sel', 'url', 'sec'], true)
                && ($w = hub_col_max($def['table'] ?? '', $f['col'] ?? $f['key']))) {
                $r[] = 'max:' . $w;
            }
            // سقفُ العدد من **دقّة العمود العشريّ**: كان num/big يُتحقّق كـ`numeric`
            // بلا حدّ، فقيمةٌ تفوق decimal(M,D) تمرّ على SQLite ثم يرفضها MySQL بـ22003
            // (٥٠٠ ورسالةٌ تُسرّب القيمة). الحدُّ يرفضها للمستخدم قبل القاعدة.
            if (in_array($f['type'], ['num', 'big'], true)
                && ($nm = hub_col_num_max($def['table'] ?? '', $f['col'] ?? $f['key'])) !== null) {
                $r[] = 'between:' . rtrim(rtrim(number_format(-$nm, 3, '.', ''), '0'), '.')
                    . ',' . rtrim(rtrim(number_format($nm, 3, '.', ''), '0'), '.');
            }
            // وقائمةُ الخيارات تُلزِم: شاشةُ الحالة تفرضها منذ v2.x والنموذج لا
            if (($f['type'] ?? '') === 'sel' && ! empty($f['options'])) {
                $r[] = \Illuminate\Validation\Rule::in($f['options']);
            }

            $rules[$f['key']] = $r;
        }

        // الحقول المخصصة (باني الحقول)
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) {
            $r = [! empty($cf['required']) ? 'required' : 'nullable'];
            $r[] = match ($cf['type'] ?? 'text') {
                'num'  => 'numeric',
                'date' => 'date',
                default => 'string',
            };
            if (($cf['type'] ?? '') === 'ref' && ($t = hub_ref_table($cf['ref'] ?? ''))) $r[] = "exists:$t,id";
            $rules['custom.' . $cf['key']] = $r;
        }

        return $rules;
    }

    /** تسميات الحقول العربية لرسائل التحقق (:attribute) — تشمل الحقول المخصصة */
    protected function attrs(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $f) $out[$f['key']] = $f['label'];
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) $out['custom.' . $cf['key']] = $cf['label'];

        return $out;
    }

    /** تعبئة الموديل من الطلب حسب نوع كل حقل */
    /**
     * @param ?array $only حصرُ الكتابة بمفاتيحَ بعينها — لإعادة تشغيل حمولة
     *   موافقةٍ معتمَدة: المعتمِد وافق على **تلك** التغييرات لا على تفريغ ما
     *   عداها. وبغير الحصر يُكتب null فوق كل حقلٍ غاب عن الحمولة.
     */
    protected function fill(array $def, Request $r, Model $m, ?array $only = null): void
    {
        foreach ($def['fields'] as $f) {
            $k = $f['key']; $c = $f['col']; $t = $f['type'];

            if ($only !== null && ! in_array($k, $only, true)) continue;

            // حقل مخفي أو قراءة فقط لدور المستخدم: لا يُكتب أبداً (حتى لو حُقن في الطلب)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $k) !== '') continue;

            if (in_array($t, ['file', 'img'], true)) {
                if ($r->hasFile($k)) $m->{$c} = $r->file($k)->store('hub', 'local');   // خاص — يُخدم عبر بوابة الملفات المصادَق عليها
                continue;
            }
            if ($t === 'bool') { $m->{$c} = $r->boolean($k); continue; }
            if ($t === 'tags') {
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

        \App\Support\AppsProjects::inherit($def, $m);
    }
}
