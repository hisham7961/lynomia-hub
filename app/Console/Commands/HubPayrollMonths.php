<?php

namespace App\Console\Commands;

use App\Support\PayrollMonth;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مسيّرات الرواتب: كشفُ المكرَّرِ وإحكامُ القيد** (مجلس الخبراء · DB-01).
 *
 * هجرةُ `month_key` تفرض الفهرسَ الفريدَ **حين تسمح البيانات** فقط — فقاعدةٌ
 * قائمةٌ تحمل مكرَّراً أُنشئ قبل الإصلاح تبقى بلا قيدٍ في القاعدة (وحارسُ
 * النموذجِ يمنع الجديد). **ولولا هذا الأمرُ لبقيت كذلك إلى الأبد** ولو نظّف
 * المشغّلُ المكرَّرَ بعد حين.
 *
 * فهذا الأمرُ يقول للمشغّل **ما المكرَّرُ بالضبط**، ويُحكم القيدَ حين يصير
 * الإحكامُ ممكناً. **ولا يحذف شيئاً ولا يدمج**: القرارُ في أيِّ مسيّرٍ يبقى
 * قرارُ المحاسبِ لا قرارُ أداة.
 */
class HubPayrollMonths extends Command
{
    protected $signature = 'hub:payroll-months {--fix-index : أحكِم الفهرسَ الفريدَ إن سمحت البيانات}';

    protected $description = 'كشفُ مسيّرات الرواتب المكرَّرة لشهرٍ واحد، وإحكامُ قيد الفرادة';

    public function handle(): int
    {
        if (! Schema::hasTable('payroll_runs') || ! Schema::hasColumn('payroll_runs', 'month_key')) {
            $this->error('جدولُ المسيّرات أو عمودُ `month_key` غير موجود — شغّل الهجرات أولاً.');

            return self::FAILURE;
        }

        // تعبئةُ ما دخل بلا مفتاح (صفوفٌ كُتبت مباشرةً في القاعدة مثلاً)
        $filled = 0;
        DB::table('payroll_runs')->whereNull('month_key')->select('id', 'month')
            ->orderBy('id')->chunk(500, function ($rows) use (&$filled) {
                foreach ($rows as $r) {
                    if (($k = PayrollMonth::key($r->month)) !== null) {
                        DB::table('payroll_runs')->where('id', $r->id)->update(['month_key' => $k]);
                        $filled++;
                    }
                }
            });
        if ($filled) $this->line("مُلئ مفتاحُ الشهر لـ{$filled} مسيّراً.");

        $dupes = DB::table('payroll_runs')
            ->whereNull('deleted_at')->whereNotNull('month_key')
            ->select('company_id', 'month_key', DB::raw('COUNT(*) as n'))
            ->groupBy('company_id', 'month_key')
            ->havingRaw('COUNT(*) > 1')->get();

        if ($dupes->isEmpty()) {
            $this->info('✓ لا مسيّرَ مكرَّراً لشهرٍ واحد.');
        } else {
            $this->warn('⚠ مسيّراتٌ تتقاسم الشهرَ نفسَه لنفس الشركة — واعتمادُ اثنين يصرف الراتبَ مرّتين:');
            foreach ($dupes as $d) {
                $names = DB::table('payroll_runs')->whereNull('deleted_at')
                    ->where('month_key', $d->month_key)
                    ->when($d->company_id === null,
                        fn ($q) => $q->whereNull('company_id'),
                        fn ($q) => $q->where('company_id', $d->company_id))
                    ->pluck('name')->implode(' · ');
                $this->line("  {$d->month_key} × {$d->n}: {$names}");
            }
            $this->line('القرارُ في أيِّها يبقى — قرارُ المحاسب. هذا الأمرُ لا يحذف ولا يدمج.');
        }

        $idx = collect(Schema::getIndexes('payroll_runs'))->pluck('name')->all();
        $has = in_array('payroll_runs_company_month_uniq', $idx, true);

        if ($has) {
            $this->info('✓ قيدُ الفرادة مُحكَمٌ في القاعدة.');
        } elseif (! $this->option('fix-index')) {
            $this->line('قيدُ الفرادة غيرُ مُحكَمٍ في القاعدة (حارسُ النموذج يعمل). '
                . 'لإحكامِه بعد التنظيف: php artisan hub:payroll-months --fix-index');
        } elseif ($dupes->isNotEmpty()) {
            $this->error('لا يُحكَم القيدُ والمكرَّرُ قائم — نظّف أعلاه أوّلاً.');

            return self::FAILURE;
        } else {
            Schema::table('payroll_runs', function (Blueprint $t) {
                $t->unique(['company_id', 'month_key'], 'payroll_runs_company_month_uniq');
            });
            $this->info('✓ أُحكِم قيدُ الفرادة `(company_id, month_key)`.');
        }

        return $dupes->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
