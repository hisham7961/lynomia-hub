<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الفحص النهائيّ (بند ٨) — ترقيمٌ/قصٌّ بلا فاصل تعادلٍ حاسم في أربعة مواضع
 * (المستحقات في تقرير المالية، صندوق الوارد، سجل الويبهوك، مخاطر لوحة CEO):
 * فرزٌ بعمودٍ تتساوى قيمُه (due/created_at/severity) ثم قصٌّ — قرعةٌ بين المحرّكين.
 * الإصلاحُ واحدٌ في الأربعة: فاصلُ تعادلٍ بالمفتاح id. هنا اختبارٌ سلوكيّ
 * تمثيليّ على «المستحقات» يفشل أولاً (SQLite يُعيد ترتيب الإدراج عند التعادل).
 */
class FinalAuditDeterminismTest extends TestCase
{
    public function test_report_unpaid_list_has_deterministic_id_tiebreak(): void
    {
        $this->seedCore();

        // ثلاثةُ مستحقاتٍ بتاريخ استحقاقٍ موحّد (تعادلٌ في عمود الفرز due)،
        // مُدرَجةٌ بترتيبٍ مخالفٍ لترتيب id: ج ثم أ ثم ب.
        $due = '2026-09-01';
        $ids = ['11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '33333333-3333-4333-8333-333333333333'];
        $now = now()->toDateTimeString();
        foreach ([2, 0, 1] as $k) {   // ترتيبُ الإدراج ≠ ترتيب id
            DB::table('fin_documents')->insert(['id' => $ids[$k], 'doc_no' => "INV-{$k}",
                'kind' => 'فاتورة مبيعات', 'total' => 100, 'paid' => 0, 'amount' => 100, 'tax' => 0,
                'state' => 'مرسلة', 'due' => $due, 'date' => $due, 'partner' => 'جهة',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        $unpaid = $this->actingAs($this->owner)->get('/reports/finance')->assertOk()->viewData('unpaid');
        $got = collect($unpaid)->pluck('id')->all();

        // due متساوٍ → يُحسم بـ id تصاعديّاً → id0, id1, id2 (بلا الفاصل: ترتيبُ الإدراج)
        $this->assertSame($ids, $got, 'قائمة المستحقات بلا فاصل تعادلٍ حاسم عند تساوي due — قرعةٌ بين المحرّكين');
    }
}
