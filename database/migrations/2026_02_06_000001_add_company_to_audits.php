<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عمود الشركة على جدول التدقيق.
 *
 * كان الجدول بلا `company_id`، فلم يملك `hub_scope` ما يُفلتر به — وودجة «آخر النشاطات»
 * كانت تُظهر للمحدود بشركاته أسماء سجلات من شركات لا يراها (نتيجة التدقيق العدائي ٢).
 * أُصلح مؤقتاً بتقييد محافظ يُضيّق قائمته؛ وهذا هو الإصلاح البنيوي الذي يستعيدها كاملة.
 *
 * **الترحيل ممكن خلافاً لما قدّرتُه أولاً:** القيد يحمل `(module, record_id)`، وشركةُ
 * السجل تُقرأ من جدول وحدته. فلكل وحدة تحمل عمود شركة يُنسخ منها. وما لا يُحلّ —
 * قيدُ سجلٍ حُذف نهائياً — يبقى فارغاً، ويُحجب عن المحدودين لا يُكشف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audits')) return;

        if (! Schema::hasColumn('audits', 'company_id')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->uuid('company_id')->nullable()->after('project_id');
                $t->index(['company_id', 'created_at'], 'audits_company_created_index');
            });
        }

        foreach (config('hub.modules', []) as $mk => $md) {
            $table = $md['table'] ?? null;
            if (! $table || ! Schema::hasTable($table)) continue;

            // الشركة نفسها: شركةُ القيد هي سجلّه
            if ($table === 'companies') {
                DB::table('audits')->where('module', $mk)->whereNull('company_id')
                    ->update(['company_id' => DB::raw('record_id')]);
                continue;
            }

            if (! Schema::hasColumn($table, 'company_id')) continue;

            DB::table('audits')->where('module', $mk)->whereNull('company_id')
                ->update(['company_id' => DB::raw(
                    "(select t.company_id from {$table} t where t.id = audits.record_id)"
                )]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('audits') || ! Schema::hasColumn('audits', 'company_id')) return;

        Schema::table('audits', function (Blueprint $t) {
            $t->dropIndex('audits_company_created_index');
            $t->dropColumn('company_id');
        });
    }
};
