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
        // hub_str: `?m[]=` يصل preg_match مصفوفةً فيرمي TypeError — ٥٠٠ (v2.325)
        $month = hub_str($r->input('m'));
        $start = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay()
            : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // مخبّأ لكل (شهر × مستخدم): يمنع إعادة عشرات الاستعلامات عند كل تنقّل
        // شهري — على غرار رادار الانتهاءات.
        // **والمحتوى مُرشَّحٌ بالصلاحيات أيضاً**: مفتاحٌ باسم «all» لكل غير
        // المُنطَّقين كان يُقدّم نتيجة أوسعِهم صلاحيةً لأضيقهم — فحسابٌ يرى وحدةً
        // واحدة يرث ما رآه من فتح الشهر قبله. ثمّ تبيّن أنّ المفتاحَ بالدورِ
        // لا يكفي كذلك (انظر أدناه)، فصار بالمستخدمِ وحدَه.
        /*
         * **المفتاحُ بالمستخدمِ دائماً — لا بالدورِ حين يُظَنُّ غيرَ مقيَّد** (v2.556).
         *
         * كان يُحسَب `$scoped` بآليّتَي تضييقٍ (مشروعٌ · شركة) فيُخبَّأ لغيرِهما
         * بمفتاحِ الدورِ `r:{role_id}`. و`hub_scope` — وهي تُستدعى هنا **دائماً**
         * — تُنطّق بالمستخدمِ لا بالدورِ وحدَه: قاعدةُ سرّيّةِ الوثائق
         * (`helpers.php:278`) تنتهي بـ`orWhere('created_by', $user->id)`، أي أنّ
         * **رافعَ الوثيقةِ السرّيّةِ يراها وزميلُه بالدورِ نفسِه لا يراها**.
         *
         * فنتيجةُ الرافعِ كانت تُخبَّأ تحت مفتاحِ الدورِ ثمّ يقرؤها الزميل —
         * والتقويمُ يقرأ `files.issue_date` و`files.expiry` فعلاً. تنطيقٌ سليمٌ
         * يُبطله مفتاحٌ أعمُّ منه. (توأمُ عيبِ رادارِ الانتهاءات L5-01.)
         *
         * والقاعدةُ: **ما نُطِّق بالمستخدمِ يُخبَّأ بالمستخدم.**
         */
        // **الختم يسبق المهلة**: المفتاح يحمل ختم الجداول المؤرَّخة + roles، ويدعم
        // ?fresh — فاجتماعٌ يُضاف أو صلاحيةٌ تُسحب تظهر فوراً لا بعد انقضاء المهلة.
        $tables = array_values(array_unique(array_filter(array_map(
            fn ($x) => (string) (hub_mod($x[0])['table'] ?? ''), $this->fields()))));
        $tables[] = 'roles';
        $ckey = 'hub:calendar:' . $start->format('Y-m') . ':'
              . 'u:' . (auth()->id() ?? 'sys')
              . ':c:' . (string) session('hub.company', '') . hub_data_stamp($tables);
        if (request()->boolean('fresh')) \Illuminate\Support\Facades\Cache::forget($ckey);
        [$days, $overflow] = \Illuminate\Support\Facades\Cache::remember($ckey, 300, function () use ($start, $end) {
            $days = [];
            $overflow = 0;
            foreach ($this->fields() as [$mk, $f]) {
                if (! hub_can(auth()->user(), $mk, 'v')) continue;
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                try {
                    $base = hub_scope(
                        // نطاقٌ على العمود الخام لا DATE(col): الدالة على العمود
                        // تُعمي الفهرس فيمسح المحرّك الجدول كله لكل وحدة شهرياً
                        DB::table($md['table'])->whereNull('deleted_at')->whereNotNull($f['col'])
                            ->where($f['col'], '>=', $start->toDateString())
                            ->where($f['col'], '<', $end->copy()->addDay()->toDateString()),
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
