<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Risk;
use App\Support\StepUp;
use Tests\TestCase;

/**
 * المرحلة ج — تصعيد المصادقة ومحرك الخطر المفسَّر.
 *
 * الأفعال الحرجة تتطلب إعادةَ تحقّق، والخطرُ يُفسَّر بعوامله لا يُعرَض رقماً.
 */
class StepUpAndRiskTest extends TestCase
{
    public function test_lockdown_requires_step_up_then_proceeds(): void
    {
        $this->seedCore();

        // بلا تصعيدٍ ساري: قفلُ الطوارئ يُعاد توجيهاً لشاشة التأكيد ولا يُفعَّل
        $this->actingAs($this->owner)->post('/admin/security/lockdown')
            ->assertRedirect();
        $this->assertNotSame('1', (string) setting('security.lockdown', '0'),
            'قفلُ الطوارئ فُعّل دون تصعيد مصادقة');

        // يؤكّد هويته (المالك بلا TOTP → كلمة المرور)
        $this->actingAs($this->owner)->post('/stepup', [
            'answer' => 'Secret!2026x', 'next' => '/admin/security',
        ])->assertRedirect('/admin/security');
        $this->assertTrue(StepUp::fresh());

        // الآن يُفعَّل القفل
        $this->actingAs($this->owner)->post('/admin/security/lockdown');
        $this->assertSame('1', (string) setting('security.lockdown', '0'));
    }

    public function test_step_up_rejects_a_wrong_answer_and_audits_it(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'خطأ', 'next' => '/'])
            ->assertRedirect();
        $this->assertFalse(StepUp::fresh());
        $this->assertDatabaseHas('audits', ['action' => 'فشل تصعيد المصادقة']);
    }

    public function test_role_edit_records_a_before_after_change_summary(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دور اختبار', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);

        // تصعيدٌ ساري أولاً
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/']);

        $this->actingAs($this->owner)->put('/admin/roles/' . $role->id, [
            'name' => 'دور اختبار', 'scope' => 'all',
            'flags' => ['exp' => 1],
            'matrix' => ['tasks' => ['v' => 1, 'e' => 1]],
        ])->assertRedirect();

        // التدقيق يحمل «قبل» و«بعد» وملخّصَ التغيير (رايةٌ أُضيفت)
        $row = \App\Models\AuditEntry::where('action', 'تعديل دور')->where('record_id', $role->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->before, 'تعديلُ الدور بلا لقطةِ «قبل»');
        $changed = implode(' | ', (array) ($row->after['_changed'] ?? []));
        $this->assertStringContainsString('exp', $changed, 'ملخّصُ التغيير لم يذكر الرايةَ المُضافة');
    }

    public function test_risk_engine_explains_its_factors(): void
    {
        $this->seedCore();
        // المالك: صاحبُ صلاحياتٍ عالية → عاملٌ واحدٌ على الأقل بنقاطه
        $r = Risk::session($this->owner, request());
        $this->assertIsArray($r['factors']);
        $this->assertArrayHasKey('score', $r);
        $this->assertArrayHasKey('band', $r);
        // كلُّ عاملٍ ببندٍ ونقاط — لا رقمٌ أعمى
        foreach ($r['factors'] as $f) {
            $this->assertArrayHasKey('label', $f);
            $this->assertArrayHasKey('points', $f);
            $this->assertGreaterThan(0, $f['points']);
        }
        // الدرجة مجموعُ نقاط عوامله (بحدّ ١٠٠)
        $this->assertSame(min(100, array_sum(array_column($r['factors'], 'points'))), $r['score']);
    }

    public function test_adaptive_policy_redirects_privileged_user_without_2fa(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.2fa_required_priv', '1');

        // موظفٌ عاديّ (بلا راية) لا يُعترَض
        $this->actingAs($this->employee)->get('/')->assertOk();

        // دورٌ صاحبُ راية أسرار بلا 2FA → يُوجَّه للملف لتفعيله
        $role = Role::create(['name' => 'أمين خزنة', 'scope' => 'all', 'flags' => ['secrets' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $priv = User::create(['name' => 'أمين', 'email' => 'vault@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($priv)->get('/')->assertRedirect(route('profile.edit'));
        // وملفُّه مفتوحٌ ليُفعّل التحقّق (لا يُحبَس)
        $this->actingAs($priv)->get('/profile')->assertOk();
    }

    public function test_secret_step_up_gate_is_off_by_default(): void
    {
        // القاعدة الافتراضية: لا تصعيد على الكشف (كي لا يُعطَّل القائم)
        $this->assertSame('0', (string) setting('security.stepup_secrets', '0'));
    }
}
