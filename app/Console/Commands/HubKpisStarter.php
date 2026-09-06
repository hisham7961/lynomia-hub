<?php

namespace App\Console\Commands;

use App\Models\KpiDef;
use Illuminate\Console\Command;

/**
 * عدّة انطلاق مؤشرات KPI: مكتبةٌ جاهزة تغطي كل جوانب النظام —
 * التسليم، المالية، المبيعات، الدعم، الجودة، الموارد البشرية، الحوكمة،
 * الأصول، التسويق، والامتثال.
 *
 * كل مؤشرٍ **محسوبٌ من بياناتك** لا مكتوبٌ بيدك: عدّ سجلات أو مجموع عمود أو
 * نسبةُ أحدهما للآخر — بالصيغة نفسها التي يبنيها باني المؤشرات في الواجهة،
 * فما وُلّد هنا يُعدَّل ويُحذف من شاشته كأي مؤشرٍ يدوي.
 *
 * المطابقة بالاسم فلا تكرار مهما أُعيد التشغيل، ولا يُمَسّ ما عدّلتَه.
 */
class HubKpisStarter extends Command
{
    protected $signature = 'hub:kpis-starter {--all : أضف المكتبة الموسّعة أيضاً}'
        . ' {--repair : أصلِح مؤشراتِ البذر التي تُرشِّح حالةً لا وجودَ لها في السجل}';
    protected $description = 'إنشاء مكتبة مؤشرات KPI جاهزة محسوبة من بياناتك (لا يكرر الموجود بالاسم)';

    /**
     * [الاسم، الوحدة، التجميع، العمود، الحالة، الدمج، وحدة‌ب، تجميع‌ب، عمود‌ب، حالة‌ب، الوحدة، الهدف، الاتجاه]
     * combine: none | ratio_pct | ratio | diff | sum
     *
     * **كلُّ حالةٍ هنا مأخوذةٌ حرفياً من خيارات وحدتها في `config/hub.php`**
     * (WP-8.5). كانت عشرُ صفوفٍ تُرشِّح حالاتٍ لا وجودَ لها — «متأخرة» في
     * المهامّ و«مفتوحة» في التذاكر و«نشط» في العملاء و«مرسل» بلا ضمّة — فتقرأ
     * **صفراً أبداً**، وصفرٌ مقابلَ هدفٍ صفر باتّجاه «الأقلّ أفضل» كان يُعرض
     * «على الهدف». فالبطاقةُ تهنّئ صاحبَها على قياسٍ لم يقع.
     * وحارسُ `KpiCenterTest` يمنع عودةَ الصنف كلِّه لا هذه الصفوف وحدها.
     */
    protected const CORE = [
        // ── التسليم والمشاريع ──
        ['🚀 المشاريع النشطة', 'projects', 'count', null, 'قيد التنفيذ', 'none', null, null, null, null, 'مشروع', null, 'up'],
        ['✅ نسبة إنجاز المهام', 'tasks', 'count', null, 'منجزة', 'ratio_pct', 'tasks', 'count', null, '', '٪', 80, 'up'],
        // «متأخرة» ليست حالةً في سجل المهامّ — التأخّرُ يُشتقّ من `due` لا يُخزَّن،
        // ومحرّكُ المؤشّرات يُرشّح بالحالة وحدَها. فالمقروءُ هنا **التوقّف** وهو حالةٌ
        // معلَنة، والمتأخّرُ يُقرأ من `ExecutionStats` حيث يُحسب من موعده.
        ['⛔ المهام المتوقفة', 'tasks', 'count', null, 'متوقفة', 'none', null, null, null, null, 'مهمة', 0, 'down'],
        ['📋 بنود خطة العمل المنشورة', 'feats', 'count', null, 'منشورة', 'ratio_pct', 'feats', 'count', null, '', '٪', 75, 'up'],

        // ── المالية ──
        ['💰 إجمالي الفواتير الصادرة', 'fin', 'sum', 'total', null, 'none', null, null, null, null, null, null, 'up'],
        ['💵 المحصّل فعلياً', 'fin', 'sum', 'paid', null, 'none', null, null, null, null, null, null, 'up'],
        ['📊 نسبة التحصيل', 'fin', 'sum', 'paid', null, 'ratio_pct', 'fin', 'sum', 'total', '', '٪', 90, 'up'],
        ['🧾 الفواتير المتأخرة', 'fin', 'count', null, 'متأخرة', 'none', null, null, null, null, 'فاتورة', 0, 'down'],
        ['💳 مصروفات المشتريات', 'purchases', 'sum', 'amount', null, 'none', null, null, null, null, null, null, 'down'],
        ['🔁 كلفة الاشتراكات الشهرية', 'subs', 'sum', 'amount', 'نشط', 'none', null, null, null, null, null, null, 'down'],

        // ── المبيعات ──
        ['🤝 العملاء الحاليون', 'clients', 'count', null, 'عميل حالي', 'none', null, null, null, null, 'عميل', null, 'up'],
        ['📄 عروض الأسعار المرسلة', 'quotes', 'count', null, 'مُرسل', 'none', null, null, null, null, 'عرض', null, 'up'],
        ['🏆 نسبة قبول العروض', 'quotes', 'count', null, 'مقبول', 'ratio_pct', 'quotes', 'count', null, '', '٪', 40, 'up'],
        ['💼 قيمة العروض المقبولة', 'quotes', 'sum', 'total', 'مقبول', 'none', null, null, null, null, null, null, 'up'],

        // ── الدعم والتذاكر ──
        // «مفتوحة» تجميعٌ لا حالة؛ وسجلُّ التذاكر يبدأ بـ«جديدة» — وهي التي
        // تقيس تراكمَ ما لم يُلمس بعد. والمفتوحُ كلُّه يُقرأ من مركز الدعم.
        ['🎫 التذاكر الجديدة', 'tickets', 'count', null, 'جديدة', 'none', null, null, null, null, 'تذكرة', 5, 'down'],
        ['✔️ نسبة التذاكر المحلولة', 'tickets', 'count', null, 'تم الحل', 'ratio_pct', 'tickets', 'count', null, '', '٪', 90, 'up'],

        // ── الجودة والتقنية ──
        ['🐞 المشاكل المفتوحة', 'issues', 'count', null, 'مفتوحة', 'none', null, null, null, null, 'مشكلة', 0, 'down'],
        ['🚨 الحوادث التقنية هذا الربع', 'incidents', 'count', null, null, 'none', null, null, null, null, 'حادثة', 0, 'down'],
        ['📦 عمليات النشر الناجحة', 'deploys', 'count', null, 'ناجح', 'ratio_pct', 'deploys', 'count', null, '', '٪', 95, 'up'],

        // ── الموارد البشرية ──
        ['👥 الموظفون على رأس العمل', 'hr', 'count', null, 'نشط', 'none', null, null, null, null, 'موظف', null, 'up'],
        ['🏝️ الإجازات المعتمدة', 'leaves', 'count', null, 'معتمد', 'none', null, null, null, null, 'إجازة', null, 'up'],
        ['🎯 المرشحون في مسار التوظيف', 'recruit', 'count', null, null, 'none', null, null, null, null, 'مرشح', null, 'up'],

        // ── الحوكمة ──
        ['✋ الموافقات المعلّقة', 'approvals', 'count', null, 'معلّق', 'none', null, null, null, null, 'طلب', 0, 'down'],
        ['📜 العقود السارية', 'contracts', 'count', null, 'ساري', 'none', null, null, null, null, 'عقد', null, 'up'],
        ['⚖️ بنود الامتثال الملتزمة', 'compliance', 'count', null, 'ملتزم', 'ratio_pct', 'compliance', 'count', null, '', '٪', 100, 'up'],
        ['📝 القرارات المنفَّذة', 'decisions', 'count', null, 'منفَّذ', 'ratio_pct', 'decisions', 'count', null, '', '٪', 90, 'up'],
    ];

    /** مكتبةٌ موسّعة — تُضاف بـ--all لمن يريد تغطيةً أعمق */
    protected const MORE = [
        ['💡 الأفكار المعتمدة', 'ideas', 'count', null, 'معتمدة', 'ratio_pct', 'ideas', 'count', null, '', '٪', 25, 'up'],
        ['🧱 الأصول قيد الاستخدام', 'assets', 'count', null, 'قيد الاستخدام', 'none', null, null, null, null, 'أصل', null, 'up'],
        ['📉 أصناف نفدت من المخزون', 'stock', 'count', null, 'نفد', 'none', null, null, null, null, 'صنف', 0, 'down'],
        // وحدةُ الموردين **بلا حقل حالة** أصلاً (v2.340) — ففلترُ «نشط» كان
        // يسقط صامتاً والبطاقةُ تعدّ الجميع تحت اسمٍ يقول «النشطون».
        ['🏭 عدد الموردين', 'suppliers', 'count', null, null, 'none', null, null, null, null, 'مورّد', null, 'up'],
        ['📣 حسابات التواصل النشطة', 'social', 'count', null, 'نشط', 'none', null, null, null, null, 'حساب', null, 'up'],
        ['👍 إجمالي متابعي المنصات', 'social', 'sum', 'followers', null, 'none', null, null, null, null, 'متابع', null, 'up'],
        ['📝 المنشورات المنشورة', 'posts', 'count', null, 'منشور', 'none', null, null, null, null, 'منشور', null, 'up'],
        ['📱 التطبيقات المنشورة', 'apps', 'count', null, 'منشور', 'none', null, null, null, null, 'تطبيق', null, 'up'],
        ['⬇️ إجمالي تحميلات التطبيقات', 'apps', 'sum', 'downloads', null, 'none', null, null, null, null, 'تحميل', null, 'up'],
        ['⭐ متوسط تقييم التطبيقات', 'apps', 'avg', 'rating', null, 'none', null, null, null, null, '/5', 4.3, 'up'],
        ['🌐 المواقع العاملة', 'websites', 'count', null, 'يعمل', 'none', null, null, null, null, 'موقع', null, 'up'],
        ['🖥️ السيرفرات العاملة', 'servers', 'count', null, 'يعمل', 'none', null, null, null, null, 'سيرفر', null, 'up'],
        ['💸 الكلفة الشهرية للسيرفرات', 'servers', 'sum', 'cost_month', null, 'none', null, null, null, null, null, null, 'down'],
        ['📚 مقالات المعرفة المنشورة', 'kb', 'count', null, 'منشور', 'none', null, null, null, null, 'مقال', null, 'up'],
        ['📨 الطلبات الداخلية المفتوحة', 'requests', 'count', null, 'جديد', 'none', null, null, null, null, 'طلب', 0, 'down'],
        ['🎨 مهام التصميم الجاهزة', 'designs', 'count', null, 'جاهز', 'ratio_pct', 'designs', 'count', null, '', '٪', 80, 'up'],
        ['🗓️ الاجتماعات المنعقدة', 'meetings', 'count', null, 'انعقد', 'none', null, null, null, null, 'اجتماع', null, 'up'],
        ['🎪 الفعاليات القادمة', 'events', 'count', null, 'مخطط', 'none', null, null, null, null, 'فعالية', null, 'up'],
        ['📰 الظهور الإعلامي', 'media', 'count', null, 'منشور', 'none', null, null, null, null, 'ظهور', null, 'up'],
        ['🏁 المنافسون المرتفعو التهديد', 'competitors', 'count', null, 'نشط', 'none', null, null, null, null, 'منافس', null, 'down'],
        ['🧩 الخدمات النشطة', 'services', 'count', null, 'نشطة', 'none', null, null, null, null, 'خدمة', null, 'up'],
        ['🔐 أسرار الخزنة', 'vault', 'count', null, null, 'none', null, null, null, null, 'سرّ', null, 'up'],
        ['📑 السياسات السارية', 'policies', 'count', null, 'سارية', 'none', null, null, null, null, 'سياسة', null, 'up'],
        ['🖊️ الإقرارات المكتملة', 'policyacks', 'count', null, 'مُقرّة', 'ratio_pct', 'policyacks', 'count', null, '', '٪', 100, 'up'],
        ['🎯 الأهداف على المسار', 'okrs', 'count', null, 'على المسار', 'ratio_pct', 'okrs', 'count', null, '', '٪', 70, 'up'],
    ];

    /**
     * أسماءٌ تغيّرت مع تصحيح حالتها (WP-8.5) — قديمٌ ⟵ جديد.
     *
     * تلزم لأمرين: كي لا يبذر `--repair` نسخةً ثانيةً بالاسم الجديد بجانب
     * المكسورة، وكي يُقرأ في الشيفرة **ما الذي كان يقيس ماذا** قبل التصحيح.
     */
    protected const RENAMED = [
        '🔥 المهام المتأخرة'          => '⛔ المهام المتوقفة',
        '📋 بنود خطة العمل المكتملة'  => '📋 بنود خطة العمل المنشورة',
        '🧾 عدد الفواتير غير المسددة' => '🧾 الفواتير المتأخرة',
        '🤝 العملاء النشطون'          => '🤝 العملاء الحاليون',
        '🎫 التذاكر المفتوحة'         => '🎫 التذاكر الجديدة',
        '⚖️ بنود الامتثال المحققة'    => '⚖️ بنود الامتثال الملتزمة',
        '🏭 الموردون النشطون'         => '🏭 عدد الموردين',
        '🖥️ السيرفرات النشطة'         => '🖥️ السيرفرات العاملة',
    ];

    public function handle(): int
    {
        // الإصلاحُ قبل البذر: وإلا وُلد الاسمُ الجديد بجانب المكسور فصارا اثنين
        $fixed = $this->option('repair')
            ? $this->repair(self::CORE) + $this->repair(self::MORE) : 0;

        $n = $this->seed(self::CORE);
        $extra = $this->option('all') ? $this->seed(self::MORE) : 0;

        $this->info("أُنشئ {$n} مؤشراً أساسياً" . ($this->option('all') ? " و{$extra} موسّعاً" : ''));
        if ($this->option('repair')) {
            $this->info("أُصلح {$fixed} مؤشراً كان يُرشِّح حالةً لا وجودَ لها في السجل");
        }
        if (! $this->option('all')) {
            $this->line('لمكتبةٍ أوسع (٢٥ مؤشراً إضافياً): php artisan hub:kpis-starter --all');
        }
        $this->line('تُعدَّل وتُحذف من شاشة «مؤشرات KPI» كأي مؤشرٍ يدوي.');

        return self::SUCCESS;
    }

    /**
     * إصلاحُ ما بُذر مكسوراً في تنصيبٍ قائم (WP-8.5).
     *
     * تصحيحُ الجدول أعلاه يحمي **التنصيبات الجديدة** وحدها: القديمةُ فيها
     * صفوفٌ محفوظةٌ تُرشِّح حالةً ميتة وتقرأ صفراً أبداً، والبذرُ يتخطّاها
     * بالاسم فلا يمسّها. وهذا المسارُ **اختياريٌّ صراحةً** (`--repair`) ولا
     * يمسّ إلا صفّاً اجتمع فيه شرطان: اسمُه اسمُ البذرة (قديمُه أو جديدُه)،
     * **وفلترُه ميّتٌ فعلاً** بميزان `KpiCentre::deadFilters`. فما عدّلتَه
     * بيدك إلى حالةٍ صحيحة لا يُمَسّ.
     */
    protected function repair(array $rows): int
    {
        $n = 0;
        $old = array_flip(self::RENAMED);          // الجديدُ ⟵ القديم

        foreach ($rows as [$name, $mod, $agg, $col, $st, $combine, $bMod, $bAgg, $bCol, $bSt, $unit, $target, $good]) {
            $names = array_values(array_unique(array_filter([$name, $old[$name] ?? null])));
            $k = KpiDef::whereIn('name', $names)->orderBy('id')->first();
            if (! $k) continue;

            $f = (array) $k->formula;
            // الوحدةُ نفسُها **والفلترُ ميّت**: وإلا فهو مؤشّرُ صاحبِ النظام لا بذرتُنا
            if (hub_str($f['a']['module'] ?? '') !== $mod) continue;
            if (! \App\Support\KpiCentre::deadFilters($f)) continue;

            $formula = ['a' => ['agg' => $agg, 'module' => $mod, 'col' => $col, 'st' => $st ?? ''],
                        'combine' => $combine];
            if ($combine !== 'none') {
                $formula['b'] = ['agg' => $bAgg, 'module' => $bMod, 'col' => $bCol, 'st' => $bSt ?? ''];
            }

            $before = ['name' => $k->name, 'formula' => hub_kpi_explain((array) $k->formula)];
            $k->update(['name' => $name, 'unit' => $unit, 'target' => $target,
                        'good' => $good, 'formula' => $formula]);
            hub_audit('إصلاح مؤشر KPI', null, $k->id, $name, ['before' => $before,
                'after' => ['name' => $name, 'formula' => hub_kpi_explain($formula)]]);
            $n++;
        }

        return $n;
    }

    /** لا يُنشئ مؤشراً لوحدةٍ غير مثبّتة، ولا يكرر اسماً موجوداً */
    protected function seed(array $rows): int
    {
        $sort = (int) KpiDef::max('sort');
        $n = 0;

        foreach ($rows as [$name, $mod, $agg, $col, $st, $combine, $bMod, $bAgg, $bCol, $bSt, $unit, $target, $good]) {
            if (KpiDef::where('name', $name)->exists()) continue;
            if (! $this->usable($mod, $agg, $col)) continue;
            if ($combine !== 'none' && ! $this->usable($bMod, $bAgg, $bCol)) continue;

            $formula = ['a' => ['agg' => $agg, 'module' => $mod, 'col' => $col, 'st' => $st ?? ''],
                        'combine' => $combine];
            if ($combine !== 'none') {
                $formula['b'] = ['agg' => $bAgg, 'module' => $bMod, 'col' => $bCol, 'st' => $bSt ?? ''];
            }

            KpiDef::create([
                'name' => $name, 'unit' => $unit, 'target' => $target,
                'good' => $good, 'formula' => $formula, 'sort' => ++$sort,
            ]);
            $n++;
        }

        return $n;
    }

    /** الوحدة موجودة، وعمود المجموع/المتوسط رقميٌّ معلَنٌ فيها */
    protected function usable(?string $module, string $agg, ?string $col): bool
    {
        $def = $module ? hub_mod($module) : null;
        if (! $def) return false;
        if (! \Illuminate\Support\Facades\Schema::hasTable($def['table'])) return false;
        if ($agg === 'count') return true;

        $f = collect($def['fields'])->firstWhere('col', $col) ?: collect($def['fields'])->firstWhere('key', $col);

        return $f && in_array($f['type'] ?? '', ['num', 'big'], true)
            && \Illuminate\Support\Facades\Schema::hasColumn($def['table'], $f['col']);
    }
}
