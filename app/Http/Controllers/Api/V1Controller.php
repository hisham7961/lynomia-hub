<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Web\ModuleController;
use Illuminate\Http\Request;

/**
 * REST API v1 — يرث محرك الوحدات نفسه: الصلاحيات والنطاق والتحقق والتعبئة
 * واحدة في الويب والـ API. الكتابة تمر بالتدقيق تلقائياً باسم صاحب المفتاح.
 */
class V1Controller extends ModuleController
{
    /** GET /api/v1/me */
    public function me()
    {
        $u = auth()->user();

        return response()->json([
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'role' => $u->role?->name, 'is_owner' => hub_is_owner($u),
        ]);
    }

    /** GET /api/v1/modules — ما يستطيع صاحب المفتاح رؤيته */
    public function modules()
    {
        $out = [];
        foreach (hub_modules() as $key => $def) {
            if (! hub_can(auth()->user(), $key, 'v')) continue;
            if (! $this->tokenAllows($key, 'v')) continue;   // الفهرس يصدق عمّا يستطيعه هذا المفتاح
            $out[] = [
                'key' => $key, 'label' => $def['label'], 'table' => $def['table'],
                'can' => collect(['v', 'a', 'e', 'd'])->filter(fn ($op) => hub_can(auth()->user(), $key, $op))->values(),
                'fields' => collect(hub_visible_fields(auth()->user(), $key, $def))->map(fn ($f) => [
                    'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'],
                    'required' => (bool) ($f['required'] ?? false), 'ref' => $f['ref'] ?? null,
                ]),
            ];
        }

        return response()->json(['modules' => $out]);
    }

    /** GET /api/v1/{module}?q=&status=&page=&per= */
    public function apiIndex(Request $r, string $module)
    {
        [$def, $class] = $this->resolveApi($module, 'v');

        $q = hub_scope($class::query(), $module);
        if ($term = trim(hub_str($r->query('q')))) $q->search($term);
        if (($st = hub_str($r->query('status'))) !== '' && ($sc = hub_status_col($module))) $q->where($sc, $st);

        // فاصلُ id: الطابع بدقّة الثانية يتساوى فتتقلب الصفحات بين الطلبات
        $page = $q->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $r->query('per', 25))));

        $fields = $r->query('fields');
        $this->auditApiSecretRead($def, count($page->items()));

        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => $this->shape($def, $row, $fields)),
            'total' => $page->total(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    /** GET /api/v1/{module}/{id}?fields= */
    public function apiShow(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'v');
        $row = hub_scope($class::query(), $module)->findOrFail($id);
        $this->auditApiSecretRead($def, 1, (string) $id);

        return response()->json(['data' => $this->shape($def, $row, $r->query('fields'))]);
    }

    /** POST /api/v1/{module} — نفس تحقق النماذج، مع Idempotency-Key اختيارية */
    public function apiStore(Request $r, string $module)
    {
        [$def, $class] = $this->resolveApi($module, 'a');

        // الحجز **قبل** التنفيذ لا بعده: طلبان متزامنان بنفس المفتاح كانا ينفّذان
        // معاً (سجلان وويبهوكان) ولا يُحسم إلا تخزين الرد — الآن يُنفَّذ واحد فقط
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $r->validate($this->rules($def, true), [], $this->attrs($def));
            $this->guardProject($r, $module);
            $this->guardCompany($r, $module);

            $m = new $class;
            $this->fill($def, $r, $m);
            $this->inheritCompany($m, $module);   // كالويب: معزولٌ يرث شركته فلا يختفي عنه
            $this->inheritProject($m, $module);   // ومشروعَه — وإلا وُلد يتيماً لا يراه
            $m->save();
            $this->notifyAssignee($def, $module, $m);
            $this->bustProgress($module, $m);
            \App\Support\FlowRunner::fire('created', $module, $m);

            $resp = response()->json(['data' => $this->shape($def, $m->fresh())], 201);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            // فشل قبل الإتمام (تحقق مثلاً): حرّر الحجز كي لا يُحجب المفتاح للأبد
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /** PUT /api/v1/{module}/{id} */
    public function apiUpdate(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'e');
        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            return response()->json(['error' => 'هذه العملية محمية بالموافقات — نفّذها من الواجهة ليُصفّ الطلب'], 409);
        }
        $r->validate($this->rules($def, false), [], $this->attrs($def));
        $this->guardProject($r, $module);
        $this->guardCompany($r, $module);

        $m = $this->findScoped($class, $module, $id);
        $prev = ($af = $this->assigneeField($def)) ? $m->{$af['col']} : null;
        $prevStatus = ($sc = hub_status_col($module)) ? $m->{$sc} : null;
        $this->fill($def, $r, $m);
        $m->save();
        $this->notifyAssignee($def, $module, $m, $prev);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('updated', $module, $m);
        if ($sc && (string) $m->{$sc} !== (string) $prevStatus) {
            \App\Support\FlowRunner::fire('status', $module, $m, (string) $m->{$sc});
        }

        return response()->json(['data' => $this->shape($def, $m->fresh())]);
    }

    /** DELETE /api/v1/{module}/{id} */
    public function apiDestroy(string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'd');
        if (hub_needs_approval(auth()->user(), $module, 'd')) {
            return response()->json(['error' => 'هذه العملية محمية بالموافقات — نفّذها من الواجهة ليُصفّ الطلب'], 409);
        }
        $this->findScoped($class, $module, $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** GET /api/v1/reports/progress/{projectId} */
    public function progress(string $projectId)
    {
        abort_unless(hub_can(auth()->user(), 'projects', 'v'), 403);
        abort_unless($this->tokenAllows('projects', 'v'), 403, 'نطاق هذا المفتاح لا يشمل «projects:v»');
        hub_scope(\App\Models\Project::query(), 'projects')->findOrFail($projectId);

        return response()->json(hub_progress($projectId));
    }

    /** GET /api/v1/reports/health — للمالكين */
    public function health()
    {
        abort_unless(hub_is_owner(), 403);
        abort_unless($this->tokenAllows('reports', 'v'), 403, 'نطاق هذا المفتاح لا يشمل «reports:v»');

        return response()->json(hub_health());
    }

    /**
     * POST /api/v1/metrics — استقبال المقاييس الزمنية آلياً (n8n وغيره).
     * دفعةٌ واحدة: `{"points":[{module, record_id, metric, value, at?, source?, meta?}, ...]}`
     * أو نقطةٌ مفردة في جسم الطلب مباشرة. كل نقطةٍ تمر بصلاحية **تعديل** وحدتها
     * وبنطاق المفتاح وبنطاق الرؤية — لا تُسجَّل قياساتٌ على سجلٍ لا يُرى.
     */
    public function metricsIngest(Request $r)
    {
        $points = $r->input('points');
        if (! is_array($points)) $points = [$r->all()];

        abort_if(count($points) > 500, 422, 'الدفعة الواحدة حتى ٥٠٠ نقطة');

        $data = validator(['points' => $points], [
            'points' => 'required|array|min:1',
            'points.*.module' => 'required|string|max:40',
            'points.*.record_id' => 'required|string|max:40',
            'points.*.metric' => 'required|string|max:40',
            // حدٌّ صريح: العمود decimal(18,4) (|القيمة| < 10¹⁴)، و«numeric» وحده
            // يقبل 1e15 و«1e400»→INF فيُفيض العمود ويقع 500 وسط الدفعة. between
            // يرفض الفائض وغير المنتهي معاً قبل أي كتابة.
            'points.*.value' => 'required|numeric|between:-9999999999999,9999999999999',
            'points.*.at' => 'nullable|date',
            'points.*.source' => 'nullable|string|max:24',
            'points.*.meta' => 'nullable|array',
        ], [], [
            'points' => 'النقاط', 'points.*.module' => 'الوحدة', 'points.*.record_id' => 'السجل',
            'points.*.metric' => 'المقياس', 'points.*.value' => 'القيمة',
        ])->validate()['points'];

        // الوحدات والسجلات تُتحقّق **قبل** أي كتابة: دفعةٌ نصفها خطأ لا تُكتب نصفها
        $seen = [];
        foreach ($data as $i => $p) {
            $mod = $p['module'];
            if (! isset($seen[$mod])) {
                // وحدةٌ مجهولة عيبُ حمولةٍ لا مسارٌ مفقود — ٤٢٢ لا ٤٠٤
                if (! hub_mod($mod) || $mod === 'users') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "points.$i.module" => 'وحدة غير معروفة: «' . $mod . '»',
                    ]);
                }
                [$def, $class] = $this->resolveApi($mod, 'e');   // القياس تعديلٌ على السجل
                $seen[$mod] = $class;
            }
            $class = $seen[$mod];
            if (! hub_scope($class::query(), $mod)->whereKey($p['record_id'])->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "points.$i.record_id" => 'سجل غير موجود أو خارج نطاقك في وحدة «' . $mod . '»',
                ]);
            }
        }

        // معاملةٌ تلفّ الكتابة كلها: تصدّق ضمان «دفعةٌ نصفها خطأ لا تُكتب نصفها»
        // المعلن أعلاه — عطلٌ في نقطةٍ (بعد التحقق) كان يُبقي ما قبلها مكتوباً
        $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $n = 0;
            foreach ($data as $p) {
                hub_metric_put($p['module'], $p['record_id'], $p['metric'], (float) $p['value'],
                    $p['at'] ?? null, $p['source'] ?? 'api', $p['meta'] ?? []);
                $n++;
            }

            return $n;
        });

        return response()->json(['saved' => $saved, 'at' => now()->toIso8601String()]);
    }

    /** GET /api/v1/metrics/{module}/{id}?metric=&days= — السلسلة والنمو */
    public function metricsShow(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'v');
        hub_scope($class::query(), $module)->findOrFail($id);

        $metric = trim(hub_str($r->query('metric')));
        $days = min(730, max(1, (int) $r->query('days', 90)));

        if ($metric === '') {
            return response()->json(['metrics' => \App\Models\MetricPoint::where('module', $module)
                ->where('record_id', $id)->distinct()->orderBy('metric')->pluck('metric')]);
        }

        $series = hub_metric_series($module, $id, $metric, $days);

        return response()->json([
            'module' => $module, 'record_id' => $id, 'metric' => $metric, 'days' => $days,
            'latest' => hub_metric_latest($module, $id, $metric),
            'growth' => hub_metric_growth($module, $id, $metric, $days),
            'series' => array_map(fn ($p) => [
                'at' => $p['at']->toIso8601String(), 'value' => $p['value'], 'source' => $p['source'],
            ], $series),
        ]);
    }

    /** حل الوحدة لطلبات API برسائل JSON */
    protected function resolveApi(string $module, string $op): array
    {
        $def = hub_mod($module);
        abort_if(! $def || $module === 'users', 404, 'وحدة غير معروفة');
        abort_unless(hub_can(auth()->user(), $module, $op), 403, 'لا تملك هذه الصلاحية على الوحدة');

        // نطاقات المفتاح: مفتاح مقيد لا يتجاوز قيده حتى لو كان صاحبه يستطيع
        $token = request()->attributes->get('api_token');
        if ($token && ! $token->allows($module, $op)) {
            abort(403, 'نطاق هذا المفتاح لا يشمل «' . $module . ':' . $op . '» — أنشئ مفتاحاً بنطاق أوسع أو عدّل النطاقات');
        }

        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }

    /**
     * حجز مفتاح Idempotency قبل التنفيذ. يعيد:
     *  null  — لا مفتاح في الطلب، امضِ عادياً
     *  true  — حُجز الآن، نفّذ ثم idempotentFinish (أو Release عند الفشل)
     *  Response — إما إعادة الرد المخزن (تنفيذ سابق مكتمل) أو 409 (تنفيذ متزامن جارٍ)
     */
    protected function idempotentBegin(Request $r)
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return null;
        $fp = $this->fingerprintOf($r);

        for ($try = 0; $try < 2; $try++) {
            try {
                \Illuminate\Support\Facades\DB::table('idempotency_keys')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'token_id' => $tokenId, 'ikey' => $ikey, 'fingerprint' => $fp,
                    'code' => null, 'response' => null,           // يُملآن عند الإتمام
                    'created_at' => now(),
                ]);

                return true;
            } catch (\Illuminate\Database\QueryException $e) {
                $row = \Illuminate\Support\Facades\DB::table('idempotency_keys')
                    ->where('token_id', $tokenId)->where('ikey', $ikey)->first();
                if (! $row) continue;                             // حُذف تحتنا (تنظيف) — أعد المحاولة

                // مفتاحٌ أُعيد بطلبٍ مختلف (مسار/جسم): لا نعيد ردَّ الأول (بيانات وحدةٍ
                // أخرى) ولا نُسقط الثاني بصمت — نرفض صراحةً كي يتبيّن العميلُ خطأه.
                if (($row->fingerprint ?? null) !== null && ! hash_equals((string) $row->fingerprint, $fp)) {
                    return response()->json(
                        ['error' => 'مفتاح idempotency أُعيد بطلبٍ مختلف — استعمل مفتاحاً جديداً لكل طلب'], 422);
                }

                if ($row->response !== null) {
                    return response($row->response, $row->code)
                        ->header('Content-Type', 'application/json')
                        ->header('X-Idempotent-Replay', 'true');
                }

                // حجز يتيم من محاولة ماتت قبل الإتمام: بعد دقيقة يجوز الاستيلاء عليه
                if (\Illuminate\Support\Carbon::parse($row->created_at)->lt(now()->subMinute())) {
                    \Illuminate\Support\Facades\DB::table('idempotency_keys')
                        ->where('id', $row->id)->whereNull('response')->delete();
                    continue;
                }

                return response()->json(['error' => 'الطلب نفسه قيد المعالجة الآن — أعد المحاولة بعد لحظات'], 409);
            }
        }

        return true;    // التنظيف انزلق تحتنا مرتين — لا نعطّل العميل
    }

    /** إتمام الحجز: يُملأ الرد المخزن تحت المفتاح (مع تنظيف ما جاوز يومين) */
    protected function idempotentFinish(Request $r, $resp): void
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return;

        try {
            \Illuminate\Support\Facades\DB::table('idempotency_keys')
                ->where('token_id', $tokenId)->where('ikey', $ikey)
                ->update(['code' => $resp->getStatusCode(), 'response' => $resp->getContent()]);
            \Illuminate\Support\Facades\DB::table('idempotency_keys')
                ->where('created_at', '<', now()->subDays(2))->delete();
        } catch (\Throwable $e) {
            // فشل التخزين لا يكسر الرد — أسوأ الأحوال إعادة تنفيذ مكشوفة لاحقاً
        }
    }

    /** تحرير حجز لم يكتمل — كي لا يظل المفتاح محجوباً بعد طلب فاشل */
    protected function idempotentRelease(Request $r): void
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return;

        try {
            \Illuminate\Support\Facades\DB::table('idempotency_keys')
                ->where('token_id', $tokenId)->where('ikey', $ikey)->whereNull('response')->delete();
        } catch (\Throwable $e) {
            // الحجز اليتيم يُستولى عليه بعد دقيقة على كل حال
        }
    }

    protected function ikeyOf(Request $r): array
    {
        $ikey = trim((string) $r->header('Idempotency-Key'));
        $token = $r->attributes->get('api_token');
        if ($ikey === '' || mb_strlen($ikey) > 120 || ! $token) return [null, null];

        return [$token->id, $ikey];
    }

    /** بصمةُ الطلب: مفتاحٌ واحد لا يخدم إلا طلباً واحداً (مسار + جسم) */
    protected function fingerprintOf(Request $r): string
    {
        return hash('sha256', $r->method() . '|' . $r->path() . '|' . (string) $r->getContent());
    }

    /**
     * قيدُ تدقيقٍ لقراءة الأسرار عبر API — كما يُدقَّق كل كشفٍ في الويب (revealSecret).
     * يُسجَّل فقط حين تُعاد أسرارٌ صريحة فعلاً: وحدةٌ فيها حقل sec ومستخدمٌ يملك كشفها.
     */
    protected function auditApiSecretRead(array $def, int $count, ?string $recordId = null): void
    {
        if ($count < 1) return;
        $hasSec = collect($def['fields'] ?? [])->contains(fn ($f) => ($f['type'] ?? '') === 'sec');
        if (! $hasSec || ! hub_copy_secrets(auth()->user())) return;
        hub_audit('عرض حساس عبر API', (string) ($def['key'] ?? ''), $recordId, $count . ' سجلّ أسرار');
    }

    /**
     * تشكيل السجل قبل إخراجه — يمرّ به **كل** رد يحمل سجلاً (قراءةً وكتابةً):
     *  ١) يُسقط الحقول المخفية بصلاحيات مستوى الحقل،
     *  ٢) يُسقط حقول الأسرار عمّن لا يملك علم رؤيتها (كما تُقنّعها الواجهة تماماً)
     *     — إسقاطاً لا تقنيعاً، فإعادة إرسال السجل بـ PUT لا تدهس السر بقيمة قناع،
     *  ٣) ثم يطبّق اختيار الحقول ?fields=.
     * لا مسار يعيد السجل خاماً بعد اليوم.
     */
    protected function shape(array $def, $row, ?string $fields = null)
    {
        $module = (string) ($def['key'] ?? '');
        $u = auth()->user();
        $canSec = hub_copy_secrets($u);
        $arr = is_array($row) ? $row : $row->toArray();

        // «المستخدمون المخولون» في الخزنة تسري على الـ API كما تسري على الواجهة:
        // قائمة غير فارغة تحجب السر عمّن ليس فيها (والمالك محصّن)
        $allowed = array_values(array_filter(array_map('strval', (array) ($arr['allowed_ids'] ?? []))));
        $inAllowed = ! $allowed || hub_is_owner($u) || in_array((string) $u?->id, $allowed, true);

        foreach ($def['fields'] as $f) {
            $hidden = hub_field_mode($u, $module, $f['key']) === 'hide';
            $secret = ($f['type'] ?? '') === 'sec' && (! $canSec || ! $inAllowed);
            if ($hidden || $secret) unset($arr[$f['col']]);
        }

        $want = preg_split('/[،,\s]+/u', (string) $fields, -1, PREG_SPLIT_NO_EMPTY);
        if (! $want) return $arr;

        $out = ['id' => $arr['id'] ?? null];
        foreach ($def['fields'] as $f) {
            if (in_array($f['key'], $want, true) && array_key_exists($f['col'], $arr)) {
                $out[$f['key']] = $arr[$f['col']];
            }
        }

        return $out;
    }

    /** نطاق المفتاح لمسار لا يمرّ بـ resolveApi (التقارير والفهرس) — مفتاح بلا نطاق يمرّ */
    protected function tokenAllows(string $module, string $op = 'v'): bool
    {
        $t = request()->attributes->get('api_token');

        return ! $t || $t->allows($module, $op);
    }
}
