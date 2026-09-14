<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · PROD-05 — من تنتهي إقامتُه هو الوحيدُ الممنوعُ من إنذارِ نفسِه.**
 *
 * أبلغ خبيرُ المنتجِ عن تناقضِ ثلاثِ شاشات: `/me` تعرض «٥ يوم — انتهاء الإقامة»
 * بينما `/alerts` تقول **«😌 لا شيء ينتهي خلال ٣٠ يوماً — كل أصولك بأمان»**.
 * وشخّص السببَ بأنّ حقولَ وثائقِ الموظّفِ **خارجَ مسحِ الرادار**.
 *
 * **والتشخيصُ خاطئ** — وهذا ما كشفه التحقّقُ المستقلّ. الرادارُ يمسح `hr`
 * صراحةً بحقولِ `iqamaExp` و`passExp` و`endDate`؛ شغّلتُ `hub_expiry()` على
 * قاعدةِ العرضِ بالشخصيّتين:
 *
 * ```
 * لطيفة (hr:v = NO)  →  0 صفّاً    ⇒ «كل أصولك بأمان»
 * مريم  (hr:v = YES) →  45 صفّاً   منها صفّانِ لإقامةِ لطيفة
 * ```
 *
 * **فالسببُ الحقيقيّ:** الرادارُ منطَّقٌ بصلاحيّةِ الوحدةِ **بلا استثناءٍ
 * لصاحبِ الشأن**. ولطيفةُ لا تملك `hr:v` — ولا ينبغي أن تملكه، فملفّاتُ
 * زملائها ليست لها — **لكنّ إقامتَها ليست سرّاً عنها**.
 *
 * **ولو نُفِّذت توصيةُ الخبيرِ كما وردت لأُضيف مسحٌ قائمٌ سلفاً وبقي العطلُ.**
 *
 * **ولا صلاحيّةَ تُوسَّع:** الاستثناءُ يخصّ **سجلَّ الموظّفِ المرتبطَ بحسابِه
 * هو** — لا يفتح له ملفّاتِ زملائه ولا يمنحه `hr:v`.
 */
class CouncilOwnExpiryTest extends TestCase
{
    /** موظّفٌ بلا أيِّ صلاحيّةٍ على `hr`، وإقامتُه تنتهي بعد خمسةِ أيّام */
    protected function employeeExpiringSoon(): array
    {
        $role = Role::create(['name' => 'عضو فريق' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'لطيفة السالم', 'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'لطيفة السالم', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(5)->toDateString()]);

        return [$u, $e];
    }

    public function test_a_person_is_warned_about_their_own_expiring_residency(): void
    {
        $this->seedCore();
        [$u, $e] = $this->employeeExpiringSoon();

        $this->assertFalse(hub_can($u, 'hr', 'v'),
            'تهيئةُ الاختبارِ خاطئة: الموظّفةُ تملك `hr:v` فلا يُختبَر شيء');

        $rows = collect(hub_expiry(true, $u))
            ->where('module', 'hr')->where('id', $e->id);

        $this->assertTrue($rows->isNotEmpty(),
            'من تنتهي إقامتُه بعد خمسةِ أيّامٍ لا يُنذَر بها — والشاشةُ تقول له '
            . '«كل أصولك بأمان». فالرادارُ منطَّقٌ بصلاحيّةِ الوحدةِ بلا استثناءٍ لصاحبِ الشأن.');
        $this->assertSame(5, (int) $rows->first()['days'],
            'الصفُّ ظهر بعددِ أيّامٍ خاطئ');
    }

    public function test_the_exception_does_not_leak_a_colleagues_record(): void
    {
        $this->seedCore();
        [$u] = $this->employeeExpiringSoon();
        $peer = Employee::create(['name' => 'زميلةٌ لا تخصّه', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(3)->toDateString()]);

        $ids = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('id')->all();

        $this->assertNotContains($peer->id, $ids,
            '**تسريب**: الاستثناءُ فتح ملفَّ زميلةٍ لمن لا يملك `hr:v` — '
            . 'والمقصودُ سجلُّه هو لا وحدةُ الموارد البشريّةِ كلُّها');
    }

    public function test_a_user_without_an_employee_profile_is_unaffected(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'حسابٌ بلا ملفّ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'حسابُ إدارة', 'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->assertIsArray(hub_expiry(true, $u),
            'حسابٌ بلا ملفِّ موظّفٍ أسقط الرادار — وهو حالُ حساباتِ الإدارةِ والمالك');
    }

    public function test_the_empty_state_no_longer_claims_everything_is_safe(): void
    {
        $this->seedCore();
        $html = file_get_contents(resource_path('views/alerts/index.blade.php'));

        $this->assertStringNotContainsString('كل أصولك بأمان', $html,
            'الحالةُ الفارغةُ تجزم بما لا تعلم: «كل أصولك بأمان» تُقال لمن '
            . 'انتهت إقامتُه — والجزمُ الخاطئُ أضرُّ من الصمت');
    }
}
