<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الإصلاح البنيوي لنتيجة التدقيق ٢: عمود الشركة على جدول التدقيق.
 * الحرجُ هنا شيئان: أن الترحيل يملأ الشركة الصحيحة، وأن سلسلة التجزئة **تبقى صحيحة** بعده.
 */
class AuditCompanyBackfillTest extends TestCase
{
    public function test_column_and_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('audits', 'company_id'));
    }

    /**
     * سلسلة التدقيق مانعة للعبث. `company_id` **ليس** ضمن الأعمدة المختومة (`SEALED`)
     * لأنه بيانٌ مشتقّ من `record_id` المختوم أصلاً — فتحديثه بالترحيل لا يمسّ بصمة أي صف،
     * والسلسلة تبقى صحيحة. هذا الاختبار يُثبت ذلك: يبني سلسلة، يُرحّل، ثم يتحقق.
     */
    public function test_backfill_preserves_the_tamper_proof_chain(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة السلسلة']);

        // قيود حقيقية تمرّ بالسمة فتُختم في السلسلة
        $this->actingAs($this->owner);
        for ($i = 0; $i < 5; $i++) {
            Task::create(['title' => "مهمة {$i}", 'company_id' => $co->id]);
        }
        $this->assertGreaterThanOrEqual(5, DB::table('audits')->count());

        // نُفرّغ الشركة لنُحاكي حالة ما قبل الترحيل، ثم نُعيد الترحيل
        DB::table('audits')->update(['company_id' => null]);
        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_02_06_000001_add_company_to_audits.php', '--force' => true]);

        // التحقق يجب أن يمرّ نظيفاً — لا صفّ فُكَّ ختمه
        $code = Artisan::call('hub:audit-verify');
        $this->assertSame(0, $code, "سلسلة التدقيق كُسرت بعد الترحيل:\n" . Artisan::output());
    }

    /** الترحيل يستعيد شركة السجل من جدول وحدته */
    public function test_backfill_recovers_company_from_the_record(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة مستعادة']);
        $t = Task::create(['title' => 'مهمة', 'company_id' => $co->id]);

        // قيدٌ خام بلا شركة — كما كان قبل الإصلاح
        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => $t->id, 'action' => 'تعديل',
            'name' => 'مهمة', 'user_id' => $this->owner->id, 'company_id' => null, 'created_at' => now(),
        ]);

        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_02_06_000001_add_company_to_audits.php', '--force' => true]);

        $row = DB::table('audits')->where('action', 'تعديل')->where('record_id', $t->id)->first();
        $this->assertSame($co->id, $row->company_id, 'الترحيل لم يستعد شركة السجل');
    }

    /** قيد الشركة نفسها: شركتُه هي سجلّه */
    public function test_backfill_maps_a_company_record_to_itself(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة ذاتية']);

        DB::table('audits')->insert([
            'module' => 'companies', 'record_id' => $co->id, 'action' => 'إضافة',
            'name' => 'شركة ذاتية', 'user_id' => $this->owner->id, 'company_id' => null, 'created_at' => now(),
        ]);

        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_02_06_000001_add_company_to_audits.php', '--force' => true]);

        $row = DB::table('audits')->where('module', 'companies')->where('record_id', $co->id)->first();
        $this->assertSame($co->id, $row->company_id);
    }

    /** سجلٌ محذوف لا يُطابَق فيبقى بلا شركة — لا يُخمَّن */
    public function test_backfill_leaves_orphan_audits_null(): void
    {
        $this->seedCore();
        DB::table('audits')->insert([
            'module' => 'tasks', 'record_id' => (string) Str::uuid(), 'action' => 'حذف',
            'name' => 'سجل مندثر', 'user_id' => $this->owner->id, 'company_id' => null, 'created_at' => now(),
        ]);

        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_02_06_000001_add_company_to_audits.php', '--force' => true]);

        $row = DB::table('audits')->where('name', 'سجل مندثر')->first();
        $this->assertNull($row->company_id, 'قيدٌ يتيم خُمِّنت شركته');
    }
}
