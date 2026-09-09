<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **الحضورُ التخشينيّ لتجربةِ التواصل** (§presence · IMPLEMENT_NOW) — حالةٌ خشنةٌ
 * تحترم الخصوصيّة: **متصلٌ / نشطٌ حديثاً / بعيدٌ / غير متصل** — لا أكثر.
 *
 * **ليست مراقبة:** ليست تتبّعَ حضور، ولا تقييمَ إنتاجيّة، ولا تعقّبَ موقع، ولا سجلَّ
 * نشاطٍ دائماً عاليَ التردّد. تُقرأ من **نبضةِ الجلسة القائمة** (`sessions_log.last_seen_at`)
 * — **لا كتاباتٍ جديدةً عاليةَ التردّد**، ولا تدّعي صدقاً بالثانية. للتواصل فقط.
 *
 * محايدةُ النقل: تُستهلَك اليومَ استطلاعاً وغداً بثّاً بلا تغيّرِ عقدها.
 */
class Presence
{
    public const ONLINE = 'online';    // ≤ ٥ دقائق
    public const RECENT = 'recent';    // ≤ ١٥ دقيقة
    public const AWAY   = 'away';      // ≤ ٦٠ دقيقة
    public const OFFLINE = 'offline';  // أبعد / لا نبضة

    /** حالةُ الحضورِ من آخرِ ظهورٍ — دالّةٌ نقيّةٌ بلا كتابة */
    public static function state($lastSeen): string
    {
        if (! $lastSeen) return self::OFFLINE;
        $c = $lastSeen instanceof Carbon ? $lastSeen : Carbon::parse($lastSeen);
        $mins = $c->diffInMinutes(now());

        return $mins <= 5 ? self::ONLINE
            : ($mins <= 15 ? self::RECENT
            : ($mins <= 60 ? self::AWAY : self::OFFLINE));
    }

    /**
     * حالاتُ حضورِ مجموعةِ مستخدمين — `[uid => state]` من نبضةِ الجلسة القائمة
     * (استعلامٌ واحد، لا كتابة). العمودُ حديث: قبل الجدولِ لا يُطفأ شيء.
     *
     * @return array<string,string>
     */
    public static function for(array $userIds): array
    {
        if (! $userIds || ! Schema::hasTable('sessions_log')) return [];

        $rows = DB::table('sessions_log')
            ->whereIn('user_id', $userIds)->where('revoked', false)
            ->groupBy('user_id')
            ->pluck(DB::raw('MAX(last_seen_at)'), 'user_id');

        $out = [];
        foreach ($rows as $uid => $at) {
            $out[(string) $uid] = self::state($at ? Carbon::parse($at) : null);
        }

        return $out;
    }
}
