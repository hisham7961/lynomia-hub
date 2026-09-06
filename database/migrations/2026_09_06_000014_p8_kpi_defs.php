<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-8.5 · §6.9) مالكُ المؤشّر ودورتُه.
 *
 * مؤشّرٌ بلا مالكٍ ولا دورة ليس مؤشّرَ إدارة: «خارج الهدف» لا تعني شيئاً حتى
 * يُعرف **من** يُسأل عنه و**على أيّ مدىً** يُقاس. وبدونهما تبقى شاشةُ المؤشّرات
 * لوحةَ أرقامٍ لا أحدَ خلفها — والقائمةُ التي يطلبها §47 («مؤشّراتٌ خارج
 * الهدف») بلا مالكٍ لا تُحوَّل إلى فعل.
 *
 * إضافةٌ محضة: عمودان قابلان للإفراغ، فكلُّ مؤشّرٍ قائمٍ يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_defs')) return;

        if (! Schema::hasColumn('kpi_defs', 'owner_id')) {
            Schema::table('kpi_defs', function (Blueprint $t) {
                // مفهرسٌ لأن «مؤشّرات فلان» قراءةٌ متكرّرة في قائمة «خارج الهدف»
                $t->uuid('owner_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('kpi_defs', 'period')) {
            Schema::table('kpi_defs', function (Blueprint $t) {
                // عرضٌ صريح (٢٠) — `hub_col_max` يقرأ العرضَ من مصدر الهجرة،
                // وعمودٌ بلا طولٍ صريح غيرُ مرئيّ لحرّاس العرض
                $t->string('period', 20)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpi_defs')) return;

        foreach (['owner_id', 'period'] as $col) {
            if (Schema::hasColumn('kpi_defs', $col)) {
                Schema::table('kpi_defs', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
