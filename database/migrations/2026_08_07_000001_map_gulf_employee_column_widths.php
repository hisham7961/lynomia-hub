<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدةُ الملف الوظيفي الخليجي (‏2026_07_31_000009) أُضيفت **بحلقةٍ**
 * `foreach ($cols as $col => $len) { $t->string($col, $len) }` — واسمُ العمود
 * وطولُه متغيّران لا حرفان. وماسحُ `hub_col_widths` يقرأ الأطوال من **مصدر
 * الهجرات** بنمطٍ يبحث عن `->string('اسمٌ حرفيّ', رقم)` — فلا يرى ما أُعلن بحلقة.
 *
 * فبقيت الأعمدةُ الستّة غائبةً عن خريطة الأطوال: لا قاعدةَ `max:` تُضاف في
 * `ModuleController::rules`، فهاتفٌ ١٠٢ حرفاً على `VARCHAR(80)` يسقط بـ22001 على
 * MySQL (وقبولٌ صامتٌ ثم بترٌ على SQLite — فلا تحمرّ الحزمة).
 *
 * هنا تُعاد الأعمدةُ الستّة **بأسمائها وأطوالها حرفيّاً** (لا بحلقة) عبر
 * `change()` — لا تغييرَ فعليّ في القاعدة (الطولُ نفسُه)، بل جعلُ المصدر مقروءاً
 * للماسح، على نمط `2026_07_31_000021`. فتُضاف قاعدةُ الطول تلقائياً في مسار
 * الكتابة والاستيراد معاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) return;

        Schema::table('employees', function (Blueprint $t) {
            if (Schema::hasColumn('employees', 'email'))       $t->string('email', 300)->nullable()->change();
            if (Schema::hasColumn('employees', 'phone'))       $t->string('phone', 80)->nullable()->change();
            if (Schema::hasColumn('employees', 'nationality')) $t->string('nationality', 80)->nullable()->change();
            if (Schema::hasColumn('employees', 'civil_id'))    $t->string('civil_id', 80)->nullable()->change();
            if (Schema::hasColumn('employees', 'iban'))        $t->string('iban', 80)->nullable()->change();
            if (Schema::hasColumn('employees', 'emergency'))   $t->string('emergency', 300)->nullable()->change();
        });
    }

    public function down(): void
    {
        // الأطوالُ نفسُها لم تتغيّر — لا تراجعَ ذا معنى.
    }
};
