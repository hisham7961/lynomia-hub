<?php

namespace App\Support\Mdm;

/**
 * **نتيجةُ فعلٍ لدى مزوّد MDM** — القيمةُ التي يعيدها كلُّ محاولةِ فرضٍ
 * (مسارُ التصحيح §8/§9). نظيرُ `PushSendResult` تماماً: قيمةٌ محايدةٌ صادقة.
 *
 * **الحالاتُ الأربع — و«blocked» ليست منها أبداً (C15):** لا يزعم النظامُ قطّ
 * أنّ منفذاً حُجب على النظام. أقصى ما نقوله بصدق:
 *  • `not-configured` — لا تكاملَ MDM (أو اعتماداتُه غائبة): لا فرضَ ممكن.
 *  • `observe-only` — يُرصَد ويُبلَّغ حصراً (الوضعُ الافتراضيّ الصادق) — **لا حجب**.
 *  • `synced` — سُلِّمت **نيّةُ** السياسة لطبقة MDM (المستأجرُ ونظامُه هما من
 *    يفرضان على الجهاز فعلاً — لا نحن). لا يعني «حُجب المنفذُ».
 *  • `failed` — تعذّر التسليمُ لطبقة MDM (خطأٌ تقنيّ) — لا فرضَ.
 */
final class MdmActionResult
{
    public const NOT_CONFIGURED = 'not-configured';
    public const OBSERVE_ONLY = 'observe-only';
    public const SYNCED = 'synced';
    public const FAILED = 'failed';

    /** الحالاتُ المسموحة — «blocked» غائبةٌ عمداً بالبناء (لا ادّعاءَ حجبٍ) */
    public const STATUSES = [self::NOT_CONFIGURED, self::OBSERVE_ONLY, self::SYNCED, self::FAILED];

    private function __construct(
        public readonly string $status,
        public readonly ?string $detail = null,
    ) {}

    public static function notConfigured(?string $detail = null): self
    {
        return new self(self::NOT_CONFIGURED, $detail);
    }

    public static function observeOnly(?string $detail = null): self
    {
        return new self(self::OBSERVE_ONLY, $detail);
    }

    public static function synced(?string $detail = null): self
    {
        return new self(self::SYNCED, $detail);
    }

    public static function failed(?string $detail = null): self
    {
        return new self(self::FAILED, $detail);
    }

    /** هل سُلِّمت نيّةُ الفرض فعلاً لطبقة MDM؟ (لا يعني «حُجب» على الجهاز) */
    public function delivered(): bool
    {
        return $this->status === self::SYNCED;
    }
}
