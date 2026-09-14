<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — قفلُ الحسابِ بلا بابِ استرداد (A · أمن/حوكمة).**
 *
 * أثناء هجومِ الخبير ١٣ المصرَّحِ به **قُفل حسابُ موظّفٍ حقيقيّ** بعد محاولاتِ
 * تخمينٍ متتالية — وهو سلوكٌ أمنيٌّ صحيحٌ تماماً. ثمّ حاولتُ أن أفكَّ القفلَ
 * **من داخل المنتج** فلم أجد سبيلاً: لا زرَّ في شاشةِ المستخدمين، ولا مسارَ
 * `POST`، ولا أمرَ حرفيّ. والقفلُ ثلاثُ ساعاتٍ في العرض، وقابلٌ للضبطِ أطول.
 *
 * **والمنتجُ يعرف بالقفلِ ويعرضه:** `SecurityPosture` يعدّ الحساباتِ المقفولة،
 * و`SecurityExposure` يضع لكلِّ حسابٍ `'locked' => true`. فيقيس ويحكم ويعرض —
 * **ولا يملك فعلاً**. وهذا نسيجُ ما رصدته الخاتمةُ في المجدولةِ (X4) ووسمِ
 * التأخّر (X6): تشخيصٌ دقيقٌ بلا دواء.
 *
 * **والمبدأُ ليس مستورَداً — المستودعُ كتبه بنفسه** في شقيقةِ هذا الباب
 * (`UserController::twofaOff`): «ميزةُ أمانٍ بلا بابِ استرداد ليست أماناً بل
 * فخّاً — ونهايتُها أن يُطفئها الناسُ كلُّهم». وقد بُني بابُ استردادٍ للتحقّقِ
 * بخطوتين ولم يُبنَ لقفلِ التخمين.
 *
 * والبابُ الجديدُ محروسٌ بما حُرست به شقيقتُه: رايةُ إدارةِ المستخدمين، وامتيازٌ
 * يعلو الحسابَ الهدف، وتأكيدُ هويّةٍ — لأنّ من يفكّ القفلَ مراراً يُبطل حارسَ
 * التخمينِ نفسَه — وأثرُ تدقيقٍ باسمِ الفاعلِ والهدف.
 */
class CouncilAccountUnlockTest extends TestCase
{
    protected function userAdmin(): User
    {
        $role = Role::create(['name' => 'إداري مستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => []]);

        return User::create(['name' => 'إداري', 'email' => 'ua-unlock@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
    }

    protected function lockedEmployee(): User
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);

        return User::create(['name' => 'أحمد المقفول', 'email' => 'locked@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'locked_until' => now()->addHours(3)]);
    }

    protected function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    public function test_an_admin_can_release_a_locked_account_from_inside_the_product(): void
    {
        $this->seedCore();
        $admin = $this->userAdmin();
        $locked = $this->lockedEmployee();

        $this->assertNotNull($locked->locked_until, 'التهيئة: الحسابُ مقفولٌ فعلاً');

        $this->stepped()->actingAs($admin)
            ->post('/admin/users/' . $locked->id . '/unlock')
            ->assertRedirect();

        $this->assertNull($locked->fresh()->locked_until,
            'لا سبيلَ لفكِّ قفلِ التخمينِ من داخل المنتج — والمالكُ ينتظر ساعاتٍ '
            . 'أو يُحرّر القاعدةَ بيده. وميزةُ أمانٍ بلا بابِ استردادٍ فخٌّ لا أمان.');
    }

    public function test_releasing_a_lock_is_written_to_the_audit_trail(): void
    {
        $this->seedCore();
        $admin = $this->userAdmin();
        $locked = $this->lockedEmployee();

        $this->stepped()->actingAs($admin)->post('/admin/users/' . $locked->id . '/unlock');

        $this->assertDatabaseHas('audits', [
            'module' => 'users', 'record_id' => $locked->id,
        ]);
        $row = \Illuminate\Support\Facades\DB::table('audits')
            ->where('record_id', $locked->id)->orderByDesc('id')->first();
        $this->assertStringContainsString('قفل', (string) ($row->action ?? ''),
            'فكُّ القفلِ فعلٌ أمنيٌّ — يُكتب باسمِ فاعلِه أو يصير باباً خلفيّاً صامتاً');
    }

    public function test_a_plain_employee_cannot_release_a_lock(): void
    {
        $this->seedCore();
        $locked = $this->lockedEmployee();

        $plainRole = Role::create(['name' => 'بلا راية', 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $plain = User::create(['name' => 'موظّفٌ عاديّ', 'email' => 'plain-unlock@test.local',
            'password' => 'Secret!2026x', 'role_id' => $plainRole->id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->stepped()->actingAs($plain)
            ->post('/admin/users/' . $locked->id . '/unlock')
            ->assertForbidden();

        $this->assertNotNull($locked->fresh()->locked_until,
            'موظّفٌ بلا رايةٍ فكَّ قفلَ حسابٍ — وهذا يُبطل حارسَ التخمينِ للجميع');
    }
}
