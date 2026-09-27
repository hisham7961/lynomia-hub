<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Support\Workforce\LeaveDecision;
use Illuminate\Http\Request;

/**
 * **قرارُ طلب الإجازة** (الجولة 1 · F5) — كان الحسمُ يتمّ بتحرير السجلّ الخام
 * (فتح النموذج وتغيير حقل «الحالة» يدوياً) بلا أزرارٍ ولا سببِ رفضٍ ملزَمٍ ولا
 * إشعارٍ لصاحب الطلب (وكيلا المحاكاة 1 و5). هنا سكّةُ القرار الصريحة:
 *
 *  - **مدير الموظّف** (mgr_id على الطلب، أو employees.manager_id): «موافقة المدير»
 *    أو «مرفوض» — لا يعتمد نهائيّاً.
 *  - **الموارد البشريّة** (hr:e) أو حاملُ راية approve أو المالك: «معتمد» أو «مرفوض».
 *  - الرفض يُلزم سبباً؛ وصاحبُ الطلب لا يقرّر في طلبِ نفسِه.
 *
 * الخصمُ من الرصيد واستعادتُه يبقيان في نموذج LeaveRequest (idempotent) —
 * هذه السكّةُ واجهةُ قرارٍ فوق المحرّك القائم، لا محرّكٌ ثانٍ.
 */
class LeaveDecisionController extends Controller
{
    /** الحالات التي ما زالت بانتظار قرار — مصدرُها السلطةُ الواحدة `LeaveDecision` */
    public const PENDING = LeaveDecision::PENDING;

    /**
     * **هل يملك هذا المستخدمُ أن يمسَّ حالةَ هذا الطلبِ أصلاً؟** — تفويضٌ للسلطة الواحدة
     * (`LeaveDecision::mayDecideOn`) يبقى هنا بتوقيعه كي لا يتغيّر مستهلكوه (`DecisionFields`).
     */
    public static function mayDecideOn($u, LeaveRequest $m): bool
    {
        return LeaveDecision::mayDecideOn($u, $m);
    }

    /** صلاحيّات المستخدم تجاه طلبٍ بعينه — تُستهلك هنا وفي واجهة الأزرار */
    public static function abilities($u, LeaveRequest $m): array
    {
        return LeaveDecision::abilities($u, $m);
    }

    /**
     * بابُ الويب: التحقّقُ وشكلُ الردّ (تحويلٌ برسالة) هنا، والسلسلةُ نفسُها في
     * `LeaveDecision::decide` — يسلكها الجوالُ حرفاً (`MobileLeavesController`).
     */
    public function decide(Request $r, string $id)
    {
        $u = auth()->user();
        $m = LeaveDecision::findScoped($u, $id);

        $d = $r->validate([
            'decision' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ], [], ['decision' => 'القرار', 'note' => 'السبب']);

        $res = LeaveDecision::decide($u, $m, $d['decision'], $d['note'] ?? null);

        if (! $res['ok']) {
            // سببُ الرفضِ الناقص خطأُ حقلٍ في النموذج (كما كان) — والباقي رفضٌ صريح
            if ($res['reason'] === LeaveDecision::R_REASON) {
                return back()->withErrors(['note' => $res['msg']])->withInput();
            }
            abort($res['http'], $res['msg']);
        }

        return back()->with('ok', $res['msg']);
    }
}
