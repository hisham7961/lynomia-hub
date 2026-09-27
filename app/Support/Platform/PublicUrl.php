<?php

namespace App\Support\Platform;

/**
 * **رابطٌ يغادر النظام (بريد · رسالة) يُبنى من العنوان المضبوط لا من الطلب.**
 *
 * `route()` يأخذ جذرَه من ترويسة `Host` للطلب الحاليّ — وطلبٌ مجهولٌ (مثل «نسيتُ كلمة المرور») يتحكّم فيها
 * المهاجم، فيصل الضحيّةَ بريدٌ حقيقيٌّ برابطٍ إلى نطاق المهاجم يحمل رمزاً صالحاً («تسميمُ رابط الاستعادة»).
 * فكلُّ رابطٍ يحمل رمزاً ويُرسَل يمرّ من هنا: جذرُه `config('app.url')`.
 */
final class PublicUrl
{
    public static function route(string $name, mixed $params = []): string
    {
        return rtrim((string) config('app.url'), '/') . route($name, $params, false);
    }
}
