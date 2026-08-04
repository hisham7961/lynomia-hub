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
        $onlineSince = now()->subMinutes(5);
        $users = User::whereNull('deleted_at')->orderBy('name')->get()->map(function ($u) use ($today, $onlineSince) {
            $v = DB::table('page_visits')->where('user_id', $u->id)->where('at', '>=', $today);
            $sess = DB::table('sessions_log')->where('user_id', $u->id);

            // **آخر ظهورٍ موثوق من نبضة الجلسة** (`sessions_log.last_seen_at` تُحدَّث مع
            // كل طلبٍ عبر SessionSentry) لا من `page_visits` وحدها — التي تُسجَّل
            // للصفحات الكاملة فقط، فمستخدمٌ يعمل في الملفات والتفاصيل (htmx/تنزيل)
            // كان يظهر فارغاً تماماً رغم نشاطه. النبضة تلتقط كلَّ طلبٍ فيبين حضورُه.
            $lastSeen = (clone $sess)->max('last_seen_at');

            return (object) [
                'u'       => $u,
                'first'   => (clone $v)->min('at'),
                'last'    => $lastSeen ?: (clone $v)->max('at'),
                'visits'  => (clone $v)->count(),
                'actions' => DB::table('audits')->where('user_id', $u->id)
                                 ->where('created_at', '>=', $today)->count(),
                'online'  => (clone $sess)->where('last_seen_at', '>=', $onlineSince)->exists(),
            ];
        })->sortByDesc(fn ($r) => (string) $r->last)->values();

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

        return view('activity.show', compact('u', 'days', 'topPages', 'devices', 'ips', 'trail', 'suspects')
            + ['risk' => $this->riskProfile($u->id, $visits)]);
    }

    /**
     * ملف الشك — أرقامٌ مشتقة من نشاط النظام نفسه (١٤ يوماً) بلا أي مراقبة خارجية:
     *  - ساعات النشاط مصنفةً: دوام (٠٨–١٦)، خارج الدوام، مريبة (منتصف الليل–٠٦).
     *  - معدل التلاعب: الدخول المريب + المحاولات الفاشلة نسبةً إلى الدخول الكلي.
     *  - تعدد الأجهزة وعناوين الشبكة، ونسبة شكٍّ مركّبة معلنة المكوّنات.
     */
    protected function riskProfile(string $uid, $visits): array
    {
        $since = now()->subDays(14);

        // تصنيف سلال النشاط (كل سلة ٥ دقائق) حسب ساعتها المحلية
        $seen = []; $inMin = $outMin = $nightMin = 0;
        foreach ($visits as $v) {
            $ts = strtotime((string) $v->at);
            $bucket = intdiv($ts, 300);
            if (isset($seen[$bucket])) continue;
            $seen[$bucket] = true;
            $h = (int) date('G', $ts);
            if ($h < 6)                 $nightMin += 5;
            elseif ($h >= 8 && $h < 16) $inMin += 5;
            else                        $outMin += 5;
        }
        $totalMin = $inMin + $outMin + $nightMin;

        $logins   = DB::table('sessions_log')->where('user_id', $uid)->where('started_at', '>=', $since)->count();
        $strange  = DB::table('audits')->where('user_id', $uid)->where('action', 'دخول مريب')
                        ->where('created_at', '>=', $since)->count();
        $failed   = DB::table('audits')->where('user_id', $uid)->where('action', 'دخول فاشل')
                        ->where('created_at', '>=', $since)->count();
        $devCount = (int) DB::table('sessions_log')->where('user_id', $uid)->where('started_at', '>=', $since)
                        ->distinct()->count('device');
        $ipCount  = (int) DB::table('user_ips')->where('user_id', $uid)->count();

        // معدل التلاعب: إشارات الدخول الشاذة منسوبةً لكل الدخول
        $tamper = $logins + $failed > 0
            ? (int) round(($strange + $failed) * 100 / ($logins + $failed)) : 0;
        // معدل تعدد الأجهزة: دخولٌ من كم جهازاً مختلفاً وسطياً
        $devRate = $logins > 0 ? round($devCount * 100 / $logins) : 0;

        // نسبة الشك المركبة (٠–١٠٠) — مكوناتها معلنة في الواجهة كي تُفهم لا تُخشى
        $parts = [
            'دخول شاذ (مكان غريب/خارج الدوام)' => $logins > 0 ? min(40, (int) round($strange * 40 / $logins)) : 0,
            'محاولات دخول فاشلة'               => min(15, $failed * 3),
            'نشاط في ساعات مريبة (٠٠–٠٦)'      => $totalMin > 0 ? min(25, (int) round($nightMin * 25 / $totalMin)) : 0,
            'نشاط خارج الدوام'                  => $totalMin > 0 ? min(10, (int) round($outMin * 10 / $totalMin)) : 0,
            'تعدد الأجهزة'                      => min(10, max(0, $devCount - 1) * 3),
        ];
        $score = min(100, array_sum($parts));

        return [
            'in_h'    => round($inMin / 60, 1),  'out_h' => round($outMin / 60, 1),
            'night_h' => round($nightMin / 60, 1),
            'logins'  => $logins, 'strange' => $strange, 'failed' => $failed,
            'devices' => $devCount, 'ips' => $ipCount,
            'tamper'  => $tamper, 'dev_rate' => $devRate,
            'score'   => $score, 'parts' => $parts,
            'tone'    => $score >= 55 ? 'bad' : ($score >= 25 ? 'wn' : 'ok'),
        ];
    }
}
