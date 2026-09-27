<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityRadar;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * وسيطُ الرادار: يلتقط **محاولاتِ الوصول المرفوضة** بعد أن تُقرَّر.
 *
 * ٤٠٣ (ممنوع): طرقٌ على مسارٍ خارج الصلاحية — تصعيدٌ أو تقصٍّ. و٤٠٤ على روابط
 * التوقيع/التحقّق العامّة (`sign/*`/`verify/*`): تخمينُ رمزِ رابطٍ من غير مستخدم.
 *
 * **`abort(403)` يرمي استثناءً لا يعيد رداً**: يمرّ عبر `$next()` رمياً، ويُصيَّر
 * رداً عند نواة النظام لا هنا — فلا يكفي فحصُ حالة الرد. نلتقط الاستثناءَ ونُعيد
 * رميَه كما هو (فلا يتغيّر ردُّ المنع)، ونفحص الحالةَ للردود التي تُعاد مباشرةً 403.
 * التسجيلُ يفشل مفتوحاً دائماً: لا يمسّ المنعَ ولا يُسقط الطلب.
 */
class AccessRadar
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (HttpExceptionInterface $e) {
            // **والسببُ في اليد فلا يُرمى** (L2-10): الاستثناءُ يحمل رسالةَ المنع
            $this->capture($request, $e->getStatusCode(), $e->getMessage());

            throw $e;   // نُعيد الرمي: ردُّ المنع كما كان بلا تغيير
        }

        try {
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            /*
             * **وهذا هو المسارُ الحيُّ لا مسارُ الاستثناء** (L2-10). `abort(403, '…')`
             * يرفع استثناءً، لكنّ `Illuminate\Routing\Pipeline` **يُصيّره رداً
             * داخل خطِّ الأنابيب** قبل أن يبلغ الوسائطَ المحيطة — فمَقطعُ
             * `catch` أعلاه لا يُنفَّذ في المنعِ العاديِّ إطلاقاً. وقياسُه: بعد
             * تمريرِ الرسالةِ في المقطعِ هناك وحدَه بقي `detail` فارغاً.
             *
             * ولاراﭬل تُعلّق الاستثناءَ على ردِّه (`Pipeline::handleException` →
             * `withException`)، فالسببُ يُقرأ من الردّ لا من الرمي.
             */
            $why = (is_object($response) && property_exists($response, 'exception')
                && $response->exception instanceof \Throwable)
                ? $response->exception->getMessage() : null;
            $this->capture($request, $status, $why);
        } catch (\Throwable $e) {
            // رادارٌ معطوب لا يُسقط الطلب
        }

        return $response;
    }

    /**
     * يسجّل المنعَ حسب الحالة — ٤٠٣ دائماً، و٤٠٤ على روابط عامّة بعينها.
     *
     * **ومعه سببُه** (L2-10 · المراجعةُ الشاملة · الطبقة ٢). قِيس على قاعدةِ
     * المحاكاة: **٣٣٧٣ منعاً، كلُّها `kind = 'وصول مرفوض'` و`detail = NULL`** —
     * مئةٌ بالمئة. فعشرةُ مواضعَ أخرى تُمرّر تفصيلاً غنيّاً (دفاعُ IP · طردُ
     * الجلسة · توقيعُ الجهازِ الطرفيّ · API بلا مفتاح) وهي التي لا تكاد تُنتج
     * صفّاً، **وهذا الموضعُ وحدَه يُنتج الحجمَ كلَّه ولا يقول شيئاً**.
     *
     * والسببُ كان في اليدِ ويُرمى: `abort(403, '…')` يرفع استثناءً **رسالتُه هي
     * سببُ المنع**، و٢٢٣ موضعَ `abort_if`/`abort_unless` في التطبيق تحمل رسائلَ
     * صريحة — «لا تملك صلاحية على هذه الوحدة» · «عميلٌ خارجَ نطاقك» · «ليست لديك
     * صلاحية رؤية الأسرار». وكانت `capture` تستقبل رمزَ الحالةِ وحدَه.
     *
     * فالمالكُ يفتح مركزَ الأمان بعد حادثةٍ فيقرأ آلافَ الأسطرِ المتطابقة ولا
     * يعرف **أيُّ قاعدةٍ منعت**: صلاحيّةٌ ناقصةٌ أم نطاقٌ أم عزلُ شركةٍ أم
     * تصعيدُ هويّة. وسجلٌّ لا يميّز لا يُحقَّق فيه.
     *
     * والرسالةُ تمرّ بـ`Redactor::text` في `SecurityRadar::record` كسائرِ الحقول.
     */
    protected function capture(Request $request, int $status, ?string $why = null): void
    {
        try {
            if ($status === 403) {
                // رسالةٌ فارغة (`abort(403)` بلا نصّ) تبقى `null` لا سلسلةً خاوية
                SecurityRadar::record($request, 'وصول مرفوض', trim((string) $why) !== '' ? $why : null);
            } elseif ($status === 404 && $request->isMethod('get') && $request->is('sign/*', 'verify/*')) {
                // رمزُ رابطٍ عامٍّ غير موجود — تخمينُ رابط توقيعٍ من غير مستخدم
                SecurityRadar::record($request, 'تخمين رابط');
            }
        } catch (\Throwable $e) {
            // يفشل مفتوحاً
        }
    }
}
