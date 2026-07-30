<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiDef;
use Illuminate\Http\Request;

/**
 * باني معادلات KPI: مؤشرات مخصصة بمعادلة **مُهيكَلة آمنة** — بدائل مُحدَّدة
 * (عدد/مجموع/متوسط) فوق وحدة بفلتر حالة، وعملية بين مقياسين. لا نص حر يُقيَّم.
 */
class KpiController extends Controller
{
    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner || hub_flag(auth()->user(), 'monitor'),
            403, 'باني المؤشرات للمالكين ومن يحمل صلاحية المتابعة');
    }

    /** الوحدات المتاحة وأعمدتها الرقمية لبناء المقاييس */
    protected function catalog(): array
    {
        $mods = [];
        foreach (hub_modules() as $mk => $md) {
            if (! hub_can(auth()->user(), $mk, 'v')) continue;
            $nums = collect($md['fields'])->whereIn('type', ['num', 'big'])
                ->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']])->values()->all();
            $status = collect($md['fields'])->firstWhere('key', $md['status'] ?? '');
            $mods[$mk] = [
                'label'  => $md['label'],
                'nums'   => $nums,
                'states' => $status['options'] ?? [],
            ];
        }

        return $mods;
    }

    public function index()
    {
        $this->gate();

        return view('kpis.index', [
            'kpis'    => hub_kpis(),
            'catalog' => $this->catalog(),
        ]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'name'     => ['required', 'string', 'max:190'],
            'unit'     => ['nullable', 'string', 'max:30'],
            'target'   => ['nullable', 'numeric'],
            'good'     => ['required', 'in:up,down'],
            'a_agg'    => ['required', 'in:count,sum,avg'],
            'a_module' => ['required', 'string', 'max:60'],
            'a_col'    => ['nullable', 'string', 'max:60'],
            'a_st'     => ['nullable', 'string', 'max:120'],
            'combine'  => ['required', 'in:none,ratio_pct,ratio,diff,sum'],
            'b_agg'    => ['nullable', 'in:count,sum,avg'],
            'b_module' => ['nullable', 'string', 'max:60'],
            'b_col'    => ['nullable', 'string', 'max:60'],
            'b_st'     => ['nullable', 'string', 'max:120'],
        ]);

        // الوحدات لا بد أن تكون مسجَّلة ومرئية — نفس حارس المقياس
        abort_unless(hub_mod($d['a_module']) && hub_can(auth()->user(), $d['a_module'], 'v'), 422);
        $metricA = ['agg' => $d['a_agg'], 'module' => $d['a_module'], 'col' => $d['a_col'] ?? null, 'st' => $d['a_st'] ?? ''];

        $formula = ['a' => $metricA, 'combine' => $d['combine']];
        if ($d['combine'] !== 'none') {
            abort_unless($d['b_module'] && hub_mod($d['b_module']) && hub_can(auth()->user(), $d['b_module'], 'v'), 422, 'اختر وحدة المقياس الثاني');
            $formula['b'] = ['agg' => $d['b_agg'] ?: 'count', 'module' => $d['b_module'],
                             'col' => $d['b_col'] ?? null, 'st' => $d['b_st'] ?? ''];
        }

        KpiDef::create([
            'name' => $d['name'], 'unit' => $d['unit'] ?? null,
            'target' => $d['target'] ?? null, 'good' => $d['good'],
            'formula' => $formula, 'sort' => (int) KpiDef::max('sort') + 1,
            'created_by' => auth()->id(),
        ]);

        return back()->with('ok', '📈 أُضيف المؤشر — قيمته محسوبة الآن من بياناتك');
    }

    public function destroy(string $id)
    {
        $this->gate();
        KpiDef::findOrFail($id)->delete();

        return back()->with('ok', 'حُذف المؤشر');
    }
}
