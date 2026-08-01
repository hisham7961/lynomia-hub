<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسعةُ أعمدةٍ نصّيةٍ حرّة أضيقَ من مدخلاتها — من عائلة عطل `flows.event`.
 *
 * الحقل في سجل الوحدات `text` (إدخالٌ حرّ) والعمود `varchar(10)` أو `char(36)`.
 * وSQLite لا يفرض الطول فتمرّ الحزمة خضراء، ثم يرفض MySQL أول قيمةٍ واقعية
 * بـ`SQLSTATE[22001]`. أوضحُها:
 *
 *   — `services.currency` و`competitors.currency` بعشرة محارف: «دينار كويتي»
 *     وحدها أحد عشر.
 *   — `key_results.kpi_id` و`src_record` معرّفان بطول ٣٦ يُطلب من المستخدم
 *     لصقُهما يدوياً — وأيُّ لصقٍ زائدٍ أو مسافةٍ يتجاوزهما.
 *   — `key_results.unit` بثلاثين: «عدد العملاء الجدد المؤهلين شهرياً» ثلاثةٌ
 *     وثلاثون.
 *   — `policies.ver` و`policy_acks.ver` بأربعين، وهما يُقارَنان نصّاً لربط
 *     الإقرار بنسخته — فالقصُّ يكسر الربط لا الحفظ وحده.
 *
 * الأعمدة **مكتوبةٌ صريحةً** لا في حلقةٍ بمتغيّرات: حارسُ العروض يقرأ مصدر
 * الهجرات، وما لا يُقرأ من المصدر لا يُحرَس.
 *
 * توسعةٌ محضة: لا عمودَ يضيق، ولا بياناتٍ تُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn ($t, $c) => Schema::hasTable($t) && Schema::hasColumn($t, $c);

        if ($has('services', 'currency')) {
            Schema::table('services', fn (Blueprint $t) => $t->string('currency', 60)->nullable()->change());
        }
        if ($has('competitors', 'currency')) {
            Schema::table('competitors', fn (Blueprint $t) => $t->string('currency', 60)->nullable()->change());
        }
        if ($has('competitors', 'founded')) {
            Schema::table('competitors', fn (Blueprint $t) => $t->string('founded', 80)->nullable()->change());
        }
        if ($has('key_results', 'kpi_id')) {
            Schema::table('key_results', fn (Blueprint $t) => $t->string('kpi_id', 120)->nullable()->change());
        }
        if ($has('key_results', 'src_record')) {
            Schema::table('key_results', fn (Blueprint $t) => $t->string('src_record', 120)->nullable()->change());
        }
        if ($has('key_results', 'src_module')) {
            Schema::table('key_results', fn (Blueprint $t) => $t->string('src_module', 80)->nullable()->change());
        }
        if ($has('key_results', 'src_metric')) {
            Schema::table('key_results', fn (Blueprint $t) => $t->string('src_metric', 80)->nullable()->change());
        }
        if ($has('key_results', 'unit')) {
            Schema::table('key_results', fn (Blueprint $t) => $t->string('unit', 80)->nullable()->change());
        }
        if ($has('users', 'phone')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('phone', 80)->nullable()->change());
        }
        if ($has('policies', 'ver')) {
            Schema::table('policies', fn (Blueprint $t) => $t->string('ver', 80)->nullable()->change());
        }
        if ($has('policy_acks', 'ver')) {
            Schema::table('policy_acks', fn (Blueprint $t) => $t->string('ver', 80)->nullable()->change());
        }
    }

    public function down(): void
    {
        // لا تضييق: العودة تقطع قيماً كُتبت بالعرض الجديد — والضيقُ هو العطل نفسه
    }
};
