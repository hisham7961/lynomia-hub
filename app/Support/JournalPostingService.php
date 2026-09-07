<?php

namespace App\Support;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

/**
 * خدمةُ الترحيل المحاسبيّ المشترَكة (Work OS · الطور E · WP-E.2 · §21a/b).
 *
 * **لا دفترَ ثانٍ ولا نسخةَ ثالثة:** كان منطقُ ترحيلِ القيد الآليّ مكرَّراً حرفاً
 * بحرفٍ في `FinController::autoJournal` و`PayrollController::autoJournal` — بوابةُ
 * `finance.auto_journal`، وقراءةُ خريطة `finance.accounts`، وحلُّ رمز الحساب إلى
 * معرّفٍ **مُحصَّرٍ بالشركة ثم بترتيب id** (لا قرعة)، وكتلةُ الترحيل التي ترفع رايةَ
 * `JournalEntry::$posting` حول معاملةٍ تبني القيدَ المرحَّلَ ثمّ سطورَه الموزونة.
 * كلُّ هذا استُخرِج إلى هنا مرّةً واحدة: يستهلكه الآن الترحيلُ الماليّ والرواتب
 * **والعهدةُ المالية** (عبر `CustodyPostingService`) — كلُّ أثرٍ ماليٍّ عبر
 * `JournalEntry` الوحيد، لا محرّكَ محاسبةٍ موازٍ.
 *
 * **السلوكُ محفوظٌ حرفيّاً:** ما يكتبه المتحكّمان بعد الاستخراج مطابقٌ لما كانا
 * يكتبانه قبله (نفسُ البوابة، نفسُ الحلّ الحتميّ، نفسُ القيد وسطريه) — الاستخراجُ
 * إعادةُ هيكلةٍ لا تغييرَ سلوك، تحرسه اختباراتُ `PayrollJournalTest`
 * و`FinalAuditLedgerCompanyTest` القائمة (نتائجُ القاعدة نفسُها).
 */
class JournalPostingService
{
    /**
     * هل الترحيلُ الآليّ مفعَّل — البوابةُ القائمة نفسُها.
     *
     * بالسلسلة لا بالنوع: `hub:set` يخزّن القيمةَ عدداً فتفشل `===` النوعيّة (داءُ
     * `contracts.auto_expire` نفسُه الذي أصابَ الرواتبَ قبلُ) — فالمقارنةُ على النصّ.
     */
    public function enabled(): bool
    {
        return (string) setting('finance.auto_journal') === '1';
    }

    /** خريطةُ حسابات الترحيل الآليّ، مقروءةً موحّداً (مصفوفةً كانت أو JSON) */
    public function accountsMap(): array
    {
        $map = setting('finance.accounts');

        return is_array($map) ? $map : (json_decode((string) $map, true) ?: []);
    }

    /**
     * يحلّ رمزَ حسابٍ إلى معرّفه، **مُحصَّراً بالشركة ثم بترتيب id** — لا قرعة.
     *
     * `ledger_accounts.code` غير فريدٍ و`company_id` قد يتكرّر عبر الشركات، فبلا
     * التحصير يلتصق سطرُ قيدِ شركةٍ بحساب شركةٍ أخرى. نفضّل حسابَ شركة المصدر، فحساباً
     * عامّاً (company_id فارغ) احتياطاً، وترتيبُ id يحسم التعادل حتميّاً على المحرّكين.
     * رمزٌ فارغٌ أو لا يطابق حساباً → `null` (فلا قيدٌ أعرجُ يُبنى فوقه).
     */
    public function resolveAccount(?string $code, ?string $companyId): ?string
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

    /**
     * يبني قيداً مرحَّلاً موزوناً بسطورٍ داخل معاملةٍ واحدة، رافعاً رايةَ
     * `JournalEntry::$posting` حول كتلته فقط (نمطُ `StockMove::$posting`) ليمرّ
     * حارسُ التوازن في النموذج بينما تُكتب السطور. المعاملةُ تضمن ألّا يبقى القيدُ
     * بسطرٍ واحدٍ إن تعثّر ما بعده — و`JournalEntry::booted` يقفله بعد الترحيل فلا
     * يُصحَّح إلا بقيدِ عكس. يعيد القيدَ المُنشأ.
     *
     * @param  array  $entryAttrs  خصائصُ `JournalEntry` (state='مرحّل' وmeta وغيرها)
     * @param  array  $lines       سطورُ القيد؛ كلُّ سطرٍ مصفوفةُ خصائص `JournalLine` بلا entry_id
     */
    public function postBalanced(array $entryAttrs, array $lines): JournalEntry
    {
        JournalEntry::$posting = true;
        try {
            return DB::transaction(function () use ($entryAttrs, $lines) {
                $entry = JournalEntry::create($entryAttrs);
                foreach ($lines as $line) {
                    JournalLine::create(array_merge(['entry_id' => $entry->id], $line));
                }

                return $entry;
            });
        } finally {
            JournalEntry::$posting = false;
        }
    }
}
