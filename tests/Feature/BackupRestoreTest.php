<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * النسخ والاستعادة — أخطر عيوب الموجة الثانية:
 * (١) **الاستعادة كانت معطّلة كلياً على أي نسخة حقيقية**: النسخة تُسقط الحقول
 *     الفارغة من كل صف على حدة، وupsert يبني قائمة الأعمدة من أول صفٍّ وحده —
 *     فصفّان مختلفا الحقول = «Column count doesn't match» أو انزلاق قيم؛
 * (٢) الاستعادة تدوس تواريخ الإنشاء بـnow() ولا تُحيي المحذوف ناعماً؛
 * (٣) ملف تالف يمرّ «بنجاح: 0 سجل»؛
 * (٤) النسخة «الكاملة» تُسقط قيود اليومية وبنود الرواتب والمرفقات وسلسلة
 *     أدلة التواقيع والنسخ التاريخية؛
 * (٥) خبيئة الإعدادات لا تُنسف بعد الاستعادة؛
 * (٦) chunk على created_at بلا فاصل + ملف النسخة مقروء لكل حسابات الخادم.
 */
class BackupRestoreTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/hub-*.json')) ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    protected function latestBackup(): string
    {
        $files = glob(storage_path('app/backups/hub-*.json'));
        $this->assertNotEmpty($files, 'لم يُكتب ملف نسخة أصلاً');
        sort($files);

        return end($files);
    }

    /** (١) صفوف غير متجانسة الحقول تستعاد كاملةً — كان الانهيار الكلي */
    public function test_restore_survives_heterogeneous_rows(): void
    {
        $this->seedCore();
        // صف غني الحقول وصف فقيرها — الوضع الطبيعي لأي بيانات حقيقية
        Task::create(['title' => 'مهمة غنية', 'status' => 'قيد التنفيذ', 'priority' => 'عاجلة',
            'description' => 'وصف طويل', 'due' => now()->addDays(3)->toDateString(), 'est_h' => 8]);
        Task::create(['title' => 'مهمة فقيرة', 'status' => 'جديدة']);

        $this->artisan('hub:backup')->assertExitCode(0);
        DB::table('tasks')->delete();

        $this->artisan('hub:import', ['file' => $this->latestBackup(), '--truncate' => true])
            ->assertExitCode(0);

        $this->assertSame(2, Task::count(), 'الاستعادة أسقطت صفوفاً');
        $rich = Task::where('title', 'مهمة غنية')->first();
        $this->assertSame('عاجلة', $rich->priority, 'انزلقت القيم بين الأعمدة');
        $this->assertSame('وصف طويل', $rich->description);
        $this->assertSame('جديدة', Task::where('title', 'مهمة فقيرة')->value('status'));
    }

    /** (٢) تواريخ الإنشاء تبقى، والمحذوف بعد النسخة يعود حياً */
    public function test_restore_preserves_dates_and_revives_soft_deleted(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $id, 'title' => 'المهمة الأصل', 'status' => 'جديدة',
            'created_at' => '2025-01-15 10:00:00', 'updated_at' => '2025-01-15 10:00:00',
            'created_by' => $this->owner->id]);

        $this->artisan('hub:backup')->assertExitCode(0);

        // بعد النسخة: حُذفت خطأً وعُدّل عنوانها — سيناريو «استرجع نسخة الأمس»
        DB::table('tasks')->where('id', $id)->update(['title' => 'دُهست', 'deleted_at' => now()]);

        $this->artisan('hub:import', ['file' => $this->latestBackup()])->assertExitCode(0);

        $row = DB::table('tasks')->where('id', $id)->first();
        $this->assertSame('المهمة الأصل', $row->title);
        $this->assertNull($row->deleted_at,
            'استعادةُ نسخةٍ كان السجل فيها حياً لم تُحيه — أشيعُ دوافع الاستعادة لا يعمل');
        $this->assertStringStartsWith('2025-01-15', (string) $row->created_at,
            'الاستعادة داست تاريخ الإنشاء الحقيقي بـnow()');
        $this->assertSame($this->owner->id, $row->created_by, 'نسبة المنشئ ضاعت في الاستعادة');
    }

    /** (٣) ملف تالف يُرفض برسالة لا «بنجاح: 0 سجل» */
    public function test_corrupt_backup_file_is_rejected(): void
    {
        $this->seedCore();
        $bad = storage_path('app/backups/hub-9999-corrupt.json');
        @mkdir(dirname($bad), 0700, true);
        file_put_contents($bad, '{"roles": [{"id": "x"');   // نسخة مبتورة النقل

        $this->artisan('hub:import', ['file' => $bad])->assertExitCode(1);
    }

    /** (٤) الجداول التشغيلية خارج سجل الوحدات تدخل النسخة وتعود بالاستعادة */
    public function test_backup_covers_operational_tables(): void
    {
        $this->seedCore();
        $jid = (string) Str::uuid();
        DB::table('journal_lines')->insert(['id' => $jid, 'entry_id' => (string) Str::uuid(),
            'debit' => 100, 'credit' => 0, 'memo' => 'قيد تجريبي',
            'created_at' => now(), 'updated_at' => now()]);
        $aid = (string) Str::uuid();
        DB::table('attachments')->insert(['id' => $aid, 'module' => 'tasks',
            'record_id' => (string) Str::uuid(), 'path' => 'hub/x.pdf', 'disk' => 'local',
            'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 10,
            'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('hub:backup')->assertExitCode(0);
        $data = json_decode(file_get_contents($this->latestBackup()), true);
        $this->assertNotEmpty($data['_tables']['journal_lines'] ?? null,
            'قيود اليومية المزدوجة خارج النسخة «الكاملة» — لا تُعاد بنقر المستخدمين');
        $this->assertNotEmpty($data['_tables']['attachments'] ?? null,
            'بيانات المرفقات خارج النسخة');

        // وتعود فعلاً
        DB::table('journal_lines')->delete();
        $this->artisan('hub:import', ['file' => $this->latestBackup()])->assertExitCode(0);
        $this->assertSame('قيد تجريبي', DB::table('journal_lines')->where('id', $jid)->value('memo'));
    }

    /**
     * (٧ · Work OS · الطور M · WP-M.3 · §74–81) **جولةُ Work OS الكاملة**: صفٌّ
     * في كلِّ جدولٍ أرسته الأطوار A–L يدخل النسخةَ ويعود بالاستعادة — امتدادُ (٤)
     * من عيّنتين إلى **كلِّ** سكّةِ Work OS: عضويّاتُ العملاء والمحادثاتُ والتفعيلُ
     * ودفترُ العهدة والمحطاتُ والجردُ وقواعدُ IP وأسطولُ النقاط بمفاتيحه ورموزِه
     * وذاكرةِ الإعادة وأحداثِه وأوامرِه وسياساتِه وإصداراتِه والمزوّدون. غيابُ
     * جدولٍ واحد = استعادةٌ «ناجحة» تُطفئ عزلاً أو تُصمِت أسطولاً بصمت.
     */
    public function test_every_work_os_table_round_trips_through_backup_and_restore(): void
    {
        $this->seedCore();
        $mk = fn () => (string) Str::uuid();

        // جدولٌ ⟵ [الصفُّ المبذور، عمودُ العلامة] — علامةٌ مميِّزةٌ تُتعقَّب حرفيّاً
        $seed = [
            'client_memberships'     => [['client_id' => $mk(), 'user_id' => $this->employee->id,
                                          'role' => 'member', 'status' => 'active'], 'status'],
            'conversations'          => [['kind' => 'channel', 'audience' => 'internal',
                                          'visibility' => 'private', 'title' => 'قناةُ جولة النسخ'], 'title'],
            'conversation_members'   => [['conversation_id' => $mk(), 'user_id' => $this->employee->id,
                                          'role' => 'member', 'source' => 'explicit'], 'role'],
            'account_activations'    => [['user_id' => $this->employee->id, 'email' => 'roundtrip@act.local',
                                          'token_hash' => hash('sha256', 'rt-token'), 'attempts' => 0], 'email'],
            'employee_custody_moves' => [['employee_id' => $mk(), 'kind' => 'صرف عهدة',
                                          'amount' => 10, 'sign' => 1, 'approval_state' => 'posted'], 'kind'],
            'stations'               => [['code' => 'ST-RT-77', 'facility' => 'مقرُّ الجولة',
                                          'status' => 'شاغرة'], 'code'],
            'station_assignments'    => [['station_id' => $mk(), 'user_id' => $this->employee->id,
                                          'action' => 'assign', 'note' => 'إسنادُ الجولة'], 'note'],
            'inventory_sessions'     => [['status' => 'مفتوحة', 'by_id' => $this->owner->id], 'status'],
            'inventory_items'        => [['session_id' => $mk(), 'asset_id' => $mk(),
                                          'verdict' => 'معلّق'], 'verdict'],
            'inventory_scans'        => [['session_id' => $mk(), 'result' => 'مطابق',
                                          'by_id' => $this->owner->id], 'result'],
            'ip_rules'               => [['ip' => '203.0.113.77', 'mode' => 'block',
                                          'origin' => 'manual', 'reason' => 'حظرُ الجولة'], 'ip'],
            'endpoint_devices'       => [['device_uuid' => $mk(), 'hostname' => 'RT-LAPTOP-01',
                                          'os' => 'windows', 'status' => 'active',
                                          'public_key' => 'PUB-KEY-RT', 'pubkey_fp' => 'fp-rt-1'], 'pubkey_fp'],
            'enrollment_tokens'      => [['token_hash' => hash('sha256', 'rt-enroll'), 'company_id' => $mk(),
                                          'expires_at' => now()->addDay(), 'minted_by' => $this->owner->id], 'token_hash'],
            'endpoint_nonces'        => [['id' => 424242, 'device_id' => $mk(), 'nonce' => 'nonce-rt-1'], 'nonce'],
            'endpoint_events'        => [['device_id' => $mk(), 'kind' => 'usb.plug', 'severity' => 'info',
                                          'summary' => 'حدثُ جولة النسخ', 'nonce' => 'nonce-rt-2'], 'summary'],
            'endpoint_commands'      => [['device_id' => $mk(), 'type' => 'lock', 'state' => 'pending',
                                          'ikey' => 'ikey-rt-1', 'reason' => 'سببُ الجولة'], 'ikey'],
            'endpoint_policies'      => [['name' => 'سياسةُ الجولة', 'usb_mode' => 'audit'], 'name'],
            'endpoint_releases'      => [['version' => '9.9.9-rt', 'os' => 'windows', 'arch' => 'amd64',
                                          'path' => 'agents/rt.exe', 'sha256' => str_repeat('ab', 32),
                                          'size' => 10, 'signing_status' => 'unsigned-dev'], 'sha256'],
            'carriers'               => [['name' => 'مزوّدُ الجولة'], 'name'],
        ];

        $ids = [];
        foreach ($seed as $t => [$row, $marker]) {
            $ids[$t] = $row['id'] ?? $mk();
            // الطوابعُ تُقصَر على أعمدة الجدول الفعليّة (endpoint_nonces بلا updated_at مثلاً)
            $full = $row + ['id' => $ids[$t], 'created_at' => now(), 'updated_at' => now()];
            DB::table($t)->insert(array_intersect_key($full,
                array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($t))));
        }

        $this->artisan('hub:backup')->assertExitCode(0);

        // كلُّ جدولٍ حاضرٌ في النسخة بصفِّه المبذور — لا تغطيةَ بالاسم وحده
        $dump = json_decode(file_get_contents($this->latestBackup()), true);
        foreach ($seed as $t => [$row, $marker]) {
            $rows = collect($dump['_tables'][$t] ?? []);
            $this->assertTrue($rows->contains('id', $ids[$t]),
                "جدولُ Work OS «{$t}» غائبٌ عن النسخة (أو صفُّه المبذور لم يُصدَّر) — WP-M.3");
        }

        // الكارثة ثم الاستعادة: تُمحى الجداول كلُّها ويُستورَد الملفُ نفسُه
        foreach (array_keys($seed) as $t) DB::table($t)->delete();
        $this->artisan('hub:import', ['file' => $this->latestBackup()])->assertExitCode(0);

        // كلُّ صفٍّ عاد بعلامته حرفيّاً — حضورٌ وقيمةٌ لا حضورٌ فحسب
        foreach ($seed as $t => [$row, $marker]) {
            $back = DB::table($t)->where('id', $ids[$t])->first();
            $this->assertNotNull($back, "صفُّ «{$t}» لم يعُد بالاستعادة — الجولةُ مبتورة");
            $this->assertSame((string) $row[$marker], (string) $back->{$marker},
                "علامةُ «{$t}.{$marker}» انحرفت في الجولة — قيمةٌ ضاعت أو انزلقت");
        }
    }

    /** (٥) الإعدادات المستعادة تسري فوراً لا بعد انتهاء الخبيئة */
    public function test_restore_busts_the_settings_cache(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'الاسم القديم');

        $this->artisan('hub:backup')->assertExitCode(0);
        $file = $this->latestBackup();
        $data = json_decode(file_get_contents($file), true);
        $data['settings']['app.name'] = 'الاسم المستعاد';
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

        // التسخين بعد النسخ (النسخ نفسه ينسف الخبيئة) — فتُقاس الاستعادة وحدها
        $this->assertSame('الاسم القديم', setting('app.name'));

        $this->artisan('hub:import', ['file' => $file])->assertExitCode(0);
        $this->assertSame('الاسم المستعاد', setting('app.name'),
            'القاعدة تحمل الإعداد الجديد والنظام يعمل بالقديم من الخبيئة');
    }

    /** (٦) ملف النسخة لا يقرؤه غير مالكه + التقسيم بمعرّف لا بطابع زمني */
    public function test_backup_files_are_private_and_chunking_deterministic(): void
    {
        $this->seedCore();
        $this->artisan('hub:backup')->assertExitCode(0);
        $file = $this->latestBackup();

        $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3),
            'ملف النسخة (القاعدة كلها بهواتف المستخدمين وأسرار الخزنة) مقروء لكل حسابات الخادم');

        $src = file_get_contents(app_path('Console/Commands/HubBackup.php'));
        $this->assertStringContainsString('chunkById', $src,
            'chunk على created_at بلا فاصل — صفوف تفلت من النسخة عند تساوي الطوابع');
    }
}
