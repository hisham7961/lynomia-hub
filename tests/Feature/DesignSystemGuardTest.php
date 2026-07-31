<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UI د1 (v2.125): حارس نظام التصميم — يمنع عودة الصنفين الأخطر من العلل الصامتة:
 * var() بلا تعريف (يبطل الإعلان كله فتختفي حدود وألوان بلا خطأ ظاهر)، وأصنافٌ
 * يبثها JS أو القوالب بلا CSS يقابلها.
 */
class DesignSystemGuardTest extends TestCase
{
    /** كل var(--x) بلا قيمة احتياطية في app.css له تعريف --x: فعلي */
    public function test_every_css_variable_reference_is_defined(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        preg_match_all('/--([a-zA-Z][\w-]*)\s*:/', $css, $defs);
        $defined = array_unique($defs[1]);

        // المراجع بلا احتياطي فقط — var(--x,fallback) ينجو بذاته
        preg_match_all('/var\(--([a-zA-Z][\w-]*)\s*\)/', $css, $refs);
        $missing = array_diff(array_unique($refs[1]), $defined,
            ['mh', 'st', 'ql', 'av', 'seg', 'w']);   // توكنز نطاقية تضبطها القوالب سطرياً

        $this->assertSame([], array_values($missing),
            'متغيرات CSS مرجوعة بلا تعريف (تبطل إعلاناتها بصمت): ' . implode(', ', $missing));
    }

    /** المتغيرات النطاقية داخل القوالب (var(--brd) في المساحات المخصصة) معرفة أيضاً */
    public function test_view_level_variable_references_are_defined(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        foreach (['--brd', '--line'] as $alias) {
            $this->assertMatchesRegularExpression('/' . preg_quote($alias, '/') . '\s*:/', $css,
                "المرادف $alias تستهلكه القوالب — يجب أن يبقى معرفاً في :root");
        }
    }

    /** أصناف يبثها app.js أو تستعملها القوالب — كانت بلا CSS فتظهر عارية */
    public function test_classes_emitted_by_js_and_views_have_css(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        foreach (['.flash.wn', '.mut{', '.reltl{'] as $needle) {
            $this->assertStringContainsString($needle, $css, "الصنف $needle بلا تعريف في app.css");
        }
        // .mini مستقل — لا يشترط حاضنة .kid (جداول التوقيع والتحقق خارجها)
        $this->assertStringContainsString("\n.mini{", $css,
            '.mini يجب أن يكون صنفاً مستقلاً لا مقيداً بـ.kid');
        $this->assertStringNotContainsString('.kid .mini{', $css);
        // الخط الزمني الموحد يملك .tl وحده — تصادم الإصدارات حُسم بالاسم الخاص .reltl
        $this->assertSame(1, substr_count($css, "\n.tl{"),
            '.tl يجب أن يكون له تعريف واحد لا تعريفان متناقضان');
    }

    /** د2 (v2.126): لا width:1% مضمّنة (الصنف .acts هو الطريق) وكل جداول .tbl ملفوفة بحاويتها */
    public function test_tables_use_acts_class_and_tblwrap(): void
    {
        $bad = [];
        foreach (glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $f) {
            $s = file_get_contents($f);
            if (str_contains($s, 'style="width:1%')) $bad[] = basename(dirname($f)) . '/' . basename($f) . ': width:1% مضمّنة';
        }
        foreach (['users/index', 'audit/index', 'support/index', 'roles/index', 'dataroom/index',
                  'boards/index', 'flows/index', 'flows/sandbox', 'performance/index', 'personalize'] as $v) {
            $s = file_get_contents(resource_path("views/$v.blade.php"));
            if (str_contains($s, '<table class="tbl"') && ! str_contains($s, 'tblwrap')) {
                $bad[] = "$v: جدول .tbl بلا .tblwrap — رؤوس لاصقة مكسورة وفيضان جوال";
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    /** أوزان الخط المطلوبة كلها محملة فعلاً — لا faux-bold مركّب من المتصفح */
    public function test_no_font_weight_beyond_loaded_families(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringNotContainsString('font-weight:800', $css,
            'وزن 800 غير محمل لخط Plex Arabic — استعمل 700 الحقيقي بدل التسمين المركب');
    }
}
