<?php

namespace App\Http\Middleware;

use App\Support\SecurityRadar;
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
            $this->capture($request, $e->getStatusCode());

            throw $e;   // نُعيد الرمي: ردُّ المنع كما كان بلا تغيير
        }

        try {
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            $this->capture($request, $status);
        } catch (\Throwable $e) {
            // رادارٌ معطوب لا يُسقط الطلب
        }

        return $response;
    }

    /** يسجّل المنعَ حسب الحالة — ٤٠٣ دائماً، و٤٠٤ على روابط عامّة بعينها */
    protected function capture(Request $request, int $status): void
    {
        try {
            if ($status === 403) {
                SecurityRadar::record($request, 'وصول مرفوض');
            } elseif ($status === 404 && $request->isMethod('get') && $request->is('sign/*', 'verify/*')) {
                // رمزُ رابطٍ عامٍّ غير موجود — تخمينُ رابط توقيعٍ من غير مستخدم
                SecurityRadar::record($request, 'تخمين رابط');
            }
        } catch (\Throwable $e) {
            // يفشل مفتوحاً
        }
    }
}
