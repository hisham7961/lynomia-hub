<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إصلاحات تظهر على MySQL وحده (sqlite يخفيها في الاختبارات):
 *
 * ١) error_events.first_seen من نوع TIMESTAMP وهو **أول** عمود توقيت في الجدول،
 *    وMySQL — حين يكون explicit_defaults_for_timestamp مطفأً كما في أغلب الاستضافات —
 *    يمنح هذا العمود ضمنياً «ON UPDATE CURRENT_TIMESTAMP»، فيُدهس «أول ظهور» مع كل
 *    زيادة عدّاد للخطأ نفسه وتضيع أثمن معلومة فيه. نحوّله إلى DATETIME بلا سلوك ضمني.
 *
 * ٢) فهارس ناقصة تجعل استعلامات ساخنة تمسح الجدول كاملاً:
 *    - idempotency_keys.created_at: التنظيف يعمل مع **كل** كتابة عبر الـ API.
 *    - webhook_deliveries.state: عامل التسليم يستعلم عنه كل خمس دقائق.
 *    - inbox_documents(status, created_at): صفحة الصندوق ترتّب عليهما.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE error_events MODIFY first_seen DATETIME NOT NULL');
            DB::statement('ALTER TABLE error_events MODIFY last_seen DATETIME NOT NULL');
        }

        Schema::table('idempotency_keys', fn (Blueprint $t) => $t->index('created_at', 'idem_gc_idx'));
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->index(['state', 'next_at'], 'wd_due_idx'));
        Schema::table('inbox_documents', fn (Blueprint $t) => $t->index(['status', 'created_at'], 'inbox_list_idx'));
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', fn (Blueprint $t) => $t->dropIndex('idem_gc_idx'));
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dropIndex('wd_due_idx'));
        Schema::table('inbox_documents', fn (Blueprint $t) => $t->dropIndex('inbox_list_idx'));
    }
};
