<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حارسُ الفصل على بطاقة الموظف** (WP-7.1+7.3 · spec §5.1 · §5.3 · §5.8).
 *
 * `SecurityActivitySplitTest` يحرس الفصلَ على شاشة الأداء وشاشة النشاط،
 * و`EmployeeWorkProfileTest` يحرس التنطيقَ وصلاحيةَ الحقل — ويبقى ثلاثةُ
 * أبوابٍ على **بطاقة الموظف** نفسِها بلا مسمار، وكلُّها من الصنف الذي ينفتح
 * بلمسةٍ في العرض ولا يشكو منه اختبارٌ قائم:
 *
 *  ١) بطاقةُ **الأمن** فيها للمالك وحدَه — حاملُ رايةِ المتابعة ليس مالكاً،
 *     ودرجةُ الشك ليست رقمَ أداءٍ يُعرض لكل من يفتح ملفَّ زميله.
 *  ٢) بطاقةُ **النشاط** خلف رايةِ المتابعة — قارئُ الموارد البشرية وحدَه
 *     يرى ملفَّ العمل ولا يرى «متى دخل ومتى خرج».
 *  ٣) **ولا زياراتِ صفحاتٍ خام** في البطاقة بحال، ولو كان الفاتحُ هو المالك:
 *     الأثرُ الخام مطويٌّ في مركز النشاط وحدَه (§5.8) — وهذا هو الفرقُ بين
 *     قياس إنتاجيةٍ ومراقبةٍ شخصية.
 */
class Phase7SplitGuardTest extends TestCase
{
    /** موظفةٌ مربوطةُ الحساب، بمهمّةٍ ونبضةِ جلسةٍ وزيارةِ صفحةٍ ذاتِ مسارٍ مميَّز */
    protected function seedPerson(): Employee
    {
        $this->seedCore();
        $uid = $this->employee->id;

        $emp = Employee::create(['name' => 'مهندسة', 'user_id' => $uid,
            'dept' => 'الهندسة', 'status' => 'نشط']);

        Task::create(['title' => 'مهمة مفتوحة', 'assignee_id' => $uid,
            'status' => 'قيد التنفيذ', 'due' => now()->addDay()->toDateString()]);
        DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid,
            'started_at' => now()->subDay()->setTime(2, 0),
            'last_seen_at' => now()->subDay()->setTime(3, 0)]);
        // نشاطٌ ليليٌّ ودخولٌ مريب: مادّةُ درجةِ الشك — كي لا تكون البطاقةُ صامتةً بذاتها
        for ($i = 0; $i < 6; $i++) {
            DB::table('page_visits')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid,
                'path' => '/سرّي-جداً-' . $i, 'at' => now()->subDay()->setTime(3, $i * 5)]);
        }
        hub_audit('دخول مريب', null, null, 'مهندسة', ['user_id' => $uid]);

        return $emp;
    }

    /** دورٌ يرى كلَّ الوحدات، برايةٍ اختيارية — وليس مالكاً بحال */
    protected function reader(string $email, array $flags = []): User
    {
        $role = Role::create(['name' => 'قارئ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => collect(array_keys(hub_modules()))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);

        return User::create(['name' => 'قارئ', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ── ١) الأمن للمالك وحدَه ── */

    public function test_the_security_card_on_the_employee_profile_is_owner_only(): void
    {
        $emp = $this->seedPerson();
        $url = route('portal.employee', $emp->id);

        // المالكُ يراها — البطاقةُ موجودةٌ فعلاً فالتوكيدُ المقابل ذو معنى
        $this->actingAs($this->owner)->get($url)->assertOk()
            ->assertSee('مخاطر النشاط الأمني')->assertSee('نسبة الشك');

        // وحاملُ رايةِ المتابعة (وهو يرى كلَّ الوحدات) لا يراها — المتابعةُ
        // صلاحيةُ عملٍ لا صلاحيةُ اطّلاعٍ على ملفٍّ أمنيٍّ لزميل
        $mon = $this->reader('split-mon@test.local', ['monitor' => 1]);
        $this->actingAs($mon)->get($url)->assertOk()
            ->assertSee('ملفّ العمل')                       // ملفُّ العمل ظاهرٌ له
            ->assertDontSee('مخاطر النشاط الأمني')
            ->assertDontSee('نسبة الشك');
    }

    /* ── ٢) النشاط خلف رايةِ المتابعة ── */

    public function test_the_activity_card_needs_the_monitor_flag(): void
    {
        $emp = $this->seedPerson();
        $url = route('portal.employee', $emp->id);

        $mon = $this->reader('split-act@test.local', ['monitor' => 1]);
        $this->actingAs($mon)->get($url)->assertOk()->assertSee('النشاط داخل النظام');

        // قارئٌ بلا رايةِ متابعة: ملفُّ العمل نعم، ومتى ظهر ومتى غاب لا
        $plain = $this->reader('split-plain@test.local');
        $this->actingAs($plain)->get($url)->assertOk()
            ->assertSee('ملفّ العمل')
            ->assertDontSee('النشاط داخل النظام')
            ->assertDontSee('أفعالٌ ذاتُ معنى');
    }

    /* ── ٣) لا أثرَ زياراتٍ خام في البطاقة ولو للمالك ── */

    public function test_the_employee_profile_never_spills_raw_page_visits(): void
    {
        $emp = $this->seedPerson();

        foreach ([$this->owner, $this->reader('split-raw@test.local', ['monitor' => 1])] as $who) {
            $html = $this->actingAs($who)->get(route('portal.employee', $emp->id))
                ->assertOk()->getContent();
            $this->assertStringNotContainsString('سرّي-جداً', $html,
                'مسارُ زيارةٍ خام تسرّب إلى بطاقة الموظف');
            $this->assertStringNotContainsString('مسار التنقل', $html,
                'أثرُ التنقّل مكانُه مركزُ النشاط للمالك وحدَه (spec §5.8)');
        }

        // والمصدرُ نفسُه: لا العرضُ ولا القارئُ يمسّان جدولَ الزيارات
        foreach ([resource_path('views/portal/_work.blade.php'),
                  app_path('Support/ExecutionStats.php')] as $src) {
            $this->assertStringNotContainsString('page_visits', file_get_contents($src),
                basename($src) . ' يقرأ الزيارات');
        }
    }
}
