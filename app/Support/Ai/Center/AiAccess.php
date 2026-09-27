<?php

namespace App\Support\Ai\Center;

/**
 * **بابُ مركزِ الذكاء — موضعٌ واحدٌ لا خمسةٌ متفرّقة** (المرحلة ٢ · W8 · §١٠).
 *
 * كانت خمسةُ متحكّماتٍ تكتب `gate()` نفسَها نسخاً. والنسخُ يعمل اليوم ويفترق
 * غداً: يُضاف علمٌ في أربعةٍ ويُنسى في الخامس، **فيُصَدُّ من يُرى له الرابط**
 * أو — وهو الأسوأ — يُفتَح ما يجب أن يُغلَق.
 *
 * ── **ثلاثُ بوّاباتٍ لا واحدة** ──
 *
 *  · `view()` — قراءةٌ محضة: المزوّدون والنماذجُ والتوجيهُ والتشخيص.
 *  · `manage()` — الكتابة: الاعتماداتُ والنماذجُ والسلاسلُ والإعدادات.
 *  · `cost()` — أرقامُ الإنفاق: **تُعاد استعمالُ `hub_monitor()` القائمة** ولا
 *    تُخترَع لها رايةٌ سادسة (§١٠).
 *
 * ── **ولمَ رايةٌ واحدةٌ جديدةٌ لا تسع؟** ──
 *
 * التفتيتُ يبدو أدقَّ ويُنتج في الواقع أدواراً لا أحدَ يفهمها. **والحدُّ
 * الحقيقيُّ ليس علماً بل `hub_require_stepup`**: كلُّ كتابةِ اعتمادٍ أو توجيهٍ
 * تطلب هويّةً طازجةً مهما كان العلم.
 *
 * ── **و`aiView` لا يرى حالةَ الاعتماد** ──
 *
 * الخطّةُ تنصّ: «قراءةٌ فقط… **بلا كتابةٍ وبلا حالةِ اعتماد**». وحالةُ
 * الاعتمادِ ليست سرّاً لكنّها **خريطةُ من يملك المفاتيح**: من يعرف أنّ مزوّداً
 * بعينِه «مضبوطٌ ولم يُختبر» يعرف أين الثغرة. فالقارئُ يرى أنّ المزوّدَ يعمل
 * أو لا يعمل، ولا يرى لماذا.
 */
final class AiAccess
{
    /** الرايةُ الواسعةُ — القائمةُ منذ المرحلة ١ ولا تُمَسّ */
    public const MANAGE_FLAG = 'aiAdmin';

    /** **الرايةُ الجديدةُ الوحيدة** (§١٠) — قراءةٌ لا كتابة */
    public const VIEW_FLAG = 'aiView';

    /** أيرى هذا المستخدمُ المركزَ أصلاً؟ */
    public static function canView(mixed $user = null): bool
    {
        $u = $user ?? auth()->user();

        // **والمستخدمُ يُمرَّر إلى `hub_is_owner` لا يُفترَض من الجلسة**:
        // الشريطُ الجانبيُّ ومُشخِّصُ الوصولِ يبنيان لمستخدمٍ **غيرِ** صاحبِ
        // الجلسة، فافتراضُ الجلسةِ هنا كان سيقول «مالك» عن غيرِ مالك.
        return hub_is_owner($u) || hub_flag($u, self::MANAGE_FLAG) || hub_flag($u, self::VIEW_FLAG);
    }

    /** أيكتب فيه؟ — والقراءةُ لا تُرقّى إلى كتابةٍ بحال */
    public static function canManage(mixed $user = null): bool
    {
        $u = $user ?? auth()->user();

        return hub_is_owner($u) || hub_flag($u, self::MANAGE_FLAG);
    }

    /**
     * **أرقامُ الإنفاق — رقابةٌ قائمةٌ تُعاد استعمالُها لا رايةٌ سادسة** (§١٠).
     *
     * و`hub_monitor_group('finAnalytics')` هي البابُ الدقيقُ لا `hub_monitor`
     * الجامعة: محاسبٌ نال لوحاتِ التكاليفِ يرى إنفاقَ الذكاءِ كما يرى بقيّةَ
     * التكاليف، وحاملُ `monitor` يدخل كما كان — فلا هجرةَ ولا تضييق.
     */
    public static function canSeeCost(mixed $user = null): bool
    {
        $u = $user ?? auth()->user();

        return self::canView($u) && (hub_is_owner($u) || hub_monitor_group('finAnalytics', $u));
    }

    /** **حالةُ الاعتمادِ للمدير وحدَه** — القارئُ يرى «يعمل» لا «لماذا لا» */
    public static function showsCredentialState(mixed $user = null): bool
    {
        return self::canManage($user);
    }

    // ── الحرّاسُ الملقيةُ ────────────────────────────────────────────────

    public static function gateView(): void
    {
        abort_unless(self::canView(), 403, 'مركزُ الذكاء الاصطناعيّ يحتاج صلاحيّةَ الاطّلاعِ عليه');
    }

    public static function gateManage(): void
    {
        abort_unless(self::canManage(), 403, 'مركزُ الذكاء الاصطناعيّ يحتاج صلاحيّةَ إدارتِه');
    }

    public static function gateCost(): void
    {
        self::gateView();
        abort_unless(self::canSeeCost(), 403, 'أرقامُ الإنفاق تحتاج صلاحيّةَ الرقابة');
    }

    /**
     * **أقسامُ المركزِ التسعةُ — مصدرُ حقيقةِ التنقّلِ الواحد** (§١١ · مُدَّت بقسمَي الحوكمة في المرحلة ٤).
     *
     * ويُبنى منها شريطُ الأقسامِ في كلِّ شاشة، **وتُقاس منها بوّابةُ كلِّ
     * قسمٍ في الاختبار**. فقسمٌ يُعرَض ولا يُفتَح عيبٌ يُكشَف آليّاً لا بالعين.
     *
     * @return list<array{key: string, label: string, icon: string, route: string, ok: bool}>
     */
    public static function sections(mixed $user = null): array
    {
        $view   = self::canView($user);
        $manage = self::canManage($user);
        $cost   = self::canSeeCost($user);

        return [
            ['key' => 'overview',    'label' => 'نظرة',     'icon' => '📊', 'route' => 'ai.index',            'ok' => $view],
            ['key' => 'providers',   'label' => 'المزوّدون', 'icon' => '🔌', 'route' => 'ai.providers.index',  'ok' => $view],
            ['key' => 'models',      'label' => 'النماذج',   'icon' => '🧠', 'route' => 'ai.models.all',       'ok' => $view],
            ['key' => 'routing',     'label' => 'التوجيه',   'icon' => '🎯', 'route' => 'ai.profiles.index',   'ok' => $view],
            /*
             * ── **قسمانِ للحوكمة** (المرحلة ٤ · P4-W7) ──
             *
             * **والسياساتُ للقارئِ والميزانيّاتُ لصاحبِ الرقابة** — والفرقُ
             * مقصود: السياسةُ تقول «ما المسموح» وهي معرفةٌ يحتاجها من يشخّص
             * منعاً، والميزانيّةُ تقول «كم أُنفق» وهو **رقمُ مالٍ** يخضع
             * لحارسِ الكلفةِ القائمِ (`canSeeCost`) لا لرايةٍ جديدة.
             */
            ['key' => 'policies',    'label' => 'السياسات',  'icon' => '🛡️', 'route' => 'ai.policies.index',   'ok' => $view],
            ['key' => 'budgets',     'label' => 'الميزانيّات', 'icon' => '🧾', 'route' => 'ai.budgets.index',  'ok' => $cost],
            ['key' => 'usage',       'label' => 'الاستهلاك', 'icon' => '💰', 'route' => 'ai.usage',            'ok' => $cost],
            ['key' => 'settings',    'label' => 'الإعدادات', 'icon' => '⚙️', 'route' => 'ai.settings',         'ok' => $manage],
            ['key' => 'diagnostics', 'label' => 'التشخيص',   'icon' => '🩺', 'route' => 'ai.diagnostics',      'ok' => $view],
            // المدقّق (A4): دقّةُ كواشفه — أعدادٌ لا محتوى، فلقارئِ المركز
            ['key' => 'auditor',     'label' => 'المدقّق',   'icon' => '🔎', 'route' => 'ai.auditor',          'ok' => $view],
        ];
    }
}
