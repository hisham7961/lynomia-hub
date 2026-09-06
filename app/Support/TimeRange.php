<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * المدى الزمنيّ الموحّد للمنصّة كلّها (WP-1.1) — كبسولةٌ واحدة تفهمها كلُّ شاشة.
 *
 * `?range=1h|6h|24h|7d|30d|90d` نوافذُ منزلقة تنتهي الآن، و`custom` مع `from/to`
 * (تاريخٌ وحده أو تاريخٌ ووقت). التوافقُ الرجعيّ صريح: `from/to` وحدهما — كما
 * تقرؤهما شاشةُ التدقيق (whereDate >= from و<= to) والقدرات والعروضُ المحفوظة —
 * تعنيان `custom` بنفس الحدود حرفياً، فلا ينكسر رابطٌ مخزَّن في `saved_views.query`.
 * ومعاملاتُ API (`created_from/created_to` بدلالة اليوم كاملاً كما في
 * `Api::timeFilters`، و`updated_since` لحظةً حتى الآن) و`days`/`d` العدديّان
 * (السوشال) تُقرأ كما هي — **لا يُعاد تسميةُ معاملٍ قائم**.
 *
 * `to` **حصريٌّ دائماً**: apply() تكتب `>= from` و`< to` — سارغابل، ولا whereDate
 * على عمودٍ مفهرَس. المنطقةُ الزمنية `config('app.timezone')` كما يفعل hub_metric_put.
 */
class TimeRange
{
    /** الكبسولات المعتمدة: المفتاح ⇒ [الدقائق، التسمية العربية] */
    public const PRESETS = [
        '1h'  => [60, 'آخر ساعة'],
        '6h'  => [360, 'آخر ٦ ساعات'],
        '24h' => [1440, 'آخر ٢٤ ساعة'],
        '7d'  => [10080, 'آخر ٧ أيام'],
        '30d' => [43200, 'آخر ٣٠ يوماً'],
        '90d' => [129600, 'آخر ٩٠ يوماً'],
    ];

    public readonly Carbon $from;
    public readonly Carbon $to;      // حصريّ: apply() تقرأ < to لا <=
    public readonly string $preset;  // مفتاحُ كبسولةٍ أو 'custom'

    private function __construct(Carbon $from, Carbon $to, string $preset, private readonly bool $isPrev = false)
    {
        // نقطتان متطابقتان (from == to بوقتٍ صريح) نافذةٌ فارغة تُقوَّم لدقيقة
        if (! $from->lt($to)) $to = $from->copy()->addMinute();
        $this->from = $from;
        $this->to = $to;
        $this->preset = $preset;
    }

    /**
     * قراءةُ المدى من الطلب — الأولوية: `range` صالح، ثم `from/to` (صريحاً أو
     * توافقاً رجعياً)، ثم معاملاتُ API، ثم `days`/`d` العدديّان، ثم الافتراضي.
     * كلُّ ما لا يُفهَم يرتدّ للافتراضي بصمت: معاملُ رابطٍ يتحكّم به الطالب
     * لا يُسقط شاشةً أبداً (نمطُ hub_capacity v2.325).
     */
    public static function fromRequest(?Request $r = null, string $default = '7d'): self
    {
        $r = $r ?? request();
        $tz = (string) config('app.timezone');
        $now = Carbon::now($tz);
        if (! isset(self::PRESETS[$default])) $default = '7d';

        $range = trim(hub_str($r->query('range')));
        if (isset(self::PRESETS[$range])) return self::preset($range, $now);

        // custom صريحٌ أو from/to وحدهما (شاشةُ التدقيق والقدرات والعروضُ المحفوظة)
        $from = self::side(hub_str($r->query('from')), $tz);
        $to = self::side(hub_str($r->query('to')), $tz);
        // توافقُ API: created_from/created_to يومٌ كامل دائماً (Api::timeFilters تمرّر toDateString لـ whereDate)
        if (! $from && ! $to) {
            $from = self::side(hub_str($r->query('created_from')), $tz, true);
            $to = self::side(hub_str($r->query('created_to')), $tz, true);
        }
        if ($from || $to) return self::custom($from, $to, $now, $default);

        // updated_since لحظةٌ حتى الآن (في Api::timeFilters: where >= بلا قصّ لليوم)
        if ($since = self::side(hub_str($r->query('updated_since')), $tz)) {
            return new self(self::instant($since), $now, 'custom');
        }

        // days/d العدديّان (السوشال/مقاييس API) — عددُ أيامٍ ينتهي الآن. غيرُ العدديّ
        // لا يُختطف: d اتجاهُ فرزٍ في الوحدات ومعرّفُ لوحةٍ في الرئيسية.
        foreach (['days', 'd'] as $p) {
            $v = trim(hub_str($r->query($p)));
            if ($v !== '' && ctype_digit($v) && (int) $v > 0) {
                $n = min(732, (int) $v);   // سقفُ hub_capacity نفسُه — لا نافذةَ بلا قاع
                $k = [1 => '24h', 7 => '7d', 30 => '30d', 90 => '90d'][$n] ?? null;
                return $k ? self::preset($k, $now) : new self($now->copy()->subDays($n), $now->copy(), 'custom');
            }
        }

        return self::preset($default, $now);
    }

    /** النافذةُ السابقةُ المساوية (spec §36): تنتهي حيث تبدأ هذه وبنفس الطول */
    public function prev(): self
    {
        $sec = (int) round($this->from->diffInSeconds($this->to));

        return new self($this->from->copy()->subSeconds($sec), $this->from->copy(), $this->preset, true);
    }

    /** ترشيحُ استعلامٍ بالمدى: `>= from` و`< to` — سارغابل، لا whereDate على مفهرَس */
    public function apply($q, string $col = 'created_at')
    {
        return $q->where($col, '>=', $this->from)->where($col, '<', $this->to);
    }

    /** طولُ المدى أياماً (كسريّ) */
    public function days(): float
    {
        return $this->from->diffInSeconds($this->to) / 86400;
    }

    /** طولُ المدى دقائق */
    public function minutes(): int
    {
        return (int) round($this->from->diffInSeconds($this->to) / 60);
    }

    /**
     * مفتاحُ الخبيئة (لبادئة hub_screen): الكبسولةُ باسمها — نافذتُها المنزلقة
     * يضبطها TTL — والمخصّصُ بحدوده، والسابقةُ ببادئة p: كي لا تتلوّث نافذتان.
     */
    public function key(): string
    {
        $k = $this->preset === 'custom'
            ? 'c' . $this->from->format('YmdHi') . '-' . $this->to->format('YmdHi')
            : $this->preset;

        return $this->isPrev ? 'p:' . $k : $k;
    }

    /** التسميةُ العربية للعرض */
    public function label(): string
    {
        if ($this->isPrev) return 'النافذة السابقة';

        return $this->preset === 'custom' ? 'مدى مخصّص' : self::PRESETS[$this->preset][1];
    }

    /**
     * معاملاتُ هذا المدى لبناء الروابط (تُدمج مع باقي معاملات الطلب في العرض).
     * مدىً بحدود أيامٍ كاملة يخرج تاريخين فقط — فيرجع عبر fromRequest بنفس
     * الحدود حرفياً (اليومُ الأخير ظاهرٌ شاملاً كما يكتبه المستخدم).
     */
    public function toQuery(): array
    {
        if ($this->preset !== 'custom') return ['range' => $this->preset];
        $days = $this->from->format('H:i:s') === '00:00:00' && $this->to->format('H:i:s') === '00:00:00';

        return $days
            ? ['range' => 'custom', 'from' => $this->from->format('Y-m-d'), 'to' => $this->to->copy()->subDay()->format('Y-m-d')]
            : ['range' => 'custom', 'from' => $this->from->format('Y-m-d H:i:s'), 'to' => $this->to->format('Y-m-d H:i:s')];
    }

    /** نافذةُ كبسولةٍ منزلقة تنتهي الآن */
    private static function preset(string $key, Carbon $now): self
    {
        return new self($now->copy()->subMinutes(self::PRESETS[$key][0]), $now->copy(), $key);
    }

    /**
     * قراءةُ طرفٍ واحد: null لما لا يُفهَم (Carbon::parse على نصٍّ حرّ ترمي —
     * v2.325). `day` = تاريخٌ بلا وقت (لا نقطتين في النصّ) أو مفروضٌ (created_*).
     */
    private static function side(string $v, string $tz, bool $dayAlways = false): ?array
    {
        $v = trim($v);
        if ($v === '') return null;
        try {
            $t = Carbon::parse($v, $tz);
        } catch (\Throwable $e) {
            return null;
        }

        return ['at' => $t, 'day' => $dayAlways || ! str_contains($v, ':')];
    }

    /** لحظةُ طرفٍ: اليومُ الكامل يبدأ من منتصف ليله */
    private static function instant(array $side): Carbon
    {
        return $side['day'] ? $side['at']->copy()->startOfDay() : $side['at']->copy();
    }

    /**
     * مدىً مخصّص: المعكوسُ يُقوَّم **قبل** بسط اليوم — فالمعنى «بين اليومين» أيّاً
     * كان ترتيبُهما، واليومُ الأبعد يدخل كاملاً (يُطابق whereDate <= في التدقيق
     * وendOfDay في hub_capacity). الطرفُ الغائب: النهايةُ الآن، والبدايةُ بطول
     * الافتراضي قبل النهاية.
     */
    private static function custom(?array $from, ?array $to, Carbon $now, string $default): self
    {
        if ($from && $to && $to['at']->lt($from['at'])) [$from, $to] = [$to, $from];
        $f = $from ? self::instant($from) : null;
        $t = $to ? ($to['day'] ? $to['at']->copy()->startOfDay()->addDay() : $to['at']->copy()) : null;
        $t = $t ?? $now->copy();
        $f = $f ?? $t->copy()->subMinutes(self::PRESETS[$default][0]);

        return new self($f, $t, 'custom');
    }
}
