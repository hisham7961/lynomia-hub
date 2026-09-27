<?php

namespace App\Support\Workforce;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * **قرارُ طلب الإجازة — السلطةُ الواحدة للويب والجوال.**
 *
 * استُخرج من `LeaveDecisionController` (الجولة 1 · F5) حرفاً بحرف كي يسلك الجوالُ
 * (`POST /api/mobile/v1/leaves/{id}/decide`) **السلسلةَ نفسَها** لا نسخةً ثانيةً تنحرف:
 *
 *  - **مدير الموظّف** (mgr_id على الطلب، أو employees.manager_id): «موافقة المدير»
 *    أو «مرفوض» — لا يعتمد نهائيّاً.
 *  - **الموارد البشريّة** (hr:e) أو حاملُ راية approve أو المالك: «معتمد» أو «مرفوض».
 *  - الرفض يُلزم سبباً؛ وصاحبُ الطلب لا يقرّر في طلبِ نفسِه؛ ولا قرارَ فوق قرار.
 *
 * الخصمُ من الرصيد واستعادتُه يبقيان في نموذج LeaveRequest (idempotent). والبابان
 * (الويبُ والجوال) يختلفان في **شكلِ الردّ** وحدَه: `decide()` يعيد نتيجةً دلاليّةً
 * (`reason` آليّ + `http` + رسالةٌ عربيّة) يترجمها كلُّ بابٍ إلى لغته.
 */
final class LeaveDecision
{
    /** الحالات التي ما زالت بانتظار قرار */
    public const PENDING = ['مقدّم', 'مقدم', 'موافقة المدير', 'موافقة الموارد البشرية'];

    /** أسبابُ الرفضِ الآليّة (يتفرّع عليها العميلُ لا على النصّ) */
    public const R_DECIDED = 'already_decided';
    public const R_SELF = 'self_request';
    public const R_NOT_PARTY = 'not_decider';
    public const R_REASON = 'reason_required';

    /**
     * الأطرافُ الثلاثةُ تجاه طلبٍ بعينه: **صاحبُه** · **مديرُه** · **الموارد
     * البشريّة** (ومن يعلوها: المالكُ وحاملُ رايةِ الاعتماد).
     */
    public static function parties($u, LeaveRequest $m): array
    {
        $uid = (string) $u->id;
        $requester = null;
        if ($m->emp_id) {
            $requester = Employee::whereKey($m->emp_id)->value('user_id');
        }

        return [
            'self' => $requester !== null && (string) $requester === $uid,
            'mgr' => ((string) $m->mgr_id === $uid)
                || ($m->emp_id && (string) Employee::whereKey($m->emp_id)->value('manager_id') === $uid),
            'hr' => hub_is_owner($u) || hub_flag($u, 'approve') || hub_can($u, 'hr', 'e'),
        ];
    }

    /** هل يملك هذا المستخدمُ أن يمسَّ حالةَ هذا الطلبِ أصلاً؟ (صلاحيّةٌ لا تسلسل) */
    public static function mayDecideOn($u, LeaveRequest $m): bool
    {
        if (! $u) return false;
        $p = self::parties($u, $m);

        return ! $p['self'] && ($p['mgr'] || $p['hr']);
    }

    /** صلاحيّات المستخدم تجاه طلبٍ بعينه — تُستهلك في القرار وفي واجهة الأزرار */
    public static function abilities($u, LeaveRequest $m): array
    {
        $p = self::parties($u, $m);
        $isSelf = $p['self'];
        $pending = in_array((string) $m->status, self::PENDING, true);

        return [
            'pending' => $pending,
            'self' => $isSelf,
            // المديرُ يوصي ما دام الطلبُ عند مرحلته؛ وHR يحسم من أيّ حالةٍ معلّقة
            'mgr_approve' => $pending && ! $isSelf && $p['mgr']
                && in_array((string) $m->status, ['مقدّم', 'مقدم'], true),
            'final_approve' => $pending && ! $isSelf && $p['hr'],
            'reject' => $pending && ! $isSelf && ($p['mgr'] || $p['hr']),
        ];
    }

    /**
     * **الطلبُ بنطاقِ القارئ** — بوّابةُ الرؤية (`leaves:v` + `hub_scope`). خارجَ النطاق ٤٠٤.
     */
    public static function findScoped(User $u, string $id): LeaveRequest
    {
        abort_unless(hub_can($u, 'leaves', 'v'), 403, 'لا تملك عرض الإجازات');

        /** @var LeaveRequest */
        return hub_scope(LeaveRequest::query()->whereNull('deleted_at'), 'leaves', $u)->findOrFail($id);
    }

    /**
     * **القرار.** يعيد `['ok'=>true, 'status'=>…, 'was'=>…, 'msg'=>…]` أو
     * `['ok'=>false, 'http'=>403|422, 'reason'=>R_*, 'msg'=>…]` — ولا يكتب شيئاً عند الرفض.
     */
    public static function decide(User $u, LeaveRequest $m, string $decision, ?string $note): array
    {
        $note = filled($note) ? (string) $note : null;
        $ab = self::abilities($u, $m);

        if (! $ab['pending']) {
            return self::deny(422, self::R_DECIDED, 'هذا الطلب محسومٌ أصلاً — لا قرارَ فوق قرار');
        }
        if ($ab['self']) {
            return self::deny(403, self::R_SELF, 'لا يُقرَّر في طلبِ النفس — يقرّر مديرُك أو الموارد البشرية');
        }

        if ($decision === 'reject') {
            if (! $ab['reject']) {
                return self::deny(403, self::R_NOT_PARTY, 'قرارُ هذا الطلب لمدير الموظّف أو الموارد البشرية');
            }
            if ($note === null) {
                return self::deny(422, self::R_REASON, 'سببُ الرفض مطلوب — يقرؤه صاحبُ الطلب');
            }
            $new = 'مرفوض';
        } elseif ($ab['final_approve']) {
            $new = 'معتمد';
        } elseif ($ab['mgr_approve']) {
            $new = 'موافقة المدير';
        } else {
            return self::deny(403, self::R_NOT_PARTY, 'قرارُ هذا الطلب لمدير الموظّف أو الموارد البشرية');
        }

        $was = (string) $m->status;
        $m->status = $new;
        if ($note !== null) $m->note = $note;
        $m->save();   // خصمُ الرصيد/استعادتُه في نموذج LeaveRequest نفسه

        hub_audit('قرار إجازة', 'leaves', $m->id, (string) ($m->type ?: 'طلب'),
            ['before' => ['الحالة' => $was], 'after' => ['الحالة' => $new, 'ملاحظة' => $note ?? '—']]);

        // صاحبُ الطلب يُخبَر بالقرار — لا يكتشفه صدفةً من الجدول
        if ($m->emp_id && ($ruid = Employee::whereKey($m->emp_id)->value('user_id'))) {
            hub_notify($ruid, 'leave',
                ($new === 'مرفوض' ? '❌ رُفض طلبك: ' : ($new === 'معتمد' ? '✅ اعتُمد طلبك: ' : '👍 وافق مديرُك على طلبك: '))
                . ($m->type ?: 'إجازة')
                . ($note !== null ? ' — ' . \Illuminate\Support\Str::limit($note, 120) : ''),
                'leaves', $m->id);
        }

        \App\Support\Platform\FlowRunner::fire('status', 'leaves', $m, $new);

        return ['ok' => true, 'status' => $new, 'was' => $was,
            'msg' => $new === 'مرفوض' ? 'رُفض الطلب وأُخطر صاحبُه بالسبب'
                : ($new === 'معتمد' ? 'اعتُمد الطلب — خُصم من الرصيد إن كان غياباً' : 'سُجّلت موافقتُك — بقي اعتمادُ الموارد البشرية')];
    }

    private static function deny(int $http, string $reason, string $msg): array
    {
        return ['ok' => false, 'http' => $http, 'reason' => $reason, 'msg' => $msg];
    }
}
