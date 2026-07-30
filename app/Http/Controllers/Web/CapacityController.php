<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** القدرات والاستغلال + خريطة أثر الاعتماديات */
class CapacityController extends Controller
{
    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner || hub_flag(auth()->user(), 'monitor'),
            403, 'هذه اللوحة للمالكين ومن يحمل صلاحية المتابعة');
        // كل لوحات هذا المتحكم تجمع عبر المنشأة كلها بمخبّأ عام — تُمنع عن المعزول
        hub_org_analytics_guard();
    }

    public function index(Request $r)
    {
        $this->gate();

        return view('capacity', [
            'c' => hub_capacity($r->query('from'), $r->query('to')),
        ]);
    }

    /** جودة البرمجيات لكل تطبيق */
    public function quality()
    {
        $this->gate();

        return view('quality_apps', ['apps' => hub_app_quality((bool) request()->query('fresh'))]);
    }

    /** مركز التوصيات: إشارات قابلة للتنفيذ مجموعة من محرّكات النظام */
    public function recommendations()
    {
        $this->gate();

        return view('recommendations', ['r' => hub_recommendations((bool) request()->query('fresh'))]);
    }

    /**
     * خريطة الأثر: تُقلب سجل الاعتماديات رأساً على عقب —
     * بدل «على ماذا يعتمد نظامي» تعرض «ماذا يسقط إن سقط هذا العنصر».
     */
    public function impact()
    {
        $this->gate();
        abort_unless(Schema::hasTable('dependencies'), 404, 'وحدة الاعتماديات غير مثبّتة');

        $deps = DB::table('dependencies')->whereNull('deleted_at')
            ->whereNotIn('status', ['ملغاة', 'مُستبدَلة'])
            ->limit(500)->get();

        $projects = hub_ref_labels('projects', $deps->pluck('project_id')->all());
        $apps     = hub_ref_labels('apps', $deps->pluck('app_id')->all());
        $servers  = hub_ref_labels('servers', $deps->pluck('server_id')->all());
        $sups     = hub_ref_labels('suppliers', $deps->pluck('supplier_id')->all());
        $emps     = hub_ref_labels('hr', $deps->pluck('emp_id')->all());

        $rank = ['حرجة' => 4, 'عالية' => 3, 'متوسطة' => 2, 'منخفضة' => 1];
        $nodes = [];

        foreach ($deps as $d) {
            // العنصر المعتمَد عليه: اسمه الصريح أو اسم المورد/الموظف المرتبط
            $target = trim((string) ($d->dep_name ?: ($sups[$d->supplier_id] ?? '') ?: ($emps[$d->emp_id] ?? '')));
            if ($target === '') continue;
            // المفتاح بالاسم وحده: العنصر واحد ولو صنّفه مُدخِلان تصنيفين مختلفين،
            // ودمجهما هو المقصود — «ماذا يسقط إن سقط KNET» لا «إن سقط KNET كـAPI».
            $k = mb_strtolower(preg_replace('/\s+/u', ' ', $target));

            $dependents = array_values(array_filter([
                $projects[$d->project_id] ?? null,
                $apps[$d->app_id] ?? null,
                $servers[$d->server_id] ?? null,
            ]));

            $nodes[$k] ??= ['name' => $target, 'types' => [],
                            'crit' => 0, 'critLabel' => '', 'dependents' => [], 'impacts' => [],
                            'fallback' => null, 'rto' => null, 'ids' => []];

            $n = &$nodes[$k];
            if ($d->dep_type && ! in_array($d->dep_type, $n['types'], true)) $n['types'][] = $d->dep_type;
            $n['dependents'] = array_values(array_unique(array_merge($n['dependents'], $dependents)));
            if ($d->impact) $n['impacts'][] = $d->impact;
            if ($d->fallback && ! $n['fallback']) $n['fallback'] = $d->fallback;
            if ($d->rto_hours !== null && ($n['rto'] === null || $d->rto_hours < $n['rto'])) $n['rto'] = (int) $d->rto_hours;
            if (($rank[$d->criticality] ?? 0) > $n['crit']) { $n['crit'] = $rank[$d->criticality] ?? 0; $n['critLabel'] = (string) $d->criticality; }
            $n['ids'][] = $d->id;
            unset($n);
        }

        // نقاط الفشل المفردة: حرجة أو يعتمد عليها أكثر من عنصر
        $nodes = collect($nodes)->values()
            ->sortByDesc(fn ($n) => [$n['crit'], count($n['dependents'])])->values();

        return view('impact', [
            'nodes' => $nodes,
            'spof'  => $nodes->filter(fn ($n) => $n['crit'] >= 4 || count($n['dependents']) > 1)->count(),
        ]);
    }
}
