<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **من أين جاء هذا الهدف؟** — نسبُ هدفِ المؤشّر.
 *
 * `target` رقمٌ صامت. والرقمُ الصامتُ في لوحةِ إدارةٍ خطر: من يقرأ «الهدف ٨٠٪»
 * لا يدري أهو **التزامٌ تجاريٌّ أُقرّ في مجلس**، أم **خطُّ أساسٍ قِيس من
 * بياناتِ الشهرِ الماضي** ليكون نقطةَ انطلاق، أم رقمٌ كتبه أحدهم من رأسه.
 * والثلاثةُ تُقرأ سواءً، فيُحاسَب فريقٌ على رقمٍ لم يلتزم به أحد.
 *
 * فيُحفظ **النسب** مع الرقم:
 *  · `target_basis` — `baseline` (مقيسٌ من البيانات) · `policy` (التزامٌ معلَن)
 *    · `manual` (كتبه إنسانٌ في الشاشة) · فارغٌ لِما سبق هذه الهجرة.
 *  · `target_note` — الجملةُ التي تُعرض تحت الرقم، وفيها تاريخُ القياس وقيمتُه.
 *  · `baseline_at` — متى قِيس، فيُعرف متى شاخ خطُّ الأساس.
 *
 * إضافةٌ محضة: ثلاثةُ أعمدةٍ قابلةٍ للإفراغ، فكلُّ مؤشّرٍ قائمٍ يبقى كما هو.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_defs')) return;

        if (! Schema::hasColumn('kpi_defs', 'target_basis')) {
            Schema::table('kpi_defs', function (Blueprint $t) {
                // عرضٌ صريح — `hub_col_max` يقرأ العرضَ من مصدر الهجرة
                $t->string('target_basis', 20)->nullable()->index();
            });
        }

        if (! Schema::hasColumn('kpi_defs', 'target_note')) {
            Schema::table('kpi_defs', fn (Blueprint $t) => $t->string('target_note', 300)->nullable());
        }

        if (! Schema::hasColumn('kpi_defs', 'baseline_at')) {
            Schema::table('kpi_defs', fn (Blueprint $t) => $t->dateTime('baseline_at')->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpi_defs')) return;

        foreach (['target_basis', 'target_note', 'baseline_at'] as $col) {
            if (Schema::hasColumn('kpi_defs', $col)) {
                Schema::table('kpi_defs', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
