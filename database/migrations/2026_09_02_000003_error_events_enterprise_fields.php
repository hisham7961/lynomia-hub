<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **إدارةُ الأخطاء المؤسسية — إضافةً لا كسراً.**
 *
 * `error_events` كان يجمّع بالبصمة ويعدّ التكرار، لكنه لا يعرف **صنفَ** العطل
 * (تحقّق؟ قاعدة؟ تكامل خارجي؟ تخزين؟) ولا **شدّتَه** (تحذير أم انقطاع؟) ولا
 * **الإصدارَ** الذي ظهر فيه ولا **كم مستخدماً** أصابه. فكلُّ الأخطاء متساوية
 * في القائمة، ولا يُجاب عن «أيُّها يستحقّ الآن؟» إلا بقراءة كل صف.
 *
 * الأعمدةُ كلُّها اختيارية: الصفوفُ القديمة تبقى، والكودُ يقرأها بحارس `hasColumn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events')) return;

        Schema::table('error_events', function (Blueprint $t) {
            if (! Schema::hasColumn('error_events', 'category')) $t->string('category', 24)->nullable()->index();   // VALIDATION|DATABASE|INTEGRATION|…
            if (! Schema::hasColumn('error_events', 'severity')) $t->string('severity', 12)->nullable()->index();   // INFO|WARNING|ERROR|HIGH|CRITICAL
            if (! Schema::hasColumn('error_events', 'release')) $t->string('release', 20)->nullable();              // إصدار النظام عند أول ظهور
            if (! Schema::hasColumn('error_events', 'env')) $t->string('env', 16)->nullable();                      // production|local|…
            if (! Schema::hasColumn('error_events', 'route')) $t->string('route', 160)->nullable();                 // اسم المسار أو نمطه
            if (! Schema::hasColumn('error_events', 'users')) $t->unsignedInteger('users')->default(1);            // مستخدمون متأثرون (تقريبٌ محدود)
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('error_events')) return;
        Schema::table('error_events', function (Blueprint $t) {
            foreach (['category', 'severity', 'release', 'env', 'route', 'users'] as $c) {
                if (Schema::hasColumn('error_events', $c)) $t->dropColumn($c);
            }
        });
    }
};
