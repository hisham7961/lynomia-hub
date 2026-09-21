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

    /*
     * ── **تفصيلُ `MODEL_FAILURE` بعد قبولِ إنتاجٍ حقيقيّ** ──
     *
     * كان رمزاً واحداً يبتلع **أربعَ حالاتٍ علاجُ كلٍّ منها مختلفٌ تماماً**،
     * فيقرأ المشغّلُ «ردٌّ غيرُ مفهوم» عن ردٍّ **مفهومٍ تماماً** ولا يُشخَّص منه
     * شيء. والرمزُ الجامعُ يبقى لما لا يُصنَّف، والأربعةُ تخرج منه بأسمائِها.
     */

    /** **٢٠٠ بجسمٍ ليس إكمالَ محادثةٍ أصلاً** — عيبُ طرفٍ آخرَ لا عيبُ فهمِنا */
    public const MALFORMED_MODEL_RESPONSE = 'MALFORMED_MODEL_RESPONSE';

    /** **بنيةٌ سليمةٌ ولا مخرَجَ البتّة** — `content: null` بلا أداةٍ ولا تفكير */
    public const MODEL_NO_OUTPUT = 'MODEL_NO_OUTPUT';

    /** **أنفق النموذجُ مخرَجَه في التفكيرِ ولم يُخرج جواباً** */
    public const MODEL_REASONED_ONLY = 'MODEL_REASONED_ONLY';

    /** **سببُ الانتهاءِ يُناقض الرسالة** — «أدوات» بلا نداءِ أداة */
    public const TOOL_PROTOCOL_ERROR = 'TOOL_PROTOCOL_ERROR';

    /**
     * **رقمٌ في الجوابِ ولم تُنفَّذ قراءةٌ واحدة.**
     *
     * والسؤالُ «كم مشروعاً لدينا؟» لا يُجاب من معرفةِ نموذج: العددُ يأتي من
     * Hub عبر أداةٍ مصرَّحٍ بها بنطاقِ صاحبِ الجلسة. **وجوابٌ واثقٌ بعددٍ
     * مخترَعٍ أسوأُ من لا جواب** — لأنّه يُصدَّق ويُبنى عليه قرار.
     */
    public const UNSOURCED_NUMBER = 'UNSOURCED_NUMBER';

    public const TIMEOUT      = 'TIMEOUT';
    public const RATE_LIMITED = 'RATE_LIMITED';

    /**
     * **نفد رصيدُ الحسابِ عند المزوّد** — والفرقُ عن `RATE_LIMITED` عمليٌّ لا لفظيّ.
     *
     * كلاهما يعود `429`، **وقرارُهما متضادّ**: حدُّ المعدّلِ ينقضي بمهلةٍ
     * مُعلَنةٍ فيصحُّ الانتظارُ والإعادة، ونفادُ الرصيد **لا ينقضي بشيء** —
     * فالإعادةُ نداءٌ ضائعٌ والانتظارُ كذبٌ على من ينتظر. والاحتياطُ إلى
     * نموذجٍ آخرَ على **الاعتمادِ نفسِه** يصطدم بالرصيدِ نفسِه.
     */
    public const PROVIDER_CREDITS = 'PROVIDER_CREDITS';

    /** النموذجُ بعينِه غيرُ صالحٍ الآن — مُعطَّلٌ أو مُزالٌ أو مُستبعَد */
    public const MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';

    /** منعته سياسةُ الحوكمةِ — **والصلاحيّةُ قائمةٌ** فلا يُقال «لا صلاحيّةَ لك» */
    public const POLICY_DENIED = 'POLICY_DENIED';

    /** بلغت الميزانيّةُ سقفَها في هذه الفترة — مالٌ لا صلاحيّة */
    public const BUDGET_EXCEEDED = 'BUDGET_EXCEEDED';

    /** بلغت الحصّةُ سقفَها — عددُ طلباتٍ أو رموزٍ لا مال */
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';

    /** السياقُ بلغ سقفَه قبل أن يكتملَ الجواب */
    public const CONTEXT_LIMIT = 'CONTEXT_LIMIT';

    /** طلبُ أداةٍ لا يُطابق العقد: اسمٌ مجهولٌ أو شكلٌ فاسد */
    public const MALFORMED_TOOL_REQUEST = 'MALFORMED_TOOL_REQUEST';

    /**
     * وسائطُ أداةٍ مرفوضة: وحدةٌ غيرُ متاحةٍ أو حقلٌ محجوبٌ أو عاملٌ مجهول.
     *
     * **مُعلَنٌ بلا مُنتِج** (وُثّق في تدقيقِ ما قبل المرحلة ٥): رفضُ الحارسِ
     * لوسائطِ أداةٍ **لا يُسقط الطلب** — يُسجَّل في المظروفِ `rejected_step_N`
     * ويمضي النموذجُ ليجيب بما يملك. فالرفضُ حدثٌ **داخلَ** الطلبِ لا تصنيفٌ
     * له. ويحرس الحالَ `AskAdversarialSweepTest` فلا يُوصَّل مُنتِجٌ بلا اختبار.
     */
    public const UNSAFE_TOOL_ARGUMENTS = 'UNSAFE_TOOL_ARGUMENTS';

    /**
     * **لا بياناتٍ في نطاقِك** — ولا يُقال «لا وجودَ لها» فذلك كشفُ وجود.
     *
     * **مُعلَنٌ بلا مُنتِج** (كسابقِه): «لا بيانات» **جوابٌ يقوله النموذجُ
     * نصّاً** لا إخفاقٌ يُصنَّف، ومنعُ الاختلاقِ مكانَه بحارسَي
     * `FORGED_SOURCE` و`UNSOURCED_NUMBER` — وكلاهما يُقاس بالدليل.
     */
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
        self::PROVIDER_CREDITS, self::MODEL_UNAVAILABLE,
        self::MALFORMED_MODEL_RESPONSE, self::MODEL_NO_OUTPUT, self::MODEL_REASONED_ONLY,
        self::TOOL_PROTOCOL_ERROR, self::UNSOURCED_NUMBER,
        self::POLICY_DENIED, self::BUDGET_EXCEEDED, self::QUOTA_EXCEEDED,
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
        self::MALFORMED_MODEL_RESPONSE => 'وصل ردٌّ لا يُطابق عقدَ البوّابة — ولم يُعرَض شيءٌ منه.',
        self::MODEL_NO_OUTPUT        => 'أجاب النموذجُ بلا مخرَجٍ البتّة. أعِد السؤالَ أو جرّب غرضاً آخرَ للتوجيه.',
        self::MODEL_REASONED_ONLY    => 'أنفق النموذجُ مخرَجَه في التفكيرِ ولم يُخرج جواباً. ارفع سقفَ رموزِ المخرَجِ أو ضيِّق السؤال.',
        self::TOOL_PROTOCOL_ERROR    => 'ردُّ النموذجِ يُناقض نفسَه في طلبِ القراءة — لم يُنفَّذ شيء.',
        self::UNSOURCED_NUMBER       => 'ذكر الجوابُ رقماً بلا قراءةٍ من Hub — فحُجب. الأرقامُ تُقرَأ ولا تُخمَّن.',
        self::TIMEOUT                => 'انقضت المهلةُ قبل اكتمالِ الجواب.',
        self::RATE_LIMITED           => 'تجاوزتَ حدَّ الطلبات. انتظر قليلاً ثمّ أعِد السؤال.',
        self::PROVIDER_CREDITS       => 'نفد رصيدُ الحسابِ عند المزوّد — ولا يُصلحه الانتظار. راجِع الإدارةَ لشحنِ الرصيد.',
        self::MODEL_UNAVAILABLE      => 'النموذجُ المختارُ غيرُ صالحٍ الآن. جرّب غرضاً آخرَ للتوجيه.',
        self::POLICY_DENIED          => 'منعت سياسةُ الذكاءِ هذه العمليّة. راجِع الإدارةَ لتعديلِ السياسة.',
        self::BUDGET_EXCEEDED        => 'بلغت ميزانيّةُ الذكاءِ سقفَها لهذه الفترة.',
        self::QUOTA_EXCEEDED         => 'بلغت حصّةُ الاستعمالِ سقفَها لهذه الفترة.',
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
