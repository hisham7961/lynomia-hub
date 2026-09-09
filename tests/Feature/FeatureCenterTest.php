<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\FeatureRegistry;
use App\Support\FeatureStatus;
use App\Support\Settings;
use Tests\TestCase;

/**
 * **مركزُ سجلِّ القدرات — الحارسُ والتبديلُ والبوّابةُ الآمنة** (§8/§13/§20/§23).
 *
 * المالكُ وحدَه يبلغ المركز؛ والموظّفُ يُصَدّ (٤٠٣)، والعميلُ لا يبلغه أصلاً (٤٠٤ بالبوّابة).
 * التبديلُ عبر Settings::put (كاتبٌ واحد + تاريخ)؛ والثابتُ النظاميُّ لا يُطفأ؛ والقدرةُ
 * الاختياريّةُ المُطفأةُ **يفشل مسارُها بأمان** (لا تسريب). إثباتٌ لا ادّعاء.
 */
class FeatureCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FeatureRegistry::flush();
    }

    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ───────── الحارس ───────── */

    public function test_owner_sees_the_registry_with_summary_and_features(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->get(route('features.index'))->assertOk();
        $res->assertSee('سجلّ القدرات');
        $res->assertSee('مركز التواصل الموحّد');   // قدرةٌ مسجَّلة
        $res->assertSee('ثابتٌ نظاميّ');            // حالةٌ معروضة
    }

    public function test_owner_sees_feature_detail_with_status_and_reason(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->get(route('features.show', 'mobile.production_push'))->assertOk();
        $res->assertSee('الدفع الإنتاجيّ');
        $res->assertSee('غير مُهيَّأة');            // حالةٌ صادقة (لا مزوّد)
    }

    public function test_non_owner_internal_user_is_forbidden(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get(route('features.index'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('features.show', 'collab.typing'))->assertForbidden();
        $this->actingAs($this->employee)->post(route('features.toggle', 'collab.typing'), ['on' => '0'])->assertForbidden();
    }

    public function test_client_cannot_reach_the_feature_center(): void
    {
        $this->seedCore();
        $client = $this->client();
        // العميلُ محجوبٌ بالبوّابة (٤٠٤ قبل الحارس) — لا يكتشف القدراتِ ولا خارطةَ الطريق
        $this->actingAs($client)->get(route('features.index'))->assertNotFound();
        $this->actingAs($client)->get(route('features.show', 'collab.center'))->assertNotFound();
        $this->actingAs($client)->post(route('features.toggle', 'collab.typing'), ['on' => '0'])->assertNotFound();
    }

    /* ───────── التبديلُ الآمن ───────── */

    public function test_owner_can_toggle_an_optional_capability_via_settings(): void
    {
        $this->seedCore();
        $this->assertSame(FeatureStatus::ENABLED, FeatureRegistry::status('collab.typing')['status']);

        $this->actingAs($this->owner)->post(route('features.toggle', 'collab.typing'), ['on' => '0', 'reason' => 'اختبار'])
            ->assertRedirect();
        FeatureRegistry::flush();
        $this->assertSame(FeatureStatus::DISABLED, FeatureRegistry::status('collab.typing')['status']);
        // سُجِّل في تاريخ الإعدادات (سكّةٌ واحدة)
        $this->assertArrayHasKey('feature.collab_typing', Settings::lastChanges(['feature.collab_typing']));

        $this->actingAs($this->owner)->post(route('features.toggle', 'collab.typing'), ['on' => '1'])->assertRedirect();
        FeatureRegistry::flush();
        $this->assertSame(FeatureStatus::ENABLED, FeatureRegistry::status('collab.typing')['status']);
    }

    public function test_a_system_invariant_cannot_be_disabled_through_the_center(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post(route('features.toggle', 'security.authorization'), ['on' => '0'])
            ->assertRedirect();
        // لم يتغيّر شيء — لا مفتاحَ إعدادٍ كُتب
        FeatureRegistry::flush();
        $this->assertSame(FeatureStatus::SYSTEM_INVARIANT, FeatureRegistry::status('security.authorization')['status']);
    }

    public function test_a_non_toggleable_capability_is_refused(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->post(route('features.toggle', 'collab.channels'), ['on' => '0'])->assertRedirect();
        $res->assertSessionHas('err');
    }

    /* ───────── البوّابةُ الآمنة (fail-safe) ───────── */

    public function test_disabling_typing_makes_its_endpoint_fail_safe(): void
    {
        $this->seedCore();
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'ق', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);

        // مُفعَّلٌ افتراضاً: النبضةُ تنجح
        $this->actingAs($this->owner)->post(route('conversations.typing', $conv->id))->assertNoContent();

        // يُطفئه المالكُ من مركز القدرات ⇒ يفشل المسارُ بأمان (٤٠٤)، ولا كتابةَ تُبثّ في since
        FeatureRegistry::setEnabled('collab.typing', false, 'اختبار البوّابة');
        FeatureRegistry::flush();
        $this->actingAs($this->owner)->post(route('conversations.typing', $conv->id))->assertNotFound();
        $res = $this->actingAs($this->owner)->getJson(route('conversations.since', $conv->id))->assertOk();
        $this->assertSame([], $res->json('typing'), 'الكتابةُ تسرّبت رغم إطفاء القدرة');
    }

    public function test_disabling_presence_yields_no_presence_anywhere(): void
    {
        $this->seedCore();
        // مُفعَّلٌ افتراضاً: presence تعيد بنيةً (قد تكون فارغةً لغياب الجلسات، لكنها لا تُقصَر بالقدرة)
        FeatureRegistry::setEnabled('collab.presence', false, 'اختبار');
        FeatureRegistry::flush();
        // مُطفأة ⇒ لا حضورَ في أيِّ سطح (الدالّةُ المركزيّة تعيد [])
        $this->assertSame([], \App\Http\Controllers\Web\DmController::presence([$this->employee->id]));
    }
}
