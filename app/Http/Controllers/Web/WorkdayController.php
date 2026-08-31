<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Workday;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * يومُ العمل: حضورٌ وانصرافٌ بضغطة (للموظف عن نفسه حصراً — كبوابته الذاتية،
 * لا يحتاج صلاحيةَ وحدة الحضور)، وشاشةُ «فريقي اليوم» للمدير.
 */
class WorkdayController extends Controller
{
    public function checkIn(Request $r)
    {
        $d = $r->validate([
            'mode' => ['nullable', 'string', Rule::in(['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'])],
            'client_id' => ['nullable', 'string', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'geo' => 'nullable|string|max:80',
        ], [], ['mode' => 'وضع العمل', 'client_id' => 'العميل', 'project_id' => 'المشروع']);

        $res = Workday::checkIn($r->user(), $d);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['msg']);
    }

    public function checkOut(Request $r)
    {
        $res = Workday::checkOut($r->user());

        return back()->with($res['ok'] ? 'ok' : 'err', $res['msg']);
    }

    /** فريقي اليوم — مراجعةُ المدير المهيكلة: من حضر، من كتب، من تعثّر */
    public function team()
    {
        abort_unless(hub_can(auth()->user(), 'hr', 'v'), 403,
            'شاشة الفريق اليومية تتطلب صلاحية عرض الموارد البشرية');

        return view('workforce.team', Workday::teamToday());
    }
}
