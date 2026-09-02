<?php

namespace Tests\Feature;

use App\Support\SecurityEvents;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** الأحداثُ الأمنية القانونية: تصنيفٌ واحد فوق التدقيق ورادار المنع — بلا جدولٍ ثانٍ. */
class SecurityEventsTest extends TestCase
{
    public function test_existing_arabic_actions_map_to_canonical_codes(): void
    {
        $this->assertSame('AUTH_FAILURE', SecurityEvents::codeFor('دخول فاشل'));
        $this->assertSame('AUTH_SUCCESS', SecurityEvents::codeFor('دخول ناجح'));
        $this->assertSame('SECRET_REVEALED', SecurityEvents::codeFor('عرض حساس عبر API'));
        $this->assertSame('SESSION_REVOKED', SecurityEvents::codeFor('إنهاء جلسات مستخدم'));
        $this->assertSame('API_CREDENTIAL_CREATED', SecurityEvents::codeFor('إنشاء مفتاح API'));
        $this->assertSame('SECURITY_POLICY_CHANGED', SecurityEvents::codeFor('تجميد التصدير (طوارئ)'));
        $this->assertSame('SECURITY_POLICY_CHANGED', SecurityEvents::codeFor('تفعيل قفل الطوارئ'));
        $this->assertSame('SECURITY_POLICY_CHANGED', SecurityEvents::codeFor('تعديل إعدادات النظام', 'settings', null, 'security.stepup_minutes · auth.pw_min'));
        $this->assertSame('SETTINGS_CHANGED', SecurityEvents::codeFor('تعديل إعدادات النظام', 'settings', null, 'app.name'));
        $this->assertSame('ROLE_CHANGED', SecurityEvents::codeFor('تعديل دور', 'roles'));
        $this->assertSame('PERMISSION_CHANGED', SecurityEvents::codeFor('تعديل', 'users', ['role_id' => 'x']));
        $this->assertNull(SecurityEvents::codeFor('تعديل', 'users', ['phone' => '1']), 'تعديلُ هاتفٍ ليس حدثاً أمنياً');
        $this->assertNull(SecurityEvents::codeFor('تعديل', 'clients', ['name' => 'x']));
        $this->assertSame('SENSITIVE_EXPORT', SecurityEvents::codeFor('تصدير كبير'));
        $this->assertSame('DATA_EXPORT', SecurityEvents::codeFor('تصدير'));
        $this->assertSame('MFA_CHALLENGE', SecurityEvents::codeFor('تحدّي التحقق بخطوتين'));
    }

    public function test_unified_log_merges_audit_and_radar_and_filters_by_code(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('دخول فاشل', null, null, 'x@y.z');
        hub_audit('عرض حساس', 'vault', null, 'كلمة سر الخادم — القيمة');
        hub_audit('تعديل', 'clients', null, 'عميل');   // ليس أمنياً
        $this->employee->role->forceFill(['name' => 'موظفة ٢'])->save();
        hub_audit('تعديل دور', 'roles', $this->employee->role_id, 'موظفة ٢', ['after' => ['flags' => []]]);
        DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'user_id' => $this->employee->id, 'ip' => '10.0.0.9',
            'method' => 'GET', 'path' => '/admin/security', 'created_at' => now()]);

        $all = SecurityEvents::recent(7, 50);
        $codes = $all->pluck('code')->all();
        foreach (['AUTH_FAILURE', 'SECRET_REVEALED', 'ROLE_CHANGED', 'ACCESS_DENIED'] as $c) $this->assertContains($c, $codes, $c);
        $this->assertNotContains(null, $codes);
        $this->assertSame(0, $all->where('name', 'عميل')->count(), 'تعديلُ عميلٍ لا يدخل السجل الأمني');

        $denied = $all->firstWhere('code', 'ACCESS_DENIED');
        $this->assertSame('radar', $denied['source']);
        $this->assertSame('10.0.0.9', $denied['ip']);
        $this->assertStringContainsString('/admin/security', $denied['name']);

        $only = SecurityEvents::recent(7, 50, 'SECRET_REVEALED');
        $this->assertCount(1, $only);
        $this->assertNotEmpty($only[0]['request_id'] ?? null, 'معرّف الطلب يصل السجل الأمني');

        $counts = SecurityEvents::counts(7);
        $this->assertSame(1, $counts['AUTH_FAILURE']);
        $this->assertSame(1, $counts['ACCESS_DENIED']);
    }

    public function test_security_center_renders_and_filters_the_log(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('دخول فاشل', null, null, 'attacker@x.y');
        hub_audit('عرض حساس', 'vault', null, 'سرّ الخادم');

        // اسمُ الدخول الفاشل يظهر أيضاً في بطاقة «آخر المحاولات الفاشلة» — فنعدّ ظهوره: مرتان بلا تصفية، ومرةٌ واحدة (البطاقة وحدها) عند التصفية
        $all = $this->get('/admin/security')->assertOk()->assertSee('السجل الأمني الموحّد')->getContent();
        $this->assertSame(2, substr_count($all, 'attacker@x.y'));
        $this->assertStringContainsString('سرّ الخادم', $all);
        $filtered = $this->get('/admin/security?ev=SECRET_REVEALED')->assertOk()->assertSee('سرّ الخادم')->getContent();
        $this->assertSame(1, substr_count($filtered, 'attacker@x.y'), 'التصفيةُ تُخرج الدخولَ الفاشل من السجل الموحّد');
        $this->get('/admin/security?ev=NOT_A_CODE')->assertOk();
        $this->actingAs($this->employee)->get('/admin/security')->assertForbidden();
    }

    public function test_mfa_challenge_is_audited(): void
    {
        $this->seedCore();
        $this->owner->forceFill(['totp_enabled' => true, 'totp_secret_cipher' => \App\Support\Totp::secret()])->save();
        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'Secret!2026x'])->assertRedirect(route('login.otp'));
        $this->assertSame(1, DB::table('audits')->where('action', 'تحدّي التحقق بخطوتين')->where('user_id', $this->owner->id)->count());
    }
}
