<?php

namespace App\Support;

/**
 * **تصنيفُ الإخفاق** — لماذا لم يُجَب السؤال. (المرحلة ٣ · P3-W5)
 *
 * **ولماذا تصنيفٌ أصلاً بدل رسالةِ خطأٍ واحدة؟** لأنّ عيباً حقيقيّاً وقع في
 * هذا المشروعِ من قبل: انقطاعُ خدمةٍ ظهر للمستخدمِ **منعَ صلاحيّة**. فمن لا
 * يملك الصلاحيّةَ يقرأ «غيرُ متاحٍ الآن» فينتظر، ومَن يملكها والخدمةُ ساقطةٌ
 * يقرأ «لا صلاحيّةَ لك» فيذهب إلى مديرِه يطلب ما يملكه أصلاً. **الرسالةُ
 * الواحدةُ تكذب على الاثنين.**
 *
 * ولذلك يفصل هذا الصنفُ **الصلاحيّةَ عن التوافرِ عن العطل**، ويفصل داخلَ
 * العطلِ بين بوّابةٍ ومزوّدٍ ونموذجٍ ومهلةٍ ومعدّل. وكلُّ رمزٍ هنا له **رسالةٌ
 * للمستخدمِ لا تكشف ما لا يملكه**، و**تصنيفٌ للتدقيقِ يكشف الحقيقةَ كاملةً**.
 */
final class AskFailures
{
    /** لا صلاحيّةَ — البابُ مغلقٌ لهذا المستخدِمِ بعينِه */
    public const UNAUTHORIZED = 'UNAUTHORIZED';

    /** الصلاحيّةُ قائمةٌ والخدمةُ غيرُ مهيّأةٍ بعد — **وهذا ليس منعاً** */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /** البوّابةُ لم تُجِب أو ردّت خطأً بنيويّاً */
    public const GATEWAY_FAILURE = 'GATEWAY_FAILURE';

    /** المزوّدُ خلفَ البوّابةِ رفض أو سقط */
    public const PROVIDER_FAILURE = 'PROVIDER_FAILURE';

    /** النموذجُ أجاب بما لا يُفهَم — لا عطلَ شبكةٍ بل ردٌّ فاسد */
    public const MODEL_FAILURE = 'MODEL_FAILURE';

    public const TIMEOUT      = 'TIMEOUT';
    public const RATE_LIMITED = 'RATE_LIMITED';

    /** السياقُ بلغ سقفَه قبل أن يكتملَ الجواب */
    public const CONTEXT_LIMIT = 'CONTEXT_LIMIT';

    /** طلبُ أداةٍ لا يُطابق العقد: اسمٌ مجهولٌ أو شكلٌ فاسد */
    public const MALFORMED_TOOL_REQUEST = 'MALFORMED_TOOL_REQUEST';

    /** وسائطُ أداةٍ مرفوضة: وحدةٌ غيرُ متاحةٍ أو حقلٌ محجوبٌ أو عاملٌ مجهول */
    public const UNSAFE_TOOL_ARGUMENTS = 'UNSAFE_TOOL_ARGUMENTS';

    /** **لا بياناتٍ في نطاقِك** — ولا يُقال «لا وجودَ لها» فذلك كشفُ وجود */
    public const NO_ACCESSIBLE_DATA = 'NO_ACCESSIBLE_DATA';

    /** جوابٌ جزئيّ: بعضُ ما طُلب خرج عن الميزانيّةِ أو النطاق */
    public const PARTIAL_RESULT = 'PARTIAL_RESULT';

    /** النموذجُ أحال إلى مصدرٍ لم يقرأه الخادمُ قطّ */
    public const FORGED_SOURCE = 'FORGED_SOURCE';

    /** تجاوزُ عددِ الأدواتِ المسموحِ في الطلب */
    public const TOOL_BUDGET = 'TOOL_BUDGET';

    /** السؤالُ نفسُه فارغٌ أو أطولُ من المسموح — خطأُ طلبٍ لا خطأُ خدمة */
    public const MALFORMED_QUESTION = 'MALFORMED_QUESTION';

    /**
     * **حُجب المحتوى بمرشِّحِ سياسةٍ عند المزوّد** — وهذه **نتيجةٌ لا عطل**.
     *
     * وأُضيف الرمزُ حين قُرئ عقدُ البوّابةِ الحقيقيّ: `ContentPolicyViolationError`
     * يعود `400` مثلَ الطلبِ الفاسد، و`finish_reason` قد يعود `content_filter`
     * على ردٍّ **ناجحٍ** بـ`200`. وبلا رمزٍ له كان يُقال للمستخدمِ «وصل ردٌّ غيرُ
     * مفهومٍ من النموذج» — وهو كذبٌ يُرسله يُطارد عطلاً لا وجودَ له، بينما
     * الصوابُ أن يُعيد صياغةَ سؤالِه.
     */
    public const CONTENT_FILTERED = 'CONTENT_FILTERED';

    public const CODES = [
        self::UNAUTHORIZED, self::UNAVAILABLE, self::GATEWAY_FAILURE, self::PROVIDER_FAILURE,
        self::MODEL_FAILURE, self::TIMEOUT, self::RATE_LIMITED, self::CONTEXT_LIMIT,
        self::MALFORMED_TOOL_REQUEST, self::UNSAFE_TOOL_ARGUMENTS, self::NO_ACCESSIBLE_DATA,
        self::PARTIAL_RESULT, self::FORGED_SOURCE, self::TOOL_BUDGET, self::MALFORMED_QUESTION,
        self::CONTENT_FILTERED,
    ];

    /**
     * **الرموزُ التي تعني «البابُ مغلق»** — وما عداها يعني «الخدمةُ متعثّرة».
     *
     * والفرقُ ليس تجميليّاً: عليه تُبنى الرسالةُ، وعليه يُقرَّر أيُعاد المحاولةُ
     * أم يُراجَع المدير.
     */
    public const PERMISSION_CODES = [self::UNAUTHORIZED];

    /** رسالةُ المستخدِم — **لا تكشف وجودَ ما لا يملكه** */
    public const MESSAGES = [
        self::UNAUTHORIZED           => 'لا صلاحيّةَ لديك لاستعمالِ مساعدِ Hub.',
        self::UNAVAILABLE            => 'المساعدُ غيرُ متاحٍ الآن — الإعدادُ لم يكتمل بعد. وهذه ليست مسألةَ صلاحيّة.',
        self::GATEWAY_FAILURE        => 'تعذّر الوصولُ إلى بوّابةِ الذكاء. أعِد المحاولةَ بعد قليل.',
        self::PROVIDER_FAILURE       => 'المزوّدُ لم يستجب. جرّب مرّةً أخرى أو اختر غرضاً آخرَ للتوجيه.',
        self::MODEL_FAILURE          => 'وصل ردٌّ غيرُ مفهومٍ من النموذج — ولم يُعرَض شيءٌ منه.',
        self::TIMEOUT                => 'انقضت المهلةُ قبل اكتمالِ الجواب.',
        self::RATE_LIMITED           => 'تجاوزتَ حدَّ الطلبات. انتظر قليلاً ثمّ أعِد السؤال.',
        self::CONTEXT_LIMIT          => 'السؤالُ يحتاج بياناتٍ أكثرَ ممّا يتّسع له السياق. ضيِّق السؤالَ.',
        self::MALFORMED_TOOL_REQUEST => 'طلبُ قراءةٍ غيرُ صالحٍ — لم يُنفَّذ.',
        self::UNSAFE_TOOL_ARGUMENTS  => 'طلبُ قراءةٍ برفضٍ من الحارس — لم يُنفَّذ.',
        self::NO_ACCESSIBLE_DATA     => 'لا بياناتٍ ضمنَ نطاقِك تجيب عن هذا السؤال.',
        self::PARTIAL_RESULT         => 'الجوابُ جزئيٌّ — بعضُ البياناتِ خرج عن حدودِ السياقِ أو نطاقِك.',
        self::FORGED_SOURCE          => 'أحال الجوابُ إلى مصدرٍ لم يُقرأ — فحُجب.',
        self::TOOL_BUDGET            => 'بلغ السؤالُ حدَّ خطواتِ القراءةِ المسموحة.',
        self::MALFORMED_QUESTION     => 'السؤالُ فارغٌ أو أطولُ من المسموح.',
        self::CONTENT_FILTERED       => 'حجب المزوّدُ الردَّ بمرشِّحِ محتوى. أعِد صياغةَ السؤال.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'تعذّر إكمالُ الطلب.';
    }

    public static function isPermission(string $code): bool
    {
        return in_array($code, self::PERMISSION_CODES, true);
    }

    public static function known(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
