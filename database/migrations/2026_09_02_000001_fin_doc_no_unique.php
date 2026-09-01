<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فهرسٌ فريدٌ على `fin_documents.doc_no` — بعد ترحيلٍ **مأمونِ البيانات**.
 *
 * رقمُ المستند الماليّ مُدخَلٌ يدوياً، فقد يكتب مستخدمان الرقمَ نفسَه. التفرّدُ
 * يُفرَض الآن (بإذنٍ صريح)، لكن **بلا مسِّ أرقامِ المستنداتِ الصادرةِ عمياءَ**:
 *
 *  (١) الفراغُ يُوحَّد إلى NULL — الفهرسُ الفريدُ يسمح بعدّة NULL في المحرّكين،
 *      فمستنداتٌ بلا رقمٍ بعدُ لا تتصادم. العمودُ يُوسَّع ليقبل NULL (إضافةٌ لا هدم).
 *  (٢) التكرارُ غيرُ الفارغ: يبقى **الأقدمُ** برقمه (المستندُ الأصل)، وتُلحَق
 *      لاحقةٌ ظاهرةٌ (-٢، -٣) بالأحدث، مع حفظِ الرقم الأصليّ في `meta` — فلا يضيع
 *      رقمٌ ماليٌّ صادر، بل يُوسَم مكرّراً كي يُراجَع بشرياً.
 *  (٣) الفهرسُ يُضاف بحارس: إن بقي تكرارٌ (حالةٌ نادرة) يُتخطّى بلا إسقاطِ الترحيل،
 *      فلا يُعطَّل النشرُ ويُعالَج التكرارُ يدوياً.
 *
 * مأمونُ التكرار: على قاعدةٍ سليمة لا يجد ما يُرمّمه فلا يكتب شيئاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_documents') || ! Schema::hasColumn('fin_documents', 'doc_no')) return;

        // (١) توسيعُ العمود ليقبل NULL ثم توحيدُ الفراغ إليه
        Schema::table('fin_documents', function (Blueprint $t) {
            $t->string('doc_no', 300)->nullable()->change();
        });
        DB::table('fin_documents')->where('doc_no', '')->update(['doc_no' => null]);
        DB::table('fin_documents')->whereRaw("TRIM(doc_no) = ''")->update(['doc_no' => null]);

        // (٢) إزالةُ تكرار الأرقام غير الفارغة — الأقدمُ يبقى، والأحدثُ يُوسَم
        $dupes = DB::table('fin_documents')->whereNotNull('doc_no')
            ->select('doc_no')->groupBy('doc_no')
            ->havingRaw('COUNT(*) > 1')->pluck('doc_no');

        foreach ($dupes as $no) {
            $rows = DB::table('fin_documents')->where('doc_no', $no)
                ->orderBy('created_at')->orderBy('id')->get(['id', 'meta']);
            foreach ($rows as $idx => $row) {
                if ($idx === 0) continue;   // الأصلُ الأقدمُ بلا مساس

                $n = $idx + 1;
                $suffix = $no . '-' . $n;
                while (DB::table('fin_documents')->where('doc_no', $suffix)->exists()) {
                    $suffix = $no . '-' . (++$n);
                }
                $meta = json_decode((string) $row->meta, true) ?: [];
                $meta['doc_no_original'] = $no;
                $meta['doc_no_dedup_at'] = now()->toIso8601String();
                DB::table('fin_documents')->where('id', $row->id)->update([
                    'doc_no' => $suffix,
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // (٣) الفهرسُ الفريد — بحارسٍ يتخطّاه إن بقي تكرارٌ نادر
        $stillDup = DB::table('fin_documents')->whereNotNull('doc_no')
            ->select('doc_no')->groupBy('doc_no')->havingRaw('COUNT(*) > 1')->exists();
        if (! $stillDup) {
            try {
                Schema::table('fin_documents', function (Blueprint $t) {
                    $t->unique('doc_no', 'fin_documents_doc_no_unique');
                });
            } catch (\Throwable $e) {
                report($e);   // فهرسٌ قائمٌ أصلاً أو تعذّرٌ نادر — لا يُسقط الترحيل
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_documents')) return;
        try {
            Schema::table('fin_documents', function (Blueprint $t) {
                $t->dropUnique('fin_documents_doc_no_unique');
            });
        } catch (\Throwable $e) {
            // الفهرسُ غيرُ موجود — لا شيءَ يُنقَض
        }
    }
};
