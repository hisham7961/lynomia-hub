<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة (الدفعة الخامسة والأخيرة من الأسطول) — **بابٌ يُقفل على
 * صاحبه، ورابطٌ يتجاهل صفَّه، وجدولٌ ينمو بلا سقف.**
 *
 *  ١) **قفلٌ دائمٌ بلا مخرج**: `users.recovery_codes` عمودٌ قائمٌ ومُكاستٌ
 *     ومحجوبٌ عن التدقيق — **ولا يُكتب ولا يُقرأ في المستودع كلِّه**. والمسارُ
 *     الوحيد لإطفاء التحقّق بخطوتين (`ProfileController`) يشترط **رمزاً
 *     صحيحاً من الجهاز المفقود نفسِه**. فمن ضاع هاتفُه مقفولٌ خارج النظام
 *     إلى الأبد، ولا مالكَ ولا مديرَ مستخدمين يستطيع فكَّه. ميزةُ أمانٍ بلا
 *     بابِ استرداد ليست أماناً بل فخّ.
 *  ٢) أزرارُ الصفوف في الشاشة القانونية («قرار» و«فتح») تتجاهل صفَّها وتنقل
 *     إلى قائمة التوقيع العامة — فالمستخدم يضغط «قرار» على موافقةٍ بعينها
 *     ويصل إلى قائمةٍ يبحث فيها عنها من جديد.
 *  ٣) `metric_points` ينمو بلا سقفٍ من فحص التوافر كل خمس دقائق، بينما
 *     تُقلَّم أربعةُ جداولٍ شقيقة — والنظامُ يُرفع على استضافةٍ مشتركة.
 */
class LockoutAndDeadEndsRound7Test extends TestCase
{
    protected function manager(): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'مدير مستخدمين', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'مدير', 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ── ١) بابُ استردادٍ للمقفول خارج حسابه ── */

    public function test_a_user_manager_can_release_an_account_locked_out_of_2fa(): void
    {
        $this->seedCore();
        $mgr = $this->manager();

        $locked = User::create(['name' => 'مقفول', 'email' => 'locked@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $locked->forceFill(['totp_enabled' => true, 'totp_secret_cipher' => 'SECRETBASE32'])->save();

        // إطفاءُ تحقّق غيرِك صار يتطلب تصعيدَ مصادقة (v2.368) — يؤكّد المدير هويته أولاً
        $this->actingAs($mgr)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/users']);
        $this->actingAs($mgr)->post("/admin/users/{$locked->id}/twofa-off")->assertRedirect();

        $fresh = $locked->fresh();
        $this->assertFalse((bool) $fresh->totp_enabled,
            'لا سبيلَ لفكِّ قفلِ التحقّق بخطوتين عمّن ضاع جهازُه: المسارُ الوحيد '
            . 'يشترط رمزاً من الجهاز المفقود نفسِه، و`recovery_codes` عمودٌ ميت. '
            . 'ميزةُ أمانٍ بلا بابِ استرداد ليست أماناً بل فخّ');
        $this->assertNull($fresh->totp_secret_cipher, 'بقي السرُّ بعد الإطفاء');
        $this->assertDatabaseHas('audits', ['action' => 'إطفاء التحقق بخطوتين']);
    }

    /** والبابُ محروسٌ: لا يُفتح إلا لمن يملك إدارةَ المستخدمين */
    public function test_releasing_2fa_needs_the_user_management_flag(): void
    {
        $this->seedCore();

        $victim = User::create(['name' => 'ضحية', 'email' => 'v@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $victim->forceFill(['totp_enabled' => true])->save();

        $this->actingAs($this->employee)->post("/admin/users/{$victim->id}/twofa-off")->assertForbidden();
        $this->assertTrue((bool) $victim->fresh()->totp_enabled);
    }

    /** ولا يُنزع عن حسابٍ يعلو صاحبَ الطلب — وإلا صار بابَ اقتحام */
    public function test_a_manager_cannot_strip_2fa_from_a_more_privileged_account(): void
    {
        $this->seedCore();
        $mgr = $this->manager();
        $this->owner->forceFill(['totp_enabled' => true])->save();

        $this->actingAs($mgr)->post("/admin/users/{$this->owner->id}/twofa-off")->assertForbidden();
        $this->assertTrue((bool) $this->owner->fresh()->totp_enabled,
            'نُزع التحقّقُ بخطوتين عن حسابٍ ذي امتياز: إطفاؤه ثم تصيُّدُ كلمته '
            . 'طريقُ اقتحامٍ كامل — فيلزمه امتيازٌ يعلوه');
    }

    /* ── ٢) أزرارُ الصفوف تعرف صفَّها ── */

    public function test_the_legal_screen_row_buttons_open_their_own_row(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/legal/index.blade.php'));

        $this->assertStringNotContainsString('href="{{ route(\'esign.index\') }}">قرار', $src,
            'زرُّ «قرار» في صفِّ موافقةٍ بعينها ينقل إلى القائمة العامة — '
            . 'فالمستخدم يبحث فيها عمّا كان بين يديه');
        $this->assertStringNotContainsString('href="{{ route(\'esign.index\') }}">فتح', $src,
            'زرُّ «فتح» في صفِّ وثيقةٍ بعينها ينقل إلى القائمة العامة');
    }

    /* ── ٣) جدولُ المقاييس يُقلَّم كأخواته ── */

    public function test_metric_points_are_pruned_like_their_sibling_tables(): void
    {
        $this->seedCore();

        if (! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) {
            $this->markTestSkipped('لا جدولَ مقاييس');
        }

        DB::table('metric_points')->insert([
            ['id' => (string) Str::uuid(), 'module' => 'websites', 'record_id' => (string) Str::uuid(),
             'metric' => 'uptime', 'value' => 1,
             'at' => now()->subDays(500)->toDateTimeString(), 'created_at' => now()->subDays(500)],
            ['id' => (string) Str::uuid(), 'module' => 'websites', 'record_id' => (string) Str::uuid(),
             'metric' => 'uptime', 'value' => 2,
             'at' => now()->toDateTimeString(), 'created_at' => now()],
        ]);

        $this->artisan('hub:automation');

        $this->assertSame(1, DB::table('metric_points')->count(),
            'جدولُ نقاط المقاييس ينمو بلا سقفٍ من فحص التوافر كل خمس دقائق، '
            . 'بينما تُقلَّم أربعةُ جداولٍ شقيقة — والنظامُ يُرفع على استضافةٍ '
            . 'مشتركة بقرصٍ محدود');
    }
}
