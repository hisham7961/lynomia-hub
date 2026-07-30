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
            'role' => $u->role?->name, 'is_owner' => (bool) $u->role?->is_owner,
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
        if ($term = trim((string) $r->query('q'))) $q->search($term);
        if (($st = $r->query('status')) && ($def['status'] ?? null)) $q->where($def['status'], $st);

        $page = $q->orderByDesc('created_at')
            ->paginate(min(100, max(1, (int) $r->query('per', 25))));

        $fields = $r->query('fields');

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

            $m = new $class;
            $this->fill($def, $r, $m);
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

        $m = $this->findScoped($class, $module, $id);
        $prev = ($af = $this->assigneeField($def)) ? $m->{$af['col']} : null;
        $prevStatus = ($sc = $def['status'] ?? null) ? $m->{$sc} : null;
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
        abort_unless(auth()->user()->role?->is_owner, 403);
        abort_unless($this->tokenAllows('reports', 'v'), 403, 'نطاق هذا المفتاح لا يشمل «reports:v»');

        return response()->json(hub_health());
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

        for ($try = 0; $try < 2; $try++) {
            try {
                \Illuminate\Support\Facades\DB::table('idempotency_keys')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'token_id' => $tokenId, 'ikey' => $ikey,
                    'code' => null, 'response' => null,           // يُملآن عند الإتمام
                    'created_at' => now(),
                ]);

                return true;
            } catch (\Illuminate\Database\QueryException $e) {
                $row = \Illuminate\Support\Facades\DB::table('idempotency_keys')
                    ->where('token_id', $tokenId)->where('ikey', $ikey)->first();
                if (! $row) continue;                             // حُذف تحتنا (تنظيف) — أعد المحاولة

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
        $canSec = (bool) ($u?->role?->is_owner || hub_flag($u, 'secrets') || hub_flag($u, 'copySec'));
        $arr = is_array($row) ? $row : $row->toArray();

        foreach ($def['fields'] as $f) {
            $hidden = hub_field_mode($u, $module, $f['key']) === 'hide';
            $secret = ($f['type'] ?? '') === 'sec' && ! $canSec;
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
