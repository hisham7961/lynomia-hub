<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\MobileSession;
use App\Support\Mobile\MobileSessionService;
use App\Support\Platform\Api;
use App\Support\Security\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **أدواتُ نقاطِ الجوال المشتركة** (المرحلة ٣ · أفعالُ الميدان والموظّف).
 *
 * نمطُ المتحكّمات القائمة (`MobileFileController`/`MobileClientMembersController`) مجموعاً في
 * موضعٍ واحدٍ للمتحكّمات الجديدة — لا سلوكٌ جديد:
 *  • `tagMobile` — وسمُ مصدرِ الطلب `mobile` (يقرؤه `hub_audit`؛ وسمٌ لا تخويل).
 *  • `okData` — غلافُ النجاح الموحَّد `{data, request_id}`.
 *  • `requireMobileStepUp` — تصعيدُ الجوال المربوط بالغرض (مِنحةُ `auth/step-up` الحيّة)،
 *    نظيرُ `hub_require_stepup` الويبيّ؛ وإلا `STEP_UP_REQUIRED` 428 بالغرض والطريقة.
 *  • `idempotently` — آلةُ `Idempotency-Key` الموروثةُ من `V1Controller` (مالكُ الجوال · F1):
 *    حجزٌ ⇒ تنفيذٌ ⇒ تخزينُ الردّ، وتحريرٌ عند الفشل — فلا تُضاعِف إعادةُ المحاولة الأثرَ.
 *
 * يُستعمل في أصنافٍ ترث `V1Controller` (مصدرُ `idempotentBegin/Finish/Release`).
 */
trait MobileEndpoint
{
    /** وسمُ مصدرِ الطلب `mobile` — للتدقيق (وسمٌ لا تخويل) */
    protected function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    protected function okData(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], $status);
    }

    /** تصعيدُ الجوال بغرضٍ مسمّى — null حين توجد مِنحةٌ حيّة للجلسة */
    protected function requireMobileStepUp(Request $r, string $purpose): ?JsonResponse
    {
        $session = $r->attributes->get('mobile_session');
        if ($session instanceof MobileSession && MobileSessionService::mobileStepUpFresh($session, $purpose)) {
            return null;
        }

        return Api::error(Api::STEP_UP_REQUIRED, 428,
            'هذا الإجراءُ يتطلّب تأكيدَ الهوية — نفّذ auth/step-up بالغرض المرفق ثم أعد المحاولة',
            ['purpose' => $purpose, 'method' => StepUp::method(auth()->user())]);
    }

    /**
     * تنفيذُ جانبٍ قابلٍ للإعادة تحت `Idempotency-Key` (إن أُرسل): الإعادةُ بالمفتاح نفسِه
     * تعيد الردَّ المخزَّن (`X-Idempotent-Replay`)، والفشلُ (استثناءٌ أو ردُّ خطأ) يحرّر الحجز.
     *
     * @param  \Closure(): Response  $fn
     */
    protected function idempotently(Request $r, \Closure $fn): Response
    {
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $resp = $fn();
            if ($resp->getStatusCode() >= 400) {
                // ردُّ رفضٍ لم يُحدث أثراً — لا يُخزَّن فيُحجَب به تصحيحُ الطلب بالمفتاح نفسِه
                if ($gate === true) $this->idempotentRelease($r);

                return $resp;
            }
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }
}
