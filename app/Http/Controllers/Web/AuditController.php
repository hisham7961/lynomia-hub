<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\SecurityEvents;
use App\Support\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجل التدقيق — تحقيقٌ لا سردٌ زمني.
 *
 * كان مرشِّحان اثنان (وحدة + بحثٌ في الاسم) فوق جدولٍ ينمو أسرع من كل شيء:
 * لا «من فعل»، ولا «أي إجراء»، ولا «متى»، ولا «من أي عنوان» — فسؤالٌ بسيط
 * مثل «من صدّر بيانات الرواتب الشهر الماضي» يعني تصفّح مئات الصفحات يدوياً.
 *
 * (WP-5.3) النظرةُ التنفيذية: ١٢ عدّاداً **تجميعياً** فوق `Audit::scopedQuery`
 * — كان النبضُ يحمّل صفوفَ اليوم كلَّها (`$base()->get()`) ليَعُدّ في PHP،
 * والعدُّ الآن في القاعدة مستفيداً من فهارس `(action, created_at)` و
 * `(created_at, ip)` و`(company_id, created_at)`. والمدى عبر `TimeRange`
 * (`from/to` القديمة تُقرأ توافقاً — بلا `whereDate` غير السارغابل).
 */
class AuditController extends Controller
{
    /** أعمدةُ الفرز المسموحة خلف رؤوس cc/th — قائمةٌ بيضاء لا معاملٌ حرّ */
    protected const SORTS = [
        'time'     => 'audits.created_at',
        'user'     => 'users.name',
        'action'   => 'audits.action',
        'module'   => 'audits.module',
        'ip'       => 'audits.ip',
        'severity' => 'audits.severity',
    ];

    public function index(Request $r)
    {
        abort_unless(hub_flag(auth()->user(), 'audit'), 403);
        $u = auth()->user();

        // المدى الزمنيّ الموحّد (WP-5.3): كبسولات TimeRange، وfrom/to القديمة
        // (كما في روابطَ محفوظةٍ قبل التوحيد) تُقرأ توافقاً مدىً مخصّصاً
        $range = hub_range($r, '7d');

        // فرزٌ حقيقيّ خلف رؤوس cc/th — aria-sort لا يكذب، وفاصلُ id يثبّت الترقيم
        $sort = self::SORTS[(string) $r->query('sort')] ?? 'audits.created_at';
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 'asc' : 'desc';

        // النطاق أولاً ودائماً — المرشِّحات تضيّق ما بعده ولا تفتح ما قبله.
        // WP-5.1 وحّده في Audit::scopedQuery ليقرأ منه كلُّ قارئٍ للجدول.
        $q = Audit::scopedQuery($u)
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->select('audits.*', 'users.name as user_name');
        $this->applyRange($q, $range);
        $this->applyFilters($q, $r);
        $rows = $q
            // فاصلُ id بعد عمود الفرز: created_at بدقّة الثانية تتساوى قيمُه،
            // والترتيبُ على المتساوي قرعةٌ تكرّر صفوفاً وتُسقط أخرى عبر الصفحات
            ->orderBy($sort, $dir)->orderBy('audits.id', $dir)
            // بلا COUNT(*) إجمالي — أسرع الجداول نمواً والعدّ مع join يثقل كل صفحة
            ->simplePaginate(40)->withQueryString();

        // مصادرُ المنسدلات منطَّقةٌ كالقائمة نفسِها: المحصورُ لا تُسمّى له شركةٌ
        // أو عميلٌ خارج نطاقه ولو في مرشِّح
        $cids = hub_company_ids($u);
        $kids = hub_client_ids($u);

        return view('audit.index', [
            'rows'    => $rows,
            'range'   => $range,
            'kpis'    => $this->counters($range),
            // القائمة المنسدلة تعرض الوحدات المرئية وحدها — الوحدة المحجوبة لا تُسمّى ولا تُعدّ
            'modules' => array_filter(hub_modules(), fn ($mk) => hub_can($u, $mk, 'v'), ARRAY_FILTER_USE_KEY),
            'users'   => DB::table('users')->whereNull('deleted_at')->orderBy('name')->orderBy('id')->pluck('name', 'id'),
            // DISTINCT على أسرع الجداول نموّاً في كل فتحة (PERF-02): خمسُ دقائقَ خبيئة — والفهرسُ (action) يسنده (v2.399)
            'actions' => \Illuminate\Support\Facades\Cache::remember('audit:actions', 300, fn () => DB::table('audits')->distinct()->orderBy('action')->limit(40)->pluck('action')),
            'roles'   => DB::table('roles')->orderBy('name')->orderBy('id')->pluck('name', 'id'),
            'companies' => DB::table('companies')
                ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($w) => $w->whereNull('deleted_at'))
                ->when($cids !== null, fn ($w) => $w->whereIn('id', $cids))
                ->orderBy('name_ar')->orderBy('id')->pluck('name_ar', 'id'),
            'projects' => DB::table('projects')
                ->when(Schema::hasColumn('projects', 'deleted_at'), fn ($w) => $w->whereNull('deleted_at'))
                ->when(hub_scoped($u), fn ($w) => $w->whereIn('id', $u->visibleProjectIds()))
                ->orderBy('name')->orderBy('id')->pluck('name', 'id'),
            'clients' => DB::table('clients')
                ->when(Schema::hasColumn('clients', 'deleted_at'), fn ($w) => $w->whereNull('deleted_at'))
                ->when($kids !== null, fn ($w) => $w->whereIn('id', $kids))
                ->orderBy('name')->orderBy('id')->pluck('name', 'id'),
            'categories' => $this->categories(),
            // التحقيقاتُ المحفوظة: saved_views نفسُه بوحدةٍ خاصة 'audit' (لا جدولَ ثانٍ)
            'views'      => \App\Models\SavedView::where('user_id', $u->id)
                ->where('module', 'audit')->orderBy('name')->orderBy('id')->get(),
            'chain'   => Audit::verifyTail(),
            // (WP-5.5 · §1.6) تاريخُ النزاهة: آخرُ تشغيلات الفحص الكامل — قراءةٌ
            // خفيفةٌ محضة؛ على تحميل الصفحة يبقى verifyTail أعلاه وحدَه، ولا
            // يجري الفحصُ الكامل في طلبٍ أبداً (زرُّ مركز التشغيل أو المجدول)
            'verifs'  => hub_has_col('audit_verifications', 'id')
                ? DB::table('audit_verifications')->orderByDesc('started_at')->orderByDesc('id')->limit(6)->get()
                : collect(),
            // (WP-5.5 · §1.8 · ق٦) الاحتفاظ وصفٌ لا مقصّ: مفتاحان يُعرضان للقراءة
            // في الشاشة — ولا كودَ تقليمٍ يقرؤهما في أي كنّاس (0 = للأبد، الافتراضي)
            'retention' => [
                'days'   => (int) setting('audit.retention_days', 0),
                'policy' => (string) setting('audit.retention_policy', ''),
            ],
        ]);
    }

    /**
     * (WP-5.4) صفحةُ تفصيل قيدٍ واحد — «هذا القيدُ في يدي، ماذا يقول كاملاً؟»
     * (spec §1.3 · §45 · §42.10): من فعل، وماذا (الفرقُ عبر `Audit::diff` ثم
     * `Redactor`)، ومتى وأين، وبأيّ طلب، وما دلالتُه الأمنية، وهل بصمتُه سليمة،
     * وما الذي كتبه الطلبُ نفسُه في بقية الطبقات.
     *
     * الحارس بترتيبٍ مقصود: الرايةُ أولاً (403 موحّدةً لمن لا يملكها أصلاً)،
     * ثم `Audit::scopedQuery` (WP-5.1) فالخارجُ عن النطاق **404 لا 403** —
     * فالتمييزُ بين «موجودٌ ممنوع» و«غير موجود» يفشي وقوعَ الفعل لمن لا يراه.
     */
    public function show(string $id)
    {
        $u = auth()->user();
        abort_unless(hub_flag($u, 'audit'), 403, 'سجل التدقيق يتطلب صلاحيته');

        $scopedId = Audit::scopedQuery($u)->where('audits.id', (int) $id)->value('audits.id');
        abort_unless($scopedId, 404);
        $a = \App\Models\AuditEntry::findOrFail($scopedId);

        // من فعل: المستخدم ودوره — والقيدُ النظاميّ (بلا user_id) يُسمّى «النظام»
        $actor = $a->user_id
            ? DB::table('users')->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.id', $a->user_id)
                ->first(['users.name', 'users.deleted_at', 'roles.name as role_name',
                         'roles.scope as role_scope', 'roles.is_owner'])
            : null;
        $company = ($a->company_id ?? null) && hub_has_col('companies', 'name_ar')
            ? DB::table('companies')->where('id', $a->company_id)->value('name_ar')
            : null;

        // ماذا: الحقولُ المتغيّرة وحدها، مقنَّعةً بقيود الوحدة والحقل داخل
        // Audit::diff، ثم **المُطهِّرُ الواحد** فوق القيمتين — رمزٌ داخل قيمةٍ
        // بريئة (lyn_/JWT/token=) يُطمَس ولو لم يكن العمودُ نفسُه سرّياً
        $rawB = $a->getAttributes()['before'] ?? null;
        $rawA = $a->getAttributes()['after'] ?? null;
        $diff = array_map(fn ($d) => [
            'from' => \App\Support\Redactor::text($d['from']),
            'to'   => \App\Support\Redactor::text($d['to']),
        ] + $d, Audit::diff($a->module, $rawB, $rawA));

        // التصنيف: المخزّنُ للجديد (WP-5.2)، ومُترجِمُ القراءة للصفوف الأقدم
        $class = ($a->severity ?? null)
            ? ['category' => $a->category, 'severity' => $a->severity]
            : hub_audit_class($a->action, $a->module, $rawB, $rawA, $a->name);

        // أين: جلسةُ الكاتب إن سُجّلت، و«المألوف» بقاعدة Risk::session نفسِها
        // (user_ips.hits < 3 = غيرُ مألوف) — **لا حاسبَ ثالثاً** للخطر
        $session = null;
        if (hub_has_col('audits', 'session_id') && ($a->session_id ?? null)) {
            $session = DB::table('sessions_log')->where('id', $a->session_id)
                ->first(['device', 'ip', 'started_at', 'last_seen_at', 'revoked']);
        }
        $ipHits = null;
        if ($a->ip && $a->user_id && hub_has_col('user_ips', 'hits')) {
            $ipHits = (int) DB::table('user_ips')->where('user_id', $a->user_id)
                ->where('ip', $a->ip)->value('hits');
        }

        // الأمن: الكودُ القانونيّ من الكتالوج الواحد — null صادقةٌ لغير الأمنيّ
        $secCode = SecurityEvents::codeFor($a->action, $a->module, $rawA, $a->name);

        // العلاقات: ما كتبه الطلبُ نفسُه في الطبقات الأخرى — كلُّ مصدرٍ بحارسه:
        // الأخطاءُ لمركزها (مالك)، والحوادثُ والمهامُّ بوحدتَيهما ونطاقَيهما
        $rid = hub_has_col('audits', 'request_id') ? ($a->request_id ?? null) : null;
        $siblings = 0;
        $relErrors = collect();
        $relIncidents = collect();
        $relTasks = collect();
        if ($rid) {
            $siblings = (int) Audit::scopedQuery($u)->where('audits.request_id', $rid)
                ->where('audits.id', '!=', $a->id)->count();
            $taskIds = [];
            if (hub_is_owner($u) && hub_has_col('error_events', 'request_id')) {
                $relErrors = DB::table('error_events')->where('request_id', $rid)
                    ->orderBy('last_seen')->orderBy('id')->limit(10)
                    ->get(['id', 'kind', 'message', 'status', 'last_seen',
                           ...(hub_has_col('error_events', 'meta') ? ['meta'] : [])])
                    ->map(function ($e) use (&$taskIds) {
                        $meta = json_decode((string) ($e->meta ?? ''), true) ?: [];
                        if (! empty($meta['task_id'])) $taskIds[] = (string) $meta['task_id'];
                        $e->message = \App\Support\Redactor::text((string) $e->message);

                        return $e;
                    });
            }
            if (hub_has_col('incidents', 'request_id') && hub_can($u, 'incidents', 'v')) {
                $relIncidents = hub_scope(DB::table('incidents')->whereNull('deleted_at')
                    ->where('request_id', $rid), 'incidents', $u)
                    ->orderBy('created_at')->orderBy('id')->limit(10)
                    ->get(['id', 'title', 'severity', 'status']);
            }
            // «أو الرابط»: مهمةُ الإصلاح المولَّدة من الخطأ (error_events.meta.task_id)
            if ($taskIds && hub_can($u, 'tasks', 'v')) {
                $relTasks = hub_scope(DB::table('tasks')->whereNull('deleted_at')
                    ->whereIn('id', array_values(array_unique($taskIds))), 'tasks', $u)
                    ->orderBy('created_at')->orderBy('id')->limit(10)
                    ->get(['id', 'title', 'status']);
            }
        }

        return view('audit.show', [
            'a'          => $a,
            'actor'      => $actor,
            'company'    => $company,
            'diff'       => $diff,
            'class'      => $class,
            'categories' => $this->categories(),
            'session'    => $session,
            'ipHits'     => $ipHits,
            'secCode'    => $secCode,
            // النزاهة: تحقّقٌ **موضعيّ** على هذا الصفّ وحده — لا فحصَ كامل في طلب
            'verify'     => Audit::verifyRow($a),
            'rid'        => $rid,
            'siblings'   => $siblings,
            // «relErrors» لا «errors» — الاسمُ الأخير محجوزٌ لحقيبة أخطاء التحقّق المشتركة في القوالب
            'relErrors'    => $relErrors,
            'relIncidents' => $relIncidents,
            'relTasks'     => $relTasks,
        ]);
    }

    /**
     * (WP-5.5 · §1.7 · §45) محلّلُ التغطية — «هل ثمّة عملياتٌ مهمّة بلا تدقيق؟».
     *
     * **للمالك وحده**: الصفحةُ خريطةُ ما يُدقَّق وما لا يُدقَّق — وهي لغير
     * المالك خريطةُ المواضع التي لا تترك أثراً.
     */
    public function coverage()
    {
        abort_unless(hub_is_owner(auth()->user()), 403, 'محلّل التغطية لمالك النظام');

        return view('audit.coverage', [
            'report'     => self::coverageReport(),
            'categories' => $this->categories(),
            // (WP-5.5 · ق٦) الاحتفاظ يُعرض هنا كذلك للقراءة — الوصفُ بجانب الخريطة
            'retention'  => [
                'days'   => (int) setting('audit.retention_days', 0),
                'policy' => (string) setting('audit.retention_policy', ''),
            ],
        ]);
    }

    /**
     * تقريرُ التغطية: سجلُّ الوحدات × سمةُ `Auditable` × كتالوجُ
     * `SecurityEvents::CODES` × مواضعُ الكتابة الفعلية في `app/`.
     *
     * **لا يقينَ مزيَّفاً** (spec: "No fake certainty") — ثلاث درجاتٍ صادقة:
     *  · `proven`  موضعُ كتابةٍ **حرفيّ** للصيغة وُجد في المصدر.
     *  · `derived` تغطيةٌ بنيوية: سمةُ Auditable تكتب CRUD الوحدة، أو كاتبُ
     *    رادار المنع (`SecurityRadar`) يغذّي `access_denials` — إثباتُ بنيةٍ
     *    لا إثباتُ صيغةٍ حرفية.
     *  · `review`  لا إثباتَ نصّياً — قد يُبنى الفعلُ من متغيّرٍ وقد لا يُكتب
     *    أصلاً: **يحتاج مراجعة** بعينٍ بشرية، ولا يُدّعى غيرُ ذلك.
     *
     * المسحُ النصّيّ **مقيَّدٌ ومخبّأ**: `app/` وحدَه، ملفّات PHP، قراءةٌ واحدة
     * لكل ملف، وخبيئةُ ١٠ دقائق موسومةٌ بالنسخة — فلا يتحوّل التحليلُ عبئاً
     * على كل فتحِ صفحة. كتالوجٌ ممرَّرٌ (للاختبار) يُبنى بلا خبيئة.
     */
    public static function coverageReport(?array $codes = null): array
    {
        $build = function (array $codes): array {
            // ١) مصدرُ app/ في مسحةٍ واحدة — الكتالوجُ نفسُه (SecurityEvents)
            //    يُستثنى: وجودُ الصيغة في تعريفها ليس موضعَ كتابة
            $src = '';
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) continue;
                if ($f->getSize() > 1048576) continue;               // حدٌّ احترازيّ: لا ملفَّ فوق ١م.ب
                if (str_ends_with($f->getPathname(), 'SecurityEvents.php')) continue;
                $src .= "\n" . (string) @file_get_contents($f->getPathname());
            }
            $lit = fn (string $s) => str_contains($src, "'" . $s) || str_contains($src, '"' . $s);

            // ٢) الوحدات × Auditable: CRUD كلِّ وحدةٍ موسومةٍ يُكتب آلياً
            $auditableModule = function (string $mk): bool {
                $model = (string) (hub_mod($mk)['model'] ?? '');
                $class = '\\App\\Models\\' . $model;

                return $model !== '' && class_exists($class)
                    && in_array(\App\Traits\Auditable::class, class_uses_recursive($class), true);
            };
            $modules = ['total' => 0, 'audited' => 0, 'missing' => []];
            foreach (hub_modules() as $mk => $def) {
                $modules['total']++;
                $auditableModule($mk)
                    ? $modules['audited']++
                    : $modules['missing'][] = (string) ($def['label'] ?? $mk);
            }

            // ٣) الكتالوج الأمنيّ × مواضعُ الكتابة — درجةُ كلِّ صيغةٍ بدليلها
            $radar = str_contains($src, "access_denials')->insert");
            $events = [];
            foreach ($codes as $code => [$label, $sev, $acts]) {
                $rows = [];
                foreach ($acts as $a) {
                    if (str_starts_with($a, '@module:')) {
                        $mk = explode(':', substr($a, 8), 2)[0];
                        if ($auditableModule($mk)) {
                            $rows[] = ['action' => $a, 'status' => 'derived',
                                'why' => "سمةُ Auditable تكتب CRUD وحدة «{$mk}» آلياً"];
                        } elseif (preg_match("/hub_audit\\([^;]*?'" . preg_quote($mk, '/') . "'/su", $src)) {
                            // وحدةٌ خارج السجل (roles مثلاً): كاتبُها اليدويّ يسمّيها
                            // في hub_audit والفعلُ قد يُبنى من متغيّر — بنيةٌ لا صيغة
                            $rows[] = ['action' => $a, 'status' => 'derived',
                                'why' => "كاتبٌ يدويّ يسمّي وحدة «{$mk}» في hub_audit — الفعل قد يُبنى من متغيّر"];
                        } else {
                            $rows[] = ['action' => $a, 'status' => 'review',
                                'why' => "وحدة «{$mk}» بلا سمة Auditable ولا كاتبٍ يدويّ ظاهر — لا إثبات"];
                        }
                    } elseif (str_starts_with($a, '@denial:')) {
                        $rows[] = ['action' => $a, 'status' => $radar ? 'derived' : 'review',
                            'why' => $radar ? 'يكتبه رادارُ المنع (SecurityRadar ← access_denials) لا التدقيق'
                                            : 'كاتبُ access_denials غيرُ موجود — لا إثبات'];
                    } elseif (str_starts_with($a, '@prefix:')) {
                        $ok = $lit(substr($a, 8));
                        $rows[] = ['action' => $a, 'status' => $ok ? 'proven' : 'review',
                            'why' => $ok ? 'بادئةُ الصيغة لها موضعُ كتابةٍ حرفيّ'
                                         : 'لا موضعَ كتابةٍ بهذه البادئة حرفياً'];
                    } elseif (str_starts_with($a, '@settings:')) {
                        // الصيغةُ مركَّبةٌ هنا كي لا يطابق المسحُ هذا الملفَ نفسَه
                        $ok = $lit('تعديل إعدادات' . ' النظام');
                        $rows[] = ['action' => $a, 'status' => $ok ? 'derived' : 'review',
                            'why' => $ok ? 'يُشتقّ من قيد الإعدادات بنمط اسم المفتاح وقتَ القراءة'
                                         : 'كاتبُ قيد الإعدادات غيرُ موجود — لا إثبات'];
                    } else {
                        $ok = $lit($a);
                        $rows[] = ['action' => $a, 'status' => $ok ? 'proven' : 'review',
                            'why' => $ok ? 'موضعُ كتابةٍ حرفيّ في app/'
                                         : 'لا موضعَ كتابةٍ حرفياً — قد يُبنى الفعل من متغيّر وقد لا يُكتب أصلاً'];
                    }
                }
                // درجةُ الكود أدنى درجات صيغه — الأسوأ يحكم لا الأحسن
                $rank = ['proven' => 0, 'derived' => 1, 'review' => 2];
                $worst = 'proven';
                foreach ($rows as $r) if ($rank[$r['status']] > $rank[$worst]) $worst = $r['status'];
                $events[$code] = ['label' => $label, 'severity' => $sev,
                                  'status' => $worst, 'actions' => $rows];
            }

            // ٤) الثغراتُ المعلومة — حالُها يُفحص من المصدر نفسِه لا يُفترض،
            //    فإغلاقُ ثغرةٍ لاحقاً يقلب حالَها هنا بلا تحرير
            $gaps = [
                ['key' => 'restore', 'state' => 'partial',
                 'title' => 'الاستعادة تُكتب «تعديل» لا «استعادة»',
                 'note' => '$m->restore() يمرّ بحدث updated فلا فعلَ «استعادة» يكتبه أحد — '
                     . 'الفئة RESTORE تُشتقّ من الفرق (deleted_at ← null) كتابةً وقراءةً (WP-5.2). '
                     . 'تغطيةٌ جزئية: التصنيف صحيح والصيغة الحرفية «تعديل».'],
                // صيغُ الفحص مركَّبةٌ ('…' . '…') كي لا يطابق المسحُ النصّيّ
                // نداءاتِ الفحص نفسَها في هذا الملف فيدّعي إغلاقاً لم يقع
                ['key' => 'error_lifecycle',
                 'state' => ($lit('تغيير حالة' . ' خطأ') && $lit('إسناد خطأ' . ' لمهمة')) ? 'closed' : 'open',
                 'title' => 'أفعالُ دورة حياة الخطأ (تغيير حالة/تجاهل/كتم/إسناد لمهمة)',
                 'note' => 'كانت بلا قيدٍ إطلاقاً — أُغلقت في الطور ٣ (WP-3.3) بقيودٍ حرفية.'],
                ['key' => 'saved_view_store',
                 'state' => $lit('حفظ تحقيق' . ' تدقيق') ? 'closed' : 'open',
                 'title' => 'حفظُ تحقيقٍ محفوظ على سجل التدقيق',
                 'note' => 'من حفظ أيَّ سؤالٍ عن السجل سؤالٌ مشروع للمدقّق — يُدقَّق منذ WP-5.3.'],
                ['key' => 'saved_view_destroy',
                 'state' => $lit('حذف تحقيق' . ' تدقيق') ? 'closed' : 'open',
                 'title' => 'حذفُ تحقيقٍ محفوظ بلا قيد تدقيق',
                 'note' => 'حذفُ العروض المحفوظة (تحقيقاتُ التدقيق ضمناً) لا يكتب قيداً — '
                     . 'من أخفى سؤالَه عن السجل لا أثرَ لإخفائه. تُغلق بقيدٍ في PrefController::destroyView.'],
            ];

            // خلاصةُ الدرجات عبر كل الصيغ — أرقامُ البطاقات
            $counts = ['proven' => 0, 'derived' => 0, 'review' => 0];
            foreach ($events as $e) foreach ($e['actions'] as $r) $counts[$r['status']]++;

            return compact('modules', 'events', 'gaps', 'counts');
        };

        // كتالوجٌ ممرَّر (اختبار) = بناءٌ مباشر؛ الكتالوجُ القانونيّ يُخبّأ ١٠ دقائق
        if ($codes !== null) return $build($codes);

        return \Illuminate\Support\Facades\Cache::remember(
            'audit:coverage:' . config('hub.version'), 600,
            fn () => $build(SecurityEvents::CODES));
    }

    /**
     * ترشيحُ المدى على `audits.created_at` — كنمط `TimeRange::apply` (`>= from`
     * و`< to`) مع تقويمِ حدِّ النهاية: `to` للكبسولات هو «الآن» بميكروثوانٍ،
     * والربطُ يقصّها **نزولاً** لصيغة الثواني بينما `created_at` مخزّنٌ بدقّة
     * الثانية — فتسقط صفوفُ الثانية الجارية من «مدى ينتهي الآن» (وتُسقط الحزمةَ
     * التي تكتب وتقرأ في الثانية نفسِها). يُقرَّب صعوداً لثانيةٍ كاملة، وحدودُ
     * المدى المخصّص (ثوانٍ صحيحة أصلاً) لا تمسّ.
     */
    protected function applyRange($q, TimeRange $range): void
    {
        $to = $range->to->copy();
        if ((int) $to->format('u') > 0) $to = $to->startOfSecond()->addSecond();
        $q->where('audits.created_at', '>=', $range->from)
          ->where('audits.created_at', '<', $to);
    }

    /**
     * المرشِّحاتُ كلُّها `where` فوق النطاق — تضييقٌ محض، لا `orWhere` يفتح
     * ما أغلقه `Audit::scopedQuery` (نمط SecurityCenterTest::filters_do_not_bypass_scope).
     */
    protected function applyFilters($q, Request $r): void
    {
        $q->when($r->input('module'), fn ($w, $m) => $w->where('audits.module', $m))
            ->when($r->input('user'), fn ($w, $v) => $w->where('audits.user_id', $v))
            ->when($r->input('action'), fn ($w, $a) => $w->where('audits.action', $a))
            ->when($r->input('ip'), fn ($w, $i) => $w->where('audits.ip', 'LIKE', "%$i%"))
            ->when($r->input('q'), fn ($w, $t) => $w->where(fn ($x) => $x
                ->where('audits.name', 'LIKE', "%$t%")->orWhere('audits.reason', 'LIKE', "%$t%")))
            // ── (WP-5.3) مرشِّحاتُ التحقيق المتقدّم — على الأعمدة المطبَّعة (WP-5.2) ──
            ->when(in_array($r->input('severity'), ['info', 'notice', 'warning', 'high'], true),
                fn ($w) => $w->where('audits.severity', $r->input('severity')))
            ->when(preg_match('/^[A-Z_]{2,24}$/', (string) $r->input('category')) === 1,
                fn ($w) => $w->where('audits.category', $r->input('category')))
            ->when($r->input('role'), fn ($w, $v) => $w->where('users.role_id', $v))
            ->when($r->input('company'), fn ($w, $v) => $w->where('audits.company_id', $v))
            ->when($r->input('project'), fn ($w, $v) => $w->where('audits.project_id', $v))
            ->when($r->input('request_id'), fn ($w, $v) => $w->where('audits.request_id', $v))
            // حسّاسٌ فقط: شدّةٌ مخزّنة عالية، أو — للصفوف الأقدم من التطبيع —
            // صيغةُ فعلٍ من الأكواد الأمنية عالية الشدّة
            ->when($r->boolean('sensitive'), fn ($w) => $w->where(fn ($x) => $x
                ->where('audits.severity', 'high')->orWhereIn('audits.action', self::highActions())))
            // فاشلٌ فقط: المآلُ المخزّن، أو صيغُ الفشل الأمنية للصفوف القديمة
            ->when($r->boolean('failed'), fn ($w) => $w->where(fn ($x) => $x
                ->whereIn('audits.outcome', ['failed', 'denied'])
                ->orWhereIn('audits.action', self::failureActions())))
            // بياناتٌ تغيّرت فقط: قيدٌ يحمل فرقاً (قبل/بعد)
            ->when($r->boolean('changed'), fn ($w) => $w->where(fn ($x) => $x
                ->whereNotNull('audits.before')->orWhereNotNull('audits.after')))
            // طوارئ: قفلُ الطوارئ ومفاتيحُ التجميد — بالفئة المخزّنة وبالصيغ للقديم
            ->when($r->boolean('emergency'), fn ($w) => $w->where(fn ($x) => $x
                ->where('audits.category', 'SECURITY_POLICY_CHANGED')
                ->orWhereIn('audits.action', SecurityEvents::actions('SECURITY_POLICY_CHANGED'))
                ->orWhere('audits.action', 'LIKE', 'تجميد %')
                ->orWhere('audits.action', 'LIKE', 'رفع تجميد %')))
            ->when($r->input('client'), fn ($w, $v) => $this->clientFilter($w, $v));
    }

    /**
     * مرشِّحُ العميل — نظيرُ البند ٤ في `Audit::scopedQuery` تضييقاً لا نطاقاً:
     * أثرُ سجلّاتِ هذا العميل وحدَه عبر عمود العميل في جدول كل وحدةٍ يراها
     * القارئ. القيدُ بلا سجلٍّ مطابق يسقط — مرشِّحٌ يعني «هذا العميل فقط».
     */
    protected function clientFilter($q, string $clientId)
    {
        $u = auth()->user();

        return $q->where(function ($w) use ($clientId, $u) {
            $w->whereRaw('1 = 0');
            foreach (array_keys(hub_modules()) as $mk) {
                if (! hub_can($u, $mk, 'v')) continue;
                $col = hub_client_col($mk);
                if ($col === null) continue;
                if ($col === 'id') {           // وحدةُ العملاء نفسها: السجلُّ هو العميل
                    $w->orWhere(fn ($x) => $x->where('audits.module', $mk)
                                             ->where('audits.record_id', $clientId));
                    continue;
                }
                $table = hub_mod($mk)['table'] ?? null;
                try {
                    if (! $table || ! Schema::hasTable($table)) continue;
                } catch (\Throwable $e) {
                    continue;
                }
                $w->orWhere(fn ($x) => $x->where('audits.module', $mk)
                    ->whereIn('audits.record_id',
                        fn ($s) => $s->select('id')->from($table)->where($col, $clientId)));
            }
        });
    }

    /**
     * النظرةُ التنفيذية: ١٢ عدّاداً في مسحٍ تجميعيٍّ واحد + استعلامِ «الجديد»
     * — كلُّها فوق `Audit::scopedQuery` فلا يَعُدّ القارئُ ما لا يراه، وكلُّها
     * في القاعدة (SUM CASE / COUNT DISTINCT) لا تحميلَ صفوفٍ إلى PHP.
     *
     * @return array<int, array{label:string, value:int|string, tone?:string, sub?:string, url?:string}>
     */
    protected function counters(TimeRange $range): array
    {
        $u = auth()->user();

        $secrets = array_values(array_unique(array_merge(
            SecurityEvents::actions('SECRET_REVEALED'), SecurityEvents::actions('CLASSIFIED_ACCESS'))));
        $fails = self::failureActions();
        $emg = SecurityEvents::actions('SECURITY_POLICY_CHANGED');

        // حدودُ الدوام تُطبَّع HH:MM:SS فتتطابق مقارنةُ time() نصياً على SQLite
        // وزمنياً على MySQL — `time()` بالحرف الصغير تفهمه اللهجتان
        $hs = (string) setting('sec.hours_start', '08:00');
        $he = (string) setting('sec.hours_end', '16:00');
        if (preg_match('/^\d{2}:\d{2}$/', $hs)) $hs .= ':00';
        if (preg_match('/^\d{2}:\d{2}$/', $he)) $he .= ':00';

        $in = fn (array $a) => implode(',', array_fill(0, count($a), '?'));

        $base = Audit::scopedQuery($u);
        $this->applyRange($base, $range);
        $row = $base->selectRaw(
            'COUNT(*) AS c_total,
             COUNT(DISTINCT audits.user_id) AS c_actors,
             COUNT(DISTINCT audits.ip) AS c_ips,
             SUM(CASE WHEN audits.action = ? THEN 1 ELSE 0 END) AS c_del,
             SUM(CASE WHEN audits.action = ? THEN 1 ELSE 0 END) AS c_exp,
             SUM(CASE WHEN audits.action IN (' . $in($secrets) . ') THEN 1 ELSE 0 END) AS c_secrets,
             SUM(CASE WHEN audits.outcome IN (?, ?) OR audits.action IN (' . $in($fails) . ') THEN 1 ELSE 0 END) AS c_failed,
             SUM(CASE WHEN audits.severity = ? THEN 1 ELSE 0 END) AS c_high,
             SUM(CASE WHEN time(audits.created_at) < ? OR time(audits.created_at) >= ? THEN 1 ELSE 0 END) AS c_off,
             SUM(CASE WHEN audits.category = ? OR audits.action IN (' . $in($emg) . ') OR audits.action LIKE ? OR audits.action LIKE ? THEN 1 ELSE 0 END) AS c_emg,
             SUM(CASE WHEN audits.before IS NOT NULL OR audits.after IS NOT NULL THEN 1 ELSE 0 END) AS c_chg',
            array_merge(['حذف', 'تصدير'], $secrets, ['failed', 'denied'], $fails,
                ['high', $hs, $he, 'SECURITY_POLICY_CHANGED'], $emg, ['تجميد %', 'رفع تجميد %'])
        )->first();

        // الجديد: عنوانٌ مميّزٌ داخل المدى لم يُر في التسعين يوماً قبله —
        // والمرجعُ منطَّقٌ كذلك، وإلا بدا عنوانُ شركةٍ أخرى «معروفاً» فيُخفى
        $seen = Audit::scopedQuery($u)
            ->where('audits.created_at', '<', $range->from)
            ->where('audits.created_at', '>=', $range->from->copy()->subDays(90))
            ->whereNotNull('audits.ip')
            ->select('audits.ip');
        $fresh = Audit::scopedQuery($u);
        $this->applyRange($fresh, $range);
        $newIps = (int) $fresh->whereNotNull('audits.ip')->whereNotIn('audits.ip', $seen)
            ->distinct()->count('audits.ip');

        $url = fn (array $extra = []) => route('audit.index') . '?' . http_build_query($range->toQuery() + $extra);
        $n = fn ($v) => (int) $v;
        $wn = fn ($v) => (int) $v > 0 ? 'wn' : '';
        $bad = fn ($v) => (int) $v > 0 ? 'bad' : '';

        return [
            ['label' => '🧾 قيود المدى', 'value' => $n($row->c_total), 'sub' => $range->label(), 'url' => $url()],
            ['label' => '👤 فاعلون', 'value' => $n($row->c_actors), 'sub' => 'مستخدمون مميّزون'],
            ['label' => '🌐 عناوين IP', 'value' => $n($row->c_ips), 'sub' => 'عناوين مميّزة'],
            ['label' => '🆕 عناوين جديدة', 'value' => $newIps, 'tone' => $wn($newIps),
                'sub' => 'لم تُر في ٩٠ يوماً قبل المدى'],
            ['label' => '🗑️ حذف', 'value' => $n($row->c_del), 'tone' => $wn($row->c_del),
                'sub' => 'أثرٌ لا يُستردّ', 'url' => $url(['action' => 'حذف'])],
            ['label' => '📤 تصدير', 'value' => $n($row->c_exp), 'tone' => $wn($row->c_exp),
                'sub' => 'بياناتٌ غادرت النظام', 'url' => $url(['action' => 'تصدير'])],
            ['label' => '🔑 كشف أسرار', 'value' => $n($row->c_secrets), 'tone' => $bad($row->c_secrets),
                'sub' => 'عرضٌ حساس أو بياناتٌ مصنَّفة'],
            ['label' => '⛔ فاشل أو مرفوض', 'value' => $n($row->c_failed), 'tone' => $wn($row->c_failed),
                'sub' => 'مآلُ العملية لا نجاحها', 'url' => $url(['failed' => 1])],
            ['label' => '🚨 شديدة الخطورة', 'value' => $n($row->c_high), 'tone' => $bad($row->c_high),
                'sub' => 'شدّةٌ مخزّنة high', 'url' => $url(['severity' => 'high'])],
            ['label' => '🌙 خارج الدوام', 'value' => $n($row->c_off), 'tone' => $wn($row->c_off),
                'sub' => 'قبل ' . mb_substr($hs, 0, 5) . ' أو بعد ' . mb_substr($he, 0, 5)],
            ['label' => '🧯 طوارئ أمنية', 'value' => $n($row->c_emg), 'tone' => $bad($row->c_emg),
                'sub' => 'قفلٌ أو تجميدُ مفاتيح', 'url' => $url(['emergency' => 1])],
            ['label' => '✏️ بيانات تغيّرت', 'value' => $n($row->c_chg),
                'sub' => 'قيودٌ تحمل فرقاً', 'url' => $url(['changed' => 1])],
        ];
    }

    /** صيغُ الأفعال عالية الشدّة من الكتالوج الأمنيّ — للصفوف الأقدم من التطبيع */
    protected static function highActions(): array
    {
        $out = [];
        foreach (SecurityEvents::CODES as [, $sev, $acts]) {
            if ($sev !== 'high') continue;
            foreach ($acts as $a) if (! str_starts_with($a, '@')) $out[] = $a;
        }

        return array_values(array_unique($out));
    }

    /** صيغُ الفشل الأمنية الحرفية — دخولٌ وتحقّقٌ وتصعيدٌ فاشل */
    protected static function failureActions(): array
    {
        return array_values(array_unique(array_merge(
            SecurityEvents::actions('AUTH_FAILURE'),
            SecurityEvents::actions('MFA_FAILURE'),
            SecurityEvents::actions('PASSKEY_FAILURE'),
            SecurityEvents::actions('STEP_UP_FAILURE'))));
    }

    /** فئاتُ التصنيف بأسمائها العربية: الأكوادُ الأمنية من كتالوجها + فئاتُ النطاق (WP-5.2) */
    protected function categories(): array
    {
        $out = [];
        foreach (SecurityEvents::CODES as $code => [$label]) $out[$code] = $label;

        return $out + [
            'DELETE' => 'حذف', 'EXPORT' => 'تصدير', 'IMPORT' => 'استيراد',
            'SECRET_ACCESS' => 'وصول لحساس', 'SETTINGS' => 'إعدادات', 'FINANCE' => 'مالية',
            'API' => 'واجهة API', 'INTEGRATION' => 'تكامل', 'ADMINISTRATION' => 'إدارة',
            'DATA_CHANGE' => 'تغيير بيانات', 'RESTORE' => 'استعادة',
        ];
    }
}
