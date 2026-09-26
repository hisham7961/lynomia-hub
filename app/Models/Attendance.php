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

    /**
     * صيغةٌ قانونيّةٌ للوقت: `HH:MM:SS` بخاناتٍ مصفَّرة. تقبل `9:00` و`09:00`
     * و`9:00:05`، وتردُّ ما ليس وقتاً كما هو (فالتحقّقُ شأنُ طبقةِ القواعد لا الطبقةِ
     * التي تُخزّن). فارغٌ ⇐ `null`، فلا يصير النصُّ الفارغُ وقتاً.
     */
    public static function normTime($t): ?string
    {
        $t = trim((string) $t);
        if ($t === '') return null;

        if (! preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $t, $m)) return $t;

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
    }

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            /*
             * **الوقتُ يُخزَّن مُصفَّراً — قبل كلِّ شيء** (التحقّقُ المستقلّ الثاني عشر).
             *
             * `time_in`/`time_out` عمودا **نصّ** يُقارَنان **كنصّ** في خمسةِ مواضعَ على
             * الأقلّ، ونموذجُ الإدخالِ يقبل الساعةَ بخانةٍ واحدة. و`'9:00' > '23:00:00'`
             * **معجميّاً** — فتفوز ورديّةُ الصباحِ المنسيّةُ على ورديّةِ الليلِ المفتوحة،
             * وتعود مهلةُ التقريرِ اثنتين وعشرين ساعةً إلى الوراء. جذرٌ واحدٌ تحت ثلاثةِ
             * عيوبٍ حمراء.
             *
             * **والمستودعُ يعرف هذه القاعدةَ ويفرضها** على ساعاتِ العمل في الإعدادات
             * برسالةٍ صريحة: «‏«8:00» تقلب المقارنة صمتاً» — ولم تكن مفروضةً على
             * الحقلَين اللذين تُبنى عليهما الرواتبُ والامتثال. التصفيرُ هنا **عند
             * الكتابة** يُصلح كلَّ قارئٍ دفعةً واحدة، بدل مطاردةِ المقارناتِ واحدةً واحدة.
             */
            $a->time_in = self::normTime($a->time_in);
            $a->time_out = self::normTime($a->time_out);

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
        self::bootDayKeyRelease();

        static::saving(function (self $a) {
            [$a->in_at, $a->out_at] = self::instants($a->date, $a->time_in, $a->time_out, (bool) $a->overnight);

            /*
             * **موظّفٌ ويومٌ = صفٌّ واحد — على كلِّ الأبواب** (مجلس الخبراء · N-15).
             *
             * `unique_together` في سجلِّ الوحدة كان يُفرَض في `ModuleController`
             * من `request()->input()` — أي في **بابِ النموذجِ وحدَه**؛ والاستيرادُ
             * والـAPI والسكربتُ يحفظون بلا قواعدِ الوحدة، ولا قيدَ في القاعدة.
             * وصفّان لليومِ نفسِه هما المُغذّي المباشرُ لعائلةِ «أيُّ صفٍّ هو
             * الصحيح؟» التي طاردها هذا المجلسُ اثنتي عشرةَ جولة.
             *
             * فالمفتاحُ **مشتقٌّ** كنظيرِه في `payroll_runs.month_key`: يحمل اليومَ
             * حين يكون الصفُّ حيّاً، ويُفرَّغ عند الحذف فلا يحجز يوماً لصفٍّ ميّت.
             * والحارسُ هنا يسبق الفهرسَ برسالةٍ مفهومة — والفهرسُ خطُّ الدفاعِ
             * الأخيرُ لسباقِ الكتابةِ المتزامنة.
             */
            $a->day_key = $a->deleted_at !== null ? null
                : ((string) ($a->date?->toDateString() ?? $a->date) ?: null);

            if ($a->day_key !== null && $a->emp_id
                && self::hasColumnCached('day_key')) {
                $clash = static::withoutTrashed()
                    ->where('emp_id', $a->emp_id)->where('day_key', $a->day_key)
                    ->when($a->exists, fn ($q) => $q->whereKeyNot($a->getKey()))
                    ->exists();
                if ($clash) {
                    throw ValidationException::withMessages(['date' =>
                        'لهذا الموظّف صفٌّ مسجَّلٌ في ' . $a->day_key . ' — موظّفٌ ويومٌ '
                        . 'واحدٌ لا يحتمل صفَّين. عدّل الصفَّ القائمَ أو احذفه أوّلاً.']);
                }
            }

            /*
             * **مهلةُ التقريرِ يختمها كلُّ بابٍ لا بابٌ واحد** (مجلس الخبراء · N-21).
             *
             * كان `report_deadline_at` يُكتب في `Workday::checkOut` **وحدَه** — وهو
             * بابٌ من أربعة: نموذجُ الوحدات، والاستيراد، والـAPI، وزرُّ الانصراف.
             * وأمرُ المصالحةِ (`AttendanceReconcileReports`) يرشِّح بـ
             * `whereNotNull('report_deadline_at')`، فكلُّ يومٍ أدخلته المواردُ يدويّاً
             * **لا تراه شبكةُ الأمانِ أبداً**: تُكتب ساعاتُه وتُحتسب حالتُه ولا
             * يُذكَّر أحدٌ بتقريرِه.
             *
             * وهو نظيرُ ما فعله المنتجُ بـ`hours` فوق: نُقل إلى الخطّافِ «كي يغطّيَ
             * كلَّ الأبواب». فيُعطى المعاملةَ نفسَها — كاتبٌ واحدٌ يراه الجميع.
             */
            $a->report_deadline_at = self::derivedDeadline($a);
        });
    }

    /**
     * مهلةُ التقريرِ المشتقّةُ من الصفّ — أو `null` لصفٍّ بلا انصراف.
     * تُستدعى من خطّافِ الحفظِ فتغطّي كلَّ بابٍ يكتب حضوراً (N-21).
     */
    protected static function derivedDeadline(self $a): ?\Carbon\Carbon
    {
        static $has = null;
        if ($has === null) {
            $has = \Illuminate\Support\Facades\Schema::hasTable('attendance')
                && \Illuminate\Support\Facades\Schema::hasColumn('attendance', 'report_deadline_at');
        }
        if (! $has) return null;
        if (! $a->time_in || ! $a->time_out) return null;   // ورديّةٌ مفتوحة: لا مهلةَ بعد (§46)

        $date = (string) ($a->date?->toDateString() ?? $a->date);
        if ($date === '') return null;

        // العبورُ من الصفِّ نفسِه — الرايةُ الصريحةُ ووقتٌ يسبق الدخول
        $crossed = (bool) $a->overnight
            && (string) $a->time_out < (string) $a->time_in;

        return \App\Support\Workforce\DailyWorkCompliance::computeDeadline($date, $a->time_out, true, $crossed);
    }

    /**
     * **المحذوفُ لا يحجز يومَه** (N-15): `day_key` مفتاحٌ مشتقٌّ لا بيانات، يُفرَّغ
     * عند الحذفِ الناعم فيُعاد إدخالُ اليومِ بصفٍّ جديد — والفهرسُ الفريدُ يعدّ
     * الفراغاتِ متمايزةً على المحرِّكَين فلا يصطدم صفّان محذوفان.
     */
    protected static function bootDayKeyRelease(): void
    {
        static::deleted(function (self $a) {
            if (! self::hasColumnCached('day_key')) return;
            if (method_exists($a, 'isForceDeleting') && $a->isForceDeleting()) return;
            static::withoutEvents(fn () => static::withTrashed()
                ->whereKey($a->getKey())->update(['day_key' => null]));
        });
    }

    /** أعمودُ `day_key` موجودٌ؟ — فحصُ مخطَّطٍ يُحفَظ مرّةً (الهجرةُ قد لم تُطبَّق بعد) */
    protected static function hasColumnCached(string $col): bool
    {
        static $memo = [];
        if (! array_key_exists($col, $memo)) {
            $memo[$col] = \Illuminate\Support\Facades\Schema::hasTable('attendance')
                && \Illuminate\Support\Facades\Schema::hasColumn('attendance', $col);
        }

        return $memo[$col];
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
