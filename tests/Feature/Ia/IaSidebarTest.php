<?php

namespace Tests\Feature\Ia;

/**
 * **IA الطور 4 — الشريطُ الجانبيُّ مبنيٌّ على IA**: منطقةُ «مساحات العمل» تعرض
 * المجالاتِ بترتيب IA، كلٌّ قابلٌ للطيّ يكشف «نظرة عامة» وأقسامَه المرئيّة —
 * بإعادةِ استعمالِ نفسِ الأصنافِ (`.ni`/`.navsection`/`<details>`/`.nbdg`) بلا CSS جديد.
 *
 * يحرسُ: صفرَ فقدان (كلُّ مساحةٍ ما زالت مبلوغةً عبر «نظرة عامة»)، لا تسريبَ (مجالٌ
 * لا يراه المستخدمُ غائبٌ تماماً)، والإدارةَ (نظام) خارجَ الشريط، والنمطَ الكلاسيكيَّ باقياً.
 */
class IaSidebarTest extends IaTestCase
{
    public function test_sidebar_renders_ia_domains_expandable_to_sections(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/')->assertOk()
            ->assertSee('مساحات العمل')
            ->assertSee('🗂 نظرة عامة')                    // رابطُ صفحةِ المساحة (لا فقدان)
            ->assertSee('الكيانات والعلاقات')              // تسميةُ مجالٍ (من المساحة — اتّساقٌ مع الصفحة)
            ->assertSee('الشركات والمشاريع')               // عنوانُ قسمٍ مرئيٍّ داخل المجال
            ->getContent();

        // رابطُ «نظرة عامة» يشير لصفحةِ /w الحقيقيّة، والقسمُ لمرساةٍ عليها
        $this->assertStringContainsString(route('workspace', 'entities'), $html);
        $this->assertStringContainsString('#sec-', $html);
    }

    /** «نظرة عامة» تفتحُ صفحةَ المساحةِ فعلاً — لا رابطَ ميّت (صفر فقدان) */
    public function test_domain_overview_link_opens_the_workspace_page(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/w/entities')->assertOk()->assertSee('وحدات المساحة');
    }

    /** ترتيبُ IA مُطبَّقٌ: «العمل» (order 20) قبل «التقنية» (order 50) — لا ترتيبُ الإعداد القديم */
    public function test_sidebar_domains_follow_ia_order(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $posWork = mb_strpos($html, 'العمل والعمليات');
        $posTech = mb_strpos($html, 'التقنية والبنية الرقمية');
        $this->assertNotFalse($posWork);
        $this->assertNotFalse($posTech);
        $this->assertLessThan($posTech, $posWork, 'ترتيبُ IA لم يُطبَّق على الشريط (العمل قبل التقنية)');
    }

    /** لا تسريب: مستخدمٌ يرى الكياناتِ فقط لا يظهر له مجالُ المالية في الشريط */
    public function test_sidebar_hides_domains_the_user_cannot_see(): void
    {
        $this->seedCore();
        $u = $this->scopedUser(['companies', 'projects', 'clients']);

        $html = $this->actingAs($u)->get('/')->assertOk()
            ->assertSee('الكيانات والعلاقات')     // يراها
            ->getContent();

        $this->assertStringNotContainsString('المالية والمشتريات', $html, 'مجالٌ محجوبٌ ظهر في الشريط');
    }

    /** النمطُ الكلاسيكيُّ يبقى: القائمةُ الكاملةُ للوحدات إلى جانب المجالات (لا حذفَ قدرة) */
    public function test_classic_style_still_lists_full_modules_alongside_ia_domains(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['nav' => ['style' => 'classic']];
        $this->owner->save();

        $this->actingAs($this->owner->fresh())->get('/')->assertOk()
            ->assertSee('<div class="navsection">الوحدات</div>', false)
            ->assertSee('<div class="navsection">مساحات العمل</div>', false);
    }
}
