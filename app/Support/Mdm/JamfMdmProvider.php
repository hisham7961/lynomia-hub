<?php

namespace App\Support\Mdm;

/**
 * **مزوّدُ Jamf Pro** — سيمُ التكامل الرسميّ (مسارُ التصحيح §8/§9). الفرضُ الحيُّ
 * (Jamf API / Configuration Profiles) مؤجَّلٌ خلف `canEnforce()`؛ فبلا وصلٍ
 * حقيقيٍّ للمستأجر يبقى الوضعُ **رصدٌ فقط** صادقاً — لا حجبَ منفذٍ يُزعَم.
 */
class JamfMdmProvider extends RemoteMdmProvider
{
    public function name(): string
    {
        return 'jamf';
    }

    public function label(): string
    {
        return 'Jamf';
    }
}
