<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Support\FeatureRegistry;
use Tests\TestCase;

/**
 * **تدقيقُ الطور النهائيّ — إصلاحاتٌ مُثبَتة.** كلُّ اختبارٍ يُثبّت عيباً حقيقيّاً كُشِف في
 * التدقيق الختاميّ فلا يعود: عزلُ العميل على `/api/v1`، صدقُ حالةِ دفاعِ IP، أثرُ حالةِ الأصل
 * عبر العهدة، وحدُّ نافذةِ الرسائل.
 */
class FinalAuditHardeningTest extends TestCase
{
    /** AUDIT-1 (§14): حسابُ عميلٍ — ولو مُنِح دوراً — لا يبلغ وحدةً خارجَ قائمته على /api/v1 */
    public function test_client_api_token_is_confined_to_allowed_modules(): void
    {
        $this->seedCore();
        // عميلٌ مُنِح دوراً واسعاً (السيناريو الذي يجب أن ينجو منه العزل — لا يعتمد على مصفوفة الدور)
        $role = Role::create(['name' => 'عميلٌ بدور', 'scope' => 'all', 'flags' => [],
            'matrix' => ['servers' => ['v' => 1], 'projects' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@t.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $tok = ['Authorization' => 'Bearer ' . $this->apiToken($client)];

        // وحدةٌ داخليّةٌ خارجَ قائمة العميل → ٤٠٤ (لا تسريبَ عبر الشركات، لا كشفَ وجود)
        $this->withHeaders($tok)->getJson('/api/v1/servers')->assertNotFound();
        // وحدةٌ مسموحةٌ للعميل (projects) لا تُحجب بسبب نوع الحساب (العزلُ على مستوى الصفوف يتكفّل)
        $this->withHeaders($tok)->getJson('/api/v1/projects')->assertOk();
    }

    /** ولا يُكسر الداخليُّ: مستخدمٌ داخليٌّ بدوره يبلغ servers كالمعتاد */
    public function test_internal_api_token_still_reaches_modules(): void
    {
        $this->seedCore();
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiToken($this->owner)])
            ->getJson('/api/v1/servers')->assertOk();
    }

    /** AUDIT-2 (§8/§110): دفاعُ IP في طبقة التطبيق نشطٌ دائماً — لا يُشتقُّ «غيرَ مُهيّأ» زوراً */
    public function test_app_ip_defense_reports_enabled_not_misconfigured(): void
    {
        $this->assertSame('ENABLED', FeatureRegistry::status('security.app_ip_defense')['status']);
        // والحجبُ عند الحافّة (خارجيّ) يبقى صادقاً «غيرَ مُهيّأ» بلا مزوّد
        $this->assertSame('NOT_CONFIGURED', FeatureRegistry::status('security.edge_blocking')['status']);
    }

    /** AUDIT-4 (§25): مسارُ مواصفةِ الـAPI مُسمّىً فلا يشير سجلُّ القدرات إلى اسمٍ معدوم */
    public function test_openapi_route_is_named(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('api.openapi'));
    }

    /** AUDIT-6 (§39/§63): مزامنةُ حالةِ الأصلِ من الصيانة تمرّ بالعهدة — أثرٌ في asset_custody */
    public function test_maintenance_status_sync_goes_through_custody_audit_trail(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'طابعة', 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->post('/m/assetlog', [
            'assetId' => $asset->id, 'title' => 'إصلاح', 'date' => now()->toDateString(), 'status' => 'قيد التنفيذ',
        ]);

        $this->assertSame('صيانة', $asset->fresh()->status);
        // الأثرُ: صفُّ تغييرِ حالةٍ في asset_custody (لم يكن يُكتَب مع saveQuietly السابق)
        $this->assertTrue(AssetCustody::where('asset_id', $asset->id)->where('action', 'تغيير حالة')->exists(),
            'تغييرُ حالةِ الأصلِ لم يُسجَّل في سجل العهدة (asset_custody)');
    }

    /** AUDIT-6: أصلٌ نهائيٌّ (مباع) لا يُحيا بمزامنةِ الصيانة (الانتقالُ غيرُ المشروع يُتخطّى) */
    public function test_maintenance_sync_does_not_resurrect_terminal_asset(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'مباع', 'status' => 'مباع']);

        $this->actingAs($this->owner)->post('/m/assetlog', [
            'assetId' => $asset->id, 'title' => 'محاولة', 'date' => now()->toDateString(), 'status' => 'قيد التنفيذ',
        ]);

        $this->assertSame('مباع', $asset->fresh()->status, 'أصلٌ نهائيٌّ أُحيي إلى صيانة بغير انتقالٍ مشروع');
    }

    /** AUDIT-3 (§56): نافذةُ رسائلِ الحاوية محدودةٌ — أحدثُ N بترتيبٍ زمنيّ صاعد */
    public function test_conversation_root_messages_are_bounded_and_chronological(): void
    {
        $this->seedCore();
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناة', 'created_by' => $this->owner->id]);
        for ($i = 1; $i <= 6; $i++) {
            \App\Models\Comment::create(['conversation_id' => $conv->id, 'module' => 'channel',
                'record_id' => (string) $conv->id, 'user_id' => $this->owner->id, 'body' => 'م' . $i,
                'created_at' => now()->addSeconds($i), 'updated_at' => now()->addSeconds($i)]);
        }

        $rows = $conv->rootMessages(3);
        $this->assertCount(3, $rows, 'النافذةُ لم تُحدَّد بـ3');
        // أحدثُ ثلاثٍ بترتيبٍ صاعد: م4، م5، م6
        $this->assertSame(['م4', 'م5', 'م6'], $rows->pluck('body')->all());
    }
}
