<?php

namespace Tests\Feature\Ia;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * **IA الطور 5 — الفتاتُ الدلاليّ**: مُحلِّلٌ واحدٌ `breadcrumbs()` يعطي مسارَ
 * مجال ← قسم ← وجهة (← سجلّ) لكلِّ مسارٍ، وجزءٌ واحدٌ مشتركٌ يعرضُه (لا فتاتَ لكلِّ Blade).
 */
class IaBreadcrumbsTest extends IaTestCase
{
    /** يبني طلباً حقيقيّاً مربوطاً بالمسار المُسمّى ثمّ يسأل المُحلِّل */
    private function crumbLabels(string $uri, string $name, User $user): array
    {
        $route = app('router')->getRoutes()->getByName($name);
        $this->assertNotNull($route, "المسار «{$name}» غير مسجّل");
        $request = Request::create('/' . ltrim($uri, '/'));
        $request->setRouteResolver(fn () => $route->bind($request));

        return array_values(array_map(fn ($c) => $c['label'], $this->ia()->breadcrumbs($request, $user)));
    }

    public function test_module_index_trail_is_domain_then_section(): void
    {
        $this->seedCore();
        $labels = $this->crumbLabels('m/companies', 'm.index', $this->owner);

        $this->assertContains('الكيانات والعلاقات', $labels, 'الفتاتُ لا يحمل المجال');
        $this->assertContains('الشركات والمشاريع', $labels, 'الفتاتُ لا يحمل القسم');
        // المجالُ يسبق القسم (ترتيبٌ دلاليّ)
        $this->assertLessThan(array_search('الشركات والمشاريع', $labels, true),
            array_search('الكيانات والعلاقات', $labels, true));
    }

    public function test_module_show_appends_record_id(): void
    {
        $this->seedCore();
        $labels = $this->crumbLabels('m/companies/5', 'm.show', $this->owner);

        $this->assertContains('الكيانات والعلاقات', $labels);
        $this->assertSame('#5', end($labels), 'سجلٌّ مفردٌ يُلحَق مُعرِّفُه آخرَ الفتات');
    }

    public function test_workspace_trail_is_the_domain(): void
    {
        $this->seedCore();
        $labels = $this->crumbLabels('w/finance', 'workspace', $this->owner);
        $this->assertContains('المالية والمشتريات', $labels);
    }

    public function test_admin_route_trail_is_administration_domain_and_section(): void
    {
        $this->seedCore();
        $labels = $this->crumbLabels('admin/settings', 'settings.edit', $this->owner);

        $this->assertContains('الإدارة والنظام', $labels, 'الفتاتُ لا يحمل مجالَ الإدارة');
        $this->assertContains('الإعدادات والتكاملات', $labels, 'الفتاتُ لا يحمل قسمَ الإعدادات');
    }

    /** المُحلِّلُ لا يرمي على أيِّ مسارٍ مجهول — دلوٌ مُسمّى (نظير IaRouteCoverage) */
    public function test_unknown_route_yields_a_bucket_not_an_exception(): void
    {
        $this->seedCore();
        $request = Request::create('/nowhere');
        $request->setRouteResolver(fn () => null);
        $trail = $this->ia()->breadcrumbs($request, $this->owner);
        $this->assertIsArray($trail);   // لا استثناء
    }

    /* ═════════ عرضُ الجزء المشترك في التخطيط ═════════ */

    public function test_shared_partial_renders_on_a_module_page(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/m/companies')->assertOk()->getContent();

        // الجزءُ ذو الصنفِ المميّز iacrumb حاضرٌ (مسارٌ ذو دلالةٍ ≥ عنصرين)
        $this->assertStringContainsString('iacrumb', $html, 'فتاتُ المعمارية لم يُعرَض على صفحة الوحدة');
    }

    /** لا فتاتَ للزائر (قبل الدخول) — محايدٌ ولا يُعرَض إلا لمستخدمٍ */
    public function test_no_breadcrumb_for_guests(): void
    {
        $this->seedCore();
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('iacrumb', $html);
    }
}
