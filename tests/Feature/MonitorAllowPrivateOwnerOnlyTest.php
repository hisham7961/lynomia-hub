<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Support\Settings;
use Tests\TestCase;

/**
 * **SSRF-2 (تدقيق أمنيّ v2.600) — «افحص الآن» مع السماحِ بالعناوين الداخليّة للمالكِ وحدَه.**
 *
 * `monitor.allow_private` علَمٌ عامٌّ واحد يُبطِل — بعد تفعيلِ المالكِ له — حظرَ
 * العناوين الداخليّة في `hub_outbound_ok` **لكلِّ مستخدم**. فكان محرِّرُ وحدةِ
 * servers/websites (صلاحيّة `e`) يضبط `monitor_url` على عنوانٍ داخليّ ويُطلق
 * «افحص الآن» فينعكس رمزُ HTTP وزمنُ الاستجابة — مِجَسٌّ داخليٌّ بانعكاسٍ لغيرِ
 * المالك. الحارس: عند تفعيلِ العلَم، الفحصُ الحيُّ للمالكِ وحدَه. CWE-918.
 */
class MonitorAllowPrivateOwnerOnlyTest extends TestCase
{
    public function test_non_owner_cannot_run_live_check_when_allow_private_is_on(): void
    {
        $this->seedCore();
        Settings::put('monitor.allow_private', true, 'monitor');

        // دورٌ غيرُ مالكٍ بصلاحيّةِ تعديلِ الخوادم
        $role = Role::create(['name' => 'مشغّل', 'scope' => 'all', 'flags' => [],
            'matrix' => ['servers' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);
        $u = User::create(['name' => 'مشغّل', 'email' => 'op'.uniqid().'@t.test',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $s = Server::create(['name' => 'خادم', 'monitor_url' => 'http://169.254.169.254/latest/meta-data']);

        $this->actingAs($u)->post("/monitor/servers/{$s->id}/check")->assertStatus(403);
    }
}
