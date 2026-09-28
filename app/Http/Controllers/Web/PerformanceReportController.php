<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EmployeePerformanceReport;
use App\Models\User;
use App\Support\Ai\Reports\EmployeePerformance;
use App\Support\Ai\Reports\PerformanceAccess;
use App\Support\Ai\Reports\PerformancePeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * **«تقارير الأداء (ذكاء اصطناعي)»** — قائمةُ من يرى القارئُ تقاريرَهم، وصفحةُ الموظّف بفتراتها وتاريخها،
 * و«تحديث». والتبويبُ نفسُه في الملفّ الشامل (`portal.employee?tab=performance`) لمن يملك `hr:v`؛ وهذه
 * الصفحةُ بابُ المدير المباشر أيضاً. الحرّاس كلُّها من `PerformanceAccess` (العميلُ ٤٠٤، والموظّفُ خارج
 * النطاق أو الموظّفُ نفسُه ٤٠٤، ومن لا يملك `hr:v` ولا يدير أحداً ٤٠٣).
 */
class PerformanceReportController extends Controller
{
    /** أقصى موظّفين في القائمة */
    public const LIST_LIMIT = 300;

    protected function guard(): User
    {
        /** @var User $u */
        $u = auth()->user();
        abort_if(hub_is_client($u), 404);
        abort_unless(PerformanceAccess::canUseAny($u), 403, 'تقاريرُ الأداء للموارد البشريّة وللمدير المباشر');

        return $u;
    }

    public function index(Request $r)
    {
        $u = $this->guard();
        $q = trim((string) $r->query('q', ''));

        $emps = PerformanceAccess::employees($u)->whereNotNull('user_id')
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', EmployeePerformance::ENDED))
            ->when($q !== '', fn ($w) => $w->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')->orderBy('id')->limit(self::LIST_LIMIT)->get(['id', 'name', 'title', 'dept', 'manager_id']);

        // أحدثُ فترةٍ لكلِّ موظّف — ترتيبٌ صريحٌ ثمّ أوّلُ صفٍّ لكلِّ مفتاح
        $latest = EmployeePerformanceReport::query()->whereIn('employee_id', $emps->pluck('id')->all())
            ->orderByDesc('period_from')->orderByDesc('period')->orderBy('id')
            ->get(['id', 'employee_id', 'period', 'status', 'error_code', 'generated_at', 'facts'])
            ->unique('employee_id')->keyBy('employee_id');

        return view('reports.performance_index', [
            'emps' => $emps, 'latest' => $latest, 'q' => $q, 'me' => (string) $u->id,
            'masked' => PerformanceAccess::masked($u), 'why' => EmployeePerformance::whyNot(),
        ]);
    }

    public function show(Request $r, string $id)
    {
        $u = $this->guard();
        $emp = PerformanceAccess::employee($u, $id);
        abort_if($emp === null, 404);

        $pf = PerformanceAccess::panel($u, $emp, (string) $r->query('period', ''));

        return view('reports.performance', ['emp' => $emp, 'pf' => $pf, 'managerView' => PerformanceAccess::isManagerOf($u, $emp)] + $pf);
    }

    /** «تحديث» — مخنوقٌ بالمسار، ومحدودٌ لكلِّ موظّف (نقرةٌ في الدقيقة مهما تعدّد النقّارون)، ومدقَّق */
    public function refresh(Request $r, string $id)
    {
        $u = $this->guard();
        $emp = PerformanceAccess::employee($u, $id);
        abort_if($emp === null, 404);
        abort_unless(PerformanceAccess::canRefresh($u, $emp), 403, 'التحديثُ للمالك ولمن يعدّل الموارد البشريّة');

        $period = trim((string) $r->input('period', ''));
        if ($period !== '' && PerformancePeriod::parse($period) === null) $period = '';
        $back = redirect()->route('reports.performance.show', array_filter(['id' => $emp->id, 'period' => $period]));

        if (! EmployeePerformance::ready()) return $back->with('err', (string) EmployeePerformance::whyNot());
        if (! Cache::add('employee-performance-refresh:' . $emp->id, 1, 60)) {
            return $back->with('err', 'حُدِّث هذا التقريرُ قبل لحظات — أعد المحاولة بعد دقيقة');
        }

        $res = EmployeePerformance::refresh($u, $emp, $period === '' ? null : $period);

        return $back->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }
}
