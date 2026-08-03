<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\WidgetRegistry;
use Tests\TestCase;

/**
 * سجل الودجات: تعريفٌ واحد لكل بطاقة بدل تعريفين لا يعرف أحدهما الآخر
 * (استعلامات في المتحكم وأسماء في التفضيلات).
 */
class WidgetRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        WidgetRegistry::forgetRegistered();
        parent::tearDown();
    }

    public function test_registry_is_the_single_source_of_widget_names(): void
    {
        $labels = WidgetRegistry::labels();
        foreach (['counts', 'kpis', 'expiry', 'apps', 'donut', 'due', 'audits', 'recent'] as $k) {
            $this->assertArrayHasKey($k, $labels, "الودجة {$k} مفقودة من السجل");
            $this->assertNotSame('', $labels[$k]);
        }

        // وشاشة التخصيص تقرأ من السجل نفسه — فلا اسم بلا بطاقة ولا بطاقة بلا اسم
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/personalize')->assertOk()->getContent();
        foreach ($labels as $key => $label) {
            $this->assertStringContainsString('value="' . $key . '"', $html, "«{$label}» لا تظهر في التخصيص");
        }
    }

    public function test_hidden_widget_resolves_to_null(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['dash' => ['hidden' => ['donut']]];
        $this->owner->save();

        $this->assertNull(WidgetRegistry::resolve('donut', $this->owner->fresh()));
        $this->assertNotNull(WidgetRegistry::resolve('counts', $this->owner->fresh()));
    }

    /** البوابة تُفحص قبل الاستعلام — ودجة ممنوعة لا تُشغّل استعلامها أصلاً */
    public function test_a_gated_resolver_never_runs(): void
    {
        $this->seedCore();
        $ran = false;
        WidgetRegistry::register('spy', [
            'label' => 'جاسوسة', 'size' => ['w' => 1, 'h' => 1],
            'gate' => fn ($u) => false,
            'resolver' => function () use (&$ran) { $ran = true; return 'بيانات'; },
        ]);

        $this->assertNull(WidgetRegistry::resolve('spy', $this->owner));
        $this->assertFalse($ran, 'مُحضِّر ودجة ممنوعة نُفِّذ رغم البوابة');
    }

    public function test_a_hidden_resolver_never_runs_either(): void
    {
        $this->seedCore();
        $ran = false;
        WidgetRegistry::register('spy2', [
            'label' => 'جاسوسة ٢', 'size' => ['w' => 1, 'h' => 1],
            'gate' => fn ($u) => true,
            'resolver' => function () use (&$ran) { $ran = true; return 'بيانات'; },
        ]);
        $this->owner->prefs = ['dash' => ['hidden' => ['spy2']]];
        $this->owner->save();

        $this->assertNull(WidgetRegistry::resolve('spy2', $this->owner->fresh()));
        $this->assertFalse($ran, 'المخفيّة استعلمت رغم إخفائها');
    }

    /** مؤشرات الأداء حساسة — بوابتها هي نفسها التي كانت في المتحكم */
    public function test_kpi_widget_stays_gated_to_monitors(): void
    {
        $this->seedCore();
        $this->assertNotNull(WidgetRegistry::resolve('kpis', $this->owner));

        $modules = array_keys(config('hub.modules'));
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بلا مراقبة', 'scope' => 'all', 'flags' => [], 'matrix' => $view]);
        $u = User::create(['name' => 'عادي', 'email' => 'plain@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->assertNull(WidgetRegistry::resolve('kpis', $u));
        $this->assertFalse(WidgetRegistry::isVisible('kpis', $u));
    }

    public function test_registered_widget_becomes_visible_everywhere(): void
    {
        $this->seedCore();
        WidgetRegistry::register('extra', [
            'label' => '🧩 ودجة ملحقة', 'size' => ['w' => 3, 'h' => 1],
            'gate' => fn ($u) => true,
            'resolver' => fn ($u) => ['قيمة'],
        ]);

        $this->assertArrayHasKey('extra', WidgetRegistry::labels());
        $this->assertSame(['قيمة'], WidgetRegistry::resolve('extra', $this->owner));
        $this->actingAs($this->owner)->get('/personalize')
            ->assertOk()->assertSee('ودجة ملحقة');
    }

    /** كل ودجة تعلن حجماً افتراضياً — باني اللوحات في المرحلة ٦ يقرأه */
    public function test_every_widget_declares_a_default_size(): void
    {
        foreach (WidgetRegistry::definitions() as $key => $def) {
            $this->assertArrayHasKey('size', $def, "الودجة {$key} بلا حجم افتراضي");
            $this->assertGreaterThan(0, $def['size']['w'] ?? 0);
            $this->assertGreaterThan(0, $def['size']['h'] ?? 0);
        }
    }

    /** التنطيق يبقى داخل المُحضِّر كما كان داخل المتحكم */
    public function test_widget_resolvers_still_respect_project_scope(): void
    {
        $this->seedCore();
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بمشاريعه', 'scope' => 'proj', 'flags' => [], 'matrix' => $full]);
        $u = User::create(['name' => 'محدود', 'email' => 'wr@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $mine = Project::create(['name' => 'لي', 'manager_id' => $u->id]);
        $theirs = Project::create(['name' => 'لغيري', 'manager_id' => $this->owner->id]);
        Task::create(['title' => 'داخل', 'project_id' => $mine->id]);
        Task::create(['title' => 'خارج أ', 'project_id' => $theirs->id]);
        Task::create(['title' => 'خارج ب', 'project_id' => $theirs->id]);

        $this->actingAs($u);
        $counts = collect(WidgetRegistry::resolve('counts', $u))->keyBy('key');
        $this->assertSame(1, $counts['tasks']['count'], 'بطاقة العدّ تجاوزت النطاق');
    }

    /** الدخان: اللوحة نفسها ما زالت تُعرض بكل بطاقاتها بعد النقل */
    public function test_dashboard_still_renders_every_card(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع']);
        Task::create(['title' => 'مهمة اللوحة', 'project_id' => $p->id,
            'due' => now()->addDays(3)->toDateString(), 'status' => 'جديدة']);

        $this->actingAs($this->owner)->get('/')
            ->assertOk()
            ->assertSee('مهمة اللوحة')          // ودجة المواعيد
            ->assertSee('المشاريع');            // بطاقات العدّ
    }
}
