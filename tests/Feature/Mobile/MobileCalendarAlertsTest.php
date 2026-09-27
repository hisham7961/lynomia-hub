<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **التقويمُ والتنبيهاتُ على الجوال** (خطّة التطبيق 4.5) — `CalendarFeed` (القارئُ الذي
 * تقرأ به صفحةُ `calendar` الويبيّة) و`hub_expiry` (رادارُ صفحة `alerts`): الوحدةُ بلا
 * `v` لا تظهر، الشركةُ الأخرى لا تظهر، حقلُ التاريخ المحجوبُ لا يُمسح، النافذةُ مقيّدة،
 * وحسابُ العميل ٤٠٤.
 */
class MobileCalendarAlertsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    /** @return list<string> أسماءُ عناصر التقويم في الردّ */
    private function names(array $data): array
    {
        $out = [];
        foreach ($data['days'] as $d) foreach ($d['items'] as $i) $out[] = (string) $i['name'];
        sort($out);

        return $out;
    }

    public function test_calendar_window_is_scoped_by_module_company_and_field_mode(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $day = now()->startOfDay()->addDays(3);
        Client::create(['name' => 'متابعةُ ألف', 'company_id' => $coA->id, 'next_date' => $day]);
        Client::create(['name' => 'متابعةُ باء', 'company_id' => $coB->id, 'next_date' => $day]);
        Task::create(['title' => 'موعدٌ-نهائيٌّ-محجوب', 'due' => $day]);
        Task::create(['title' => 'مهمةٌ خارجَ النافذة', 'due' => now()->addDays(50)]);

        $role = Role::create(['name' => 'محدود', 'scope' => 'all', 'flags' => [],
            'matrix' => ['clients' => ['v' => 1], 'tasks' => ['v' => 1]],
            'field_rules' => ['tasks' => ['due' => 'hide']]]);
        $u = User::create(['name' => 'محدود', 'email' => 'lim@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$coA->id]]);

        $res = $this->withHeaders($this->h($u))->getJson('/api/mobile/v1/calendar')->assertOk();
        $this->assertSame(['متابعةُ ألف'], $this->names($res->json('data')));
        $res->assertJsonPath('data.from', now()->toDateString());
        $this->assertMaskedValueAbsent((string) $res->getContent(), 'موعدٌ-نهائيٌّ-محجوب');
        $item = $res->json('data.days.0.items.0');
        $this->assertSame('clients', $item['module']);
        $this->assertSame(['module' => 'clients', 'id' => $item['id']], $item['target']);

        // المالكُ يرى كلَّ ما في النافذة — والبعيدُ خارجها
        $all = $this->withHeaders($this->h($this->owner))
            ->getJson('/api/mobile/v1/calendar?from=' . now()->toDateString() . '&to=' . now()->addDays(10)->toDateString())
            ->assertOk()->json('data');
        $this->assertSame(['متابعةُ ألف', 'متابعةُ باء', 'موعدٌ-نهائيٌّ-محجوب'], $this->names($all));
    }

    public function test_calendar_rejects_bad_or_oversized_windows(): void
    {
        $this->seedCore();
        $H = $this->h($this->owner);

        $this->withHeaders($H)->getJson('/api/mobile/v1/calendar?from=2026-13-40')->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withHeaders($H)->getJson('/api/mobile/v1/calendar?from=2026-05-10&to=2026-05-01')->assertStatus(422);
        $this->withHeaders($H)->getJson('/api/mobile/v1/calendar?from=2026-01-01&to=2026-06-01')->assertStatus(422);
        $this->withHeaders($H)->getJson('/api/mobile/v1/calendar?from=2026-01-01&to=2026-02-28')->assertOk();
    }

    public function test_alerts_bucket_the_radar_and_carry_the_server_target(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظّفة' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'لطيفة', 'email' => 'latifa@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'لطيفة', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(5)->toDateString()]);
        Employee::create(['name' => 'زميلٌ آخر', 'status' => 'نشط', 'iqama_exp' => now()->addDays(5)->toDateString()]);

        $d = $this->withHeaders($this->h($u))->getJson('/api/mobile/v1/alerts?fresh=1')->assertOk()->json('data');
        $this->assertSame(1, $d['total'], 'صفُّ صاحبِ الشأن وحدَه — لا وثيقةَ زميلٍ بلا hr:v');
        $this->assertSame([], $d['late']);
        $this->assertCount(1, $d['week']);
        $this->assertTrue($d['week'][0]['self']);
        $this->assertSame('portal.me', $d['week'][0]['target']['route']);

        // المالكُ يرى الاثنين
        $o = $this->withHeaders($this->h($this->owner))->getJson('/api/mobile/v1/alerts?fresh=1')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(2, $o['total']);
    }

    public function test_client_account_is_404_on_calendar_and_alerts(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => ['clients' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $H = $this->h($client);

        $this->withHeaders($H)->getJson('/api/mobile/v1/calendar')->assertNotFound();
        $this->withHeaders($H)->getJson('/api/mobile/v1/alerts')->assertNotFound();
    }
}
