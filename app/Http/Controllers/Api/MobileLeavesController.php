<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileEndpoint;
use App\Support\Platform\Api;
use App\Support\Workforce\LeaveDecision;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **قرارُ الإجازة على الجوال** (خطّةُ التطبيق · المرحلة ٣ · 3.2).
 *
 * السلسلةُ نفسُها التي يسلكها زرُّ الويب (`m/leaves/{id}/decide`) — `LeaveDecision::decide`
 * (مستخرَجةٌ من `LeaveDecisionController` بلا تغيير سلوك): المديرُ يوصي («موافقة المدير»)،
 * والموارد البشريّة/المالك/حاملُ رايةِ الاعتماد يحسم («معتمد»)، والرفضُ بسببٍ إلزاميّ،
 * ولا قرارَ في طلبِ النفس ولا فوق قرار. الرؤيةُ `leaves:v` + `hub_scope` (خارجَ النطاق ٤٠٤).
 * حسابُ العميل محجوبٌ ببوّابة `mobile.portal` (المسارُ خارجَ قائمته البيضاء).
 */
class MobileLeavesController extends V1Controller
{
    use MobileEndpoint;

    /**
     * `GET leaves/{id}/decision` — **أهليّةُ زرِّ القرار بلا أثر**: هل يقرّر هذا القارئُ في هذا
     * الطلب الآن؟ من `LeaveDecision::abilities` نفسِها التي يحسم بها `decide` — والسببُ الآليُّ
     * بالترتيب نفسِه: already_decided ثم self_request ثم not_decider. البوّابةُ والنطاقُ
     * (`findScoped`: `leaves:v` ⇒ ٤٠٣، خارجَ النطاق ٤٠٤) كالفعل.
     */
    public function decision(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        $m = LeaveDecision::findScoped($u, $id);
        $ab = LeaveDecision::abilities($u, $m);

        $reason = match (true) {
            ! $ab['pending'] => LeaveDecision::R_DECIDED,
            $ab['self'] => LeaveDecision::R_SELF,
            ! $ab['reject'] => LeaveDecision::R_NOT_PARTY,   // `reject` = طرفٌ يقرّر (مديرٌ أو HR)
            default => null,
        };
        $canApprove = $ab['final_approve'] || $ab['mgr_approve'];

        return $this->okData([
            'id' => (string) $m->id,
            'status' => (string) $m->status,
            'can_decide' => $reason === null,
            'reason' => $reason,
            'can_approve' => $canApprove,
            'can_reject' => (bool) $ab['reject'],
            // ما يصير إليه الطلبُ بالموافقة: HR يحسم «معتمد»، والمديرُ يوصي «موافقة المدير»
            'approve_status' => $ab['final_approve'] ? 'معتمد' : ($ab['mgr_approve'] ? 'موافقة المدير' : null),
            'reject_reason_required' => true,
        ]);
    }

    /**
     * `POST leaves/{id}/decide` — `{decision: approve|reject, reason}` (السببُ إلزاميٌّ للرفض).
     * الرفضُ الدلاليُّ يحمل `details.reason` آليّاً: already_decided | self_request |
     * not_decider | reason_required.
     */
    public function decide(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        $m = LeaveDecision::findScoped($u, $id);

        $d = $r->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
        ], [], ['decision' => 'القرار', 'reason' => 'السبب']);

        return $this->idempotently($r, function () use ($u, $m, $d) {
            $res = LeaveDecision::decide($u, $m, $d['decision'], $d['reason'] ?? null);

            if (! $res['ok']) {
                $code = match ($res['reason']) {
                    LeaveDecision::R_REASON => Api::VALIDATION_FAILED,
                    LeaveDecision::R_DECIDED => Api::BUSINESS_RULE_VIOLATION,
                    default => Api::FORBIDDEN,
                };
                $legacy = $res['reason'] === LeaveDecision::R_REASON ? ['errors' => ['reason' => [$res['msg']]]] : [];

                return Api::error($code, (int) $res['http'], (string) $res['msg'], ['reason' => $res['reason']], $legacy);
            }

            return $this->okData([
                'id' => (string) $m->id,
                'status' => $res['status'],
                'previous_status' => $res['was'],
                'decision' => $d['decision'],
                'message' => $res['msg'],
            ]);
        });
    }
}
