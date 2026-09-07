<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\Custody;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **جلساتُ الجرد: لقطةٌ مجمَّدةٌ تُقارَن بالمسح المُصادَق** (Work OS · الطور F · WP-F.3 · §32 · §99).
 *
 * التجميدُ (`freeze`) يأخذ صورةً ثابتةً لمجموعةِ الأصول **بنطاق القارئ** في
 * `inventory_items` — لا تتبدّل بتغيّرِ الأصلِ بعدها؛ والمسحُ (`scan`) يحلّ الرمزَ
 * عبر **المحلِّل الموحّد** `Identity::resolve` (لا محلِّلَ ثانٍ) ويختم الماسِحَ
 * (`by_id`) وزمنَه؛ والمصالحةُ (`reconcile`) تصنّف الفروق: موجود/مفقود/غير متوقع/انتقل.
 *
 * يمتدّ `CompanyIsolationTest` (العزلُ الصارم) و`WorkOsPortalGuardTest` (منعُ العميل):
 *   · التجميدُ لقطةٌ **ثابتة** — تغييرُ الأصلِ بعده لا يمسّ الصفَّ المجمَّد.
 *   · التصنيفُ صحيحٌ للحالات الأربع.
 *   · مسحُ أصلِ شركةٍ أجنبية → «غير معروف» بلا تسريبِ اسمٍ أو كود (المحلِّلُ منطَّق).
 *   · كلُّ مسحٍ يختم `by_id`.
 *   · الإغلاقُ يتطلّب تصعيدَ مصادقةٍ (`hub_require_stepup`).
 *   · داخليّةٌ فقط: حسابُ العميل ٤٠٤ (PortalGuard قائمةٌ بيضاءُ لا تضمّ inventory).
 */
class WorkOsInventoryTest extends TestCase
{
    /** ختمُ تصعيدِ مصادقةٍ طازج في الجلسة — للأفعال خلفَ `hub_require_stepup` */
    private function withFreshStepUp(): static
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** حسابُ عميلٍ صلبٌ (account_type=client) بمصفوفةٍ تمنح الأصول — ليُثبَت المنعُ من الحارس */
    private function clientUser(): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => ['assets' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]]]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ────────── ١) التجميدُ لقطةٌ ثابتة: تغييرُ الأصلِ بعده لا يمسّ الصفَّ المجمَّد ────────── */

    public function test_freeze_is_a_stable_snapshot(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب اللقطة', 'type' => 'لابتوب', 'status' => 'متاح']);

        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();

        $session = InventorySession::firstOrFail();
        $item = InventoryItem::where('session_id', $session->id)->where('asset_id', $a->id)->firstOrFail();
        $this->assertSame('متاح', (string) data_get($item->snapshot, 'status'),
            'اللقطةُ لم تلتقط حالةَ الأصلِ لحظةَ التجميد');

        // نُغيّر الأصلَ بعد التجميد: الحالة، ثم إسنادٌ لمحطة (عبر Custody المقفلة)
        Custody::transition($a->fresh(), 'صيانة', now()->toDateString());
        $st = Station::create(['facility' => 'مبنى', 'type' => 'مكتب']);
        Custody::assignStation($a->fresh(), $st->id, now()->toDateString());

        // الأصلُ تبدّل — واللقطةُ المجمَّدةُ تبقى كما كانت (نقطةٌ زمنيّةٌ مجمَّدة)
        $this->assertSame('صيانة', (string) $a->fresh()->status);
        $item->refresh();
        $this->assertSame('متاح', (string) data_get($item->snapshot, 'status'),
            'اللقطةُ تبدّلت بتغيّرِ الأصل — التجميدُ ليس ثابتاً');
        $this->assertNull(data_get($item->snapshot, 'station_id'),
            'اللقطةُ اكتسبت محطةً أُسنِدت بعد التجميد — ليست مجمَّدة');
    }

    /* ────────── ٢) تصنيفُ الفروق: موجود/مفقود/انتقل/غير متوقع ────────── */

    public function test_verdict_classification_present_missing_moved_unexpected(): void
    {
        $this->seedCore();
        $present = Asset::create(['name' => 'أصلٌ موجود', 'type' => 'لابتوب', 'status' => 'متاح']);
        $missing = Asset::create(['name' => 'أصلٌ مفقود', 'type' => 'لابتوب', 'status' => 'متاح']);
        $moved   = Asset::create(['name' => 'أصلٌ منتقل', 'type' => 'لابتوب', 'status' => 'متاح']);

        // التجميدُ يلتقط الثلاثة بمواضعها الحاليّة (بلا محطة)
        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        // موجود: يُمسح في موضعه ولا يتحرّك
        $this->actingAs($this->owner)->post(route('inventory.scan', $session->id), ['code' => $present->code])
            ->assertRedirect();

        // انتقل: يُسنَد لمحطةٍ بعد التجميد ثم يُمسح — موضعُه الحاليّ يخالف المجمَّد
        $st = Station::create(['facility' => 'مبنى', 'type' => 'مكتب']);
        Custody::assignStation($moved->fresh(), $st->id, now()->toDateString());
        $this->actingAs($this->owner)->post(route('inventory.scan', $session->id), ['code' => $moved->code])
            ->assertRedirect();

        // غير متوقع: أصلٌ أُنشئ **بعد** التجميد (ليس في اللقطة) ثم يُمسح
        $extra = Asset::create(['name' => 'أصلٌ طارئ', 'type' => 'لابتوب', 'status' => 'متاح']);
        $this->actingAs($this->owner)->post(route('inventory.scan', $session->id), ['code' => $extra->code])
            ->assertRedirect();

        // مفقود: $missing لم يُمسح إطلاقاً

        // المصالحةُ الكتابيّة خلفَ تصعيدِ المصادقة
        $this->actingAs($this->owner)->withFreshStepUp()
            ->post(route('inventory.reconcile', $session->id))->assertRedirect();

        $verdict = fn ($assetId) => (string) InventoryItem::where('session_id', $session->id)
            ->where('asset_id', $assetId)->value('verdict');

        $this->assertSame('موجود', $verdict($present->id), 'الموجودُ لم يُصنَّف موجوداً');
        $this->assertSame('مفقود', $verdict($missing->id), 'غيرُ الممسوحِ لم يُصنَّف مفقوداً');
        $this->assertSame('انتقل', $verdict($moved->id), 'المنتقلُ لم يُصنَّف منتقلاً');
        $this->assertSame('غير متوقع', $verdict($extra->id),
            'الطارئُ (خارجَ اللقطة) لم يُصنَّف غيرَ متوقع');
    }

    /* ────────── ٣) مسحُ أصلِ شركةٍ أجنبية → «غير معروف» بلا تسريب ────────── */

    public function test_scanning_out_of_company_asset_is_unknown_with_no_leak(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);

        Asset::create(['name' => 'أصلُ ألف', 'type' => 'لابتوب', 'company_id' => $coA->id]);
        $secret = Asset::create(['name' => 'سِرُّ شركةِ باء', 'type' => 'سيرفر', 'company_id' => $coB->id]);

        $this->actingAs($this->employee)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        // موظفةُ ألف تمسح كودَ أصلِ باء: خارجَ نطاقها → «غير معروف» لا كشفَ وجود
        $resp = $this->actingAs($this->employee)
            ->post(route('inventory.scan', $session->id), ['code' => $secret->code]);
        $resp->assertRedirect();

        $scan = InventoryScan::where('session_id', $session->id)->latest('at')->firstOrFail();
        $this->assertSame('غير معروف', (string) $scan->result, 'المسحُ الأجنبيُّ لم يُصنَّف «غير معروف»');
        $this->assertNull($scan->asset_id, 'المسحُ الأجنبيُّ ربط asset_id — تسريبُ هوية');

        // ولا يظهر اسمُ الأصلِ الأجنبيِّ ولا كودُه في أيّ صفحةٍ يراها الماسح
        $this->actingAs($this->employee)->get(route('inventory.show', $session->id))
            ->assertOk()->assertDontSee($secret->name)->assertDontSee($secret->code);
    }

    /* ────────── ٤) كلُّ مسحٍ يختم الماسِح (by_id) وزمنَه ────────── */

    public function test_every_scan_stamps_by_id(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'أصلُ الختم', 'type' => 'لابتوب', 'status' => 'متاح']);

        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        $this->actingAs($this->owner)->post(route('inventory.scan', $session->id), ['code' => $a->code])
            ->assertRedirect();

        $scan = InventoryScan::where('session_id', $session->id)->where('asset_id', $a->id)->firstOrFail();
        $this->assertSame($this->owner->id, $scan->by_id, 'المسحُ لم يختم هويةَ الماسح (by_id)');
        $this->assertNotNull($scan->at, 'المسحُ لم يختم زمنَه');
    }

    /* ────────── ٥) الإغلاقُ يتطلّب تصعيدَ مصادقة ────────── */

    public function test_close_requires_stepup(): void
    {
        $this->seedCore();
        Asset::create(['name' => 'أصلٌ للإغلاق', 'type' => 'لابتوب', 'status' => 'متاح']);

        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        // بلا تصعيدٍ طازج: يُحوَّل إلى شاشة التصعيد، والجلسةُ تبقى مفتوحة
        $resp = $this->actingAs($this->owner)->post(route('inventory.close', $session->id));
        $resp->assertStatus(302);
        $this->assertStringContainsString('stepup', (string) $resp->headers->get('Location'),
            'الإغلاقُ نُفِّذ بلا تصعيدِ مصادقة');
        $this->assertSame(InventorySession::OPEN, (string) $session->fresh()->status,
            'الجلسةُ أُغلقت رغمَ غيابِ التصعيد');

        // بتصعيدٍ طازج: تُغلَق الجلسة
        $this->actingAs($this->owner)->withFreshStepUp()
            ->post(route('inventory.close', $session->id))->assertRedirect();
        $this->assertSame(InventorySession::CLOSED, (string) $session->fresh()->status,
            'الإغلاقُ بالتصعيدِ الطازج لم يُغلق الجلسة');
    }

    /* ────────── ٦) العزلُ: التجميدُ يلتقط أصولَ الشركةِ المسموحةِ وحدَها ────────── */

    public function test_freeze_is_company_isolated(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);

        $aA = Asset::create(['name' => 'أصلُ ألف', 'type' => 'لابتوب', 'company_id' => $coA->id]);
        $aB = Asset::create(['name' => 'أصلُ باء', 'type' => 'لابتوب', 'company_id' => $coB->id]);

        $this->actingAs($this->employee)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        $this->assertSame(1, InventoryItem::where('session_id', $session->id)->count(),
            'التجميدُ التقط أصولاً خارجَ الشركةِ المسموحة');
        $this->assertTrue(InventoryItem::where('session_id', $session->id)
            ->where('asset_id', $aA->id)->exists(), 'أصلُ الشركةِ المسموحة غُيِّب من اللقطة');
        $this->assertFalse(InventoryItem::where('session_id', $session->id)
            ->where('asset_id', $aB->id)->exists(), 'أصلُ الشركةِ الأجنبية تسرّب إلى اللقطة');
    }

    /* ────────── ٧) داخليّةٌ فقط: حسابُ العميل ٤٠٤ على كلّ مسارِ جرد ────────── */

    public function test_a_client_account_gets_404_on_inventory(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركة ألف', 'stage' => 'عميل حالي']);
        $client = $this->clientUser();
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $client->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);

        $this->assertTrue(hub_can($client->fresh(), 'assets', 'v'),
            'المصفوفةُ تمنح assets:v فعلاً — فالمنعُ من الحارس لا من غيابِ الصلاحية');

        $this->actingAs($client)->get(route('inventory.center'))->assertNotFound();
        $this->actingAs($client)->post(route('inventory.freeze'))->assertNotFound();
        $this->actingAs($client)->get(route('inventory.show', Str::uuid()))->assertNotFound();
    }
}
