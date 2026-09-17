<?php

namespace Tests\Feature\UltimateReview;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **النسخةُ الاحتياطيّةُ التي لا تُستعاد ليست نسخة.**
 *
 * وجدت المراجعةُ الشاملةُ (F-14) أنّ تصديرَ المستخدمين قائمةُ سماحٍ بأحدَ عشرَ
 * مفتاحاً ليس فيها `password` — فالاستعادةُ تُنتج نظاماً كاملَ البياناتِ لا يدخله
 * أحد. ومعها يسقط `account_type` فتنقلب حساباتُ العملاءِ داخليّةً، و`clients`
 * فيسقط عزلُ العملاء، و`company_id` فيسقط عزلُ الشركات — أي أنّ **الكارثةَ
 * تُصلَح بفتحِ النظام**.
 *
 * وأقسى ما في العيب أنّ التعليقَ الواقفَ في منتصفِ المصفوفةِ نفسِها يقول:
 * «عزل الشركات وحارسا الحساب — كلها تسقط عند الاستعادة إن لم تُنسخ». فالدرسُ
 * كان مكتوباً ومُطبَّقاً على ثلاثةِ حقول، ولم يُسأل عن الرابعِ وهو أهمُّها.
 *
 * وهذه الحرّاسُ **دورةٌ كاملة**: تُصدَّر نسخةٌ حقيقيّةٌ، ويُمحى الصفّ، ثمّ
 * تُستعاد — فلا يكفي أن يظهر الحقلُ في الملفّ، بل أن يعود المستخدمُ قادراً
 * على الدخول.
 */
class BackupRestoresAWorkingSystemTest extends TestCase
{
    /** @var string[] نسخٌ حقيقيّةٌ كتبها هذا الاختبار — تُمحى في tearDown */
    protected array $written = [];

    /**
     * **ما يكتبه الاختبارُ في مخزنٍ مشترَكٍ يمحوه الاختبار.**
     * `storage/app/backups` مجلّدٌ حقيقيٌّ تقرؤه شاشةُ التشغيل، ونسخةٌ تُترَك هنا
     * تتصدّر خانةَ «آخر نسخة» فيسقط اختبارٌ آخرُ بلا ذنب. (سقط `AuditRound3Test`
     * فعلاً في أوّلِ تشغيلٍ لهذه الحزمة — وكان تلويثاً منّي لا انحداراً في المنتج.)
     */
    protected function tearDown(): void
    {
        foreach ($this->written as $f) @unlink($f);
        $this->written = [];
        parent::tearDown();
    }

    /** نسخةٌ حقيقيّةٌ تُكتب ثمّ تُقرأ — يعيد مسارَ الملفّ */
    protected function makeBackup(): string
    {
        // **الأحدثُ لا «الجديد»**: اسمُ النسخةِ بطابعٍ زمنيٍّ بدقّةِ الثانية، واختباران
        // في الثانيةِ نفسِها يكتبان الاسمَ نفسَه — فـ«الفرقُ بين قبلَ وبعد» يعود فارغاً
        // والنسخةُ مكتوبةٌ فعلاً. (وقعتُ فيه في أوّلِ تشغيلٍ لهذه الحزمة.)
        $this->artisan('hub:backup')->assertExitCode(0);
        $files = glob(storage_path('app/backups/*.json')) ?: [];
        $this->assertNotEmpty($files, 'لم تُكتب نسخةٌ احتياطيّةٌ أصلاً');
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $this->written[] = $files[0];

        return $files[0];
    }

    public function test_a_restored_user_can_still_log_in(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'موظّفةٌ تعود', 'email' => 'returns@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $file = $this->makeBackup();

        // الكارثة: يختفي الصفّ
        DB::table('users')->where('id', $u->id)->delete();
        $this->assertDatabaseMissing('users', ['id' => $u->id]);

        $this->artisan('hub:import', ['file' => $file])->assertExitCode(0);

        $back = DB::table('users')->where('id', $u->id)->first();
        $this->assertNotNull($back, 'المستخدمُ لم يعُد أصلاً من النسخة');
        $this->assertTrue(Hash::check('Secret!2026x', (string) $back->password),
            'النسخةُ أعادت الحسابَ بلا كلمةِ مرور — نظامٌ كاملُ البيانات لا يدخله أحد (F-14)');
    }

    public function test_restore_preserves_the_client_boundary_and_company_isolation(): void
    {
        $this->seedCore();
        $c = \App\Models\Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $u = User::create(['name' => 'حسابُ عميل', 'email' => 'client@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->viewer->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        DB::table('users')->where('id', $u->id)->update([
            'account_type' => 'client', 'clients' => json_encode(['k1']),
            'company_id' => $c->id, 'companies' => json_encode([$c->id]),
        ]);

        $file = $this->makeBackup();
        DB::table('users')->where('id', $u->id)->delete();
        $this->artisan('hub:import', ['file' => $file])->assertExitCode(0);

        $back = DB::table('users')->where('id', $u->id)->first();
        $this->assertNotNull($back);
        $this->assertSame('client', (string) $back->account_type,
            'الاستعادةُ قلبت حسابَ العميلِ داخليّاً — الكارثةُ تُصلَح بفتحِ النظام (F-14)');
        $this->assertSame(['k1'], json_decode((string) $back->clients, true) ?: [],
            'عزلُ العملاءِ سقط عند الاستعادة');
        $this->assertSame((string) $c->id, (string) $back->company_id,
            'عمودُ الشركةِ المفردُ سقط عند الاستعادة');
    }

    public function test_restore_preserves_two_factor_and_lockout_state(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'بحارسٍ ثنائيّ', 'email' => 'mfa@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        DB::table('users')->where('id', $u->id)->update([
            'totp_enabled' => 1, 'totp_secret_cipher' => 'CIPHERTEXTPLACEHOLDER',
            'must_change_password' => 1,
        ]);

        $file = $this->makeBackup();
        DB::table('users')->where('id', $u->id)->delete();
        $this->artisan('hub:import', ['file' => $file])->assertExitCode(0);

        $back = DB::table('users')->where('id', $u->id)->first();
        $this->assertNotNull($back);
        $this->assertEquals(1, (int) $back->totp_enabled,
            'التحقّقُ الثنائيُّ سقط عند الاستعادة — حسابٌ محميٌّ يعود مكشوفاً (F-14)');
        $this->assertSame('CIPHERTEXTPLACEHOLDER', (string) $back->totp_secret_cipher,
            'سرُّ TOTP سقط فالحسابُ يعود بتحقّقٍ ثنائيٍّ مُعطَّلٍ عمليّاً');
        $this->assertEquals(1, (int) $back->must_change_password,
            'إلزامُ تغييرِ كلمةِ المرورِ سقط عند الاستعادة');
    }
}
