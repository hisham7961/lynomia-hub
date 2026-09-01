<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — المرحلة ٤: توحيدُ تعريف «المستحقّ المتأخّر» في `hub_fin_outstanding`.
 *
 * يثبت أنّ إشارةَ التحصيل صارت على التعريف الموثوق: نوعُ دخلٍ حقيقيّ (لا «فاتورة»
 * المجرّدة التي لا تطابق البيانات)، وحالةٌ ليست مسدَّدةً/ملغاةً/مسودة، ومتبقٍّ موجب.
 */
class IntelligenceOutstandingConsolidationTest extends TestCase
{
    private function inv(array $a): FinDocument
    {
        return FinDocument::create(array_merge([
            'total' => 1000, 'paid' => 200, 'due' => now()->subDays(30)->toDateString(),
        ], $a));
    }

    private function overdueKeys(): array
    {
        return collect(hub_recommendations(true)['items'])
            ->filter(fn ($i) => str_starts_with((string) ($i['key'] ?? ''), 'fin.overdue:'))
            ->pluck('key')->all();
    }

    public function test_helper_matches_only_real_outstanding_income(): void
    {
        $this->seedCore();

        $income  = $this->inv(['doc_no' => 'R1', 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة']);
        $bare    = $this->inv(['doc_no' => 'R2', 'kind' => 'فاتورة', 'state' => 'متأخرة']);            // نوعٌ مجرّدٌ خاطئ
        $draft   = $this->inv(['doc_no' => 'R3', 'kind' => 'فاتورة مبيعات', 'state' => 'مسودة']);       // مسودة
        $paid    = $this->inv(['doc_no' => 'R4', 'kind' => 'فاتورة مبيعات', 'state' => 'مدفوعة', 'paid' => 1000]);
        $expense = $this->inv(['doc_no' => 'R5', 'kind' => 'فاتورة مشتريات', 'state' => 'متأخرة']);     // مشترياتٌ ليست دخلاً

        $ids = hub_fin_outstanding(DB::table('fin_documents')->whereNull('deleted_at'))->pluck('id')->all();
        $this->assertContains($income->id, $ids, 'الدخلُ المتأخّرُ الحقيقيّ غائب');
        $this->assertNotContains($bare->id, $ids, '«فاتورة» المجرّدة عُدَّت (عيبُ المقياس الكاذب)');
        $this->assertNotContains($draft->id, $ids, 'المسودةُ عُدَّت مستحقّاً');
        $this->assertNotContains($paid->id, $ids, 'المسدَّدةُ بالكامل عُدَّت مستحقّاً');
        $this->assertNotContains($expense->id, $ids, 'فاتورةُ مشترياتٍ عُدَّت مستحقّاً لنا');
    }

    public function test_action_center_overdue_signal_uses_the_canonical_definition(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $real = $this->inv(['doc_no' => 'S1', 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة', 'partner' => 'عميلٌ متعثّر']);
        $this->inv(['doc_no' => 'S2', 'kind' => 'فاتورة', 'state' => 'متأخرة']);   // مجرّدةٌ لا تُرصَد

        $keys = $this->overdueKeys();
        $this->assertContains('fin.overdue:' . $real->id, $keys);
        $this->assertCount(1, $keys, 'رُصد غيرُ الدخل الحقيقيّ');
    }

    public function test_minDays_threshold_ages_receivables(): void
    {
        $this->seedCore();
        $recent = $this->inv(['doc_no' => 'A1', 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة', 'due' => now()->subDays(10)->toDateString()]);
        $old    = $this->inv(['doc_no' => 'A2', 'kind' => 'فاتورة مبيعات', 'state' => 'متأخرة', 'due' => now()->subDays(90)->toDateString()]);

        $aged = hub_fin_outstanding(DB::table('fin_documents')->whereNull('deleted_at'), 60)->pluck('id')->all();
        $this->assertContains($old->id, $aged, 'المتقادمُ فوق ٦٠ يوماً غائب');
        $this->assertNotContains($recent->id, $aged, 'الحديثُ (١٠ أيام) عُدّ متقادماً فوق ٦٠');
    }
}
