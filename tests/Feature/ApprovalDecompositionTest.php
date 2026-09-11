<?php

namespace Tests\Feature;

use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **تفكيكُ رايةِ الاعتماد** (Permissions 360 · م2 · 01.3/11.3/20.2 + رقابة 02.1/20.1).
 *
 * إضافةٌ لا كسر: الرايةُ `approve` تبقى مفتاحاً رئيساً يمنحُ الحسمَ في كلِّ مجال؛ وأُضيف
 * مفتاحٌ دقيقٌ `<module>.approve` (payroll/purchases/quotes/custody) لمن يُراد له حسمُ مجالٍ
 * واحدٍ دون البقية — يُقرأ بـ`hub_can` من نفسِ المصفوفة، ويحفظه محرِّرُ الأدوار. ورقابةُ
 * الاتصالات صارت رايةً مُسنَدةً بدل التوثيقِ باسمِ الدور.
 */
class ApprovalDecompositionTest extends TestCase
{
    private function userWith(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ اعتمادُ العروض: مفتاحٌ دقيقٌ يُغني عن الرايةِ الجامعة ═══════════ */

    /** حاملُ `quotes.approve` (بلا رايةِ approve) يُرسلُ عرضاً فوقَ العتبةِ مباشرةً */
    public function test_quotes_approve_key_sends_over_threshold_directly(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_amount', '100');   // عتبةٌ تُفعّل مسارَ الاعتماد
        $u = $this->userWith('qa@test.local', ['quotes' => ['v' => 1, 'e' => 1, 'approve' => 1]]);
        $q = Quote::create(['title' => 'عرض', 'total' => 9000, 'currency' => 'د.ك', 'status' => 'مسودة']);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertNotSame('مراجعة داخلية', $q->fresh()->status,
            'حاملُ quotes.approve يُرسلُ مباشرةً دون مسارِ المراجعة');
    }

    /** نظيرٌ سلبيّ: بلا `quotes.approve` ولا رايةٍ ⇒ يُحوَّل للمراجعةِ الداخلية */
    public function test_without_approve_over_threshold_goes_to_internal_review(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_amount', '100');
        $u = $this->userWith('noqa@test.local', ['quotes' => ['v' => 1, 'e' => 1]]);
        $q = Quote::create(['title' => 'عرض ٢', 'total' => 9000, 'currency' => 'د.ك', 'status' => 'مسودة']);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertSame('مراجعة داخلية', $q->fresh()->status,
            'بلا صلاحيةِ اعتمادٍ يجب أن يمرَّ بالمراجعة');
    }

    /** الرايةُ الجامعةُ تبقى تعمل (توافقٌ خلفيّ): حاملُها يُرسلُ مباشرةً */
    public function test_legacy_approve_flag_still_sends_directly(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_amount', '100');
        $u = $this->userWith('flag@test.local', ['quotes' => ['v' => 1, 'e' => 1]], ['approve' => 1]);
        $q = Quote::create(['title' => 'عرض ٣', 'total' => 9000, 'currency' => 'د.ك', 'status' => 'مسودة']);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertNotSame('مراجعة داخلية', $q->fresh()->status, 'رايةُ approve الجامعةُ تبقى مفتاحاً رئيساً');
    }

    /* ═══════════ رقابةُ الاتصالات: رايةٌ مُسنَدةٌ لا اسمُ دور ═══════════ */

    public function test_oversight_flag_makes_officer(): void
    {
        $this->seedCore();
        $officer = $this->userWith('ov@test.local', ['feed' => ['v' => 1]], ['oversight' => 1]);
        $plain = $this->userWith('plain@test.local', ['feed' => ['v' => 1]]);

        $this->assertTrue(\App\Http\Controllers\Web\OversightController::isOversightOfficer($officer),
            'رايةُ oversight تجعلُ الحاملَ ضابطَ رقابة');
        $this->assertFalse(\App\Http\Controllers\Web\OversightController::isOversightOfficer($plain),
            'بلا الرايةِ (ولا اسمِ الدورِ المُعَدّ) ليس ضابطَ رقابة');
    }

    /* ═══════════ المحرِّرُ يحفظُ المفتاحَ الدقيقَ والرايةَ الجديدة ═══════════ */

    public function test_role_editor_saves_module_approve_key_and_oversight_flag(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'معتمِدُ رواتب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['payroll' => ['v' => 1]]]);

        // رايةُ oversight حسّاسةٌ فتتطلبُ تصعيدَ المصادقة — نُرضيه في الاختبار
        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->put(route('roles.update', $role), [
                'name' => 'معتمِدُ رواتب', 'scope' => 'all', 'matrix_submitted' => '1', 'flags_submitted' => '1',
                'matrix' => ['payroll' => ['v' => '1', 'approve' => '1']],
                'flags' => ['oversight' => '1'],
            ])->assertRedirect();

        $fresh = $role->fresh();
        $this->assertNotEmpty($fresh->matrix['payroll']['approve'] ?? null, 'payroll.approve حُفِظ');
        $this->assertNotEmpty($fresh->flags['oversight'] ?? null, 'رايةُ oversight حُفِظت');

        // ويُقرأ فِعليّاً عبرَ hub_can (لا محرّكَ ثانٍ)
        $u = User::create(['name' => 'ح', 'email' => 'payapp@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->assertTrue(hub_can($u, 'payroll', 'approve'));
        $this->assertFalse(hub_can($u, 'purchases', 'approve'), 'المفتاحُ لمجالِه وحدَه');
    }
}
