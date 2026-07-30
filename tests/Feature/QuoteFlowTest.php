<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QuoteFlow — التطبيق الجانبي المعزول: مالكٌ فقط، وحالته على الخادم لا في المتصفح.
 */
class QuoteFlowTest extends TestCase
{
    /** البوابة: غير المالك ممنوع صفحةً وحفظاً */
    public function test_only_owner_can_access(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/apps/quoteflow')->assertForbidden();
        $this->actingAs($this->employee)->post('/apps/quoteflow/save', ['data' => []])->assertForbidden();
        $this->actingAs($this->viewer)->get('/apps/quoteflow')->assertForbidden();
    }

    /** أول فتح: تُبذر النسخة الاحتياطية المرفقة تلقائياً وتظهر بياناتها في الصفحة */
    public function test_first_open_seeds_bundled_backup_into_server_store(): void
    {
        $this->seedCore();
        $res = $this->actingAs($this->owner)->get('/apps/quoteflow')->assertOk();

        $html = $res->getContent();
        $this->assertStringContainsString('qfu_products_v3', $html, 'بذرة الحالة غائبة عن الصفحة');
        $this->assertStringContainsString('Medee SERUM', $html, 'بيانات النسخة المرفقة لم تُبذر');

        $row = DB::table('sideapp_stores')->where('app', 'quoteflow')->first();
        $this->assertNotNull($row, 'المخزن لم يُهيأ من النسخة المرفقة');
        $map = json_decode($row->data, true);
        $this->assertArrayHasKey('qfu_customers_v3', $map);
        $this->assertCount(14, $map, 'خريطة المفاتيح غير كاملة');

        // فتح الصفحة مُدوَّن في تدقيق النظام
        $this->assertDatabaseHas('audits', ['action' => 'فتح QuoteFlow']);
    }

    /** الحفظ: يقبل مفاتيح التطبيق فقط ويتجاهل الدخيل، والفتح التالي يبذر المحفوظ */
    public function test_save_filters_keys_and_next_open_seeds_saved_state(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/apps/quoteflow/save', ['data' => [
            'qfu_products_v3'  => '[{"id":"p_x","name":"منتج محفوظ من الخادم"}]',
            'qfu_customers_v3' => '[]',
            'evil_key'         => 'مرفوض',
            'qfu_quotes_v3'    => ['ليست نصاً'],   // قيمة غير نصية تُتجاهل
        ]])->assertOk()->assertJson(['ok' => true]);

        $map = json_decode(DB::table('sideapp_stores')->where('app', 'quoteflow')->value('data'), true);
        $this->assertArrayHasKey('qfu_products_v3', $map);
        $this->assertArrayNotHasKey('evil_key', $map);
        $this->assertArrayNotHasKey('qfu_quotes_v3', $map);

        $html = $this->actingAs($this->owner)->get('/apps/quoteflow')->assertOk()->getContent();
        $this->assertStringContainsString('منتج محفوظ من الخادم', $html);
        // المحفوظ ساد — لا بذر من النسخة المرفقة فوقه
        $this->assertStringNotContainsString('Medee SERUM', $html);
    }

    /** التصفير: حالة فارغة تمسح نسخة الخادم وتبقى ممسوحة (لا تعود النسخة المرفقة) */
    public function test_empty_state_clears_server_and_stays_cleared(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/apps/quoteflow');   // بذر أولي
        $this->actingAs($this->owner)->post('/apps/quoteflow/save', ['data' => []])->assertOk();

        $html = $this->actingAs($this->owner)->get('/apps/quoteflow')->assertOk()->getContent();
        $this->assertStringNotContainsString('Medee SERUM', $html, 'التصفير ارتدّ — النسخة المرفقة عادت');
    }

    /** حالة أكبر من السقف تُرفض */
    public function test_oversize_state_is_rejected(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/apps/quoteflow/save', ['data' => [
            'qfu_products_v3' => str_repeat('x', 12_100_000),
        ]])->assertStatus(413);
    }

    /**
     * غلاف المزامنة يُحقن بعد آخر `</body>` لا أولها — التطبيق يحمل `</body>` نصاً
     * داخل سكربته (قالب طباعة PDF)، وحقنٌ عند أول ظهورٍ يقطع السكربت فيموت التطبيق
     * كلياً (لا زر يستجيب). أُثبت فشله أولاً ثم أُصلح.
     */
    public function test_sync_shim_is_injected_after_the_app_script_not_inside_it(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/apps/quoteflow')->assertOk()->getContent();

        $shimAt = strpos($html, 'qfsync');
        $this->assertNotFalse($shimAt, 'غلاف المزامنة غائب');
        // آخر عنصر في التطبيق سكربت html2pdf — الغلاف يجب أن يأتي بعده لا قبله
        $this->assertGreaterThan(strpos($html, 'html2pdf'), $shimAt,
            'الغلاف حُقن وسط سكربت التطبيق فقتله');
        // ودالة الطباعة سليمة لم يُقطع نصها
        $this->assertStringContainsString('function printPdfFallback', $html);
        $this->assertSame(1, substr_count($html, "b.id = 'qfsync'"), 'الغلاف حُقن أكثر من مرة أو صفراً');
    }

    /** رابط الشريط: المالك يراه والموظف لا */
    public function test_adminbar_link_visibility(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/')->assertSee('QuoteFlow');
        $this->actingAs($this->employee)->get('/')->assertDontSee('QuoteFlow');
    }
}
