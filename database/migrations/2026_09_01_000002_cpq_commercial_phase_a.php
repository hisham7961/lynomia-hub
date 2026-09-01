<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPQ — المرحلة أ: تصنيفُ الإيراد والملخّصُ التجاريّ (MRR/ARR/TCV) ونوعُ العرض.
 *
 * إضافيٌّ محضٌ فوق `quotes`/`quote_lines` القائمة (nullable، والقائمُ يعمل كما هو):
 * - `quotes.qtype`: نوعُ العرض التجاريّ (بسيط/مشروع/خدمة مُدارة/احتفاظ/…).
 * - `quotes.mrr/arr/tcv`: لقطاتٌ داخليةٌ محسوبةٌ خادمياً — لا تُعرَض للعميل.
 * - `quote_lines.rev_type`: تصنيفُ الإيراد (مرّة/دوري/استخدام/تكلفة ممرَّرة).
 * - `quote_lines.rev_period`: دوريّةُ المتكرّر (شهري/سنوي) — لحساب MRR.
 *
 * لا تُمَسّ دلالةُ `total`/`amount`/`tax` القائمة — الملخّصُ التجاريّ يُضاف بجانبها.
 * down() فارغة عمداً — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes')) {
            Schema::table('quotes', function (Blueprint $t) {
                if (! Schema::hasColumn('quotes', 'qtype')) $t->string('qtype', 40)->nullable()->index();
                if (! Schema::hasColumn('quotes', 'mrr')) $t->decimal('mrr', 16, 3)->nullable();
                if (! Schema::hasColumn('quotes', 'arr')) $t->decimal('arr', 16, 3)->nullable();
                if (! Schema::hasColumn('quotes', 'tcv')) $t->decimal('tcv', 16, 3)->nullable();
            });
        }
        if (Schema::hasTable('quote_lines')) {
            Schema::table('quote_lines', function (Blueprint $t) {
                if (! Schema::hasColumn('quote_lines', 'rev_type')) $t->string('rev_type', 20)->nullable();
                if (! Schema::hasColumn('quote_lines', 'rev_period')) $t->string('rev_period', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
