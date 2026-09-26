<?php

namespace Tests\Feature;

use App\Console\Commands\HubBackup;
use App\Support\Ops\BackupStream;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **النسخةُ تتدفّق ولا تتكدّس** (TECH_DEBT #7 · F-13).
 *
 * كان `hub:backup` يبني المنشأةَ كلَّها مصفوفةً ثم سلسلةً ثم (مشفّرةً) سلسلةً ثالثة —
 * فذروةُ ذاكرته أضعافُ حجم القاعدة. الآن تُكتب جدولاً جدولاً وقطعةً قطعة.
 * وهذه الحزمةُ تُثبت أنّ التدفّق لم يمسّ الصيغة:
 *
 *   ١) الملفُّ الصريح **هو ترميزُ `json_encode` القانونيُّ لمحتواه بايتاً ببايت** —
 *      أي ما كان يكتبه الترميزُ الدفعيُّ لنفس البيانات — والمفاتيحُ بترتيبها.
 *   ٢) صفوفٌ تعبر حدودَ القطع (أكثر من ٥٠٠) تُعدّ وتُتحقَّق وتُستعاد **كلُّها**.
 *   ٣) المشفَّرُ المتدفّق **هو حمولةُ `Crypt::encryptString`** — يفكّها `Crypt` نفسُه
 *      على كلِّ حدٍّ حرج (صفر، أقلّ من قطعة، قطعةٌ تماماً، قطعٌ عدّة بأطوالٍ شاذّة).
 *   ٤) ذروةُ الذاكرة لا تتبع حجمَ النسخة.
 */
class BackupStreamingTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    /** زرعُ مهامّ بأعدادٍ تعبر حدودَ قطع `chunkById` (٥٠٠) — بمعرّفاتٍ وعناوينَ معروفة */
    private function seedTasks(int $n, string $pad = ''): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'title' => 'مهمةٌ متدفّقة ' . $i, 'status' => 'جديدة',
                       'description' => $pad !== '' ? $pad . $i : null,
                       'created_at' => '2025-03-01 10:00:00', 'updated_at' => '2025-03-01 10:00:00'];
            if (count($rows) === 200) { DB::table('tasks')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('tasks')->insert($rows);
    }

    private function seedJournal(int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'entry_id' => (string) Str::uuid(),
                       'debit' => 1, 'credit' => 0, 'memo' => 'قيدٌ متدفّق ' . $i,
                       'created_at' => '2025-03-01 10:00:00', 'updated_at' => '2025-03-01 10:00:00'];
            if (count($rows) === 200) { DB::table('journal_lines')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('journal_lines')->insert($rows);
    }

    private function onlyBackup(string $glob): string
    {
        $files = glob(storage_path('app/backups') . '/' . $glob) ?: [];
        $this->assertCount(1, $files, 'نسخةٌ واحدةٌ متوقّعة');

        return $files[0];
    }

    /** (١)+(٢) صريحة: قانونيّةٌ بايتاً ببايت، وبترتيب مفاتيحها، وكلُّ الصفوف تعود */
    public function test_plain_backup_is_canonical_json_and_restores_every_row_across_chunks(): void
    {
        $this->seedCore();
        $this->seedTasks(1234);
        $this->seedJournal(1100);

        $this->artisan('hub:backup', ['--verify' => true, '--keep' => 3])->assertExitCode(0);
        $file = $this->onlyBackup('hub-*.json');
        $raw = (string) file_get_contents($file);
        $db = json_decode($raw, true);
        $this->assertIsArray($db, 'النسخةُ المتدفّقة JSON غيرُ صالح');

        // الملفُّ هو الترميزُ القانونيُّ لمحتواه — ما كان الترميزُ الدفعيُّ يكتبه تماماً
        $this->assertSame(json_encode($db, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $raw,
            'المجرى لا يطابق ترميزَ المصفوفة كلِّها بايتاً ببايت');

        // ترتيبُ المفاتيح: الغلافُ أولاً، ثم الوحداتُ، ثم الخام، ثم الإعدادات أخيراً
        $keys = array_keys($db);
        $this->assertSame(['_meta', 'roles', 'users'], array_slice($keys, 0, 3));
        $this->assertSame('settings', end($keys));
        $this->assertSame('_tables', $keys[count($keys) - 2]);

        // كلُّ صفٍّ حاضرٌ مرّةً واحدة — لا سقوطَ ولا تكرارَ عند حدود القطع
        $taskIds = array_column($db['tasks'], 'id');
        $this->assertCount(1234, $taskIds);
        $this->assertCount(1234, array_unique($taskIds));
        $this->assertCount(1100, $db['_tables']['journal_lines']);

        $wantTasks = DB::table('tasks')->whereNull('deleted_at')->orderBy('id')->pluck('title', 'id')->all();
        $wantJournal = DB::table('journal_lines')->orderBy('id')->pluck('memo', 'id')->all();

        DB::table('tasks')->delete();
        DB::table('journal_lines')->delete();
        $this->artisan('hub:import', ['file' => $file, '--truncate' => true])->assertExitCode(0);

        $gotTasks = DB::table('tasks')->whereNull('deleted_at')->orderBy('id')->pluck('title', 'id')->all();
        $gotJournal = DB::table('journal_lines')->orderBy('id')->pluck('memo', 'id')->all();
        $this->assertSame(count($wantTasks), count($gotTasks));
        foreach ($wantTasks as $id => $title) $this->assertSame($title, $gotTasks[$id] ?? null, "المهمة {$id} لم تُستعد كما كانت");
        $this->assertSame(count($wantJournal), count($gotJournal));
        foreach ($wantJournal as $id => $memo) $this->assertSame($memo, $gotJournal[$id] ?? null, "القيد {$id} لم يُستعد كما كان");
    }

    /** (٣) المشفَّرُ المتدفّق حمولةُ `Crypt` نفسُها — على كلِّ حدٍّ حرج */
    public function test_streamed_ciphertext_is_exactly_what_crypt_decrypts(): void
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);
        $chunk = 48 * 1024;
        $cases = [
            'فارغ' => [''],
            'أقلّ من قطعة' => ['{"a":"عربيٌّ"}'],
            'قطعةٌ تماماً' => [str_repeat('x', $chunk)],
            'قطعةٌ وبايت' => [str_repeat('y', $chunk), 'z'],
            'قطعٌ بأطوالٍ شاذّة' => array_map(fn ($i) => str_repeat(chr(65 + $i % 26), 7919 * ($i % 5 + 1)) . 'ـ', range(1, 40)),
        ];

        foreach ($cases as $label => $parts) {
            $path = $dir . '/probe.enc';
            $s = BackupStream::open($path, true);
            $this->assertNotNull($s);
            foreach ($parts as $p) $this->assertTrue($s->write($p));
            $this->assertTrue($s->close(), $label);

            $raw = (string) file_get_contents($path);
            $this->assertSame(implode('', $parts), Crypt::decryptString($raw), "حالة «{$label}»: Crypt لا يفكّ المجرى");
            $this->assertSame(strlen($raw), $s->bytes());
            @unlink($path);
        }
    }

    /** (٢)+(٣) نسخةٌ مشفّرةٌ كبيرة: تتحقّق وتُستعاد، والنصُّ الصريح لا يلمس القرص */
    public function test_encrypted_backup_with_many_rows_verifies_and_restores(): void
    {
        $this->seedCore();
        $this->seedTasks(1234);
        $this->hubSetting('backup.encrypt', '1');

        $this->artisan('hub:backup', ['--verify' => true, '--keep' => 3])->assertExitCode(0);
        $file = $this->onlyBackup('hub-*.json.enc');
        $this->assertSame([], glob(storage_path('app/backups') . '/hub-*.json') ?: [], 'نسخةٌ صريحةٌ بجوار المشفّرة');
        $this->assertSame([], glob(storage_path('app/backups') . '/*.tmp') ?: []);
        $this->assertStringNotContainsString('مهمةٌ متدفّقة', (string) file_get_contents($file));

        $db = HubBackup::readFile($file);
        $this->assertIsArray($db);
        $this->assertCount(1234, $db['tasks']);

        DB::table('tasks')->delete();
        $this->artisan('hub:import', ['file' => $file, '--truncate' => true])->assertExitCode(0);
        $this->assertSame(1234, DB::table('tasks')->whereNull('deleted_at')->count());
    }

    /** قياسُ ذروة الذاكرة لتشغيلِ نسخةٍ واحدة، وحجمِ ملفّها */
    private function measureBackup(): array
    {
        File::deleteDirectory(storage_path('app/backups'));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $this->artisan('hub:backup', ['--keep' => 3])->assertExitCode(0);
        $peak = memory_get_peak_usage() - $base;

        return [$peak, filesize($this->onlyBackup('hub-*.json'))];
    }

    /**
     * (٤) الذروةُ لا تتبع حجمَ النسخة. يُقاس الفرقُ بين نسختين لا الذروةُ المطلقة
     * (فهي تتبع حجمَ القطعة والإطارَ لا البيانات): الدفعيُّ كان يحمل المصفوفةَ
     * والسلسلةَ معاً فتزيد ذروتُه **ضعفَي** ما زاد الملفّ على الأقل؛ والمتدفّقُ لا يزيد.
     */
    public function test_peak_memory_does_not_scale_with_backup_size(): void
    {
        $this->seedCore();
        $pad = str_repeat('و', 1000);   // ≈ ٢ كيلوبايت لكلِّ مهمّة

        $this->seedTasks(1000, $pad);
        [$p1, $s1] = $this->measureBackup();

        $this->seedTasks(5000, $pad);
        [$p2, $s2] = $this->measureBackup();

        $grew = $s2 - $s1;
        $this->assertGreaterThan(8 * 1024 * 1024, $grew, 'الفرقُ أصغرُ من أن يقيس شيئاً');
        $this->assertLessThan(intdiv($grew, 4), $p2 - $p1,
            'زاد الملفّ ' . round($grew / 1048576, 1) . 'MB فزادت الذروة '
            . round(($p2 - $p1) / 1048576, 1) . 'MB — النسخةُ تتكدّس في الذاكرة ولا تتدفّق');
    }

    /** الكتابةُ المتعذّرة لا تترك نصفَ نسخة — ويُبلَّغ فشلاً */
    public function test_unwritable_temp_path_fails_without_leaving_a_partial_file(): void
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0700);
        $this->assertNull(BackupStream::open($dir . '/لا-مجلّد/x.tmp', false));

        $s = BackupStream::open($dir . '/half.tmp', false);
        $s->write('{"_meta":');
        $s->abort();
        $this->assertFileDoesNotExist($dir . '/half.tmp');
    }
}
