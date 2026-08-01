<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Support\Compliance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سجل الامتثال كان صفوفاً: عنوانٌ وجهةٌ وتاريخ. وثلاثة أشياء لا يقولها:
 *
 *   — **الأثر**: «متأخر» كلمةٌ لا ثمن لها، بخلاف «إيقاف النشاط والتعاقد
 *     والفوترة، وتعطّل تجديد الإقامات».
 *   — **المهلة**: التجديد نفسه يستغرق أسابيع؛ من ينتبه يوم الاستحقاق تأخّر.
 *   — **الغائب**: وهو أخطرها. شركةٌ بلا ترخيصٍ مسجَّل تبدو **سليمة** لأن لا
 *     صفَّ لها يحمرّ — وما لا سجلَّ له لا يُفحَص.
 */
class ComplianceBoardTest extends TestCase
{
    protected function item(array $a = []): string
    {
        $id = (string) Str::uuid();
        DB::table('compliance_items')->insert(array_merge([
            'id' => $id, 'title' => 'بند', 'status' => 'مطلوب إجراء',
            'created_at' => now(), 'updated_at' => now(),
        ], $a));

        return $id;
    }

    /** الرادار يبدأ من مهلة النوع لا من تاريخ الاستحقاق */
    public function test_the_radar_starts_at_the_kinds_lead_time(): void
    {
        $this->seedCore();
        // ترخيص تجاري مهلتُه ٦٠ يوماً: يظهر وهو على بُعد ٥٠
        $this->item(['title' => 'ترخيص خلال ٥٠ يوماً', 'kind' => 'ترخيص تجاري',
            'due' => now()->addDays(50)->toDateString()]);
        // ضريبة مهلتُها ٣٠: على بُعد ٥٠ لا تظهر بعد
        $this->item(['title' => 'ضريبة خلال ٥٠ يوماً', 'kind' => 'ضريبة',
            'due' => now()->addDays(50)->toDateString()]);

        $this->actingAs($this->owner);
        $titles = collect(Compliance::radar())->pluck('title');

        $this->assertContains('ترخيص خلال ٥٠ يوماً', $titles,
            'ترخيصٌ مهلتُه ٦٠ يوماً لم يدخل الرادار وهو على بُعد ٥٠ — التنبيه يوم الموعد تأخّر');
        $this->assertNotContains('ضريبة خلال ٥٠ يوماً', $titles,
            'الرادار أغرق نفسه بما لا يُعمَل فيه اليوم');
    }

    /** ولكلٍّ أثرُه المكتوب لا كلمة «مخالفة» */
    public function test_each_item_carries_its_real_consequence(): void
    {
        $this->seedCore();
        $this->item(['title' => 'الترخيص', 'kind' => 'ترخيص تجاري', 'due' => now()->toDateString()]);

        $this->actingAs($this->owner);
        $r = Compliance::radar()[0];

        $this->assertStringContainsString('إيقاف النشاط', $r['impact']);
        $this->assertSame('يستحق اليوم', $r['act']);
        $this->assertSame(60, $r['lead']);
    }

    /** نوعٌ غير مصنّف لا يبقى بلا أثر — يُقال له صنّفني */
    public function test_an_unclassified_kind_still_gets_guidance(): void
    {
        $this->seedCore();
        $this->item(['title' => 'شيءٌ ما', 'kind' => null, 'due' => now()->toDateString()]);

        $this->actingAs($this->owner);
        $this->assertStringContainsString('صنّف النوع', Compliance::radar()[0]['impact']);
    }

    /** الغائب: شركةٌ لا سجلَّ لالتزامٍ أساسيّ لديها */
    public function test_a_company_missing_a_baseline_obligation_is_named(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة كاملة', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ناقصة', 'status' => 'نشطة']);

        foreach (['ترخيص تجاري', 'ضريبة', 'تأمينات اجتماعية'] as $k) {
            $this->item(['title' => $k, 'kind' => $k, 'company_id' => $a->id,
                'due' => now()->addYear()->toDateString()]);
        }
        $this->item(['title' => 'ضريبة', 'kind' => 'ضريبة', 'company_id' => $b->id,
            'due' => now()->addYear()->toDateString()]);

        $this->actingAs($this->owner);
        $miss = collect(Compliance::missing());

        $this->assertNull($miss->firstWhere('company', 'شركة كاملة'), 'شركةٌ مكتملة عُدّت ناقصة');
        $gap = $miss->firstWhere('company', 'شركة ناقصة');
        $this->assertNotNull($gap, 'شركةٌ بلا ترخيصٍ مسجَّل بدت سليمة');
        $this->assertContains('ترخيص تجاري', $gap['gaps']);
        $this->assertContains('تأمينات اجتماعية', $gap['gaps']);
        $this->assertNotContains('ضريبة', $gap['gaps']);
    }

    /** والشركة المغلقة لا يُطالَب لها بتراخيص */
    public function test_a_closed_company_is_not_asked_for_licences(): void
    {
        $this->seedCore();
        Company::create(['name_ar' => 'شركة مغلقة', 'status' => 'مغلقة']);

        $this->actingAs($this->owner);
        $this->assertNull(collect(Compliance::missing())->firstWhere('company', 'شركة مغلقة'));
    }

    /** حالةٌ تكذب: «ملتزم» واستحقاقه فات */
    public function test_a_compliant_status_with_a_past_due_is_flagged_as_lying(): void
    {
        $this->seedCore();
        $this->item(['title' => 'رخصة قديمة', 'kind' => 'ترخيص تجاري', 'status' => 'ملتزم',
            'due' => now()->subDays(40)->toDateString(), 'att_id' => (string) Str::uuid()]);

        $this->actingAs($this->owner);
        $g = collect(Compliance::gaps())->firstWhere('kind', 'حالةٌ تكذب');

        $this->assertNotNull($g, 'بندٌ مكتوبٌ «ملتزم» وقد فات استحقاقه مرّ بلا تنبيه');
        $this->assertSame('bad', $g['tone']);
    }

    /** «ملتزم» بلا مرفقٍ يُثبته — ما يُبرَز عند التفتيش هو الشهادة لا الشاشة */
    public function test_compliant_without_evidence_is_flagged(): void
    {
        $this->seedCore();
        $this->item(['title' => 'شهادة ضريبية', 'kind' => 'ضريبة', 'status' => 'ملتزم',
            'due' => now()->addYear()->toDateString(), 'att_id' => null]);

        $this->actingAs($this->owner);
        $this->assertNotNull(collect(Compliance::gaps())->firstWhere('kind', 'ملتزمٌ بلا دليل'));
    }

    /** بلا مالكٍ وبلا تاريخ */
    public function test_ownerless_and_dateless_items_are_flagged(): void
    {
        $this->seedCore();
        $this->item(['title' => 'بلا مالك', 'kind' => 'ضريبة', 'due' => now()->addDays(10)->toDateString()]);
        $this->item(['title' => 'بلا تاريخ', 'kind' => 'ضريبة', 'owner_id' => $this->owner->id]);

        $this->actingAs($this->owner);
        $k = collect(Compliance::gaps())->pluck('kind');

        $this->assertContains('بلا مالك', $k);
        $this->assertContains('بلا تاريخ استحقاق', $k);
    }

    /** رسوم التسعين يوماً تدفّقٌ نقديّ يُخطَّط له */
    public function test_upcoming_fees_are_summed_as_cash_flow(): void
    {
        $this->seedCore();
        $this->item(['title' => 'أ', 'kind' => 'ضريبة', 'fee' => 300, 'due' => now()->addDays(20)->toDateString()]);
        $this->item(['title' => 'ب', 'kind' => 'ضريبة', 'fee' => 200, 'due' => now()->addDays(80)->toDateString()]);
        $this->item(['title' => 'ج', 'kind' => 'ضريبة', 'fee' => 900, 'due' => now()->addDays(200)->toDateString()]);

        $this->actingAs($this->owner);
        $p = Compliance::pulse();

        $this->assertSame(500.0, $p['fees90'], 'رسوم التسعين يوماً حُسبت خطأً');
        $this->assertSame(2, $p['soon']);
    }

    /** والشاشة خلف صلاحية الوحدة */
    public function test_the_board_is_behind_permission(): void
    {
        $this->seedCore();
        $this->item(['title' => 'رخصة', 'kind' => 'ترخيص تجاري', 'due' => now()->toDateString()]);

        $this->actingAs($this->owner)->get('/compliance-board')->assertOk()
            ->assertSee('الامتثال وأثره')->assertSee('إيقاف النشاط');

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['compliance'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->employee->fresh())->get('/compliance-board')->assertForbidden();
    }
}
