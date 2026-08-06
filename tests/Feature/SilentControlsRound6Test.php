<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٨: **ضوابطُ تُعلن العمل وهي معطَّلة أو منقلبة.**
 *
 *  ١) `AuthController:29-47` — أربعةُ فروعٍ تُفصح عن **حالة الحساب قبل التحقق
 *     من كلمة المرور**: موقوف/منتهٍ/مقفول/محصورٌ بشبكة، وخامسٌ لقفل الطوارئ.
 *     فأربعُ رسائل مختلفة تقول للمهاجم: هذا البريد موجود. أوراكلُ تعداد
 *     بطلبٍ واحد، ورسالةُ القفل تُخبره أنّ هجومَه نجح.
 *
 *  ٢) `ErrorLog:97` — مسارُ الطلب يُعمَّم ثمّ يُرسَل لكل المراقبين، فرمزُ نقطة
 *     الاستقبال (`/hook/<48 محرفاً>` — بيانُ الاعتماد الوحيد لنقطةٍ غير موقّعة)
 *     يصل مَن لا يملك رؤيتَه.
 *
 *  ٣) `UserController:104` — إداريُّ المستخدمين المعزولُ بشركات يُنشئ حساباً
 *     **بلا `companies`** فيولد غيرَ معزول: تجاوزٌ كامل لحارس «العزل لا يُفكّ
 *     عن النفس» — يصنع لنفسه باباً خلفياً يرى المنشأة كلها.
 *
 *  ٤) `HireController:28` — التعيين ليس ذرّياً: `Employee::create` ثمّ إنشاءُ
 *     الحساب. فشلُ الثاني يترك ملفاً وظيفياً يتيماً **وحارسُ عدم التكرار
 *     مبنيٌّ على `meta['employee_id']`** الذي لم يُكتب بعد — فالتعيينُ الثاني
 *     يُنشئ ملفاً ثانياً للمرشح نفسه.
 */
class SilentControlsRound6Test extends TestCase
{
    /* ── ١) لا أوراكلَ تعداد على شاشة الدخول ── */

    public function test_login_does_not_reveal_whether_an_account_exists(): void
    {
        $this->seedCore();

        $this->owner->forceFill(['status' => 'موقوف'])->saveQuietly();

        $known = $this->post('/login', ['email' => $this->owner->email, 'password' => 'WrongPass!1']);
        $ghost = $this->post('/login', ['email' => 'nobody@test.local', 'password' => 'WrongPass!1']);

        $this->assertSame($ghost->getStatusCode(), $known->getStatusCode(),
            'رمزُ الردّ يفرّق بين بريدٍ موجودٍ وبريدٍ وهميّ');

        $msgOf = function (string $email): string {
            $this->flushSession();
            $this->post('/login', ['email' => $email, 'password' => 'WrongPass!1']);

            return (string) (session('errors')?->get('email')[0] ?? '');
        };
        $this->assertSame($msgOf('nobody@test.local'), $msgOf($this->owner->email),
            'الرسالةُ تفرّق بين بريدٍ موجودٍ وبريدٍ وهميّ — أوراكلُ تعداد بطلبٍ واحد');
    }

    public function test_locked_and_suspended_accounts_give_the_generic_message(): void
    {
        $this->seedCore();

        foreach (['موقوف' => null, 'نشط' => now()->addHour()] as $status => $lock) {
            $this->owner->forceFill(['status' => $status, 'locked_until' => $lock])->saveQuietly();
            $this->post('/login', ['email' => $this->owner->email, 'password' => 'WrongPass!1']);
            $msg = (string) (session('errors')?->get('email')[0] ?? '');

            $this->assertStringNotContainsString('موقوف', $msg,
                'الردُّ يُفصح عن حالة الحساب قبل التحقق من كلمة المرور — أوراكلُ تعداد');
            $this->assertStringNotContainsString('مقفل', $msg,
                'الردُّ يُخبر المهاجمَ أنّ قفلَه نجح');
        }
    }

    /** والحسابُ السليم يبقى يدخل — الحارس لا يقفل الباب على أصحابه */
    public function test_a_valid_login_still_succeeds(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'Secret!2026x'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->owner->fresh());
    }

    /* ── ٢) رمزُ نقطة الاستقبال لا يتسرّب في الإشعار ── */

    public function test_hook_token_is_redacted_from_error_notifications(): void
    {
        $this->seedCore();

        $token = \Illuminate\Support\Str::random(48);
        $req = \Illuminate\Http\Request::create('/hook/' . $token, 'POST');

        \App\Support\ErrorLog::capture(new \RuntimeException('عطلٌ في /hook/' . $token), $req);

        $rows = \App\Models\HubNotification::pluck('text')->implode(' | ')
              . ' | ' . \App\Models\ErrorEvent::pluck('url')->implode(' | ')
              . ' | ' . \App\Models\ErrorEvent::pluck('message')->implode(' | ');

        $this->assertStringNotContainsString($token, $rows,
            'رمزُ نقطة الاستقبال — بيانُ الاعتماد الوحيد — تسرّب في الإشعار أو في صفّ الخطأ');
    }

    /* ── ٣) العزل لا يُفكّ عن النفس ولا عن غيرها ── */

    public function test_a_company_scoped_user_admin_cannot_create_an_unscoped_account(): void
    {
        $this->seedCore();

        $a = \App\Models\Company::create(['name_ar' => 'شركة ألف']);
        $role = Role::create(['name' => 'إداريُّ المستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all()]);
        $admin = User::create(['name' => 'إداريّ', 'email' => 'ua@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$a->id], 'password_changed_at' => now()]);

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'بابٌ خلفيّ', 'email' => 'backdoor@test.local',
            'role_id' => $role->id, 'status' => 'نشط', 'password' => 'Secret!2026x',
        ]);

        $made = User::where('email', 'backdoor@test.local')->first();
        if ($made) {
            $this->assertNotEmpty((array) $made->companies,
                'إداريٌّ معزولٌ بشركةٍ أنشأ حساباً بلا عزل — بابٌ خلفيّ يرى المنشأة كلها');
        }
    }

    /* ── ٤) التعيين ذرّيّ ── */

    public function test_hiring_twice_never_creates_two_employee_files(): void
    {
        $this->seedCore();

        $c = Candidate::create(['name' => 'مرشحُ الاختبار', 'job' => 'مطوّر',
            'email' => 'cand@test.local', 'status' => 'مقابلة']);

        $this->actingAs($this->owner)->post('/candidates/' . $c->id . '/hire');
        $this->actingAs($this->owner)->post('/candidates/' . $c->id . '/hire');

        $this->assertSame(1, Employee::where('name', 'مرشحُ الاختبار')->count(),
            'التعيينُ مرّتين أنشأ ملفَّين وظيفيَّين للمرشح نفسه — حارسُ عدم التكرار '
            . 'مبنيٌّ على meta[employee_id] الذي لا يُكتب إن تعثّر ما بعده');
    }
}
