<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٤ — الملف الوظيفي الخليجي: كان ملف الموظف بلا بريد ولا هاتف ولا
 * جنسية ولا بطاقة مدنية ولا IBAN ولا جهة طوارئ — وكلها مطلوبة عملياً في
 * الكويت والخليج. أعمدة اختيارية إضافية صرفة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cols = ['email' => 300, 'phone' => 80, 'nationality' => 80,
                 'civil_id' => 80, 'iban' => 80, 'emergency' => 300];
        Schema::table('employees', function (Blueprint $t) use ($cols) {
            foreach ($cols as $col => $len) {
                if (! Schema::hasColumn('employees', $col)) $t->string($col, $len)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            foreach (['email', 'phone', 'nationality', 'civil_id', 'iban', 'emergency'] as $col) {
                if (Schema::hasColumn('employees', $col)) $t->dropColumn($col);
            }
        });
    }
};
