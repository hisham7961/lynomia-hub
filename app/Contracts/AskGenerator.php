<?php

namespace App\Contracts;

/**
 * **منفذُ التوليد** — الحدُّ بين Ask Hub وأيِّ نموذجٍ كان. (المرحلة ٣ · P3-W5)
 *
 * **ولا اسمَ مزوّدٍ ولا نموذجٍ في هذا العقد.** المنسّقُ يعرف أنّه يسأل «مولِّداً»
 * ويتلقّى إمّا **طلبَ قراءةٍ** وإمّا **جواباً** وإمّا **خطأً مصنَّفاً** — ولا
 * يعرف من وراءَه ولا كيف وصل إليه. والتوجيهُ إلى مزوّدٍ بعينِه شأنُ
 * `AiProfiles`/`AiRouteRun` من المرحلةِ الثانية، لا شأنُ هذا العقد.
 *
 * **والمولِّدُ لا يملك سلطةَ تنفيذ.** ما يعود منه **طلبٌ غيرُ موثوق**: اسمُ
 * الأداةِ ووسائطُها يُعادُ التحقّقُ منهما في الخادمِ من الصفرِ قبل أن يُنفَّذ
 * شيء. فطاعةُ النموذجِ لحقنٍ كاملةً لا تفتح باباً — الأبوابُ ليست عنده.
 *
 * والافتراضُ `NullAskGenerator`: **لا توليدَ حقيقيٌّ حتى يقرّر المالك**.
 */
interface AskGenerator
{
    /**
     * خطوةٌ واحدة: إمّا طلبُ قراءةٍ وإمّا جوابٌ نهائيّ.
     *
     * @param  string  $envelope  مظروفُ السياقِ كاملاً (`AskContext::render()`)
     * @param  array   $tools     كتالوجُ الأدواتِ **لهذا المستخدمِ وحدَه**
     * @param  array   $history   خطواتُ هذا الطلبِ السابقة (بلا تفكيرٍ مخزَّن)
     * @return array{kind: string, tool?: ?string, args?: array, answer?: ?string,
     *               sources?: array, code?: ?string, usage?: array}
     *               `kind` ∈ `tool` · `answer` · `error`
     */
    public function step(string $envelope, array $tools, array $history): array;

    /** أهذا المولِّدُ قادرٌ على توليدٍ حقيقيّ؟ — الصدقُ على الشاشةِ يبدأ هنا */
    public function isLive(): bool;

    /** اسمٌ وصفيٌّ للتدقيق — لا سرَّ فيه ولا مفتاح */
    public function label(): string;
}
