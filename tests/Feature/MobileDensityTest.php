<?php

namespace Tests\Feature;

use Tests\TestCase;

/** v2.115: كثافة الجوال — حارس بنيوي يمنع تراجع القواعد التي قاست 632→423 بكسل ميدانياً */
class MobileDensityTest extends TestCase
{
    public function test_density_layer_is_last_so_it_wins_the_cascade(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        // كتلة الكثافة موجودة، وتقع بعد تعريف .rh-acts الأساسي — الترتيب هو ما جعلها تسري
        $block = strpos($css, 'كثافة الجوال (v2.115)');
        $this->assertNotFalse($block);
        $this->assertGreaterThan(strpos($css, '.rh-acts{display:flex;gap:8px'), $block,
            'كتلة v2.115 يجب أن تبقى آخر الملف وإلا جاوزتها قواعد المكونات الأساسية');

        // الجواهر: صف أفعال منزلق لا يلتف، والزر الأساسي يتصدره، وبانر التجربة صنفٌ لا نمطاً مضمّناً
        $this->assertStringContainsString('.rh-acts{flex-basis:100%;display:flex;flex-wrap:nowrap', $css);
        $this->assertStringContainsString('.rh-acts .btn.p{order:-1}', $css);
        $this->assertStringContainsString('.demoband{background:repeating-linear-gradient', $css);
    }

    public function test_demo_banner_uses_class_not_inline_style(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('class="demoband"', $layout);
        $this->assertStringNotContainsString('repeating-linear-gradient(45deg,#7c3aed', $layout);
    }
}
