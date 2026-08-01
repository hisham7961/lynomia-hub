<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إيقافُ مؤشر بلا حذفه: المؤشر الموسمي (حملةٌ انتهت، هدفُ ربعٍ مضى) كان أمامه
 * خياران فقط — أن يبقى يشغل لوحة الجميع، أو يُحذف فتضيع معادلته فيُعاد بناؤها
 * من الذاكرة. العمود يفصل «مُخفى» عن «محذوف».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_defs') && ! Schema::hasColumn('kpi_defs', 'active')) {
            Schema::table('kpi_defs', function (Blueprint $t) {
                $t->boolean('active')->default(true)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kpi_defs') && Schema::hasColumn('kpi_defs', 'active')) {
            Schema::table('kpi_defs', fn (Blueprint $t) => $t->dropColumn('active'));
        }
    }
};
