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
