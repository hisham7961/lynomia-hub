<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use Tests\TestCase;

/**
 * تدقيقٌ عميق — رقمُ المستند الدوريّ: متسلسلٌ فريدٌ لا لاحقةٌ عشوائيةٌ تتصادم.
 */
class FinRecurringNoTest extends TestCase
{
    public function test_recurring_numbers_are_sequential_and_unique(): void
    {
        $this->seedCore();
        $ym = now()->format('ym');

        $first = FinDocument::nextRecurringNo();
        $this->assertSame("REC-{$ym}-0001", $first);

        // إنشاءٌ فعليّ ثم الرقمُ التالي يتقدّم
        FinDocument::create(['doc_no' => $first, 'kind' => 'مصروف', 'total' => 100, 'date' => now()->toDateString()]);
        $this->assertSame("REC-{$ym}-0002", FinDocument::nextRecurringNo());

        // خمسون رقماً متتاليةً كلُّها فريدة (لا تصادمَ عيدِ ميلاد)
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $no = FinDocument::nextRecurringNo();
            $this->assertNotContains($no, $seen, 'رقمٌ دوريٌّ مكرّر');
            $seen[] = $no;
            FinDocument::create(['doc_no' => $no, 'kind' => 'مصروف', 'total' => 100, 'date' => now()->toDateString()]);
        }
        $this->assertCount(50, array_unique($seen));
    }
}
