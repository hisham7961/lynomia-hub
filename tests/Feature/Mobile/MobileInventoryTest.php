<?php

namespace Tests\Feature\Mobile;

use App\Models\Asset;
use App\Models\Company;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **جلساتُ الجرد بالمسح على الجوال** (خطّةُ التطبيق · 3.4) — المحرّكُ الواحد `InventorySessions`.
 *
 * يُثبت: القراءةُ `assets:v` والكتابةُ `e`/`assetInventory` (غيرُه ٤٠٣)، عزلُ الشركة (٤٠٤)،
 * المسحُ عبر المحلِّل الموحّد بنطاق الماسِح (الرمزُ الأجنبيّ «غير معروف» بلا تسريب)، والمصالحةُ
 * والإغلاقُ خلفَ تصعيد الجوال (٤٢٨ بلا مِنحة)، ولا مسحَ بعد الإغلاق، والعميلُ محجوب.
 */
class MobileInventoryTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function person(string $name, array $matrix): User
    {
        $role = Role::create(['name' => $name . Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    public function test_freeze_scan_reconcile_close_with_step_up(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $keeper = $this->person('أمينُ الجرد', ['assets' => ['v' => 1, 'assetInventory' => 1]]);
        $keeper->forceFill(['companies' => [$coA->id]])->save();
        $mine1 = Asset::create(['name' => 'لابتوب ١', 'type' => 'لابتوب', 'company_id' => $coA->id]);
        $mine2 = Asset::create(['name' => 'لابتوب ٢', 'type' => 'لابتوب', 'company_id' => $coA->id]);
        $foreign = Asset::create(['name' => 'جهازُ شركةٍ أخرى', 'type' => 'لابتوب', 'company_id' => $coB->id]);
        $h = $this->h($keeper);

        $f = $this->withHeaders($h)->postJson('/api/mobile/v1/inventory/sessions')->assertStatus(201);
        $this->assertSame(2, $f->json('data.frozen'), 'اللقطةُ بنطاقي وحدَه — لا أصلَ أجنبيّ');
        $sid = $f->json('data.session.id');
        $this->assertSame($coA->id, $f->json('data.session.company_id'));

        $list = $this->withHeaders($h)->getJson('/api/mobile/v1/inventory/sessions')->assertOk();
        $this->assertSame([$sid], array_column($list->json('data.sessions'), 'id'));

        // مسحٌ معروف، وطارئٌ بعد التجميد، وأجنبيٌّ «غير معروف» بلا رمزٍ ولا أصل
        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/scan", ['code' => $mine1->code])
            ->assertOk()->assertJsonPath('data.result_key', 'known')->assertJsonPath('data.asset.id', $mine1->id);
        $late = Asset::create(['name' => 'أصلٌ بعد التجميد', 'type' => 'لابتوب', 'company_id' => $coA->id]);
        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/scan", ['code' => $late->code])
            ->assertOk()->assertJsonPath('data.result_key', 'unexpected');
        $u = $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/scan", ['code' => $foreign->code])
            ->assertOk()->assertJsonPath('data.result_key', 'unknown')->assertJsonPath('data.asset', null)
            ->assertJsonPath('data.scan.code', null);
        $this->assertStringNotContainsString((string) $foreign->code, (string) $u->getContent());
        $this->assertNull(data_get(InventoryScan::where('session_id', $sid)->where('result', InventoryScan::UNKNOWN)
            ->orderBy('id')->first()->meta, 'code'), 'الرمزُ الأجنبيّ لا يُخزَّن');
        $this->assertSame(3, InventoryScan::where('session_id', $sid)->where('by_id', $keeper->id)->count());

        // المصالحةُ بلا تصعيد ⇒ ٤٢٨ بالغرض المسمّى
        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/reconcile")
            ->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED')
            ->assertJsonPath('details.purpose', 'action:inventory:reconcile');
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/step-up',
            ['purpose' => 'action:inventory:reconcile', 'credential' => 'Secret!2026x'])->assertOk();
        $rec = $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/reconcile")->assertOk();
        $this->assertSame(1, $rec->json('data.counts.موجود'));
        $this->assertSame(1, $rec->json('data.counts.مفقود'));
        $this->assertSame(1, $rec->json('data.counts.غير متوقع'));

        // الإغلاقُ غرضٌ مستقلّ — مِنحةُ المصالحة لا تكفيه
        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/close")->assertStatus(428);
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/step-up',
            ['purpose' => 'action:inventory:close', 'credential' => 'Secret!2026x'])->assertOk();
        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/close")->assertOk()
            ->assertJsonPath('data.closed_now', true);
        $this->assertSame(InventorySession::CLOSED, (string) InventorySession::find($sid)->status);

        $this->withHeaders($h)->postJson("/api/mobile/v1/inventory/sessions/{$sid}/scan", ['code' => $mine2->code])
            ->assertStatus(422)->assertJsonPath('details.reason', 'session_closed');

        $show = $this->withHeaders($h)->getJson("/api/mobile/v1/inventory/sessions/{$sid}")->assertOk();
        $this->assertSame(3, $show->json('data.session.total'));
        $this->assertFalse($show->json('data.can.scan'));
    }

    public function test_permissions_and_company_isolation(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $session = new InventorySession(['company_id' => $coA->id, 'status' => InventorySession::OPEN]);
        $session->forceFill(['company_id' => $coA->id, 'status' => InventorySession::OPEN, 'by_id' => $this->owner->id])->save();

        $viewer = $this->person('مشاهدُ الأصول', ['assets' => ['v' => 1]]);
        $vh = $this->h($viewer);
        $this->withHeaders($vh)->getJson('/api/mobile/v1/inventory/sessions')->assertOk()
            ->assertJsonPath('data.can.freeze', false);
        $this->withHeaders($vh)->postJson('/api/mobile/v1/inventory/sessions')->assertStatus(403);
        $this->withHeaders($vh)->postJson("/api/mobile/v1/inventory/sessions/{$session->id}/scan", ['code' => 'X'])
            ->assertStatus(403);

        $blind = $this->person('بلا أصول', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($blind))->getJson('/api/mobile/v1/inventory/sessions')->assertStatus(403);

        $otherCo = $this->person('أمينُ باء', ['assets' => ['v' => 1, 'e' => 1]]);
        $otherCo->forceFill(['companies' => [$coB->id]])->save();
        $oh = $this->h($otherCo);
        $this->withHeaders($oh)->getJson("/api/mobile/v1/inventory/sessions/{$session->id}")->assertStatus(404);
        $this->withHeaders($oh)->postJson("/api/mobile/v1/inventory/sessions/{$session->id}/scan", ['code' => 'X'])
            ->assertStatus(404);
        $this->assertSame([], $this->withHeaders($oh)->getJson('/api/mobile/v1/inventory/sessions')->json('data.sessions'));
        $this->assertSame(0, InventoryScan::count());
    }

    public function test_client_accounts_cannot_reach_inventory(): void
    {
        $this->seedCore();
        $client = \App\Models\Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => 'c@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(), 'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);

        $h = $this->h($cu);
        $this->withHeaders($h)->getJson('/api/mobile/v1/inventory/sessions')->assertStatus(404);
        $this->withHeaders($h)->postJson('/api/mobile/v1/inventory/sessions')->assertStatus(404);
        $this->assertSame(0, InventorySession::count());
    }
}
