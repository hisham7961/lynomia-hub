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

    /** «جمع كل التكاملات في مكان واحد»: بوابة موحّدة + قنوات البيع عبر أودو */
    public function test_center_gathers_everything_and_marks_sales_channels_via_odoo(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/integrations')->assertOk()->getContent();

        // البوابة الموحّدة تصل بكل شاشة إعداد
        $this->assertStringContainsString('التكاملات وإعداداتها — في مكان واحد', $html);
        foreach (['خوادم أودو', 'مفاتيح REST API', 'الصندوق الصادر'] as $rowName) {
            $this->assertStringContainsString($rowName, $html, "صف «{$rowName}» غائب عن البوابة");
        }

        // منصات البيع في الكتالوج موسومةً «عبر أودو» — لا مفاتيح منصات
        $this->assertStringContainsString('قنوات البيع (عبر أودو)', $html);
        foreach (['ترنديول', 'أمازون', 'نون', 'المتجر الإلكتروني'] as $chan) {
            $this->assertStringContainsString($chan, $html, "قناة «{$chan}» غائبة عن الكتالوج");
        }
    }

    /** الدليل التفصيلي على شاشة خوادم أودو يغطي الخطوات السبع */
    public function test_odoo_hub_carries_the_detailed_connection_guide(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/integrations/odoo')->assertOk()->getContent();

        foreach (['مستخدمَ قراءةٍ مخصصاً', 'مفتاح API', 'اسم القاعدة', 'اختبره',
                  'شريكَ كل مشروع', 'قنوات البيع لكل مشروع', 'استكشاف الأخطاء'] as $step) {
            $this->assertStringContainsString($step, $html, "خطوة «{$step}» غائبة عن الدليل");
        }
        // وجدول الأعطال يشرح أخبثها: تبديل APP_KEY يفشل فك التشفير صامتاً
        $this->assertStringContainsString('APP_KEY', $html);
    }
}
