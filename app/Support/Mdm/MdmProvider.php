<?php

namespace App\Support\Mdm;

use App\Models\EndpointDevice;

/**
 * **واجهةُ مزوّدِ MDM** — تجريدٌ يفصل `MdmService` عن أيِّ مزوّدٍ بعينه
 * (مسارُ التصحيح §8/§9 — نظيرُ `PushProvider`).
 *
 * ثلاثةُ تنفيذات: `NullMdmProvider` (الافتراضيّ — لا تكامل، **رصدٌ فقط، لا حجبَ
 * زائف**)، و`IntuneMdmProvider`/`JamfMdmProvider` (السيمُ الرسميّ للتكامل، محجوبٌ
 * فرضُه الحيُّ خلف اعتماداتِ المستأجر ووصلٍ مؤجَّل). المُنادي يمرّر أوّليّاتٍ لا
 * يُسرّب سرّاً — والمزوّدُ لا يزعم قطّ أنّ منفذاً حُجب على النظام (C15).
 */
interface MdmProvider
{
    /** اسمُ المزوّد للسجلّ (`null|intune|jamf`) — لا يحمل سرّاً */
    public function name(): string;

    /** هل الاعتماداتُ حاضرة؟ (المزوّدُ الصفريُّ دائماً false — الصدقُ لا التزييف) */
    public function isConfigured(): bool;

    /**
     * هل يقدر هذا المزوّدُ على تسليم نيّةِ الفرض لطبقة MDM **الآن**؟
     * كلُّ المزوّدات المشحونة تعيد false (الجسرُ الحيُّ مؤجَّلٌ صراحةً — لا اعتمادَ
     * مستأجرٍ حقيقيٍّ ولا وصلَ API): فالوضعُ الفعليُّ رصدٌ فقط. تنفيذٌ حيٌّ مستقبليٌّ
     * (أو مزوّدٌ مزيّفٌ في الاختبار) وحدَه يقلبها true — وحتى حينها لا «حجبٌ» يُزعَم.
     */
    public function canEnforce(): bool;

    /**
     * محاولةُ تسليمِ نيّةِ سياسةِ USB لطبقة MDM — لا تُطلق استثناءً للفشل المتوقَّع
     * (تعيد `failed`/`not-configured`/`observe-only`)؛ والأعطالُ غيرُ المتوقَّعة
     * يلتقطها `MdmService`. **لا تعيد قطّ حالةَ «حُجب»** — أقصاها `synced` (سُلِّمت
     * النيّةُ لطبقة MDM؛ الفرضُ الفعليُّ على الجهاز عملُ المستأجر ونظامِه لا نحن).
     */
    public function applyUsbPolicy(EndpointDevice $device, string $usbMode): MdmActionResult;

    /**
     * فحصُ صحّةِ التكامل — صادقٌ بلا سرّ: `['status' => ..., 'detail' => ...]`
     * حيث status ∈ {not-configured, observe-only, ok, error}. الصفريُّ observe-only.
     */
    public function health(): array;
}
