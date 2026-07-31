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
    protected $signature = 'hub:kpis-starter {--all : أضف المكتبة الموسّعة أيضاً}';
    protected $description = 'إنشاء مكتبة مؤشرات KPI جاهزة محسوبة من بياناتك (لا يكرر الموجود بالاسم)';

    /**
     * [الاسم، الوحدة، التجميع، العمود، الحالة، الدمج، وحدة‌ب، تجميع‌ب، عمود‌ب، حالة‌ب، الوحدة، الهدف، الاتجاه]
     * combine: none | ratio_pct | ratio | diff | sum
     */
    protected const CORE = [
        // ── التسليم والمشاريع ──
        ['🚀 المشاريع النشطة', 'projects', 'count', null, 'قيد التنفيذ', 'none', null, null, null, null, 'مشروع', null, 'up'],
        ['✅ نسبة إنجاز المهام', 'tasks', 'count', null, 'منجزة', 'ratio_pct', 'tasks', 'count', null, '', '٪', 80, 'up'],
        ['🔥 المهام المتأخرة', 'tasks', 'count', null, 'متأخرة', 'none', null, null, null, null, 'مهمة', 0, 'down'],
        ['📋 بنود خطة العمل المكتملة', 'feats', 'count', null, 'مكتمل', 'ratio_pct', 'feats', 'count', null, '', '٪', 75, 'up'],

        // ── المالية ──
        ['💰 إجمالي الفواتير الصادرة', 'fin', 'sum', 'total', null, 'none', null, null, null, null, null, null, 'up'],
        ['💵 المحصّل فعلياً', 'fin', 'sum', 'paid', null, 'none', null, null, null, null, null, null, 'up'],
        ['📊 نسبة التحصيل', 'fin', 'sum', 'paid', null, 'ratio_pct', 'fin', 'sum', 'total', '', '٪', 90, 'up'],
        ['🧾 عدد الفواتير غير المسددة', 'fin', 'count', null, 'غير مدفوعة', 'none', null, null, null, null, 'فاتورة', 0, 'down'],
        ['💳 مصروفات المشتريات', 'purchases', 'sum', 'amount', null, 'none', null, null, null, null, null, null, 'down'],
        ['🔁 كلفة الاشتراكات الشهرية', 'subs', 'sum', 'amount', 'نشط', 'none', null, null, null, null, null, null, 'down'],

        // ── المبيعات ──
        ['🤝 العملاء النشطون', 'clients', 'count', null, 'نشط', 'none', null, null, null, null, 'عميل', null, 'up'],
        ['📄 عروض الأسعار المرسلة', 'quotes', 'count', null, 'مرسل', 'none', null, null, null, null, 'عرض', null, 'up'],
        ['🏆 نسبة قبول العروض', 'quotes', 'count', null, 'مقبول', 'ratio_pct', 'quotes', 'count', null, '', '٪', 40, 'up'],
        ['💼 قيمة العروض المقبولة', 'quotes', 'sum', 'total', 'مقبول', 'none', null, null, null, null, null, null, 'up'],

        // ── الدعم والتذاكر ──
        ['🎫 التذاكر المفتوحة', 'tickets', 'count', null, 'مفتوحة', 'none', null, null, null, null, 'تذكرة', 5, 'down'],
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
        ['⚖️ بنود الامتثال المحققة', 'compliance', 'count', null, 'مستوفى', 'ratio_pct', 'compliance', 'count', null, '', '٪', 100, 'up'],
        ['📝 القرارات المنفَّذة', 'decisions', 'count', null, 'منفّذ', 'ratio_pct', 'decisions', 'count', null, '', '٪', 90, 'up'],
    ];

    /** مكتبةٌ موسّعة — تُضاف بـ--all لمن يريد تغطيةً أعمق */
    protected const MORE = [
        ['💡 الأفكار المعتمدة', 'ideas', 'count', null, 'معتمدة', 'ratio_pct', 'ideas', 'count', null, '', '٪', 25, 'up'],
        ['🧱 الأصول قيد الاستخدام', 'assets', 'count', null, 'قيد الاستخدام', 'none', null, null, null, null, 'أصل', null, 'up'],
        ['📉 أصناف نفدت من المخزون', 'stock', 'count', null, 'نفد', 'none', null, null, null, null, 'صنف', 0, 'down'],
        ['🏭 الموردون النشطون', 'suppliers', 'count', null, 'نشط', 'none', null, null, null, null, 'مورّد', null, 'up'],
        ['📣 حسابات التواصل النشطة', 'social', 'count', null, 'نشط', 'none', null, null, null, null, 'حساب', null, 'up'],
        ['👍 إجمالي متابعي المنصات', 'social', 'sum', 'followers', null, 'none', null, null, null, null, 'متابع', null, 'up'],
        ['📝 المنشورات المنشورة', 'posts', 'count', null, 'منشور', 'none', null, null, null, null, 'منشور', null, 'up'],
        ['📱 التطبيقات المنشورة', 'apps', 'count', null, 'منشور', 'none', null, null, null, null, 'تطبيق', null, 'up'],
        ['⬇️ إجمالي تحميلات التطبيقات', 'apps', 'sum', 'downloads', null, 'none', null, null, null, null, 'تحميل', null, 'up'],
        ['⭐ متوسط تقييم التطبيقات', 'apps', 'avg', 'rating', null, 'none', null, null, null, null, '/5', 4.3, 'up'],
        ['🌐 المواقع العاملة', 'websites', 'count', null, 'يعمل', 'none', null, null, null, null, 'موقع', null, 'up'],
        ['🖥️ السيرفرات النشطة', 'servers', 'count', null, 'نشط', 'none', null, null, null, null, 'سيرفر', null, 'up'],
        ['💸 الكلفة الشهرية للسيرفرات', 'servers', 'sum', 'cost_month', null, 'none', null, null, null, null, null, null, 'down'],
        ['📚 مقالات المعرفة المنشورة', 'kb', 'count', null, 'منشور', 'none', null, null, null, null, 'مقال', null, 'up'],
        ['📨 الطلبات الداخلية المفتوحة', 'requests', 'count', null, 'جديد', 'none', null, null, null, null, 'طلب', 0, 'down'],
        ['🎨 مهام التصميم الجاهزة', 'designs', 'count', null, 'جاهز', 'ratio_pct', 'designs', 'count', null, '', '٪', 80, 'up'],
        ['🗓️ الاجتماعات المنعقدة', 'meetings', 'count', null, 'منعقد', 'none', null, null, null, null, 'اجتماع', null, 'up'],
        ['🎪 الفعاليات القادمة', 'events', 'count', null, 'مخطط', 'none', null, null, null, null, 'فعالية', null, 'up'],
        ['📰 الظهور الإعلامي', 'media', 'count', null, 'منشور', 'none', null, null, null, null, 'ظهور', null, 'up'],
        ['🏁 المنافسون المرتفعو التهديد', 'competitors', 'count', null, 'نشط', 'none', null, null, null, null, 'منافس', null, 'down'],
        ['🧩 الخدمات النشطة', 'services', 'count', null, 'نشطة', 'none', null, null, null, null, 'خدمة', null, 'up'],
        ['🔐 أسرار الخزنة', 'vault', 'count', null, null, 'none', null, null, null, null, 'سرّ', null, 'up'],
        ['📑 السياسات السارية', 'policies', 'count', null, 'سارية', 'none', null, null, null, null, 'سياسة', null, 'up'],
        ['🖊️ الإقرارات المكتملة', 'policyacks', 'count', null, 'مُقرّة', 'ratio_pct', 'policyacks', 'count', null, '', '٪', 100, 'up'],
        ['🎯 الأهداف على المسار', 'okrs', 'count', null, 'على المسار', 'ratio_pct', 'okrs', 'count', null, '', '٪', 70, 'up'],
    ];

    public function handle(): int
    {
        $n = $this->seed(self::CORE);
        $extra = $this->option('all') ? $this->seed(self::MORE) : 0;

        $this->info("أُنشئ {$n} مؤشراً أساسياً" . ($this->option('all') ? " و{$extra} موسّعاً" : ''));
        if (! $this->option('all')) {
            $this->line('لمكتبةٍ أوسع (٢٥ مؤشراً إضافياً): php artisan hub:kpis-starter --all');
        }
        $this->line('تُعدَّل وتُحذف من شاشة «مؤشرات KPI» كأي مؤشرٍ يدوي.');

        return self::SUCCESS;
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
