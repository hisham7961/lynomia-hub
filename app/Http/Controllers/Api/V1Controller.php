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

        return response()->json([
            'data' => $page->items(), 'total' => $page->total(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    /** GET /api/v1/{module}/{id} */
    public function apiShow(string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'v');

        return response()->json(['data' => hub_scope($class::query(), $module)->findOrFail($id)]);
    }

    /** POST /api/v1/{module} — نفس تحقق النماذج */
    public function apiStore(Request $r, string $module)
    {
        [$def, $class] = $this->resolveApi($module, 'a');
        $r->validate($this->rules($def, true));
        $this->guardProject($r, $module);

        $m = new $class;
        $this->fill($def, $r, $m);
        $m->save();
        $this->notifyAssignee($def, $module, $m);
        $this->bustProgress($module, $m);

        return response()->json(['data' => $m->fresh()], 201);
    }

    /** PUT /api/v1/{module}/{id} */
    public function apiUpdate(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolveApi($module, 'e');
        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            return response()->json(['error' => 'هذه العملية محمية بالموافقات — نفّذها من الواجهة ليُصفّ الطلب'], 409);
        }
        $r->validate($this->rules($def, false));
        $this->guardProject($r, $module);

        $m = $this->findScoped($class, $module, $id);
        $prev = ($af = $this->assigneeField($def)) ? $m->{$af['col']} : null;
        $this->fill($def, $r, $m);
        $m->save();
        $this->notifyAssignee($def, $module, $m, $prev);
        $this->bustProgress($module, $m);

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
        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }
}
