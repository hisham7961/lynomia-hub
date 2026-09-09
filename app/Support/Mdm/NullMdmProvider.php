<?php

namespace App\Support\Mdm;

use App\Models\EndpointDevice;

/**
 * **المزوّدُ الصفريّ** — الافتراضيُّ حين لا تكاملَ MDM (مسارُ التصحيح §8 —
 * «‏USB observe-vs-enforce، لا حجبَ زائفاً»). نظيرُ `NullPushProvider`.
 *
 * لا يتّصل بشيءٍ ولا يزعم فرضاً: كلُّ محاولةٍ تعيد `observe-only` — الوكيلُ يرصد
 * أحداثَ USB ويبلّغها، **ولا منفذَ يُحجَب على النظام**. هو مزوّدُ الإنتاج ما لم
 * يُضبَط تكاملُ Intune/Jamf، والصدقُ الصريحُ لمسار «الرصدُ فقط».
 */
class NullMdmProvider implements MdmProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;   // الصدقُ لا التزييف
    }

    public function canEnforce(): bool
    {
        return false;   // لا فرضَ بلا تكامل — رصدٌ فقط
    }

    public function applyUsbPolicy(EndpointDevice $device, string $usbMode): MdmActionResult
    {
        return MdmActionResult::observeOnly('لا تكاملَ MDM — الوكيلُ يرصد USB ويبلّغه، ولا يُحجَب منفذ');
    }

    public function health(): array
    {
        return ['status' => 'observe-only', 'detail' => 'لا تكاملَ MDM مُهيّأ — رصدٌ فقط'];
    }
}
