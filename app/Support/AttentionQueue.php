<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **صفُّ «يستدعي تدخّلك»** (WP-10.2 · spec §33 · §34 · قرار ق١/ق٣/ق٤).
 *
 * ═══ لماذا مُنتِجٌ ثانٍ لا مركزُ فعلٍ ثانٍ ═══
 * `ActionCenter` قائمٌ ويملك السكّة كاملةً: تجميعٌ بالكيان، وإسقاطُ تصرّفِ
 * المستخدم من `signal_states`، وحارسُ «الحرجُ لا يُهمَل»، ومسارُ `recs.act`.
 * ما كان ينقصه **مادّةُ النظام**: صفُّه كلُّه إشاراتٌ تجارية (خدمةٌ خاسرة،
 * فريقٌ فوق طاقته، مستحقٌّ متأخّر) بينما سلسلةُ تدقيقٍ مكسورةٌ أو مجدولاتٌ
 * لا تنبض لا تصل إلى أحد. فهذا **منتِجٌ ثانٍ يُدمج في `ActionCenter::signals`
 * نفسِه** — لا مخزنَ ثالثاً، ولا مسارَ إقرارٍ ثانياً، ولا شاشةَ تصرّفٍ موازية.
 *
 * ═══ التسمية (تحذيرُ الخطّة) ═══
 * كلمةُ «انتباه» محجوزةٌ سلفاً لعدّاد `Workspaces::attentionByModule` (شارةُ
 * الشريط الجانبي لرادار الانتهاءات: **سجلاتٌ** تقارب موعدَها، منطَّقةٌ بصلاحية
 * كلِّ مستخدم). وهذا صفٌّ آخرُ تماماً: **شروطُ نظامٍ** على مستوى المنشأة
 * لقارئٍ مالكٍ أو مراقب. فلا يُطوى أحدُهما في الآخر — الأول يقول «أيُّ سجلٍّ
 * ينتهي»، والثاني «ما الذي يمنع النظامَ من أن يكون سليماً الآن». ولذلك
 * تسميتُه في الواجهة **«يستدعي تدخّلك»** لا «انتباه»، واسمُ الصنف تقنيٌّ
 * بالإنجليزية كسائر المستودع.
 *
 * ═══ المصادرُ السبعة (بلا ثامن) ═══
 *   ① نتيجةٌ أمنية حرجة        `sec.finding:<code>[:<entity>]`   ← `security_findings`
 *   ② تدهورُ النظام            `health.<component>`              ← `Health::check`
 *   ③ خطأٌ حرجٌ غيرُ محلول      `error.critical:<hash>`           ← `error_events`
 *   ④ سلسلةُ تدقيقٍ مكسورة     `audit.chain`                     ← `audit_verifications` ثم `Audit::verifyTail`
 *   ⑤ مشكلةُ مجدول/طابور شديدة `health.scheduler|outbox`         ← المصدرُ ② نفسُه (لا عدّادَ ثانٍ)
 *   ⑥ نقصُ جودةٍ حرج           `quality.<module>:<rule>`         ← `DataQuality::scan`
 *   ⑦ هدفٌ حرجٌ متأخّر         `okr.<id>`                        ← `objectives`
 * و`alert:<dedup_key>` يبقى حيث وُلد: `ActionCenter::alertSignals` (ق٣ · critic
 * #5) — إقرارُ التنبيه في صفِّه بجدوله، فلا سطحَ إقرارٍ ثانٍ له هنا.
 *
 * ═══ التوصيةُ حتميّة (§34) ═══
 * لا نصَّ يُخترع عند العرض: نتيجةُ الأمن تحمل `remediation` الذي كتبه
 * `SecurityPosture::row` في `fix`، ونقصُ الجودة يحمل `fix` قاعدتِه، وأمّا
 * مكوّناتُ `Health` فتحمل «لماذا» ولا تحمل «ماذا أفعل» — فلها خريطةٌ صريحة
 * أدناه (`HEALTH_FIX`)، سطرٌ لكلّ مكوّن، تُقرأ ولا تُستنتج.
 *
 * ═══ الحارس (ق١) ═══
 * الصفُّ كلُّه محجوبٌ عمّن ليس مالكاً ولا حاملَ راية مراقبة. وفوق ذلك **كلُّ
 * مصدرٍ بحارس مركزِه**: التشغيلُ والأخطاءُ والجودة للمالك وحدَه (مراكزُها
 * كذلك)، والتدقيقُ لحامل راية `audit`، والنتائجُ الأمنية والأهدافُ للمالك أو
 * المراقب. فلا يقود بندٌ إلى بابٍ مغلقٍ في وجه قارئه.
 */
final class AttentionQueue
{
    /** مهلةُ خبيئة الصفّ — ٦٠ ثانية كبطاقات مستوى التحكّم (Health::check وحدَه عشراتُ الاستعلامات) */
    public const TTL = 60;

    /** سقفُ بنودٍ لكلّ مصدر: صفٌّ لا يُقرأ ليس صفَّ عمل (§33 — «بلا ضجيج») */
    public const CAP = 10;

    /** أنواعُ الصفّ — الوسمُ الذي يفصل إشارةَ النظام عن الإشارة التجارية */
    public const TYPES = ['security', 'system', 'error', 'audit', 'quality', 'execution', 'alert'];

    /** تسمياتُ الأنواع للشارة */
    public const TYPE_LABELS = [
        'security' => 'أمن', 'system' => 'تشغيل', 'error' => 'أخطاء', 'audit' => 'تدقيق',
        'quality' => 'جودة', 'execution' => 'تنفيذ', 'alert' => 'تنبيه',
    ];

    /**
     * مفردةُ `ActionCenter` من درجة `Severity` — الجسرُ بين السلّمين القائمين
     * (لا سلّمٌ ثالث): `ActionCenter::RANK` ثلاثُ مفردات، و`Severity` خمسُ درجات.
     */
    public const SEV_WORD = ['critical' => 'حرج', 'high' => 'مهم', 'medium' => 'مهم',
                             'low' => 'اطّلاع', 'info' => 'اطّلاع'];

    /**
     * (§34) توصيةُ كلِّ مكوّنِ صحّةٍ — `Health::c()` يعيد `why` ولا يعيد `fix/url`.
     * سطرٌ لكلّ مفتاحٍ في `Health::check()`، مكتوبٌ لا مستنتَج.
     */
    public const HEALTH_FIX = [
        'db'           => 'افحص اتصالَ القاعدة وزمنَ استجابتها في مركز التشغيل، وراجع عتبة ops.db_ms_warn قبل أن تُطاردَ عطلاً وهميّاً.',
        'cache'        => 'تأكّد أن مخزنَ الخبيئة يعمل ويكتب — بلا خبيئةٍ تعمل الشاشاتُ لكن كلّ فتحةٍ تدفع ثمنَها كاملاً.',
        'storage'      => 'أخلِ مساحةً على القرص أو أصلح صلاحياتِ مجلد storage — بلا كتابةٍ تتوقّف المرفقاتُ وملفاتُ السجل.',
        'migrations'   => 'شغّل الترحيلات المعلّقة (زرُّ الترحيل في مركز التشغيل) — الكودُ يسبق القاعدةَ الآن.',
        'config'       => 'راجع رسالةَ الفحص: مفتاحُ تطبيقٍ غائبٌ أو تنقيحٌ مفعّلٌ في الإنتاج أو قفلُ طوارئ/صيانةٍ مرفوع.',
        'scheduler'    => 'فعّل سطرَ cron على الخادم (كتيّبات التشغيل ← المجدولات) — بلا نبضةٍ لا تسليمَ ولا نسخَ احتياطي ولا تنبيهات.',
        'outbox'       => 'افتح طابورَ الصادر في مركز التشغيل وأعِد محاولةَ العالق بعد قراءة سببِ الفشل — لا تُفرغ الطابورَ قبل قراءته.',
        'webhooks'     => 'راجع نقاطَ الويبهوك الصادرة وسجلَّ محاولاتِها — وجهةٌ ميّتةٌ تُبقي الصفَّ ينمو.',
        'integrations' => 'افتح مركزَ التكاملات واختبر الاتصالَ للتكامل المتعطّل — الرمزُ المنتهي أشيعُ سببٍ.',
        'errors'       => 'افتح مركزَ الأخطاء وابدأ بأعلى بصمةٍ تكراراً في آخر ساعة.',
        'security'     => 'افتح مركزَ الأمان: حادثةٌ أمنية مفتوحة أو مفتاحُ طوارئٍ مرفوعٌ ونُسي.',
        'system'       => 'راجع استهلاكَ المعالج والذاكرة والقرص في مركز التشغيل — والرقمُ بلا مستهلِكٍ لا يُعالَج.',
    ];

    /**
     * مكوّناتٌ لا تُبَثّ من `Health` لأنّ لها في هذا الصفّ مصدراً **أدقَّ**:
     * `errors` يقول «س خطأً حرجاً في آخر ساعة» بينما `error.critical:<hash>`
     * يسمّي العطلَ نفسَه ويقود إلى صفحته. بندان لفعلٍ واحد ضجيجٌ لا تغطية.
     */
    public const HEALTH_SKIP = ['errors'];

    /**
     * (§26 · نمطُ `ExecutionStats::RISK_RULE`) قاعدةُ «الهدفُ الحرجُ المتأخّر»
     * **مكتوبةٌ لا مخترَعة**: فات موعدُه (`due` قبل اليوم) ولم يبلغ حالةً مغلقة،
     * **و**هو إمّا معلَنُ التعثّر (حالةُ «متعثر» كتبها إنسان) أو على مستوى
     * «الشركة» (أعلى مستوىً في خيارات السجل نفسِه). وما عدا ذلك من التأخّر
     * مكانُه لوحةُ الأهداف لا صفُّ التدخّل.
     */
    public const OKR_RULE = 'هدفٌ فات موعدُه ولم يُغلق، وهو معلَنُ التعثّر أو على مستوى الشركة.';

    /** الحالةُ المعلَنة للتعثّر في سجلّ الأهداف، وأعلى مستوىً في خياراته */
    public const OKR_BLOCKED = 'متعثر';
    public const OKR_TOP_LEVEL = 'الشركة';

    /** الجهةُ المسؤولة حين لا يُسمّي السجلُّ مالكاً — دورٌ لا شخص */
    public const DEFAULT_OWNER = 'مالكُ النظام';

    /**
     * بنودُ الصفّ للقارئ الحاليّ — مخبّأةٌ ٦٠ ثانية ببصمة نطاقه (`hub_scope_key`)
     * فلا يقرأ أحدٌ صفَّ غيره، ولا يُعاد بناءُ `Health::check` مع كلّ نقرة.
     *
     * **الشكل** = شكلُ `hub_recommendations` (`sev, ico, title, why, url, action,
     * key, module, recordId`) مضافاً إليه `fix` و`level` و`type` و`detected`
     * و`owner` — إضافةٌ متوافقةٌ خلفياً: كلُّ قارئٍ قديمٍ يقرأ الستّةَ الأولى.
     *
     * @param  mixed  $user  القارئ (الافتراض: المصادَق)
     * @param  \Closure|null $health  مزوّدٌ **كسول** لنموذج `Health::check()` حين
     *         يكون المُنادي قد حسبه في الطلب نفسِه (بطاقةُ التشغيل في مستوى
     *         التحكّم) — فلا يُحسب أثقلُ قارئٍ مرّتين في فتحةٍ واحدة. غيابُه
     *         يعني «احسبه بنفسك»، وهو حالُ `/recommendations`.
     */
    public static function items($user = null, bool $fresh = false, ?\Closure $health = null): array
    {
        $user = $user ?: auth()->user();
        if (! $user) return [];
        // ق١: الصفُّ كلُّه حالةُ نظامٍ على مستوى المنشأة — للمالك أو حامل راية المراقبة
        if (! (hub_is_owner($user) || hub_monitor($user))) return [];

        /*
         * مهلةٌ بلا ختمِ جداول — عن قصد: أدلّةُ الأمن والأخطاء والتحقّق تُكتب
         * بـ`DB::table` لا بنماذج فلا يُضرب لها ختمٌ أصلاً، و`tasks`/`objectives`
         * كثيرةُ التغيّر فختمُها يُعيد بناءَ `Health::check` كلَّ ثوانٍ. فالستّون
         * ثانيةً هي **الحدُّ الأقصى المُعلَن** للتأخّر، ويُلغيها `?fresh=1`.
         */
        return hub_cached(hub_scope_key('attn'), self::TTL, $fresh, fn () => self::build($user, $health));
    }

    /**
     * صفُّ **مستوى التحكّم**: إشاراتُ النظام كلُّها + الحرجُ وحدَه من الإشارات
     * التجارية (spec §32/§33). إشارةٌ تجاريةٌ «مهمّة» ليست خطأً — لكنّ مكانَها
     * مركزُ التوصيات، وخلطُها بحالة النظام يُنتج صفّاً لا يُقرأ.
     */
    public static function forControl(array $signals): array
    {
        return array_values(array_filter($signals,
            fn ($s) => in_array($s['type'] ?? '', self::TYPES, true) || ($s['sev'] ?? '') === 'حرج'));
    }

    /* ────────── البناء ────────── */

    /**
     * مصدرٌ يسقط لا يُسقط الصفّ: كلُّ منتِجٍ داخل حارسه — فعطلٌ في قارئ الجودة
     * لا يُخفي سلسلةَ تدقيقٍ مكسورة.
     */
    protected static function build($user, ?\Closure $health = null): array
    {
        // عقدُ المصادر واحد: `($user, $health)` — فالمزوّدُ الكسول يصل إلى من
        // يحتاجه (`health`) بلا استثناءٍ في الحلقة ولا وسيطٍ فائضٍ صامت

        $out = [];
        foreach (['security', 'health', 'errors', 'audit', 'quality', 'okr'] as $src) {
            try {
                foreach (self::{$src}($user, $health) as $item) $out[] = $item;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // الأشدُّ أولاً، والتساوي يُحسم بالمفتاح — ترتيبٌ واحدٌ على المحرّكين
        usort($out, fn ($a, $b) => (Severity::rank($b['level']) <=> Severity::rank($a['level']))
            ?: strcmp((string) $a['key'], (string) $b['key']));

        return $out;
    }

    /**
     * قالبُ البند الواحد — نقطةُ الشكل الوحيدة، فلا ينزلق مصدرٌ عن نظيره.
     * `record_id` **و**`recordId` معاً: الأول ما يقرؤه `ActionCenter` (تجميعاً
     * وكتابةً في `signal_states`)، والثاني توأمُ `hub_recommendations` الحرفيّ.
     */
    protected static function item(string $type, string $level, string $key, string $ico,
                                   string $title, string $why, string $fix, string $url,
                                   string $action, ?string $module = null, ?string $recordId = null,
                                   $detected = null, ?string $owner = null): array
    {
        $level = Severity::normalize($level);

        return [
            'sev'       => self::SEV_WORD[$level] ?? 'مهم',
            'level'     => $level,
            'ico'       => $ico,
            'title'     => mb_substr($title, 0, 200),
            'why'       => mb_substr($why, 0, 400),
            'fix'       => mb_substr($fix, 0, 400),
            'url'       => $url,
            'action'    => $action,
            'key'       => mb_substr($key, 0, 191),          // عرضُ signal_states.skey
            'module'    => $module,
            'record_id' => $recordId,
            'recordId'  => $recordId,
            'type'      => $type,
            'detected'  => $detected,
            'owner'     => $owner ?: self::DEFAULT_OWNER,
        ];
    }

    /* ────────── ① النتائجُ الأمنية الحرجة ────────── */

    /**
     * غيرُ المحلولة (`open`/`acknowledged`) من الدرجة الحرجة وحدَها. القراءةُ
     * بحارس مركز النتائج نفسِه (مالكٌ أو مراقب) وبتنطيقه نفسِه (المعزولُ بشركاتٍ
     * يرى نتائجَ المنظّمة وشركاتِه)، والنصوصُ تمرّ بـ`maskPII` لغير المالك —
     * فلا يقرأ حاملُ الراية بريدَ أحدٍ ولا عنوانَه (critic #9).
     */
    protected static function security($user, ?\Closure $health = null): array
    {
        if (! Schema::hasTable('security_findings')) return [];

        // شرطُ «أيَّ نتيجةٍ يرى هذا القارئ» من مالكه (`SecurityFindings::scopeCompanies`)
        // لا نسخةً ثالثةً منه — فالعدّادُ والقائمةُ والصفُّ يقولون قولاً واحداً
        $q = SecurityFindings::scopeCompanies(
            DB::table('security_findings')
                ->where('severity', 'critical')->whereIn('status', ['open', 'acknowledged']),
            hub_company_ids($user));

        // الأقدمُ رصداً أولاً («منذ متى ونحن مكشوفون؟») والتعادلُ بالمعرّف
        $rows = $q->orderBy('first_seen_at')->orderBy('id')->limit(self::CAP)->get();
        if ($rows->isEmpty()) return [];

        $owner = hub_is_owner($user);
        $names = hub_ref_labels('users', $rows->pluck('owner_id')->all());

        $out = [];
        foreach ($rows as $f) {
            $entity = ($f->entity_type === SecurityFindings::ORG_TYPE && (string) $f->entity_id === '')
                ? '' : ':' . $f->entity_id;
            $txt = fn (?string $s) => $owner ? (string) $s : SecurityFindings::maskPII((string) $s);

            $out[] = self::item('security', 'critical',
                'sec.finding:' . $f->code . $entity, '🛡️',
                $txt($f->title),
                $txt($f->description) ?: 'نتيجةٌ أمنية حرجة مفتوحة.',
                $txt($f->remediation) ?: 'افتح النتيجةَ في مركز الأمان واتّبع التوصيةَ المسجَّلة فيها.',
                route('security.finding', $f->id), 'افتح النتيجة',
                null, null, $f->first_seen_at,
                $f->owner_id ? ($names[$f->owner_id] ?? null) : null);
        }

        return $out;
    }

    /* ────────── ② + ⑤ تدهورُ النظام والمجدولاتُ والطوابير ────────── */

    /**
     * كلُّ مكوّنٍ خرج عن السلامة في `Health::check()`: **متعطّلٌ ⇒ حرج**،
     * **متدهورٌ ⇒ مرتفع**. و«صيانة»/«غيرُ معلوم» لا يدخلان الصفّ: الأولُ حالةٌ
     * مقصودةٌ يُعلنها المالك، والثاني غيابُ قياسٍ لا عطل — وإنذارٌ على غيابِ
     * قياسٍ يُدرَّب الناسُ على تجاهله.
     *
     * مركزُ التشغيل للمالك وحدَه اليوم، فلا يُبَثّ بندٌ يقود إلى بابٍ مغلق.
     */
    protected static function health($user, ?\Closure $health = null): array
    {
        if (! hub_is_owner($user)) return [];

        // النموذجُ المُمرَّر إن وُجد (فتحةُ مستوى التحكّم تحسبه لبطاقة التشغيل)،
        // وإلا فحسابُه هنا — لا فرقَ في المخرَج، وفرقُ عشراتِ الاستعلامات في الكلفة
        $h = $health ? ($health)() : Health::check();
        $out = [];
        foreach ($h['components'] ?? [] as $key => $c) {
            if (in_array($key, self::HEALTH_SKIP, true)) continue;
            $st = (string) ($c['status'] ?? '');
            if (! in_array($st, [Health::DEGRADED, Health::UNAVAILABLE], true)) continue;

            $out[] = self::item('system', $st === Health::UNAVAILABLE ? 'critical' : 'high',
                'health.' . $key, $st === Health::UNAVAILABLE ? '🛑' : '⚠️',
                ($st === Health::UNAVAILABLE ? 'متعطّل: ' : 'متدهور: ') . ($c['label'] ?? $key),
                (string) ($c['why'] ?? ''),
                self::HEALTH_FIX[$key] ?? 'افتح مركزَ التشغيل واقرأ تفصيلَ المكوّن قبل أيّ إجراء.',
                route('ops.index'), 'افتح مركز التشغيل');
            // `detected` يبقى فارغاً عمداً: نموذجُ الصحّة لقطةٌ حيّة بلا ذاكرةِ
            // «منذ متى» — و«الآن» المكتوبةُ في مكان تاريخِ الرصد كذبٌ صغير.
        }

        return $out;
    }

    /* ────────── ③ الأخطاءُ الحرجة غيرُ المحلولة ────────── */

    /**
     * البصمةُ مفتاحٌ لا معرّفُ الصفّ: `error.critical:<hash>` يبقى هو نفسَه لو
     * قُلِّم الصفُّ وعاد العطل — فالتأجيلُ لا يُنسى بإعادة الإدراج.
     * «غيرُ المحسوم» بتعريف `ErrorStats::SETTLED` الواحد لا بشرطٍ ثانٍ.
     */
    protected static function errors($user, ?\Closure $health = null): array
    {
        if (! hub_is_owner($user)) return [];
        if (! Schema::hasTable('error_events') || ! hub_has_col('error_events', 'severity')) return [];

        $rows = DB::table('error_events')
            ->where('severity', 'CRITICAL')->whereNotIn('status', ErrorStats::SETTLED)
            ->orderByDesc('last_seen')->orderByDesc('id')->limit(self::CAP)->get();
        if ($rows->isEmpty()) return [];

        $assignees = hub_has_col('error_events', 'assignee_id')
            ? hub_ref_labels('users', $rows->pluck('assignee_id')->all()) : [];

        $out = [];
        foreach ($rows as $e) {
            $out[] = self::item('error', 'critical',
                'error.critical:' . $e->hash, '🐞',
                'عطلٌ حرجٌ مفتوح: ' . $e->message,
                'رُصد ×' . (int) $e->count . ' — آخرُ ظهورٍ ' . substr((string) $e->last_seen, 0, 16)
                    . ($e->file ? ' · ' . basename((string) $e->file) . ':' . (int) $e->line : ''),
                'افتح العطلَ في مركز الأخطاء: اقرأ آخرَ وقوعٍ وأثرَ طلبِه، ثم أسنِده أو أصلحه — لا تُغلقه بلا سبب.',
                route('errors.show', $e->id), 'افتح العطل',
                null, null, $e->first_seen,
                ($e->assignee_id ?? null) ? ($assignees[$e->assignee_id] ?? null) : null);
        }

        return $out;
    }

    /* ────────── ④ نزاهةُ سلسلة التدقيق ────────── */

    /**
     * **ولا فحصَ سلسلةٍ كاملاً في طلبٍ أبداً**: يُقرأ آخرُ صفٍّ في
     * `audit_verifications` (ناتجُ `hub:audit-verify`)، وعند غيابه يرتدّ إلى
     * `Audit::verifyTail` (نافذةٌ من آخر القيود). و«خرج بملاحظات» (warn) يُقال
     * كما هو — لا يُسمّى «مكسورة» ولا يُبتلع تحت «سليمة».
     */
    protected static function audit($user, ?\Closure $health = null): array
    {
        $owner = hub_is_owner($user);
        if (! $owner && ! hub_flag($user, 'audit')) return [];
        $url = $owner ? route('audit.coverage') : route('audit.index');

        $row = Schema::hasTable('audit_verifications')
            ? DB::table('audit_verifications')->orderByDesc('started_at')->orderByDesc('id')->first()
            : null;

        if ($row) {
            if ((string) $row->result === 'ok') return [];

            $fail = (string) $row->result === 'fail';

            return [self::item('audit', $fail ? 'critical' : 'high', 'audit.chain', '🔗',
                $fail ? 'سلسلةُ التدقيق مكسورة' : 'تحقّقُ سلسلة التدقيق خرج بملاحظات',
                (string) ($row->message ?: 'آخرُ تحقّقٍ كاملٍ لم يخرج سليماً.'),
                $fail
                    ? 'لا تكتب فوقها: افتح تغطيةَ التدقيق، وحدّد أولَ قيدٍ متأثّر، وقارنه بالنسخة الاحتياطية قبل أيّ إعادةِ وصل.'
                    : 'افتح تغطيةَ التدقيق واقرأ الملاحظات: بصمةُ جيلٍ أول أو اختلافُ عمود شركة — كلاهما يُعالَج بلا كسرِ الختم.',
                $url, 'افتح تغطية التدقيق', null, null, $row->started_at)];
        }

        $tail = Audit::verifyTail();
        if ($tail['ok']) return [];

        return [self::item('audit', 'critical', 'audit.chain', '🔗',
            'سلسلةُ التدقيق مكسورة في ذيلها',
            (string) ($tail['why'] ?: 'آخرُ القيود لا تتطابق بصماتُها.'),
            'شغّل الفحصَ الكامل (زرُّ التحقّق في مركز التشغيل) ليُسجَّل صفٌّ في تاريخ التحقّق، ثم عالج أولَ قيدٍ متأثّر.',
            $url, 'افتح تغطية التدقيق')];
    }

    /* ────────── ⑥ نقصُ الجودة الحرج ────────── */

    /**
     * الشدّةُ من `DataQuality::severity` وحدَها (WP-8.2): الحرجُ هو المرجعُ
     * المكسور إلى وحدةٍ في `CRITICAL_REFS` — مالٌ أو مساءلة. والرابطُ هو
     * رابطُ مركز الجودة نفسُه (`?qc=`) فيفتح **السجلاتِ المعدودةَ بعينها**.
     *
     * للمالك وحدَه: مسحُ الجودة غيرُ منطَّق ويُظهر أسماءَ سجلاتٍ من كلّ الوحدات.
     */
    protected static function quality($user, ?\Closure $health = null): array
    {
        if (! hub_is_owner($user)) return [];

        $checks = DataQuality::scan()['checks'] ?? [];
        $out = [];
        foreach ($checks as $c) {
            if (($c['sev'] ?? '') !== 'critical') continue;
            if (count($out) >= self::CAP) break;

            $mk = (string) $c['module'];
            $out[] = self::item('quality', 'critical',
                'quality.' . $mk . ':' . $c['key'], '🧹',
                (hub_mod($mk)['label'] ?? $mk) . ' — ' . $c['label'] . ' (' . number_format((int) $c['count']) . ')',
                (string) ($c['why'] ?? ''),
                (string) ($c['fix'] ?? 'افتح السجلاتِ المعنيّة وأصلح الحقل.'),
                route('m.index', $mk) . '?qc=' . urlencode((string) $c['key']),
                'افتح السجلات', $mk);
        }

        return $out;
    }

    /* ────────── ⑦ الأهدافُ الحرجة المتأخّرة ────────── */

    /**
     * القاعدةُ في `OKR_RULE` أعلاه — مكتوبةٌ لا مخترَعة، ومن وصفَي السجل نفسِه
     * (حالةُ «متعثر» ومستوى «الشركة»). ولا يُستدعى `hub_okr_progress` هنا:
     * استعلاماتٌ لكلّ هدفٍ من مئةٍ وعشرين في صفٍّ يُبنى كلَّ دقيقة — والتأخّرُ
     * لا يحتاج نسبةً ليكون تأخّراً.
     */
    protected static function okr($user, ?\Closure $health = null): array
    {
        if (! Schema::hasTable('objectives')) return [];
        if (! hub_can($user, 'okrs', 'v')) return [];

        $rows = hub_open_scope(
            hub_scope(DB::table('objectives')->whereNull('deleted_at'), 'okrs', $user)
                ->whereNotNull('due')->where('due', '<', now()->toDateString())
                ->where(fn ($w) => $w->where('status', self::OKR_BLOCKED)
                    ->orWhere('level', self::OKR_TOP_LEVEL))
        )->orderBy('due')->orderBy('id')->limit(self::CAP)->get(['id', 'title', 'due', 'status', 'level', 'owner_id']);

        if ($rows->isEmpty()) return [];
        $names = hub_ref_labels('users', $rows->pluck('owner_id')->all());

        $out = [];
        foreach ($rows as $o) {
            $late = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($o->due)->startOfDay(), false);
            $out[] = self::item('execution', 'critical',
                'okr.' . $o->id, '🎯',
                'هدفٌ فات موعدُه: ' . $o->title,
                'استحقاقُه ' . substr((string) $o->due, 0, 10) . ' (متأخّرٌ ' . abs($late) . ' يوماً)'
                    . ' · الحالة: ' . ($o->status ?: '—') . ' · المستوى: ' . ($o->level ?: '—')
                    . ' — ' . self::OKR_RULE,
                'افتح الهدفَ: أعِد جدولةَ موعده بقرارٍ معلن، أو أغلقه بنتيجته، أو ارفع المعوّقَ الذي أوقفه — بقاؤه متأخّراً بلا قرارٍ يُفقد اللوحةَ معناها.',
                route('m.show', ['okrs', $o->id]), 'افتح الهدف',
                'okrs', (string) $o->id, $o->due,
                $o->owner_id ? ($names[$o->owner_id] ?? null) : null);
        }

        return $out;
    }
}
