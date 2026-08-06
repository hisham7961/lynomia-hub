<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١: **النسخةُ الصفريّة تُبلَّغ نجاحاً ثم تدهس السليمة.**
 *
 * `json_encode` تعيد `false` على بايتةٍ واحدة فاسدة في أي صفّ (`audits.device`
 * كانت تُكتب بـ`substr` بالبايتات فتقطع الحرف العربي نصفين). العائد لم يكن
 * يُفحَص، فـ`file_put_contents($file, false)` تكتب **صفر بايت**، ثم يُشغَّل
 * التدوير غير المشروط فيحذف آخر النسخ **السليمة**، ثم يعود الأمر `SUCCESS`
 * ويسجّل `heartbeat.backup` — والمشغّل يقرأ «✓» ويطمئن. أي أن قدرة التعافي
 * كلها تُعطَّل بصمت، وهو أخطر ما في الفحص كله: لا رجعة بعده.
 *
 * الضمانان المحروسان هنا:
 *   ١) لا نسخةَ صفريّة تُبلَّغ نجاحاً — الترميز يُفحَص والبايتة الفاسدة تُستبدل.
 *   ٢) لا تدويرَ قبل كتابةٍ متحقَّقٍ منها — الكتابةُ الفاشلة لا تحذف شيئاً.
 */
class BackupIntegrityGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    /**
     * صفٌّ بترميزٍ فاسد في القاعدة — بايتة `\xD8` يتيمة (نصفُ حرفٍ عربي).
     * تُعيد false على محرّكٍ يرفض البايتة عند الكتابة (MySQL الصارمة، خطأ 1366):
     * هناك القاعدةُ نفسها هي الحارس، والسيناريو لا يقع أصلاً.
     */
    private function seedCorruptAuditRow(): bool
    {
        $id = DB::table('audits')->insertGetId([
            'user_id' => $this->owner->id, 'action' => 'عرض',
            'module' => 'clients', 'ip' => '127.0.0.1', 'created_at' => now(),
        ]);
        // الكتابة عبر الروابط يرفضها PDO — تُحقن البايتة عبر تعبير القاعدة نفسه
        $expr = DB::getDriverName() === 'sqlite' ? "CAST(x'D8' AS TEXT)" : "UNHEX('D8')";
        try {
            DB::statement("UPDATE audits SET device = $expr WHERE id = ?", [$id]);
        } catch (\Illuminate\Database\QueryException) {
            return false;   // المحرّك الصارم ردّها — وهو المطلوب
        }

        return true;
    }

    /** العيب الأصل: بايتةٌ فاسدة كانت تُنتج نسخةً بصفر بايت يُبلَّغ عنها نجاحاً */
    public function test_corrupt_row_never_yields_a_zero_byte_backup_reported_as_success(): void
    {
        $this->seedCore();

        // على المحرّك المتساهل يُزرع الفساد ويُفحَص أثره؛ وعلى الصارم يُفحص أن
        // القاعدة نفسها ردّته — فالضمانُ محروسٌ على المحرّكين لا على أحدهما.
        $seeded = $this->seedCorruptAuditRow();
        if (! $seeded) {
            $this->assertSame('', (string) DB::table('audits')->orderByDesc('id')->value('device'),
                'المحرّك الصارم ردّ البايتة الفاسدة فلم تُكتب — وهو الحارس على مستوى القاعدة');
        }

        $code = $this->artisan('hub:backup', ['--keep' => 5])->run();

        $files = glob(storage_path('app/backups') . '/hub-*.json');
        foreach ($files as $f) {
            $this->assertNotSame(0, filesize($f),
                'نسخةٌ بصفر بايت على القرص — والأمر أبلغ نجاحاً: ' . basename($f));
            $this->assertIsArray(json_decode(file_get_contents($f), true),
                'نسخةٌ غير قابلة للقراءة — الاستعادة منها مستحيلة: ' . basename($f));
        }

        if ($code === 0) {
            $this->assertNotEmpty($files, 'نجاحٌ بلا ملفٍ مكتوب — «✓» كاذبة');
        }
    }

    /** الكتابة الفاشلة لا تُدوّر: آخر نسخةٍ سليمة تبقى على القرص */
    public function test_failed_write_does_not_rotate_away_good_backups(): void
    {
        $this->seedCore();

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);
        $good = $dir . '/hub-2020-01-01-0000.json';
        file_put_contents($good, '{"_meta":{"app":"نسخةٌ سليمة"}}');

        // يُعطَّل مسارُ الكتابة: الملف المؤقّت مشغولٌ بمجلَّد فتتعذّر الكتابة إليه
        $blocked = $dir . '/hub-' . now()->format('Y-m-d-Hi') . '.json.tmp';
        File::ensureDirectoryExists($blocked);

        $code = $this->artisan('hub:backup', ['--keep' => 1])->run();

        $this->assertNotSame(0, $code, 'الكتابةُ المتعذّرة تُبلَّغ فشلاً لا نجاحاً');
        $this->assertFileExists($good,
            'التدوير شُغِّل رغم فشل الكتابة — فحُذفت آخر نسخةٍ سليمة');
    }

    /** المسار السليم يبقى سليماً: نسخةٌ غير فارغة وJSON صالح */
    public function test_healthy_backup_still_written_and_valid(): void
    {
        $this->seedCore();

        $this->artisan('hub:backup', ['--keep' => 3])->assertExitCode(0);

        $files = glob(storage_path('app/backups') . '/hub-*.json');
        $this->assertNotEmpty($files, 'النسخة السليمة تُكتب');
        $json = json_decode(file_get_contents($files[0]), true);
        $this->assertIsArray($json, 'النسخة المكتوبة JSON صالح');
        $this->assertArrayHasKey('_meta', $json);
        $this->assertNotEmpty($json['users'] ?? [], 'المستخدمون داخل النسخة');
        // لا يبقى ملفٌ مؤقّتٌ خلفَ نجاحٍ
        $this->assertSame([], glob(storage_path("app/backups") . "/*.tmp") ?: []);
    }
}
