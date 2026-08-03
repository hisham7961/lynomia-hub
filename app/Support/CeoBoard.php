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
    public const TTL = 300;

    /** ما لا يمضي دون قرار المالك */
    public static function awaiting($user): array
    {
        return hub_screen('ceo:await', self::TTL, fn () => self::awaitingCalc($user),
            ['approvals', 'decisions', 'internal_requests']);
    }

    protected static function awaitingCalc($user): array
    {
        $out = [];

        // الحرّاس هنا لا في المتحكم وحده: أيُّ ودجةٍ أو موجزٍ يستدعي هذه القارئات
        // مستقبلاً يجب أن يجد البابَ مغلقاً، لا أن يعتمد على سطرٍ في شاشةٍ واحدة
        if (hub_read('approvals', $user)) {
            $pending = fn () => hub_open_scope(hub_read('approvals', $user),
                'status', ['موافق', 'موافقة', 'معتمد', 'معتمدة']);
            $n = $pending()->count();
            $old = $pending()->where('created_at', '<', now()->subDays(3))->count();
            if ($n) $out[] = ['icon' => '🔏', 'n' => $n, 'label' => 'موافقات معلّقة',
                'why' => $old ? "منها {$old} معلّقة أكثر من ٣ أيام — التأخير قرارٌ أيضاً وله ثمن." : 'بانتظار حسم.',
                'url' => route('m.index', 'approvals'), 'tone' => $old ? 'bad' : 'wn'];
        }

        if (hub_read('decisions', $user)) {
            $open = fn () => hub_read('decisions', $user)
                ->whereIn('status', ['لم يبدأ', 'قيد التنفيذ', 'متعثر']);
            $late = $open()->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            $stuck = $open()->where('status', 'متعثر')->count();
            // القرار المتعثر الذي فات موعده واحدٌ لا اثنان — الجمع كان يضاعفه
            $both = $open()->where('status', 'متعثر')
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            $n = $late + $stuck - $both;
            if ($n) $out[] = ['icon' => '🧭', 'n' => $n, 'label' => 'قرارات لم تُنفَّذ',
                'why' => ($late ? "{$late} تجاوزت موعدها" : '') . ($late && $stuck ? ' و' : '')
                    . ($stuck ? "{$stuck} متعثّرة" : '') . ' — قرارٌ لا يُنفَّذ يُعلّم الفريق أن القرارات اختيارية.',
                'url' => route('m.index', 'decisions'), 'tone' => 'bad'];
        }

        if ($rq = hub_read('requests', $user)) {
            $n = hub_open_scope($rq->where(fn ($w) => $w->whereNull('decision')->orWhere('decision', '')))->count();
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
        return hub_screen('ceo:leaks', self::TTL, fn () => self::leaksCalc(),
            ['fin_documents', 'projects', 'subscriptions']);
    }

    protected static function leaksCalc(): array
    {
        $out = [];
        $cur = setting('app.currency', 'د.ك');

        // مستحقات متقادمة: المتأخر عن استحقاقه فوق ٦٠ يوماً يقترب من كونه خسارة
        if ($fin = hub_read('fin')) {
            // ثلاثةُ تصحيحات: (١) النوع — كانت فواتير **المشتريات** تُحسب مستحقاتٍ
            // لنا؛ (٢) `total - NULL = NULL` فالفاتورة التي لم يُدفع منها شيء —
            // وهي أسوأها — كانت تسقط من المجموع؛ (٣) النطاق.
            $agedQ = $fin->whereIn('kind', (array) config('hub.fin.income', []) ?: ['فاتورة مبيعات'])
                ->whereIn('state', ['مرسلة', 'مدفوعة جزئياً', 'متأخرة'])
                ->whereNotNull('due')->where('due', '<', now()->subDays(60)->toDateString());
            // استنساخٌ قبل التجميع: `sum()` ينفّذ الاستعلام، ونريد العملات أيضاً
            $aged = (float) (clone $agedQ)->sum(DB::raw('total - COALESCE(paid, 0)'));
            if ($aged > 0) $out[] = ['icon' => '⏳', 'amount' => $aged,
                'label' => 'مستحقات متأخرة فوق ٦٠ يوماً',
                'why' => 'كلما طال التقادم قلّت فرصة التحصيل — هذا أقرب ما يكون إلى خسارة مؤجّلة.',
                'url' => route('m.index', 'fin'), 'tone' => 'bad']
                + self::curLabel((clone $agedQ)->distinct()->pluck('currency'), $cur);
        }

        // مشاريع تجاوزت ميزانيتها: الفرق هو النزف نفسه
        if ($pj = hub_read('projects')) {
            $over = hub_open_scope($pj)
                ->whereNotNull('budget')->where('budget', '>', 0)
                ->whereColumn('cost', '>', 'budget')
                ->get(['name', 'budget', 'cost', 'currency']);
            $gap = $over->sum(fn ($p) => (float) $p->cost - (float) $p->budget);
            if ($over->count()) $out[] = ['icon' => '📉', 'amount' => $gap,
                'label' => $over->count() . ' مشروع تجاوز ميزانيته',
                'why' => 'أكبرها: ' . ($over->sortByDesc(fn ($p) => $p->cost - $p->budget)->first()->name ?? '—'),
                'url' => route('m.index', 'projects'), 'tone' => 'bad']
                + self::curLabel($over->pluck('currency'), $cur);
        }

        // اشتراكات تتجدد تلقائياً خلال شهر: تُدفع بصمت ما لم تُراجَع قبل موعدها
        if ($sb = hub_read('subs')) {
            $subs = hub_open_scope($sb)
                ->whereNotNull('renew')
                ->whereBetween('renew', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->get(['service', 'amount', 'auto_renew', 'currency']);
            $auto = $subs->where('auto_renew', 1);
            if ($auto->count()) $out[] = ['icon' => '🔁', 'amount' => (float) $auto->sum('amount'),
                'label' => $auto->count() . ' اشتراك يتجدد تلقائياً خلال شهر',
                'why' => 'التجديد التلقائي يُدفع بلا قرار — راجع الحاجة قبل أن يُخصم.',
                'url' => route('m.index', 'subs'), 'tone' => 'wn']
                + self::curLabel($auto->pluck('currency'), $cur);
        }

        return $out;
    }

    /**
     * صدقُ اللصيقة: بطاقةٌ تجمع مبالغَ صفوفٍ قد تحمل عملاتٍ مختلفة. لا محرّكَ
     * تحويلٍ في النظام (`app.currency` تسميةٌ لا تحويل)، فإن اتّحدت عملاتُ
     * الصفوف عُنوِنت البطاقةُ بعملتها الحقيقية؛ وإن اختلفت رُفع علمُ `mixed`
     * كي يُقرأ الرقمُ المخلوط مؤشّراً لا رقماً دقيقاً — بدل عنونةِ كلِّ شيءٍ
     * بعملة النظام الافتراضية زوراً. القيمُ الفارغة تُنسَب للعملة الافتراضية.
     */
    protected static function curLabel($currencies, string $default): array
    {
        $set = collect($currencies)->map(fn ($c) => filled($c) ? (string) $c : $default)
            ->unique()->values();

        return ['cur' => $set->count() === 1 ? $set->first() : $default, 'mixed' => $set->count() > 1];
    }

    /**
     * التركّز: الخطر الذي يبدو نجاحاً.
     * عميلٌ واحد نصفُ إيرادك ليس «عميلاً كبيراً» — هو نصفُ شركتك في يد غيرك.
     */
    public static function concentration(): array
    {
        return hub_screen('ceo:conc', self::TTL, fn () => self::concentrationCalc(), ['fin_documents', 'clients']);
    }

    protected static function concentrationCalc(): array
    {
        if (! ($fin = hub_read('fin'))) return [];

        $income = (array) config('hub.fin.income', []);
        $dead = (array) config('hub.fin.dead', []);
        $rows = $fin->whereIn('kind', $income ?: ['فاتورة مبيعات'])
            // NOT IN تُسقط NULL صامتةً — ومستندٌ بلا حالة مستندٌ قائم
            ->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead ?: ['ملغاة']))
            ->where('date', '>=', now()->subMonths(12)->toDateString())
            ->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(total) as t'))
            ->groupBy('client_id')->orderByDesc('t')->get();

        $total = (float) $rows->sum('t');
        if ($total <= 0 || $rows->count() < 2) return [];

        $cq = hub_read('clients');
        $names = $cq ? $cq->whereIn('id', $rows->pluck('client_id'))->pluck('name', 'id') : collect();

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
        return hub_screen('ceo:risks', self::TTL, fn () => self::risksCalc(), ['issues', 'incidents']);
    }

    protected static function risksCalc(): array
    {
        $out = [];

        if ($iq = hub_read('issues')) {
            $rows = hub_open_scope($iq)
                ->orderByRaw("CASE severity WHEN 'حرجة' THEN 0 WHEN 'عالية' THEN 1 WHEN 'متوسطة' THEN 2 ELSE 3 END")
                ->limit(6)->get(['id', 'title', 'severity', 'status', 'found']);
            foreach ($rows as $r) {
                $age = $r->found ? (int) \Illuminate\Support\Carbon::parse($r->found)->diffInDays(now()) : null;
                $out[] = ['icon' => '⚠️', 'title' => $r->title, 'sev' => $r->severity ?: 'غير مصنّفة',
                    'status' => $r->status, 'age' => $age, 'module' => 'issues', 'id' => $r->id,
                    'tone' => in_array($r->severity, ['حرجة', 'عالية'], true) ? 'bad' : 'wn'];
            }
        }

        if ($nq = hub_read('incidents')) {
            $rows = hub_open_scope($nq)
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
        return hub_screen('ceo:gov', self::TTL, fn () => self::governanceCalc(),
            ['contracts', 'compliance_items', 'contract_obligations']);
    }

    protected static function governanceCalc(): array
    {
        $out = [];

        if ($cq = hub_read('contracts')) {
            $soon = hub_open_scope($cq)
                ->whereNotNull('date_end')
                ->whereBetween('date_end', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->count();
            if ($soon) $out[] = ['icon' => '📜', 'n' => $soon, 'label' => 'عقود تنتهي خلال ٦٠ يوماً',
                'why' => 'قرار التجديد يحتاج مهلةً تفاوضية — بعد الانتهاء لا تفاوض.',
                'url' => route('legal'), 'tone' => 'wn'];
        }

        if ($mq = hub_read('compliance')) {
            // «معفى» ليس متأخراً — شاشةُ الامتثال تستثنيه واللوحة كانت تعدّه
            $late = hub_open_scope($mq, 'status', ['معفى'])
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            if ($late) $out[] = ['icon' => '⚖️', 'n' => $late, 'label' => 'التزامات نظامية متأخرة',
                'why' => 'المخالفة النظامية تُغرَّم ولا تُناقَش — وهي أرخص ما يُعالَج قبل موعدها.',
                'url' => route('m.index', 'compliance'), 'tone' => 'bad'];
        }

        if ($oq = hub_read('obligations')) {
            $late = hub_open_scope($oq)
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
            if ($late) $out[] = ['icon' => '📑', 'n' => $late, 'label' => 'التزامات تعاقدية متأخرة',
                'why' => 'إخلالٌ بعقدٍ وقّعته الشركة — يُقاس بالغرامة أو بالسمعة.',
                'url' => route('legal'), 'tone' => 'bad'];
        }

        return $out;
    }
}
