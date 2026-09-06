<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use App\Support\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **إدارةُ عضويّة العميل** (Work OS · الطور B · WP-B.3 · §13/§98) — لوحةٌ **داخليّةٌ**
 * على «عميل ٣٦٠» يديرها مديرُ الحساب: يُعدّد الأعضاءَ بأدوارهم، ويمنح دوراً، ويدعو
 * زميلاً للتفعيل، ويسحب الوصول.
 *
 * ── لا RBAC ثانٍ (§5/المرجع القاطع): الصلاحيةُ = مصفوفةُ الأدوار الداخلية
 * (`hub_can('clients','e')`) للمدير + بياناتُ `client_memberships` لما يملكه العميل.
 * ── لا سكّةَ تفعيلٍ ثانية: الدعوةُ تمرّ **حصراً** عبر `AccountActivation` (B.1) —
 * حسابُ عميلٍ **بلا كلمةِ سرٍّ** (نمط C8) ثم عضويّةٌ ثم تفعيلٌ يضع فيه العميلُ كلمتَه
 * بنفسه. لا كلمةَ سرٍّ تُولَّد/تُرسَل أبداً.
 * ── لا سكّةَ عزلٍ ثانية: `PortalGuard` يردّ حسابَ العميل عن هذه المسارات (٤٠٤)
 * فوق مصفوفة الأدوار، و`hub_scope` يحصر المديرَ في عملائه.
 *
 * منحُ **Client Owner** وسحبُ الوصول فعلان عاليا الخطورة: يتطلّبان `hub_require_stepup`
 * (تصعيدَ هوية)، ويُدخلان سلسلةَ التدقيق عبر حدثٍ دلاليّ + `hub_audit`.
 */
class ClientMemberController extends Controller
{
    /**
     * يحسم العميلَ الهدفَ محروساً: صلاحيةُ الإدارة الداخلية (`clients:e`) أولاً، ثم
     * العميلُ في نطاق المدير (`hub_scope`) وإلا ٤٠٤ (لا نُثبت وجودَ ما لا يخصّه).
     * حسابُ العميل نفسُه لا يبلغ هنا أصلاً — `PortalGuard` ردّه قبل المتحكّم.
     */
    private function manageClient(string $clientId): Client
    {
        abort_unless(hub_can(auth()->user(), 'clients', 'e'), 403, 'إدارةُ أعضاء العميل تتطلّب صلاحيةَ تعديل العملاء');

        return hub_scope(Client::query(), 'clients')->whereNull('deleted_at')->findOrFail($clientId);
    }

    /**
     * **الدعوة** — تُنشئ حسابَ عميلٍ بلا كلمةِ سرٍّ (إن لم يكن قائماً) + عضويّة + تفعيلاً
     * على سكّة B.1. البريدُ هو الهوية: بريدٌ لحسابٍ قائمٍ لا يُكرّر مستخدماً، وبريدٌ
     * لحسابٍ **داخليٍّ** يُرفَض (لا يُحوَّل موظفٌ داخليٌّ إلى عميلٍ خارجيّ).
     */
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

        $email = mb_strtolower(trim($data['email']));
        $name  = trim((string) ($data['name'] ?? '')) ?: Str::before($email, '@');

        try {
            $membership = DB::transaction(function () use ($clientRow, $email, $name, $data) {
                $existing = User::where('email', $email)->first();
                if ($existing && ! $existing->isClientAccount()) {
                    abort(422, 'هذا البريدُ لحسابٍ داخليّ — لا يُدعى كعضوٍ عميل');
                }

                if ($existing) {
                    $user = $existing;
                    // إعادةُ إصدارِ تفعيلٍ فقط لمن لم يضع كلمتَه بعد (لا كلمةَ سرٍّ له)
                    if ($user->password_changed_at === null) {
                        AccountActivation::issue($user);
                    }
                } else {
                    // النقطةُ الوحيدة لخلق مستخدمِ عميلٍ بلا كلمةِ سرّ + إصدارِ تفعيله (C8)
                    [$user] = AccountActivation::provisionClient([
                        'name'  => hub_fit($name, 190),
                        'email' => hub_fit($email, 190),
                    ]);
                }

                return $this->upsertMembership($clientRow->id, $user->id, $data['role']);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // سباقُ دعوتين متزامنتين على القيد الفريد (client_id,user_id) — رسالةٌ ناعمة لا ٥٠٠
            report($e);

            return back()->with('warn', 'هذا الزميلُ عضوٌ بالفعل — حُدِّثت دعوتُه.');
        }

        $this->recordEvent('granted', 'منح عضويّة عميل', $clientRow, $membership);

        return back()->with('ok', 'دُعي الزميلُ إلى مساحة العميل — رسالةُ تفعيلٍ في الطريق، ويضع كلمتَه بنفسه (لا كلمةَ سرٍّ تُرسَل).');
    }

    /**
     * **تغييرُ الدور** — رفعٌ أو خفضٌ لعضوٍ قائم. الرفعُ إلى Client Owner يتطلّب تصعيداً؛
     * ما دونه لا. لا يمسّ حالةَ العضويّة (فعّال/معلّق) — الدورُ وحدَه.
     */
    public function setRole(Request $r, string $client, string $membership)
    {
        $clientRow = $this->manageClient($client);
        $data = $r->validate(['role' => ['required', 'string', 'in:' . implode(',', ClientMembership::ROLES)]]);

        if ($data['role'] === 'owner' && ($resp = $this->stepUp($client))) {
            return $resp;
        }

        $m = ClientMembership::where('client_id', $clientRow->id)->whereKey($membership)->firstOrFail();
        if ($m->role !== $data['role']) {
            $m->forceFill(['role' => $data['role']])->save();
            $this->recordEvent('granted', 'تغيير دور عضويّة عميل', $clientRow, $m);
        }

        return back()->with('ok', 'حُدِّث دورُ العضو.');
    }

    /**
     * **سحبُ الوصول** — يتطلّب تصعيداً دائماً. يُعلّق العضويّة (لا حذفٌ صلب: التاريخُ
     * يبقى)، فيسقط العميلُ من نطاق العضو فوراً (`hub_client_ids` يعدّ الفعّالَ وحدَه).
     */
    public function revoke(Request $r, string $client, string $membership)
    {
        $clientRow = $this->manageClient($client);

        if ($resp = $this->stepUp($client)) {
            return $resp;
        }

        $m = ClientMembership::where('client_id', $clientRow->id)->whereKey($membership)->firstOrFail();
        if ($m->status !== 'suspended') {
            $m->forceFill(['status' => 'suspended'])->save();
        }
        $this->recordEvent('revoked', 'سحب عضويّة عميل', $clientRow, $m);

        return back()->with('ok', 'سُحب وصولُ العضو — يسقط عن نطاق العميل فوراً.');
    }

    /* ────────── مساعِدات ────────── */

    /** بوابةُ تصعيد الهوية — تعود إلى صفحة العميل (GET) بعد التأكيد */
    private function stepUp(string $client)
    {
        return hub_require_stepup(route('m.show', ['clients', $client], absolute: false));
    }

    /**
     * صفٌّ واحدٌ لكلّ (عميل، مستخدم): يُنشئ العضويّةَ «مدعوّ» أو يُحيي/يُحدّث القائمةَ.
     * لا يخفض «فعّال» إلى «مدعوّ» (من فعّلَ حسابَه يبقى فعّالاً وإن أُعيدت دعوتُه بدورٍ آخر).
     */
    private function upsertMembership(string $clientId, string $userId, string $role): ClientMembership
    {
        $m = ClientMembership::withTrashed()
            ->where('client_id', $clientId)->where('user_id', $userId)->first();

        if ($m) {
            if ($m->trashed()) {
                $m->restore();
            }
            $m->forceFill([
                'role'       => $role,
                'status'     => $m->status === 'active' ? 'active' : 'invited',
                'invited_by' => auth()->id(),
                'invited_at' => now(),
            ])->save();

            return $m;
        }

        return ClientMembership::create([
            'client_id'  => $clientId,
            'user_id'    => $userId,
            'role'       => $role,
            'status'     => 'invited',
            'invited_by' => auth()->id(),
            'invited_at' => now(),
        ]);
    }

    /**
     * يُدخل الفعلَ سلسلةَ التدقيق: حدثٌ دلاليّ (تدفّقات/ويبهوكس كأيّ حدث) + قيدُ تدقيقٍ
     * على العميل بختم SHA-256. الحدثُ معزولٌ في try — تعثّرُه لا يكسر الفعلَ الأصليّ.
     */
    private function recordEvent(string $event, string $action, Client $client, ClientMembership $m): void
    {
        try {
            FlowRunner::fire($event, 'client_memberships', $m);
        } catch (\Throwable $e) {
            report($e);
        }

        hub_audit($action, 'clients', $client->id, $client->name, [
            'after' => ['membership_id' => $m->id, 'member_id' => $m->user_id, 'role' => $m->role, 'status' => $m->status],
        ]);
    }
}
