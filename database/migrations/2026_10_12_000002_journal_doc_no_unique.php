<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فهرسٌ فريدٌ على `journal_entries.doc_no` — رقمُ القيد لا يتكرّر **بالقاعدة** لا بالفحص وحده.
 *
 * `JournalPosting::allocateNumber` يفحص `exists()` ثم يُدرج — وبين الفحص والإدراج قد تلتزم معاملةٌ
 * أخرى بالرقم نفسِه (قيدا رواتبٍ من الطابع الزمنيّ نفسِه). الفهرسُ يُسقط الثاني فيعيد المحرّكُ التخصيص.
 *
 * **إضافةٌ لا هدم:** أرقامُ القيود صادرةٌ فلا تُعاد تسميتُها — إن وُجد تكرارٌ قائمٌ يُتخطّى الفهرسُ
 * (ويُبلَّغ) ولا يُسقط الترحيل؛ ويبقى الفحصُ في المحرّك حاجزاً على تلك القاعدة حتى يُراجَع التكرارُ يدويّاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasColumn('journal_entries', 'doc_no')) return;
        if (Schema::hasIndex('journal_entries', 'journal_entries_doc_no_unique')) return;

        $dup = DB::table('journal_entries')->select('doc_no')->groupBy('doc_no')->havingRaw('COUNT(*) > 1')->exists();
        if ($dup) {
            report(new \RuntimeException('journal_entries.doc_no فيه تكرارٌ قائم — تُخطّي الفهرسُ الفريد؛ راجِع الأرقامَ المكرّرة'));

            return;
        }

        try {
            Schema::table('journal_entries', function (Blueprint $t) {
                $t->unique('doc_no', 'journal_entries_doc_no_unique');
            });
        } catch (\Throwable $e) {
            report($e);   // تعذّرٌ نادر — لا يُسقط الترحيل، والمحرّكُ يفحص كما كان
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasIndex('journal_entries', 'journal_entries_doc_no_unique')) return;
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropUnique('journal_entries_doc_no_unique');
        });
    }
};
