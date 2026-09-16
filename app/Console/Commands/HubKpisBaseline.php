<?php

namespace App\Console\Commands;

use App\Models\KpiDef;
use App\Support\KpiCentre;
use Illuminate\Console\Command;

/**
 * **خطُّ الأساس: هدفٌ مقيسٌ من بياناتك لا رقمٌ مخترَع.**
 *
 * مؤشّرٌ بلا هدفٍ لا يُحكم عليه — يُعرض «بلا هدف» ويمرّ. وهدفٌ مخترَعٌ من الرأس
 * أسوأ: يُحاسَب به فريقٌ على رقمٍ لا سند له. والمخرجُ الصادقُ بينهما هو
 * **خطُّ الأساس**: يُقاس الواقعُ اليومَ ويُسجَّل هدفاً مبدئيّاً، **موسوماً
 * بأنّه قياسٌ لا التزام** — فمن يرفعه غداً يرفعه عن أرضٍ يعرفها.
 *
 * وثلاثةُ أشياءَ لا يمسّها هذا الأمر أبداً:
 *  · هدفاً كتبه إنسانٌ في الشاشة (`manual`) أو أعلنته المكتبة (`policy`)؛
 *  · مؤشّراً **فلترُه ميّت** (`KpiCentre::deadFilters`) — قياسُه صفرٌ كاذب،
 *    وتثبيتُه هدفاً يخلّد الكذبَ في جدول؛
 *  · مؤشّراً لا تُحسب قيمتُه أصلاً (وحدةٌ خارج النطاق، قسمةٌ على صفر).
 *
 * `--all` يُعيد القياسَ لخطوطِ الأساسِ وحدَها (`target_basis = baseline`) كي
 * يُحدَّث خطٌّ شاخ، ولا يمتدّ إلى هدفٍ التزم به أحد.
 */
class HubKpisBaseline extends Command
{
    protected $signature = 'hub:kpis-baseline {--all : أعِد قياسَ خطوطِ الأساسِ القائمة أيضاً}'
        . ' {--dry : اعرض ما سيُكتب ولا تكتب}';
    protected $description = 'ضبطُ أهدافِ المؤشّراتِ على خطِّ أساسٍ مقيسٍ من بياناتك (لا يمسّ هدفاً معلَناً)';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('kpi_defs')) {
            $this->error('جدول kpi_defs غير موجود — شغّل الهجرات أولاً');

            return self::FAILURE;
        }
        if (! hub_has_col('kpi_defs', 'target_basis')) {
            $this->error('عمودُ نسبِ الهدف غير مُرحَّل — شغّل php artisan migrate');

            return self::FAILURE;
        }

        $set = 0; $skipped = [];
        // ترتيبٌ حتميّ (`sort` ثمّ `id`) — التقريرُ نفسُه على المحرّكين
        foreach (KpiDef::orderBy('sort')->orderBy('id')->get() as $k) {
            $basis = trim((string) $k->target_basis);

            if ($k->target !== null && ! ($this->option('all') && $basis === 'baseline')) {
                $skipped[] = [$k->name, $basis === '' ? 'له هدفٌ قائم' : "هدفٌ نسبُه «{$basis}»"];
                continue;
            }

            $formula = (array) $k->formula;
            if ($dead = KpiCentre::deadFilters($formula)) {
                $skipped[] = [$k->name, 'فلترٌ لا يطابق السجل: «' . ($dead[0]['status'] ?? '?') . '»'];
                continue;
            }

            $value = hub_kpi_value($formula);
            if ($value === null) {
                $skipped[] = [$k->name, 'لا تُحسب قيمتُه'];
                continue;
            }

            /*
             * **والصفرُ ليس خطَّ أساس.** وحدةٌ لم يُسجَّل فيها شيءٌ بعدُ تقرأ صفراً،
             * وتثبيتُ الصفرِ هدفاً باتّجاه «الأعلى أفضل» يجعل المؤشّرَ «على الهدف»
             * أبداً — فالنظامُ يهنّئ نفسَه على غيابِ البيان. الهدفُ الصفريُّ حكمٌ
             * يُعلَن («لا فاتورةَ متأخّرة») لا قياسٌ يُلتقَط.
             */
            if (abs((float) $value) < 0.000001) {
                $skipped[] = [$k->name, 'لا بياناتٍ بعدُ — الصفرُ ليس خطَّ أساس'];
                continue;
            }

            $kind = KpiCentre::kind($formula, $k->unit);
            $def  = KpiCentre::kindDefaults($kind);
            $target = round((float) $value, $def['decimals']);
            if ($def['min'] !== null) $target = max($def['min'], $target);
            if ($def['max'] !== null) $target = min($def['max'], $target);

            $shown = KpiCentre::format($target, $kind, $k->unit ?: $def['unit']);
            $note = 'خطُّ أساسٍ مبدئيّ لا هدفٌ تجاريّ — قِيس ' . $shown
                . ' من بياناتك يوم ' . now()->format('Y-m-d') . '. ارفعه حين تلتزم به.';

            $this->line(($this->option('dry') ? '· ' : '✔ ') . $k->name . ' ⟵ ' . $shown);
            if ($this->option('dry')) { $set++; continue; }

            $attrs = ['target' => $target, 'target_basis' => 'baseline',
                      'target_note' => hub_fit($note, hub_col_max('kpi_defs', 'target_note') ?? 300),
                      'baseline_at' => now()];

            // والنواقصُ تُسَدّ في النفَسِ نفسِه: وحدةُ القياسِ ودورتُه من نوعِ المؤشّر
            if (trim((string) $k->unit) === '' && $def['unit'] !== null) $attrs['unit'] = $def['unit'];
            if (hub_has_col('kpi_defs', 'period') && trim((string) $k->period) === '') {
                $attrs['period'] = $def['period'];
            }

            $before = ['target' => $k->target, 'target_basis' => $k->target_basis];
            $k->update($attrs);
            hub_audit('ضبط خطِّ أساسٍ لمؤشر KPI', null, $k->id, $k->name,
                ['before' => $before, 'after' => ['target' => $target, 'target_basis' => 'baseline']]);
            $set++;
        }

        $this->info(($this->option('dry') ? 'سيُضبط ' : 'ضُبط ') . $set . ' مؤشراً على خطِّ أساسٍ مقيس');

        // **وما لم يُمَسّ يُقال ولا يُبتلع صمتاً** — وإلا قُرئ «ضُبط ٤» على أنّه الكلّ
        if ($skipped) {
            $this->line('ولم يُمَسّ ' . count($skipped) . ':');
            foreach ($skipped as [$name, $why]) $this->line("   — {$name}: {$why}");
        }

        return self::SUCCESS;
    }
}
