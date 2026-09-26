<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Platform\Modules\ModuleDependencies;
use Tests\TestCase;

/**
 * **تبعيّاتُ الوحدات `depends_on`** (بندُ الدَّين #4 · DI-11).
 *
 * لا وحدةَ في السجلّ تُعلنها اليوم — فالاختباراتُ تُعلنها **بتجاوزٍ داخلَ الاختبار**
 * (`config([...])`) ولا تمسّ السجلَّ ولا لقطاتِ البنية. والسلوكُ الافتراضيّ (بلا إعلان)
 * يبقى كما كان حرفاً: هذا ما يحرسه أوّلُ اختبار.
 */
class ModuleDependenciesTest extends TestCase
{
    /** مستخدمٌ بكلِّ الوحدات عدا ما يُحجَب عنه عرضُه */
    private function userWithout(array $hidden): User
    {
        $matrix = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => in_array($m, $hidden, true)
                ? ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]
                : ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'دورُ تبعيّة', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدمُ التبعيّة', 'email' => 'deps@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function navKeys(User $u): array
    {
        return collect(hub_nav($u))->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();
    }

    public function test_without_declarations_nothing_changes(): void
    {
        $this->seedCore();

        // السجلُّ الحقيقيّ: كلُّ تبعيّةٍ مُعلَنةٍ (إن وُجدت يوماً) تشير إلى وحدةٍ قائمة
        foreach (array_keys(config('hub.modules')) as $mk) {
            foreach (ModuleDependencies::declared($mk) as $dep) {
                $this->assertNotNull(hub_mod($dep), "«{$mk}» تُعلن تبعيّةً على وحدةٍ غيرِ موجودة: {$dep}");
            }
            $this->assertTrue(ModuleDependencies::met($this->employee, $mk), "«{$mk}» صارت ناقصةً بلا إعلان");
        }

        $this->assertContains('tasks', $this->navKeys($this->employee));
        $this->actingAs($this->employee)->get('/m/tasks/create')->assertOk();
    }

    public function test_a_hidden_dependency_hides_the_module_and_explains_on_create(): void
    {
        $this->seedCore();
        config(['hub.modules.tasks.depends_on' => ['projects']]);
        $u = $this->userWithout(['projects']);

        $this->assertNotContains('tasks', $this->navKeys($u), 'وحدةٌ تبعيّتُها محجوبةٌ بقيت في التنقّل');
        $this->assertSame(['projects' => hub_mod('projects')['label']], ModuleDependencies::missing($u, 'tasks'));

        $res = $this->actingAs($u)->get('/m/tasks/create');
        $res->assertRedirect(route('m.index', 'tasks'));
        $err = (string) session('err');
        $this->assertStringContainsString('تعتمد هذه الوحدةُ على', $err);
        $this->assertStringContainsString(hub_mod('projects')['label'], $err);

        // ومن يرى التبعيّةَ لا يتغيّر عليه شيء
        $this->assertContains('tasks', $this->navKeys($this->employee));
        $this->actingAs($this->employee)->get('/m/tasks/create')->assertOk();
    }

    public function test_a_disabled_dependency_hides_even_for_the_owner(): void
    {
        $this->seedCore();
        config(['hub.modules.tasks.depends_on' => ['module_that_is_off']]);

        $this->assertFalse(ModuleDependencies::met($this->owner, 'tasks'));
        $this->assertNotContains('tasks', $this->navKeys($this->owner));
        $this->actingAs($this->owner)->get('/m/tasks/create')->assertRedirect(route('m.index', 'tasks'));
        // عرضٌ لا تخويل: القائمةُ نفسُها بالرابط المباشر ما زالت تعمل
        $this->actingAs($this->owner)->get('/m/tasks')->assertOk();
    }

    public function test_dependencies_are_transitive_and_cycles_terminate(): void
    {
        $this->seedCore();
        config([
            'hub.modules.tasks.depends_on'    => ['issues'],
            'hub.modules.issues.depends_on'   => ['projects', 'tasks'],   // دورةٌ مع tasks
        ]);
        $u = $this->userWithout(['projects']);

        $this->assertSame(['projects'], array_keys(ModuleDependencies::missing($u, 'tasks')));
        $this->assertFalse(ModuleDependencies::met($u, 'issues'));
        $this->assertTrue(ModuleDependencies::met($this->employee, 'tasks'), 'الدورةُ وحدَها لا تجعل الوحدةَ ناقصة');
    }
}
