<?php

namespace Tests\Feature\Ia;

/**
 * **IA الطور 7 — لوحةُ الأوامر تقرأ IA**: `SearchController::destinations()` صار يستمدُّ
 * شريحةَ الوجهات من `InformationArchitecture::searchDestinations()` — بمرادفاتٍ ar/en
 * جديدة، مع بقاءِ `operational()`/`workOs()`/الدمجِ/الإزالةِ بالرابط حرفاً بحرف (C3)،
 * ومرادفاتِ `find` الإداريّةِ القديمةِ محفوظة. مُنطَّقٌ بحارسِ كلِّ وجهة.
 */
class IaSearchTest extends IaTestCase
{
    /** @return string[] مساراتُ وجهاتِ البحث للاستعلام */
    private function routes(string $q, $user): array
    {
        return array_values(array_filter(array_map(
            fn ($h) => $h['route'] ?? null,
            $this->ia()->searchDestinations($user, $q))));
    }

    /** مرادفٌ عربيٌّ على مستوى الوجهة يَصِل (الجرد ⇐ مركزُ الجرد) */
    public function test_arabic_synonym_resolves_a_destination(): void
    {
        $this->seedCore();
        $this->assertContains('inventory.center', $this->routes('الجرد', $this->owner),
            'المرادفُ العربيُّ «الجرد» لا يصل إلى مركز الجرد');
    }

    /** مرادفٌ إنجليزيٌّ يَصِل (inventory ⇐ مركزُ الجرد) — قدرةٌ جديدة */
    public function test_english_synonym_resolves_a_destination(): void
    {
        $this->seedCore();
        $this->assertContains('inventory.center', $this->routes('inventory', $this->owner),
            'المرادفُ الإنجليزيُّ «inventory» لا يصل');
    }

    /** مرادفُ مركزٍ مستقلّ (لوحات ⇐ boards.index) */
    public function test_center_synonym_resolves(): void
    {
        $this->seedCore();
        $this->assertContains('boards.index', $this->routes('لوحات', $this->owner));
        $this->assertContains('system-map', $this->routes('خريطة', $this->owner));
    }

    /** التنطيق: مستخدمٌ بلا رؤيةِ الأصول لا يجد مركزَ الجرد ولو طابق المرادف */
    public function test_synonym_is_permission_filtered(): void
    {
        $this->seedCore();
        $u = $this->scopedUser(['companies', 'projects']);   // بلا assets

        $this->assertNotContains('inventory.center', $this->routes('الجرد', $u),
            'مركزُ الجرد ظهر لمستخدمٍ لا يرى الأصول — تسريبٌ عبر المرادف');
    }

    /** مرادفُ `find` الإداريُّ القديمُ ما زال يصل (عبر IA الآن · C3) */
    public function test_old_admin_find_synonym_still_resolves(): void
    {
        $this->seedCore();
        // «التكاملات» و«نشاط الموظفين» — أسماءٌ إداريّةٌ يجب أن تظلَّ تصل عبر البحث
        $this->actingAs($this->owner);
        $this->get('/search?q=التكاملات')->assertOk()->assertSee(route('integrations.index'), false);
        $this->get('/search?q=نشاط الموظفين')->assertOk()->assertSee(route('activity.index'), false);
    }

    /** الدمجُ لم يُكسَر (C3): البحثُ التشغيليّ (بريدُ حساب) ما زال يصل بجانب الوجهات */
    public function test_operational_search_still_merged_after_ia_slice(): void
    {
        $this->seedCore();
        // المالكُ يبحث ببريد المستخدم القائم → مطابقةٌ تشغيليّةٌ (حسابُ …) تبقى
        $html = $this->actingAs($this->owner)->get('/search/mini?q=' . urlencode($this->owner->email))
            ->assertOk()->getContent();
        $this->assertStringContainsString(route('users.edit', $this->owner->id), $html,
            'المطابقةُ التشغيليّةُ (بريدُ حساب) ضاعت بعد إدخال شريحة IA');
    }
}
