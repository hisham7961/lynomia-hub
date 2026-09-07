<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Models\Station;
use App\Models\StationAssignment;
use Tests\TestCase;

/**
 * **سيناريو القبول §99 — رحلةُ المحطة والأصل من الإنشاء إلى الجرد**
 * (Work OS · الطور M · WP-M.4).
 *
 * يمتدّ سوابقَه الموسومة ولا يستنسخها (`WorkOsStationsTest` الكودُ والمسحُ
 * والإسناد، `WorkOsAssetUpgradeTest` station_id المقفل عبر Custody،
 * `WorkOsInventoryTest` اللقطةُ المجمَّدة والمسحُ المُصادَق) — وهذا الملفُّ يثبت
 * **الخيطَ الواصل** الذي لا تراه ملفّاتُ الوحدات، عبر أسطح HTTP الحقيقية:
 *
 *   محطةٌ تُنشأ من النموذج العامّ (الكودُ يتولّد لا يُكتب) ← ملصقُها `s/{code}`
 *   يتطلّب دخولاً ويُحسم ← تُسنَد لموظف ← أصلٌ يُنشأ ويُسنَد **للمحطة** عبر
 *   Custody ← صفحةُ ٣٦٠ تُظهر الشاغلَ والتاريخَ والأصولَ المرتبطة ← تُخلى
 *   ويغادر الموظفُ (يُحذف حسابُه) **والتاريخُ يبقى** ← جلسةُ جردٍ تُجمَّد
 *   **فتلتقط موضعَ الأصل على المحطة**، ومسحُه يصنّفه «موجوداً» في موضعه.
 */
class WorkOsAcceptanceStationTest extends TestCase
{
    /** جلسةُ تصعيدٍ سارية — للأفعال خلف hub_require_stepup (نمطُ WorkOsInventoryTest) */
    private function withFreshStepUp(): static
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    public function test_the_full_station_journey_from_creation_to_inventory(): void
    {
        $this->seedCore();

        /* ── (١) الإنشاء من النموذج العامّ: الكودُ يتولّد ولا يُكتب بيد ── */

        $this->actingAs($this->owner)->post('/m/stations', [
            'facility' => 'برج القبول', 'floor' => '4', 'desk' => 'D-99', 'type' => 'مكتب',
            'code' => 'ST-FORGED-0001',   // محاولةُ فرضِ كودٍ — الحقلُ مقفل
        ]);
        $station = Station::where('facility', 'برج القبول')->firstOrFail();
        $this->assertNotEmpty($station->code, 'المحطةُ وُلدت بلا كود');
        $this->assertNotSame('ST-FORGED-0001', $station->code,
            'كودُ الملصق كُتب بيدٍ من النموذج العامّ — حقلٌ مقفلٌ يولّده النظام');

        /* ── (٢) الملصق s/{code}: الضيفُ للدخول، والعضوُ يُحسَم لصفحة المحطة ── */

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('s/' . $station->code)->assertRedirect(route('login'));

        $this->actingAs($this->owner)->get('s/' . $station->code)
            ->assertRedirect(route('m.show', ['stations', $station->id]));

        /* ── (٣) الإسناد لموظف: current_employee_id + صفُّ تاريخٍ معاً ── */

        $this->actingAs($this->owner)
            ->post(route('stations.assign', $station->id),
                ['user_id' => $this->viewer->id, 'note' => 'مقعدُ الدعم الدائم'])
            ->assertRedirect();
        $this->assertSame($this->viewer->id, $station->fresh()->current_employee_id);

        /* ── (٤) أصلٌ يُنشأ ويُسنَد للمحطة عبر Custody (لا عبر النموذج العامّ) ── */

        $this->actingAs($this->owner)->post('/m/assets', [
            'name' => 'شاشةُ مقعدِ الدعم', 'type' => 'شاشة',
        ]);
        $asset = Asset::where('name', 'شاشةُ مقعدِ الدعم')->firstOrFail();
        $assetCode = (string) $asset->code;
        $this->assertNotEmpty($assetCode, 'الأصلُ وُلد بلا رمز');

        $this->actingAs($this->owner)->post('/custody/' . $asset->id . '/station', [
            'station_id' => $station->id, 'at' => now()->toDateString(),
        ])->assertRedirect();
        $asset->refresh();
        $this->assertSame($station->id, $asset->station_id, 'الأصلُ لم يُسنَد للمحطة');
        $this->assertNull($asset->holder_id, 'إسنادُ المحطة فرض حائزاً — لا إجبار');
        $this->assertSame(1, AssetCustody::where('asset_id', $asset->id)
            ->where('action', 'إسناد لمحطة')->count(), 'إسنادُ المحطة بلا صفِّ حركة');

        /* ── (٥) صفحةُ ٣٦٠: الشاغلُ والتاريخُ ظاهران، والأصلُ على حافّة العلاقات ── */

        $this->actingAs($this->owner)->get('/m/stations/' . $station->id)->assertOk()
            ->assertSee($station->code)
            ->assertSee('مشاهد')                 // اسمُ الشاغل (viewer من seedCore)
            ->assertSee('مقعدُ الدعم الدائم');   // ملاحظةُ الإسناد في جدول التاريخ

        // حافّةُ «أصولُ هذه المحطة» عبر hub_related — القارئُ مالكٌ فيرى الحافّةَ كاملة
        $related = collect(hub_related('stations', (string) $station->id));
        $assetsPanel = $related->firstWhere('module', 'assets');
        $this->assertNotNull($assetsPanel, 'لوحةُ أصول المحطة غائبةٌ عن hub_related');
        $this->assertContains((string) $asset->id,
            collect($assetsPanel['rows'])->pluck('id')->map('strval')->all(),
            'الأصلُ المُسنَدُ للمحطة غائبٌ عن حافّتها');

        /* ── (٦) الجردُ يلتقط الموضعَ المجمَّد: أصلٌ على محطةٍ يُمسَح «موجوداً» ── */

        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();
        $session = InventorySession::firstOrFail();

        // **الخيطُ العابرُ F.2↔F.3:** اللقطةُ تحفظ موضعَ الأصل على المحطة لحظةَ التجميد
        $item = InventoryItem::where('session_id', $session->id)
            ->where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame((string) $station->id, (string) data_get($item->snapshot, 'station_id'),
            'اللقطةُ المجمَّدة لم تلتقط محطةَ الأصل — مقارنةُ المواضع عمياء');

        $this->actingAs($this->owner)
            ->post(route('inventory.scan', $session->id), ['code' => $assetCode])
            ->assertRedirect();
        $scan = InventoryScan::where('session_id', $session->id)
            ->where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame($this->owner->id, $scan->by_id, 'المسحُ بلا ختمِ الماسح');

        $this->actingAs($this->owner)->withFreshStepUp()
            ->post(route('inventory.reconcile', $session->id))->assertRedirect();
        $this->assertSame('موجود', (string) $item->fresh()->verdict,
            'أصلٌ ممسوحٌ في موضعه لم يُصنَّف «موجوداً»');

        $this->actingAs($this->owner)->withFreshStepUp()
            ->post(route('inventory.close', $session->id))->assertRedirect();
        $this->assertSame(InventorySession::CLOSED, (string) $session->fresh()->status);

        /* ── (٧) الإخلاءُ ومغادرةُ الموظف: التاريخُ لا يُمحى ── */

        $this->actingAs($this->owner)
            ->post(route('stations.vacate', $station->id), ['note' => 'غادر الموظف'])
            ->assertRedirect();
        $this->assertNull($station->fresh()->current_employee_id, 'الإخلاءُ لم يُفرّغ الشاغل');

        // الموظفُ يغادر المنشأةَ كلَّها (يُحذف حسابُه) — الأثرُ يبقى كاملاً
        $this->viewer->delete();
        $this->assertSame(2, StationAssignment::where('station_id', $station->id)->count(),
            'تاريخُ الإسناد/الإخلاء نقص بعد مغادرة الموظف — الأثرُ لا يُمحى');
        $this->assertSame(1, StationAssignment::where('station_id', $station->id)
            ->where('action', 'vacate')->count());

        // وصفحةُ ٣٦٠ ما تزال تعرض التاريخَ كاملاً — الإسنادُ باسمه والإخلاءُ بملاحظته
        $this->actingAs($this->owner)->get('/m/stations/' . $station->id)->assertOk()
            ->assertSee('مقعدُ الدعم الدائم')
            ->assertSee('غادر الموظف')
            ->assertSee('إخلاء');
    }
}
