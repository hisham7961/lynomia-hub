<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * مركز التكاملات: كانت المعرفة متناثرة بين شاشة الويبهوك وإعدادات أودو
 * وصفحة المفاتيح — فلا يعرف المالك ما المتاح ولا ما المربوط ولا **أين تنزل
 * بيانات n8n** لصالح أي قسم.
 */
class IntegrationCenterTest extends TestCase
{
    public function test_center_shows_installed_integrations_with_live_state(): void
    {
        $this->seedCore();
        DB::table('webhooks')->insert(['id' => (string) Str::uuid(), 'name' => 'n8n الإنتاج',
            'url' => 'https://n8n.example.com/webhook/x', 'secret' => 's3cr3t', 'events' => '*',
            'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->owner)->get('/admin/integrations')->assertOk()->getContent();

        foreach (['Webhooks', 'أودو', 'REST API', 'الصندوق الصادر'] as $name) {
            $this->assertStringContainsString($name, $html, "التكامل «{$name}» غائب عن المركز");
        }
        $this->assertStringContainsString('1 مفعّل من 1', $html, 'الحالة الحية للويبهوك');
        $this->assertStringContainsString('ماذا يمكن أن نربط؟', $html, 'كتالوج ما يمكن ربطه');
        $this->assertStringContainsString('n8n', $html);
    }

    public function test_guide_answers_where_data_lands_for_each_module(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/integrations/guide')->assertOk()->getContent();

        // الاتجاه الوارد: نقطة استقبال لكل قسم
        $this->assertStringContainsString('أين تنزل بيانات n8n', $html);
        $this->assertStringContainsString('/api/v1/tickets', $html);
        $this->assertStringContainsString('/api/v1/fin', $html);
        $this->assertStringContainsString('Idempotency-Key', $html, 'منع الإنشاء المزدوج موثّق');

        // الاتجاه الصادر: كتالوج الأحداث مولَّداً من السجل
        $this->assertStringContainsString('tickets.created', $html);
        $this->assertStringContainsString('invoice.paid', $html, 'الأحداث الدلالية معروضة');
        $this->assertStringContainsString('X-Hub-Signature', $html, 'التحقق من التوقيع مشروح');

        // أودو: أين يرتبط
        $this->assertStringContainsString('قراءة فقط', $html);
        $this->assertStringContainsString('/m/clients/{id}', $html);
    }

    public function test_center_is_owner_only_and_linked_in_admin_bar(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/integrations')->assertForbidden();
        $this->actingAs($this->employee)->get('/admin/integrations/guide')->assertForbidden();

        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee('التكاملات');
    }
}
