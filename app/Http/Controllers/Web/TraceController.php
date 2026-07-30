<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/**
 * خيط التتبع الكامل: طلب → متطلب → مهمة → تغيير → إصدار → نشر → نتيجة.
 * من أي سجل في السلسلة يُبنى الخيط صعوداً لأصله ونزولاً لعواقبه —
 * أسلاف السجل ونسله هو، لا فروع إخوته.
 */
class TraceController extends Controller
{
    /** العمود الفقري بالترتيب + عمود الربط الصاعد لكل مرحلة */
    protected const SPINE = [
        ['stage' => 'requests', 'up' => null],
        ['stage' => 'feats',    'up' => 'request_id'],
        ['stage' => 'tasks',    'up' => 'feat_id'],
        ['stage' => 'changes',  'up' => 'task_id'],
        ['stage' => 'deploys',  'up' => 'change_id'],
    ];

    /** مراحل جانبية تتعلق بالنشر: الإصدار المنشور والحادث الناتج */
    protected const SIDES = ['code' => 'release_id', 'incidents' => 'incident_id'];

    public function show(string $module, string $id)
    {
        $stages = [...array_column(self::SPINE, 'stage'), ...array_keys(self::SIDES)];
        abort_unless(in_array($module, $stages, true), 404, 'هذه الوحدة خارج خيط التتبع');
        abort_unless(hub_can(auth()->user(), $module, 'v'), 403);

        // السجل البذرة ضمن نطاق المستخدم
        $def = hub_mod($module);
        $class = '\\App\\Models\\' . $def['model'];
        hub_scope($class::query(), $module)->findOrFail($id);

        $S = array_fill_keys($stages, []);
        $S[$module] = [$id];

        // مرحلة جانبية كبذرة: صِلها بعمليات النشر أولاً ثم اسلك العمود الفقري
        $seed = $module;
        if (isset(self::SIDES[$module])) {
            $S['deploys'] = DB::table('deployments')->whereNull('deleted_at')
                ->where(self::SIDES[$module], $id)->pluck('id')->all();
            $seed = 'deploys';
        }

        $idx = array_search($seed, array_column(self::SPINE, 'stage'), true);

        // صعوداً: من البذرة إلى الطلب الأصلي
        for ($i = $idx; $i > 0 && $S[self::SPINE[$i]['stage']]; $i--) {
            $cur = self::SPINE[$i];
            $parents = DB::table(hub_mod($cur['stage'])['table'])->whereNull('deleted_at')
                ->whereIn('id', $S[$cur['stage']])->pluck($cur['up'])->filter()->unique()->values()->all();
            $S[self::SPINE[$i - 1]['stage']] = $parents;
        }

        // نزولاً: من البذرة إلى النشر
        for ($i = $idx; $i < count(self::SPINE) - 1 && $S[self::SPINE[$i]['stage']]; $i++) {
            $next = self::SPINE[$i + 1];
            $S[$next['stage']] = array_values(array_unique(array_merge(
                $S[$next['stage']],
                DB::table(hub_mod($next['stage'])['table'])->whereNull('deleted_at')
                    ->whereIn($next['up'], $S[self::SPINE[$i]['stage']])->pluck('id')->all()
            )));
        }

        // الجانبيات من عمليات النشر (ما لم تكن هي البذرة)
        if ($S['deploys']) {
            $dep = DB::table('deployments')->whereIn('id', $S['deploys'])->get(['release_id', 'incident_id']);
            foreach (self::SIDES as $sk => $col) {
                $S[$sk] = array_values(array_unique(array_merge($S[$sk], $dep->pluck($col)->filter()->all())));
            }
        }

        // تحميل السجلات مرحلةً مرحلة — بصلاحية رؤية كل وحدة ونطاق المستخدم
        $cols = [];
        foreach ($stages as $st) {
            if (! hub_can(auth()->user(), $st, 'v')) { $cols[$st] = null; continue; }   // مرحلة محجوبة عن الدور
            $d = hub_mod($st);
            $c = '\\App\\Models\\' . $d['model'];
            $rows = $S[$st]
                ? hub_scope($c::query(), $st)->whereIn('id', array_slice($S[$st], 0, 50))
                    ->orderBy('created_at')->get()
                : collect();
            $cols[$st] = ['def' => $d, 'rows' => $rows, 'display' => hub_display_col($st)];
        }

        return view('trace', [
            'module' => $module, 'id' => $id, 'cols' => $cols,
            'order'  => ['requests', 'feats', 'tasks', 'changes', 'code', 'deploys', 'incidents'],
            'titles' => ['requests' => '١ · الطلب', 'feats' => '٢ · المتطلب', 'tasks' => '٣ · المهمة',
                         'changes' => '٤ · التغيير', 'code' => '٥ · الإصدار', 'deploys' => '٦ · النشر',
                         'incidents' => '٧ · النتيجة (حوادث)'],
        ]);
    }
}
