<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٥ — ربط الإيراد بالخدمة: كان محرك `hub_service_costs` يقيس هامشاً
 * **نظرياً** من السعر المُعلن في بطاقة الخدمة لا من فواتير مُصدَرة، وMRR غير
 * قابل للقياس أصلاً. مرجعان يفتحان الإيراد الحقيقي والهامش الحقيقي.
 */
return new class extends Migration
{
    private const COLS = [
        'contracts' => ['service_id', 'plan_id'],
        'fin_documents' => ['service_id'],
        'quotes' => ['service_id'],
    ];

    public function up(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $col) {
                    if (! Schema::hasColumn($table, $col)) $t->uuid($col)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $col) {
                    if (Schema::hasColumn($table, $col)) $t->dropColumn($col);
                }
            });
        }
    }
};
