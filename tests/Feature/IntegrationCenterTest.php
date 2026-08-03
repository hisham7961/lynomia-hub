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

        // البوابة الموحّدة تصل بكل شاشة إعداد — أودو صفٌّ واحدٌ جامع لا صفّان
        $this->assertStringContainsString('التكاملات وإعداداتها — في مكان واحد', $html);
        foreach (['أودو — كل شيء', 'مفاتيح REST API', 'مركز المراسلة'] as $rowName) {
            $this->assertStringContainsString($rowName, $html, "صف «{$rowName}» غائب عن البوابة");
        }

        // منصات البيع في الكتالوج موسومةً «عبر أودو» — لا مفاتيح منصات
        $this->assertStringContainsString('قنوات البيع (عبر أودو)', $html);
        foreach (['ترنديول', 'أمازون', 'نون', 'المتجر الإلكتروني'] as $chan) {
            $this->assertStringContainsString($chan, $html, "قناة «{$chan}» غائبة عن الكتالوج");
        }
    }

    /**
     * **«وجدتُ لخبطةً في أماكن التكاملات وتكراراً في أماكن ربط أودو».**
     *
     * كانت إدارةُ أودو موزَّعةً: الافتراضيُّ في الإعدادات والإضافيون في المركز،
     * وجدولُ «أين يرتبط أودو» في الفهرس والدليلُ في شاشةٍ ثالثة. صار كلُّ
     * شيءٍ في شاشة خوادم أودو، والفهرسُ بوابةً بلا تفاصيلَ مكررة.
     */
    public function test_odoo_is_managed_in_exactly_one_place(): void
    {
        $this->seedCore();

        // شاشة أودو تدير الافتراضيَّ كاملاً: نموذجه وزر اختباره وجدول الارتباط
        $odoo = $this->actingAs($this->owner)->get('/admin/integrations/odoo')->assertOk()->getContent();
        $this->assertStringContainsString('الاتصال الافتراضي', $odoo);
        $this->assertStringContainsString(route('integrations.odoo.defaults'), $odoo, 'لا نموذج للافتراضي في بيته');
        $this->assertStringContainsString(route('settings.odoo.test'), $odoo, 'لا زر اختبارٍ للافتراضي في بيته');
        $this->assertStringContainsString('أين يرتبط أودو بالنظام', $odoo, 'جدول الارتباط لم ينتقل لبيته');

        // والفهرس بوابة: لا يكرر جدول الارتباط ولا يشتت لشاشة الإعدادات
        $index = $this->actingAs($this->owner)->get('/admin/integrations')->assertOk()->getContent();
        $this->assertStringNotContainsString('أين يرتبط أودو بالنظام', $index, 'الجدول مكرر في الفهرس');
        $this->assertSame(0, substr_count($index, 'اتصال أودو الافتراضي'),
            'الفهرس ما زال يوجه لشاشة الإعدادات لإدارة أودو — التشتت باقٍ');
    }

    /** حفظ الافتراضي من شاشة أودو يكتب المفاتيح نفسها — والمفتاح مشفَّر وبقاؤه عند الفراغ */
    public function test_default_connection_saves_through_the_odoo_hub(): void
    {
        $this->seedCore();
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);

        $this->actingAs($this->owner)->post('/admin/integrations/odoo/defaults', [
            'url' => 'https://main.example.com', 'db' => 'maindb',
            'username' => 'ro@main.com', 'key' => 'sekret-1',
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('https://main.example.com', setting('odoo.url'));
        $this->assertSame('sekret-1', setting('odoo.key'), 'القراءة الشفافة تفك المشفر');
        $this->assertStringStartsWith('enc:', (string) \App\Models\Setting::where('key', 'odoo.key')->value('value'),
            'المفتاح كُتب نصاً صريحاً');

        // مفتاح فارغ يبقي المخزون — نمط pass نفسه
        $this->actingAs($this->owner)->post('/admin/integrations/odoo/defaults', [
            'url' => 'https://main.example.com', 'db' => 'db2', 'username' => 'ro@main.com', 'key' => '',
        ]);
        $this->assertSame('db2', setting('odoo.db'));
        $this->assertSame('sekret-1', setting('odoo.key'), 'الفراغ محا المفتاح المخزون');
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
