<?php

namespace App\Support;

use App\Models\AiBudget;
use App\Models\AiBudgetPeriod;
use Illuminate\Support\Facades\DB;

/**
 * **الميزانيّاتُ والحصص — حجزٌ ثمّ التزامٌ أو إفراج** (المرحلة ٤ · P4-W4).
 *
 * ── **لماذا لا يكفي «افحص ثمّ ولّد ثمّ زِد»؟** ──
 *
 * لأنّه **يُنفق فوقَ السقفِ بالبناء**، لا بسوءِ حظّ. طلبان متزامنان عند حافّةِ
 * الميزانيّة: كلاهما يقرأ «بقي دولار»، وكلاهما يرى أنّ دولاراً يكفي، فيُنفَق
 * دولاران. والفجوةُ ليست ميكروثانية: **بين الفحصِ والزيادةِ رحلةُ شبكةٍ
 * كاملةٌ إلى مزوّدٍ خارجيّ** — ثوانٍ يمرّ فيها عشراتُ الطلبات.
 *
 * ── **والحلُّ: القاعدةُ هي الحكَم، بجملةٍ واحدةٍ لا بجملتين** ──
 *
 * ```sql
 * UPDATE ai_budget_periods
 *    SET reserved_micro = reserved_micro + :est, requests = requests + 1
 *  WHERE id = :id AND spent_micro + reserved_micro + :est <= :limit
 * ```
 *
 * الشرطُ والزيادةُ **في عبارةٍ ذرّيّةٍ واحدة**، فالقاعدةُ تُسلسِلها حتماً.
 * ومن أعادت جملتُه صفّاً مُعدَّلاً فاز، ومن أعادت صفراً مُنع — **ولا نافذةَ
 * بينهما أصلاً**.
 *
 * **ولمَ لا `SELECT … FOR UPDATE`؟** لأنّ SQLite لا تعرفه، فكان الحارسُ
 * سيُختبَر على محرّكٍ ويعمل على آخر. **ولا قفلٌ في التطبيق** (`Cache::lock`)
 * لأنّه يسقط مع أوّلِ عامِلٍ ثانٍ أو خادمٍ ثانٍ — وهو بالضبطِ الحالُ التي
 * بُني لها.
 *
 * ── **ودورةُ الحياةِ ثلاثيّةٌ لا ثنائيّة** ──
 *
 * ```
 * reserve ──┬── commit   (وقع نداءٌ: يُنقَص الحجزُ ويُضاف المُنفَق)
 *           ├── release  (لم يقع نداءٌ: يُنقَص الحجزُ ويُردّ عدُّ الطلب)
 *           └── expire   (مات المسارُ بينهما: مِقصٌّ دوريٌّ يُفرج)
 * ```
 *
 * **والانتهاءُ ليس ترفاً:** مهلةٌ تنقضي أو عمليّةٌ تموت بين الحجزِ والالتزامِ
 * تترك حجزاً معلّقاً إلى الأبد، **فتُخنَق الميزانيّةُ بمالٍ لم يُنفَق قطّ**.
 *
 * ── **والمجهولُ لا يمرّ تحت سقفٍ مفروض** ──
 *
 * قاعدةُ `AiRouting::withinBudget` نفسُها في موضعِ المال: تقديرٌ `null` مع
 * سقفٍ قائمٍ **يُرَدّ**، لأنّ تمريرَ ما لا يُقاس يُبطل السقفَ في صمت.
 */
final class AiBudgets
{
    public const PERIODS = ['daily', 'monthly', 'total'];
    public const SCOPES  = ['global', 'company', 'user', 'role', 'purpose'];

    /** شكلُ نتيجةِ الحجزِ — لا شكلَ يُخترَع في مُستدعٍ */
    public const SHAPE = ['ok', 'code', 'why', 'budget_id', 'period_key', 'reserved_micro'];

    /** عمرُ الحجزِ المعلّقِ قبل أن يُفرَج عنه قسراً (دقائق) */
    public const RESERVATION_TTL_MIN = 15;

    /**
     * **الميزانيّاتُ التي تحكم هذا الطلب** — واحدةٌ أو أكثرُ أو لا شيء.
     *
     * وترتيبُها **حتميّ**: بالنطاقِ ثمّ بالمفتاح. فرسالةُ «أيُّ ميزانيّةٍ
     * منعتك» يجب أن تكون هي هي على كلِّ محرّك.
     *
     * @return list<AiBudget>
     */
    public static function governing(array $ctx): array
    {
        $userId    = (string) ($ctx['user_id'] ?? '');
        $roleId    = (string) ($ctx['role_id'] ?? '');
        $companyId = (string) ($ctx['company_id'] ?? '');
        $purpose   = (string) ($ctx['purpose'] ?? '');

        return array_values(AiBudget::query()
            ->where('enabled', true)
            ->orderBy('scope_type')->orderBy('key')->orderBy('id')
            ->get()
            ->filter(static fn ($b) => match ((string) $b->scope_type) {
                'global'  => true,
                'company' => $companyId !== '' && (string) $b->scope_id === $companyId,
                'user'    => $userId    !== '' && (string) $b->scope_id === $userId,
                'role'    => $roleId    !== '' && (string) $b->scope_id === $roleId,
                'purpose' => $purpose   !== '' && (string) $b->scope_id === $purpose,
                default   => false,
            })->all());
    }

    /**
     * **مفتاحُ الفترةِ الحاليّة** — و`total` فترةٌ واحدةٌ لا تنقضي.
     *
     * والتاريخُ يُقرأ من `now()` فيطيع `Carbon::setTestNow` في الاختبار — فلا
     * يُختبَر انقضاءُ فترةٍ بانتظارِ منتصفِ الليل.
     */
    public static function periodKey(string $period, ?\DateTimeInterface $at = null): string
    {
        $t = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return match ($period) {
            'daily'   => $t->format('Y-m-d'),
            'monthly' => $t->format('Y-m'),
            default   => 'total',
        };
    }

    /** صفُّ الفترةِ — يُنشَأ إن لم يكن، **وسباقُ الإنشاءِ لا يُسقط الطلب** */
    public static function periodRow(AiBudget $b): AiBudgetPeriod
    {
        $key = self::periodKey((string) $b->period);

        try {
            return AiBudgetPeriod::firstOrCreate(
                ['budget_id' => (string) $b->id, 'period_key' => $key],
                ['opened_at' => now()]);
        } catch (\Illuminate\Database\QueryException $e) {
            /*
             * **سبق طلبٌ متزامنٌ إلى الإنشاء** فاصطدم القيدُ الفريد. وهذا
             * **نجاحٌ لا عطل**: الصفُّ موجودٌ الآن وهو ما أردناه. والقراءةُ
             * بعدَه تُعيده. ولو رُفع الاستثناءُ لَسقط طلبٌ سليمٌ لأنّ آخرَ
             * سبقه بميلي‑ثانية.
             */
            $row = AiBudgetPeriod::query()
                ->where('budget_id', (string) $b->id)->where('period_key', $key)->first();

            if ($row === null) throw $e;

            return $row;
        }
    }

    /**
     * **حجزٌ على كلِّ ميزانيّةٍ حاكمة** — وكلُّها أو لا شيء.
     *
     * **والتراجعُ عند أوّلِ رفضٍ لازم**: لو حُجز على الأولى ثمّ رُفضت الثانية
     * ولم يُفرَج عن الأولى، لَبقي مالٌ محجوزاً لطلبٍ لن يقع — وتراكمُه يَخنق
     * ميزانيّةً سليمةً بلا إنفاقٍ واحد.
     *
     * @param  ?int  $estimateMicro  `null` = كلفةٌ لا تُقدَّر
     * @return array{ok:bool, code:?string, why:?string, holds:list<array{budget_id:string, period_key:string, micro:int}>}
     */
    public static function reserve(array $ctx, ?int $estimateMicro, int $estTokens = 0): array
    {
        $holds = [];

        foreach (self::governing($ctx) as $b) {
            $enforce = (bool) $b->enforce;
            $row     = self::periodRow($b);

            /*
             * **ومجهولُ الكلفةِ لا يمرّ تحت سقفِ مالٍ مفروض.**
             *
             * `null` ليست صفراً بل «لا نعرف كم يكلّف»، وتمريرُها تحت سقفٍ
             * وُضع عمداً يُبطل السقفَ — وهي قاعدةُ `Tri` ① في موضعِ المال.
             * وبلا سقفِ مالٍ لا شيءَ يُقاس، فتمرّ ويُعَدّ مجهولُها.
             */
            if ($enforce && $b->limit_micro !== null && $estimateMicro === null) {
                self::releaseAll($holds);

                return self::deny(AskFailures::BUDGET_EXCEEDED,
                    'كلفةُ هذا الطلبِ لا تُقدَّر — وميزانيّةُ «' . $b->label
                    . '» مفروضةٌ بسقفِ مال، والمجهولُ لا يمرّ تحتَه');
            }

            $micro = max(0, (int) ($estimateMicro ?? 0));

            if (! $enforce) {
                // **مراقبةٌ بلا منع** — تُحجَز وتُحسَب ولا تُرَدّ
                self::bump($row, $micro, +1);
                $holds[] = ['budget_id' => (string) $b->id,
                            'period_key' => (string) $row->period_key, 'micro' => $micro];
                continue;
            }

            [$won, $blocked] = self::tryReserve($b, $row, $micro, $estTokens);

            if (! $won) {
                self::releaseAll($holds);

                return self::deny(
                    $blocked === 'money' ? AskFailures::BUDGET_EXCEEDED : AskFailures::QUOTA_EXCEEDED,
                    self::blockWhy($b, $blocked));
            }

            $holds[] = ['budget_id' => (string) $b->id,
                        'period_key' => (string) $row->period_key, 'micro' => $micro];
        }

        return ['ok' => true, 'code' => null, 'why' => null, 'holds' => $holds];
    }

    /**
     * **الالتزام** — وقع نداءٌ فعلاً: يُنقَص الحجزُ ويُضاف ما أُنفق.
     *
     * **وعدُّ الطلبِ لا يُردّ** ولو فشل النداء: محاولةٌ بلغت المزوّدَ محاولةٌ
     * وقعت، وحصّةٌ تتجاهل المحاولاتِ الفاشلةَ تُشجّع على إغراقِ المزوّدِ بطلباتٍ
     * ساقطةٍ بلا حساب.
     *
     * @param  list<array{budget_id:string, period_key:string, micro:int}>  $holds
     */
    public static function commit(array $holds, ?int $actualMicro, int $tokens = 0): void
    {
        $unknown = $actualMicro === null ? 1 : 0;
        $spent   = max(0, (int) ($actualMicro ?? 0));

        foreach ($holds as $h) {
            $micro = max(0, (int) ($h['micro'] ?? 0));

            DB::update(
                'UPDATE ai_budget_periods
                    SET reserved_micro = CASE WHEN reserved_micro >= ? THEN reserved_micro - ? ELSE 0 END,
                        spent_micro = spent_micro + ?,
                        tokens = tokens + ?,
                        unknown_cost_events = unknown_cost_events + ?,
                        updated_at = ?
                  WHERE budget_id = ? AND period_key = ?',
                [$micro, $micro, $spent, max(0, $tokens), $unknown, now(),
                 (string) $h['budget_id'], (string) $h['period_key']]);
        }
    }

    /** **الإفراج** — لم يقع نداءٌ: يُنقَص الحجزُ **ويُردّ عدُّ الطلب** */
    public static function releaseAll(array $holds): void
    {
        foreach ($holds as $h) {
            $micro = max(0, (int) ($h['micro'] ?? 0));

            DB::update(
                'UPDATE ai_budget_periods
                    SET reserved_micro = CASE WHEN reserved_micro >= ? THEN reserved_micro - ? ELSE 0 END,
                        requests = CASE WHEN requests > 0 THEN requests - 1 ELSE 0 END,
                        updated_at = ?
                  WHERE budget_id = ? AND period_key = ?',
                [$micro, $micro, now(), (string) $h['budget_id'], (string) $h['period_key']]);
        }
    }

    /**
     * **حالةُ ميزانيّةٍ للعرض** — و**المجهولُ يُعرَض عدداً لا يُطوى في الصفر**.
     *
     * @return array{limit_micro:?int, spent_micro:int, reserved_micro:int, available_micro:?int,
     *               requests:int, tokens:int, unknown_cost_events:int, period_key:string, pct:?int}
     */
    public static function status(AiBudget $b): array
    {
        $row  = self::periodRow($b);
        $lim  = $b->limit_micro === null ? null : (int) $b->limit_micro;
        $used = (int) $row->spent_micro + (int) $row->reserved_micro;

        return [
            'limit_micro'         => $lim,
            'spent_micro'         => (int) $row->spent_micro,
            'reserved_micro'      => (int) $row->reserved_micro,
            'available_micro'     => $lim === null ? null : max(0, $lim - $used),
            'requests'            => (int) $row->requests,
            'tokens'              => (int) $row->tokens,
            'unknown_cost_events' => (int) $row->unknown_cost_events,
            'period_key'          => (string) $row->period_key,
            'pct'                 => ($lim === null || $lim <= 0) ? null
                : (int) min(100, (int) floor($used * 100 / $lim)),
        ];
    }

    // ── الداخل ────────────────────────────────────────────────────────

    /**
     * **الجملةُ الذرّيّة** — وعليها يقوم الحارسُ كلُّه.
     *
     * @return array{0:bool, 1:?string}  فاز؟ وإن لا، فأيُّ سقفٍ منع
     */
    private static function tryReserve(AiBudget $b, AiBudgetPeriod $row, int $micro, int $tokens): array
    {
        $sql  = 'UPDATE ai_budget_periods
                    SET reserved_micro = reserved_micro + ?, requests = requests + 1, updated_at = ?
                  WHERE id = ?';
        $bind = [$micro, now(), (string) $row->id];

        if ($b->limit_micro !== null) {
            $sql   .= ' AND spent_micro + reserved_micro + ? <= ?';
            $bind[] = $micro;
            $bind[] = (int) $b->limit_micro;
        }
        if ($b->limit_requests !== null) {
            $sql   .= ' AND requests < ?';
            $bind[] = (int) $b->limit_requests;
        }
        if ($b->limit_tokens !== null) {
            $sql   .= ' AND tokens + ? <= ?';
            $bind[] = max(0, $tokens);
            $bind[] = (int) $b->limit_tokens;
        }

        if (DB::update($sql, $bind) === 1) return [true, null];

        /*
         * **ولماذا يُقرَأ الصفُّ بعد الرفضِ لا قبلَه؟**
         *
         * لأنّ القراءةَ هنا **للرسالةِ وحدَها** لا للقرار. القرارُ وقع في
         * الجملةِ أعلاه ذرّيّاً، وهذه قراءةٌ لاحقةٌ تقول «أيُّ سقفٍ منعك»
         * — وحتّى لو تغيّر الصفُّ بينهما لم يتغيّر أنّ الطلبَ مُنع.
         */
        $fresh = AiBudgetPeriod::query()->where('id', (string) $row->id)->first();
        if ($fresh === null) return [false, 'money'];

        if ($b->limit_requests !== null && (int) $fresh->requests >= (int) $b->limit_requests) {
            return [false, 'requests'];
        }
        if ($b->limit_tokens !== null && (int) $fresh->tokens + max(0, $tokens) > (int) $b->limit_tokens) {
            return [false, 'tokens'];
        }

        return [false, 'money'];
    }

    /** زيادةٌ بلا شرطٍ — لميزانيّةٍ «تراقب ولا تمنع» */
    private static function bump(AiBudgetPeriod $row, int $micro, int $requests): void
    {
        DB::update(
            'UPDATE ai_budget_periods
                SET reserved_micro = reserved_micro + ?, requests = requests + ?, updated_at = ?
              WHERE id = ?',
            [$micro, $requests, now(), (string) $row->id]);
    }

    private static function blockWhy(AiBudget $b, ?string $blocked): string
    {
        return match ($blocked) {
            'requests' => 'بلغت ميزانيّةُ «' . $b->label . '» سقفَ عددِ الطلباتِ لهذه الفترة',
            'tokens'   => 'بلغت ميزانيّةُ «' . $b->label . '» سقفَ الرموزِ لهذه الفترة',
            default    => 'بلغت ميزانيّةُ «' . $b->label . '» سقفَ الإنفاقِ لهذه الفترة',
        };
    }

    private static function deny(string $code, string $why): array
    {
        return ['ok' => false, 'code' => $code, 'why' => $why, 'holds' => []];
    }
}
