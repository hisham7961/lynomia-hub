<?php

namespace Tests\Feature;

use Tests\TestCase;

class PersonalizeTest extends TestCase
{
    public function test_page_renders_and_prefs_save(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get('/personalize')->assertOk()
            ->assertSee('شاشة البداية')->assertSee('تسمية بديلة');

        $this->actingAs($this->employee)->post('/personalize', [
            'home' => 'm:tasks',
            'hidden' => ['competitors'],
            'names' => ['clients' => 'الزبائن'],
            'order' => ['المالية' => 0, 'الكيانات' => 5],
            'dash_hidden' => ['audits', 'donut'],
        ])->assertRedirect();

        $u = $this->employee->fresh();
        $this->assertSame('m:tasks', $u->prefs['home']);
        $this->assertSame(['competitors'], $u->prefs['nav']['hidden']);
        $this->assertSame('الزبائن', $u->prefs['nav']['names']['clients']);
        $this->assertSame(['المالية', 'الكيانات'], $u->prefs['nav']['order']);
        $this->assertEqualsCanonicalizing(['audits', 'donut'], $u->prefs['dash']['hidden']);
    }

    public function test_nav_applies_hide_rename_and_order(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => [
            'nav' => ['hidden' => ['competitors'], 'names' => ['clients' => 'الزبائن'], 'order' => ['المالية']],
        ]]);

        $nav = hub_nav($this->employee->fresh());

        $keys = collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('key'));
        $this->assertFalse($keys->contains('competitors'), 'الوحدة المخفية تسقط من القائمة');

        $labels = collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('label', 'key'));
        $this->assertSame('الزبائن', $labels['clients']);

        $this->assertSame('المالية', $nav[0]['g'], 'المجموعة المختارة أولاً تتقدم');
    }

    public function test_hiding_is_display_only_not_permission(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['nav' => ['hidden' => ['clients']]]]);

        // الرابط المباشر يعمل — الإخفاء تنظيم عرض لا صلاحية
        $this->actingAs($this->employee->fresh())->get('/m/clients')->assertOk();
    }

    public function test_login_lands_on_chosen_home(): void
    {
        $this->seedCore();
        $this->employee->update(['password' => 'Secret!2026x', 'prefs' => ['home' => 'm:tasks']]);

        $this->post('/login', ['email' => 'emp@test.local', 'password' => 'Secret!2026x'])
            ->assertRedirect(route('m.index', 'tasks'));
    }

    public function test_invalid_home_falls_back_to_dashboard(): void
    {
        $this->seedCore();

        // وحدة لا يراها الدور: تفضيل البداية إليها يُهمل عند الحفظ ويهبط للوحة
        $role = $this->viewer->role;
        $m = $role->matrix; $m['tasks'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer)->post('/personalize', ['home' => 'm:tasks'])->assertRedirect();
        $this->assertNull($this->viewer->fresh()->prefs['home'] ?? null);
        $this->assertSame(route('dashboard'), hub_home_url($this->viewer->fresh()));
    }

    public function test_hidden_dashboard_cards_disappear(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['dash' => ['hidden' => ['audits', 'counts']]]]);

        $r = $this->actingAs($this->employee->fresh())->get('/')->assertOk();
        $r->assertDontSee('آخر النشاطات');
    }

    public function test_reset_clears_everything(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['home' => 'feed', 'nav' => ['hidden' => ['clients']]]]);

        $this->actingAs($this->employee)->post('/personalize/reset')->assertRedirect();
        $this->assertNull($this->employee->fresh()->prefs);
    }

    public function test_prefs_are_per_user(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['nav' => ['hidden' => ['clients']]]]);

        // تخصيص الموظفة لا يلمس قائمة المشاهد
        $keys = collect(hub_nav($this->viewer))->flatMap(fn ($g) => collect($g['items'])->pluck('key'));
        $this->assertTrue($keys->contains('clients'));
    }
}
