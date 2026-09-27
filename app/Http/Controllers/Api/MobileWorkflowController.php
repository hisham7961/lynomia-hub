<?php

namespace App\Http\Controllers\Api;

use App\Models\MobileSession;
use App\Support\Mobile\MobileSessionService;
use App\Support\Platform\Api;
use App\Support\Security\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **أساسُ متحكّماتِ سير العمل على الجوال** (خطّة التطبيق · المرحلة ٤) — مساعِداتٌ
 * مشتركةٌ لا قواعد: غلافُ النجاح الموحّد، وسمُ المصدر، طيُّ حساب العميل ٤٠٤ عن
 * السطوح الداخليّة، آلةُ `Idempotency-Key` الموروثة من `V1Controller` (مالكُ الجوال)
 * مغلّفةً، وتصعيدُ الجوال المربوطُ بالغرض (`mobile_stepup_grants` · §41).
 *
 * **القواعدُ نفسُها ليست هنا** — كلُّ متحكّمٍ فرعيٍّ يستدعي الخدمةَ المشتركةَ التي
 * يستدعيها الويبُ حرفاً (لا حارسَ ثانٍ ينحرف).
 */
abstract class MobileWorkflowController extends V1Controller
{
    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    protected function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], $status,
            [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** وسمُ مصدرِ الطلب `mobile` — للتدقيق (وسمٌ لا تخويل) */
    protected function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }

    /** السطحُ داخليّ — حسابُ العميل يُطوى ٤٠٤ (لا نكشف وجودَه · نظيرُ PortalGuard) */
    protected function denyClient(): ?Response
    {
        return hub_is_client(auth()->user())
            ? Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود')
            : null;
    }

    /**
     * **تنفيذٌ عديمُ الأثر** بمفتاح `Idempotency-Key` (اختياريّ): الإعادةُ بالمفتاح نفسِه
     * تعيد الردَّ المحفوظ (`X-Idempotent-Replay`) لا تنفيذاً ثانياً؛ والمفتاحُ مع جسمٍ مختلف
     * `IDEMPOTENCY_KEY_REUSED`. الردُّ الفاشل (≥400) لا يُحفظ — يُحرَّر الحجزُ فتصحّ الإعادة.
     *
     * @param \Closure(): Response $fn
     */
    protected function idempotent(Request $r, \Closure $fn): Response
    {
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof Response) return $gate;

        try {
            $resp = $fn();
            if ($resp->getStatusCode() < 400) {
                $this->idempotentFinish($r, $resp);
            } elseif ($gate === true) {
                $this->idempotentRelease($r);
            }

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * تصعيدُ الجوال المربوط بالغرض (§41): مِنحةٌ حيّةٌ للجلسة بالغرض المسمّى وإلا
     * `STEP_UP_REQUIRED` 428 بغرضٍ وطريقةٍ — التطبيقُ ينفّذ `auth/step-up` ثم يعيد.
     */
    protected function requireStepUp(Request $r, string $purpose): ?Response
    {
        $session = $r->attributes->get('mobile_session');
        if ($session instanceof MobileSession
            && MobileSessionService::mobileStepUpFresh($session, $purpose)) {
            return null;
        }

        return Api::error(Api::STEP_UP_REQUIRED, 428,
            'هذا الإجراءُ يتطلّب تأكيدَ الهوية — نفّذ auth/step-up بالغرض المرفق ثم أعد المحاولة',
            ['purpose' => $purpose, 'method' => StepUp::method(auth()->user())]);
    }

    /** تاريخٌ/وقتٌ بصيغة ISO-8601 أو null */
    protected static function iso($v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return ($v instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($v) : \Illuminate\Support\Carbon::parse((string) $v))
                ->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
