<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **مثبّتاتي** — رصيفٌ شخصيّ لأكثر الوجهات استعمالاً فوق كل شيء في الشريط.
 * لنظامٍ من ٧١ وحدة، أكبرُ مكسبٍ ملاحيّ: نقرةٌ واحدة بلا تنقيبٍ ولا ⌘K.
 */
class NavPinsTest extends TestCase
{
    public function test_pin_and_unpin_a_module_shows_it_in_the_rail(): void
    {
        $this->seedCore();

        // لا رصيف قبل التثبيت — لا يزحم شريطَ المبتدئ
        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringNotContainsString('📌 مثبّتاتي', $html);
        $this->assertStringContainsString('📌 تثبيت', $html);

        // ثبّت
        $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'm:tasks'])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertContains('m:tasks', (array) data_get($this->owner->fresh()->prefs, 'nav.pins'));

        // يظهر في الرصيف، والزر صار «مثبّتة»
        $html2 = $this->actingAs($this->owner)->get('/m/tasks')->getContent();
        $this->assertStringContainsString('📌 مثبّتاتي', $html2);
        $this->assertStringContainsString('📌 مثبّتة', $html2);

        // فكّ التثبيت يزيله
        $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'm:tasks']);
        $this->assertNotContains('m:tasks', (array) data_get($this->owner->fresh()->prefs, 'nav.pins'));
    }

    public function test_a_top_link_can_be_pinned(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'me'])->assertSessionHas('ok');

        $html = $this->actingAs($this->owner)->get('/')->getContent();
        $this->assertStringContainsString('📌 مثبّتاتي', $html);
        $this->assertStringContainsString('بوابتي', $html);
    }

    /** لا يُثبَّت ما لا يُفتح: وحدةٌ خارج صلاحية المستخدم تُرفض */
    public function test_cannot_pin_a_destination_outside_permission(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'محدود', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);   // vault غير مرئية
        $u = User::create(['name' => 'محدود', 'email' => 'lim@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post('/personalize/pin', ['token' => 'm:vault'])
            ->assertRedirect()->assertSessionHas('err');
        $this->assertNull(data_get($u->fresh()->prefs, 'nav.pins'));

        // ورمزٌ مُلفَّق يُرفض كذلك
        $this->actingAs($u)->post('/personalize/pin', ['token' => 'm:nonesuch'])->assertSessionHas('err');
    }

    public function test_pin_cap_is_enforced(): void
    {
        $this->seedCore();
        $mods = collect(array_keys(config('hub.modules')))
            ->reject(fn ($m) => $m === 'users')->take(12);
        foreach ($mods as $m) {
            $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'm:' . $m]);
        }
        $this->assertCount(12, (array) data_get($this->owner->fresh()->prefs, 'nav.pins'));

        // الثالث عشر يُرفض بلطف
        $extra = collect(array_keys(config('hub.modules')))
            ->first(fn ($m) => $m !== 'users' && ! $mods->contains($m));
        $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'm:' . $extra])
            ->assertSessionHas('err');
        $this->assertCount(12, (array) data_get($this->owner->fresh()->prefs, 'nav.pins'));
    }

    /** حفظُ شاشة التخصيص لا يمحو المثبّتات (مسارٌ آخر يديرها) */
    public function test_saving_personalize_preserves_pins(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/personalize/pin', ['token' => 'm:tasks']);

        $this->actingAs($this->owner)->post('/personalize', ['nav_style' => 'classic', 'home' => 'dashboard']);

        $this->assertContains('m:tasks', (array) data_get($this->owner->fresh()->prefs, 'nav.pins'),
            'حفظُ التخصيص محا المثبّتات');
    }
}
