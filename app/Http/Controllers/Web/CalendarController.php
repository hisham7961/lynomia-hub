<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * التقويم الموحّد: شبكة شهرية تجمع كل السجلات المؤرخة من كل الوحدات —
 * مواعيد المهام والاجتماعات والانتهاءات والإطلاقات — كلٌّ بصلاحيات
 * المستخدم ونطاقه (مشاريع + عزل شركات) عبر hub_scope.
 */
class CalendarController extends Controller
{
    /** حقول التواريخ الجديرة بالتقويم: كل حقول date/dt عدا التأريخية البحتة (إنشاء/آخر لمس) */
    protected function fields(): array
    {
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                if (! in_array($f['type'] ?? '', ['date', 'dt'], true)) continue;
                if (preg_match('/آخر|التسجيل|الإنشاء|الاكتشاف|التأسيس|الشراء|الفعلي/u', $f['label'])) continue;
                $out[] = [$mk, $f];
            }
        }

        return $out;
    }

    public function index(Request $r)
    {
        $month = $r->input('m');
        $start = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // نجمع أحداث الشهر يوماً بيوم: [Y-m-d => [ [module, icon, label, name, id], ... ]]
        $days = [];
        $overflow = 0;
        foreach ($this->fields() as [$mk, $f]) {
            if (! hub_can(auth()->user(), $mk, 'v')) continue;
            $md = hub_mod($mk);
            $disp = hub_display_col($mk);
            try {
                $rows = hub_scope(
                    DB::table($md['table'])->whereNull('deleted_at')->whereNotNull($f['col'])
                        ->whereBetween(DB::raw("DATE(`{$f['col']}`)"), [$start->toDateString(), $end->toDateString()]),
                    $mk
                )->limit(80)->get(['id', $disp . ' as _n', DB::raw("DATE(`{$f['col']}`) as _d")]);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($rows as $row) {
                $d = (string) $row->_d;
                if (count($days[$d] ?? []) >= 8) { $overflow++; continue; }   // اليوم المزدحم يُقتصر ويُصرَّح بالباقي
                $days[$d][] = [
                    'module' => $mk,
                    'mlabel' => $md['label'] ?? $mk,
                    'label'  => $f['label'],
                    'name'   => (string) ($row->_n ?? ''),
                    'id'     => (string) $row->id,
                ];
            }
        }

        return view('calendar', [
            'start' => $start, 'days' => $days, 'overflow' => $overflow,
            'prev' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
        ]);
    }
}
