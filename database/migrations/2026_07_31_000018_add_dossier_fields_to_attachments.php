<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ملف الكيان: المرفق يصير **وثيقةً لها نوعٌ ورقمٌ ومدّة** لا ملفاً في كومة.
 *
 * ومعها إصلاحُ عيبٍ صامت: ملاحظة المرفق كانت تُحقَّق حتى ٢٠٠ حرف وتُحشر في
 * العمود `field` بطول ٦٠ — فتنكسر على MySQL الصارم أو تُبتر صمتاً. الملاحظة
 * صار لها عمودها، والقديم يُنقل إليه ولا يُحذف العمود القديم (لا كسر).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $t) {
            $t->string('kind', 40)->nullable()->index();   // مفتاح الوثيقة من config/hub_docs.php
            $t->string('note', 300)->nullable();
            $t->string('doc_no', 80)->nullable();          // رقم السجل/الشهادة/الوكالة
            $t->date('issued_at')->nullable();
            $t->date('expires_at')->nullable()->index();
        });

        // الملاحظات القديمة كانت في `field` — تُنقل ولا تُفقد
        try {
            DB::table('attachments')->whereNotNull('field')->where('field', '!=', '')
                ->update(['note' => DB::raw('field')]);
        } catch (\Throwable $e) {
            // قاعدةٌ لا تدعم النقل بهذه الصيغة — الأعمدة أُنشئت والقديم باقٍ في field
        }
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $t) {
            $t->dropColumn(['kind', 'note', 'doc_no', 'issued_at', 'expires_at']);
        });
    }
};
