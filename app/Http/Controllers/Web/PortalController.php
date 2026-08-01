<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * بوابة الموظف: «بوابتي» للمستخدم الحالي، و«الملف الشامل» لمن يملك عرض HR.
 * تجميع ٣٦٠°: الملف الوظيفي + المهام + الإجازات + الحضور + السجل + العهدة.
 */
class PortalController extends Controller
{
    /** بوابتي — بيانات المستخدم الحالي نفسه (لا تتطلب صلاحية HR) */
    public function me()
    {
        $emp = Employee::where('user_id', auth()->id())->whereNull('deleted_at')->first();
        $inbox = \App\Support\Inbox::items(auth()->user());

        return view('portal.me', ['emp' => $emp, 'self' => true,
            'inbox' => $inbox, 'buckets' => \App\Support\Inbox::summary($inbox),
        ] + $this->bundle($emp, auth()->id()));
    }

    /** الملف الشامل لموظف — لمن يملك عرض وحدة HR */
    public function employee(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'hr', 'v'), 403, 'عرض ملفات الموظفين يتطلب صلاحية الموارد البشرية');
        $emp = Employee::whereNull('deleted_at')->findOrFail($id);

        return view('portal.employee', ['emp' => $emp, 'self' => false] + $this->bundle($emp, $emp->user_id));
    }

    /* ────────── تجميع البيانات ────────── */

    /** كل ما يخص الموظف: مهامه (عبر حسابه)، إجازاته، حضوره، سجله، عهدته */
    protected function bundle(?Employee $emp, ?string $userId): array
    {
        $out = [
            'tasks' => collect(), 'openTasks' => 0,
            'leaves' => collect(), 'attend' => collect(),
            'attMonth' => ['days' => 0, 'hours' => 0.0],
            'log' => collect(), 'assets' => collect(),
            'mustRead' => collect(),
            'approvals' => collect(), 'tickets' => collect(),
            'meetings' => collect(), 'decisions' => collect(),
        ];

        if ($userId) {
            // «مفتوحة» من التعريف الموحَّد — مصدرها hub_closed_states لا مفردات محلية
            $open = fn ($q) => hub_open_scope($q);

            $out['tasks'] = $open(DB::table('tasks')->whereNull('deleted_at')->where('assignee_id', $userId))
                ->orderByRaw('due IS NULL, due')
                ->limit(10)->get(['id', 'title', 'status', 'due', 'progress', 'priority', 'project_id']);
            $out['openTasks'] = $open(DB::table('tasks')->whereNull('deleted_at')->where('assignee_id', $userId))->count();

            $out['assets'] = DB::table('assets')->whereNull('deleted_at')
                ->where('holder_id', $userId)->orderBy('name')
                ->limit(12)->get(['id', 'name', 'type', 'tag', 'status']);

            /* ── الصندوق الموحد: كل ما ينتظر تصرفي ── */

            // موافقات بانتظاري: أنا المعتمد أو ضمن سلسلة الاعتماد، ولم تُحسم
            $out['approvals'] = DB::table('approvals')->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('approver_id', $userId)->orWhere('chain', 'LIKE', '%"' . $userId . '"%'))
                ->tap(fn ($q) => hub_open_scope($q, 'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة']))
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'type', 'amount', 'currency', 'due', 'status']);

            // تذاكري المفتوحة
            $out['tickets'] = DB::table('tickets')->whereNull('deleted_at')
                ->where('assignee_id', $userId)
                ->tap(fn ($q) => hub_open_scope($q))
                ->orderByDesc('created_at')->limit(8)
                ->get(['id', 'subject', 'customer', 'priority', 'status']);

            // اجتماعاتي القادمة: أنا من المشاركين
            $out['meetings'] = DB::table('meetings')->whereNull('deleted_at')
                ->where('parts', 'LIKE', '%"' . $userId . '"%')
                ->where('dt', '>=', now()->startOfDay())
                ->orderBy('dt')->limit(6)
                ->get(['id', 'title', 'dt', 'link']);

            // قرارات أنفّذها ولم تُنجز
            $out['decisions'] = DB::table('decisions')->whereNull('deleted_at')
                ->where('exec_id', $userId)
                ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر'])
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'due', 'status']);
        }

        if ($emp) {
            $out['leaves'] = DB::table('leave_requests')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date_from')
                ->limit(6)->get(['id', 'type', 'date_from', 'date_to', 'days', 'status']);

            $out['attend'] = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(7)->get(['id', 'date', 'time_in', 'time_out', 'hours', 'status']);

            $m = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
                ->selectRaw('COUNT(*) as d, COALESCE(SUM(hours),0) as h')->first();
            $out['attMonth'] = ['days' => (int) ($m->d ?? 0), 'hours' => (float) ($m->h ?? 0)];

            $out['log'] = DB::table('employee_records')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(8)->get(['id', 'kind', 'title', 'date', 'expiry', 'status']);
        }

        // سياسات «يجب قراءتها» من قاعدة المعرفة — للبوابة الشخصية
        try {
            $out['mustRead'] = DB::table('kb_articles')->whereNull('deleted_at')
                ->where('must_read', 1)->orderByDesc('created_at')
                ->limit(5)->get(['id', 'title', 'cat']);
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
