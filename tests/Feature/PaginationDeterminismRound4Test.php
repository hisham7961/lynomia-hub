<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة الرابعة (مؤجّل) — ترقيم القائمة بلا فاصل تعادلٍ حاسم:
 * `ModuleController` كان يفرز بعمودٍ واحد (`created_at` افتراضاً) ثم `paginate(25)`
 * بلا فاصلِ تعادلٍ ثانويّ — فصفوفٌ تتساوى قيمةُ فرزها (created_at بدقّة الثانية،
 * إدراجٌ دفعيّ) تُقرَع بين الصفحات على MySQL 8: تخطٍّ أو تكرارٌ صامت. `id` (المفتاح)
 * يمنحها ترتيباً كليّاً ثابتاً على المحرّكين.
 */
class PaginationDeterminismRound4Test extends TestCase
{
    public function test_list_sort_has_deterministic_id_tiebreak(): void
    {
        $this->seedCore();

        // ثلاثةُ صفوفٍ بـ created_at موحّد (تعادلٌ في عمود الفرز)، مُدرَجةٌ بترتيبٍ
        // مخالفٍ لترتيب id: أ ثم ج ثم ب — فترتيبُ الإدراج ≠ ترتيب id
        $at = '2026-06-01 10:00:00';
        $ids = ['11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '33333333-3333-4333-8333-333333333333'];
        foreach ([[0, 'أ'], [2, 'ج'], [1, 'ب']] as [$i, $n]) {
            DB::table('tasks')->insert(['id' => $ids[$i], 'title' => "مهمة {$n}",
                'status' => 'جديدة', 'version' => 1, 'created_at' => $at, 'updated_at' => $at]);
        }

        $rows = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->viewData('rows');
        $got = collect($rows->items())->pluck('id')->all();

        // الفرز الافتراضي created_at تنازليّ، والتعادل يُحسم بـ id تنازليّاً → ج، ب، أ
        $this->assertSame([$ids[2], $ids[1], $ids[0]], $got,
            'ترقيم القائمة بلا فاصل تعادلٍ حاسم — ترتيبٌ يقترع بين المحرّكين');
    }
}
