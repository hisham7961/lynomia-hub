<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رادارُ الكشف الحيّ — **من طرق باباً لا يملك مفتاحه**.
 *
 * مركزُ الأمان كان يرصد المحاولات الفاشلة للدخول (كلمة مرور خاطئة) — لكن ثمّة
 * سطحٌ آخر: **محاولةُ فتح ما لا يُملَك**. زائرٌ يخمّن رابطَ توقيعٍ أو مشاركة، أو
 * مستخدمٌ يطرق مساراً خارج صلاحيته (٤٠٣) — كلاهما إشارةُ تسلّلٍ أو تقصٍّ لا يراها
 * أحد. يلتقطها هذا الرادار من ردود المنع (٤٠٣) وتخمينِ الروابط العامّة (٤٠٤ على
 * `sign/*`/`verify/*`)، ويعرضها حيّةً في مركز الأمان مع تجميعٍ للعناوين الطارقة.
 *
 * يفشل مفتوحاً دائماً: تسجيلٌ متعذّر لا يُسقط الطلبَ ولا يمنع المنعَ نفسه.
 */
class SecurityRadar
{
    /** يلتقط محاولةَ وصولٍ مرفوضة — يُستدعى من الوسيط بعد أن يتقرّر المنع */
    public static function record(Request $r, string $kind, ?string $detail = null): void
    {
        if (! Schema::hasTable('access_denials')) return;   // قبل الهجرة: لا نكسر الطلب

        try {
            DB::table('access_denials')->insert([
                'kind'       => mb_substr($kind, 0, 40),
                // صاحبُ المحاولة إن كان مسجّلاً — و`null` تعني **زائراً غير مستخدم**
                'user_id'    => auth()->id(),
                'ip'         => $r->ip(),
                'method'     => mb_substr((string) $r->method(), 0, 10),
                'path'       => mb_substr('/' . ltrim((string) $r->path(), '/'), 0, 300),
                'detail'     => $detail !== null ? mb_substr($detail, 0, 300) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // رادارٌ معطوب لا يُسقط الطلب — الكشفُ إضافةٌ لا شرطٌ للخدمة
        }
    }

    /** أحدث المحاولات المرفوضة — لعرضها حيّةً بمن ومن أين وماذا طرق */
    public static function recent(int $days = 7, int $limit = 20): Collection
    {
        if (! Schema::hasTable('access_denials')) return collect();

        return DB::table('access_denials')
            ->leftJoin('users', 'users.id', '=', 'access_denials.user_id')
            ->where('access_denials.created_at', '>=', now()->subDays($days))
            ->orderByDesc('access_denials.id')->limit($limit)
            ->get(['access_denials.kind', 'access_denials.ip', 'access_denials.path',
                   'access_denials.method', 'access_denials.detail', 'access_denials.created_at',
                   'access_denials.user_id', 'users.name as uname']);
    }

    /**
     * العناوين الطارقة: من كثُرت محاولاتُه المرفوضة وتعدّدت أهدافُه — تقصٍّ لا خطأ.
     * مجمَّعٌ في try كتوأمه في مركز الأمان: HAVING/تجميعٌ قد يُرفض على محرّكٍ صارم،
     * وبطاقةٌ ناقصة أهون من لوحةٍ لا تُفتح.
     */
    public static function threats(int $days = 7, int $limit = 6): Collection
    {
        if (! Schema::hasTable('access_denials')) return collect();

        try {
            return DB::table('access_denials')
                ->where('created_at', '>=', now()->subDays($days))->whereNotNull('ip')
                ->groupBy('ip')
                ->orderByRaw('COUNT(*) DESC')->limit($limit)
                ->get([DB::raw('ip'), DB::raw('COUNT(*) as hits'),
                       DB::raw('COUNT(DISTINCT path) as targets'),
                       DB::raw('SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) as anon')]);
        } catch (\Throwable $e) {
            ErrorLog::capture('php', 'security-radar: تعذّر تجميع العناوين — ' . $e->getMessage(),
                $e->getFile(), $e->getLine());

            return collect();
        }
    }

    /** ملخّصٌ للشريط العلوي: كم محاولة، من كم عنوان، وكم منها من غير مستخدم */
    public static function summary(int $days = 7): array
    {
        if (! Schema::hasTable('access_denials')) return ['total' => 0, 'ips' => 0, 'anon' => 0];

        $since = now()->subDays($days);
        $base = fn () => DB::table('access_denials')->where('created_at', '>=', $since);

        return [
            'total' => (int) $base()->count(),
            'ips'   => (int) $base()->whereNotNull('ip')->distinct()->count('ip'),
            'anon'  => (int) $base()->whereNull('user_id')->count(),   // محاولاتُ غير المستخدمين
        ];
    }
}
