<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * البحث الشامل صار الطريق الواحد: لوحة ⌘K أُزيلت، والبحث يصل لكل أجزاء النظام —
 * السجلات والوحدات والأدوات وصفحات الإدارة — بلا أي اقتراح إضافة.
 */
class SearchDestinationsTest extends TestCase
{
    /** لوحة ⌘K اختفت من الصفحة كلياً */
    public function test_command_palette_is_gone(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('⌘K', $html);
        $this->assertStringNotContainsString('id="palette"', $html);
        $this->assertStringNotContainsString('navdata', $html);
    }

    /** البحث يجد الصفحات بالاسم: أداة، وحدة، وصفحة إدارة */
    public function test_search_reaches_tools_modules_and_admin_pages(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->get('/search?q=التقويم')->assertOk()->assertSee('صفحات وأقسام')->assertSee(route('calendar'), false);
        $this->get('/search?q=المهام')->assertOk()->assertSee(route('m.index', 'tasks'), false);
        $this->get('/search?q=الإعدادات')->assertOk()->assertSee(route('settings.edit'), false);
        $this->get('/search?q=QuoteFlow')->assertOk()->assertSee(route('quoteflow'), false);
    }

    /** النتائج الحية (mini) تعرض الوجهات أيضاً */
    public function test_mini_search_includes_destinations(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/search/mini?q=التقويم')->assertOk()->getContent();
        $this->assertStringContainsString(route('calendar'), $html);
        $this->assertStringContainsString('صفحة', $html);
    }

    /** الصلاحيات محترمة: الموظف لا يجد صفحات لا يملكها، ولا أزرار إضافة في النتائج */
    public function test_destinations_respect_permissions_and_offer_no_creation(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get('/search?q=الإعدادات')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('settings.edit'), $html);

        $html2 = $this->actingAs($this->owner)->get('/search?q=المهام')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('m.create', 'tasks'), $html2, 'البحث يقترح إضافة!');
        $this->assertStringNotContainsString('＋ جديد', $html2);
    }
}
