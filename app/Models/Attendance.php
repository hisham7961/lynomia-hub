<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * الحضور والانصراف.
 *
 * **الساعاتُ تُشتقُّ هنا لا في بابٍ بعينه** (الجولة ٢ · G9): كان الاحتسابُ يعيش
 * في `Workday::checkOut` وحدَه، فتصحيحُ الموارد البشرية للانصرافِ من نموذجِ
 * الوحدات العامّ (`ModuleController`) يحفظ 17:00 ويترك `hours` «—» أبداً —
 * موظّفٌ عالقٌ على ٥٠ ساعةً بدل ٧٥٫٥ في الشبكةِ والمجاميعِ وملفِّ المحاسب.
 * وضعُ القاعدةِ في خطّافِ `saving` يغطّي **كلَّ** الأبواب دفعةً واحدة (الويب
 * والـAPI والجوال والاستيراد وكنسُ نهاية اليوم) بقاعدةِ الاحتسابِ القائمةِ
 * نفسِها — فرقُ اللحظتَين بالثانية ÷ ٣٦٠٠ مقرَّباً لخانتَين — بلا محرّكٍ ثانٍ.
 *
 * **واليومُ المستحيلُ يُرفض** (G10): انصرافٌ قبل الدخول (16:00 → 09:00) كان
 * يُقبل صامتاً، و**يمحو شارةَ الشذوذ** فيبدو اليومُ «حاضراً» نظيفاً — أسرعُ
 * طريقٍ لتصفيرِ الشذوذاتِ قبل الرواتب هو حشوُ وقتٍ كاذب. لا ورديةَ ليليّةً
 * يدعمها المنتجُ صراحةً (لا إعدادَ لها، ويومُ العملِ تاريخٌ واحد)، فالرفضُ
 * برسالةٍ تسمّي القيمتَين هو الصواب — والصفُّ القديمُ الفاسدُ يُوسَم في
 * السجلِّ الشهريِّ «مدة غير صالحة» لا يُجمَّل.
 */
class Attendance extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'attendance';
    public const MODULE = 'attend';
    public const DISPLAY = 'date';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'overnight' => 'boolean',
        'in_at' => 'datetime',
        'out_at' => 'datetime',
        'hours' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            /*
             * **النوبةُ الليليّة** (مجلس الخبراء · DB-02): «٢٢:٠٠ ← ٠٦:٠٠» كانت
             * تُرفض بوصفِها «يوماً مدّتُه سالبة» — ونمذجةُ **اليومِ** لا الفترةِ
             * هي السبب. والنظامُ يحمل محطّاتٍ وعمليّاتٍ ميدانيّةً وأدوارَ إشرافٍ
             * ميدانيّ: الليليّةُ واقعُ هذه الأدوارِ لا استثناؤها.
             *
             * **والرايةُ صريحةٌ لا مستنتَجة:** لو رُوّل العبورُ تلقائيّاً كلَّما
             * سبق الانصرافُ الدخولَ، لابتُلع **الخطأُ المطبعيُّ** في ورديةٍ
             * نهاريّةٍ وصار وردّيةً ليليّةً بثماني ساعات. فالحارسُ يبقى على حالِه
             * لمن لم يُعلن، والمُعلِنُ يُسجَّل.
             */
            if (! $a->overnight && self::hasInvalidSpan($a->time_in, $a->time_out)) {
                throw ValidationException::withMessages(['out' =>
                    'الانصراف (' . trim((string) $a->time_out) . ') قبلَ الدخول ('
                    . trim((string) $a->time_in) . ') — يومٌ مدّتُه سالبةٌ لا يُحفظ. '
                    . 'صحّح الوقتَين أو امسح الانصراف.']);
            }

            // ── G9: الساعاتُ تتبع الأوقات ──
            // الإدخالُ اليدويُّ الصريحُ في هذه الكتابةِ نفسِها تجاوزٌ محترَم (خصمُ
            // استراحةٍ مثلاً): لا يُدهَس. وما عداه يُشتقُّ متى مُسَّ الزوجُ، أو متى
            // كان الحقلُ فارغاً — فالصفُّ المصحَّحُ سابقاً يُحتسب عند أوّلِ حفظٍ تالٍ.
            if ($a->isDirty('hours') && filled($a->hours)) return;

            $touched = $a->isDirty('time_in') || $a->isDirty('time_out');
            if (! $touched && filled($a->hours)) return;

            // زوجٌ ناقصٌ = لا ساعاتٍ تُختلق (F11): «انصراف مفقود» شذوذٌ يُصحَّح لا يُقدَّر
            $a->hours = self::derivedHours($a->time_in, $a->time_out, (bool) $a->overnight);
        });

        // **اللحظتان تُشتقّان من اليومِ والوقتِ والراية** — تُكتبان دائماً كي
        // يقرأ منهما مَن يحتاج زمناً مطلقاً، و`date`/`time_in`/`time_out` تبقى
        // كما هي للعرضِ والتوافقِ الخلفيّ (الإضافةُ لا الكسر).
        static::saving(function (self $a) {
            [$a->in_at, $a->out_at] = self::instants($a->date, $a->time_in, $a->time_out, (bool) $a->overnight);
        });
    }

    /** ثوانيُ اليومِ من وقتٍ نصّيّ `H:i[:s]` — أو null لِما ليس وقتاً صالحاً */
    public static function secondsOfDay($time): ?int
    {
        if (! preg_match('/^\s*([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?\s*$/', (string) $time, $m)) {
            return null;
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) ($m[3] ?? 0);
    }

    /**
     * الساعاتُ المشتقّةُ من زوجِ الأوقات — قاعدةُ `Workday::checkOut` نفسُها
     * (الفرقُ بالثانية ÷ ٣٦٠٠ مقرَّباً لخانتَين). زوجٌ ناقصٌ أو مقلوبٌ ⇒ null.
     */
    public static function derivedHours($timeIn, $timeOut, bool $overnight = false): ?float
    {
        $in = self::secondsOfDay($timeIn);
        $out = self::secondsOfDay($timeOut);
        if ($in === null || $out === null) return null;
        /*
         * **والمساواةُ صفرٌ لا عبور** (التحقّق المستقلّ). كانت `<=` تبتلع
         * المساواةَ فيصير «٠٩:٠٠ ← ٠٩:٠٠» برايةِ الليليّةِ **أربعاً وعشرين ساعةً
         * صامتة** — بلا سقفٍ ولا وسمٍ في الكشفِ الشهريّ، والساعاتُ تُغذّي الرواتبَ
         * والامتثال. وهو عينُ الخطرِ الذي جُعلت الرايةُ صريحةً لأجلِه، مصروفاً في
         * الاتّجاهِ المعاكس: نقرةٌ في غيرِ محلِّها تُحوّل يومَ صفرٍ إلى يومٍ كامل.
         */
        if ($overnight && $out < $in) $out += 86400;
        if ($out < $in) return null;

        return round(($out - $in) / 3600, 2);
    }

    /**
     * اللحظتان المطلقتان من اليومِ والوقتِ والراية — أو `null` لِما لا يُشتقّ.
     *
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    public static function instants($date, $timeIn, $timeOut, bool $overnight = false): array
    {
        if (blank($date)) return [null, null];
        try {
            $day = \Illuminate\Support\Carbon::parse(
                $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10)
            )->startOfDay();
        } catch (\Throwable $e) { return [null, null]; }

        $in = self::secondsOfDay($timeIn);
        $out = self::secondsOfDay($timeOut);
        $inAt = $in === null ? null : $day->copy()->addSeconds($in);
        if ($out === null) return [$inAt, null];

        $outAt = $day->copy()->addSeconds($out);
        // العبورُ المُعلَن: النهايةُ في اليومِ التالي — **والمساواةُ ليست عبوراً**
        if ($overnight && $in !== null && $out < $in) $outAt->addDay();

        return [$inAt, $outAt];
    }

    /** يومٌ مستحيل: وقتان صالحان والانصرافُ قبلَ الدخول (صفوفٌ سابقةٌ للحارس) */
    public static function hasInvalidSpan($timeIn, $timeOut): bool
    {
        $in = self::secondsOfDay($timeIn);
        $out = self::secondsOfDay($timeOut);

        return $in !== null && $out !== null && $out < $in;
    }

    public function emp(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'emp_id');
    }
}
