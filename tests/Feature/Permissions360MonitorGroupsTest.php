<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **تفكيكُ رايةِ المراقبةِ الجامعة** (Permissions 360 · م3 · 01.2 · 11.4 · 20.5 · 04.3).
 *
 * إثباتٌ يفشل أولاً: قبلَ الدفعة كانت كلُّ لوحةٍ خلفَ `hub_monitor()` وحدَها، فلا سبيلَ
 * لمنحِ لوحاتِ التكاليفِ دون الأداءِ والأمن. الآن ثلاثُ مجموعاتٍ أدقّ، ورايةُ monitor
 * تبقى جامعةً (إضافةٌ لا كسر — لا هجرة).
 */
class Permissions360MonitorGroupsTest extends TestCase
{
    private function withFlags(string $email, array $flags): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => []]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ كلُّ مجموعةٍ تفتح مراكزَها وحدَها ═══════════ */

    public function test_ops_analytics_flag_opens_performance_not_costs_or_security(): void
    {
        $this->seedCore();
        $ops = $this->withFlags('ops@test.local', ['opsAnalytics' => 1]);

        $this->actingAs($ops)->get(route('performance'))->assertOk();          // مجموعتُه
        $this->actingAs($ops)->get(route('costs.index'))->assertForbidden();   // مجموعةٌ أخرى
        $this->actingAs($ops)->get(route('digital.assets'))->assertForbidden();
    }

    public function test_fin_analytics_flag_opens_costs_not_performance(): void
    {
        $this->seedCore();
        $fin = $this->withFlags('fin@test.local', ['finAnalytics' => 1]);

        $this->actingAs($fin)->get(route('costs.index'))->assertOk();
        $this->actingAs($fin)->get(route('performance'))->assertForbidden();
    }

    public function test_sec_ops_flag_opens_digital_assets_not_performance(): void
    {
        $this->seedCore();
        $sec = $this->withFlags('sec@test.local', ['secOps' => 1]);

        $this->actingAs($sec)->get(route('digital.assets'))->assertOk();
        $this->actingAs($sec)->get(route('performance'))->assertForbidden();
    }

    /* ═══════════ رايةُ monitor الجامعةُ لا تتغيّر (إضافةٌ لا كسر) ═══════════ */

    public function test_master_monitor_flag_still_opens_all_groups(): void
    {
        $this->seedCore();
        $mon = $this->withFlags('mon@test.local', ['monitor' => 1]);

        $this->actingAs($mon)->get(route('performance'))->assertOk();
        $this->actingAs($mon)->get(route('costs.index'))->assertOk();
        $this->actingAs($mon)->get(route('digital.assets'))->assertOk();
    }

    /* ═══════════ الشريطُ يطابق البوّابة (لا رابطٌ يظهر ثم يُصَدّ — 04.3) ═══════════ */

    public function test_nav_shows_only_the_groups_centers(): void
    {
        $this->seedCore();
        $ops = $this->withFlags('opsnav@test.local', ['opsAnalytics' => 1]);

        // hub_top_links يُعيد الروابطَ الظاهرةَ وحدَها (ok=true مُرشَّح) — فالحضورُ هو الرؤية
        $keys = collect(hub_top_links($ops))->pluck('key')->all();
        $this->assertContains('perf', $keys, 'لوحةُ الأداء تظهر لحاملِ opsAnalytics');
        $this->assertNotContains('costs', $keys, 'لوحةُ التكاليف لا تظهر له (مجموعةُ finAnalytics)');
        $this->assertNotContains('dassets', $keys, 'الأصولُ الرقميّة لا تظهر له (مجموعةُ secOps)');
    }
}
