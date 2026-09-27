<?php

namespace App\Support\Finance;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\Platform\SchemaCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **محرّكُ ترحيل القيود الواحد** (TECH_DEBT #29 · ARCH-06..09 · `JournalPosting`).
 *
 * كان للقيد المرحَّل أربعةُ أبوابٍ بقواعدَ متفرّقة:
 *  - الترحيلُ الآليّ (دفعةُ المستند وعكسُها في `FinController`، واعتمادُ الرواتب في
 *    `PayrollController`، وحركةُ العهدة وعكسُها في `CustodyPostingService`) عبر
 *    `JournalPostingService::postBalanced` — التي **ترفع رايةَ التجاوز ولا تفحص التوازن
 *    بنفسها**: فقيدٌ أعرجُ يمرّ صامتاً لو مرّره مستدعٍ؛ ولا تدقيقَ «ترحيل قيد» له؛ ولا
 *    حاجزَ لمصدرٍ واحدٍ يُرحَّل مرّتين (مسيّرٌ أُعيد إلى المسودة فاعتُمد ثانيةً = مصروفٌ
 *    مضاعف)؛ ورقمُ القيد مشتقٌّ من الساعة فدفعتان في الثانية نفسِها تحملان رقماً واحداً.
 *  - زرُّ «ترحيل» (`EntryController::post`) يفحص التوازنَ بالعائم ويدقّق.
 *  - النموذجُ العامّ (`PUT /m/entries/{id}` والـAPI) يمرّ بحارس النموذج وحده — فيُرحَّل
 *    بلا ختمِ `posted_at` ولا قيدِ تدقيق «ترحيل قيد».
 *
 * الآن كلُّ ترحيلٍ يمرّ بهذا المحرّك فيحمل الثوابتَ نفسَها:
 *  - **التوازن عشريّاً لا عائماً**: المبالغُ تُحوَّل إلى ملّيماتٍ صحيحة (عرضُ العمود
 *    decimal(16,3)) — مدينٌ = دائنٌ > صفر، وكلُّ سطرٍ على حسابٍ وبطرفٍ واحد.
 *  - **ترحيلٌ واحدٌ للمصدر الواحد**: رابطُ المصدر المتعدّد الأشكال `(source_module,
 *    source_id, source_key)` — فحصٌ تحت قفلٍ + `UNIQUE` على القاعدة؛ المصدرُ المرحَّل
 *    سلفاً يُعاد قيدُه القائم بلا قيدٍ ثانٍ.
 *  - **رقمٌ لا يتكرّر**: المقترَحُ يُقصّ لعرض عموده، ويُلحَق به `-2`، `-3`… إن سبقه قيد.
 *  - **قيدُ تدقيقٍ واحد** بفعل `ترحيل قيد` لكلّ ترحيل — آلياً كان أو يدوياً.
 *  - **ختمُ الترحيل** `meta.posted_at` (و`posted_by` لليدويّ).
 *
 * **وقفلُ الفترة؟** لا مفهومَ لفترةٍ محاسبيّةٍ مقفلة في النظام (لا إعدادَ ولا جدول) —
 * فلا يُخترع هنا؛ ومتى وُجد يُضاف حارسُه في `guard()` مرّةً فيسري على الأبواب كلّها.
 */
final class JournalPosting
{
    /** الحالةُ المرحَّلة المقفلة — مفردةُ `journal_entries.state` نفسُها */
    public const STATE = 'مرحّل';

    /** فعلُ التدقيق الواحد لكلّ ترحيل — والمصدرُ في `after` */
    public const AUDIT_ACTION = 'ترحيل قيد';

    /** ثلاثُ خاناتٍ عشريّة — عرضُ `journal_lines.debit/credit` = decimal(16,3) */
    public const SCALE = 1000;

    /** أعمدةُ رابطِ المصدر (هجرة 2026_10_12_000001) */
    public const SOURCE_COLS = ['source_module', 'source_id', 'source_key'];

    /* ─────────────────────────── الإعداد ─────────────────────────── */

    /**
     * هل الترحيلُ الآليّ مفعَّل — بالسلسلة لا بالنوع: `hub:set` يخزّن القيمةَ عدداً
     * فتفشل `===` النوعيّة (داءُ `contracts.auto_expire`).
     */
    public static function enabled(): bool
    {
        return (string) setting('finance.auto_journal') === '1';
    }

    /** خريطةُ حسابات الترحيل الآليّ، مقروءةً موحّداً (مصفوفةً كانت أو JSON) */
    public static function accountsMap(): array
    {
        $map = setting('finance.accounts');

        return is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
    }

    /**
     * يحلّ رمزَ حسابٍ إلى معرّفه، **مُحصَّراً بالشركة ثم بترتيب id** — لا قرعة:
     * حسابُ شركة المصدر أولاً، فالعامّ (company_id فارغ) احتياطاً. رمزٌ فارغٌ أو بلا
     * حساب → null (فلا قيدٌ أعرجُ يُبنى فوقه).
     */
    public static function resolveAccount(?string $code, ?string $companyId): ?string
    {
        if (blank($code)) return null;

        return LedgerAccount::whereNull('deleted_at')->where('code', (string) $code)
            ->where(function ($w) use ($companyId) {
                $w->whereNull('company_id');
                if (filled($companyId)) $w->orWhere('company_id', $companyId);
            })
            ->orderByRaw('company_id IS NULL')   // الخاصّ بالشركة أولاً، العامّ احتياطاً
            ->orderBy('id')->value('id');
    }

    /* ─────────────────────── الحسابُ العشريّ ─────────────────────── */

    /**
     * مبلغٌ ⟵ ملّيماتٌ صحيحة، **من النصّ لا من الحساب العائم**: `0.1 + 0.2` عائماً
     * ليس `0.3`، أمّا ١٠٠ + ٢٠٠ ملّيم فـ٣٠٠ تماماً. التقريبُ نصفٌ لأعلى عند الخانة
     * الرابعة — كما يخزّن عمودُ decimal(16,3).
     */
    public static function mills(mixed $v): int
    {
        if ($v === null || $v === '') return 0;
        if (is_int($v)) return $v * self::SCALE;

        $s = is_float($v) ? sprintf('%.6F', $v) : trim((string) $v);
        if (! preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw ValidationException::withMessages(['amount' => 'مبلغٌ غير مقروء في سطر القيد: ' . $s]);
        }
        $ten = (int) ($m[2] !== '' ? $m[2] : '0') * 10000 + (int) str_pad(substr($m[3] ?? '', 0, 4), 4, '0');
        $mills = intdiv($ten + 5, 10);

        return $m[1] === '-' ? -$mills : $mills;
    }

    /** ملّيماتٌ ⟵ نصٌّ للعرض (للرسائل وحدها — لا يُحسب عليه) */
    public static function fmt(int $mills): string
    {
        return number_format($mills / self::SCALE, 3);
    }

    /** مجموعا المدين والدائن بالملّيمات — لسطورٍ (مصفوفاتٍ أو نماذج) */
    public static function totals(iterable $lines): array
    {
        $d = 0;
        $c = 0;
        foreach ($lines as $l) {
            $d += self::mills(data_get($l, 'debit'));
            $c += self::mills(data_get($l, 'credit'));
        }

        return ['debit' => $d, 'credit' => $c];
    }

    /** مجموعا سطور قيدٍ قائم — من القاعدة، سطراً سطراً بالملّيمات (لا SUM عائم) */
    public static function entryTotals(string $entryId): array
    {
        return self::totals(JournalLine::where('entry_id', $entryId)->orderBy('id')->get(['debit', 'credit']));
    }

    /** سببُ رفض سطرٍ، أو null إن صحّ: حسابٌ، وطرفٌ واحدٌ موجب، ولا سالب */
    public static function lineError(array $line): ?string
    {
        if (blank($line['acc_id'] ?? null)) return 'سطرُ قيدٍ بلا حساب';
        $d = self::mills($line['debit'] ?? 0);
        $c = self::mills($line['credit'] ?? 0);
        if ($d < 0 || $c < 0) return 'سطرُ قيدٍ بمبلغٍ سالب';
        if ($d > 0 && $c > 0) return 'السطر الواحد إما مدين أو دائن لا كلاهما';
        if ($d === 0 && $c === 0) return 'سطرُ قيدٍ بلا مبلغ';

        return null;
    }

    /* ─────────────────────────── الترحيل ─────────────────────────── */

    /**
     * **الترحيلُ الواحد لقيدٍ جديد.** يبني قيداً مرحَّلاً وسطورَه في معاملةٍ واحدة بعد
     * فحص الثوابت، ويعيد القيدَ — أو القيدَ القائمَ إن رُحّل المصدرُ نفسُه سلفاً.
     *
     * @param  array       $entryAttrs  خصائصُ `JournalEntry` (doc_no مقترَحٌ، date، description…)
     * @param  array       $lines       سطورٌ: acc_id · debit · credit · cc_id? · memo?
     * @param  array|null  $source      رابطُ المصدر: module · id · key (الحدثُ داخل المصدر)
     */
    public static function postBalanced(array $entryAttrs, array $lines, ?array $source = null): JournalEntry
    {
        foreach ($lines as $line) {
            if ($why = self::lineError((array) $line)) {
                throw ValidationException::withMessages(['lines' => $why . ' — لا يُرحَّل قيدٌ أعرج']);
            }
        }
        ['debit' => $d, 'credit' => $c] = self::totals($lines);
        if ($d <= 0 || $d !== $c) {
            throw ValidationException::withMessages(['state' =>
                'لا يُرحَّل قيدٌ لا يوازن: مدين ' . self::fmt($d) . ' ≠ دائن ' . self::fmt($c)]);
        }

        $src = self::sourceAttrs($source);

        JournalEntry::$posting = true;   // الحارسُ في النموذج يُخلي لهذه الكتلة — الفحصُ أعلاه بديلُه
        try {
            // محاولاتٌ قليلة: سباقُ رقم القيد بين معاملتين يُسقطه الفهرسُ الفريد فيُعاد التخصيصُ بلاحقة
            for ($attempt = 1; ; $attempt++) {
                try {
                    return DB::transaction(function () use ($entryAttrs, $lines, $src, $d) {
                        // بلا `lockForUpdate`: قفلُ فجوةٍ على مفتاحٍ غائبٍ في فهرسٍ فريد يُعاطل ترحيلين
                        // لمصدرين مختلفين في InnoDB — والفهرسُ الفريدُ على المصدر هو الحاجزُ الحقيقيّ
                        if ($src && ($existing = self::existing($src))) return self::sameAmount($existing, $d);

                        $meta = (array) ($entryAttrs['meta'] ?? []);
                        $meta['posted_at'] ??= now()->toIso8601String();

                        $entry = JournalEntry::create(array_merge($entryAttrs, $src, [
                            'doc_no' => self::allocateNumber($entryAttrs['doc_no'] ?? null),
                            'date' => $entryAttrs['date'] ?? now()->toDateString(),
                            'state' => self::STATE,
                            'meta' => $meta,
                        ]));
                        foreach ($lines as $line) {
                            JournalLine::create(array_merge(['entry_id' => $entry->id], (array) $line));
                        }

                        self::audit($entry, $d, $src);

                        return $entry;
                    });
                } catch (UniqueConstraintViolationException $e) {
                    // سباقٌ عبر المعاملات: سبقنا ترحيلُ المصدر نفسِه — قيدُه هو القيد (بالمبلغ نفسِه)
                    if ($src && ($existing = self::existing($src))) return self::sameAmount($existing, $d);
                    // وإلّا فرقمُ القيد: التزمت معاملةٌ أخرى بالرقم بين الفحص والإدراج — يُعاد التخصيص
                    if ($attempt >= 3) throw $e;
                }
            }
        } finally {
            JournalEntry::$posting = false;
        }
    }

    /**
     * القيدُ القائمُ للمصدر يُعاد **إن كان بالمبلغ نفسِه** — وإلّا فالمصدرُ تغيّر بعد ترحيله
     * (مسيّرٌ عُدّل ثم أُعيد اعتمادُه): إعادةُ القديم صامتاً تُفرّق الدفترَ عن مصدره بلا أثر.
     */
    private static function sameAmount(JournalEntry $existing, int $mills): JournalEntry
    {
        $had = self::entryTotals($existing->id)['debit'];
        if ($had !== $mills) {
            throw ValidationException::withMessages(['source' =>
                'المصدرُ مُرحَّلٌ سلفاً بمبلغٍ مختلف (' . self::fmt($had) . ' ≠ ' . self::fmt($mills)
                . ') — اعكس القيدَ القائم ' . $existing->doc_no . ' أوّلاً ثمّ رحّل من جديد']);
        }

        return $existing;
    }

    /**
     * **ترحيلُ مسودةٍ قائمة** (زرُّ «ترحيل»): فحصٌ بقفلٍ صفّيّ ثم قلبُ الحالة — والحارسُ
     * في النموذج (`guard`) يعيد الفحصَ ويختم، و`audit` بعد الحفظ. يُنادى داخل معاملةٍ.
     */
    public static function postDraft(JournalEntry $fresh): JournalEntry
    {
        abort_if($fresh->state === self::STATE, 422, 'القيد مُرحَّل أصلاً');

        ['debit' => $d, 'credit' => $c] = self::entryTotals($fresh->id);
        abort_if($d <= 0, 422, 'لا يُرحَّل قيدٌ بلا سطور');
        abort_if($d !== $c, 422, 'القيد لا يوازن: مدين ' . self::fmt($d) . ' ≠ دائن ' . self::fmt($c));

        $fresh->state = self::STATE;
        $fresh->save();   // saving → guard (توازنٌ + ختم) · saved → audit

        return $fresh;
    }

    /**
     * **حارسُ الدخول إلى «مرحّل» لكلّ بابٍ غيرِ آليّ** — يستدعيه `JournalEntry::saving`
     * فيسري على الزرّ والنموذج العامّ والـAPI وسحب الكانبان: قيدٌ لا يوازن (بالملّيمات)
     * لا يُرحَّل، والمرحَّلُ يُختم بوقته وفاعله.
     */
    public static function guard(JournalEntry $m): void
    {
        ['debit' => $d, 'credit' => $c] = $m->getKey() ? self::entryTotals($m->getKey()) : ['debit' => 0, 'credit' => 0];
        if ($d <= 0 || $d !== $c) {
            throw ValidationException::withMessages(['state' =>
                'لا يُرحَّل قيدٌ لا يوازن: مدين ' . self::fmt($d)
                . ' ≠ دائن ' . self::fmt($c) . ' — أضف سطورَه أولاً']);
        }

        $meta = (array) $m->meta;
        $meta['posted_at'] ??= now()->toIso8601String();
        if (auth()->id()) $meta['posted_by'] ??= auth()->id();
        $m->meta = $meta;
    }

    /**
     * قيدُ التدقيق الواحد للترحيل: الفعلُ `ترحيل قيد` على `(entries, id)` باسم رقم
     * القيد، والمبلغُ والمصدرُ في `after` — الشكلُ نفسُه آلياً ويدوياً.
     */
    public static function audit(JournalEntry $entry, ?int $debitMills = null, array $src = []): void
    {
        $debitMills ??= self::entryTotals($entry->id)['debit'];
        $src = $src ?: array_filter(array_intersect_key($entry->getAttributes(), array_flip(self::SOURCE_COLS)));

        hub_audit(self::AUDIT_ACTION, 'entries', $entry->id, (string) $entry->doc_no, ['after' => array_filter([
            'المبلغ' => self::fmt($debitMills),
            'المصدر' => $src ? implode(':', array_filter([
                $src['source_module'] ?? null, $src['source_id'] ?? null, $src['source_key'] ?? null])) : 'يدوي',
        ])]);
    }

    /* ─────────────────────────── داخلي ─────────────────────────── */

    /**
     * رقمُ القيد: المقترَحُ مقصوصاً لعرض عموده؛ وإن سبقه قيدٌ بالرقم نفسِه يُلحَق
     * `-2`، `-3`… — فلا رقمان متطابقان لقيدين (دفعتان في الثانية نفسِها).
     */
    public static function allocateNumber(?string $proposed): string
    {
        $max = hub_col_max('journal_entries', 'doc_no') ?? 300;
        $base = trim((string) $proposed) !== '' ? (string) $proposed : 'JE-' . now()->format('ymd-His');
        $no = (string) hub_fit($base, $max);

        for ($i = 2; JournalEntry::withTrashed()->where('doc_no', $no)->exists(); $i++) {
            $suffix = '-' . $i;
            $no = hub_fit($base, $max - mb_strlen($suffix)) . $suffix;
        }

        return $no;
    }

    /** رابطُ المصدر أعمدةً — أو [] إن لم يُمرَّر أو لم تُهاجَر الأعمدةُ بعد (نشرٌ قبل ترحيل) */
    private static function sourceAttrs(?array $source): array
    {
        if (! $source || blank($source['module'] ?? null) || blank($source['id'] ?? null)) return [];
        if (! SchemaCache::hasColumns('journal_entries', self::SOURCE_COLS)) return [];

        return [
            'source_module' => mb_substr((string) $source['module'], 0, 60),
            'source_id' => (string) $source['id'],
            'source_key' => mb_substr((string) ($source['key'] ?? 'post'), 0, 120),
        ];
    }

    private static function existing(array $src): ?JournalEntry
    {
        $q = JournalEntry::query();
        $q->withTrashed();
        $q->where($src)->orderBy('id');

        return $q->first();
    }
}
