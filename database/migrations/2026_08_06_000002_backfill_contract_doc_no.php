<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترميمُ ترقيم العقود — ما تخطّاه الترحيلُ الأصلُ فبقي بلا `doc_no`.
 *
 * `2026_02_15_000001_clm_data_foundation` رقّم العقودَ القائمة بـ:
 *
 *     ->whereNull('doc_no')->orderBy('created_at')->orderBy('id')->chunkById(200, …)
 *
 * و`chunkById` تُلاحق الصفحاتِ بشرطِ `id > آخرِ معرّفٍ في الدفعة`، لكنّها لا
 * تزيل إلا ترتيبَ عمودِ المفتاح — فبقي `ORDER BY created_at` **قبله**. فآخرُ
 * صفٍّ في الدفعة ليس صاحبَ أكبر `id` فيها (والمعرّفات UUID لا تسلسلَ زمنيّاً
 * فيها أصلاً)، فيقفز المؤشّرُ فوق عقودٍ لم تُرقَّم — **إلى الأبد**، لأن الترحيل
 * لا يُعاد. وعقدٌ بلا رقمٍ مستنديّ لا يُشار إليه في مراسلةٍ ولا تدقيق.
 *
 * هنا يُستكمَل الترقيمُ بالتسلسل السنويّ نفسِه، مبتدئاً من أعلى تسلسلٍ مستعمَل
 * في كل سنة كي لا يصطدم بالفهرس الفريد. والترحيلُ **مأمونُ التكرار**: على قاعدةٍ
 * سليمة لا يجد ما يُرمّمه فلا يكتب شيئاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasColumn('contracts', 'doc_no')) return;

        // أعلى تسلسلٍ مستعمَل لكل سنة — البدايةُ من فوقه لا من الواحد
        $seq = [];
        foreach (DB::table('contracts')->whereNotNull('doc_no')->pluck('doc_no') as $no) {
            if (preg_match('/^CTR-(\d{4})-(\d+)$/', (string) $no, $m)) {
                $seq[$m[1]] = max($seq[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        // بلا `orderBy('created_at')`: chunkById تفرض ترتيبَها على المفتاح، وأي
        // ترتيبٍ قبله يكسر ملاحقةَ الصفحات. السنةُ تُشتقّ من الصفّ نفسِه.
        DB::table('contracts')->whereNull('doc_no')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$seq) {
                foreach ($rows as $c) {
                    $year = substr((string) $c->created_at, 0, 4) ?: date('Y');
                    $seq[$year] = ($seq[$year] ?? 0) + 1;
                    DB::table('contracts')->where('id', $c->id)->update([
                        'doc_no' => sprintf('CTR-%s-%04d', $year, $seq[$year]),
                        'kind' => $c->kind ?? 'أصلي',
                    ]);
                }
            });
    }

    public function down(): void
    {
        // ترميمٌ لا يُنقَض: نزعُ أرقامٍ مستنديّة مُشار إليها في مراسلاتٍ وتدقيق
        // خسارةُ بيانات، وهذا الترحيلُ يُضيف ولا يهدم.
    }
};
