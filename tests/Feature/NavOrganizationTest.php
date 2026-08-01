<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * تنظيم القائمة: الأدوات الـ٢١ المسطّحة صارت ثلاثة أقسام مجموعةً
 * (مساحتي اليومية · التحليلات واللوحات · مراكز متخصّصة)، مع احترام الصلاحيات والإخفاء.
 */
class NavOrganizationTest extends TestCase
{
    private function limited(string $email, array $matrixOverride = [], array $flags = []): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        foreach ($matrixOverride as $k => $v) $matrix[$k] = $v;
        $role = Role::create(['name' => 'دور ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_flat_shape_of_hub_top_links_is_preserved(): void
    {
        $this->seedCore();
        $links = hub_top_links($this->owner);

        $this->assertNotEmpty($links);
        foreach ($links as $l) {
            // المستهلكون القدامى (اللوحة، التخصيص، شاشة البداية) يعتمدون هذه المفاتيح
            $this->assertArrayHasKey('key', $l);
            $this->assertArrayHasKey('label', $l);
            $this->assertArrayHasKey('route', $l);
            $this->assertArrayHasKey('group', $l);          // الحقل الجديد
            $this->assertContains($l['group'], ['daily', 'analytics', 'centers']);
        }
    }

    public function test_groups_bucket_every_permitted_link_once(): void
    {
        $this->seedCore();
        $flat = collect(hub_top_links($this->owner))->pluck('key')->sort()->values();
        $grouped = collect(hub_top_groups($this->owner))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'))->sort()->values();

        // لا رابط يضيع ولا يتكرر بين المسطّح والمجموع
        $this->assertEquals($flat->all(), $grouped->all());
    }

    public function test_daily_group_is_open_and_others_collapsed_by_default(): void
    {
        $this->seedCore();
        $groups = collect(hub_top_groups($this->owner))->keyBy('label');

        $this->assertTrue($groups['مساحتي اليومية']['open']);
        $this->assertFalse($groups['اللوحات والمراكز']['open']);
    }

    public function test_sidebar_renders_region_labels_and_group_headers(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        // النمط الافتراضي «مساحات العمل» (CTO م2): المساحات والأدوات ظاهرة
        foreach (['مساحات العمل', 'الأدوات واللوحات'] as $region) {
            $this->assertStringContainsString('<div class="navsection">' . $region . '</div>', $html, "منطقة «{$region}» غائبة");
        }
        // القائمة الكاملة للوحدات مطويّة على لوحة التحكم في النمط الافتراضي — لا ازدحام
        $this->assertStringNotContainsString('<div class="navsection">الوحدات</div>', $html);
        foreach (['مساحتي اليومية', 'اللوحات والمراكز'] as $g) {
            $this->assertStringContainsString($g, $html, "قسم «{$g}» غائب");
        }
        // «النظام» غادر الجانبي إلى شريط الإدارة العلوي (v2.89)
        // لم تبقَ قائمة الأدوات المسطّحة: الرابط المسطّح الوحيد هو لوحة التحكم
        $this->assertSame(1, substr_count($html, 'class="ni top'));
    }

    /** النمط الكلاسيكي (تفضيل مستخدم) يُعيد القائمة الكاملة للوحدات — لا حذف قدرة */
    public function test_classic_nav_style_shows_full_module_list(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['nav' => ['style' => 'classic']];
        $this->owner->save();

        $html = $this->actingAs($this->owner->fresh())->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="navsection">الوحدات</div>', $html);
        // ومساحات العمل تبقى ظاهرة أيضاً في النمط الكلاسيكي — إضافة لا استبدال
        $this->assertStringContainsString('<div class="navsection">مساحات العمل</div>', $html);
    }

    /** حتى في نمط المساحات، تصفّح وحدة يُظهر قائمتها كي لا يفقد المستخدم سياقه */
    public function test_spaces_nav_style_still_shows_modules_on_module_page(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="navsection">الوحدات</div>', $html);
    }

    public function test_a_hidden_top_link_is_removed_from_its_group(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['nav' => ['hidden_top' => ['ceo']]];
        $this->owner->save();

        $keys = collect(hub_top_groups($this->owner->fresh()))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'));
        $this->assertFalse($keys->contains('ceo'), 'رابطٌ مُخفى بقي في مجموعته');
    }

    /** قسمٌ تفرغ روابطه كلها (صلاحية أو إخفاء) لا يُعرض رأسه أصلاً */
    public function test_a_fully_empty_group_is_suppressed(): void
    {
        $this->seedCore();
        // بلا monitor، ومحجوب عن كل ما يُبقي روابط «اللوحات والمراكز» بعد الدمج
        $none = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $u = $this->limited('noanalytics@test.local', [
            'fin' => $none, 'contracts' => $none, 'tickets' => $none, 'plans' => $none,
            'media' => $none, 'events' => $none, 'hr' => $none,
            'ideas' => $none, 'suppliers' => $none, 'social' => $none, 'okrs' => $none, 'policies' => $none,
            'feats' => $none, 'deploys' => $none, 'requests' => $none, 'designs' => $none,
            'assets' => $none, 'compliance' => $none,
        ]);

        $labels = collect(hub_top_groups($u))->pluck('label');
        $this->assertFalse($labels->contains('اللوحات والمراكز'), 'قسمٌ فارغ عُرض رأسه');
        $this->assertTrue($labels->contains('مساحتي اليومية'), 'القسم اليومي يبقى دائماً');
    }

    /** لكن قسمٌ فيه رابطٌ واحد مسموح يُعرض */
    public function test_a_group_with_one_permitted_link_is_shown(): void
    {
        $this->seedCore();
        // يرى المالية وحدها من روابط القسم المدموج ⇒ finrep يبقى، فيُعرض القسم به وحده
        $none = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $u = $this->limited('onlyfin@test.local', [
            'contracts' => $none, 'tickets' => $none, 'ideas' => $none, 'suppliers' => $none,
            'social' => $none, 'okrs' => $none, 'policies' => $none, 'plans' => $none,
            'media' => $none, 'events' => $none, 'hr' => $none,
            'feats' => $none, 'deploys' => $none, 'requests' => $none, 'designs' => $none,
            'assets' => $none, 'compliance' => $none,
        ]);

        $g = collect(hub_top_groups($u))->firstWhere('label', 'اللوحات والمراكز');
        $this->assertNotNull($g, 'قسم فيه رابط مسموح لم يُعرض');
        $this->assertSame(['finrep'], collect($g['items'])->pluck('key')->all());
    }

    public function test_personalization_still_lists_every_tool_for_hiding(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/personalize')->assertOk()->getContent();

        // القائمة المسطّحة تغذّي شاشة التخصيص — كل أداة لها مربّع إخفاء
        foreach (hub_top_links($this->owner) as $l) {
            $this->assertStringContainsString('value="' . $l['key'] . '"', $html,
                "أداة «{$l['label']}» غابت عن التخصيص");
        }
    }
}
