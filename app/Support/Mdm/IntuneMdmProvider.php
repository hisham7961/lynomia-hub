<?php

namespace App\Support\Mdm;

/**
 * **مزوّدُ Microsoft Intune** — سيمُ التكامل الرسميّ (مسارُ التصحيح §8/§9).
 * الفرضُ الحيُّ (Microsoft Graph / Device Configuration) مؤجَّلٌ خلف
 * `canEnforce()`؛ فبلا وصلٍ حقيقيٍّ للمستأجر يبقى الوضعُ **رصدٌ فقط** صادقاً.
 */
class IntuneMdmProvider extends RemoteMdmProvider
{
    public function name(): string
    {
        return 'intune';
    }

    public function label(): string
    {
        return 'Intune';
    }
}
