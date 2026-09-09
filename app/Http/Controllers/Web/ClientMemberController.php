<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Support\ClientMembers;
use Illuminate\Http\Request;

/**
 * **إدارةُ عضويّة العميل** (Work OS · الطور B · WP-B.3 · §13/§98) — لوحةٌ **داخليّةٌ**
 * على «عميل ٣٦٠» يديرها مديرُ الحساب: يمنح دوراً، ويدعو زميلاً للتفعيل، ويسحب الوصول.
 *
 * ── لا RBAC ثانٍ: الصلاحيةُ = `hub_can('clients','e')` للمدير + بياناتُ
 * `client_memberships` لما يملكه العميل.
 * ── **الجوهرُ صار مصدراً واحداً مشترَكاً** (`App\Support\ClientMembers`) منذ هبوطِ
 * تطبيق الجوال: السطحان (هذه اللوحةُ و`/api/mobile/v1/clients/{client}/members`)
 * يستدعيان منطقَ الدعوة/الدور/السحب نفسَه حرفاً — لا سكّةَ تفعيلٍ ولا عضويّةٍ ثانية.
 * ── لا سكّةَ عزلٍ ثانية: `PortalGuard` يردّ حسابَ العميل عن هذه المسارات (٤٠٤)،
 * و`hub_scope` يحصر المديرَ في عملائه.
 *
 * منحُ **Client Owner** وسحبُ الوصول فعلان عاليا الخطورة: `hub_require_stepup`.
 */
class ClientMemberController extends Controller
{
    /** يحسم العميلَ الهدفَ محروساً: `clients:e` ثم النطاق وإلا ٤٠٤ */
    private function manageClient(string $clientId): Client
    {
        abort_unless(hub_can(auth()->user(), 'clients', 'e'), 403, 'إدارةُ أعضاء العميل تتطلّب صلاحيةَ تعديل العملاء');

        return hub_scope(Client::query(), 'clients')->whereNull('deleted_at')->findOrFail($clientId);
    }

    /** **الدعوة** — حسابُ عميلٍ بلا كلمةِ سرّ + عضويّة + تفعيلٌ على سكّة B.1 */
    public function invite(Request $r, string $client)
    {
        $clientRow = $this->manageClient($client);

        $data = $r->validate([
            'email' => ['required', 'email', 'max:190'],
            'name'  => ['nullable', 'string', 'max:120'],
            'role'  => ['required', 'string', 'in:' . implode(',', ClientMembership::ROLES)],
        ]);

        // منحُ Client Owner = تصعيدُ هوية (قبل أيّ كتابة)
        if ($data['role'] === 'owner' && ($resp = $this->stepUp($client))) {
            return $resp;
        }

        try {
            $membership = ClientMembers::invite(
                $clientRow, $data['email'], $data['name'] ?? null, $data['role'], auth()->user());
        } catch (\Illuminate\Database\QueryException $e) {
            // سباقُ دعوتين متزامنتين على القيد الفريد — رسالةٌ ناعمة لا ٥٠٠
            report($e);

            return back()->with('warn', 'هذا الزميلُ عضوٌ بالفعل — حُدِّثت دعوتُه.');
        }

        ClientMembers::recordEvent('granted', 'منح عضويّة عميل', $clientRow, $membership);

        return back()->with('ok', 'دُعي الزميلُ إلى مساحة العميل — رسالةُ تفعيلٍ في الطريق، ويضع كلمتَه بنفسه (لا كلمةَ سرٍّ تُرسَل).');
    }

    /** **تغييرُ الدور** — الرفعُ إلى Client Owner يتطلّب تصعيداً؛ ما دونه لا */
    public function setRole(Request $r, string $client, string $membership)
    {
        $clientRow = $this->manageClient($client);
        $data = $r->validate(['role' => ['required', 'string', 'in:' . implode(',', ClientMembership::ROLES)]]);

        if ($data['role'] === 'owner' && ($resp = $this->stepUp($client))) {
            return $resp;
        }

        $before = ClientMembership::where('client_id', $clientRow->id)->whereKey($membership)->value('role');
        $m = ClientMembers::setRole($clientRow, $membership, $data['role']);
        if ($before !== $m->role) {
            ClientMembers::recordEvent('granted', 'تغيير دور عضويّة عميل', $clientRow, $m);
        }

        return back()->with('ok', 'حُدِّث دورُ العضو.');
    }

    /** **سحبُ الوصول** — تصعيدٌ دائماً؛ تعليقٌ لا حذف */
    public function revoke(Request $r, string $client, string $membership)
    {
        $clientRow = $this->manageClient($client);

        if ($resp = $this->stepUp($client)) {
            return $resp;
        }

        $m = ClientMembers::revoke($clientRow, $membership);
        ClientMembers::recordEvent('revoked', 'سحب عضويّة عميل', $clientRow, $m);

        return back()->with('ok', 'سُحب وصولُ العضو — يسقط عن نطاق العميل فوراً.');
    }

    /** بوابةُ تصعيد الهوية — تعود إلى صفحة العميل (GET) بعد التأكيد */
    private function stepUp(string $client)
    {
        return hub_require_stepup(route('m.show', ['clients', $client], absolute: false));
    }
}
