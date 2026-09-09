<?php

namespace App\Support;

use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **جوهرُ إدارة عضويّة العميل — المصدرُ الواحد** (Work OS · WP-B.3 + تطبيق العميل §15):
 * منطقُ الدعوة/الدور/السحب المنزوعُ من `ClientMemberController` الويبيّ كي يستهلكه
 * السطحان (الويبُ والجوال) حرفاً — **لا سكّةَ عضويّةٍ ثانية ولا سكّةَ تفعيلٍ ثانية**:
 * الدعوةُ تمرّ حصراً عبر `AccountActivation` (حسابُ عميلٍ بلا كلمةِ سرٍّ ثم يضعها
 * العميلُ بنفسه)، والحالةُ upsert واحدٌ لكل (عميل، مستخدم).
 *
 * حراسةُ **الصلاحية والنطاق والتصعيد مسؤوليةُ السطح المستدعي** (كلٌّ بآليّته:
 * `hub_require_stepup` ويبيّاً، ومِنحُ `mobile_stepup_grants` جوّالاً) — الجوهرُ هنا
 * يفترض عميلاً محسوماً ومصرَّحاً به سلفاً ويحفظ بقيّةَ القواعد: بريدُ حسابٍ داخليٍّ
 * يُرفَض 422، والفعّالُ لا يُخفَض «مدعوّاً»، والسحبُ تعليقٌ لا حذف (التاريخُ يبقى)،
 * وكلُّ فعلٍ يدخل سلسلةَ التدقيق (حدثٌ دلاليّ + `hub_audit`).
 */
class ClientMembers
{
    /** أعضاءُ العميل بترتيبٍ حتميّ — للسطحين (قائمةُ 360 والجوال) */
    public static function membersOf(Client $client)
    {
        return ClientMembership::with('user:id,name,email,password_changed_at')
            ->where('client_id', $client->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();
    }

    /**
     * **الدعوة**: بريدٌ لحسابٍ قائمٍ لا يُكرّر مستخدماً، وبريدٌ داخليٌّ يُرفَض
     * (لا يُحوَّل موظفٌ إلى عميل). مَن لم يضع كلمتَه بعدُ يُعاد إصدارُ تفعيله.
     * سباقُ دعوتين على القيد الفريد يُطوى upsert لاحقاً لدى المستدعي عبر
     * `QueryException` (يعالجها السطحُ برسالةٍ ناعمة).
     */
    public static function invite(Client $client, string $email, ?string $name, string $role, User $inviter): ClientMembership
    {
        $email = mb_strtolower(trim($email));
        $name = trim((string) $name) ?: Str::before($email, '@');

        return DB::transaction(function () use ($client, $email, $name, $role, $inviter) {
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
                    'name' => hub_fit($name, 190),
                    'email' => hub_fit($email, 190),
                ]);
            }

            return self::upsertMembership($client->id, $user->id, $role, $inviter);
        });
    }

    /** **تغييرُ الدور** — الدورُ وحدَه، لا يمسّ الحالة. يعيد العضويّةَ (تغيّرت أو لا). */
    public static function setRole(Client $client, string $membershipId, string $role): ClientMembership
    {
        $m = ClientMembership::where('client_id', $client->id)->whereKey($membershipId)->firstOrFail();
        if ($m->role !== $role) {
            $m->forceFill(['role' => $role])->save();
        }

        return $m;
    }

    /** **سحبُ الوصول** — تعليقٌ (لا حذف): يسقط عن `hub_client_ids` فوراً */
    public static function revoke(Client $client, string $membershipId): ClientMembership
    {
        $m = ClientMembership::where('client_id', $client->id)->whereKey($membershipId)->firstOrFail();
        if ($m->status !== 'suspended') {
            $m->forceFill(['status' => 'suspended'])->save();
        }

        return $m;
    }

    /**
     * صفٌّ واحدٌ لكلّ (عميل، مستخدم): يُنشئ «مدعوّاً» أو يُحيي/يُحدّث القائم.
     * لا يخفض «فعّالاً» إلى «مدعوّ» (من فعّل حسابَه يبقى فعّالاً وإن أُعيدت دعوتُه).
     */
    public static function upsertMembership(string $clientId, string $userId, string $role, User $inviter): ClientMembership
    {
        $m = ClientMembership::withTrashed()
            ->where('client_id', $clientId)->where('user_id', $userId)->first();

        if ($m) {
            if ($m->trashed()) {
                $m->restore();
            }
            $m->forceFill([
                'role' => $role,
                'status' => $m->status === 'active' ? 'active' : 'invited',
                'invited_by' => $inviter->id,
                'invited_at' => now(),
            ])->save();

            return $m;
        }

        return ClientMembership::create([
            'client_id' => $clientId,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'invited',
            'invited_by' => $inviter->id,
            'invited_at' => now(),
        ]);
    }

    /**
     * يُدخل الفعلَ سلسلةَ التدقيق: حدثٌ دلاليّ (تدفّقات/ويبهوكس) + قيدُ تدقيقٍ على
     * العميل. الحدثُ معزولٌ — تعثّرُه لا يكسر الفعلَ الأصليّ. الأحداثُ المسموحةُ
     * حرفيّتان مغلقتان — حدثٌ غيرُهما خطأُ برمجةٍ لا صمتُ تجاهُل.
     */
    public static function recordEvent(string $event, string $action, Client $client, ClientMembership $m): void
    {
        try {
            match ($event) {
                'granted' => FlowRunner::fire('granted', 'client_memberships', $m),
                'revoked' => FlowRunner::fire('revoked', 'client_memberships', $m),
            };
        } catch (\UnhandledMatchError $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
        }

        hub_audit($action, 'clients', $client->id, $client->name, [
            'after' => ['membership_id' => $m->id, 'member_id' => $m->user_id, 'role' => $m->role, 'status' => $m->status],
        ]);
    }
}
