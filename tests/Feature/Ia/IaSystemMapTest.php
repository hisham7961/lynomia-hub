<?php

namespace Tests\Feature\Ia;

/**
 * **IA الطور 6 — خريطةُ النظام «/system-map»**: الشجرةُ الكاملةُ المُنطَّقة، للمالك
 * لوحةُ تشخيصٍ حيّة. يحرسُ: العرضَ لكلِّ داخليّ، التنطيقَ (لا تسريبَ مجالٍ محجوب)،
 * حصرَ التشخيص بالمالك، وثباتَ المعمارية (يتيم = 0، بيتٌ مكرّر = 0).
 */
class IaSystemMapTest extends IaTestCase
{
    public function test_route_renders_the_full_tree_for_an_internal_user(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/system-map')->assertOk()
            ->assertSee('خريطة النظام')
            ->assertSee('الكيانات والعلاقات')      // مجال
            ->assertSee('الشركات والمشاريع')       // قسم
            ->assertSee('مهامّي');                 // سطحٌ عامّ
    }

    public function test_guest_is_redirected(): void
    {
        $this->seedCore();
        $this->get('/system-map')->assertRedirect();
    }

    /** التنطيق: مستخدمٌ يرى الكياناتِ فقط لا يظهر له مجالُ المالية في الخريطة (لا تسريب) */
    public function test_map_is_scoped_and_leaks_no_hidden_domain(): void
    {
        $this->seedCore();
        $u = $this->scopedUser(['companies', 'projects', 'clients']);

        $html = $this->actingAs($u)->get('/system-map')->assertOk()
            ->assertSee('الكيانات والعلاقات')
            ->getContent();

        $this->assertStringNotContainsString('المالية والمشتريات', $html, 'مجالٌ محجوبٌ ظهر في الخريطة');
        // ولا لوحةَ تشخيصٍ لغير المالك
        $this->assertStringNotContainsString('تشخيصُ المعمارية', $html, 'غيرُ المالك رأى لوحةَ التشخيص');
    }

    public function test_owner_sees_the_diagnostic_panel(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/system-map')->assertOk()
            ->assertSee('تشخيصُ المعمارية')
            ->assertSee('يتيمة (يجب 0)');
    }

    /** الثباتُ الحيّ (نظيرُ 06-final-audit): صفرُ يتيمٍ وصفرُ بيتٍ مكرّر */
    public function test_diagnostic_reports_zero_orphans_and_zero_duplicate_homes(): void
    {
        $this->seedCore();
        $dg = $this->ia()->systemMap($this->owner)['diagnostic'] ?? null;

        $this->assertNotNull($dg, 'خريطةُ المالك بلا تشخيص');
        $this->assertSame([], $dg['orphans'], 'وحداتٌ يتيمةٌ بلا بيت: ' . implode('، ', $dg['orphans']));
        $this->assertSame([], $dg['duplicate_homes'],
            'بيوتٌ مكرّرة: ' . implode('، ', array_keys($dg['duplicate_homes'])));
        // كلُّ الوحدات مُصنّفة: مُبيَّتة أو مؤرشفة (لا حالةَ ثالثة)
        $this->assertSame($dg['module_count'], $dg['homed'] + count($dg['deprecated']),
            'وحداتٌ لا مُبيَّتةٌ ولا مؤرشفة');
    }

    /** المدخلُ ظاهرٌ في البار العلوي — أداةُ اكتشافٍ لا مخفيّة */
    public function test_system_map_link_is_discoverable_in_the_top_bar(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/')->assertOk()
            ->assertSee(route('system-map'), false);
    }
}
