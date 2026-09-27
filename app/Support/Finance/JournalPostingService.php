<?php

namespace App\Support\Finance;

use App\Models\JournalEntry;

/**
 * خدمةُ الترحيل المحاسبيّ المشترَكة (Work OS · الطور E · WP-E.2 · §21a/b) — **بابُ توافقٍ**.
 *
 * كانت هنا كتلةُ الترحيل التي استُخرجت من `FinController` و`PayrollController`: البوابةُ،
 * وخريطةُ `finance.accounts`، وحلُّ الرمز المُحصَّر، و`postBalanced` برايةِ التجاوز —
 * **لكنّها لا تفحص التوازن ولا تمنع ترحيلَ المصدر مرّتين ولا تدقّق** (TECH_DEBT #29).
 * صار ذلك كلُّه في المحرّك الواحد `JournalPosting`، وهذه الخدمةُ تفوّض إليه حرفاً بحرف
 * — فمن ينادي البابَ القديم يمرّ بالثوابت نفسِها. الشيفرةُ الجديدةُ تنادي المحرّكَ مباشرة.
 */
class JournalPostingService
{
    public function enabled(): bool
    {
        return JournalPosting::enabled();
    }

    public function accountsMap(): array
    {
        return JournalPosting::accountsMap();
    }

    public function resolveAccount(?string $code, ?string $companyId): ?string
    {
        return JournalPosting::resolveAccount($code, $companyId);
    }

    /** تفويضٌ إلى المحرّك الواحد — بمعاملته وحرّاسه ورابط مصدره */
    public function postBalanced(array $entryAttrs, array $lines, ?array $source = null): JournalEntry
    {
        return JournalPosting::postBalanced($entryAttrs, $lines, $source);
    }
}
