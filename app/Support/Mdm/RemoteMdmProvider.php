<?php

namespace App\Support\Mdm;

use App\Models\EndpointDevice;

/**
 * **الأساسُ المشترك لمزوّدَي MDM الحقيقيّين** (Intune/Jamf) — مسارُ التصحيح §9.
 *
 * يمثّل **السيمَ الرسميّ** للتكامل: اعتماداتُ المستأجر تُحقَن (من `VaultSecret`
 * عبر `MdmService`)، و`isConfigured()` يصدُق عن حضورها. لكنّ **الجسرَ الحيَّ
 * مؤجَّلٌ صراحةً** (لا وصلَ Graph/Jamf API في هذا النظام): فـ`canEnforce()`
 * تعيد false دوماً، و`applyUsbPolicy` أقصاها `observe-only` بتفصيلٍ صادقٍ يقول
 * إنّ الاعتمادات حاضرةٌ والفرضَ الحيَّ مؤجَّل — **لا «synced» زائفةٌ ولا «حُجب»**.
 *
 * حين يُوصَل الجسرُ الحيُّ مستقبلاً، يُغلَّف نداءُ الـAPI هنا خلف `canEnforce()`
 * (تماماً كما نداءُ FCM محجوبٌ خلف اعتماد `FcmPushProvider`).
 */
abstract class RemoteMdmProvider implements MdmProvider
{
    /** @param array{tenant?:string,secret?:string} $creds */
    public function __construct(protected array $creds = []) {}

    /** الوسمُ العربيّ للمزوّد في الواجهة الصادقة */
    abstract public function label(): string;

    public function isConfigured(): bool
    {
        // حضورٌ لا قيمة: المستأجرُ والسرُّ حاضران (السرُّ لا يُقرأ هنا ولا يُسجَّل)
        return trim((string) ($this->creds['tenant'] ?? '')) !== ''
            && trim((string) ($this->creds['secret'] ?? '')) !== '';
    }

    public function canEnforce(): bool
    {
        // **مؤجَّلٌ صراحةً (C15):** لا وصلَ API حيّاً — فلا فرضَ يُزعَم مهما اكتملت الاعتمادات
        return false;
    }

    public function applyUsbPolicy(EndpointDevice $device, string $usbMode): MdmActionResult
    {
        if (! $this->isConfigured()) {
            return MdmActionResult::notConfigured(
                'اعتماداتُ ' . $this->label() . ' غيرُ مكتملة — لا فرضَ (رصدٌ فقط)');
        }

        // الاعتماداتُ حاضرةٌ لكنّ الجسرَ الحيَّ مؤجَّل: أقصى الصدقِ «رصدٌ فقط»
        return MdmActionResult::observeOnly(
            'اعتماداتُ ' . $this->label() . ' حاضرةٌ، لكنّ جسرَ الفرض الحيَّ مؤجَّلٌ — رصدٌ فقط، لا حجبَ منفذٍ يُزعَم');
    }

    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'not-configured',
                'detail' => 'اعتماداتُ ' . $this->label() . ' غيرُ مكتملة'];
        }

        // لا نداءَ حيّاً نتحقّق به — نصرّح بذلك صادقين لا نزعم «ok»
        return ['status' => 'observe-only',
            'detail' => 'اعتماداتُ ' . $this->label() . ' مُهيّأة؛ فحصُ الاتصال الحيّ مؤجَّلٌ — رصدٌ فقط'];
    }
}
