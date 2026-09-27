<?php

namespace App\Support\Ai\Gateway;

/**
 * **نسبةُ الطلبِ إلى صاحبِه — بلا جدولِ استهلاكٍ في Hub** (المرحلة ٢ · W8 · §١٣).
 *
 * ── **المبدأ: لا قياسَ مكرَّر** ──
 *
 * البوّابةُ تملك `LiteLLM_SpendLogs` و`DailyUserSpend` و`DailyTeamSpend`
 * و`DailyTagSpend`. فبناءُ جدولِ قياسٍ في Hub **تكرارٌ يفترق عن الأصلِ خلال
 * أسابيع** — ثمّ يُصدَّق أحدُهما عشوائيّاً حين يختلفان.
 *
 * **وما لا تعرفه البوّابةُ بحال:** مَن المستخدمُ، وأيُّ ميزةٍ طلبت، ولأيِّ
 * شركة. فهذه وحدَها ما يحقنه Hub — **ولا يخزّن منها شيئاً**.
 *
 * ── **والمفاتيحُ الثلاثةُ غيرُ شخصيّةٍ بالبناءِ لا بالوعد** ──
 *
 * `hub_user_ref` **مُلخَّصٌ لا معرّف**: بصمةُ `hash_hmac` بمفتاحِ التطبيق. فهي
 * ثابتةٌ للمستخدمِ الواحدِ (فيصحّ التجميع) **ولا تُردّ إلى هويّة**، ولا تُقارَن
 * عبر نشرتين لأنّ المفتاحَ يختلف. والبديلُ — إرسالُ المعرّفِ أو البريدِ — يضع
 * هويّةَ موظّفٍ في سجلِّ خدمةٍ خارجيّةٍ لا نحكمها.
 *
 * **ولا اسمَ ولا بريدَ ولا معرّفَ خامّ يخرج من هنا** — واختبارٌ يزرع الثلاثةَ
 * ويُثبت أنّها لا تبلغ الحمولة.
 *
 * ── **والمرحلةُ ٢ لا تنشئ جدولاً واحداً** ──
 *
 * تضمن أنّ **مفاتيحَ النسبةِ تُرسَل** فلا ينغلق الطريقُ أمام الفوترةِ لاحقاً.
 * وإن احتاج التجميعُ تسريعاً فجدولٌ **مشتقٌّ قابلٌ لإعادةِ البناء** — لا
 * مصدرُ حقيقةٍ ثانٍ.
 */
final class AiUsage
{
    /** المفاتيحُ الثلاثةُ التي يحقنها Hub — ولا رابعَ لها */
    public const ATTRIBUTION = ['hub_user_ref', 'hub_feature', 'hub_company_ref'];

    /** طولُ البصمة — يكفي للتفريقِ ولا يكفي للاستدلال */
    public const REF_LEN = 16;

    /**
     * **حمولةُ `metadata` لطلبٍ واحد.**
     *
     * @param  string  $feature  اسمُ الميزةِ الطالبةِ في Hub (`ask` · `summarize` …)
     * @return array{hub_user_ref: ?string, hub_feature: string, hub_company_ref: ?string}
     */
    public static function metadata(string $feature, mixed $user = null, ?string $companyId = null): array
    {
        $u = $user ?? auth()->user();

        return [
            'hub_user_ref'    => self::ref(is_object($u) ? ($u->id ?? null) : $u),
            'hub_feature'     => self::slug($feature),
            'hub_company_ref' => self::ref($companyId),
        ];
    }

    /**
     * **بصمةٌ لا معرّف** — ثابتةٌ للقيمةِ الواحدةِ ولا تُردّ إليها.
     *
     * و`app.key` في البصمةِ مقصود: يجعلها **محليّةَ هذه النشرةِ وحدَها**،
     * فلا تُقارَن بصمةُ موظّفٍ هنا ببصمتِه في نشرةٍ أخرى.
     */
    public static function ref(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') return null;

        return substr(hash_hmac('sha256', $v, (string) config('app.key')), 0, self::REF_LEN);
    }

    /** اسمُ ميزةٍ آمنٌ للسجلّ — حروفٌ وأرقامٌ وشرطاتٌ لا أكثر */
    private static function slug(string $feature): string
    {
        $s = mb_strtolower(trim($feature));
        $s = (string) preg_replace('~[^a-z0-9._-]+~', '-', $s);
        $s = trim($s, '-');

        return $s === '' ? 'unknown' : mb_substr($s, 0, 60);
    }
}
