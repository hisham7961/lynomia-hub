<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة السابعة — **حارسُ عمود الشركة أعمى عن الشكل الذي يُخفي القيد.**
 *
 * `HubAuditVerify::companyMismatch` يقارن `audits.company_id` بشركة السجل
 * ليكشف تبديلاً يُخرج قيداً من نطاق مالكه بلا كسرِ السلسلة (العمودُ مشتقٌّ
 * لا مختوم). لكنّه يشترط `whereNotNull('audits.company_id')`، فيفلت منه
 * **أخطرُ شكلٍ للتبديل**: تفريغُ العمود.
 *
 * فـ`hub_company_scope` يُرشّح بـ`whereIn(company_id, …)`، وقيدٌ عمودُه `NULL`
 * لا يُطابق أيَّ شركة — فيختفي عن **كل** صاحب نطاق. أي أنّ من يُفرّغ العمودَ
 * يُخفي أثرَه من شاشة التدقيق التي بُنيت لكشفه، **والفاحصُ نفسُه يستثنيه
 * صراحةً من الفحص**. حارسٌ يستثني أخطرَ حالاته ليس حارساً.
 *
 * والحالةُ المرآة (سجلٌّ فُرّغت شركتُه والقيدُ يحمل القديمة) تفلت كذلك:
 * `company_id <> t.company_id` مع `NULL` يعطي `NULL` لا `TRUE`.
 */
class AuditCompanyGuardRound7Test extends TestCase
{
    protected function scene(): array
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $co = Company::create(['name' => 'شركة أ', 'name_ar' => 'شركة أ']);
        $cl = Client::create(['name' => 'عميل', 'company_id' => $co->id]);

        return [$co, $cl];
    }

    public function test_a_blanked_company_column_is_reported(): void
    {
        [, $cl] = $this->scene();

        $this->assertGreaterThan(0, DB::table('audits')->where('record_id', $cl->id)
            ->whereNotNull('company_id')->count(), 'القيدُ لم يُكتب بشركةٍ أصلاً');

        // التبديلُ الذي يُخفي القيد: تفريغُ العمود المشتقّ — البصمةُ سليمة
        DB::table('audits')->where('record_id', $cl->id)->update(['company_id' => null]);

        $this->artisan('hub:audit-verify')
            ->expectsOutputToContain('بلا شركة')
            ->assertExitCode(0);

        $this->artisan('hub:audit-verify', ['--strict' => true])->assertExitCode(1);
    }

    public function test_a_record_whose_company_was_cleared_is_reported_too(): void
    {
        [, $cl] = $this->scene();

        // الحالةُ المرآة: القيدُ يحمل الشركةَ والسجلُّ فُرّغت شركتُه
        DB::table('clients')->where('id', $cl->id)->update(['company_id' => null]);

        $this->artisan('hub:audit-verify')
            ->expectsOutputToContain('يخالف عمودُ الشركة')
            ->assertExitCode(0);
    }

    /** وقاعدةٌ متّسقة لا تُنذر — الحارسُ الذي يصرخ دائماً لا يُقرأ */
    public function test_a_consistent_database_raises_nothing(): void
    {
        $this->scene();

        $this->artisan('hub:audit-verify', ['--strict' => true])
            ->doesntExpectOutputToContain('بلا شركة')
            ->assertExitCode(0);
    }
}
