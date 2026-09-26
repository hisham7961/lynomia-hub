<?php

use App\Support\Workforce\PayrollMonth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **DB-01 · مسيّرا رواتبَ لشهرٍ واحد** (مجلس الخبراء).
 *
 * «الشهر» مفهومٌ زمنيٌّ عومل معاملةَ نصٍّ حرّ، فحُفظ `2026-08` و«أغسطس 2026»
 * لشركةٍ واحدةٍ بلا تحذير — واعتمادُهما معاً يصرف الراتبَ مرّتين.
 *
 * **إضافةٌ لا كسر:** عمودٌ جديدٌ `month_key` للمقارنةِ وحدَها، و`month` النصّيُّ
 * يبقى كما هو للعرضِ والتوافقِ الخلفيّ. ولا حذفَ ولا تحويلَ ولا تغييرَ عقد.
 *
 * **ولمَ لا يُفرَض `unique` دائماً؟** قاعدةٌ قائمةٌ قد تحمل مكرَّراً **أُنشئ قبل
 * هذا الإصلاح** (وقاعدةُ العرضِ تحمله فعلاً). وهجرةٌ تسقط على بياناتٍ قائمةٍ
 * **تمنع الترقية كلَّها** — وهذا أسوأُ من العيبِ الذي تعالجه. فيُفرض الفهرسُ
 * الفريدُ **حين تسمح البيانات**، وإلّا فحارسُ النموذجِ يمنع الجديدَ ويُترك
 * القائمُ للمشغّل يقرّر فيه. والحارسُ يعمل في الحالتين.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) return;

        if (! Schema::hasColumn('payroll_runs', 'month_key')) {
            Schema::table('payroll_runs', function (Blueprint $t) {
                $t->date('month_key')->nullable()->after('month');
            });
        }

        // تعبئةُ القائم — دفعاتٍ صغيرةً كي لا تُحمَّل الذاكرةُ على جدولٍ كبير
        DB::table('payroll_runs')->select('id', 'month')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $r) {
                $key = PayrollMonth::key($r->month);
                if ($key !== null) {
                    DB::table('payroll_runs')->where('id', $r->id)->update(['month_key' => $key]);
                }
            }
        });

        // فهرسٌ عاديٌّ دائماً: يخدم الحارسَ والاستعلامَ معاً
        $idx = collect(Schema::getIndexes('payroll_runs'))->pluck('name')->all();
        if (! in_array('payroll_runs_company_month_idx', $idx, true)) {
            Schema::table('payroll_runs', function (Blueprint $t) {
                $t->index(['company_id', 'month_key'], 'payroll_runs_company_month_idx');
            });
        }

        // والفريدُ **حين تسمح البيانات** — لا تُسقَط ترقيةٌ على مكرَّرٍ قديم
        $dupes = DB::table('payroll_runs')
            ->whereNull('deleted_at')->whereNotNull('month_key')
            ->select('company_id', 'month_key')
            ->groupBy('company_id', 'month_key')
            ->havingRaw('COUNT(*) > 1')->count();

        if ($dupes === 0 && ! in_array('payroll_runs_company_month_uniq', $idx, true)) {
            try {
                Schema::table('payroll_runs', function (Blueprint $t) {
                    $t->unique(['company_id', 'month_key'], 'payroll_runs_company_month_uniq');
                });
            } catch (\Throwable $e) {
                // سباقُ إدراجٍ أثناء الهجرة: الحارسُ في النموذج يبقى خطَّ الدفاع
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_runs')) return;

        $idx = collect(Schema::getIndexes('payroll_runs'))->pluck('name')->all();
        Schema::table('payroll_runs', function (Blueprint $t) use ($idx) {
            if (in_array('payroll_runs_company_month_uniq', $idx, true)) {
                $t->dropUnique('payroll_runs_company_month_uniq');
            }
            if (in_array('payroll_runs_company_month_idx', $idx, true)) {
                $t->dropIndex('payroll_runs_company_month_idx');
            }
        });
        if (Schema::hasColumn('payroll_runs', 'month_key')) {
            Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn('month_key'));
        }
    }
};
