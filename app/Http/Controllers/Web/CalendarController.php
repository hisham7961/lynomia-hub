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

        // مخبّأ لكل (شهر × نطاق المستخدم): يمنع إعادة عشرات الاستعلامات عند كل تنقّل
        // شهري — على غرار رادار الانتهاءات. المقيَّد (مشاريع/شركات) بمفتاح خاص به.
        // **والمحتوى مُرشَّحٌ بالصلاحيات أيضاً**: مفتاحٌ باسم «all» لكل غير
        // المُنطَّقين كان يُقدّم نتيجة أوسعِهم صلاحيةً لأضيقهم — فحسابٌ يرى وحدةً
        // واحدة يرث ما رآه من فتح الشهر قبله. المفتاح يحمل الدور (ومنه تُشتقّ
        // المصفوفة)، والمُنطَّق يحمل هويته.
        $scoped = hub_scoped(auth()->user()) || hub_company_ids() !== null;
        $ckey = 'hub:calendar:' . $start->format('Y-m') . ':'
              . ($scoped ? 'u:' . auth()->id() : 'r:' . (auth()->user()?->role_id ?? '0'))
              . ':c:' . (string) session('hub.company', '');
        [$days, $overflow] = \Illuminate\Support\Facades\Cache::remember($ckey, $scoped ? 300 : 600, function () use ($start, $end) {
            $days = [];
            $overflow = 0;
            foreach ($this->fields() as [$mk, $f]) {
                if (! hub_can(auth()->user(), $mk, 'v')) continue;
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                try {
                    $base = hub_scope(
                        DB::table($md['table'])->whereNull('deleted_at')->whereNotNull($f['col'])
                            ->whereBetween(DB::raw("DATE(`{$f['col']}`)"), [$start->toDateString(), $end->toDateString()]),
                        $mk
                    );
                    // نعدّ الكل ثم نجلب الأقرب زمنياً بترتيب صريح — الحدّ لم يعد يُسقط
                    // سجلات عشوائية صمتاً، والمحجوب يُصرَّح به في العدّاد.
                    $total = (clone $base)->count();
                    $rows = (clone $base)->orderBy(DB::raw("DATE(`{$f['col']}`)"))
                        ->limit(80)->get(['id', $disp . ' as _n', DB::raw("DATE(`{$f['col']}`) as _d")]);
                    $overflow += max(0, $total - $rows->count());
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

            return [$days, $overflow];
        });

        return view('calendar', [
            'start' => $start, 'days' => $days, 'overflow' => $overflow,
            'prev' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
        ]);
    }
}
