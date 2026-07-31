<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * النتيجة الرئيسية تُقاس من **مصدر** لا من كتابة.
 *
 * كان الحقل الوحيد للأتمتة `kpi_id` نصاً حراً — يُتوقّع من المستخدم لصقُ
 * معرّفٍ لا يعرفه، فيبقى فارغاً وتُكتب القيمة يدوياً؛ ومعها تُكتب «نسبة
 * الإنجاز» على الهدف باليد، وهذا ينقض OKR من أصله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('key_results', function (Blueprint $t) {
            // nullable لا NOT NULL: نموذج الوحدات يكتب null للحقل الفارغ،
            // و«بلا مصدر» تُقرأ يدويةً في الحساب — فلا قيد يكسر الحفظ
            $t->string('source', 20)->nullable()->index();          // manual|count|sum|avg|kpi|metric
            $t->string('src_module', 40)->nullable();               // وحدة القياس
            $t->string('src_col', 60)->nullable();                  // العمود للمجموع/المتوسط
            $t->string('src_status', 80)->nullable();               // تضييق بحالة السجل
            $t->uuid('src_record')->nullable();                     // سجلٌ بعينه (للمقياس الزمني)
            $t->string('src_metric', 40)->nullable();               // اسم المقياس الزمني
            $t->timestamp('read_at')->nullable();                   // آخر قراءةٍ آلية
        });

        Schema::table('objectives', function (Blueprint $t) {
            $t->decimal('progress_at_risk', 5, 2)->nullable();       // فجوة الإيقاع وقت آخر حساب
            $t->timestamp('computed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('key_results', function (Blueprint $t) {
            $t->dropColumn(['source', 'src_module', 'src_col', 'src_status',
                'src_record', 'src_metric', 'read_at']);
        });
        Schema::table('objectives', function (Blueprint $t) {
            $t->dropColumn(['progress_at_risk', 'computed_at']);
        });
    }
};
