<?php

namespace App\Http\Middleware;

use App\Models\IpRule;
use App\Support\Api;
use App\Support\SecurityRadar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * **فرضُ الدفاع التكيّفي على كل طلب** (Work OS · الطور I · WP-I.3 · §39/§42) —
 * يستهلك مخزنَ `ip_rules` (WP-I.1) الذي يملؤه اليدويُّ (شاشةُ security.blocks)
 * والآليُّ (سلّمُ WP-I.2)، ويحاكي دلالاتِ `HubMaintenance` القائمة: استثناءُ
 * المالك، وردٌّ **يفاوض النوع** (صفحةُ HTML لطالبها، غلافُ JSON الموحَّد لـAPI).
 *
 * **ترتيبُ التقييم (المواصفة حرفياً):**
 *  ١. **fail-open**: جدولٌ غائب/مكسور أو أيُّ عطلٍ في التقييم ⇒ الطلبُ يمرّ —
 *     انقطاعُ الدفاع لا يجوز أن يصير انقطاعَ موقع. الـtry يطوّق التقييمَ وحده
 *     لا `$next` — فاستثناءاتُ التطبيق (٤٠٣/٤٠٤ المتحكّمات) تصعد كما كانت.
 *  ٢. مجموعةُ القواعد الحيّة من **خبيئةٍ قصيرة** (٣٠ ثانية) تُنسف عند أي كتابةِ
 *     قاعدة (`IpRule::booted` ينادي `bust()`) — لا استعلامَ ثقيلاً لكل طلب.
 *  ٣. **allow يفوز block دائماً** — قاعدةُ سماحٍ مطابقة = مرورٌ فوريّ.
 *  ٤. **مالكٌ مصادَقٌ لا يُحظر أبداً** — وضربتُه **تشفي** القاعدةَ المطابقة
 *     (إلغاءٌ صريحٌ مؤثَّل لا حذف): مالكٌ يستعمل العنوانَ حجّةُ براءته، وقاعدةٌ
 *     كامنةٌ كانت ستصدّه عند أول دخولٍ **غير مصادَقٍ** تالٍ.
 *  ٥. `security.trusted_ips` قناةُ تعافٍ تمرّ فوق أي حظر (المُطابِقُ الواحد).
 *  ٦. حظرٌ حيٌّ مطابق ⇒ صدٌّ ٤٠٣ مفاوَضُ النوع، يُسجَّل في سكّة
 *     **`access_denials` القائمة** (فيغذّي تجميعَ WP-I.2 بنفسه) ويزيد `hits`.
 *
 * **لا مُطابِقَ ثانٍ:** كلُّ مطابقةٍ عبر `ip_allowed()` (helpers:27). والمنتهي
 * (`expires_at` ماضٍ) يتوقف عن الصدّ **لحظياً** لكل طلبٍ — لا رهينةَ TTL الخبيئة
 * (حظرٌ شبحٌ من خبيئةٍ متأخرة = انقطاع)، والقديمُ الآليُّ يُقلَّم عند إعادة التحميل.
 *
 * الحظرُ هنا **حظرُ التطبيق** — السلطةُ القاطعة، يعمل بلا أي تبعيةٍ خارجية.
 * حظرُ حافّة الشبكة شأنُ `EdgeDefense` (best-effort صادق) ولا يُدّعى هنا.
 */
class IpDefense
{
    /** مفتاحُ خبيئة المجموعة الحيّة — يُنسف عند كل كتابةِ قاعدة */
    public const CACHE_KEY = 'ip_defense:ruleset';

    /** عمرُ الخبيئة بالثواني — قصيرٌ عمداً: شبكةُ أمانٍ تحت النسف لا بديلاً عنه */
    public const CACHE_TTL = 30;

    /** كم يوماً تبقى القاعدةُ الآليّةُ المنتهية قبل التقليم — أطولُ من نافذة
     *  تصعيد WP-I.2 بهامشٍ واسع، فذاكرةُ «العودِ يُصعِّد» لا تُمحى قبل أوانها */
    public const PRUNE_AFTER_DAYS = 7;

    public function handle(Request $request, Closure $next): Response
    {
        $deny = null;
        try {
            $deny = $this->evaluate($request);
        } catch (\Throwable $e) {
            $deny = null;   // fail-open: عطلُ الدفاع لا يمسّ الخدمة — ولا يُبلَّغ في المسار الساخن
        }

        return $deny ?? $next($request);
    }

    /** نسفُ خبيئة المجموعة — يناديه `IpRule::booted` عند كل حفظٍ/حذف، والشفاءُ أدناه */
    public static function bust(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // خبيئةٌ معطوبة لا تكسر الكتابة — الـTTL القصير يتكفّل بالتقارب
        }
    }

    /** قرارُ الدفاع: ردُّ صدٍّ أو null للمرور — كلُّ عطلٍ فيه يمرّ (fail-open) */
    protected function evaluate(Request $request): ?Response
    {
        $ip = (string) $request->ip();
        if ($ip === '') return null;

        $rules = $this->ruleset();
        if ($rules === []) return null;

        // المطابقةُ على المجموعة كلِّها: سماحٌ مطابقٌ يقطع فوراً (allow يفوز block)،
        // والمنتهي يُتجاوز لحظياً ولو حملته الخبيئة — الفحصُ لكل طلبٍ لا لكل تحميل
        $ts = now()->getTimestamp();
        $block = null;
        foreach ($rules as $r) {
            if ($r['exp'] !== null && $r['exp'] <= $ts) continue;
            if (! ip_allowed($ip, $r['ip'])) continue;
            if ($r['mode'] === 'allow') return null;
            $block ??= $r;
        }
        if ($block === null) return null;

        // قناةُ التعافي المعلنة: عناوينُ الإدارة الموثوقة تمرّ فوق أي حظر
        if (ip_allowed($ip, (string) setting('security.trusted_ips', ''))) return null;

        // مالكٌ مصادَقٌ لا يُحظر أبداً — وضربتُه تشفي القاعدةَ فلا تصيد دخولَه التالي
        if ($this->authenticatedOwner($request)) {
            $this->heal($request, $ip);

            return null;
        }

        return $this->deny($request, $ip, $block);
    }

    /**
     * المجموعةُ الحيّة (غيرُ الملغاة وغيرُ المنتهية) صفوفاً خفيفةً من خبيئةٍ
     * قصيرة — قراءةُ فهرس `(mode, expires_at)`، بترتيبٍ حتميّ (id). داخل إعادة
     * التحميل يجري تقليمُ الآليّ القديم (لا المُلغى — ذاك أثرُ قرارٍ يبقى).
     */
    protected function ruleset(): array
    {
        return (array) Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            if (! Schema::hasTable('ip_rules')) return [];

            $this->prune();

            return IpRule::query()->active()->orderBy('id')
                ->get(['id', 'ip', 'mode', 'expires_at'])
                ->map(fn ($r) => ['id' => (string) $r->id, 'ip' => (string) $r->ip,
                    'mode' => (string) $r->mode, 'exp' => $r->expires_at?->getTimestamp()])
                ->all();
        });
    }

    /**
     * تقليمُ القواعد **الآليّة** المنتهية منذ أكثر من `PRUNE_AFTER_DAYS`:
     * اليدويُّ والملغى أثرُ قرارِ إنسانٍ فيبقيان؛ والآليُّ الحديثُ الانتهاءِ يبقى
     * أيضاً — هو ذاكرةُ سلّم التصعيد (WP-I.2 يقرأ آخرَ قاعدةٍ آليّةٍ للعنوان).
     */
    protected function prune(): void
    {
        try {
            DB::table('ip_rules')->where('origin', 'auto')->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS))
                ->delete();
        } catch (\Throwable $e) {
            // التقليمُ نظافةٌ لا شرطُ خدمة
        }
    }

    /**
     * هل الطلبُ لمالكٍ مصادَق؟ — على الويب من جلسته؛ وعلى API (الهويةُ لا تُحلّ
     * قبل `ApiAuth` الذي يلي هذا الوسيط — درسُ HubMaintenance نفسُه) تُستطلَع
     * **عند مطابقةِ حظرٍ فقط** (لا كلفةَ على المسار السليم) ببصمة الرمز على
     * جدول `api_tokens` القائم: رمزٌ حيٌّ غيرُ منتهٍ لمالكٍ نشط = مالكٌ مصادَق.
     */
    protected function authenticatedOwner(Request $request): bool
    {
        try {
            if ($u = $request->user()) return hub_is_owner($u);

            if ($request->is('api/*')) {
                $plain = (string) $request->bearerToken();
                if ($plain === '') return false;
                $t = DB::table('api_tokens')->where('token_hash', hash('sha256', $plain))
                    ->whereNull('revoked_at')->first();
                if (! $t || ($t->expires_at && now()->gt($t->expires_at))) return false;
                $owner = \App\Models\User::whereNull('deleted_at')->find($t->user_id);

                return $owner !== null && $owner->status !== 'موقوف' && hub_is_owner($owner);
            }
        } catch (\Throwable $e) {
            // تعذُّرُ الاستطلاع لا يمنح الاستثناء — لكنه لا يكسر التقييم أيضاً
        }

        return false;
    }

    /**
     * شفاءُ ضربةِ المالك: كلُّ قاعدةِ حظرٍ حيّةٍ تطابق عنوانَه تُلغى (revoked_at —
     * الصفُّ يبقى أثراً) بقيدِ تدقيقٍ واحدٍ بدلالة SECURITY_POLICY_CHANGED.
     * كتابةٌ مباشرةٌ (لا أحداثَ Eloquent) كي لا يزدحم التدقيق، والنسفُ يدويّ.
     */
    protected function heal(Request $request, string $ip): void
    {
        try {
            $live = IpRule::query()->active()->where('mode', 'block')->orderBy('id')->get();
            $ids = $live->filter(fn ($r) => $r->matches($ip))->pluck('id')->all();
            if ($ids === []) return;

            DB::table('ip_rules')->whereIn('id', $ids)
                ->update(['revoked_at' => now(), 'revoked_by' => $request->user()?->id, 'updated_at' => now()]);
            self::bust();

            hub_audit('شفاء حظر IP آلياً', null, (string) $ids[0],
                "مالكٌ مصادَقٌ دخل من العنوان {$ip} — أُلغيت " . count($ids) . ' قاعدة حظرٍ كانت ستصدّ دخولَه التالي',
                ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);
        } catch (\Throwable $e) {
            // الشفاءُ إضافةٌ — فشلُه لا يمسّ مرورَ المالك
        }
    }

    /**
     * الصدُّ المفاوَضُ النوع (نمطُ HubMaintenance): غلافُ API الموحَّد لطالب
     * JSON/API، وصفحةُ منعٍ لطالب HTML — مع التسجيل في سكّة `access_denials`
     * القائمة (القارئُ الواحد `SecurityRadar::record` — لا سجلَّ منعٍ ثانٍ)
     * وزيادةِ عدّاد إصابات القاعدة (كتابةٌ مباشرةٌ بلا أحداثَ — لا عاصفةَ تدقيق).
     */
    protected function deny(Request $request, string $ip, array $rule): Response
    {
        try {
            DB::table('ip_rules')->where('id', $rule['id'])->update(['hits' => DB::raw('hits + 1')]);
        } catch (\Throwable $e) {
            // عدّادٌ متعثّر لا يُسقط الصدّ
        }
        SecurityRadar::record($request, 'حظر IP', 'قاعدةُ دفاعٍ تكيّفي ' . $rule['id']);

        if ($request->expectsJson() || $request->is('api/*')) {
            return Api::error(Api::FORBIDDEN, 403,
                'عنوانُ شبكتك محظورٌ بقاعدةِ دفاعٍ تكيّفي — راجع مالكَ النظام إن كنت تظنّه خطأً',
                null, ['reason' => 'ip_blocked']);
        }

        return response()->view('maintenance', [
            'icon' => '⛔', 'title' => 'الوصول محظور',
            'msg'  => 'حُظر عنوانُ شبكتك مؤقتاً أو دائماً لأسبابٍ أمنية — تواصل مع مالك النظام',
        ], 403);
    }
}
