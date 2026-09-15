<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * مجلسُ الخبراء · N-19 — **خمسةُ قرّاءٍ في التقارير وعقدِ الـAPI**.
 *
 * `resolve()/resolveMany()` يسألان «ماذا وقع في ذلك اليوم؟» — سؤالٌ صحيحٌ في
 * موضعِه. لكنّ التقاريرَ تعرضُ جوابَه على أنّه «أهو الآن على رأسِ عملِه؟»، فيقول
 * عقدُ الـAPI `"state":"absent"` عن موظّفٍ يعمل، وتقول صفحةُ الموظّفِ نفسِها
 * **«لا حضور»** بينما بطاقتُه في الصفحةِ المجاورةِ تعدّ ساعاتِه حيّاً.
 *
 * **والعلاجُ إضافةٌ لا كسر:** حقلٌ جديدٌ `open_shift` — و`state` كما هو، فلا
 * يتغيّر عقدٌ منشور. الحقلُ يُملأ لليومِ الجاري وحدَه (لأنّ السؤالَ عن «الآن»)،
 * ومن الجوابِ الجماعيِّ نفسِه الذي تسأله البطاقةُ وشاشةُ المدير.
 */
class CouncilOpenShiftReadersTest extends TestCase
{
    private function member(string $email, array $matrix = ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]]): array
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'companies' => null]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /** موظّفٌ بدأ ورديّتَه ٢٣:٠٠ أمس، والساعةُ الآن ٠٩:٣٠ من الغد */
    private function nightWorker(string $email): array
    {
        [$u, $e] = $this->member($email);
        Carbon::setTestNow(Carbon::parse('2026-09-14 23:00:00'));
        Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:30:00'));

        return [$u, $e];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ١ — المُحلِّلُ يحمل الحقلَ الجديد، والعقدُ لم يتغيّر ═══════════ */

    public function test_resolve_carries_the_open_shift_without_changing_the_state_contract(): void
    {
        $this->seedCore();
        [$u, $e] = $this->nightWorker('rs-night@test.local');

        $c = DailyWorkCompliance::resolve($e, '2026-09-15');

        $this->assertArrayHasKey('open_shift', $c, 'لا حقلَ `open_shift` في المُحلِّل');
        $this->assertNotNull($c['open_shift'], 'المُحلِّلُ لا يرى الورديّةَ المفتوحة');
        $this->assertSame('23:00:00', $c['open_shift']['time_in']);
        $this->assertSame('2026-09-14', $c['open_shift']['date']);

        // **والعقدُ لم يُمسّ** — `state` يبقى جوابَ «ماذا وقع في هذا اليوم»
        $this->assertSame('absent', $c['state'],
            'تغيّر معنى `state` — وهو عقدٌ منشور: العلاجُ إضافةٌ لا قلبُ معنى');
    }

    public function test_the_field_is_null_for_a_past_day_and_for_a_normal_day(): void
    {
        $this->seedCore();
        [$u, $e] = $this->nightWorker('rs-past@test.local');

        // يومٌ ماضٍ: السؤالُ عن «الآن» لا معنى له هناك
        $this->assertNull(DailyWorkCompliance::resolve($e, '2026-09-10')['open_shift']);

        // وموظّفٌ بلا ورديّةٍ مفتوحة
        [$u2, $e2] = $this->member('rs-plain@test.local');
        $this->assertNull(DailyWorkCompliance::resolve($e2, '2026-09-15')['open_shift']);
    }

    /* ═══════════ ٢ — صفحةُ الموظّفِ نفسِها ═══════════ */

    public function test_my_report_shows_the_open_shift_instead_of_no_attendance(): void
    {
        $this->seedCore();
        [$u, $e] = $this->nightWorker('rs-mine@test.local');

        $html = $this->actingAs($u)->get(route('reports.mine'))->assertOk()->getContent();

        $this->assertStringContainsString('على رأس العمل منذ 23:00', $html,
            'صفحةُ الموظّفِ تقول «لا حضور» وبطاقتُه تعدّ ساعاتِه حيّاً');
    }

    /* ═══════════ ٣ — شاشةُ التقارير للمدير ═══════════ */

    public function test_the_daily_report_screen_shows_the_open_shift(): void
    {
        $this->seedCore();
        [$u, $e] = $this->nightWorker('rs-team@test.local');

        $html = $this->actingAs($this->owner)->get(route('reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('على رأس العمل منذ 23:00', $html,
            'مركزُ التقارير يقول «لم يسجّل» عن موظّفٍ على رأسِ عملِه');
    }

    /* ═══════════ ٤ — عقدُ الـAPI: حقلٌ يُضاف، ولا معنًى يُقلَب ═══════════ */

    public function test_the_api_adds_the_field_and_keeps_state_untouched(): void
    {
        $this->seedCore();
        [$u, $e] = $this->nightWorker('rs-api@test.local');

        $token = $this->apiToken($this->owner);
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.v1.reports.daily', ['date' => '2026-09-15']))->assertOk();

        $row = collect($res->json('data.employees') ?? $res->json('employees') ?? [])
            ->first(fn ($r) => ($r['employee_id'] ?? null) === $e->id);

        $this->assertNotNull($row, 'لم أجد صفَّ الموظّف في مُخرَج الـAPI: ' . $res->getContent());
        $c = $row['compliance'] ?? [];
        $this->assertArrayHasKey('open_shift', $c, 'عقدُ الـAPI بلا الحقلِ الجديد');
        $this->assertNotNull($c['open_shift']);
        $this->assertSame('23:00:00', $c['open_shift']['time_in']);
        $this->assertSame('absent', $c['state'] ?? null,
            'تغيّر `state` في عقدٍ منشور — العلاجُ إضافةٌ لا كسر');
    }
}
