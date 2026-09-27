<?php

namespace App\Support\Ai\Ask;

use App\Contracts\AskGenerator;
use App\Support\Ai\Gateway\AiGateway;

/**
 * **من يُجيب؟ — قرارٌ واحدٌ مكتوبٌ في موضعٍ واحد.** (جاهزيّةُ الإنتاج · PR-4)
 *
 * ── **ولمَ مصنعٌ بدل سطرِ ربطٍ في `AppServiceProvider`؟** ──
 *
 * لأنّ المطلوبَ صريح: **ألّا يحتاج المالكُ تعديلَ شيفرةٍ لتشغيلِ المساعد.**
 * وسطرُ الربطِ الثابتُ يجعل «التشغيل» تحريرَ ملفٍّ ودفعةً ونشراً. والمصنعُ
 * يقرأ **حالةَ النظامِ الحيّة** فيختار: اكتملت التهيئةُ ⇒ مولِّدٌ حيّ، ولم
 * تكتمل ⇒ مولِّدٌ فارغٌ يقول السببَ بتصنيفٍ صريح.
 *
 * ── **وفشلٌ مُغلَقٌ لا مفتوح** ──
 *
 * الشرطُ للحياة **اجتماعُ أربعة**، ولا يكفي أحدُها:
 *
 *  ① بوّابةٌ مُعلَنةٌ (عنوانٌ ومفتاح) **ومُشغَّلة**.
 *  ② فحصُ اتصالٍ ناجحٌ **على البصمةِ الحاليّةِ بعينِها** — فشهادةُ إعدادٍ زال
 *     ليست شهادة.
 *  ③ غرضُ توجيهٍ موجودٌ **بسلسلةٍ غيرِ فارغة**.
 *  ④ قدرةُ `ai.assistant` مرفوعةٌ في سجلِّ القدرات.
 *
 * **وما لم يجتمع الأربعةُ فلا مزوّدَ افتراضيٌّ ولا احتياطَ مُخترَع.** تهيئةٌ
 * ناقصةٌ تُنتج `UNAVAILABLE` مُصنَّفاً — **لا تُنتج محاولةَ اتصالٍ برجاء**.
 * فالاحتياطُ غيرُ المقصودِ أخطرُ من التعطّل: يُنفق على مسارٍ لم يُقصَد،
 * ويُخفي أنّ الإعدادَ ناقص.
 *
 * ── **والاختباراتُ لا تبلغ هذا المسارَ صدفةً** ──
 *
 * الحارسُ في الربطِ نفسِه (`AppServiceProvider`): في بيئةِ الاختبارِ يُعاد
 * `NullAskGenerator` دائماً ما لم يُحقَن مولِّدٌ صراحةً. فاختبارٌ يُهيّئ
 * البوّابةَ لغرضٍ آخرَ لا يطرق `127.0.0.1:4000` من حيث لا يدري. **والقرارُ
 * نفسُه يبقى مقيساً**: `which()` دالّةٌ خالصةٌ تُستدعى مباشرةً في الاختبار،
 * فلا فرعَ يبقى بلا قياسٍ بحجّةِ الحماية.
 */
final class AskGeneratorFactory
{
    public const LIVE = 'litellm';
    public const NONE = 'null';

    /**
     * **أيُّ مولِّدٍ يستحقّ هذه اللحظة؟** — قرارٌ خالصٌ بلا أثرٍ جانبيّ.
     */
    public static function which(): string
    {
        if (! AiGateway::enabled())      return self::NONE;
        if (! AiGateway::probePassed())  return self::NONE;
        if (! hub_capability(AskPolicy::CAPABILITY)) return self::NONE;
        if (AskPolicy::profile() === null) return self::NONE;

        return self::LIVE;
    }

    /** **ولمَ لا؟** — بجملةٍ تُعرَض للمدير، أو `null` إن كان حيّاً */
    public static function why(): ?string
    {
        if (self::which() === self::LIVE) return null;

        if (! AiGateway::enabled()) {
            return (string) (AiGateway::whyNotReady() ?? 'بوّابةُ النماذجِ غيرُ مهيّأة');
        }
        if (! AiGateway::probePassed()) {
            return 'لم يُفحَص الاتصالُ بالبوّابةِ على الإعدادِ الحاليّ — شغّل الفاحصَ A';
        }
        if (! hub_capability(AskPolicy::CAPABILITY)) {
            return 'قدرةُ «مساعد Hub» غيرُ مرفوعةٍ بعد — تحتاج توليداً مُثبَتاً (الفاحص D)';
        }
        if (AskPolicy::profile() === null) {
            return 'غرضُ «' . AskPolicy::profileKey() . '» بلا سلسلةِ نماذجَ صالحة';
        }

        return 'التهيئةُ لم تكتمل بعد';
    }

    /** **يبني المولِّدَ المناسب** — ولا يبني حيّاً إلّا باجتماعِ الأربعة */
    public static function make(): AskGenerator
    {
        return self::which() === self::LIVE
            ? new LiteLlmAskGenerator()
            : new NullAskGenerator();
    }
}
