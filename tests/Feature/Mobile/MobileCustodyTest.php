<?php

namespace Tests\Feature\Mobile;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **عهدتي + التسليمُ والاستردادُ على الجوال** (خطّةُ التطبيق · 3.3) — سكّةُ `CustodyHandover`
 * نفسُها التي يسلكها الويب: `assets:e` أو المفتاحُ الدقيق `custodyAssign`، `Custody::scoped`
 * (عزلُ الشركة ٤٠٤)، ومشروعٌ خارجَ نطاق المسلِّم مرفوض، والإقرارُ عبر إجراءِ السجلّ القائم.
 */
class MobileCustodyTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function person(string $name, array $matrix, array $extra = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => $name . Str::random(4), 'scope' => $scope, 'flags' => [], 'matrix' => $matrix]
            + (isset($extra['field_rules']) ? ['field_rules' => $extra['field_rules']] : []));

        return User::create(['name' => $name, 'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function h(User $u, array $extra = []): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']) + $extra;
    }

    public function test_handover_by_a_custody_clerk_then_my_custody_shows_it_pending_receipt(): void
    {
        $this->seedCore();
        $clerk = $this->person('أمينُ العهدة', ['assets' => ['v' => 1, 'custodyAssign' => 1]]);
        $holder = $this->person('المستلم', ['assets' => ['v' => 1]], ['field_rules' => ['assets' => ['serial' => 'hide']]]);
        $laptop = Asset::create(['name' => 'لابتوب التصميم', 'type' => 'لابتوب', 'serial' => 'SN-99887766']);
        $other = Asset::create(['name' => 'جهازٌ بيد غيري', 'type' => 'لابتوب', 'holder_id' => $this->employee->id]);

        $ck = $this->h($clerk, ['Idempotency-Key' => 'ho-' . Str::random(8)]);
        $body = ['user_id' => $holder->id, 'at' => now()->toDateString(), 'note' => 'تسليمٌ للمشروع'];
        $this->withHeaders($ck)->postJson('/api/mobile/v1/custody/' . $laptop->id . '/handover', $body)
            ->assertOk()->assertJsonPath('data.asset.holder_id', $holder->id)->assertJsonPath('data.movement.action', 'تسليم');
        $this->withHeaders($ck)->postJson('/api/mobile/v1/custody/' . $laptop->id . '/handover', $body)
            ->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame(1, AssetCustody::where('asset_id', $laptop->id)->count(), 'الإعادةُ لم تُكرّر الحركة');
        $this->assertSame(1, DB::table('notifications_hub')->where('user_id', $holder->id)->where('module', 'assets')->count());
        $this->assertSame(1, DB::table('audits')->where('action', 'تسليم عهدة')->where('record_id', $laptop->id)->count());

        $hh = $this->h($holder);
        $mine = $this->withHeaders($hh)->getJson('/api/mobile/v1/me/custody')->assertOk();
        $ids = array_column($mine->json('data.assets'), 'id');
        $this->assertSame([$laptop->id], $ids, 'عهدتي وحدي — لا جهازَ غيري');
        $this->assertTrue($mine->json('data.assets.0.receipt_pending'));
        $this->assertNull($mine->json('data.assets.0.serial'), 'الحقلُ المحجوبُ على دوري لا يُعاد');
        $this->assertMaskedValueAbsent((string) $mine->getContent(), 'SN-99887766');
        $this->assertSame($laptop->id, $mine->json('data.pending_receipts.0.id'));
        $ackPath = $mine->json('data.pending_receipts.0.ack.path');
        $this->assertSame('/api/mobile/v1/assets/' . $laptop->id . '/actions/ack', $ackPath);
        $this->assertNotContains($other->id, $ids);

        // الإقرارُ عبر إجراءِ السجلّ القائم — ثم يخرج من المعلّق
        $this->withHeaders($hh)->postJson($ackPath)->assertOk()->assertJsonPath('data.action', 'ack');
        $after = $this->withHeaders($hh)->getJson('/api/mobile/v1/me/custody')->assertOk();
        $this->assertSame([], $after->json('data.pending_receipts'));
        $this->assertFalse($after->json('data.assets.0.receipt_pending'));
    }

    public function test_recover_requires_a_holder_and_returns_the_asset(): void
    {
        $this->seedCore();
        $clerk = $this->person('أمين', ['assets' => ['v' => 1, 'e' => 1]]);
        $free = Asset::create(['name' => 'شاشةٌ في المخزن', 'type' => 'شاشة', 'status' => 'متاح']);
        $held = Asset::create(['name' => 'هاتفٌ بيد موظّفة', 'type' => 'هاتف', 'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);
        $h = $this->h($clerk);

        $this->withHeaders($h)->postJson('/api/mobile/v1/custody/' . $free->id . '/recover', ['at' => now()->toDateString()])
            ->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/mobile/v1/custody/' . $held->id . '/recover', ['at' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.asset.holder_id', null)->assertJsonPath('data.asset.status', 'متاح');
        $this->assertNull($held->fresh()->holder_id);
    }

    public function test_permissions_scope_and_validation_mirror_the_web(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $asset = Asset::create(['name' => 'طابعة', 'type' => 'طابعة', 'company_id' => $coA->id]);

        // بلا e ولا custodyAssign ⇒ ٤٠٣
        $viewer = $this->person('مشاهد', ['assets' => ['v' => 1]]);
        $this->withHeaders($this->h($viewer))->postJson('/api/mobile/v1/custody/' . $asset->id . '/handover',
            ['user_id' => $this->employee->id, 'at' => now()->toDateString()])->assertStatus(403);

        // أمينٌ معزولٌ على شركةٍ أخرى ⇒ ٤٠٤ (لا كشفَ وجود)
        $isolated = $this->person('أمينُ باء', ['assets' => ['v' => 1, 'custodyAssign' => 1]]);
        $isolated->forceFill(['companies' => [$coB->id]])->save();
        $this->withHeaders($this->h($isolated))->postJson('/api/mobile/v1/custody/' . $asset->id . '/handover',
            ['user_id' => $this->employee->id, 'at' => now()->toDateString()])->assertStatus(404);

        // التحقّقُ بقواعد الويب: المستلمُ والتاريخُ إلزاميّان، والمستلمُ حسابٌ قائم
        $clerk = $this->person('أمينٌ شامل', ['assets' => ['v' => 1, 'e' => 1]]);
        $ch = $this->h($clerk);
        $this->withHeaders($ch)->postJson('/api/mobile/v1/custody/' . $asset->id . '/handover', [])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id', 'at']);
        $this->withHeaders($ch)->postJson('/api/mobile/v1/custody/' . $asset->id . '/handover',
            ['user_id' => (string) Str::uuid(), 'at' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // ومحدودُ النطاق بمشاريعه لا يبلغ أصلاً خارجَ نطاقه (Custody::scoped · ٤٠٤)
        $scoped = $this->person('أمينُ مشاريعه', ['assets' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1]], [], 'proj');
        $this->withHeaders($this->h($scoped))->postJson('/api/mobile/v1/custody/' . $asset->id . '/handover',
            ['user_id' => $this->employee->id, 'at' => now()->toDateString()])->assertStatus(404);
        $this->assertSame(0, AssetCustody::where('asset_id', $asset->id)->count(), 'لا حركةَ من أيِّ رفض');
    }

    public function test_client_accounts_cannot_reach_custody(): void
    {
        $this->seedCore();
        $client = \App\Models\Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => 'c@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(), 'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);
        $asset = Asset::create(['name' => 'جهاز', 'type' => 'لابتوب', 'holder_id' => $cu->id]);
        $h = $this->h($cu);

        $this->withHeaders($h)->getJson('/api/mobile/v1/me/custody')->assertStatus(404);
        $this->withHeaders($h)->postJson('/api/mobile/v1/custody/' . $asset->id . '/recover', ['at' => now()->toDateString()])
            ->assertStatus(404);
        $this->assertSame($cu->id, $asset->fresh()->holder_id);
    }
}
