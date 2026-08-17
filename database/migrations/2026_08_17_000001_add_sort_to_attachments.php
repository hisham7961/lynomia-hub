<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ترتيبُ المرفقات — لأن الصور ليست ملفات.**
 *
 * المرفقات كانت تُرتَّب بتاريخ الرفع وحده، وهو ترتيبٌ كافٍ لعقدٍ وفاتورة: لا
 * أحد يسأل «أيُّ الفاتورتين أولاً؟». أمّا **لقطات المتجر** فترتيبُها هو
 * العرضُ نفسه — الصورةُ الأولى هي ما يراه المستخدم في المتجر قبل أن يقرّر
 * التحميل، ورفعُ لقطةٍ جديدةٍ كان يقفز بها إلى الصدارة لأنها الأحدث.
 *
 * عمودٌ واحدٌ يُصلح ذلك: `sort` يُملأ عند الرفع بترتيب الوصول، ويُحرَّك بعده
 * صعوداً ونزولاً. وما لا يُرتَّب يبقى على صفره فيعمل كما كان بالضبط.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attachments') || Schema::hasColumn('attachments', 'sort')) return;

        Schema::table('attachments', function (Blueprint $t) {
            $t->integer('sort')->default(0);
        });

        // فهرسُ العرض: مرفقاتُ سجلٍّ مرتَّبةً — مسارُ المعرض في كل فتحةِ صفحة
        Schema::table('attachments', fn (Blueprint $t) => $t->index(['record_id', 'sort'], 'attachments_record_sort_idx'));
    }

    public function down(): void
    {
        if (! Schema::hasTable('attachments') || ! Schema::hasColumn('attachments', 'sort')) return;

        Schema::table('attachments', function (Blueprint $t) {
            $t->dropIndex('attachments_record_sort_idx');
            $t->dropColumn('sort');
        });
    }
};
