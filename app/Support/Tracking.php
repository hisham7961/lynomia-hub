<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\TrackPoint;
use App\Models\TrackSession;
use Illuminate\Support\Facades\DB;

/**
 * محرّك تتبّع المسار الميدانيّ — **بقواعد الخصوصية مفروضةً في كل دالة**.
 *
 *  — لا جلسةَ بلا موافقةٍ صريحة (`consent`) — تُختم لحظةَ البدء.
 *  — التتبّعُ ضمن جلسةٍ نشطةٍ فقط؛ نقطةٌ لجلسةٍ منتهية تُرفض بنيوياً.
 *  — النقاطُ تُرشَّح بالجودة (دقّةٌ سيئة تُهمَل) وتُمنَع من التكرار بمعرّف العملية.
 *  — GPS لا يمسّ جدول الحضور إطلاقاً — سجلٌّ منفصل.
 *  — النقطةُ الخام تُقلَّم بسياسة الاحتفاظ؛ الخطُّ المبسَّط يبقى على الجلسة.
 */
class Tracking
{
    /** أقصى دفعةٍ في نداءٍ واحد — كنمط metricsIngest */
    public const BATCH_MAX = 500;

    /** أسوأُ دقّةٍ مقبولة بالمتر — قراءةٌ أسوأُ منها ضجيجٌ لا مسار */
    public const MAX_ACCURACY_M = 200;

    /**
     * يبدأ جلسةَ تتبّع لموظفٍ في يومه الميدانيّ — بموافقةٍ مختومة.
     * جلسةٌ نشطةٌ قائمةٌ لليوم نفسه تُعاد بدل فتح ثانية (idempotent على اليوم).
     */
    public static function start(Employee $emp, array $ctx = []): TrackSession
    {
        $day = $ctx['field_day'] ?? now()->toDateString();
        // whereDate لا where: عمود التاريخ المُكاست يُخزَّن «Y-m-d 00:00:00» فلا
        // تطابقه مقارنةٌ نصّيةٌ بـ«Y-m-d» على بعض المحرّكات
        $existing = TrackSession::where('emp_id', $emp->id)->whereDate('field_day', $day)
            ->where('status', 'نشطة')->orderByDesc('started_at')->orderByDesc('id')->first();
        if ($existing) return $existing;

        return TrackSession::create([
            'emp_id' => $emp->id,
            'user_id' => $ctx['user_id'] ?? $emp->user_id,
            'field_day' => $day,
            'status' => 'نشطة',
            'consent_at' => now(),          // لا تتبّع بلا إقرار — يُختم هنا
            'started_at' => now(),
            'device' => isset($ctx['device']) ? hub_fit((string) $ctx['device'], 200) : null,
            'company_id' => $ctx['company_id'] ?? null,
        ]);
    }

    /**
     * يستوعب دفعةَ نقاط — **بمنع تكرارٍ بنيويّ، وترشيحِ جودة، وحدٍّ للعدد**،
     * كلُّها في معاملةٍ واحدة كقالب metricsIngest. يُرجع عدد ما حُفظ فعلاً.
     * نقطةٌ لجلسةٍ غير نشطة لا تُقبل — لا تتبّعَ خارج النافذة المصرَّح بها.
     */
    public static function ingest(TrackSession $s, array $points): array
    {
        if (! $s->active()) {
            return ['saved' => 0, 'skipped' => count($points), 'reason' => 'جلسةٌ غير نشطة — لا تُقبل نقاطُها'];
        }
        $points = array_slice($points, 0, self::BATCH_MAX);

        $saved = 0; $skipped = 0;
        DB::transaction(function () use ($s, $points, &$saved, &$skipped) {
            foreach ($points as $p) {
                $lat = isset($p['lat']) ? (float) $p['lat'] : null;
                $lng = isset($p['lng']) ? (float) $p['lng'] : null;
                $op = (string) ($p['op'] ?? $p['client_operation_id'] ?? '');
                // ترشيحُ الجودة: إحداثيةٌ غائبةٌ أو خارج المدى أو دقّةٌ سيئة → تُهمَل
                $acc = isset($p['acc']) ? (int) $p['acc'] : (isset($p['accuracy']) ? (int) $p['accuracy'] : null);
                if ($lat === null || $lng === null || $op === ''
                    || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
                    || ($acc !== null && $acc > self::MAX_ACCURACY_M)) {
                    $skipped++; continue;
                }
                try {
                    TrackPoint::create([
                        'session_id' => $s->id, 'lat' => $lat, 'lng' => $lng,
                        'accuracy_m' => $acc, 'client_operation_id' => mb_substr($op, 0, 80),
                        'captured_at' => isset($p['at']) ? date('Y-m-d H:i:s', is_numeric($p['at']) ? (int) $p['at'] : strtotime((string) $p['at'])) : now(),
                        'created_at' => now(),
                    ]);
                    $saved++;
                } catch (\Illuminate\Database\QueryException $e) {
                    // القيدُ الفريد رفض التكرار — إعادةُ إرسالٍ لا خطأ
                    $skipped++;
                }
            }
            if ($saved > 0) {
                DB::table('track_sessions')->where('id', $s->id)->increment('point_count', $saved);
            }
        }, 3);

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /** ينهي الجلسة: يبسّط المسار، يحسب المسافة، يختم النهاية */
    public static function end(TrackSession $s): TrackSession
    {
        $pts = TrackPoint::where('session_id', $s->id)->orderBy('captured_at')->orderBy('id')
            ->get(['lat', 'lng'])->map(fn ($p) => [(float) $p->lat, (float) $p->lng])->all();

        $simplified = self::simplify($pts, 0.00005);   // ~5م تسامح
        $s->forceFill([
            'status' => 'منتهية',
            'ended_at' => now(),
            'distance_m' => (int) round(self::pathDistanceM($pts)),
            'simplified' => $simplified,
        ])->save();

        return $s;
    }

    /** طولُ المسار بالمتر — مجموعُ Haversine بين نقاطه المتتالية */
    public static function pathDistanceM(array $pts): float
    {
        $sum = 0.0;
        for ($i = 1; $i < count($pts); $i++) {
            $sum += \App\Models\Facility::distanceM($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
        }

        return $sum;
    }

    /**
     * تبسيطُ المسار (Ramer–Douglas–Peucker): يُبقي شكلَ الطريق ويُسقط النقاطَ
     * المتراصّة — فالخريطةُ تُرسَم من عشراتٍ لا آلاف، والنقاطُ الخام تُقلَّم لاحقاً.
     */
    public static function simplify(array $pts, float $epsilon): array
    {
        $n = count($pts);
        if ($n < 3) return $pts;

        $dmax = 0.0; $index = 0;
        for ($i = 1; $i < $n - 1; $i++) {
            $d = self::perpDist($pts[$i], $pts[0], $pts[$n - 1]);
            if ($d > $dmax) { $dmax = $d; $index = $i; }
        }

        if ($dmax > $epsilon) {
            $left = self::simplify(array_slice($pts, 0, $index + 1), $epsilon);
            $right = self::simplify(array_slice($pts, $index), $epsilon);

            return array_merge(array_slice($left, 0, -1), $right);
        }

        return [$pts[0], $pts[$n - 1]];
    }

    /** بُعد نقطةٍ عن الخط بين طرفين — في فضاء الدرجات (كافٍ للتبسيط) */
    protected static function perpDist(array $p, array $a, array $b): float
    {
        [$px, $py] = $p; [$ax, $ay] = $a; [$bx, $by] = $b;
        $dx = $bx - $ax; $dy = $by - $ay;
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len == 0.0) return sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);

        return abs($dy * $px - $dx * $py + $bx * $ay - $by * $ax) / $len;
    }

    /** سياسةُ الاحتفاظ: النقاطُ الخام الأقدمُ من المدّة تُقلَّم (المسارُ المبسَّط يبقى) */
    public static function prune(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('track_points')) return 0;
        $keep = max(7, (int) setting('field.points_keep_days', 90));

        return (int) DB::table('track_points')->where('captured_at', '<', now()->subDays($keep))->delete();
    }
}
