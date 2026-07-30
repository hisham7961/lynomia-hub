<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

/**
 * باني اللوحات: لوحات متعددة تُبنى من سجل الودجات.
 * المبدأ الحاكم: **الاختيار في الباني لا يمنح صلاحية** — البوابة تُفحص عند الإضافة وعند العرض.
 */
class DashboardBuilderTest extends TestCase
{
    private function plainUser(string $email, array $flags = []): User
    {
        $modules = array_keys(config('hub.modules'));
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'عادي ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $view]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** من لم يبنِ لوحةً لا يرى تغييراً — الترتيب الافتراضي كما كان */
    public function test_without_a_board_the_dashboard_is_unchanged(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'مهمة قادمة', 'project_id' => $p->id,
            'due' => now()->addDays(2)->toDateString(), 'status' => 'جديدة']);

        $this->actingAs($this->owner)->get('/')
            ->assertOk()
            ->assertSee('المشاريع')            // بطاقات العدّ
            ->assertSee('مهمة قادمة')          // ودجة المواعيد
            ->assertSee('آخر النشاطات');
    }

    public function test_create_a_board_add_a_widget_and_view_it(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'مهمة اللوحة', 'project_id' => $p->id,
            'due' => now()->addDays(2)->toDateString(), 'status' => 'جديدة']);

        $this->actingAs($this->owner)
            ->post('/boards', ['name' => 'لوحتي التشغيلية'])->assertRedirect();
        $b = Dashboard::first();
        $this->assertSame('لوحتي التشغيلية', $b->name);
        $this->assertSame($this->owner->id, $b->owner_id);

        $this->actingAs($this->owner)
            ->post("/boards/{$b->id}/widgets", ['widget_key' => 'due'])->assertRedirect();
        $this->assertSame(1, $b->widgets()->count());

        // اللوحة تعرض ودجتها، ولا تعرض ما لم يُضَف
        $this->actingAs($this->owner)->get('/?d=' . $b->id)
            ->assertOk()
            ->assertSee('مهمة اللوحة')
            ->assertDontSee('آخر النشاطات');
    }

    public function test_default_board_opens_automatically(): void
    {
        $this->seedCore();
        $b = Dashboard::create(['name' => 'الافتراضية عندي', 'owner_id' => $this->owner->id]);
        DashboardWidget::create(['dashboard_id' => $b->id, 'widget_key' => 'audits']);

        // قبل جعلها افتراضية: الجذر يعرض الترتيب الافتراضي (وفيه بطاقات العدّ)
        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee('المشاريع');

        $this->actingAs($this->owner)
            ->put("/boards/{$b->id}", ['name' => 'الافتراضية عندي', 'is_default' => 1])
            ->assertRedirect();
        $this->assertTrue($b->fresh()->is_default);

        // بعدها: الجذر يفتح اللوحة المبنيّة — ودجتها وحدها، بلا بطاقات العدّ
        $r = $this->actingAs($this->owner)->get('/')->assertOk()->assertSee('آخر النشاطات');
        $this->assertSame($b->id, $r->original->getData()['board']->id);
        $this->assertSame(['audits'], collect($r->original->getData()['layout'])->pluck('key')->all());
    }

    public function test_only_one_default_per_owner(): void
    {
        $this->seedCore();
        $a = Dashboard::create(['name' => 'أ', 'owner_id' => $this->owner->id, 'is_default' => true]);
        $b = Dashboard::create(['name' => 'ب', 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner)->put("/boards/{$b->id}", ['name' => 'ب', 'is_default' => 1]);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    /** الباني لا يمنح صلاحية: ودجة لا يراها المستخدم لا تُضاف */
    public function test_a_user_cannot_add_a_widget_they_cannot_see(): void
    {
        $this->seedCore();
        $u = $this->plainUser('nokpi@test.local');           // بلا علم monitor
        $b = Dashboard::create(['name' => 'لوحته', 'owner_id' => $u->id]);

        $this->actingAs($u)->post("/boards/{$b->id}/widgets", ['widget_key' => 'kpis'])
            ->assertForbidden();
        $this->assertSame(0, $b->widgets()->count());
    }

    /** ولو أُضيفت ثم سُحبت الصلاحية، سقطت من العرض حينها */
    public function test_a_widget_drops_from_the_layout_when_its_gate_closes(): void
    {
        $this->seedCore();
        $u = $this->plainUser('waskpi@test.local', ['monitor' => 1]);
        $b = Dashboard::create(['name' => 'لوحته', 'owner_id' => $u->id, 'is_default' => true]);
        DashboardWidget::create(['dashboard_id' => $b->id, 'widget_key' => 'kpis']);
        DashboardWidget::create(['dashboard_id' => $b->id, 'widget_key' => 'audits']);

        $this->actingAs($u)->get('/?d=' . $b->id)->assertOk();   // يراها اليوم

        // تُسحب المراقبة
        $role = $u->role;
        $role->update(['flags' => []]);

        $r = $this->actingAs($u->fresh())->get('/?d=' . $b->id)->assertOk();
        $keys = collect($r->original->getData()['layout'])->pluck('key');
        $this->assertFalse($keys->contains('kpis'), 'ودجة ممنوعة بقيت في اللوحة');
        $this->assertTrue($keys->contains('audits'));
    }

    /** لوحة غيرك لا تُفتح ولا يُكشف وجودها */
    public function test_another_users_board_is_not_found(): void
    {
        $this->seedCore();
        $other = $this->plainUser('other@test.local');
        $b = Dashboard::create(['name' => 'لوحة الآخر', 'owner_id' => $other->id]);

        $this->actingAs($this->employee)->get('/?d=' . $b->id)->assertNotFound();
        $this->actingAs($this->employee)->get("/boards/{$b->id}")->assertNotFound();
        $this->actingAs($this->employee)
            ->post("/boards/{$b->id}/widgets", ['widget_key' => 'audits'])->assertNotFound();
        $this->actingAs($this->employee)->delete("/boards/{$b->id}")->assertNotFound();
    }

    public function test_shared_board_is_visible_to_others_but_not_editable(): void
    {
        $this->seedCore();
        $b = Dashboard::create(['name' => 'لوحة منشورة', 'owner_id' => $this->owner->id, 'shared' => true]);
        DashboardWidget::create(['dashboard_id' => $b->id, 'widget_key' => 'audits']);

        $this->actingAs($this->employee)->get('/?d=' . $b->id)->assertOk()->assertSee('آخر النشاطات');
        $this->actingAs($this->employee)->get("/boards/{$b->id}")->assertNotFound();
    }

    /** النشر قرارٌ يمسّ شاشات الآخرين — للمالك وحده */
    public function test_only_the_owner_can_publish_a_board(): void
    {
        $this->seedCore();
        $u = $this->plainUser('pub@test.local');
        $b = Dashboard::create(['name' => 'لوحته', 'owner_id' => $u->id]);

        $this->actingAs($u)->put("/boards/{$b->id}", ['name' => 'لوحته', 'shared' => 1]);
        $this->assertFalse($b->fresh()->shared, 'غير المالك نشر لوحة');

        $ob = Dashboard::create(['name' => 'لوحة المالك', 'owner_id' => $this->owner->id]);
        $this->actingAs($this->owner)->put("/boards/{$ob->id}", ['name' => 'لوحة المالك', 'shared' => 1]);
        $this->assertTrue($ob->fresh()->shared);
    }

    public function test_unknown_widget_key_is_rejected(): void
    {
        $this->seedCore();
        $b = Dashboard::create(['name' => 'لوحة', 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->post("/boards/{$b->id}/widgets", ['widget_key' => 'ودجة-مخترعة'])
            ->assertSessionHasErrors('widget_key');
        $this->assertSame(0, $b->widgets()->count());
    }

    public function test_widget_takes_its_default_size_from_the_registry(): void
    {
        $this->seedCore();
        $b = Dashboard::create(['name' => 'لوحة', 'owner_id' => $this->owner->id]);
        $this->actingAs($this->owner)->post("/boards/{$b->id}/widgets", ['widget_key' => 'donut']);

        $w = $b->widgets()->first();
        $size = \App\Support\WidgetRegistry::get('donut')['size'];
        $this->assertSame($size['w'], (int) $w->w);
        $this->assertSame($size['h'], (int) $w->h);
    }

    public function test_removing_a_widget_and_deleting_a_board(): void
    {
        $this->seedCore();
        $b = Dashboard::create(['name' => 'لوحة', 'owner_id' => $this->owner->id]);
        $w = DashboardWidget::create(['dashboard_id' => $b->id, 'widget_key' => 'audits']);

        $this->actingAs($this->owner)->delete("/boards/{$b->id}/widgets/{$w->id}")->assertRedirect();
        $this->assertSame(0, $b->widgets()->count());

        $this->actingAs($this->owner)->delete("/boards/{$b->id}")->assertRedirect();
        $this->assertNull(Dashboard::find($b->id));
    }

    /** تفضيل الإخفاء القديم يبقى عاملاً على الترتيب الافتراضي */
    public function test_legacy_hidden_preference_still_applies(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['dash' => ['hidden' => ['audits']]];
        $this->owner->save();

        $this->actingAs($this->owner->fresh())->get('/')
            ->assertOk()->assertDontSee('آخر النشاطات');
    }
}
