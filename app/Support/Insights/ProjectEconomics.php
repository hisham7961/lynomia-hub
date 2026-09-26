<?php

namespace App\Support\Insights;

/**
 * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها
 * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.
 */
final class ProjectEconomics
{
    /**
     * التكلفة الفعلية وربحية مشروع واحد — بخمس دلاء تكلفة صريحة:
     *   التكلفة المسجَّلة يدويّاً · ساعات الفريق · السيرفرات · الأدوات والاشتراكات · الخدمات الخارجية
     * مقابل الإيراد المفوتر والمحصّل، ثم الربح والهامش وتكلفة التأخير.
     *
     * كل رقم قابل للتفسير: الدوال تعيد مصادرها (ساعات، عدد مستندات، أشهر التشغيل)
     * حتى لا يواجه المالك رقماً لا يعرف من أين جاء.
     *
     * **العيبُ المُغلَق (الجولة 3 · V1): `projects.cost` كان لا يُقرأ إطلاقاً.**
     * الحقلُ مسجَّلٌ في `config/hub.php` باسم «التكلفة الفعلية»، ويقرؤه محرّكٌ آخرُ
     * حيٌّ (`CeoBoard::leaks` — «مشروع تجاوز ميزانيته»)، ومع ذلك كانت هذه الدالّةُ
     * تشتقّ التكلفةَ من الساعاتِ والدورياتِ وحدَها. فسبعةُ مشاريعَ في قاعدةِ العرض
     * تحمل تكلفةً مسجَّلة (بوّابة الخليج 21,000 · مستشفى السلام 16,500 · أفق 9,800 …)
     * كانت كلُّها تُحسَب **بتكلفةٍ صفر** لأن `act_h` فارغةٌ في كلّ مهامّها — ومن
     * الصفرِ وُلد أخطرُ الأثر: «الالتزام بالميزانية» في صحّةِ التسليم يمنح 100
     * (0٪ مستهلك) فيُصبَغ مشروعٌ متأخّرٌ عاجلٌ بـ«سليم».
     *
     * **قاعدةُ الجمعِ المعلَنة — ولمَ لا ازدواجَ صامت:** المسجَّلةُ يدويّاً تُعامَل
     * **تكلفةً مباشرةً مستقلّة** تُضاف إلى المشتقّات لا تحلّ محلَّها. ولهذا حجّتُه:
     * كلُّ دلوٍ مشتقٍّ له صفوفُه المرجعيّة (مهمّةٌ · سيرفرٌ · اشتراكٌ · أمرُ شراء)
     * تُطابَق وتُراجَع، أمّا الحقلُ اليدويُّ فلا صفَّ خلفه يُطابَق به — فلا سبيلَ
     * إلى طرحِ ما يتقاطع منه. وإسقاطُه (ما كان يجري) يُتلف رقماً سجّله المالكُ
     * بيده، واستبدالُه بالمشتقّات يُتلف عملاً محسوباً من صفوفٍ حقيقيّة. فالجمعُ
     * هو الخيارُ الوحيدُ الذي لا يُتلف بياناً — **على أن يُعلَن التقاطعُ لا يُخفى**:
     * حين تجتمع المسجَّلةُ والمشتقّةُ يُرفع `cost.overlap` (كعَلَمِ `mixed` للعملات)
     * فيقرأ القارئُ أن الرقمَ قد يحتوي عمالةً مرّتين، بدل مجموعٍ يبدو دقيقاً وهو
     * مشكوكٌ فيه. والمخرَجُ يبيّن **تركيبَ الرقم** مكوّناً مكوّناً (`cost.components`)
     * ويسمّي الغائبَ صراحةً (`cost.missing`) بدل صفرٍ صامتٍ يُقرأ «لا تكلفة».
     */
    public static function pl(string $projectId, bool $fresh = false): array
    {
        $key = "pl:$projectId";
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $DB = \Illuminate\Support\Facades\DB::class;
            $p = \Illuminate\Support\Facades\DB::table('projects')->where('id', $projectId)->first();
            if (! $p) return [];

            // ── مدة التشغيل بالأشهر (لتطبيع التكاليف الدورية) ──
            $start = $p->start_date ? \Illuminate\Support\Carbon::parse($p->start_date) : null;
            $end   = $p->launch_act ? \Illuminate\Support\Carbon::parse($p->launch_act) : now();
            $days  = $start ? max(1, $start->diffInDays($end)) : 30;
            $months = max(1, round($days / 30, 2));

            // ── ١) ساعات الفريق ──
            $rt = hub_hourly_rates();
            $byUser = \Illuminate\Support\Facades\DB::table('tasks')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotNull('act_h')->where('act_h', '>', 0)
                ->selectRaw('assignee_id, SUM(act_h) h')->groupBy('assignee_id')->get();

            $hoursCost = 0.0; $hoursTotal = 0.0;
            foreach ($byUser as $r) {
                $h = (float) $r->h;
                $hoursTotal += $h;
                $hoursCost += $h * ($rt['rates'][$r->assignee_id] ?? $rt['avg']);
            }

            // ── ٢) السيرفرات (تكلفة الدورة مطبّعة شهرياً × أشهر التشغيل) ──
            $norm = fn ($amount, $cycle) => match ((string) $cycle) {
                'سنوي' => (float) $amount / 12,
                'ربع سنوي' => (float) $amount / 3,
                'نصف سنوي' => (float) $amount / 6,
                'مرة واحدة' => 0.0,          // تُحتسب كاملةً خارج الدورية أدناه
                default => (float) $amount,   // شهري
            };
            $oneOff = fn ($amount, $cycle) => (string) $cycle === 'مرة واحدة' ? (float) $amount : 0.0;

            $servers = \Illuminate\Support\Facades\DB::table('servers')
                ->whereNull('deleted_at')->where('project_id', $projectId)->get(['cost', 'cycle', 'currency']);
            // **كلُّ مكوّنٍ يحتفظ بعملتِه حتّى لحظةِ الطيّ** (v2.542): الطيُّ
            // الفوريُّ في `float` واحدٍ يُتلف العملةَ فلا يبقى ما يُحوَّل به
            $serverRows = $servers->map(fn ($s) => ['amount' => $norm($s->cost, $s->cycle) * $months + $oneOff($s->cost, $s->cycle),
                                                    'currency' => $s->currency, 'date' => null])->all();

            // ── ٣) الأدوات والاشتراكات ──
            // الملغى/المنتهي لا يُحمَّل على كامل عمر المشروع — كما hub_service_costs
            $subs = \Illuminate\Support\Facades\DB::table('subscriptions')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['ملغي', 'منتهي']))
                ->get(['amount', 'cycle', 'currency']);
            $toolRows = $subs->map(fn ($s) => ['amount' => $norm($s->amount, $s->cycle) * $months + $oneOff($s->amount, $s->cycle),
                                               'currency' => $s->currency, 'date' => null])->all();

            // ── ٤) الخدمات الخارجية: مشتريات + مصروفات مالية مرتبطة بالمشروع ──
            // المصروف بتعريفه المعتمد config('hub.fin.expense') لا نوع «مصروف» وحده،
            // والحالات الميتة (ملغاة/مسودة) خارج الحساب — كسائر التقارير
            $finDoc = fn () => hub_fin_not_dead(\Illuminate\Support\Facades\DB::table('fin_documents')
                ->whereNull('deleted_at')->where('project_id', $projectId));
            // العدّ المزدوج: زرُّ «فاتورة المورد» يولّد من أمر الشراء مستندَ مالية
            // نوعُه «فاتورة مشتريات» (أحدُ أنواع المصروف)، فيُضاف `meta.bill_id` للأمر.
            // فبلا استثنائه يُحتسب المبلغُ مرّتين: من صفّ الشراء ومن فاتورته. والحالاتُ
            // الميتة (مسودة/ملغى/مرتجع) ليست تكلفةً كسائر التقارير. `whereNull('meta->bill_id')`
            // تعمل على المحرّكين (json_extract يُعيد NULL حين لا مفتاح).
            $conn = \Illuminate\Support\Facades\DB::connection();
            $ymP = hub_ym_expr($conn, 'date');
            $shapeYm = fn ($rows) => collect($rows)->map(fn ($r) => ['amount' => (float) $r->t,
                'currency' => $r->currency, 'date' => hub_ym_date($r->ym)])->all();

            $purchRows = $shapeYm(\Illuminate\Support\Facades\DB::table('purchases')
                ->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNull('meta->bill_id')
                ->whereNotIn('status', (array) config('hub.purchases.dead', ['مسودة', 'ملغى', 'مرتجع']))
                ->select('currency', $DB::raw("{$ymP} as ym"), $DB::raw('COALESCE(SUM(amount),0) as t'))
                ->groupBy('currency')->groupBy($DB::raw($ymP))->get());
            $expenseRows = $shapeYm($finDoc()
                ->whereIn('kind', (array) config('hub.fin.expense', ['مصروف']))
                ->select('currency', $DB::raw("{$ymP} as ym"), $DB::raw('COALESCE(SUM(total),0) as t'))
                ->groupBy('currency')->groupBy($DB::raw($ymP))->get());
            $externalRows = array_merge($purchRows, $expenseRows);

            // ── ٥) التكلفةُ المسجَّلةُ يدويّاً على المشروع (`projects.cost`) ──
            // لا استعلامَ جديد: الصفُّ مقروءٌ أصلاً أعلاه. و`null` ليست كـ`0`:
            // الأولى «لم يُسجَّل شيء» والثانية «سُجِّل صفراً» — والتمييزُ هو ما
            // يمنع صحّةَ التسليم من مكافأة الجهل بدرجةٍ كاملة (انظر hub_project_health).
            $directRec  = $p->cost !== null;
            $directCost = (float) ($p->cost ?? 0);

            // ── الإيراد: مفوتر ومحصّل ──
            // كان الشرط kind='فاتورة' — نوعٌ لا يكتبه النظام أصلاً (الحقيقي «فاتورة
            // مبيعات» من QuoteController وخيارات الوحدة) فإيراد كل مشروع حقيقي = صفر.
            // COALESCE(paid,0) داخل الجمع: فاتورة لم يُدفع منها شيء paid=NULL
            // كانت تُفسد المجموع لا تُصفَّر.
            $incKinds = (array) config('hub.fin.income', ['فاتورة مبيعات', 'دفعة واردة']);

            /*
             * **صدقُ العملة داخل المشروع الواحد**: `fin_documents.currency` حقلٌ
             * مكشوفٌ بستّة خيارات، فمشروعٌ واحدٌ قد تحمل فواتيرُه عملتين — والربحُ
             * والهامشُ يُبنيان على هذا الإيراد. فيُفصَّل بالعملةِ **وبالشهر**:
             * الفصلُ بالعملةِ لصدقِ اللصيقة، وبالشهرِ كي يُحوَّل كلُّ شهرٍ بسعرِه.
             *
             * واستعلامٌ واحدٌ يُغني عن ثلاثة: المفوترُ والمحصَّلُ والعدد.
             */
            $incRows = $finDoc()->whereIn('kind', $incKinds)
                ->select('currency', $DB::raw("{$ymP} as ym"),
                    $DB::raw('COALESCE(SUM(total),0) as t'),
                    $DB::raw('COALESCE(SUM(COALESCE(paid,0)),0) as p'),
                    $DB::raw('COUNT(*) as n'))
                ->groupBy('currency')->groupBy($DB::raw($ymP))->get();

            // «دفعة واردة» محصَّلة بطبيعتها: total هو المبلغ الواصل وإن لم يُملأ paid
            $payRows = $shapeYm($finDoc()->where('kind', 'دفعة واردة')->whereNull('paid')
                ->select('currency', $DB::raw("{$ymP} as ym"), $DB::raw('COALESCE(SUM(total),0) as t'))
                ->groupBy('currency')->groupBy($DB::raw($ymP))->get());

            $defCur = (string) ($p->currency ?: setting('app.currency', 'د.ك'));
            $revRows = $incRows->map(fn ($r) => ['amount' => (float) $r->t,
                'currency' => $r->currency, 'date' => hub_ym_date($r->ym)])->all();
            $paidRows = $incRows->map(fn ($r) => ['amount' => (float) $r->p,
                'currency' => $r->currency, 'date' => hub_ym_date($r->ym)])->all();
            /*
             * **مكوّنٌ بلا بيانٍ لا يُفبرَك له صفّ — والصفرُ لا يُخالط.**
             *
             * مشروعٌ عملتُه المُعلَنةُ درهمٌ ولا تكلفةَ مسجَّلةً فيه ولا فاتورة:
             * صفٌّ بمبلغِ صفرٍ يحمل الدرهمَ كان يُخالطه بعملةِ نظامٍ أخرى **بلا
             * مالٍ أصلاً**، فتُطبع شارةُ اختلاطٍ حمراءُ على مشروعٍ فارغ. وعلمُ
             * اختلاطٍ كاذبٌ ليس زينةً: يُعلّم القارئَ تجاهلَ التحذيرات.
             *
             * و`$directRec` هي التمييزُ القائمُ نفسُه بين «سُجِّل صفراً» و«لم
             * يُسجَّل» — المستعمَلُ في `has` أدناه.
             */
            $directRows = $directRec ? [['amount' => $directCost, 'currency' => $p->currency, 'date' => null]] : [];
            // أسعارُ الساعةِ إعدادٌ بعملةِ النظام — لا عمودَ عملةٍ خلفها
            $hourRows = $hoursTotal > 0
                ? [['amount' => $hoursCost, 'currency' => (string) setting('app.currency', 'د.ك'), 'date' => null]]
                : [];
            $costRows = array_merge($directRows, $hourRows, $serverRows, $toolRows, $externalRows);

            /*
             * **الربحُ فرقُ رقمين، فإمّا يُحوَّل الطرفان وإمّا لا يُحوَّل شيء**
             * (v2.542 · F-15).
             *
             * إيرادُ مشروعٍ ألفُ دولارٍ وتكلفتُه مئةُ دينار: تحويلُ الإيرادِ
             * وحدَه يطبع ربحاً أسوأَ من الحقيقةِ ثلاثَ مرّات — **ورقمٌ يبدو
             * دقيقاً وهو خطأٌ أسوأُ من رقمٍ موسومٍ «مخلوط»**. فالشرطُ ثلاثيّ:
             * سعرٌ مسجَّلٌ أصلاً، وعملةٌ واحدةٌ على الأقلّ تخالف الأساس (وإلّا
             * فلا تحويلَ حدث)، **وكلُّ** زوجٍ في الطرفين له سعرٌ في تاريخه.
             *
             * وتخلّفُ أيِّ شرطٍ يُعيد كلَّ رقمٍ إلى خامِه — لا نصفَ تحويل.
             */
            $curSet = array_unique(array_map(fn ($r) => filled($r['currency'] ?? null)
                ? (string) $r['currency'] : $defCur, array_merge($costRows, $revRows)));
            $foreign = (bool) array_diff($curSet, [\App\Support\Finance\Currency::base()]);
            $revBase = $foreign ? hub_money_base_total($revRows, 'amount', 'currency', 'date', $defCur) : null;
            $costBase = $foreign ? hub_money_base_total($costRows, 'amount', 'currency', 'date', $defCur) : null;
            $plConv = \App\Support\Finance\Currency::enabled() && $foreign
                && $revBase !== null && $costBase !== null;

            /** يطوي مكوّناً: محوَّلاً بعملةِ الأساسِ حين حُوِّل الطرفان، وخاماً عداه */
            $fold = function (array $rows) use ($plConv, $defCur): float {
                if ($plConv) return (float) (hub_money_base_total($rows, 'amount', 'currency', 'date', $defCur) ?? 0.0);

                return (float) array_sum(array_column($rows, 'amount'));
            };

            $directCost  = $fold($directRows);
            $hoursCost   = $fold($hourRows);
            $serverCost  = $fold($serverRows);
            $toolCost    = $fold($toolRows);
            $externalCost = $fold($externalRows);
            $revenue     = $fold($revRows);
            $collected   = $fold($paidRows) + $fold($payRows);

            // اللصيقةُ: محوَّلةٌ بعملةِ الأساس، أو المساعدُ القديمُ حرفاً بحرف.
            // **والاختلاطُ يُقاس على الطرفين** — مشروعٌ إيرادُه بعملةٍ وتكلفتُه
            // بأخرى مخلوطٌ وإن اتّحدت فواتيرُه، وكان العلمُ يُقرأ من الإيرادِ وحدَه.
            $plLabel = $plConv
                ? ['cur' => \App\Support\Finance\Currency::base(), 'mixed' => false]
                : hub_cur_label(array_column(array_merge($revRows, $costRows), 'currency'), $defCur);

            $byCurrency = collect($incRows)
                ->map(fn ($r) => ['currency' => filled($r->currency) ? (string) $r->currency : $defCur,
                                  'revenue' => round((float) $r->t, 2), 'docs' => (int) $r->n])
                ->groupBy('currency')
                ->map(fn ($g, $c) => ['currency' => $c, 'revenue' => round($g->sum('revenue'), 2),
                                      'docs' => (int) $g->sum('docs')])
                ->sortByDesc('revenue')->values()->all();
            $invN = (int) $incRows->sum('n');

            // الجمعُ الخماسيُّ بقاعدته المعلَنة أعلاه — المسجَّلةُ يدويّاً مصدرٌ
            // خامسٌ مستقلٌّ لا بديلٌ عن المشتقّات (عيبُ الجولة 3 · V1)
            $totalCost = $directCost + $hoursCost + $serverCost + $toolCost + $externalCost;
            $profit    = $revenue - $totalCost;

            /*
             * **تركيبُ الرقم مكشوفاً**: لكلِّ مكوّنٍ قيمتُه و«هل له بيانٌ أصلاً».
             * صفرٌ صامتٌ يُقرأ «لا تكلفة» وهو في الحقيقة «لا تسجيل» — والفرقُ بينهما
             * هو الفرقُ بين مشروعٍ مربحٍ ومشروعٍ مجهول. `has` مبنيّةٌ على وجودِ
             * الصفوف/التسجيل لا على كون المبلغ موجباً (اشتراكٌ بصفرٍ مسجَّلٌ بيانٌ،
             * وغيابُ الاشتراكاتِ كلِّها ليس بياناً).
             */
            $components = [
                ['k' => 'direct',   'label' => 'التكلفة المسجَّلة يدويّاً', 'v' => round($directCost, 2),   'has' => $directRec],
                ['k' => 'hours',    'label' => 'ساعات الفريق',            'v' => round($hoursCost, 2),    'has' => $hoursTotal > 0],
                ['k' => 'servers',  'label' => 'السيرفرات',               'v' => round($serverCost, 2),   'has' => $servers->isNotEmpty()],
                ['k' => 'tools',    'label' => 'الأدوات والاشتراكات',     'v' => round($toolCost, 2),     'has' => $subs->isNotEmpty()],
                ['k' => 'external', 'label' => 'الخدمات الخارجية',        'v' => round($externalCost, 2), 'has' => $externalCost != 0.0],
            ];
            $missing   = array_values(array_map(fn ($c) => $c['label'],
                array_filter($components, fn ($c) => ! $c['has'])));
            $costKnown = (bool) array_filter($components, fn ($c) => $c['has']);
            // عَلَمُ التقاطع: مسجَّلةٌ يدويّاً **و**مشتقّةٌ معاً — الرقمُ قد يحتوي
            // العمالةَ مرّتين. يُعلَن ولا يُطرَح (لا صفَّ خلف الحقلِ اليدويّ يُطابَق به)
            $overlap = $directRec && $directCost != 0.0
                && ($hoursCost + $serverCost + $toolCost + $externalCost) != 0.0;

            // ── تكلفة التأخير: أيام التأخر × متوسط الحرق اليومي ──
            $delayDays = 0;
            if ($p->launch_exp) {
                $exp = \Illuminate\Support\Carbon::parse($p->launch_exp);
                $act = $p->launch_act ? \Illuminate\Support\Carbon::parse($p->launch_act) : now();
                if ($act->gt($exp)) $delayDays = (int) $exp->diffInDays($act);
            }
            $burn = $totalCost / max(1, $days);

            return [
                'project'  => $p->name,
                // اللصيقةُ الحقيقية حين تتّحد عملاتُ الفواتير، والافتراضيةُ عند
                // الاختلاط مع رفع `mixed` — لا عنونةُ كلِّ شيءٍ بعملة النظام زوراً
                'currency' => $plLabel['cur'],
                'mixed'    => $plLabel['mixed'],
                // **المحوَّلُ يُعلَن لا يُقدَّم أصليّاً** (v2.542)
                'converted' => $plConv,
                'byCurrency' => $byCurrency,
                'months'   => $months, 'days' => $days,
                'revenue'  => ['invoiced' => round($revenue, 2), 'collected' => round($collected, 2),
                               'docs' => $invN,
                               'uncollected' => round($revenue - $collected, 2)],
                // المفاتيحُ القديمةُ باقيةٌ كما هي (الإضافةُ لا الكسر)، و«direct»
                // و«known»/«components»/«missing»/«overlap» تُضاف بجانبها
                'cost'     => ['hours' => round($hoursCost, 2), 'servers' => round($serverCost, 2),
                               'tools' => round($toolCost, 2), 'external' => round($externalCost, 2),
                               'direct' => round($directCost, 2), 'recorded' => $directRec,
                               'total' => round($totalCost, 2),
                               'known' => $costKnown, 'components' => $components,
                               'missing' => $missing, 'overlap' => $overlap],
                'hours'    => ['logged' => round($hoursTotal, 1), 'people' => $byUser->count(),
                               'avg_rate' => $rt['avg']],
                'profit'   => round($profit, 2),
                'margin'   => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'burn_day' => round($burn, 2),
                'delay'    => ['days' => $delayDays, 'cost' => round($delayDays * $burn, 2)],
                'budget'   => $p->budget !== null ? (float) $p->budget : null,
                'over'     => $p->budget ? round($totalCost - (float) $p->budget, 2) : null,
            ];
        });
    }

    /**
     * صحة المشروع: ستة عوامل من بيانات حقيقية، كل عامل ٠–١٠٠ بوزن معلن.
     * لا نخترع «رضا العميل» ولا «استقرار الفريق» لأن لا مصدر لهما في النظام —
     * ذكر عامل بلا بيانات يعطي رقماً كاذباً، والصراحة أنفع من لوحة جميلة.
     *
     * والمبدأُ نفسُه يسري على عاملٍ **له** مصدرٌ غائبُ البيان: عاملٌ بلا بيانٍ
     * يُستبعَد (وزنُه صفر ويقول ملاحظُه سببَه) وتُسوّى بقيةُ الأوزان على ١٠٠ —
     * لا يُمنح 100 مجّاناً. **هذه الدالّةُ مصدرُ رقمِ الصحّةِ الوحيد** لكل شاشة:
     * صفحةُ المشروع ولوحةُ `/delivery/psa` تقرآنها عبر `hub_project_health_for`
     * (المُرشِّحِ بعينِ القارئ) فلا يختلف رقمان على المشروع الواحد.
     */
    public static function health(string $projectId, bool $fresh = false): array
    {
        $key = "health:$projectId";
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $DB = \Illuminate\Support\Facades\DB::class;
            $p = \Illuminate\Support\Facades\DB::table('projects')->where('id', $projectId)->first();
            if (! $p) return [];

            $pl = hub_project_pl($projectId);
            $f = [];

            // ١) الالتزام بالموعد — منحنى صريح لا مجامل (الجولة 1 · F12): كان
            // `100−2×أيام` يمنح مشروعاً متأخراً خمسة أيام 90/100 «سليم» (وكيلا
            // المحاكاة 2 و4). النقطة الآن 5 لكل يوم، و8 للمشروع العاجل — فالتأخّر
            // في عاجلٍ أفدح بحكم أولويّته المعلنة.
            $delay = $pl['delay']['days'] ?? 0;
            $urgent = in_array(trim((string) ($p->priority ?? '')), ['عاجلة', 'عاجل'], true);
            $f[] = ['k' => 'الالتزام بالموعد', 'w' => 25,
                    's' => $delay <= 0 ? 100 : max(0, 100 - $delay * ($urgent ? 8 : 5)),
                    'note' => $delay > 0 ? "متأخر {$delay} يوماً" . ($urgent ? ' — مشروع عاجل' : '') : 'ضمن الموعد'];

            /*
             * ٢) الالتزام بالميزانية — **لا تُكافأ الجهلُ بدرجةٍ كاملة** (الجولة 3 · V1).
             * كان العاملُ يمنح 100 في حالتين لا بيانَ في أيٍّ منهما: مشروعٌ بلا
             * ميزانيةٍ معتمدة، ومشروعٌ بميزانيةٍ وتكلفةٍ مجهولةٍ تُقرأ صفراً (فيقال
             * «0٪ مستهلك» ويُمنح 100). ومن هنا صار مشروعٌ متأخّرٌ عاجلٌ «81 · سليم»:
             * خُمسُ الدرجةِ هبةٌ لغيابِ البيان. والآن: لا بيانَ ⇒ لا درجة — يُستبعَد
             * العاملُ (وزنُه صفر) وتُسوّى بقيةُ الأوزان على 100، ويقول الملاحظُ سببَه.
             */
            $bw = 20; $bs = 100;
            $hasBudget = ! empty($pl['budget']) && $pl['budget'] > 0;
            $costKnown = (bool) ($pl['cost']['known'] ?? false);
            if ($hasBudget && $costKnown) {
                $used = $pl['cost']['total'] / $pl['budget'] * 100;
                $bs = $used <= 100 ? 100 : max(0, (int) (100 - ($used - 100) * 2));
                $bn = round($used) . '٪ من الميزانية مستهلك';
            } else {
                $bw = 0; $bs = 0;
                $bn = (! $hasBudget ? 'لا ميزانية معتمدة' : 'لا بيانَ تكلفة')
                    . ' — العاملُ مستبعَدٌ والأوزانُ مُسوّاة على ١٠٠';
            }
            $f[] = ['k' => 'الالتزام بالميزانية', 'w' => $bw, 's' => $bs, 'note' => $bn];

            // ٣) المهام المتأخرة
            $tAll = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')->where('project_id', $projectId)->count();
            // **السلطةُ الواحدةُ لـ«مفتوحة»** (مجلس الخبراء · A-1): كانت قائمةً
            // حرفيّةً، و`NULL NOT IN (…)` **لا يصدُق في SQL** — فمهمّةٌ بلا حالةٍ
            // (والعمودُ يقبل الفراغَ والنموذجُ العامُّ يعرض خياراً فارغاً) تسقط من
            // عدّادِ المتأخّر بصمت: «٠ متأخرة من ١٢» ومهامُّ المشروعِ كلُّها فائتة.
            // و`hub_open_scope` يعالج الفراغَ صراحةً ويوسّع القائمةَ بالحالاتِ
            // المصرَّحِ بها في السجلّ — فالصحّةُ تقرأ ما يقرؤه بقيّةُ النظام.
            $tLate = hub_open_scope(
                \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')->where('project_id', $projectId)
                    ->whereNotNull('due')->whereDate('due', '<', today())
            )->count();
            $f[] = ['k' => 'انضباط المهام', 'w' => 20,
                    's' => $tAll ? max(0, (int) (100 - $tLate / $tAll * 200)) : 100,
                    'note' => $tAll ? "{$tLate} متأخرة من {$tAll}" : 'لا مهام مسجَّلة'];

            // ٤) المخاطر المفتوحة — موزونةً بالخطورة: كان العدّ يساوي بين خطرٍ
            // حرج وآخر منخفض، فمشروعٌ بخمسة أخطار تافهة يبدو أسوأ من واحدٍ قاتل
            $risks = \Illuminate\Support\Facades\DB::table('issues')->whereNull('deleted_at')
                ->where('project_id', $projectId)
                ->whereNotIn('status', ['مغلقة', 'محلولة', 'ملغاة'])->pluck('severity');
            $weights = ['حرجة' => 30, 'حرج' => 30, 'عالية' => 15, 'متوسطة' => 8, 'منخفضة' => 3];
            $penalty = 0;
            foreach ($risks as $sev) $penalty += $weights[trim((string) $sev)] ?? 10;
            $iss = $risks->count();
            $crit = $risks->filter(fn ($s) => in_array(trim((string) $s), ['حرجة', 'حرج'], true))->count();
            $f[] = ['k' => 'المخاطر المفتوحة', 'w' => 15,
                    's' => max(0, 100 - $penalty),
                    'note' => $iss ? "{$iss} مخاطرة مفتوحة" . ($crit ? " (منها {$crit} حرجة)" : '') : 'لا مخاطر مفتوحة'];

            // ٥) الأعطال التقنية (٩٠ يوماً)
            $inc = \Illuminate\Support\Facades\Schema::hasTable('incidents')
                ? \Illuminate\Support\Facades\DB::table('incidents')->whereNull('deleted_at')->where('project_id', $projectId)
                    ->where('created_at', '>=', now()->subDays(90))->count() : 0;
            $f[] = ['k' => 'استقرار التشغيل', 'w' => 10,
                    's' => max(0, 100 - $inc * 20), 'note' => $inc ? "{$inc} حادث خلال ٩٠ يوماً" : 'بلا أعطال مسجَّلة'];

            // ٦) عبء الدعم المفتوح
            $tk = \Illuminate\Support\Facades\DB::table('tickets')->whereNull('deleted_at')->where('project_id', $projectId)
                ->whereNotIn('status', ['تم الحل', 'مغلقة'])->count();
            $f[] = ['k' => 'عبء الدعم', 'w' => 10,
                    's' => max(0, 100 - $tk * 10), 'note' => $tk ? "{$tk} تذكرة مفتوحة" : 'لا تذاكر مفتوحة'];

            /*
             * تسويةُ الأوزان على ١٠٠ حين يُستبعَد عاملٌ لغيابِ بيانه — نمطُ
             * `hub_project_health_for` نفسُه (لا ثانيَ له): القارئُ يرى أوزاناً
             * مجموعُها ١٠٠ فلا يحار أين ذهب الخُمس، والدرجةُ تُستخرَج من الجدول
             * المعروضِ نفسِه فيقدر على إعادة حسابها بيده. والتسويةُ **حياديّة**:
             * لا تُعلي الدرجةَ ولا تخفضها، إنّما تُعيد توزيعَ وزنِ المجهولِ على
             * ما لنا به بيان. (وهي عاطلةٌ حين تجمع الأوزانُ ١٠٠ أصلاً.)
             */
            $wsum = array_sum(array_map(fn ($x) => (int) $x['w'], $f)) ?: 1;
            if ($wsum !== 100) {
                $f = array_map(function ($x) use ($wsum) {
                    $x['w'] = (int) $x['w'] > 0 ? (int) round($x['w'] * 100 / $wsum) : 0;
                    return $x;
                }, $f);
                $wshown = array_sum(array_column($f, 'w'));
                foreach ($f as $i => $x) {
                    if ($x['w'] > 0) { $f[$i]['w'] += 100 - $wshown; break; }   // فتاتُ التقريب لأول عامل
                }
            }

            $score = (int) round(array_sum(array_map(fn ($x) => $x['s'] * $x['w'], $f)) / 100);

            /*
             * الحالةُ التشغيليّة فوق الحساب (الجولة 1 · F12): مشروعٌ «متوقف» كان
             * يخرج «94 · سليم» لأن التوقّف لا يولّد تأخّراً ولا تذاكرَ — والعوامل
             * كلُّها هادئةٌ هدوءَ الموتى. المتوقّفُ لا يُقاس سليماً: سقفُ درجته 60
             * ولقبُه يقول حالته.
             */
            $paused = hub_project_is_paused($p->status ?? null);
            $cap = null;
            if ($paused) {
                /*
                 * **والسقفُ الواحدُ سوّى بين حالتين لا تستويان** (محاكاةُ الشهر ·
                 * قرارُ المالك). مشروعٌ أُوقف الأسبوعَ الماضي بقرارٍ واعٍ ومهامُّه
                 * مغلقة **مرتَّبٌ بانتظار قرار**، و٦٠ وصفٌ عادلٌ له. ومشروعٌ أُوقف
                 * قبل ثمانيةِ أشهرٍ وتُرك — مهامُّه فائتةٌ مفتوحة وخطرُه الحرجُ لم
                 * يُغلق — **يتعفّن**، و٦٠ نفسُها كانت تقول عنه ما تقول عن الأوّل.
                 * فيقف في صفِّ الترتيبِ بجانبِه ولا يُنادى أحدُهما قبل الآخر.
                 *
                 * فالسقفُ يهبط الآن بشيئين يُقاسان لا يُقدَّران: **طولُ السكون**
                 * (من أثرِ التدقيق) و**ما تراكم من ديونٍ مفتوحةٍ أثناءه**. وكلُّ
                 * حدٍّ مسقوفٌ بنفسه فلا يبتلع عاملٌ البقيّة، والأرضيّةُ ١٠ فلا
                 * يسقط المتروكُ إلى صفرٍ يُساوي المنهار.
                 */
                $since = hub_project_paused_since($projectId, $p->updated_at ?? null);
                $months = intdiv($since['days'], 30);

                $dAge  = min(30, 5 * max(0, $months - 1));   // شهرُ سماحٍ ثمّ خمسٌ لكلِّ شهر
                $dTask = min(20, 4 * $tLate);
                $dCrit = min(15, 5 * $crit);
                $dTkt  = min(10, 2 * $tk);

                $cap = max(10, 60 - $dAge - $dTask - $dCrit - $dTkt);
                $score = min($score, $cap);
                $aged = $dAge > 0;                          // أطال السكونُ وحدَه؟ (للّقب أدناه)

                $age = $months >= 12 ? 'منذ أكثر من سنة'
                     : ($months >= 1 ? 'منذ ' . $months . ($months === 1 ? ' شهر' : ' أشهر')
                     : ($since['days'] >= 1 ? 'منذ ' . $since['days'] . ' يوماً' : 'اليوم'));

                $debts = [];
                if ($tLate) $debts[] = "{$tLate} مهمّة فائتة";
                if ($crit)  $debts[] = "{$crit} خطر حرج مفتوح";
                if ($tk)    $debts[] = "{$tk} تذكرة مفتوحة";

                $f[] = ['k' => 'الحالة التشغيلية', 'w' => 0, 's' => 0,
                        'note' => 'المشروع متوقف ' . $age
                            . ($since['exact'] ? '' : ' على الأقل (لا أثرَ لتاريخِ التوقّف)')
                            . ($debts ? ' · تراكم أثناء التوقّف: ' . implode('، ', $debts)
                                      : ' — لا ديونَ مفتوحة، الهدوءُ هنا ترتيبٌ لا إهمال')
                            . ' · سقفُ الدرجة ' . $cap];
            }

            /*
             * لقبُ المتوقّفِ يصف حالتَه لا سقفَه وحدَه: «بانتظار قرار» للمرتَّبِ
             * الذي لم يهبط سقفُه، ثم «بدأ يتراكم»، ثم «متروكٌ يتعفّن» بنبرةٍ حمراء
             * — فالقارئُ يفرزها بالعينِ قبل أن يقرأ رقماً.
             */
            $rot = $paused ? 60 - (int) $cap : 0;
            /*
             * واللقبُ يسمّي **سببَ** الهبوط لا مقدارَه وحدَه: «متروك» تصف طولَ
             * السكون، فمشروعٌ أُوقف أمسِ وعليه خمسُ مهامَّ فائتةٍ ليس متروكاً —
             * هو **متوقّفٌ بديونٍ مفتوحة**، وذاك نداءٌ آخرُ لفعلٍ آخر.
             */
            $pLabel = $rot === 0 ? 'متوقف — بانتظار قرار'
                    : (empty($aged) ? 'متوقف — بديونٍ مفتوحة'
                    : ($rot <= 15 ? 'متوقف — بدأ يتراكم' : 'متوقف — متروكٌ يتعفّن'));

            return ['score' => $score, 'factors' => $f, 'paused' => $paused, 'cap' => $cap,
                    'tone' => $paused ? ($rot > 15 ? 'bad' : 'wn')
                        : ($score >= 80 ? 'ok' : ($score >= 55 ? 'wn' : 'bad')),
                    'label' => $paused ? $pLabel
                        : ($score >= 80 ? 'سليم' : ($score >= 55 ? 'يحتاج انتباهاً' : 'متعثر'))];
        });
    }

    /**
     * القدرات والاستغلال لكل موظف خلال فترة:
     *   المتاح = (أيام العمل − أيام الإجازة المعتمدة) × ساعات اليوم
     *   المحجوز = مجموع الساعات المقدَّرة لمهامه المفتوحة المستحقة في الفترة
     *   المسجَّل = ساعاته الفعلية (من المهام والحضور)
     * الحمل = المحجوز ÷ المتاح · الاستغلال = المسجَّل ÷ المتاح · والاختناق حمل > ١٠٠٪.
     */
    public static function capacity(?string $from = null, ?string $to = null, ?string $projectId = null): array
    {
        // **تاريخٌ حرٌّ من الرابط لا يُسقط الشاشة** (v2.325): `Carbon::parse` على
        // نصٍّ لا يُفهَم ترمي `InvalidFormatException` غيرَ ملتقطة — ٥٠٠ على
        // مسارٍ مصادَق بمعاملٍ يتحكّم به الطالب. ما لا يُفهَم يرتدّ للافتراضي.
        $safe = function ($v, \Closure $default) {
            $v = hub_str($v);
            if ($v === '') return $default();
            try {
                return \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable $e) {
                return $default();
            }
        };
        $f = $safe($from, fn () => now()->startOfMonth())->startOfDay();
        $t = $safe($to, fn () => now()->endOfMonth())->endOfDay();
        // ومدىً معكوسٌ أو مفرطُ الطول يُقوَّم: حلقةُ الأيام أدناه تُحسب يوماً بيوم
        if ($t->lt($f)) [$f, $t] = [$t->copy()->startOfDay(), $f->copy()->endOfDay()];
        if ($f->diffInDays($t) > 732) $t = $f->copy()->addDays(732)->endOfDay();

        $hoursDay = max(1, (int) setting('cost.work_hours', 8));
        $workDays = hub_workdays($f, $t);
        $DB = \Illuminate\Support\Facades\DB::class;

        // صلاحيّةُ وحدةِ الموارد تُقرأ مرّةً — يُبنى عليها حجبُ الاسمِ أدناه (L5-03)
        $mayName = auth()->user() === null || hub_can(auth()->user(), 'hr', 'v');

        $emps = \Illuminate\Support\Facades\DB::table('employees')->whereNull('deleted_at')
            ->whereNotIn('status', ['منتهية خدمته', 'مستقيل', 'موقوف'])
            ->orderBy('name')->limit(300)->get(['id', 'name', 'dept', 'user_id']);
        // تحت العدسة: الموظف ينتمي للمشروع **بعمله فيه** لا بعمود project_id على
        // ملفه (وهو شبه فارغ دائماً — الموظف ليس ملكاً لمشروع). ترشيحه بالعمود
        // كان سيُفرغ اللوحة كلها ويعرض أصفاراً تبدو حقيقةً لا فراغَ بيانات.
        if ($projectId) {
            $onProject = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
                ->where('project_id', $projectId)->whereNotNull('assignee_id')
                ->distinct()->pluck('assignee_id')->all();
            $emps = $emps->filter(fn ($e) => in_array($e->user_id, $onProject, true))->values();
        }

        if ($emps->isEmpty()) return ['rows' => [], 'from' => $f->toDateString(), 'to' => $t->toDateString(),
                                      'workDays' => $workDays, 'hoursDay' => $hoursDay,
                                      'lensed' => (bool) $projectId, 'totals' => []];

        $userIds = $emps->pluck('user_id')->filter()->all();
        $empIds  = $emps->pluck('id')->all();

        // إجازات معتمدة متقاطعة مع الفترة — «معتمد» هي قيمة السجل المعلنة
        // (كانت «معتمدة» فلا تُخصم إجازة واحدة من الطاقة أبداً)
        //
        // **وتصفيةُ النوعِ لازمة** (مجلس الخبراء · A-3): كان الاستعلامُ بلا أيِّ
        // تصفيةِ نوعٍ إطلاقاً، فطلبُ «سلفة» أو «شهادة راتب» معتمدٌ **يخصم أيّامَ
        // عملٍ من طاقةِ الموظّف** — والطلبُ الإداريُّ لا يُغيّب أحداً عن مكتبه.
        // وهذا عينُ العيبِ الذي أُغلق في لوحةِ المالكِ بـv2.499.0 وقد نجا هنا:
        // أُغلق المثالُ ولم يُغلق الصنف. والمصدرُ الواحد `deduct_types` هو نفسُه
        // الذي يقرؤه `Workday::onLeave` — فالطاقةُ والحضورُ يقولان قولاً واحداً.
        // («عمل عن بعد» و«إذن خروج» **لا** يُخصمان: صاحبُهما يعمل.)
        //
        // و`date_to` الفارغُ يُحتسب بـ`COALESCE` كما في حارسِ التداخل في
        // `LeaveRequest` — صفوفٌ قديمةٌ بهذا الشكل واردةٌ ويعترف بها النموذجُ
        // صراحةً، وكانت الطاقةُ وحدَها لا تدافع عنها.
        $leaves = \Illuminate\Support\Facades\DB::table('leave_requests')->whereNull('deleted_at')
            ->where('status', 'معتمد')->whereIn('emp_id', $empIds)
            ->whereIn('type', (array) config('hub.leave.deduct_types', []))
            ->whereRaw('DATE(COALESCE(date_from, date_to)) <= ?', [$t->toDateString()])
            ->whereRaw('DATE(COALESCE(date_to, date_from)) >= ?', [$f->toDateString()])
            ->get(['emp_id', 'date_from', 'date_to']);
        $leaveDays = [];
        foreach ($leaves as $l) {
            $a = \Illuminate\Support\Carbon::parse($l->date_from)->max($f);
            $b = \Illuminate\Support\Carbon::parse($l->date_to)->min($t);
            $leaveDays[$l->emp_id] = ($leaveDays[$l->emp_id] ?? 0) + hub_workdays($a, $b);
        }

        $open = ['منجزة', 'مكتملة', 'ملغاة'];

        // المحجوز: مهام مفتوحة مستحقة في الفترة (أو بلا موعد — تُحتسب على الفترة الحالية)
        $booked = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)
            ->where(fn ($q) => $q->whereNull('due')->orWhereBetween('due', [$f->toDateString(), $t->toDateString()]))
            ->selectRaw('assignee_id, COALESCE(SUM(est_h),0) h, COUNT(*) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        // المسجَّل: ساعات فعلية على مهام حُدِّثت داخل الفترة
        $logged = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotNull('act_h')
            ->whereBetween('updated_at', [$f, $t])
            ->selectRaw('assignee_id, COALESCE(SUM(act_h),0) h')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        // حضور مسجَّل داخل الفترة (مصدر أدق حين يُستخدم)
        $att = \Illuminate\Support\Facades\DB::table('attendance')->whereNull('deleted_at')
            ->whereIn('emp_id', $empIds)->whereBetween('date', [$f->toDateString(), $t->toDateString()])
            ->selectRaw('emp_id, COALESCE(SUM(hours),0) h')->groupBy('emp_id')->get()->keyBy('emp_id');

        // مشاريع مُسندة
        $projects = $userIds ? \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('assignee_id', $userIds)->whereNotIn('status', $open)->whereNotNull('project_id')
            ->selectRaw('assignee_id, COUNT(DISTINCT project_id) n')
            ->groupBy('assignee_id')->get()->keyBy('assignee_id') : collect();

        $rows = [];
        foreach ($emps as $e) {
            $lv  = (int) ($leaveDays[$e->id] ?? 0);
            $avail = max(0, ($workDays - $lv) * $hoursDay);
            $bk  = (float) ($booked[$e->user_id]->h ?? 0);
            // بلا عدسة: أدقّ المصدرين. وتحتها: ساعات المهام وحدها — بصمة الحضور
            // لا تُنسب لمشروع، ونسبتها إليه تعطي رقماً خاطئاً يبدو معقولاً.
            $lg  = $projectId ? (float) ($logged[$e->user_id]->h ?? 0)
                              : max((float) ($logged[$e->user_id]->h ?? 0), (float) ($att[$e->id]->h ?? 0));

            /*
             * **الاسمُ يُحجب عمّن لا يرى وحدتَه — والرقمُ يبقى** (L5-03 · v2.557).
             *
             * بوّابةُ اللوحةِ رايةُ `opsAnalytics`، وهي **لا تعني** رؤيةَ ملفّاتِ
             * الموارد: نظيرتُها `field.dashboard` تشترط `hr:v` **مع** الراية.
             * فحاملُ الرايةِ بلا `hr:v` كان يقرأ أسماءَ الموظّفين كاملةً ويُصَدُّ
             * ٤٠٣ عند فتحِ أيٍّ منهم — نقضاً لثابتِ «لا رابطٌ يظهر ثم يُصَدّ».
             *
             * والحجبُ **لا يُفرّغ اللوحة**: الطاقةُ والحملُ والاستغلالُ والإجازاتُ
             * تبقى كما هي — وهي غايةُ اللوحةِ — ويسقط **التعريفُ** وحدَه. فيبقى
             * تحليلُ الطاقةِ ممكناً لمن يحلّلها، ولا تُفشى هويّةُ من لا يراه.
             */
            $rows[] = [
                'id' => $mayName ? $e->id : null,
                'name' => $mayName ? $e->name : 'موظّفٌ #' . (count($rows) + 1),
                'dept' => $e->dept,
                'leaveDays' => $lv, 'available' => $avail,
                'booked' => round($bk, 1), 'logged' => round($lg, 1),
                'tasks' => (int) ($booked[$e->user_id]->n ?? 0),
                'projects' => (int) ($projects[$e->user_id]->n ?? 0),
                'load' => $avail > 0 ? (int) round($bk / $avail * 100) : null,
                'util' => $avail > 0 ? (int) round($lg / $avail * 100) : null,
                'linked' => (bool) $e->user_id,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['load'] ?? -1) <=> ($a['load'] ?? -1));

        return [
            'rows' => $rows, 'from' => $f->toDateString(), 'to' => $t->toDateString(),
            'workDays' => $workDays, 'hoursDay' => $hoursDay,
            'lensed' => (bool) $projectId,
            'totals' => [
                'available' => array_sum(array_column($rows, 'available')),
                'booked'    => round(array_sum(array_column($rows, 'booked')), 1),
                'logged'    => round(array_sum(array_column($rows, 'logged')), 1),
                'over'      => count(array_filter($rows, fn ($r) => ($r['load'] ?? 0) > 100)),
                // «بلا شغل» يُقاس بطاقة الموظف الكاملة، وتحت العدسة ينكمش المحجوز
                // وحده — فيُقال «الفريق عاطل» وهو محترقٌ على مشاريع أخرى. إنذارٌ
                // كاذب يقود لقرار توظيفٍ خاطئ، فيُسكَت تحت العدسة عمداً.
                'idle'      => $projectId ? 0
                    : count(array_filter($rows, fn ($r) => $r['available'] > 0 && ($r['load'] ?? 0) < 50)),
                'unlinked'  => count(array_filter($rows, fn ($r) => ! $r['linked'])),
            ],
        ];
    }

    /**
     * تحليل تكلفة الخدمات: لكل خدمة نشطة سعرها الشهري المكافئ مقابل كلفتها
     * الشهرية الحقيقية = المعلنة + حصتها من سيرفرها (مقسومة على الخدمات
     * المتشاركة فيه) + دومينها (سنوي ÷ ١٢) + اشتراكات الأدوات المطابقة بالاسم.
     * ولكل باقة: هامشها من تكلفتها التقديرية وعمر آخر مراجعة سعر.
     */
    public static function serviceCosts(bool $fresh = false): array
    {
        if ($fresh) \Illuminate\Support\Facades\Cache::forget('svc:costs');

        return \Illuminate\Support\Facades\Cache::remember('svc:costs', 300, function () {
            $db = \Illuminate\Support\Facades\DB::class;
            $norm = fn (?string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));

            $services = $db::table('services')->whereNull('deleted_at')
                ->whereNotIn('status', hub_closed_states())->get();
            $servers = $db::table('servers')->whereNull('deleted_at')->get(['id', 'cost', 'cycle'])->keyBy('id');
            $domains = $db::table('domains')->whereNull('deleted_at')->get(['id', 'cost'])->keyBy('id');
            $subs = $db::table('subscriptions')->whereNull('deleted_at')
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['ملغي', 'منتهي']))
                ->get(['service', 'amount', 'cycle']);

            // كم خدمة تتشارك السيرفر نفسه؟ حصة كلٍّ = كلفته ÷ عددها
            $sharing = $services->whereNotNull('server_id')->countBy('server_id');

            $rows = [];
            foreach ($services as $s) {
                $priceM = hub_cycle_monthly($s->price, $s->cycle);
                $declared = $s->cost !== null ? (float) $s->cost : null;   // «تكلفة التشغيل الشهرية» شهرية أصلاً

                $serverM = null; $serverShared = 0;
                if ($s->server_id && ($sv = $servers[$s->server_id] ?? null) && $sv->cost !== null) {
                    $serverShared = (int) ($sharing[$s->server_id] ?? 1);
                    $whole = hub_cycle_monthly($sv->cost, $sv->cycle);
                    $serverM = $whole !== null ? $whole / max(1, $serverShared) : null;
                }

                // الدومين: كلفة التسجيل سنوية بطبيعتها → ÷ ١٢
                $domainM = ($s->domain_id && ($d = $domains[$s->domain_id] ?? null) && $d->cost !== null)
                    ? (float) $d->cost / 12 : null;

                // أدوات مطابقة بالاسم (يحوي أو يُحوى)
                $tools = 0.0; $toolNames = [];
                $sn = $norm($s->name);
                if ($sn !== '') {
                    foreach ($subs as $sub) {
                        $bn = $norm($sub->service);
                        if ($bn === '' || ($bn !== $sn && ! str_contains($bn, $sn) && ! str_contains($sn, $bn))) continue;
                        $m = hub_cycle_monthly($sub->amount, $sub->cycle);
                        if ($m !== null) { $tools += $m; $toolNames[] = trim((string) $sub->service); }
                    }
                }

                $parts = array_filter([$declared, $serverM, $domainM, $tools ?: null], fn ($v) => $v !== null);
                $costM = $parts ? round(array_sum($parts), 3) : null;
                $margin = ($priceM !== null && $costM !== null) ? round($priceM - $costM, 3) : null;

                $rows[] = [
                    'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'status' => $s->status,
                    'cycle' => $s->cycle, 'price' => $s->price !== null ? (float) $s->price : null,
                    'priceM' => $priceM !== null ? round($priceM, 3) : null,
                    'declared' => $declared,
                    'serverM' => $serverM !== null ? round($serverM, 3) : null, 'serverShared' => $serverShared,
                    'domainM' => $domainM !== null ? round($domainM, 3) : null,
                    'toolsM' => $tools ? round($tools, 3) : null, 'toolNames' => array_values(array_unique($toolNames)),
                    'costM' => $costM, 'margin' => $margin,
                    'marginPct' => ($margin !== null && $priceM > 0) ? (int) round($margin / $priceM * 100) : null,
                ];
            }

            // الباقات: الهامش من التكلفة التقديرية وعمر السعر
            $plans = $db::table('pricing_plans')->whereNull('deleted_at')
                ->whereNotIn('status', hub_closed_states())->get();
            $svcNames = $services->pluck('name', 'id');
            $planRows = [];
            foreach ($plans as $p) {
                $margin = ($p->price !== null && $p->unit_cost !== null) ? (float) $p->price - (float) $p->unit_cost : null;
                $ageFrom = $p->price_changed_at ?: $p->effective_from;
                $planRows[] = [
                    'id' => $p->id, 'name' => $p->name, 'service' => $svcNames[$p->service_id] ?? null,
                    'price' => $p->price !== null ? (float) $p->price : null, 'cycle' => $p->cycle,
                    'currency' => $p->currency, 'unitCost' => $p->unit_cost !== null ? (float) $p->unit_cost : null,
                    'free' => (bool) $p->free_tier, 'margin' => $margin,
                    'marginPct' => ($margin !== null && (float) $p->price > 0) ? (int) round($margin / (float) $p->price * 100) : null,
                    'priceAgeDays' => $ageFrom ? (int) \Illuminate\Support\Carbon::parse($ageFrom)->diffInDays(now()) : null,
                ];
            }

            $withM = collect($rows)->filter(fn ($r) => $r['margin'] !== null);

            return [
                'rows' => collect($rows)->sortBy([['margin', 'asc']])->values()->all(),
                'plans' => collect($planRows)->sortBy([['margin', 'asc']])->values()->all(),
                'totals' => [
                    'revenueM' => round((float) collect($rows)->sum(fn ($r) => $r['priceM'] ?? 0), 2),
                    'costM' => round((float) collect($rows)->sum(fn ($r) => $r['costM'] ?? 0), 2),
                    'underwater' => $withM->where('margin', '<', 0)->count(),
                    'unpriced' => collect($rows)->whereNull('priceM')->count(),
                    'uncosted' => collect($rows)->whereNull('costM')->count(),
                    'plansUnder' => collect($planRows)->filter(fn ($p) => $p['margin'] !== null && $p['margin'] < 0 && ! $p['free'])->count(),
                ],
            ];
        });
    }

    /**
     * الإيراد الشهري المتكرر (MRR) من العقود السارية — أثمن رقمٍ تجاري لم يكن
     * قابلاً للقياس أصلاً: `hub_service_costs` كان يقيس هامشاً نظرياً من السعر
     * المُعلن لا من عقدٍ مُوقَّع. قيمة كل عقد تُطبَّع شهرياً حسب دورة باقته أو
     * خدمته (سنوي ÷ ١٢، ربع سنوي ÷ ٣)، و«مرة واحدة» لا تدخل التكرار.
     * التوزيع بالخدمة يجعل «أي خدمة تحمل إيرادنا؟» سؤالاً له جواب.
     */
    public static function mrr(bool $fresh = false): array
    {
        // **مفتاحٌ يحمل بصمةَ القارئ لا مفتاحٌ عامّ** (v2.317): `rev:mrr` كان
        // واحداً للجميع، فقارئان مختلفا النطاق يتشاركان الرقمَ نفسَه — وأولُ من
        // يسخّن الخبيئة يفرض رقمَه على من لا يرى نصفَ عقوده. والقراءةُ كانت
        // خاماً بلا `hub_can` ولا `hub_scope` أصلاً.
        $key = hub_scope_key('rev:mrr');
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () {
            $DB = \Illuminate\Support\Facades\DB::class;
            $divisor = ['شهري' => 1, 'ربع سنوي' => 3, 'نصف سنوي' => 6, 'سنوي' => 12];

            // العودةُ المبكرة تحمل **مفاتيح الصدق نفسَها**: العرضُ يقرأ
            // `mixed`/`byCurrency` بلا شرط، فإسقاطُهما هنا فخُّ
            // `Undefined array key` ينتظر أوّلَ قاعدةٍ بلا عقدٍ سارٍ.
            $empty = ['mrr' => 0.0, 'arr' => 0.0, 'contracts' => 0, 'byService' => [],
                      'byCurrency' => [], 'mixed' => false, 'unmapped' => 0, 'oneTime' => 0.0,
                      'oneTimeByCurrency' => [], 'oneTimeMixed' => false,
                      // مفاتيحُ التحويلِ في العودةِ المبكرةِ كذلك — العرضُ يقرؤها بلا شرط
                      'converted' => false, 'currency' => (string) setting('app.currency', 'د.ك'),
                      'missing' => [], 'oneTimeConverted' => false];

            $q = hub_read('contracts');
            if (! $q) return $empty;
            $contracts = $q->where('status', 'ساري')->where('type', 'عقد عميل')
                ->get(['id', 'title', 'value', 'currency', 'service_id', 'plan_id', 'client_id', 'date_end']);
            if ($contracts->isEmpty()) return $empty;

            $plans = \Illuminate\Support\Facades\DB::table('pricing_plans')->whereNull('deleted_at')
                ->get(['id', 'cycle', 'service_id'])->keyBy('id');
            $services = \Illuminate\Support\Facades\DB::table('services')->whereNull('deleted_at')
                ->get(['id', 'name', 'cycle'])->keyBy('id');

            $default = (string) setting('app.currency', 'د.ك');
            $mrr = 0.0; $oneTime = 0.0; $unmapped = 0; $byService = []; $byCur = []; $otCur = [];
            foreach ($contracts as $c) {
                $value = (float) ($c->value ?? 0);
                if ($value <= 0) continue;

                $plan = $c->plan_id ? ($plans[$c->plan_id] ?? null) : null;
                $sid = $c->service_id ?: ($plan->service_id ?? null);
                $svc = $sid ? ($services[$sid] ?? null) : null;
                $cycle = (string) ($plan->cycle ?? $svc->cycle ?? 'سنوي');   // بلا ربطٍ: سنويٌّ افتراضاً
                $cur = (string) ($c->currency ?: $default);

                if (! $sid) $unmapped++;
                // **و«مرة واحدة» تُفصل بالعملة كأختها المتكرّرة** (v2.335): كانت
                // تُجمع خاماً قبل أيّ فصل، وعلمُ `mixed` مشتقٌّ من `byCur`
                // المتكرّرة وحدها — فقاعدةٌ متكرّرُها بعملةٍ واحدة تُعلن
                // «لا اختلاط» وتعرض `oneTime` جامعاً ديناراً ودولاراً.
                if ($cycle === 'مرة واحدة') {
                    $oneTime += $value;
                    $otCur[$cur] ??= ['currency' => $cur, 'total' => 0.0, 'contracts' => 0];
                    $otCur[$cur]['total'] += $value;
                    $otCur[$cur]['contracts']++;
                    continue;
                }

                $monthly = $value / ($divisor[$cycle] ?? 12);
                $mrr += $monthly;

                // **الفصل بالعملة**: كان `currency` يُقرأ ولا يُستعمل فتُجمع عقودٌ
                // بعملاتٍ شتّى في رقمٍ واحد تحت لصيقةٍ واحدة — كذبةٌ رقمية. لا
                // محرّكَ أسعار في النظام (app.currency تسميةٌ لا تحويل)، فالصادق
                // الفصلُ ورفعُ علم mixed لا جمعٌ مُخترَع.
                $byCur[$cur] ??= ['currency' => $cur, 'mrr' => 0.0, 'arr' => 0.0, 'contracts' => 0];
                $byCur[$cur]['mrr'] += $monthly;
                $byCur[$cur]['contracts']++;

                $key = $sid ?: '_none';
                $byService[$key] ??= ['id' => $sid, 'name' => $svc->name ?? 'بلا خدمة مربوطة',
                                      'mrr' => 0.0, 'contracts' => 0];
                $byService[$key]['mrr'] += $monthly;
                $byService[$key]['contracts']++;
            }

            usort($byService, fn ($a, $b) => $b['mrr'] <=> $a['mrr']);
            foreach ($byCur as &$bc) { $bc['mrr'] = round($bc['mrr'], 2); $bc['arr'] = round($bc['mrr'] * 12, 2); }
            unset($bc);
            usort($byCur, fn ($a, $b) => $b['mrr'] <=> $a['mrr']);
            foreach ($otCur as &$oc) { $oc['total'] = round($oc['total'], 2); }
            unset($oc);
            usort($otCur, fn ($a, $b) => $b['total'] <=> $a['total']);

            /*
             * **والمحرّكُ موصولٌ بالإيرادِ المتكرّرِ كذلك** (v2.542): قيمةُ العقد
             * السارية مبلغٌ قائمٌ لا حدثٌ مؤرَّخ، فبسعرِ اليوم. وبسعرٍ مسجَّلٍ
             * يصير MRR رقماً واحداً بعملةِ الأساسِ بدل تفصيلٍ لا يُجمَع —
             * وARR تبعُه. والتفصيلُ بالعملة **يبقى** مهما كان (لا حذف).
             */
            $mrrM = hub_money_sum($byCur, 'mrr', 'currency', null, $default);
            $otM = hub_money_sum($otCur, 'total', 'currency', null, $default);
            $mrrOut = $mrrM['converted'] ? $mrrM['total'] : round($mrr, 2);
            $otOut = $otM['converted'] ? $otM['total'] : round($oneTime, 2);

            return [
                'mrr' => $mrrOut,
                'arr' => round($mrrOut * 12, 2),
                'contracts' => $contracts->count(),
                'byService' => $byService,
                'byCurrency' => $byCur,
                // الرقم الموحّد أعلاه أمينٌ فقط بعملةٍ واحدة — mixed يخبر الواجهة
                // أن تعرض التفصيل لا رقماً واحداً كاذباً. ويسقط العلمُ حين يُحوَّل.
                'mixed' => $mrrM['mixed'],
                'converted' => $mrrM['converted'],
                'currency' => $mrrM['cur'],
                'missing' => $mrrM['missing'],
                'unmapped' => $unmapped,
                'oneTime' => $otOut,
                'oneTimeByCurrency' => $otCur,
                'oneTimeMixed' => $otM['mixed'],
                'oneTimeConverted' => $otM['converted'],
            ];
        });
    }
}
