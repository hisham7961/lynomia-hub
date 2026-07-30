<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * مركز نشاط الموظفين — للمالك فقط. من نشاط النظام نفسه، بشفافية:
 *  - ساعات العمل الفعلية: أول فتح للحساب، آخر ظهور، دقائق النشاط (سلال ٥ دقائق).
 *  - مسار التنقل داخل النظام: أي صفحة ومتى.
 *  - المؤشرات: أفعال التدقيق، الوحدات الأكثر استعمالاً، الأجهزة، عناوين الشبكة.
 * لا يرى شيئاً خارج النظام — لا شاشة الجهاز ولا متصفحه.
 */
class ActivityController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز النشاط للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        $today = now()->startOfDay();
        $users = User::whereNull('deleted_at')->orderBy('name')->get()->map(function ($u) use ($today) {
            $v = DB::table('page_visits')->where('user_id', $u->id)->where('at', '>=', $today);

            return (object) [
                'u'       => $u,
                'first'   => (clone $v)->min('at'),
                'last'    => (clone $v)->max('at'),
                'visits'  => (clone $v)->count(),
                'actions' => DB::table('audits')->where('user_id', $u->id)
                                 ->where('created_at', '>=', $today)->count(),
            ];
        });

        return view('activity.index', ['rows' => $users]);
    }

    public function show(string $id)
    {
        $this->gate();
        $u = User::findOrFail($id);
        $since = now()->subDays(14)->startOfDay();

        // اليوميات: أول/آخر ظهور، دقائق النشاط (كل سلة ٥ دقائق فيها زيارة = ٥ دقائق عمل)
        $visits = DB::table('page_visits')->where('user_id', $u->id)
            ->where('at', '>=', $since)->orderBy('at')->get();

        $days = [];
        foreach ($visits as $v) {
            $d = substr((string) $v->at, 0, 10);
            $days[$d] ??= ['first' => $v->at, 'last' => $v->at, 'buckets' => [], 'visits' => 0];
            $days[$d]['last'] = $v->at;
            $days[$d]['visits']++;
            $days[$d]['buckets'][intdiv(strtotime($v->at) % 86400, 300)] = true;
        }
        foreach ($days as $d => &$row) {
            $row['minutes'] = count($row['buckets']) * 5;
            $row['actions'] = DB::table('audits')->where('user_id', $u->id)
                ->whereBetween('created_at', [$d . ' 00:00:00', $d . ' 23:59:59'])->count();
            unset($row['buckets']);
        }
        krsort($days);

        // المؤشرات المجمّعة
        $topPages = DB::table('page_visits')->where('user_id', $u->id)->where('at', '>=', $since)
            ->select('path', DB::raw('COUNT(*) c'))->groupBy('path')->orderByDesc('c')->limit(12)->get();
        $devices = DB::table('sessions_log')->where('user_id', $u->id)
            ->orderByDesc('started_at')->limit(8)->get(['device', 'ip', 'started_at as created_at']);
        $ips = DB::table('user_ips')->where('user_id', $u->id)->orderByDesc('hits')->get();
        $trail = DB::table('page_visits')->where('user_id', $u->id)
            ->orderByDesc('at')->limit(120)->get();
        $suspects = DB::table('audits')->where('user_id', $u->id)
            ->where('action', 'دخول مريب')->orderByDesc('created_at')->limit(10)->get();

        return view('activity.show', compact('u', 'days', 'topPages', 'devices', 'ips', 'trail', 'suspects'));
    }
}
