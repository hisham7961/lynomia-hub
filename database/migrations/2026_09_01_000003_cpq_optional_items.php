<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPQ المرحلة ب — **بنودٌ اختيارية/بديلة/إضافية** على بنود العرض.
 *
 * `line_mode`: أساسيّ (يدخل خطَّ الأساس دائماً) · اختياريّ · بديل (واحدٌ من
 * مجموعةٍ يُختار) · إضافة. `opt_group` يجمع البدائل. `included` يحدّد ما يدخل
 * الإجماليَّ المُلتزَم — فالاختياريّ غير المُدرَج «فرصةٌ عُلويّة» لا التزام.
 *
 * إضافيٌّ محضٌ: القائمُ كلُّه `line_mode='required'` بحكم القيمة الافتراضية
 * فلا يتغيّر إجماليُّ عرضٍ واحد. down() فارغة — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quote_lines')) {
            Schema::table('quote_lines', function (Blueprint $t) {
                if (! Schema::hasColumn('quote_lines', 'line_mode')) {
                    $t->string('line_mode', 20)->default('required')->index();
                }
                if (! Schema::hasColumn('quote_lines', 'opt_group')) {
                    $t->string('opt_group', 120)->nullable();
                }
                if (! Schema::hasColumn('quote_lines', 'included')) {
                    $t->boolean('included')->default(true);
                }
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
