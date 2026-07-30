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
        $groups = collect(hub_top_groups($this->owner))->keyBy('key');

        $this->assertTrue($groups['daily']['open']);
        $this->assertFalse($groups['analytics']['open']);
        $this->assertFalse($groups['centers']['open']);
    }

    public function test_sidebar_renders_region_labels_and_group_headers(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        foreach (['الأدوات واللوحات', 'الوحدات', 'النظام'] as $region) {
            $this->assertStringContainsString($region, $html, "منطقة «{$region}» غائبة");
        }
        foreach (['مساحتي اليومية', 'التحليلات واللوحات', 'مراكز متخصّصة'] as $g) {
            $this->assertStringContainsString($g, $html, "قسم «{$g}» غائب");
        }
        // لم تبقَ قائمة الأدوات المسطّحة: الرابط المسطّح الوحيد هو لوحة التحكم
        $this->assertSame(1, substr_count($html, 'class="ni top'));
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
        // مستخدم بلا monitor ولا رؤية للمالية: قسم التحليلات يفقد كل روابطه
        $u = $this->limited('noanalytics@test.local', [
            'fin' => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0],
        ]);

        $keys = collect(hub_top_groups($u))->pluck('key');
        $this->assertFalse($keys->contains('analytics'), 'قسم تحليلات فارغ عُرض رأسه');
        $this->assertTrue($keys->contains('daily'), 'القسم اليومي يبقى دائماً');
    }

    /** لكن قسمٌ فيه رابطٌ واحد مسموح يُعرض */
    public function test_a_group_with_one_permitted_link_is_shown(): void
    {
        $this->seedCore();
        // يرى المالية فقط ⇒ finrep يبقى في التحليلات، فيُعرض القسم
        $u = $this->limited('onlyfin@test.local');

        $analytics = collect(hub_top_groups($u))->firstWhere('key', 'analytics');
        $this->assertNotNull($analytics, 'قسم فيه رابط مسموح لم يُعرض');
        $this->assertSame(['finrep'], collect($analytics['items'])->pluck('key')->all());
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
