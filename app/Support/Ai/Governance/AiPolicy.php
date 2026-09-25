<?php

namespace App\Support\Ai\Governance;

use App\Models\AiPolicyRule;
use App\Support\Ai\Ask\AskFailures;

/**
 * **محرّكُ سياسةِ الذكاء** (المرحلة ٤ · P4-W1).
 *
 * ── **المعادلةُ الحاكمةُ — وهي مفروضةٌ بالبناءِ لا بالوعد** ──
 *
 * ```
 * الوصولُ الفعليّ = تخويلُ Hub  ∩  سياسةُ الذكاء
 * ```
 *
 * والتقاطعُ يعني شيئين معاً، ولا يُكتفى بأحدِهما:
 *
 *  · **صلاحيّةُ Hub وحدَها لا تكفي** — سياسةٌ تمنع تمنع ولو كان البابُ مفتوحاً.
 *  · **والسياسةُ لا تمنح ما لا يملكه** — ولو كُتب صفُّ «اسمح» لمن لا صلاحيّةَ له.
 *
 * **والثاني مفروضٌ في الشيفرةِ لا في التوثيق:** `evaluate()` تأخذ
 * `hub_allowed` وتردّ فوراً إن كانت `false`، **قبل أن تقرأ صفّاً واحداً**.
 * فلا مسارَ — ولو بخطأٍ في مُستدعٍ — يجعل صفَّ سياسةٍ بابَ دخول.
 *
 * ── **وليست ACL موازياً — والفرقُ ليس لفظيّاً** ──
 *
 * `hub_can` و`hub_scope` و`hub_field_mode` تبقى حارسَ **البياناتِ والأدوات**:
 * من لا يرى وحدةً في الشاشةِ لا يراها بالسؤال، ولا صفَّ هنا يغيّر ذلك. وما
 * تحكمه هذه الطبقةُ **الإنفاقُ والتوليدُ واختيارُ النموذج** — أي ما ليس له
 * مالكٌ في نظامِ الصلاحيّاتِ أصلاً.
 *
 * ── **ومنعٌ بالأصلِ حيث يصحّ — وتوافقٌ خلفيٌّ مدروس** ──
 *
 * نظامٌ بلا صفٍّ واحدٍ يعمل **كما كان حرفاً**. والمنعُ بالأصلِ يُفعَّل
 * **بُعداً بُعداً عند أوّلِ صفٍّ يُسمّي ذلك البُعد**:
 *
 * > إن سمّى صفُّ «اسمح» نموذجاً، صار بُعدُ النماذجِ **قائمةَ سماحٍ**: ما ليس
 * > فيها ممنوع.
 *
 * وهذا هو الاصطلاحُ الكلاسيكيّ، **وهو الوحيدُ الذي لا يكسر نظاماً قائماً
 * بلا تفسير**: من لم يكتب صفّاً لم يضيّق شيئاً، ومن كتب «اسمح بهذين» قال
 * صراحةً «ولا ثالثَ لهما».
 */
final class AiPolicy
{
    public const ALLOW = 'allow';
    public const DENY  = 'deny';
    public const EFFECTS = [self::ALLOW, self::DENY];

    /** الأخصُّ آخراً — ويُقدَّم عند تساوي الأثرِ والأولويّة */
    public const SCOPES = ['global', 'company', 'role', 'user'];

    /** الأبعادُ التي تصير قوائمَ سماحٍ عند أوّلِ صفٍّ يُسمّيها */
    public const ALLOWLIST_DIMS = ['purpose', 'provider_id', 'model_id'];

    /** شكلُ الحكمِ الموحَّد — لا شكلَ يُخترَع في مُستدعٍ */
    public const SHAPE = ['allowed', 'code', 'why', 'limits', 'matched', 'tools'];

    /**
     * **الحكمُ على عمليّةِ ذكاءٍ واحدة.**
     *
     * @param  array{hub_allowed?:bool, user?:mixed, company_id?:?string, purpose?:?string,
     *               feature?:?string, provider_id?:?string, model_id?:?string,
     *               capability?:?string}  $ctx
     * @return array{allowed:bool, code:?string, why:?string,
     *               limits:array{max_output_tokens:?int, max_calls_per_request:?int},
     *               matched:list<string>, tools:bool}
     */
    public static function evaluate(array $ctx): array
    {
        /*
         * ── **التقاطعُ أوّلاً — قبل أيِّ قراءة** ──
         *
         * لو قُدِّمت قراءةُ الصفوفِ لَصار سطرٌ واحدٌ منسيٌّ في مُستدعٍ كافياً
         * ليجعل «اسمح» تمنح ما لا يملكه صاحبُه. **والترتيبُ هنا هو الضمان.**
         */
        if (($ctx['hub_allowed'] ?? false) !== true) {
            return self::verdict(false, AskFailures::UNAUTHORIZED,
                'لا تخويلَ في Hub — والسياسةُ لا تمنح ما لا يملكه صاحبُه');
        }

        $rules = self::applicable($ctx);

        // ① **المنعُ يغلب** — مهما كانت أولويّتُه ومهما قابله من «اسمح»
        foreach ($rules as $r) {
            if ((string) $r->effect !== self::DENY) continue;
            if (! self::matches($r, $ctx)) continue;

            return self::verdict(false, AskFailures::POLICY_DENIED,
                self::why($r, 'منعٌ صريحٌ من سياسة'), [(string) $r->key]);
        }

        $allow = array_values(array_filter($rules,
            static fn ($r) => (string) $r->effect === self::ALLOW));

        // ② قوائمُ السماح — بُعداً بُعداً، ولا يُفعَّل بُعدٌ لم يُسمَّ
        foreach (self::ALLOWLIST_DIMS as $dim) {
            $named = array_values(array_filter($allow,
                static fn ($r) => ($r->{$dim} ?? null) !== null && $r->{$dim} !== ''));

            if ($named === []) continue;   // بُعدٌ لم يُسمَّ ⇒ لا تضييقَ فيه

            $value = $ctx[$dim] ?? null;
            $ok    = false;
            foreach ($named as $r) {
                if ((string) $r->{$dim} === (string) $value && self::matches($r, $ctx)) {
                    $ok = true;
                    break;
                }
            }

            if (! $ok) {
                return self::verdict(false, AskFailures::POLICY_DENIED,
                    'السياسةُ تحصر ' . self::dimLabel($dim) . ' في قائمةٍ لا يشملها هذا الطلب');
            }
        }

        // ③ قراراتٌ ثلاثيّة: `false` تمنع، و`null` لا رأيَ لها
        $matched = [];
        $tools   = true;
        $limits  = ['max_output_tokens' => null, 'max_calls_per_request' => null];

        foreach ($allow as $r) {
            if (! self::matches($r, $ctx)) continue;
            $matched[] = (string) $r->key;

            if ($r->allow_generation === false) {
                return self::verdict(false, AskFailures::POLICY_DENIED,
                    self::why($r, 'التوليدُ ممنوعٌ بهذه السياسة'), [(string) $r->key]);
            }
            if ($r->allow_tools === false) $tools = false;

            // **الأشدُّ يفوز** — وسقفانِ متطابقانِ يُنتجان الأصغر
            foreach ($limits as $k => $cur) {
                $v = $r->{$k} ?? null;
                if ($v === null || (int) $v <= 0) continue;
                $limits[$k] = $cur === null ? (int) $v : min($cur, (int) $v);
            }
        }

        return self::verdict(true, null, null, $matched, $limits, $tools);
    }

    /** أمسموحٌ بحالٍ؟ — اختصارٌ لمن لا يحتاج التفصيل */
    public static function allows(array $ctx): bool
    {
        return (bool) self::evaluate($ctx)['allowed'];
    }

    /**
     * **الصفوفُ التي يشملها نطاقُ هذا الطلب** — مرتّبةً بالأولويّةِ ثمّ الخصوصيّة.
     *
     * **والترتيبُ ينتهي بـ`id`** — فصفّان بالأولويّةِ نفسِها والنطاقِ نفسِه
     * قرعةٌ بلا ذلك: MariaDB تُعيدهما بترتيبِ الإدراجِ صدفةً وMySQL 8 بترتيبٍ
     * آخر، فيُقرَأ «لماذا مُنعت» صفّاً مختلفاً على كلِّ خادم.
     *
     * @return list<AiPolicyRule>
     */
    public static function applicable(array $ctx): array
    {
        $user      = $ctx['user'] ?? null;
        $userId    = is_object($user) ? (string) ($user->id ?? '') : (string) ($ctx['user_id'] ?? '');
        $roleId    = is_object($user) ? (string) ($user->role_id ?? '') : '';
        $companyId = (string) ($ctx['company_id'] ?? '');

        $rows = AiPolicyRule::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderByRaw(self::scopeOrderSql())
            ->orderBy('id')
            ->get();

        return array_values($rows->filter(static function ($r) use ($userId, $roleId, $companyId) {
            return match ((string) $r->scope_type) {
                'global'  => true,
                'company' => $companyId !== '' && (string) $r->scope_id === $companyId,
                'role'    => $roleId    !== '' && (string) $r->scope_id === $roleId,
                'user'    => $userId    !== '' && (string) $r->scope_id === $userId,
                default   => false,
            };
        })->all());
    }

    /** **الأخصُّ آخراً** — `CASE` بدل `FIELD()` فهي غيرُ موجودةٍ في SQLite */
    private static function scopeOrderSql(): string
    {
        $when = '';
        foreach (self::SCOPES as $i => $s) {
            $when .= " WHEN '" . $s . "' THEN " . $i;
        }

        return 'CASE scope_type' . $when . ' ELSE 99 END';
    }

    /**
     * **أيطابق هذا الصفُّ أبعادَ الطلب؟**
     *
     * و`null` في الصفِّ تعني **«أيُّ قيمة»** لا «لا قيمة». فصفٌّ بلا غرضٍ
     * يطابق كلَّ غرض، وصفٌّ بغرضِ `general` لا يطابق غيرَه.
     */
    public static function matches(AiPolicyRule $r, array $ctx): bool
    {
        foreach (['purpose', 'feature', 'provider_id', 'model_id', 'capability'] as $dim) {
            $want = $r->{$dim} ?? null;
            if ($want === null || $want === '') continue;
            if ((string) $want !== (string) ($ctx[$dim] ?? '')) return false;
        }

        return true;
    }

    // ── الداخل ────────────────────────────────────────────────────────

    private static function dimLabel(string $dim): string
    {
        return match ($dim) {
            'purpose'     => 'الأغراض',
            'provider_id' => 'المزوّدين',
            'model_id'    => 'النماذج',
            default       => $dim,
        };
    }

    /** **سببُ الصفِّ كما كتبه المدير** — وإلّا جملةٌ عامّةٌ لا تَعِد بما لا تعرف */
    private static function why(AiPolicyRule $r, string $fallback): string
    {
        $notes = trim((string) ($r->notes ?? ''));

        return $notes === '' ? $fallback . ' «' . $r->label . '»' : $notes;
    }

    private static function verdict(bool $allowed, ?string $code, ?string $why,
                                    array $matched = [], ?array $limits = null,
                                    bool $tools = true): array
    {
        return [
            'allowed' => $allowed,
            'code'    => $code,
            'why'     => $why,
            'limits'  => $limits ?? ['max_output_tokens' => null, 'max_calls_per_request' => null],
            'matched' => array_values(array_unique($matched)),
            'tools'   => $tools,
        ];
    }
}
