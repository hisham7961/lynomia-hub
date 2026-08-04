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
        // ترتيبٌ حاسم: ربطٌ مزدوج (موظفان بنفس user_id، يُتيحه النموذج العام)
        // كان first() بلا orderBy يعرض بيانات زميلٍ بالقرعة بين المحرّكين
        $emp = Employee::where('user_id', auth()->id())->whereNull('deleted_at')
            ->orderBy('id')->first();
        $inbox = \App\Support\Inbox::items(auth()->user());

        return view('portal.me', ['emp' => $emp, 'self' => true,
            'inbox' => $inbox, 'buckets' => \App\Support\Inbox::summary($inbox),
        ] + $this->bundle($emp, auth()->id()));
    }

    /** الملف الشامل لموظف — لمن يملك عرض وحدة HR */
    public function employee(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'hr', 'v'), 403, 'عرض ملفات الموظفين يتطلب صلاحية الموارد البشرية');
        // النطاق يسري كما في كل قارئ: ملفٌّ خارج شركتي أو مشاريعي = ٤٠٤ لا ٢٠٠
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);

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

        // كل قسمٍ خلف وحدته: شاشةٌ مقاسةٌ بصلاحية HR كانت تعرض الإجازات والحضور
        // والسجل التأديبي والعهدة لمن لا يملك أيّاً من وحداتها
        $u = auth()->user();
        $out['may'] = [
            'leaves' => hub_can($u, 'leaves', 'v'), 'attend' => hub_can($u, 'attend', 'v'),
            'hrlog' => hub_can($u, 'hrlog', 'v'), 'assets' => hub_can($u, 'assets', 'v'),
            'tasks' => hub_can($u, 'tasks', 'v'), 'kb' => hub_can($u, 'kb', 'v'),
        ];

        if ($userId) {
            // «مفتوحة» من التعريف الموحَّد — مصدرها hub_closed_states لا مفردات محلية
            $open = fn ($q) => hub_open_scope($q);

            if ($out['may']['tasks']) {
                $mine = fn () => $open(hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
                    ->where('assignee_id', $userId));
                $out['tasks'] = $mine()->orderByRaw('due IS NULL, due')
                    ->limit(10)->get(['id', 'title', 'status', 'due', 'progress', 'priority', 'project_id']);
                $out['openTasks'] = $mine()->count();
            }

            if ($out['may']['assets']) $out['assets'] = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
                ->where('holder_id', $userId)->orderBy('name')
                ->limit(12)->get(['id', 'name', 'type', 'tag', 'status']);

            /* ── الصندوق الموحد: كل ما ينتظر تصرفي ── */

            // موافقات بانتظاري: أنا المعتمد أو ضمن سلسلة الاعتماد، ولم تُحسم
            $out['approvals'] = ! hub_can($u, 'approvals', 'v') ? collect()
                : hub_scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')
                ->where(fn ($w) => $w->where('approver_id', $userId)->orWhere('chain', 'LIKE', '%"' . $userId . '"%'))
                ->tap(fn ($q) => hub_open_scope($q, 'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة']))
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'type', 'amount', 'currency', 'due', 'status']);

            // تذاكري المفتوحة
            $out['tickets'] = ! hub_can($u, 'tickets', 'v') ? collect()
                : hub_scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                ->where('assignee_id', $userId)
                ->tap(fn ($q) => hub_open_scope($q))
                ->orderByDesc('created_at')->limit(8)
                ->get(['id', 'subject', 'customer', 'priority', 'status']);

            // اجتماعاتي القادمة: أنا من المشاركين
            $out['meetings'] = ! hub_can($u, 'meetings', 'v') ? collect()
                : hub_scope(DB::table('meetings')->whereNull('deleted_at'), 'meetings')
                ->where('parts', 'LIKE', '%"' . $userId . '"%')
                ->where('dt', '>=', now()->startOfDay())
                ->orderBy('dt')->limit(6)
                ->get(['id', 'title', 'dt', 'link']);

            // قرارات أنفّذها ولم تُنجز
            $out['decisions'] = ! hub_can($u, 'decisions', 'v') ? collect()
                : hub_scope(DB::table('decisions')->whereNull('deleted_at'), 'decisions')
                ->where('exec_id', $userId)
                ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر'])
                ->orderByRaw('due IS NULL, due')->limit(8)
                ->get(['id', 'title', 'due', 'status']);
        }

        if ($emp && $out['may']['leaves']) {
            $out['leaves'] = DB::table('leave_requests')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date_from')
                ->limit(6)->get(['id', 'type', 'date_from', 'date_to', 'days', 'status']);

        }

        if ($emp && $out['may']['attend']) {
            $out['attend'] = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(7)->get(['id', 'date', 'time_in', 'time_out', 'hours', 'status']);

            $m = DB::table('attendance')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
                ->selectRaw('COUNT(*) as d, COALESCE(SUM(hours),0) as h')->first();
            $out['attMonth'] = ['days' => (int) ($m->d ?? 0), 'hours' => (float) ($m->h ?? 0)];

        }

        if ($emp && $out['may']['hrlog']) {
            $out['log'] = DB::table('employee_records')->whereNull('deleted_at')
                ->where('emp_id', $emp->id)->orderByDesc('date')
                ->limit(8)->get(['id', 'kind', 'title', 'date', 'expiry', 'status']);
        }

        // مقالاتٌ «يجب قراءتها» — خلف وحدة المعرفة ونطاقها
        try {
            if ($out['may']['kb']) {
                $out['mustRead'] = hub_scope(DB::table('kb_articles')->whereNull('deleted_at'), 'kb')
                    ->where('must_read', 1)->orderByDesc('created_at')
                    ->limit(5)->get(['id', 'title', 'cat']);
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
