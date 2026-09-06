<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Risk;
use App\Support\TimeRange;
use Illuminate\Http\Request;
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

    /**
     * مصدرُ بياناتٍ مساعدٍ محميّ: صفحةُ مراقبةٍ لا يُسقطها غيابُ جدولٍ واحد
     * (استضافةٌ متأخّرةٌ في الهجرات، أو عمودٌ لم يُضَف بعد). الفشلُ يُبلَّغ ويُعاد
     * الافتراضي — فتُعرَض التفاصيلُ المتاحة لا صفحةُ خطأ. (نمط PortalController وTrackVisits.)
     */
    protected function safe(callable $fn, $default)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return $default;
        }
    }

    public function index()
    {
        $this->gate();

        $today = now()->startOfDay();
        $onlineSince = now()->subMinutes(5);

        // **تجميعٌ مسبقٌ لا استعلامٌ لكل مستخدم** (N+1): ثلاثةُ استعلاماتٍ مُجمَّعةٍ
        // بـ`groupBy('user_id')` تُبنى مرّةً، ثم تُسقَط على المستخدمين — بدلاً من ~٥
        // استعلاماتٍ داخل `map()` لكل مستخدمٍ (~5N على لوحة المالك). الخرجُ مطابقٌ
        // بالضبط (يحرسه اختبارُ لقطة). النمطُ من `SecurityExposure::map`.
        $visitsBy = DB::table('page_visits')->where('at', '>=', $today)
            ->select('user_id', DB::raw('MIN(at) as first'), DB::raw('MAX(at) as vlast'), DB::raw('COUNT(*) as visits'))
            ->groupBy('user_id')->get()->keyBy('user_id');

        // **آخر ظهورٍ موثوق من نبضة الجلسة** (`sessions_log.last_seen_at` تُحدَّث مع
        // كل طلبٍ عبر SessionSentry) لا من `page_visits` وحدها — التي تُسجَّل
        // للصفحات الكاملة فقط، فمستخدمٌ يعمل في الملفات والتفاصيل (htmx/تنزيل)
        // كان يظهر فارغاً تماماً رغم نشاطه. النبضة تلتقط كلَّ طلبٍ فيبين حضورُه.
        // «متّصلٌ الآن» = آخرُ ظهورٍ ضمن آخر ٥ دقائق — مكافئٌ للفحص الأصليّ `exists`.
        $seenBy = DB::table('sessions_log')
            ->select('user_id', DB::raw('MAX(last_seen_at) as last_seen'))
            ->groupBy('user_id')->get()->keyBy('user_id');

        $actionsBy = DB::table('audits')->where('created_at', '>=', $today)
            ->select('user_id', DB::raw('COUNT(*) as c'))
            ->groupBy('user_id')->pluck('c', 'user_id');

        $onlineStr = (string) $onlineSince;
        $users = User::whereNull('deleted_at')->orderBy('name')->get()->map(function ($u) use ($visitsBy, $seenBy, $actionsBy, $onlineStr) {
            $v = $visitsBy[$u->id] ?? null;
            $lastSeen = $seenBy[$u->id]->last_seen ?? null;

            return (object) [
                'u'       => $u,
                'first'   => $v->first ?? null,
                'last'    => $lastSeen ?: ($v->vlast ?? null),
                'visits'  => (int) ($v->visits ?? 0),
                'actions' => (int) ($actionsBy[$u->id] ?? 0),
                'online'  => $lastSeen && (string) $lastSeen >= $onlineStr,
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
        $visits = $this->safe(fn () => DB::table('page_visits')->where('user_id', $u->id)
            ->where('at', '>=', $since)->orderBy('at')->orderBy('id')->get(), collect());

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

        // المؤشرات المجمّعة — كلٌّ محميٌّ على حدة فلا يُسقط غيابُ أحدها بقيّةَ الصفحة
        $topPages = $this->safe(fn () => DB::table('page_visits')->where('user_id', $u->id)->where('at', '>=', $since)
            ->select('path', DB::raw('COUNT(*) c'))->groupBy('path')->orderByDesc('c')->limit(12)->get(), collect());
        $devices = $this->safe(fn () => DB::table('sessions_log')->where('user_id', $u->id)
            ->orderByDesc('started_at')->limit(8)->get(['device', 'ip', 'started_at as created_at']), collect());
        $ips = $this->safe(fn () => DB::table('user_ips')->where('user_id', $u->id)->orderByDesc('hits')->get(), collect());
        $trail = $this->safe(fn () => DB::table('page_visits')->where('user_id', $u->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(120)->get(), collect());
        $suspects = $this->safe(fn () => DB::table('audits')->where('user_id', $u->id)
            ->where('action', 'دخول مريب')->orderByDesc('created_at')->limit(10)->get(), collect());

        // فصلُ الأمن عن الإنتاجية (WP-7.1 — spec §46): الدرجةُ الأمنية من
        // Risk::activity وحده، وساعاتُ العمل من القارئ العمليّ المصغّر أدناه —
        // مصفوفتان منفصلتان فلا يتسرّب رقمٌ أمنيٌّ إلى بطاقة عمل ولا العكس.
        $range = TimeRange::fromRequest(new Request([
            'from' => $since->format('Y-m-d H:i:s'), 'to' => now()->format('Y-m-d H:i:s'),
        ]));

        return view('activity.show', compact('u', 'days', 'topPages', 'devices', 'ips', 'trail', 'suspects')
            + ['risk' => Risk::activity($u, $range, $visits), 'work' => $this->workHours($visits)]);
    }

    /**
     * الجانبُ العمليّ من النشاط (WP-7.1): ساعاتُ الاستخدام الفعلية مصنّفةً بحدود
     * الدوام من الإعدادات `sec.hours_start/end` — لا من ثابتٍ في الشيفرة.
     * قارئٌ مصغَّرٌ عمداً: قارئا المنظمة والشخص يأتيان في WP-7.2/7.3
     * (`ExecutionStats::org/person` — قارئُ تنفيذٍ واحدٌ لا عدّادَ ثانٍ).
     * ولا يقرأ هذا الجانبُ شيئاً من الدرجة الأمنية — تلك في `Risk::activity`
     * وحدها (فصلُ الأمن عن الإنتاجية — spec §46، يحرسه SecurityActivitySplitTest).
     */
    protected function workHours($visits): array
    {
        $start = (string) setting('sec.hours_start', '08:00');
        $end   = (string) setting('sec.hours_end', '16:00');
        $seen = []; $in = $out = $night = 0;
        foreach ($visits as $v) {
            $ts = strtotime((string) $v->at);
            $bucket = intdiv($ts, 300);
            if (isset($seen[$bucket])) continue;
            $seen[$bucket] = true;
            $t = date('H:i', $ts);
            if ((int) date('G', $ts) < 6)      $night += 5;
            elseif ($t >= $start && $t < $end) $in += 5;
            else                               $out += 5;
        }

        return [
            'in_h' => round($in / 60, 1), 'out_h' => round($out / 60, 1),
            'night_h' => round($night / 60, 1),
            'start' => $start, 'end' => $end,
        ];
    }
}
