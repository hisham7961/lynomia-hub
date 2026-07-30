<?php

namespace App\Support;

use App\Models\HubNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حارس الدخول: يتعلم عناوين المستخدم المعتادة (مكان العمل = IP متكرر ٣ مرات+)
 * ويرصد الدخول المريب — من عنوانٍ غريب أو خارج وقت العمل — فيسجله في التدقيق،
 * ويُنبّه المالكين، ويُظهر للمستخدم نفسه نافذة «اشتباه وصول غير معتاد».
 */
class LoginSentry
{
    public static function inspect(User $u, string $ip): void
    {
        try {
            // كم مرة رأينا هذا العنوان لهذا المستخدم قبل الآن؟
            $prev = (int) DB::table('user_ips')->where('user_id', $u->id)->where('ip', $ip)->value('hits');

            if ($prev > 0) {
                DB::table('user_ips')->where('user_id', $u->id)->where('ip', $ip)
                    ->update(['hits' => $prev + 1, 'last_seen_at' => now()]);
            } else {
                DB::table('user_ips')->insert(['id' => (string) Str::uuid(),
                    'user_id' => $u->id, 'ip' => $ip, 'hits' => 1, 'last_seen_at' => now()]);
            }

            $familiar = $prev >= 2;   // ثالث دخول من العنوان نفسه = مكان معتاد
            $t = now()->format('H:i');
            $outside = $t < (string) setting('sec.hours_start', '08:00')
                    || $t >= (string) setting('sec.hours_end', '16:00');

            $reasons = [];
            if (! $familiar) $reasons[] = 'من عنوان شبكة غير مألوف (' . $ip . ')';
            if ($outside)    $reasons[] = 'خارج وقت العمل (' . now()->format('H:i') . ')';
            if (! $reasons) return;

            // البصمة في التدقيق (الوقت والعنوان والجهاز يلتقطها hub_audit تلقائياً)
            hub_audit('دخول مريب', null, null, $u->name, ['user_id' => $u->id]);

            // تنبيه المالكين — يظهر في جرس التنبيهات فوراً
            foreach (array_unique(hub_approvers()) as $oid) {
                if (! $oid || $oid === $u->id) continue;
                HubNotification::create(['user_id' => $oid, 'kind' => 'sec',
                    'text' => '🛡️ دخول مريب: ' . $u->name . ' — ' . implode(' و', $reasons),
                    'read' => false, 'created_at' => now()]);
            }

            // النافذة للمستخدم نفسه — تُعرض مرة واحدة في أول صفحة بعد الدخول
            session(['sec.warn' => ['at' => now()->format('Y-m-d H:i'), 'reasons' => $reasons]]);
        } catch (\Throwable $e) {
            // الحارس لا يعطل الدخول أبداً — تعذُّره يُسجل في مركز الأخطاء فقط
            \App\Support\ErrorLog::capture('php', 'LoginSentry: ' . $e->getMessage(), __FILE__, __LINE__);
        }
    }
}
