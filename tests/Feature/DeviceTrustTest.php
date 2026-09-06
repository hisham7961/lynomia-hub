<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.4 — مركزُ ثقة الأجهزة (security.devices).
 *
 * التصنيفُ من `user_devices` القائمة **بما فيها المحذوفةُ ناعماً** وبلا أيّ
 * بصمةِ جهازٍ جديدة: موثوق (known) · معلّق (new) · مبطَل/محذوف (revoked) ·
 * و«مريب» وسمٌ مشتقّ — جهازٌ معلّقٌ آخرُ عنوانه غيرُ مألوفٍ لصاحبه (من
 * ذاكرة user_ips نفسِها التي يستعملها حارسُ الدخول).
 *
 * والقراءةُ كإخوتها: مالكٌ كامل، مراقبٌ مطموسُ البريد والعنوان ومنطَّقٌ
 * بالشركة، موظفٌ ٤٠٣ — ولا يظهر cookie_hash في أيّ ردٍّ أبداً.
 */
class DeviceTrustTest extends TestCase
{
    protected function monitorUser(array $companies = []): User
    {
        $role = Role::create(['name' => 'مراقب ' . Str::random(4), 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon' . Str::random(4) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => $companies]);
    }

    protected function makeDevice(User $u, array $over = []): UserDevice
    {
        return UserDevice::create($over + ['user_id' => $u->id,
            'cookie_hash' => hash('sha256', Str::random(48)),
            'label' => 'Chrome · Windows', 'platform' => 'Windows', 'trust' => 'معلّق',
            'first_ip' => '5.5.5.5', 'last_ip' => '5.5.5.5',
            'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()]);
    }

    /** الفئاتُ الأربع تُغطّى — والمحذوفُ ناعماً يبقى مرئياً في المركز */
    public function test_categories_cover_known_new_suspicious_and_revoked_including_soft_deleted(): void
    {
        $this->seedCore();
        // 5.5.5.5 مكانُ عملٍ معتاد للموظفة (٣ زياراتٍ فأكثر في ذاكرة العناوين)
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'ip' => '5.5.5.5', 'hits' => 5, 'last_seen_at' => now()]);

        $this->makeDevice($this->employee, ['trust' => 'موثوق', 'label' => 'جهاز موثوق']);
        $this->makeDevice($this->employee, ['trust' => 'معلّق', 'label' => 'جهاز معلق مألوف']);
        $sus = $this->makeDevice($this->employee, ['trust' => 'معلّق', 'label' => 'جهاز غريب', 'last_ip' => '7.7.7.7']);
        $rev = $this->makeDevice($this->employee, ['trust' => 'مبطَل', 'label' => 'جهاز مبطل']);
        $rev->delete();   // الإبطالُ يحذف ناعماً — والمركزُ يعرضه مع ذلك

        $html = $this->actingAs($this->owner)->get(route('security.devices'))->assertOk()->getContent();
        foreach (['جهاز موثوق', 'جهاز معلق مألوف', 'جهاز غريب', 'جهاز مبطل'] as $label) {
            $this->assertStringContainsString($label, $html, "الجهاز «{$label}» غائبٌ عن المركز");
        }
        // «مريب» يصيب الجهازَ الغريبَ وحدَه — لا المألوفَ المعلّق
        $this->assertSame(1, substr_count($html, 'مريب'),
            'وسمُ «مريب» يجب أن يصيب جهازاً واحداً (معلّقٌ من عنوانٍ غير مألوف)');

        // المرشِّحات تضيّق فعلاً
        $this->actingAs($this->owner)->get(route('security.devices', ['t' => 'suspicious']))
            ->assertOk()->assertSee('جهاز غريب')->assertDontSee('جهاز موثوق');
        $this->actingAs($this->owner)->get(route('security.devices', ['t' => 'revoked']))
            ->assertOk()->assertSee('جهاز مبطل')->assertDontSee('جهاز موثوق');
        $this->actingAs($this->owner)->get(route('security.devices', ['t' => 'known']))
            ->assertOk()->assertSee('جهاز موثوق')->assertDontSee('جهاز غريب');

        $this->assertNotNull($sus->fresh(), 'لا حذفَ ولا بصمةَ جديدة — القراءةُ عرضٌ فقط');
    }

    /** حالةٌ فارغة صادقة قبل أول جهاز */
    public function test_honest_empty_state_before_any_device(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get(route('security.devices'))
            ->assertOk()->assertSee('لا أجهزة');
    }

    /** اختبارُ التسريب الإلزاميّ: مالكٌ كامل · مراقبٌ مطموسٌ ومنطَّق · موظفٌ ٤٠٣ · لا cookie_hash */
    public function test_monitor_reads_masked_and_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $coA = (string) Str::uuid();
        $coB = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $coA, 'name_ar' => 'شركة أ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $coB, 'name_ar' => 'شركة ب', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $target = User::create(['name' => 'صاحب الجهاز', 'email' => 'dev9@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$coA]]);
        $dev = $this->makeDevice($target, ['last_ip' => '8.8.4.4', 'first_ip' => '8.8.4.4']);

        $this->actingAs($this->employee)->get(route('security.devices'))->assertForbidden();

        $this->actingAs($this->owner)->get(route('security.devices'))
            ->assertOk()->assertSee('8.8.4.4')->assertSee('dev9@test.local');

        $html = $this->actingAs($this->monitorUser())->get(route('security.devices'))->assertOk()->getContent();
        $this->assertStringNotContainsString('8.8.4.4', $html, 'عنوانُ الجهاز تسرّب لقارئ المراقبة');
        $this->assertStringNotContainsString('dev9@test.local', $html, 'البريدُ تسرّب لقارئ المراقبة');
        $this->assertStringContainsString('عنوان محجوب', $html);
        $this->assertStringNotContainsString($dev->cookie_hash, $html, 'cookie_hash سرٌّ — لا يُعرض لأحدٍ أبداً');

        // المالكُ كذلك لا يرى cookie_hash
        $ownerHtml = $this->actingAs($this->owner)->get(route('security.devices'))->getContent();
        $this->assertStringNotContainsString($dev->cookie_hash, $ownerHtml);

        // مراقبٌ منطَّق على شركةٍ أخرى لا يرى جهازَ الهدف
        $this->actingAs($this->monitorUser([$coB]))->get(route('security.devices'))
            ->assertOk()->assertDontSee('صاحب الجهاز');
    }
}
