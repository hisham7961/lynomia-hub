<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
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
    /** الحالات التي ما زالت بانتظار قرار */
    public const PENDING = ['مقدّم', 'مقدم', 'موافقة المدير', 'موافقة الموارد البشرية'];

    /** صلاحيّات المستخدم تجاه طلبٍ بعينه — تُستهلك هنا وفي واجهة الأزرار */
    public static function abilities($u, LeaveRequest $m): array
    {
        $uid = (string) $u->id;
        $requester = null;
        if ($m->emp_id) {
            $requester = \App\Models\Employee::whereKey($m->emp_id)->value('user_id');
        }
        $isSelf = $requester !== null && (string) $requester === $uid;

        $isMgr = ((string) $m->mgr_id === $uid)
            || ($m->emp_id && (string) \App\Models\Employee::whereKey($m->emp_id)->value('manager_id') === $uid);
        $isHr = hub_is_owner($u) || hub_flag($u, 'approve') || hub_can($u, 'hr', 'e');

        $pending = in_array((string) $m->status, self::PENDING, true);

        return [
            'pending' => $pending,
            'self' => $isSelf,
            // المديرُ يوصي ما دام الطلبُ عند مرحلته؛ وHR يحسم من أيّ حالةٍ معلّقة
            'mgr_approve' => $pending && ! $isSelf && $isMgr
                && in_array((string) $m->status, ['مقدّم', 'مقدم'], true),
            'final_approve' => $pending && ! $isSelf && $isHr,
            'reject' => $pending && ! $isSelf && ($isMgr || $isHr),
        ];
    }

    public function decide(Request $r, string $id)
    {
        $u = auth()->user();
        abort_unless(hub_can($u, 'leaves', 'v'), 403, 'لا تملك عرض الإجازات');

        /** @var LeaveRequest $m */
        $m = hub_scope(LeaveRequest::query()->whereNull('deleted_at'), 'leaves')->findOrFail($id);

        $d = $r->validate([
            'decision' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ], [], ['decision' => 'القرار', 'note' => 'السبب']);

        $ab = self::abilities($u, $m);
        abort_unless($ab['pending'], 422, 'هذا الطلب محسومٌ أصلاً — لا قرارَ فوق قرار');
        abort_if($ab['self'], 403, 'لا يُقرَّر في طلبِ النفس — يقرّر مديرُك أو الموارد البشرية');

        if ($d['decision'] === 'reject') {
            abort_unless($ab['reject'], 403, 'قرارُ هذا الطلب لمدير الموظّف أو الموارد البشرية');
            if (blank($d['note'] ?? null)) {
                return back()->withErrors(['note' => 'سببُ الرفض مطلوب — يقرؤه صاحبُ الطلب'])->withInput();
            }
            $new = 'مرفوض';
        } elseif ($ab['final_approve']) {
            $new = 'معتمد';
        } elseif ($ab['mgr_approve']) {
            $new = 'موافقة المدير';
        } else {
            abort(403, 'قرارُ هذا الطلب لمدير الموظّف أو الموارد البشرية');
        }

        $was = (string) $m->status;
        $m->status = $new;
        if (filled($d['note'] ?? null)) $m->note = $d['note'];
        $m->save();   // خصمُ الرصيد/استعادتُه في نموذج LeaveRequest نفسه

        hub_audit('قرار إجازة', 'leaves', $m->id, (string) ($m->type ?: 'طلب'),
            ['before' => ['الحالة' => $was], 'after' => ['الحالة' => $new, 'ملاحظة' => $d['note'] ?? '—']]);

        // صاحبُ الطلب يُخبَر بالقرار — لا يكتشفه صدفةً من الجدول
        if ($m->emp_id && ($ruid = \App\Models\Employee::whereKey($m->emp_id)->value('user_id'))) {
            hub_notify($ruid, 'leave',
                ($new === 'مرفوض' ? '❌ رُفض طلبك: ' : ($new === 'معتمد' ? '✅ اعتُمد طلبك: ' : '👍 وافق مديرُك على طلبك: '))
                . ($m->type ?: 'إجازة')
                . ($d['note'] ?? null ? ' — ' . \Illuminate\Support\Str::limit($d['note'], 120) : ''),
                'leaves', $m->id);
        }

        \App\Support\FlowRunner::fire('status', 'leaves', $m, $new);

        return back()->with('ok', $new === 'مرفوض' ? 'رُفض الطلب وأُخطر صاحبُه بالسبب'
            : ($new === 'معتمد' ? 'اعتُمد الطلب — خُصم من الرصيد إن كان غياباً' : 'سُجّلت موافقتُك — بقي اعتمادُ الموارد البشرية'));
    }
}
