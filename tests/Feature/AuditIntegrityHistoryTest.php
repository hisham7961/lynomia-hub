<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-5.5) تاريخُ نزاهة السلسلة — «هل السلسلة سليمة؟ ومتى فُحصت آخرَ مرة؟».
 *
 * الفحصُ الكامل كان يجري (أسبوعياً أو بزرّ مركز التشغيل) ثم **يتبخّر ناتجُه**:
 * سطرُ طرفيةٍ أو رسالةُ فلاش لا يقرؤها المدقّق بعد ساعة. الآن كلُّ تشغيلٍ —
 * آليٍّ أو يدويٍّ — يكتب صفاً في `audit_verifications` بعدّاداته ونتيجته
 * وأول قيدٍ متأثّر، فتُجيب الشاشةُ عن سؤال §45 بتاريخٍ لا بذاكرة.
 *
 * والقيدُ الصارم: **فتحُ الصفحات لا يشغّل فحصاً كاملاً أبداً** — على تحميل
 * الشاشة يبقى `Audit::verifyTail` وحده (ذيلٌ محدود)، تحرسه ميزانيةُ استعلامات.
 */
class AuditIntegrityHistoryTest extends TestCase
{
    // ── المخطّط ──

    public function test_table_exists_with_declared_widths(): void
    {
        $this->assertTrue(Schema::hasTable('audit_verifications'), 'جدول audit_verifications غائب');
        foreach (['mode', 'initiated_by', 'request_id', 'started_at', 'finished_at', 'duration_ms',
                  'result', 'checked_rows', 'weak_rows', 'unsealed_rows', 'mismatch_rows',
                  'blank_rows', 'first_bad_id', 'message'] as $col) {
            $this->assertTrue(Schema::hasColumn('audit_verifications', $col), "عمود {$col} غائب");
        }

        // العرضُ معلَنٌ في مصدر الهجرة بكتلٍ حرفية فتراه حرّاسُ العرض (critic #35)
        $this->assertSame(8, hub_col_max('audit_verifications', 'mode'));
        $this->assertSame(8, hub_col_max('audit_verifications', 'result'));
        $this->assertSame(40, hub_col_max('audit_verifications', 'request_id'));
        $this->assertSame(500, hub_col_max('audit_verifications', 'message'));
        $this->assertSame(36, hub_col_max('audit_verifications', 'initiated_by'));
    }

    /** دليلُ نزاهةٍ لا تليمتري: الجدول داخل النسخة الاحتياطية لا عابرٌ يُقلَّم */
    public function test_table_is_declared_in_backup_raw_tables(): void
    {
        $this->assertContains('audit_verifications', \App\Console\Commands\HubBackup::coveredTables(),
            'audit_verifications خارج النسخة الاحتياطية — تاريخُ النزاهة يضيع مع الاستعادة');
        $this->assertNotContains('audit_verifications', \App\Console\Commands\HubBackup::EPHEMERAL);
    }

    // ── الكاتب: تشغيلٌ آليّ ──

    /** التشغيلُ الآليّ (بلا مستخدمٍ مصادَق — كما يشغّله المجدول) يكتب صفاً واحداً بعدّاداته */
    public function test_auto_run_writes_one_row_with_counters(): void
    {
        $this->seedCore();
        // قيودٌ مختومة تُكتب بلا مصادقة — كما يكتبها النظام نفسُه
        foreach (['أولى', 'ثانية', 'ثالثة'] as $n) {
            AuditEntry::create(['action' => 'تعديل', 'module' => 'tasks',
                'record_id' => (string) Str::uuid(), 'name' => "مهمة {$n}", 'created_at' => now()]);
        }
        // seedCore نفسُه يكتب قيوداً مختومة (سمةُ Auditable على الأدوار والمستخدمين)
        $sealed = (int) AuditEntry::whereNotNull('hash')->count();
        $this->assertGreaterThanOrEqual(3, $sealed, 'تجهيز: القيود لم تُختم');

        $this->artisan('hub:audit-verify')->assertExitCode(0);

        $rows = DB::table('audit_verifications')->orderBy('started_at')->orderBy('id')->get();
        $this->assertCount(1, $rows, 'تشغيلٌ واحد = صفٌّ واحد');
        $v = $rows->first();
        $this->assertSame('auto', $v->mode, 'بلا مستخدمٍ مصادَق الوضعُ آليّ');
        $this->assertNull($v->initiated_by);
        $this->assertSame('ok', $v->result);
        $this->assertSame($sealed, (int) $v->checked_rows);
        $this->assertSame(0, (int) $v->weak_rows);
        $this->assertSame(0, (int) $v->unsealed_rows);
        $this->assertNull($v->first_bad_id);
        $this->assertNotNull($v->started_at);
        $this->assertNotNull($v->finished_at);
        $this->assertGreaterThanOrEqual(0, (int) $v->duration_ms);

        // تشغيلٌ ثانٍ = صفٌّ ثانٍ — تاريخٌ يتراكم لا صفٌّ يُحدَّث
        $this->artisan('hub:audit-verify')->assertExitCode(0);
        $this->assertSame(2, (int) DB::table('audit_verifications')->count());
    }

    // ── الكاتب: زرُّ مركز التشغيل ──

    /** الزرُّ اليدويّ يمرّ بالأمر نفسِه ويكتب صفاً بوضع manual ومُشغِّله */
    public function test_manual_ops_button_writes_row_with_initiator(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('تعديل', 'tasks', (string) Str::uuid(), 'قيد يدوي');

        $this->post(route('ops.verifyaudit'))->assertRedirect(route('ops.index'));

        $rows = DB::table('audit_verifications')->orderBy('started_at')->orderBy('id')->get();
        $this->assertCount(1, $rows);
        $v = $rows->first();
        $this->assertSame('manual', $v->mode);
        $this->assertSame((string) $this->owner->id, (string) $v->initiated_by);
        $this->assertSame('ok', $v->result);
        $this->assertNotNull($v->request_id, 'معرّفُ طلب الزرّ يُسجَّل للربط بالأثر');
        $this->assertGreaterThanOrEqual(1, (int) $v->checked_rows);
    }

    // ── الكاتب: الفشل يُؤرَّخ بأول قيدٍ متأثّر ──

    /** عبثٌ مباشر بالقاعدة: التشغيل يفشل ويكتب صفاً بنتيجة fail وأول قيدٍ متأثّر */
    public function test_tampering_records_fail_with_first_bad_id(): void
    {
        $this->seedCore();
        $ids = [];
        foreach (['قبل العبث', 'المعبوث به', 'بعد العبث'] as $n) {
            $ids[] = AuditEntry::create(['action' => 'تعديل', 'module' => 'tasks',
                'record_id' => (string) Str::uuid(), 'name' => $n, 'created_at' => now()])->id;
        }
        // تعديلٌ مباشرٌ يكسر مطابقة البصمة للمحتوى — لا يمرّ بالموديل
        DB::table('audits')->where('id', $ids[1])->update(['name' => 'اسمٌ مزوَّر']);

        $this->artisan('hub:audit-verify')->assertExitCode(1);

        $v = DB::table('audit_verifications')->orderBy('started_at')->orderBy('id')->first();
        $this->assertNotNull($v, 'الفشلُ يُؤرَّخ كما يُؤرَّخ النجاح');
        $this->assertSame('fail', $v->result);
        $this->assertSame((int) $ids[1], (int) $v->first_bad_id, 'أول قيدٍ متأثّر يُسمّى');
        $this->assertNotSame('', (string) $v->message, 'الرسالة تقول ما وُجد');
    }

    // ── القرّاء: فتحُ الصفحات لا يكتب صفاً ولا يشغّل فحصاً كاملاً ──

    /** شاشتا التدقيق والتشغيل تقرآن التاريخَ ولا تُنشئانه — وفي حدود ميزانية استعلامات */
    public function test_opening_pages_writes_no_row_and_stays_in_query_budget(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('تعديل', 'tasks', (string) Str::uuid(), 'قيدٌ للعرض');

        $this->get('/admin/audit')->assertOk();       // تسخينُ الخبيئات
        $this->get('/admin/ops')->assertOk();

        DB::enableQueryLog();
        $this->get('/admin/audit')->assertOk();
        $log = array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            ! preg_match('/sqlite_master|pragma_table|pragma_index|information_schema/i', $q['query'])));
        DB::disableQueryLog();

        // لا فحصَ كامل في طلب: الميزانيةُ تُسقط أيَّ مشيٍ للسلسلة كلِّها،
        // وverifyTail (ذيلٌ محدود) وقراءةُ آخر التشغيلات داخلها
        $this->assertLessThanOrEqual(50, count($log),
            'شاشة التدقيق كلّفت ' . count($log) . ' استعلاماً — فوق الميزانية (فحصٌ كامل على تحميل صفحة؟)');
        foreach ($log as $q) {
            $this->assertStringNotContainsString('insert into "audit_verifications"', $q['query'],
                'فتحُ الشاشة كتب صفَّ تحقّق — القارئ صار كاتباً');
            // الفحصُ الكامل يمشي فهرس السلسلة كلَّه بلا حدّ (id/prev_hash/hash) —
            // أيُّ قارئٍ لـprev_hash على تحميل صفحةٍ لا بدّ أن يكون مسقوفاً (verifyTail)
            if (str_contains($q['query'], 'prev_hash')) {
                $this->assertStringContainsString('limit', $q['query'],
                    'استعلامُ سلسلةٍ بلا سقف على تحميل الصفحة — هذا مشيُ الفحص الكامل: ' . $q['query']);
            }
        }

        $this->get('/admin/ops')->assertOk();
        $this->assertSame(0, (int) DB::table('audit_verifications')->count(),
            'فتحُ الشاشات أنشأ صفَّ تحقّق — الصفُّ للتشغيل الفعليّ وحده');
    }

    // ── الاحتفاظ (§1.8 · ق٦): وصفٌ لا مقصّ ──

    /** مفتاحا الاحتفاظ يُعرضان للقراءة في الشاشة — ولا كودَ تقليمٍ يقرؤهما */
    public function test_retention_keys_are_descriptive_and_displayed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // الافتراضي ٠ = للأبد — الشاشة تقولها صراحةً
        $html = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('للأبد', $html, 'سياسة الاحتفاظ الافتراضية (للأبد) غائبة عن الشاشة');

        // سياسةٌ نصّية مضبوطة تُعرض كما هي (للقراءة)
        $this->hubSetting('audit.retention_policy', 'سياسة المنشأة: يُحتفظ بسجل التدقيق عشر سنوات على الأقل');
        $html = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('عشر سنوات على الأقل', $html);

        // المفتاحان مُعلَنان في كتالوج الإعدادات (لا مفتاحَ يتيماً — حارسُ SettingsCenterTest)
        $known = array_merge(\App\Http\Controllers\Web\SettingController::exposedKeys(),
            array_keys(\App\Http\Controllers\Web\SettingController::internal()));
        $this->assertContains('audit.retention_days', $known);
        $this->assertContains('audit.retention_policy', $known);

        // ق٦: لا كودَ تقليمٍ — كنّاسُ الأتمتة لا يقرأ المفتاحَ ولا يمسّ جدول audits
        $automation = (string) file_get_contents(app_path('Console/Commands/HubAutomation.php'));
        $this->assertStringNotContainsString("setting('audit.retention_days'", $automation,
            'كنّاسُ الأتمتة صار يقرأ مفتاح الاحتفاظ — ق٦ تنصّ: وصفٌ لا مقصّ');
        $this->assertStringNotContainsString("DB::table('audits')->where", $automation,
            'كودُ تقليمٍ على جدول التدقيق المختوم');
    }
}
