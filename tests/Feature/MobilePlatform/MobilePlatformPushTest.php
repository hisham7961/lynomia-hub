<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\AuditEntry;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Models\Role;
use App\Models\User;
use App\Support\MobilePlatform;
use App\Support\PushService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الدفع (MPC-3)**: حالةُ المزوّدِ صادقة (`NOT_CONFIGURED` لا
 * نجاحٌ مزيّف، ولا مفتاحَ FCM يُعرَض)، عدُّ الرموزِ حقيقيّ، سجلُّ التسليمِ مُرشَّح،
 * واختبارُ الدفعِ آمنٌ (جهازُ المُختبِرِ وحدَه، مُدقَّق، دون تجاوزِ ضبطٍ ولا تلويثِ إحصاء).
 */
class MobilePlatformPushTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob3@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function seedToken(User $u, string $platform = 'ios', ?string $provider = 'fcm'): PushToken
    {
        return PushService::register($u, (string) Str::uuid(), $platform, $provider, 'tok-' . Str::random(20));
    }

    public function test_push_tab_shows_provider_status_not_configured_by_default(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=push')->assertOk()
            ->assertSee('حالةُ مزوّد الدفع')
            ->assertSee('غير مُهيّأ')   // صدقُ NOT_CONFIGURED
            ->assertSee('اختبارُ دفعٍ آمن');
    }

    public function test_token_counts_are_real(): void
    {
        $this->seedCore();
        $this->seedToken($this->employee, 'ios');
        $this->seedToken($this->employee, 'android');

        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=push')->assertOk()
            ->assertSee('رموزٌ حيّة')
            ->assertSee('iOS 1 · Android 1');
    }

    public function test_fcm_secret_value_is_never_rendered_on_push_tab(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SECRET-FCM-TOKEN-999');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=push')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-FCM-TOKEN-999', $html, 'سرُّ FCM ظهر في تبويب الدفع');
    }

    public function test_delivery_log_and_breakdown_render(): void
    {
        $this->seedCore();
        PushDelivery::create(['notification_id' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
            'provider' => 'fcm', 'status' => 'delivered', 'attempts' => 1, 'queued_at' => now(), 'attempted_at' => now()]);
        PushDelivery::create(['notification_id' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
            'provider' => 'fcm', 'status' => 'failed', 'error_category' => 'provider_error',
            'attempts' => 1, 'queued_at' => now(), 'attempted_at' => now()]);

        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=push')->assertOk()
            ->assertSee('تفصيلُ التسليم')
            ->assertSee('provider_error')   // صنفُ الخطأِ التقنيّ (لا رسالةَ مزوّدٍ خام)
            ->assertSee('سجلُّ محاولاتِ التسليم');
    }

    /** الترشيحُ يحترمُ allowlist ويُصفّي فعلاً (لا حقنَ عمود) */
    public function test_deliveries_filter_by_status(): void
    {
        $this->seedCore();
        PushDelivery::create(['notification_id' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
            'provider' => 'fcm', 'status' => 'delivered', 'attempts' => 1, 'queued_at' => now()]);
        PushDelivery::create(['notification_id' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
            'provider' => 'fcm', 'status' => 'failed', 'error_category' => 'rate_limited', 'attempts' => 1, 'queued_at' => now()]);

        $this->assertSame(1, MobilePlatform::deliveries(['status' => 'failed'])->count());
        $this->assertSame(1, MobilePlatform::deliveries(['status' => 'delivered'])->count());
        $this->assertSame(2, MobilePlatform::deliveries([])->count());
        // قيمةٌ خارجَ allowlist تُتجاهَل (لا ترشيح) لا تُحقَن
        $this->assertSame(2, MobilePlatform::deliveries(['status' => "x' OR 1=1"])->count());
    }

    public function test_employee_cannot_access_push_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=push')->assertForbidden();
    }

    public function test_employee_cannot_run_push_test(): void
    {
        $this->seedCore();
        $this->seedToken($this->employee);
        $this->actingAs($this->employee)->post(route('mobileplatform.push.test'))->assertForbidden();
    }

    public function test_push_test_with_no_device_warns_and_does_not_crash(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post(route('mobileplatform.push.test'))
            ->assertRedirect()->assertSessionHas('warn');
        $this->assertSame(0, PushDelivery::count(), 'الاختبارُ لوّثَ سجلَّ التسليم');
    }

    /** المزوّدُ الصفريّ ⇒ نتيجةٌ صادقة، تدقيقٌ، ولا صفَّ تسليمٍ يُنشأ (لا تلويث) */
    public function test_push_test_not_configured_is_honest_and_audited(): void
    {
        $this->seedCore();
        $this->seedToken($this->owner, 'ios');

        $this->actingAs($this->owner)->post(route('mobileplatform.push.test'))
            ->assertRedirect()->assertSessionHas('warn');

        $this->assertDatabaseHas('audits', ['action' => 'اختبارُ دفعٍ إداريّ']);
        $this->assertSame(0, PushDelivery::count(), 'اختبارُ الدفعِ أنشأ صفَّ تسليمٍ حقيقيّاً — تلويثُ إحصاء');
    }

    /** الاختبارُ يستهدفُ **جهازَ المُختبِرِ وحدَه** لا جهازَ غيرِه (لا مراقبة) */
    public function test_push_test_targets_only_own_device(): void
    {
        $this->seedCore();
        $this->seedToken($this->owner, 'ios');            // جهازُ المالكِ الوحيد
        $this->seedToken($this->employee, 'android');     // جهازُ موظّفٍ — يجب ألّا يُمَسّ
        $this->seedToken($this->employee, 'ios');

        $this->actingAs($this->owner)->post(route('mobileplatform.push.test'))->assertRedirect();

        $audit = AuditEntry::where('action', 'اختبارُ دفعٍ إداريّ')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(1, $audit->after['push_test']['devices'] ?? null, 'الاختبارُ مسّ أجهزةَ غيرِ المُختبِر');
    }

    public function test_mobile_admin_can_view_push_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=push')->assertOk()
            ->assertSee('حالةُ مزوّد الدفع');
    }
}
