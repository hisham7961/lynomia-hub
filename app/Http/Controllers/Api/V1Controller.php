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
            $out[] = [
                'key' => $key, 'label' => $def['label'], 'table' => $def['table'],
                'can' => collect(['v', 'a', 'e', 'd'])->filter(fn ($op) => hub_can(auth()->user(), $key, $op))->values(),
                'fields' => collect($def['fields'])->map(fn ($f) => [
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
            'data' => collect($page->items())->map(fn ($row) => $this->pick($def, $row, $fields)),
            'total' => $page->total(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    /** GET /api/v1/{module}/{id}?fields= */
    public function apiShow(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'v');
        $row = hub_scope($class::query(), $module)->findOrFail($id);

        return response()->json(['data' => $this->pick($def, $row, $r->query('fields'))]);
    }

    /** POST /api/v1/{module} — نفس تحقق النماذج، مع Idempotency-Key اختيارية */
    public function apiStore(Request $r, string $module)
    {
        [$def, $class] = $this->resolveApi($module, 'a');

        // نفس المفتاح + نفس Idempotency-Key = نفس الرد المخزن، بلا إنشاء ثانٍ
        if ($replay = $this->idempotent($r)) return $replay;

        $r->validate($this->rules($def, true), [], $this->attrs($def));
        $this->guardProject($r, $module);

        $m = new $class;
        $this->fill($def, $r, $m);
        $m->save();
        $this->notifyAssignee($def, $module, $m);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('created', $module, $m);

        $resp = response()->json(['data' => $m->fresh()], 201);
        $this->idempotentStore($r, $resp);

        return $resp;
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

        return response()->json(['data' => $m->fresh()]);
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
        hub_scope(\App\Models\Project::query(), 'projects')->findOrFail($projectId);

        return response()->json(hub_progress($projectId));
    }

    /** GET /api/v1/reports/health — للمالكين */
    public function health()
    {
        abort_unless(auth()->user()->role?->is_owner, 403);

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

    /** إن حمل الطلب Idempotency-Key سبق تنفيذه: أعد الرد المخزن نفسه */
    protected function idempotent(Request $r)
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return null;

        $row = \Illuminate\Support\Facades\DB::table('idempotency_keys')
            ->where('token_id', $tokenId)->where('ikey', $ikey)->first();
        if (! $row) return null;

        return response($row->response, $row->code)
            ->header('Content-Type', 'application/json')
            ->header('X-Idempotent-Replay', 'true');
    }

    /** تخزين رد ناجح تحت مفتاح Idempotency (مع تنظيف ما جاوز يومين) */
    protected function idempotentStore(Request $r, $resp): void
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return;

        try {
            \Illuminate\Support\Facades\DB::table('idempotency_keys')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'token_id' => $tokenId, 'ikey' => $ikey,
                'code' => $resp->getStatusCode(), 'response' => $resp->getContent(),
                'created_at' => now(),
            ]);
            \Illuminate\Support\Facades\DB::table('idempotency_keys')
                ->where('created_at', '<', now()->subDays(2))->delete();
        } catch (\Throwable $e) {
            // سباق إدراجين متزامنين — القيد الفريد يحسمه ولا نكسر الرد
        }
    }

    protected function ikeyOf(Request $r): array
    {
        $ikey = trim((string) $r->header('Idempotency-Key'));
        $token = $r->attributes->get('api_token');
        if ($ikey === '' || mb_strlen($ikey) > 120 || ! $token) return [null, null];

        return [$token->id, $ikey];
    }

    /** اختيار حقول ?fields=key1,key2 + إسقاط المخفي بصلاحيات مستوى الحقل — وid دائماً */
    protected function pick(array $def, $row, ?string $fields)
    {
        $module = (string) ($def['key'] ?? '');
        $visible = hub_visible_fields(auth()->user(), $module, $def);
        $want = preg_split('/[،,\s]+/u', (string) $fields, -1, PREG_SPLIT_NO_EMPTY);

        // لا اختيار ولا حقول مخفية على هذا الدور → السجل كما هو
        if (! $want && count($visible) === count($def['fields'])) return $row;

        $arr = is_array($row) ? $row : $row->toArray();
        $out = ['id' => $arr['id'] ?? null];
        foreach ($visible as $f) {
            if ($want && ! in_array($f['key'], $want, true)) continue;
            if (array_key_exists($f['col'], $arr)) $out[$f['key']] = $arr[$f['col']];
        }
        if (! $want) {
            foreach (['created_at', 'updated_at'] as $ts) if (isset($arr[$ts])) $out[$ts] = $arr[$ts];
        }

        return $out;
    }
}
