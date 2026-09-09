<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\MobileSession;
use App\Support\Api;
use App\Support\ClientMembers;
use App\Support\MobileSessionService;
use App\Support\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **إدارةُ أعضاء العميل على الجوال** (تطبيق العميل · §15) — لوحةُ مديرِ الحساب
 * **الداخليّ** (نظيرُ `ClientMemberController` الويبيّ) على سطح
 * `/api/mobile/v1/clients/{client}/members`: تعدادٌ ودعوةٌ ودورٌ وسحب، عبر
 * **الجوهر المشترك الواحد** (`App\Support\ClientMembers`) — لا سكّةَ عضويّةٍ
 * ولا تفعيلٍ ثانية.
 *
 * الحراسة: `hub_can('clients','e')` + `hub_scope` (عميلٌ خارج نطاق المدير 404)،
 * و`MobilePortalGuard` يردّ حسابَ العميل عن هذه المسارات قبل المتحكّم (منعٌ فوق
 * المصفوفة). الفعلان العاليا الخطورة — منحُ Client Owner وسحبُ الوصول — خلف
 * **تصعيدِ الجوال** (مِنحُ `mobile_stepup_grants` بغرضٍ مسمّى · §41) نظيرَ
 * `hub_require_stepup` الويبيّ. الدعوةُ أثرٌ قابلٌ لإعادة المحاولة ⇒
 * `Idempotency-Key` (مالكُ الجوال · وراثةُ آلة `V1Controller`).
 */
class MobileClientMembersController extends V1Controller
{
    /** أغراضُ التصعيد المسمّاة — مربوطةٌ بالفعل لا مِنحةٌ عامة (§41) */
    public const STEPUP_OWNER = 'action:clients:member_owner';

    public const STEPUP_REVOKE = 'action:clients:member_revoke';

    /** `GET clients/{client}/members` — أعضاءُ العميل بأدوارهم وحالاتهم */
    public function index(Request $r, string $client): JsonResponse
    {
        $this->tagMobileSource($r);
        $clientRow = $this->manageClient($client);

        return $this->okData([
            'client' => ['id' => (string) $clientRow->id, 'name' => (string) $clientRow->name],
            'roles' => ClientMembership::ROLES,
            'members' => ClientMembers::membersOf($clientRow)->map(fn ($m) => $this->memberShape($m))->values()->all(),
        ]);
    }

    /** `POST clients/{client}/members` — دعوةُ عضوٍ (سكّةُ B.1: لا كلمةَ سرٍّ تُرسَل) */
    public function invite(Request $r, string $client)
    {
        $this->tagMobileSource($r);
        $clientRow = $this->manageClient($client);

        $data = $r->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:' . implode(',', ClientMembership::ROLES)],
        ]);

        // منحُ Client Owner = تصعيدُ هوية قبل أيّ كتابة (نظيرُ الويب)
        if ($data['role'] === 'owner' && ($resp = $this->requireMemberStepUp($r, self::STEPUP_OWNER))) {
            return $resp;
        }

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            try {
                $membership = ClientMembers::invite(
                    $clientRow, $data['email'], $data['name'] ?? null, $data['role'], auth()->user());
            } catch (\Illuminate\Database\QueryException $e) {
                // سباقُ دعوتين على القيد الفريد — رسالةٌ ناعمةٌ لا ٥٠٠ (نظيرُ الويب)
                report($e);
                if ($gate === true) $this->idempotentRelease($r);

                return Api::error(Api::BUSINESS_RULE_VIOLATION, 422,
                    'هذا الزميلُ عضوٌ بالفعل — أعد تحميل القائمة');
            }

            ClientMembers::recordEvent('granted', 'منح عضويّة عميل', $clientRow, $membership);
            $resp = $this->okData(['member' => $this->memberShape($membership->fresh(['user']))]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /** `PUT clients/{client}/members/{membership}` — تغييرُ الدور (owner ⇒ تصعيد) */
    public function setRole(Request $r, string $client, string $membership)
    {
        $this->tagMobileSource($r);
        $clientRow = $this->manageClient($client);
        $data = $r->validate(['role' => ['required', 'string', 'in:' . implode(',', ClientMembership::ROLES)]]);

        if ($data['role'] === 'owner' && ($resp = $this->requireMemberStepUp($r, self::STEPUP_OWNER))) {
            return $resp;
        }

        $before = ClientMembership::where('client_id', $clientRow->id)->whereKey($membership)->value('role');
        $m = ClientMembers::setRole($clientRow, $membership, $data['role']);
        if ($before !== $m->role) {
            ClientMembers::recordEvent('granted', 'تغيير دور عضويّة عميل', $clientRow, $m);
        }

        return $this->okData(['member' => $this->memberShape($m->fresh(['user']))]);
    }

    /** `DELETE clients/{client}/members/{membership}` — سحبٌ (تعليقٌ) خلف تصعيدٍ دائماً */
    public function revoke(Request $r, string $client, string $membership)
    {
        $this->tagMobileSource($r);
        $clientRow = $this->manageClient($client);

        if ($resp = $this->requireMemberStepUp($r, self::STEPUP_REVOKE)) {
            return $resp;
        }

        $m = ClientMembers::revoke($clientRow, $membership);
        ClientMembers::recordEvent('revoked', 'سحب عضويّة عميل', $clientRow, $m);

        return $this->okData(['member' => $this->memberShape($m->fresh(['user']))]);
    }

    /* ────────── مساعِدات ────────── */

    /** يحسم العميلَ الهدفَ محروساً: `clients:e` وإلا 403، ثم النطاق وإلا 404 */
    private function manageClient(string $clientId): Client
    {
        if (! hub_can(auth()->user(), 'clients', 'e')) {
            abort(Api::error(Api::FORBIDDEN, 403, 'إدارةُ أعضاء العميل تتطلّب صلاحيةَ تعديل العملاء'));
        }

        return hub_scope(Client::query(), 'clients')->whereNull('deleted_at')->findOrFail($clientId);
    }

    /**
     * تصعيدُ الجوال المربوط بالغرض (§41): مِنحةٌ حيّةٌ للجلسة بالغرض المسمّى وإلا
     * `STEP_UP_REQUIRED` 428 بغرضٍ وطريقةٍ — التطبيقُ ينفّذ `auth/step-up` ثم يعيد.
     */
    private function requireMemberStepUp(Request $r, string $purpose)
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

    /** بطاقةُ عضويّةٍ آمنة — لا رمزَ تفعيلٍ ولا أثرَ كلمةِ سرٍّ (حضورُ التفعيل فقط) */
    private function memberShape(ClientMembership $m): array
    {
        return [
            'id' => (string) $m->id,
            'role' => (string) $m->role,
            'status' => (string) $m->status,
            'invited_at' => optional($m->invited_at)->toIso8601String(),
            'activated_at' => optional($m->activated_at)->toIso8601String(),
            'user' => [
                'id' => (string) $m->user_id,
                'name' => (string) ($m->user->name ?? ''),
                'email' => (string) ($m->user->email ?? ''),
                'activated' => ($m->user->password_changed_at ?? null) !== null,
            ],
        ];
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    private function okData(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — للتدقيق (وسمٌ لا تخويل) */
    private function tagMobileSource(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
