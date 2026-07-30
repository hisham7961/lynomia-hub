<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس مركّبة لمصادر الخط الزمني.
 *
 * `hub_timeline` تقرأ أربعة جداول بـ`(module, record_id)` على **كل** صفحة سجل في
 * الوحدات الـ٧١. اثنان منها كانا بلا فهرس مركّب — فكان القارئ الجديد سيمسح جدول
 * التدقيق كاملاً في كل فتحة صفحة، وهو أضخم جداول النظام نمواً.
 *
 * إضافةٌ محضة: لا عمود يُغيَّر ولا بيان يُمسّ.
 */
return new class extends Migration
{
    private const TARGETS = ['audits', 'attachments'];

    public function up(): void
    {
        foreach (self::TARGETS as $table) {
            if (! Schema::hasTable($table)) continue;
            if (! Schema::hasColumn($table, 'module') || ! Schema::hasColumn($table, 'record_id')) continue;

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index(['module', 'record_id'], $table . '_module_record_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table) {
            if (! Schema::hasTable($table)) continue;

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_module_record_id_index');
            });
        }
    }
};
