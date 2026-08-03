<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\ErrorLog;
use Tests\TestCase;

/**
 * **الخطأ يصل صاحبه — لا ينتظر أن يصطدم به.**
 *
 * مركزُ الأخطاء يلتقط كل خطأ ٥٠٠ تلقائياً ويُجمّعه ببصمته، وهذا مبنيٌّ ويعمل.
 * لكنه **لا يُنبّه أحداً**: فيجلس العطلُ في شاشةٍ لا يفتحها أحد حتى يصطدم به
 * صاحبُ النظام بنفسه — وهو ما وقع ثلاث مرات في دورةٍ واحدة (الإعدادات، ومركز
 * الأمان، والباقات). **صاحبُ النظام ليس جهاز رصد.**
 *
 * والقواعد التي تجعل الإشعار يُقرأ لا يُتجاهَل:
 *   · **أول ظهورٍ فقط**: الخطأ المتكرر يُزاد عدّادُه ولا يُعاد التنبيه به.
 *   · **والعودةُ خبر**: خطأٌ حُلّ ثم عاد يُنبَّه من جديد.
 *   · **وسقفٌ للانفجار**: نشرةٌ سيئة تُولّد عشراتِ الأخطاء الجديدة دفعةً —
 *     فيُنبَّه عن الأوائل ويُقال «وغيرها» بدل إغراق الصندوق.
 *   · **ولا يكسر التنبيهُ ما يحرسه**: فشلُ كتابة الإشعار لا يُفاقم الخطأ الأصلي.
 */
class ErrorReachesOwnerTest extends TestCase
{
    private function boom(string $msg = 'انفجارٌ في المالية'): void
    {
        ErrorLog::capture('php', $msg, '/app/Foo.php', 42);
    }

    private function errNotifs($user = null): int
    {
        return HubNotification::where('user_id', ($user ?? $this->owner)->id)
            ->where('kind', 'error')->count();
    }

    public function test_a_new_error_reaches_the_owner(): void
    {
        $this->seedCore();
        $this->boom();

        $this->assertSame(1, $this->errNotifs(),
            'وقع خطأٌ ٥٠٠ ولم يعلم به أحد — فيبقى حتى يصطدم به صاحب النظام');
    }

    /** والنصُّ يقول ماذا وأين — لا «حدث خطأ» */
    public function test_the_notification_names_what_broke(): void
    {
        $this->seedCore();
        $this->boom('انفجارٌ في المالية');

        $n = HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')->first();
        $this->assertStringContainsString('انفجارٌ في المالية', (string) $n?->text);
    }

    public function test_a_repeated_error_does_not_notify_again(): void
    {
        $this->seedCore();
        $this->boom();
        $this->boom();
        $this->boom();

        $this->assertSame(1, $this->errNotifs(),
            'الخطأُ نفسه نبّه ثلاث مرات — والتكرارُ بلا معنى يُدرّب على التجاهل');
        $this->assertSame(3, (int) ErrorEvent::first()->count, 'ضاع عدّ التكرار');
    }

    /** وخطأٌ آخر مختلف خبرٌ آخر */
    public function test_a_different_error_is_its_own_news(): void
    {
        $this->seedCore();
        $this->boom('الأول');
        $this->boom('الثاني');

        $this->assertSame(2, $this->errNotifs());
    }

    /** وخطأٌ حُلّ ثم عاد — عودتُه خبر */
    public function test_a_resolved_error_that_returns_notifies_again(): void
    {
        $this->seedCore();
        $this->boom();
        ErrorEvent::query()->update(['status' => 'محلول']);

        $this->boom();

        $this->assertSame(2, $this->errNotifs(),
            'عاد خطأٌ حُسب محلولاً ولم يُنبَّه أحد — والعودةُ أهمُّ من الظهور الأول');
    }

    /** ونشرةٌ سيئة لا تُغرِق الصندوق */
    public function test_a_burst_of_new_errors_is_capped(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 40; $i++) $this->boom("خطأ رقم {$i}");

        $n = $this->errNotifs();
        $this->assertGreaterThan(0, $n, 'لم يصل شيء أصلاً');
        $this->assertLessThanOrEqual(ErrorLog::NOTIFY_BURST_CAP, $n,
            'أربعون خطأً جديداً كتبت أربعين إشعاراً — الصندوق يُغرَق فيُهجَر');
    }

    /** ويصل من يملك المراقبة كما يصل المالك */
    public function test_monitors_are_told_too(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $mon = User::create(['name' => 'مراقب', 'email' => 'mon@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->boom();

        $this->assertSame(1, $this->errNotifs($mon));
    }

    /** ولا يصل من لا شأن له بالأعطال */
    public function test_an_ordinary_employee_is_not_bothered(): void
    {
        $this->seedCore();
        $this->boom();

        $this->assertSame(0, $this->errNotifs($this->employee),
            'أُزعج موظفٌ بخطأٍ تقنيّ لا يملك حياله شيئاً');
    }

    /**
     * **ولا يكسر التنبيهُ ما يحرسه**: مسجّلُ الأخطاء لا يرمي أبداً — فشلُ كتابة
     * الإشعار لا يجوز أن يُفاقم الخطأ الأصلي أو يُسقط الطلب.
     */
    public function test_a_failing_notification_never_worsens_the_original_error(): void
    {
        $this->seedCore();
        HubNotification::creating(function () { throw new \RuntimeException('صندوق ممتلئ'); });

        $this->boom();     // لا يجوز أن يرمي

        $this->assertSame(1, ErrorEvent::count(), 'ضاع تسجيلُ الخطأ لأن إشعاره فشل');
    }

    /** والحسابُ الموقوف لا يُراكم إشعاراتٍ لن يفتحها */
    public function test_a_suspended_admin_is_skipped(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $mon = User::create(['name' => 'موقوف', 'email' => 'sus@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'موقوف',
            'password_changed_at' => now()]);

        $this->boom();

        $this->assertSame(0, $this->errNotifs($mon));
    }
}
