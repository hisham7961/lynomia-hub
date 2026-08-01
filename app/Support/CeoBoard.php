<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * لوحة CEO — طبقةُ القرار فوق طبقة الأرقام.
 *
 * كانت اللوحة **جدارَ أرقام**: صافي الشهر، عدد العملاء، مهام مفتوحة… كلها
 * صحيحة وكلها **لا تطلب شيئاً**. والمالك يقرؤها فيخرج بانطباعٍ لا بقرار،
 * لأنها لا تجيب الأسئلة الثلاثة التي تُقرأ لوحةٌ تنفيذية من أجلها:
 *
 *   ١) ما الذي **ينتظر قراري أنا** ولا يمضي بدونه؟
 *   ٢) أين **ينزف المال** بلا أن ينتبه أحد؟
 *   ٣) ما **الخطر المركَّز** الذي يبدو عادياً حتى يقع؟
 *
 * والأرقام هنا كلها من مصادرها القائمة — لا جدول جديد ولا حقلٌ يُطلب ملؤه.
 */
class CeoBoard
{
    /** ما لا يمضي دون قرار المالك */
    public static function awaiting($user): array
    {
        $out = [];

        if (Schema::hasTable('approvals')) {
            $n = hub_open_scope(DB::table('approvals')->whereNull('deleted_at'),
                    'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة'])->count();
            $old = hub_open_scope(DB::table('approvals')->whereNull('deleted_at'),
                    'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة'])
                ->where('created_at', '<', now()->subDays(3))->count();
            if ($n) $out[] = ['icon' => '🔏', 'n' => $n, 'label' => 'موافقات معلّقة',
                'why' => $old ? "منها {$old} معلّقة أكثر من ٣ أيام — التأخير قرارٌ أيضاً وله ثمن." : 'بانتظار حسم.',
                'url' => route('m.index', 'approvals'), 'tone' => $old ? 'bad' : 'wn'];
        }

        if (Schema::hasTable('decisions')) {
            $late = DB::table('decisions')->whereNull('deleted_at')
                ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر'])
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            $stuck = DB::table('decisions')->whereNull('deleted_at')->where('status', 'متعثر')->count();
            if ($late || $stuck) $out[] = ['icon' => '🧭', 'n' => $late + $stuck, 'label' => 'قرارات لم تُنفَّذ',
                'why' => ($late ? "{$late} تجاوزت موعدها" : '') . ($late && $stuck ? ' و' : '')
                    . ($stuck ? "{$stuck} متعثّرة" : '') . ' — قرارٌ لا يُنفَّذ يُعلّم الفريق أن القرارات اختيارية.',
                'url' => route('m.index', 'decisions'), 'tone' => 'bad'];
        }

        if (Schema::hasTable('internal_requests')) {
            $n = hub_open_scope(DB::table('internal_requests')->whereNull('deleted_at')
                    ->where(fn ($w) => $w->whereNull('decision')->orWhere('decision', '')))->count();
            if ($n) $out[] = ['icon' => '📨', 'n' => $n, 'label' => 'طلبات داخلية بلا بتّ',
                'why' => 'الطلب المعلّق يُستهلك مرتين: مرة انتظاراً ومرة تذكيراً.',
                'url' => route('m.index', 'requests'), 'tone' => 'wn'];
        }

        return $out;
    }

    /**
     * التسرّب: مالٌ يخرج أو لا يدخل بلا أن ينتبه أحد.
     * كلٌّ منها رقمٌ بالعملة — لا «عدد سجلات» يُقرأ ولا يُحرّك.
     */
    public static function leaks(): array
    {
        $out = [];
        $cur = setting('app.currency', 'د.ك');

        // مستحقات متقادمة: المتأخر عن استحقاقه فوق ٦٠ يوماً يقترب من كونه خسارة
        if (Schema::hasTable('fin_documents')) {
            $aged = (float) DB::table('fin_documents')->whereNull('deleted_at')
                ->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
                ->whereNotNull('due')->where('due', '<', now()->subDays(60)->toDateString())
                ->sum(DB::raw('total - paid'));
            if ($aged > 0) $out[] = ['icon' => '⏳', 'amount' => $aged, 'cur' => $cur,
                'label' => 'مستحقات متأخرة فوق ٦٠ يوماً',
                'why' => 'كلما طال التقادم قلّت فرصة التحصيل — هذا أقرب ما يكون إلى خسارة مؤجّلة.',
                'url' => route('m.index', 'fin'), 'tone' => 'bad'];
        }

        // مشاريع تجاوزت ميزانيتها: الفرق هو النزف نفسه
        if (Schema::hasTable('projects')) {
            $over = hub_open_scope(DB::table('projects')->whereNull('deleted_at'))
                ->whereNotNull('budget')->where('budget', '>', 0)
                ->whereColumn('cost', '>', 'budget')
                ->get(['name', 'budget', 'cost']);
            $gap = $over->sum(fn ($p) => (float) $p->cost - (float) $p->budget);
            if ($over->count()) $out[] = ['icon' => '📉', 'amount' => $gap, 'cur' => $cur,
                'label' => $over->count() . ' مشروع تجاوز ميزانيته',
                'why' => 'أكبرها: ' . ($over->sortByDesc(fn ($p) => $p->cost - $p->budget)->first()->name ?? '—'),
                'url' => route('m.index', 'projects'), 'tone' => 'bad'];
        }

        // اشتراكات تتجدد تلقائياً خلال شهر: تُدفع بصمت ما لم تُراجَع قبل موعدها
        if (Schema::hasTable('subscriptions')) {
            $subs = hub_open_scope(DB::table('subscriptions')->whereNull('deleted_at'))
                ->whereNotNull('renew')
                ->whereBetween('renew', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->get(['service', 'amount', 'auto_renew']);
            $auto = $subs->where('auto_renew', 1);
            if ($auto->count()) $out[] = ['icon' => '🔁', 'amount' => (float) $auto->sum('amount'), 'cur' => $cur,
                'label' => $auto->count() . ' اشتراك يتجدد تلقائياً خلال شهر',
                'why' => 'التجديد التلقائي يُدفع بلا قرار — راجع الحاجة قبل أن يُخصم.',
                'url' => route('m.index', 'subs'), 'tone' => 'wn'];
        }

        return $out;
    }

    /**
     * التركّز: الخطر الذي يبدو نجاحاً.
     * عميلٌ واحد نصفُ إيرادك ليس «عميلاً كبيراً» — هو نصفُ شركتك في يد غيرك.
     */
    public static function concentration(): array
    {
        if (! Schema::hasTable('fin_documents')) return [];

        $income = (array) config('hub.fin.income', []);
        $dead = (array) config('hub.fin.dead', []);
        $rows = DB::table('fin_documents')->whereNull('deleted_at')
            ->whereIn('kind', $income ?: ['فاتورة'])->whereNotIn('state', $dead ?: ['ملغاة'])
            ->where('date', '>=', now()->subMonths(12)->toDateString())
            ->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(total) as t'))
            ->groupBy('client_id')->orderByDesc('t')->get();

        $total = (float) $rows->sum('t');
        if ($total <= 0 || $rows->count() < 2) return [];

        $names = Schema::hasTable('clients')
            ? DB::table('clients')->whereIn('id', $rows->pluck('client_id'))->pluck('name', 'id')
            : collect();

        $top = $rows->take(5)->map(fn ($r) => [
            'name' => $names[$r->client_id] ?? 'عميل محذوف',
            'amount' => (float) $r->t, 'pct' => (int) round($r->t / $total * 100),
        ])->all();

        $first = $top[0]['pct'] ?? 0;
        $top3 = collect($top)->take(3)->sum('pct');

        return ['top' => $top, 'total' => $total, 'n' => $rows->count(),
            'firstPct' => $first, 'top3Pct' => $top3,
            'tone' => $first >= 40 ? 'bad' : ($first >= 25 ? 'wn' : 'ok'),
            'verdict' => $first >= 40
                ? "عميلٌ واحد {$first}٪ من إيراد سنة — فقدانه ليس تراجعاً، هو أزمة سيولة."
                : ($first >= 25
                    ? "أكبر عميل {$first}٪ من الإيراد — راقب، وابدأ بتنويعٍ مقصود."
                    : "التوزيع صحّي: أكبر عميل {$first}٪ فقط."),
        ];
    }

    /** مخاطر مفتوحة: مشاكل وحوادث لم تُغلق، بترتيب الشدّة */
    public static function risks(): array
    {
        $out = [];

        if (Schema::hasTable('issues')) {
            $rows = hub_open_scope(DB::table('issues')->whereNull('deleted_at'))
                ->orderByRaw("CASE severity WHEN 'حرجة' THEN 0 WHEN 'عالية' THEN 1 WHEN 'متوسطة' THEN 2 ELSE 3 END")
                ->limit(6)->get(['id', 'title', 'severity', 'status', 'found']);
            foreach ($rows as $r) {
                $age = $r->found ? (int) \Illuminate\Support\Carbon::parse($r->found)->diffInDays(now()) : null;
                $out[] = ['icon' => '⚠️', 'title' => $r->title, 'sev' => $r->severity ?: 'غير مصنّفة',
                    'status' => $r->status, 'age' => $age, 'module' => 'issues', 'id' => $r->id,
                    'tone' => in_array($r->severity, ['حرجة', 'عالية'], true) ? 'bad' : 'wn'];
            }
        }

        if (Schema::hasTable('incidents')) {
            $rows = hub_open_scope(DB::table('incidents')->whereNull('deleted_at'))
                ->limit(4)->get(['id', 'title', 'severity', 'status', 'started_at']);
            foreach ($rows as $r) {
                $age = $r->started_at ? (int) \Illuminate\Support\Carbon::parse($r->started_at)->diffInDays(now()) : null;
                $out[] = ['icon' => '🚨', 'title' => $r->title, 'sev' => $r->severity ?: 'غير مصنّف',
                    'status' => $r->status, 'age' => $age, 'module' => 'incidents', 'id' => $r->id,
                    'tone' => 'bad'];
            }
        }

        return $out;
    }

    /** الاتجاه: هل الشهر أفضل من الذي قبله؟ رقمٌ بلا اتجاه لا يُقرَّر عليه */
    public static function trend(array $months): array
    {
        $n = count($months);
        if ($n < 2) return [];

        $net = fn ($m) => (float) $m['i'] - (float) $m['e'];
        $now = $net($months[$n - 1]);
        $prev = $net($months[$n - 2]);
        $delta = $now - $prev;
        $pct = $prev != 0.0 ? (int) round($delta / abs($prev) * 100) : null;

        // متوسط الأشهر السابقة كخطّ أساس — شهرٌ واحد قد يكون صدفة
        $base = collect(array_slice($months, 0, $n - 1))->avg($net);

        return [
            'now' => $now, 'prev' => $prev, 'delta' => $delta, 'pct' => $pct,
            'dir' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'vsBase' => $base !== null ? $now - (float) $base : null,
            'verdict' => $delta >= 0
                ? 'الشهر أفضل من الذي قبله' . ($pct !== null ? " بـ{$pct}٪" : '')
                : 'الشهر أضعف من الذي قبله' . ($pct !== null ? ' بـ' . abs($pct) . '٪' : ''),
        ];
    }

    /** الحوكمة: ما يقع على المالك ولو لم يكن مالكه — عقودٌ وامتثال */
    public static function governance(): array
    {
        $out = [];

        if (Schema::hasTable('contracts')) {
            $soon = hub_open_scope(DB::table('contracts')->whereNull('deleted_at'))
                ->whereNotNull('date_end')
                ->whereBetween('date_end', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->count();
            if ($soon) $out[] = ['icon' => '📜', 'n' => $soon, 'label' => 'عقود تنتهي خلال ٦٠ يوماً',
                'why' => 'قرار التجديد يحتاج مهلةً تفاوضية — بعد الانتهاء لا تفاوض.',
                'url' => route('legal'), 'tone' => 'wn'];
        }

        if (Schema::hasTable('compliance_items')) {
            $late = hub_open_scope(DB::table('compliance_items')->whereNull('deleted_at'))
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            if ($late) $out[] = ['icon' => '⚖️', 'n' => $late, 'label' => 'التزامات نظامية متأخرة',
                'why' => 'المخالفة النظامية تُغرَّم ولا تُناقَش — وهي أرخص ما يُعالَج قبل موعدها.',
                'url' => route('m.index', 'compliance'), 'tone' => 'bad'];
        }

        if (Schema::hasTable('contract_obligations')) {
            $late = hub_open_scope(DB::table('contract_obligations')->whereNull('deleted_at'))
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            if ($late) $out[] = ['icon' => '📑', 'n' => $late, 'label' => 'التزامات تعاقدية متأخرة',
                'why' => 'إخلالٌ بعقدٍ وقّعته الشركة — يُقاس بالغرامة أو بالسمعة.',
                'url' => route('legal'), 'tone' => 'bad'];
        }

        return $out;
    }
}
