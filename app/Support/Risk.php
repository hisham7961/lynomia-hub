<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محرك خطر الجلسة — **مفسَّرٌ لا صندوقٌ أسود** (المرحلة ج من منصة الأمن).
 *
 * لا يكفي «الخطر ٨٢»؛ الحارسُ والمحقّق يريان **لماذا**: كلُّ عاملٍ ببندٍ
 * ونقاطٍ ووصف. والدرجةُ إشارةٌ ترفع الاحتكاك (تصعيد مصادقة) لا حكمٌ يُدين
 * أو يحجب آلياً — فالعواملُ الغامضة تُراجَع لا تُعاقَب.
 *
 * لا مصدرَ جغرافيا IP في البنية، فلا ادعاءَ بلدٍ أو «سفرٍ مستحيل» بدقّةٍ لا
 * نملكها: يُقاس تغيُّرُ **عنوان الشبكة** ومعرفتُه من `user_ips`، والبلدُ
 * يبقى فراغاً حتى يُوصَل مزوِّدٌ لاحقاً (نطاقٌ موثَّقٌ في خريطة المنصة).
 *
 * ويُفصَل عمداً عن «نسبة الشك» في `ActivityController::riskProfile`
 * (ملفٌّ تاريخيٌّ للمستخدم على ١٤ يوماً): هذا **خطرُ الجلسة الآن**.
 */
class Risk
{
    /** حدود النطاقات — من الإعدادات كي تُضبط دون شيفرة */
    public static function bands(): array
    {
        return [
            'med'  => max(1, (int) setting('risk.band_medium', 30)),
            'high' => max(2, (int) setting('risk.band_high', 60)),
            'crit' => max(3, (int) setting('risk.band_critical', 80)),
        ];
    }

    public static function band(int $score): string
    {
        $b = self::bands();
        if ($score >= $b['crit']) return 'حرج';
        if ($score >= $b['high']) return 'عالٍ';
        if ($score >= $b['med']) return 'متوسط';

        return 'منخفض';
    }

    public static function tone(int $score): string
    {
        $b = self::bands();
        if ($score >= $b['high']) return 'bad';
        if ($score >= $b['med']) return 'wn';

        return 'ok';
    }

    /**
     * خطرُ الجلسة الحالية: درجةٌ (٠–١٠٠) وعواملُها المسمّاة.
     * كلُّ فحصٍ محميٌّ — غيابُ جدولٍ يُسقط عاملَه لا الملفَّ كلَّه.
     */
    public static function session(User $user, ?Request $r = null): array
    {
        $r = $r ?? request();
        $factors = [];
        $add = function (string $label, int $points) use (&$factors) {
            if ($points > 0) $factors[] = ['label' => $label, 'points' => $points];
        };

        try {
            $ip = (string) $r?->ip();

            // جهازٌ جديد: لم نره لهذا المستخدم من قبل
            if (Schema::hasTable('user_devices') && $ip !== '') {
                $known = Devices::isKnown($user, $r);
                if (! $known) $add('جهاز جديد لم يُرَ من قبل', 20);
                else {
                    $trust = DB::table('user_devices')->where('user_id', $user->id)
                        ->where('cookie_hash', hash('sha256', (string) Devices::currentCookie($r)))
                        ->value('trust');
                    if ($trust === 'مبطَل') $add('جهازٌ سبق إبطالُه', 25);
                    elseif ($trust !== 'موثوق') $add('جهازٌ معروفٌ لكن غير موثَّق', 6);
                }
            }

            // عنوان شبكة غير مألوف (رأيناه أقل من ٣ مرات)
            if (Schema::hasTable('user_ips') && $ip !== '') {
                $hits = (int) DB::table('user_ips')->where('user_id', $user->id)->where('ip', $ip)->value('hits');
                if ($hits < 3) $add('عنوان شبكة غير مألوف', 15);
            }

            // خارج وقت العمل
            $t = now()->format('H:i');
            $h = (int) now()->format('G');
            if ($h < 6) $add('نشاطٌ في ساعةٍ مريبة (٠٠–٠٦)', 15);
            elseif ($t < (string) setting('sec.hours_start', '08:00') || $t >= (string) setting('sec.hours_end', '16:00')) {
                $add('خارج وقت العمل', 8);
            }

            // محاولات دخول فاشلة حديثة على الحساب
            if (Schema::hasTable('audits')) {
                $failed = (int) DB::table('audits')->where('user_id', $user->id)
                    ->whereIn('action', ['دخول فاشل', 'فشل رمز التحقق'])
                    ->where('created_at', '>=', now()->subHours(24))->count();
                if ($failed > 0) $add('محاولات دخول فاشلة (٢٤ساعة): ' . $failed, min(20, $failed * 5));

                // دخولٌ مريبٌ مرصود لهذه الجلسة (اليوم)
                $strange = (int) DB::table('audits')->where('user_id', $user->id)->where('action', 'دخول مريب')
                    ->where('created_at', '>=', now()->subHours(12))->count();
                if ($strange > 0) $add('دخولٌ مريبٌ مرصود', 12);
            }

            // حسابٌ صاحبُ صلاحياتٍ حسّاسة — سطحُ خطرٍ أعلى بطبيعته
            if (self::privileged($user)) $add('حسابٌ صاحبُ صلاحياتٍ عالية', 10);
        } catch (\Throwable $e) {
            \App\Support\ErrorLog::capture('php', 'Risk::session: ' . $e->getMessage(), __FILE__, __LINE__);
        }

        $score = min(100, array_sum(array_column($factors, 'points')));
        usort($factors, fn ($a, $b) => $b['points'] <=> $a['points']);

        return [
            'score' => $score, 'band' => self::band($score), 'tone' => self::tone($score),
            'factors' => $factors,
        ];
    }

    /** هل الدورُ صاحبُ رايةٍ حسّاسة؟ (مالك أو users/secrets/exp/audit/copySec) */
    public static function privileged(User $user): bool
    {
        $role = $user->role;
        if (! $role) return false;
        if ($role->is_owner) return true;
        $flags = is_array($role->flags) ? $role->flags : (json_decode((string) $role->flags, true) ?: []);

        return (bool) array_intersect(array_keys(array_filter($flags)), ['users', 'secrets', 'copySec', 'exp', 'audit']);
    }
}
