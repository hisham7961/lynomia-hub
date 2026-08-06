<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * حدث في سجل أدلة العقود — append-only: لا تعديل ولا حذف بعد الكتابة،
 * فمنه تُبنى شهادة الإتمام والخط الزمني القانوني.
 */
class ContractEvent extends Model
{
    /**
     * رتبةُ الحدث في دورة حياة الطلب — **فاصلُ تعادلٍ دلاليّ** لا حسابيّ.
     *
     * `created_at` بدقّة الثانية، والمسارُ الآليّ يكتب أكثرَ من حدثٍ في الثانية
     * الواحدة (إنشاءٌ ثم إرسالٌ فوريّ، أو تحققٌ ثم توقيع). والترتيبُ كان يقع على
     * الـUUID عندها — أي **قرعة**: فسلسلةُ الأدلة تُحسب بترتيبٍ يختلف بين محرّكٍ
     * وآخر، والخطُّ الزمنيُّ المطبوع في الشهادة يقول «وُقّعت» قبل «أُرسلت».
     */
    public const ORDER = ['created' => 1, 'sent' => 2, 'reminded' => 3, 'opened' => 4,
                          'otp_sent' => 5, 'otp_ok' => 6, 'signed' => 7, 'declined' => 7,
                          'voided' => 8, 'downloaded' => 9];

    /** تعبيرُ الرتبة للاستعمال في `ORDER BY` — CASE يعمل على المحرّكين */
    public static function orderExpr(string $col = 'event'): string
    {
        $cases = '';
        foreach (self::ORDER as $ev => $rank) {
            $cases .= " when {$col} = '" . $ev . "' then {$rank}";
        }

        return "case{$cases} else 99 end";
    }

    /** الترتيبُ المعتمد للأحداث: وقتاً ثم رتبةً دلالية ثم فاصلاً ثابتاً */
    public static function ordered($q)
    {
        return $q->orderBy('created_at')->orderByRaw(self::orderExpr())->orderBy('id');
    }

    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'contract_events';
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];

    public static function booted(): void
    {
        static::updating(fn () => false);   // سجل أدلة لا يُمس
        static::deleting(fn () => false);
    }

    /** كتابة حدث — الملتقط الواحد لكل مواضع التسجيل */
    public static function log(string $event, ?SignRequest $req = null, array $extra = []): self
    {
        return self::create($extra + [
            'event' => $event,
            'request_id' => $req?->id,
            'contract_id' => $req?->contract_id,
            'actor_id' => auth()->id(),
            'ip' => request()?->ip(),
            // hub_fit لا substr: هذا سجلُّ أدلةٍ قانونيّ، وبايتةٌ مقطوعة
            // في منتصف حرفٍ عربيّ ترفضها MySQL فيسقط تسجيلُ الحدث كلّه
            'agent' => hub_fit((string) request()?->userAgent(), 250),
            'created_at' => now(),
        ]);
    }
}
