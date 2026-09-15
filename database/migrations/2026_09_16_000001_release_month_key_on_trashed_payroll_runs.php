<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تحريرُ الشهرِ من الصفوفِ المحذوفة** (التحقّقُ المستقلّ الثامن).
 *
 * الفهرسُ الفريدُ `(company_id, month_key)` الذي أضافته هجرةُ v2.508.0 يعدّ الصفَّ
 * المحذوفَ منطقيّاً محتلّاً لشهرِه — فمن حذف مسيّرَ يونيو ثمّ أراد إنشاءَ الصحيحِ
 * مكانَه اصطدم بخطأِ قاعدةِ بياناتٍ خام. و`month_key` مفتاحٌ **مشتقٌّ** يُعاد
 * اشتقاقُه من `month` عند الاستعادة (ويمرّ عندها بفحصِ التضارّ)، فتفريغُه للمحذوفِ
 * لا يفقد بياناً: الشهرُ المقروءُ في عمودِ `month` كما هو.
 *
 * **إضافةٌ لا كسر:** لا صفَّ يُحذف، ولا عمودَ يُسقَط، ولا فهرسَ يُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')
            || ! Schema::hasColumn('payroll_runs', 'month_key')
            || ! Schema::hasColumn('payroll_runs', 'deleted_at')) {
            return;
        }

        DB::table('payroll_runs')
            ->whereNotNull('deleted_at')
            ->whereNotNull('month_key')
            ->update(['month_key' => null]);
    }

    public function down(): void
    {
        // لا رجوع: المفتاحُ مشتقٌّ ويُعاد بناؤه من `month` بأمرِ `hub:payroll-months`
    }
};
