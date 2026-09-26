<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **محرّكُ الصرف — السلطةُ الواحدةُ على «كم يساوي هذا بعملةِ الأساس؟»**
 *
 * كان في النظامِ ٣٦ حقلَ عملةٍ في ٢٢ جدولاً، و`app.currency` **تسميةٌ لا
 * تحويل** (يقولها `hub_cur_label` صراحةً). فبطاقةٌ تجمع ديناراً ودولاراً
 * تُوسَم `mixed` ويُقرأ رقمُها مؤشّراً لا رقماً — **وذاك هو الصدقُ الصحيحُ في
 * غيابِ سعرِ صرف**، والعيبُ أنّ السعرَ لم يكن ممكناً أصلاً.
 *
 * **والعقدُ الحاكم: لا يُستبدَل صدقٌ بدقّةٍ موهومة.**
 *
 *   · بلا سعرٍ مُدخَل **لا يتغيّر شيء** — `mixed` تبقى، والأرقامُ كما هي.
 *   · بسعرٍ مُدخَل يُحوَّل **ويُعلَن أنّه محوَّل** وبأيِّ تاريخ.
 *   · وزوجٌ واحدٌ بلا سعرٍ يُبقي **المجموعَ كلَّه مخلوطاً** — لا يُحوَّل بعضُه
 *     ويُهمَل بعضُه، فذاك رقمٌ لا يُمثّل شيئاً وهو أسوأُ من «مخلوط».
 *
 * **والسعرُ مؤرَّخٌ لا لحظيّ:** يُقرأ **أحدثُ سعرٍ لا يتجاوز تاريخَ المستند**،
 * فتُحوَّل فاتورةُ يناير بسعرِ يناير — وإلّا تغيّر تقريرُ الربعِ الماضي كلَّ
 * صباح. ومستندٌ أقدمُ من أوّلِ سعرٍ مسجَّل **لا يُحوَّل**: ذاك استقراءٌ للخلف
 * لا تحويل.
 */
class Currency
{
    /** مخبأُ العمليّةِ الواحدة — الأسعارُ قليلةٌ وتُقرأ كثيراً */
    protected static ?array $memo = null;

    /** يُبطل المخبأ — تستدعيه الشاشةُ بعد إدخالِ سعرٍ، والاختبارات */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * **عملةُ الأساس** — ما تُحوَّل إليه المجاميع. افتراضُها `app.currency`
     * نفسُها، فلا يتغيّر شيءٌ لمن لم يضبط شيئاً.
     */
    public static function base(): string
    {
        $b = trim((string) setting('fin.base_currency', ''));

        return $b !== '' ? $b : (string) setting('app.currency', 'د.ك');
    }

    /** أزواجُ الأسعارِ المسجَّلةُ كلُّها، مرتّبةً بالتاريخ — قراءةٌ واحدةٌ للعمليّة */
    protected static function all(): array
    {
        if (self::$memo !== null) return self::$memo;
        if (! Schema::hasTable('currency_rates')) return self::$memo = [];

        $rows = DB::table('currency_rates')->whereNull('deleted_at')
            // تصاعديٌّ بالتاريخ ثمّ بالمعرّف — فاختيارُ «أحدثِ ما لا يتجاوز» حتميّ
            ->orderBy('as_of')->orderBy('id')
            ->get(['from_cur', 'to_cur', 'rate', 'as_of']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->from_cur . '→' . $r->to_cur][] = [
                'as_of' => substr((string) $r->as_of, 0, 10),
                'rate' => (float) $r->rate,
            ];
        }

        return self::$memo = $map;
    }

    /**
     * **سعرُ زوجٍ في تاريخ** — أحدثُ سعرٍ **لا يتجاوز** التاريخ، أو `null`.
     *
     * ويُقبَل المقلوبُ: من سجّل «دولار ← دينار» لا يُطالَب بتسجيلِ العكس.
     * ولا يُشتقُّ سعرٌ عبر عملةٍ ثالثة — تلك دقّةٌ موهومةٌ تُراكم خطأَ زوجَين.
     */
    public static function rate(?string $from, ?string $to = null, ?string $date = null): ?float
    {
        $to = $to ?: self::base();
        $from = trim((string) $from) ?: $to;
        if ($from === $to) return 1.0;

        $date = substr((string) ($date ?: now()->toDateString()), 0, 10);
        $all = self::all();

        if ($r = self::pick($all[$from . '→' . $to] ?? [], $date)) return $r;
        // المقلوب: سعرُ «to→from» يُعطي «from→to» بقلبِه
        if ($r = self::pick($all[$to . '→' . $from] ?? [], $date)) return $r > 0 ? 1 / $r : null;

        return null;
    }

    /** أحدثُ سعرٍ لا يتجاوز التاريخ — والأقدمُ من أوّلِ سعرٍ لا يُحوَّل */
    protected static function pick(array $series, string $date): ?float
    {
        $best = null;
        foreach ($series as $row) {
            if ($row['as_of'] <= $date) $best = $row['rate'];
            else break;                       // مرتَّبةٌ تصاعديّاً — ما بعدَه أحدث
        }

        return $best;
    }

    /** المبلغُ بعملةِ الأساس، أو `null` إن لم يكن للزوجِ سعرٌ في ذلك التاريخ */
    public static function toBase(float $amount, ?string $from, ?string $date = null): ?float
    {
        $r = self::rate($from, self::base(), $date);

        return $r === null ? null : round($amount * $r, 3);
    }

    /**
     * **مجموعُ صفوفٍ قد تختلف عملاتُها** — الجوابُ الصادقُ عن «كم المجموع؟».
     *
     * @param  iterable<array{amount: float|int|string, currency: ?string, date: ?string}>  $rows
     * @return array{total: float, cur: string, mixed: bool, converted: bool, missing: array<int,string>}
     */
    public static function sum(iterable $rows): array
    {
        $base = self::base();
        $seen = [];      // العملاتُ الظاهرة
        $missing = [];   // ما لا سعرَ له
        $rawTotal = 0.0; // المجموعُ بلا تحويل (سلوكُ ما قبلَ المحرّك)
        $conv = 0.0;     // المجموعُ محوَّلاً

        foreach ($rows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $cur = trim((string) ($row['currency'] ?? '')) ?: $base;
            $seen[$cur] = true;
            $rawTotal += $amount;

            $c = self::toBase($amount, $cur, $row['date'] ?? null);
            if ($c === null) $missing[$cur] = true;
            else $conv += $c;
        }

        $mixed = count($seen) > 1;
        $missing = array_keys($missing);
        // يُحوَّل **فقط** حين يكون مخلوطاً فعلاً ولا زوجَ ناقصاً — ومجموعُ عملةٍ
        // واحدةٍ لا يُوسَم «محوَّلاً» لأنّه لم يُحوَّل
        $converted = $mixed && ! $missing;

        return [
            'total' => $converted ? round($conv, 3) : round($rawTotal, 3),
            'cur' => $converted ? $base : ($mixed ? $base : (array_key_first($seen) ?: $base)),
            'mixed' => $mixed && ! $converted,
            'converted' => $converted,
            'missing' => $missing,
        ];
    }

    /** أزواجُ العملاتِ المسجَّلةُ — لشاشةِ الإدارة */
    public static function pairs(): array
    {
        return array_keys(self::all());
    }

    /**
     * **هل سُجِّل سعرُ صرفٍ واحدٌ أصلاً؟** — مفتاحُ المسارِ كلِّه.
     *
     * المحرّكُ موصولٌ بالشاشاتِ التي تجمع المال، ووصلُه يُغيّر شكلَ استعلامِها
     * (تجميعٌ بالعملةِ والشهرِ بدل مجموعٍ قياسيٍّ واحد). فمن **لم يسجّل سعراً**
     * لا يدفع ثمنَ ميزةٍ لم يُفعّلها: الشاشةُ تسلك مسارَها القديمَ حرفيّاً،
     * والاستعلامُ هو الاستعلامُ نفسُه. وقراءةُ هذا المفتاحِ رخيصةٌ لأنّ
     * `all()` مخبوءةٌ للعمليّةِ الواحدة.
     */
    public static function enabled(): bool
    {
        return self::all() !== [];
    }
}
