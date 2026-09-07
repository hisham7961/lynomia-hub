<?php

namespace App\Support;

use App\Models\EmployeeCustodyMove;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * خدمةُ ترحيلِ العهدة المالية (Work OS · الطور E · WP-E.2 · §21a/b).
 *
 * **تُرحِّل في الدفتر الوحيد لا في دفترٍ ثانٍ:** لا نسخةً ثالثةً من منطق الترحيل —
 * القيدُ المحاسبيُّ المتوازن يُبنى عبر `JournalPostingService::postBalanced`
 * المشترَكة نفسِها التي يستهلكها الترحيلُ الماليّ والرواتب. هذه الخدمةُ تُضيف فوقها
 * خصوصيّةَ العهدة: سطرُ حسابِ العهدة (رمزُ `finance.accounts.custody`) مقابلَ
 * طرفٍ نقديٍّ/مصروفيّ، واتّجاهُ المدين/الدائن من إشارة الحركة.
 *
 * **حركةٌ واحدةٌ وقيدٌ واحدٌ للمصدر الواحد:** ترحيلُ المصدرِ نفسِه مرّتين (اعتمادُ
 * المصروفِ مرّتين، نقرتان متزامنتان) يُنتج **حركةً واحدةً وقيداً واحداً**:
 *  - فحصٌ تحت `lockForUpdate` في مستهلّ المعاملة يُسلسِل النقرتين المتزامنتين،
 *  - و`UNIQUE(source_module, source_id, kind)` على القاعدة يحرس السباقَ عبر
 *    المعاملات (حاجزُ الترحيل المزدوج — نمط 2026_09_02_000001).
 *
 * **القفلُ بـ`meta.custody_move_id`:** القيدُ المُنشأ يحمل في `meta` معرّفَ حركةِ
 * العهدة، والحركةُ تحمل `entry_id` — رباطٌ ثنائيٌّ لا يُرحَّل مصدرٌ مرّتين. الترحيلُ
 * كلُّه خلفَ بوابة `finance.auto_journal` القائمة؛ ودفترُ العهدة (الحركاتُ) هو
 * مصدرُ الرصيد المشتقّ ويُكتب دائماً، بينما القيدُ مرآةٌ محاسبيّةٌ مشروطةٌ بالبوابة
 * (تماماً كالدفعةِ الماليّةِ واعتمادِ الرواتب: العمليةُ تتمّ والقيدُ أفضلُ جهدٍ مبوَّب).
 */
class CustodyPostingService
{
    public function __construct(private ?JournalPostingService $journal = null)
    {
        $this->journal = $journal ?: new JournalPostingService();
    }

    /**
     * يُنشئ حركةَ عهدةٍ من مصدرٍ ويُرحّلها — idempotent ومُسلسَل. يعيد الحركةَ
     * (القائمةَ إن سبق ترحيلُ المصدر نفسِه) فلا حركتان ولا قيدان لمصدرٍ واحد.
     *
     * @param  array  $s  {
     *     employee_id, kind, sign, amount, source_module, source_id,   (إلزاميّة)
     *     company_id?, project_id?, cc_id?, at?, by_id?, approval_state?,
     *     receipt_module?, receipt_id?, meta?,
     *     counterpart?  رمزُ الطرف المقابل صراحةً (وإلا يُشتقّ من النوع),
     *     event?        الحدثُ الخام المبثوث (وإلا يُشتقّ من النوع)
     * }
     */
    public function record(array $s): EmployeeCustodyMove
    {
        $move = DB::transaction(function () use ($s) {
            // قفلٌ + فحص: رُحّل المصدرُ سلفاً؟ نقرتان متزامنتان لا تُنتجان حركتين
            $existing = EmployeeCustodyMove::query()
                ->where('source_module', $s['source_module'])
                ->where('source_id', $s['source_id'])
                ->where('kind', $s['kind'])
                ->lockForUpdate()->first();
            if ($existing) return $existing;

            // نُولّد معرّفَ الحركة سلفاً كي يقفلها القيدُ بـmeta.custody_move_id قبل
            // كتابتها — فتُنشأ الحركةُ مرحَّلةً بـentry_id في كتابةٍ واحدة (بلا تعديلٍ
            // لاحقٍ يصطدم بقفلِ الحركة المُرحَّلة).
            $moveId = (string) Str::uuid();
            $entry = $this->postEntry($moveId, $s);

            $move = new EmployeeCustodyMove();
            $move->id             = $moveId;
            $move->employee_id    = $s['employee_id'];
            $move->company_id     = $s['company_id'] ?? null;
            $move->kind           = $s['kind'];
            $move->amount         = $s['amount'];
            $move->sign           = $s['sign'];
            $move->source_module  = $s['source_module'];
            $move->source_id      = $s['source_id'];
            $move->entry_id       = $entry?->id;
            $move->project_id     = $s['project_id'] ?? null;
            $move->cc_id          = $s['cc_id'] ?? null;
            $move->approval_state = $s['approval_state'] ?? 'approved';
            $move->receipt_module = $s['receipt_module'] ?? null;
            $move->receipt_id     = $s['receipt_id'] ?? null;
            $move->at             = $s['at'] ?? now();
            $move->posted_at      = now();
            $move->by_id          = $s['by_id'] ?? auth()->id();
            $move->meta           = $s['meta'] ?? null;
            $move->save();   // UNIQUE(source_module,source_id,kind) يحرس السباقَ عبر المعاملات

            return $move;
        });

        // الحدثُ الدلاليُّ المُعلَنُ في config('hub.events.custody') — خارجَ المعاملة
        // (نمطُ FlowRunner::fire: لا يكسر العمليةَ الأصلية ولا يُرحَّل ضمنها)
        if ($move->wasRecentlyCreated) {
            $raw = $s['event'] ?? (in_array($move->kind, ['expense', 'deduction', 'settlement'], true)
                ? 'approved' : 'charged');
            FlowRunner::fire($raw, EmployeeCustodyMove::MODULE, $move);
        }

        return $move;
    }

    /**
     * عكسُ حركةٍ مُرحَّلةٍ: صفٌّ معاكسُ الإشارة **وقيدٌ محاسبيٌّ معاكس** (مرآةُ الأصل).
     *
     * لا نسخةَ ثانيةً من منطق العكس: الصفُّ يُنشئه `EmployeeCustodyMove::reverse`
     * (بقفلِه ومنعِ العكس المزدوج ونقرتيه المتزامنتين المُسلسَلتين)، وهذه الخدمةُ
     * تُضيف فوقه القيدَ المعاكس — تُبنى مرآتُه من سطورِ قيدِ الأصل (مدينٌ↔دائن) عبر
     * `postBalanced` المشترَكة، ثمّ يُمرَّر معرّفُه إلى `reverse` فتُنشأ حركةُ العكس
     * مرحَّلةً بقيدها في كتابةٍ واحدة. القيدُ والصفُّ في معاملةٍ واحدة: إن حجب
     * `reverse` عكساً مزدوجاً بعد بناءِ المرآة، رُدّت المرآةُ معه فلا قيدٌ يتيم.
     *
     * القيدُ مشروطٌ بالبوابة كالأصل: أصلٌ بلا قيدٍ (البوابةُ كانت مطفأةً) يُعكس
     * صفّاً بلا قيدٍ — اتّساقٌ لا قيدٌ أعرج.
     */
    public function reverse(EmployeeCustodyMove $orig, ?string $reason = null): EmployeeCustodyMove
    {
        $rev = DB::transaction(function () use ($orig, $reason) {
            $entry = $this->reverseEntry($orig);            // مرآةُ قيدِ الأصل (أو null)

            // reverse يقفل الأصلَ ويحجب العكسَ المزدوج؛ إن رمى رُدّت المرآةُ في المعاملة نفسِها
            return $orig->reverse($reason, $entry?->id);
        });

        FlowRunner::fire('reversed', EmployeeCustodyMove::MODULE, $rev);

        return $rev;
    }

    /**
     * قيدُ العكس = مرآةُ قيدِ الأصل: كلُّ سطرٍ يُقلَب مدينُه دائناً ودائنُه مديناً،
     * فيصفو أثرُ الزوجِ محاسبيّاً كما يصفو رصيدُ الزوجِ في الدفتر. يُقفَل بـ
     * `meta.reverses_entry_id`. لا أصلَ للقيد (البوابةُ مطفأةٌ أو لا سطور) → null.
     */
    private function reverseEntry(EmployeeCustodyMove $orig): ?JournalEntry
    {
        if (! $this->journal->enabled() || $orig->entry_id === null) return null;

        // ترتيبٌ حتميٌّ بـid (C13) — لا قرعةَ في مطابقة سطرِ المرآة بسطرِ الأصل
        $lines = JournalLine::where('entry_id', $orig->entry_id)->orderBy('id')->get();
        if ($lines->isEmpty()) return null;

        $mirror = $lines->map(fn ($l) => [
            'cc_id'  => $l->cc_id,
            'acc_id' => $l->acc_id,
            'debit'  => (float) $l->credit,   // القلب: دائنُ الأصل يصير مدينَ العكس
            'credit' => (float) $l->debit,
            'memo'   => 'عكسُ: ' . (string) $l->memo,
        ])->all();

        return $this->journal->postBalanced([
            'doc_no' => hub_fit('JE-CUST-REV-' . substr((string) $orig->id, 0, 8) . '-' . now()->format('His'),
                hub_col_max('journal_entries', 'doc_no') ?? 300),
            'date' => now()->toDateString(),
            'description' => 'عكسُ حركةِ عهدة: ' . $orig->kind,
            'reference' => 'CUST-REV-' . substr((string) $orig->id, 0, 8),
            'state' => 'مرحّل',
            'project_id' => $orig->project_id,
            'company_id' => $orig->company_id,
            'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => 'custody',
                       'reverses_entry_id' => $orig->entry_id, 'reverses_move_id' => $orig->id],
        ], $mirror);
    }

    /**
     * القيدُ المحاسبيُّ المتوازن للحركة — سطرُ حسابِ العهدة مقابلَ طرفٍ نقديٍّ/مصروفيّ،
     * عبر الخدمة المشترَكة الوحيدة. خلفَ بوابة `finance.auto_journal`؛ وخريطةٌ ناقصةٌ
     * (لا رمزَ عهدةٍ أو لا نظير) → `null` فلا قيدٌ أعرج. يعيد القيدَ أو `null`.
     *
     * الاتّجاهُ من الإشارة: `sign > 0` تُزيد ما بذمّة الموظف (سلفة/شحن) — فحسابُ
     * العهدة (أصلٌ) **مدينٌ** والطرفُ المقابلُ دائن؛ و`sign < 0` تُنقصها
     * (مصروف/سداد) — فحسابُ العهدة دائنٌ والمقابلُ مدين. مبلغٌ واحدٌ على الطرفين
     * فالقيدُ موزون.
     */
    private function postEntry(string $moveId, array $s): ?JournalEntry
    {
        if (! $this->journal->enabled()) return null;

        $map = $this->journal->accountsMap();
        $companyId = $s['company_id'] ?? null;

        $custodyAcc = $this->journal->resolveAccount((string) ($map['custody'] ?? ''), $companyId);

        // الطرفُ المقابل: صراحةً إن مُرِّر، وإلا يُشتقّ من النوع (المصروفُ يقابله حسابُ
        // المصروف، وما عداه النقدُ — البنكُ ثمّ الصندوقُ احتياطاً كنمطِ الرواتب).
        $counterCode = $s['counterpart'] ?? (in_array($s['kind'], ['expense', 'deduction', 'settlement'], true)
            ? ($map['exp'] ?? null)
            : (($map['bank'] ?? null) ?: ($map['cash'] ?? null)));
        $counterAcc = $this->journal->resolveAccount(
            $counterCode !== null ? (string) $counterCode : null, $companyId);

        if (! $custodyAcc || ! $counterAcc) return null;   // خريطةٌ ناقصة — لا قيد أعرج

        $amount = round((float) $s['amount'], 3);
        $custodyDebit = ((int) $s['sign']) > 0;   // +1 → مدين العهدة (أصلٌ يزيد) · -1 → دائنها
        $cc = $s['cc_id'] ?? null;

        return $this->journal->postBalanced([
            // مشتقٌّ من عمودٍ بعرضٍ محدود — يُقصّ لعرض العمود (درسُ notifications_hub / hub_fit)
            'doc_no' => hub_fit('JE-CUST-' . substr($moveId, 0, 8) . '-' . now()->format('His'),
                hub_col_max('journal_entries', 'doc_no') ?? 300),
            'date' => now()->toDateString(),
            'description' => 'عهدةُ موظف: ' . $s['kind'],
            'reference' => 'CUST-' . substr($moveId, 0, 8),
            'state' => 'مرحّل',
            'project_id' => $s['project_id'] ?? null,
            'company_id' => $companyId,
            // القفل: القيدُ مربوطٌ بحركةِ العهدة — لا يُرحَّل مصدرٌ مرّتين
            'meta' => ['posted_at' => now()->toIso8601String(), 'auto' => 'custody', 'custody_move_id' => $moveId],
        ], [
            ['cc_id' => $cc, 'acc_id' => $custodyAcc,
             'debit' => $custodyDebit ? $amount : 0, 'credit' => $custodyDebit ? 0 : $amount,
             'memo' => 'عهدةُ الموظف'],
            ['cc_id' => $cc, 'acc_id' => $counterAcc,
             'debit' => $custodyDebit ? 0 : $amount, 'credit' => $custodyDebit ? $amount : 0,
             'memo' => 'مقابلُ حركةِ العهدة'],
        ]);
    }
}
