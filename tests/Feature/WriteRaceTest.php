<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Employee;
use App\Models\RecordVersion;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **ما يقع بين خطوتين.**
 *
 * ثلاثةُ عيوبٍ تسكن الفجوةَ بين فعلين متتابعين، ولا يظهر أثرُها إلا حين يفشل
 * الثاني أو يسبقه غيرُه:
 *
 *   ١) **رأسُ سلسلة التدقيق يتقدّم قبل أن يُكتب القيد**. البصمةُ تُحسب ويُقدَّم
 *      الرأس في `creating`، ثم يقع الإدراج. فإن فشل الإدراج — لأي سبب — بقي
 *      الرأس على بصمةٍ **لا يحملها أي سجل**، ويقول الفحص بعدها إلى الأبد:
 *      «⚠️ عبث — حُذفت قيودٌ من الذيل». إنذارُ تلاعبٍ كاذبٌ **دائم**، وهو أسوأ
 *      من لا إنذار: يُدرّب الجميعَ على تجاهل الإشارة الوحيدة التي تهمّ.
 *
 *   ٢) **لقطةُ النسخة: فحصٌ ثم إدراج**. بين `exists()` و`create()` نافذةٌ يسبق
 *      فيها كاتبٌ آخر، فيقع خرقُ قيد التفرّد — و**الحفظُ كلُّه يسقط بخطأ ٥٠٠**
 *      على مستخدمٍ لم يفعل شيئاً خاطئاً.
 *
 *   ٣) **ختمُ الصندوق الموحّد لا يشمل كل ما يقرؤه**: جدول `employees` يُقرأ
 *      ولا يُختم — فتغييرُ ملفٍّ وظيفيّ لا يُبطل خبيئة الصندوق، ويبقى البند
 *      المُنجَز معروضاً حتى تنتهي المهلة.
 */
class WriteRaceTest extends TestCase
{
    /* ── ١) سلسلة التدقيق ── */

    /**
     * إدراجٌ يفشل بعد تقديم الرأس لا يترك السلسلة مكسورة.
     * نُحاكي الفشل بمستمعٍ يرمي بعد الإدراج — وهو ما يقع فعلاً حين يرفض العمود
     * قيمةً أو تسقط القاعدة تحت الضغط.
     */
    public function test_a_failed_audit_insert_does_not_break_the_chain_forever(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('قيد سليم', 'hr', null, 'الأول');

        $headBefore = DB::table('audit_chain')->where('id', 1)->value('head');

        AuditEntry::created(function () { throw new \RuntimeException('فشل الإدراج'); });
        try { hub_audit('قيد يفشل', 'hr', null, 'الثاني'); } catch (\Throwable $e) { /* متوقّع */ }

        $this->assertSame($headBefore, DB::table('audit_chain')->where('id', 1)->value('head'),
            'تقدّم الرأس ولم يُكتب قيدٌ يحمله — والسلسلة تقول «عبث» إلى الأبد');

        $v = \App\Support\Audit::verifyTail();
        $this->assertTrue($v['ok'], 'إنذارُ تلاعبٍ كاذب: ' . ($v['why'] ?: $v['label']));
    }

    /** والقيد التالي يلتحم بسابقه لا بفراغ */
    public function test_the_chain_still_verifies_after_a_failed_insert(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('الأول', 'hr', null, 'أ');

        // مفتاحٌ يُطفأ بدل نزع المستمع: نزعُه يمسح خاتمَ السلسلة نفسه فيكذب الفحص
        $explode = true;
        AuditEntry::created(function () use (&$explode) {
            if ($explode) throw new \RuntimeException('فشل');
        });
        try { hub_audit('الساقط', 'hr', null, 'ب'); } catch (\Throwable $e) {}
        $explode = false;

        hub_audit('الثالث', 'hr', null, 'ج');

        $v = \App\Support\Audit::verifyTail();
        $this->assertTrue($v['ok'], 'السلسلة انكسرت بعد سقوط قيدٍ وسطيّ: ' . ($v['why'] ?: $v['label']));
    }

    /* ── ٢) لقطة النسخة ── */

    /** لقطةٌ موجودةٌ سلفاً لا تُسقط الحفظ — الكاتب الخاسر لا يُعاقَب بخطأ ٥٠٠ */
    public function test_a_duplicate_version_snapshot_never_kills_the_save(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $emp = Employee::create(['name' => 'موظف', 'status' => 'نشط']);

        // كاتبٌ آخر سبقنا فأدرج لقطة النسخة التالية قبل أن نصل إليها
        DB::table('record_versions')->insert([
            'module' => 'hr', 'record_id' => $emp->id, 'version' => 2,
            'snapshot' => json_encode(['name' => 'سبقني'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $emp->update(['name' => 'اسمٌ جديد']);

        $this->assertSame('اسمٌ جديد', $emp->fresh()->name, 'سقط الحفظ بخرق قيد تفرّد');
        $this->assertSame(1, RecordVersion::where('module', 'hr')->where('record_id', $emp->id)
            ->where('version', 2)->count(), 'ازدوجت لقطةٌ لنسخةٍ واحدة');
    }

    /** والإدراج يُتجاهَل عند التزاحم لا يُرمى — يُقرأ من المصدر */
    public function test_the_snapshot_writer_ignores_a_conflicting_insert(): void
    {
        $src = file_get_contents(app_path('Traits/HasVersions.php'));

        $this->assertStringContainsString('insertOrIgnore', $src,
            'اللقطة تُدرَج بلا تحصينٍ من التزاحم — فخرقُ التفرّد يصير خطأ ٥٠٠ على الحافظ');
    }

    /* ── ٣) ختم الصندوق ── */

    public function test_the_inbox_stamp_covers_every_table_it_reads(): void
    {
        $src = file_get_contents(app_path('Support/Inbox.php'));
        preg_match_all("/DB::table\('([a-z_]+)'\)/", $src, $m);
        $read = array_values(array_unique($m[1] ?? []));

        $missing = array_values(array_diff($read, \App\Support\Inbox::TABLES));

        $this->assertSame([], $missing,
            'جداولُ يقرؤها الصندوق ولا يختمها — تغيّرها لا يُبطل خبيئته فيبقى المُنجَز معروضاً: '
            . implode('، ', $missing));
    }

    /**
     * وتغييرُ ملفٍّ وظيفيّ يُنعش الصندوق فوراً: الجدول يُقرأ لأسماء طالبي
     * الإجازات، فاسمٌ صُحّح كان يبقى خاطئاً في الصندوق حتى تنتهي المهلة.
     */
    public function test_renaming_an_employee_refreshes_the_inbox(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $emp = Employee::create(['name' => 'اسمٌ خاطئ', 'status' => 'نشط', 'user_id' => $this->employee->id]);
        DB::table('leave_requests')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'emp_id' => $emp->id,
            'type' => 'إجازة سنوية', 'status' => 'مقدّم',
            'date_from' => now()->addDays(2)->toDateString(), 'date_to' => now()->addDays(4)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = collect(\App\Support\Inbox::items($this->owner))->pluck('title')->implode(' ');
        $this->assertStringContainsString('اسمٌ خاطئ', $before, 'لم يظهر الطلب أصلاً');

        $emp->update(['name' => 'الاسم الصحيح']);

        $after = collect(\App\Support\Inbox::items($this->owner))->pluck('title')->implode(' ');
        $this->assertStringContainsString('الاسم الصحيح', $after,
            'صُحّح الاسم وبقي الصندوق يعرض الخطأ — ختمُه لا يشمل جدول الموظفين');
    }
}
