<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **إصدارُ وكيل النقاط الطرفية** (Work OS · الطور L · WP-L.2 · §44/§62) —
 * أرتيفاكت (نسخة × نظام × معماريّة) في مركز التنزيل المُصادَق، وتجزئتُه هي
 * التي يتحقّق منها `agent/internal/update.Apply` قبل أي تبديل.
 *
 * **عقدُ الصدق (C15 — غيرُ قابلٍ للتفاوض):** لا شهادةَ Windows Authenticode
 * (OV/EV) ولا Apple Developer ID + notarytool مُهيّأة في هذا النظام — فكلُّ
 * إصدارٍ يُنشأ **'unsigned-dev'** ويُعرَض «UNSIGNED DEVELOPMENT BUILD» صادقاً.
 * حالةُ 'signed' لا تُكتب أبداً إلا عبر `attestVerifiedSignature()` — إقرارٌ
 * صريحٌ لحفظةٍ واحدةٍ بأن توقيعاً حقيقياً وُقّع **وتُحقّق منه** (يستدعيه خطُّ
 * نشرٍ مستقبليّ مُنح الشهادات — لا نموذجُ ويبٍ ولا CRUD ولا استيراد). لا
 * توقيعَ ذاتيّاً مسرحيّاً، ولا ملصقَ «موقَّع» زائفاً، ولا توقيعَ placeholder.
 *
 * `os`/`arch`/`signing_status` قوائمُ سماحٍ تُفرَض هنا (لا DB enum — C10)،
 * والمسارُ تحت قرص `local` حصراً: أيُّ إشارةٍ نحو public أو تسلّقٍ بـ`..`
 * تُرمى قبل أن تلمس القاعدة — لا سكّةَ تقديمٍ عامّةً لهذه الملفات أبداً.
 */
class EndpointRelease extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'endpoint_releases';

    protected $guarded = ['id'];

    /** الأنظمةُ المنشورُ لها — من المصدرِ الواحد `Endpoint::SUPPORTED` (§1، لا linux) */
    public const OSES = \App\Support\Endpoint::SUPPORTED;

    /** المعماريّتان — allowlist مفروضٌ في `saving` (C10) */
    public const ARCHES = ['amd64', 'arm64'];

    /** حالتا التوقيع لا غير — و'signed' خلف إقرارِ التحقّق الصريح وحدَه (C15) */
    public const SIGNING_STATUSES = ['unsigned-dev', 'signed'];

    /** حالتا التوثيق (Apple notarytool) — محورٌ منفصلٌ عن التوقيع؛ 'notarized' خلف
     *  إقرارٍ متحقَّقٍ صريحٍ فوقَ توقيعٍ على ماك وحدَه (لا شهادةَ مُهيّأة اليوم — §5/C15) */
    public const NOTARIZATION_STATUSES = ['not-configured', 'notarized'];

    /** حالاتُ دورةِ حياةِ الإصدار — و«withdrawn» **حذفٌ ناعم** (softDeletes) لا حالةٌ تُزوَّر (§6) */
    public const STATES = ['draft', 'published'];

    /** نطاقاتُ الطرح المرحليّ (§7) — 'all' الافتراضُ فالطرحُ المرحليّ غيرُ مُلزَمٍ افتراضاً */
    public const ROLLOUT_SCOPES = ['all', 'company', 'percentage'];

    /** ملصقُ الصدق الذي تعرضه كلُّ واجهة بجانب الإصدار غير الموقَّع */
    public const UNSIGNED_LABEL = 'UNSIGNED DEVELOPMENT BUILD';

    /** نصُّ الصدق العربيّ المرافق — يُعرَض مع الملصق لا بديلاً عنه */
    public const UNSIGNED_NOTICE = 'بناءٌ تطويريٌّ غيرُ موقَّع — لا شهادةَ Authenticode ولا Developer ID مُهيّأة؛ '
        . 'تحقَّق من sha256 المنشورة قبل التثبيت (الوكيلُ يتحقّق منها آلياً قبل أي تبديل)';

    /**
     * إقرارُ توقيعٍ متحقَّقٍ **لهذا الحفظ وحدَه** — البابُ الشرعيّ الوحيد لحالة
     * 'signed'. لا يستدعيه اليومَ أيُّ مسارٍ (لا شهادةَ مُهيّأة)؛ متى مُنح خطُّ
     * النشر الأسرارَ فوقّع الأرتيفاكت فعلاً وتحقّق من توقيعه، أقرّ هنا صراحةً.
     */
    protected bool $signatureAttested = false;

    public function attestVerifiedSignature(): static
    {
        $this->signatureAttested = true;

        return $this;
    }

    /**
     * إقرارُ توثيقٍ متحقَّقٍ **لهذا الحفظ وحدَه** — البابُ الشرعيّ الوحيد لحالة
     * 'notarized'. نظيرُ إقرارِ التوقيع تماماً: لا شهادةَ Apple notarytool مُهيّأةً
     * اليوم، فالتوثيقُ 'not-configured' صادقاً حتى يوثَّق الأرتيفاكتُ فعلاً ويُقرَّ.
     */
    protected bool $notarizationAttested = false;

    public function attestVerifiedNotarization(): static
    {
        $this->notarizationAttested = true;

        return $this;
    }

    protected $casts = [
        'rollout_percentage' => 'integer',
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            // القصُّ عند الكاتب بمحارفَ لا بايتات — SQLite تمرّر الفائضَ وMySQL يرمي
            $r->version = mb_substr(trim((string) $r->version), 0, 20);
            $r->notes = filled($r->notes) ? mb_substr(trim((string) $r->notes), 0, 400) : null;
            if (trim((string) $r->version) === '') {
                throw new \InvalidArgumentException('إصدارٌ بلا رقم نسخة');
            }

            // المنصّةُ قائمتا سماحٍ تطبيقيّتان (C10) — قيمةٌ خارجهما تُرمى لا تُكتب صامتةً
            if (! in_array((string) $r->os, self::OSES, true)) {
                throw new \InvalidArgumentException('نظامُ تشغيلٍ خارج القائمة: ' . $r->os);
            }
            if (! in_array((string) $r->arch, self::ARCHES, true)) {
                throw new \InvalidArgumentException('معماريّةٌ خارج القائمة: ' . $r->arch);
            }

            // المسارُ نسبيٌّ لقرص local (storage/app) حصراً — لا public ولا تسلّق
            $path = trim((string) $r->path);
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')
                || str_starts_with($path, 'public')) {
                throw new \InvalidArgumentException('مسارُ الأرتيفاكت تحت storage/app حصراً — لا public ولا تسلّق');
            }
            $r->path = mb_substr($path, 0, 200);

            // التجزئةُ الحقيقية وحدَها: hex-64 — عقدُ update.Apply قبل أي تبديل
            $sha = strtolower(trim((string) $r->sha256));
            if (! preg_match('/^[0-9a-f]{64}$/', $sha)) {
                throw new \InvalidArgumentException('sha256 ليست hex-64 — التجزئةُ تُحسب من الملف لا تُدَّعى');
            }
            $r->sha256 = $sha;

            // **حاجزُ الصدق (C15):** الفارغُ 'unsigned-dev'؛ وخارجُ القائمة يُرمى؛
            // و'signed' لا تمرّ إلا بإقرارِ توقيعٍ متحقَّقٍ صريحٍ لهذا الحفظ وحدَه —
            // ولو أخطأ متحكّمٌ مستقبليّ فمرّر مدخلَ نموذج، فهذا الحاجزُ الأخير لا يُلتفّ عليه
            if (trim((string) $r->signing_status) === '') $r->signing_status = 'unsigned-dev';
            if (! in_array((string) $r->signing_status, self::SIGNING_STATUSES, true)) {
                throw new \InvalidArgumentException('حالةُ توقيعٍ خارج القائمة: ' . $r->signing_status);
            }
            if ($r->signing_status === 'signed' && ! $r->signatureAttested) {
                throw new \InvalidArgumentException(
                    "لا ادّعاءَ توقيعٍ: 'signed' تتطلب إقرارَ توقيعٍ متحقَّقٍ صريحاً (attestVerifiedSignature) — "
                    . 'ولا شهادةَ مُهيّأةً اليوم، فالإصداراتُ unsigned-dev صادقةً');
            }

            // **حاجزُ التوثيق (C15 — نظيرُ التوقيع):** الفارغُ 'not-configured'؛
            // وخارجُ القائمة يُرمى؛ و'notarized' لا تمرّ إلا بإقرارِ توثيقٍ متحقَّقٍ
            // صريحٍ **فوقَ** توقيعٍ متحقَّقٍ (signed) **وعلى ماك** (notarytool أداةُ
            // آبل حصراً) — لا توثيقَ ويندوز، ولا توثيقٌ لغيرِ الموقَّع، ولا ادّعاء.
            if (trim((string) $r->notarization_status) === '') $r->notarization_status = 'not-configured';
            if (! in_array((string) $r->notarization_status, self::NOTARIZATION_STATUSES, true)) {
                throw new \InvalidArgumentException('حالةُ توثيقٍ خارج القائمة: ' . $r->notarization_status);
            }
            if ($r->notarization_status === 'notarized') {
                if (! $r->notarizationAttested) {
                    throw new \InvalidArgumentException(
                        "لا ادّعاءَ توثيقٍ: 'notarized' تتطلب إقرارَ توثيقٍ متحقَّقٍ صريحاً (attestVerifiedNotarization) — "
                        . 'ولا شهادةَ notarytool مُهيّأةً اليوم');
                }
                if ($r->signing_status !== 'signed') {
                    throw new \InvalidArgumentException("لا توثيقَ لغيرِ الموقَّع: 'notarized' تستلزم 'signed' أولاً");
                }
                if ((string) $r->os !== 'macos') {
                    throw new \InvalidArgumentException('التوثيقُ (notarytool) لماك حصراً — لا توثيقَ لويندوز');
                }
            }

            // **حالةُ دورةِ الحياة (§6):** الفارغُ 'published' (توافقُ السلوك القائم:
            // الرفعُ يقدّم فوراً)؛ وخارجُ القائمة يُرمى. «withdrawn» حذفٌ ناعمٌ لا حالة.
            if (trim((string) $r->state) === '') $r->state = 'published';
            if (! in_array((string) $r->state, self::STATES, true)) {
                throw new \InvalidArgumentException('حالةُ إصدارٍ خارج القائمة: ' . $r->state);
            }

            // **رقعةُ الطرح المرحليّ (§7):** الفارغُ 'all' 100% (غيرُ مُلزَمٍ افتراضاً)؛
            // 'company' يستلزم شركةً؛ والنسبةُ تُقصَر [0..100]؛ وحقولُ نطاقٍ لا يخصّه تُصفَّر.
            if (trim((string) $r->rollout_scope) === '') $r->rollout_scope = 'all';
            if (! in_array((string) $r->rollout_scope, self::ROLLOUT_SCOPES, true)) {
                throw new \InvalidArgumentException('نطاقُ طرحٍ خارج القائمة: ' . $r->rollout_scope);
            }
            $r->rollout_percentage = max(0, min(100, (int) ($r->rollout_percentage ?? 100)));
            if ($r->rollout_scope === 'company') {
                if (trim((string) $r->rollout_company_id) === '') {
                    throw new \InvalidArgumentException("طرحٌ بنطاق 'company' بلا شركةٍ مستهدفة (rollout_company_id)");
                }
                $r->rollout_percentage = 100;                 // النطاقُ يحدّد الجمهورَ لا النسبة
            } else {
                $r->rollout_company_id = null;                // لا شركةَ عالقةٌ خارج نطاقها
            }
            if ($r->rollout_scope !== 'percentage') {
                $r->rollout_percentage = 100;                 // 'all'/'company' جمهورُهما كامل
            }

            // الوسومُ النصّية تُقصّ عند الكاتب (MySQL يرفض الفائض حيث تمرّره SQLite)
            $r->build_number = filled($r->build_number) ? mb_substr(trim((string) $r->build_number), 0, 40) : null;
            $r->min_agent_version = filled($r->min_agent_version) ? mb_substr(trim((string) $r->min_agent_version), 0, 20) : null;
            $r->min_server_version = filled($r->min_server_version) ? mb_substr(trim((string) $r->min_server_version), 0, 20) : null;

            // الإقراراتُ لحفظةٍ واحدة — لا تبقى عالقةً فتُشرعن حفظاً لاحقاً خلسة
            $r->signatureAttested = false;
            $r->notarizationAttested = false;
        });
    }

    /** اسمُ التنزيل الصادق: يقول المنصّةَ والنسخةَ — و.exe لويندوز وحدَها (ثنائيّاتٌ خام، لا حِزَمَ مثبِّتٍ زائفة) */
    public function fileName(): string
    {
        return 'lynomia-agent-' . $this->version . '-' . $this->os . '-' . $this->arch
            . ($this->os === 'windows' ? '.exe' : '');
    }

    /**
     * **حالةُ دورةِ الحياة الصادقة للعرض:** المحذوفُ ناعماً «withdrawn» فعلاً
     * (بيانُ التحديث لا يقدّمه)، وإلا حالةُ العمود ('draft'/'published').
     */
    public function lifecycleState(): string
    {
        if ($this->trashed()) {
            return 'withdrawn';
        }

        return (string) ($this->state ?: 'published');
    }

    /**
     * **هل يستهدف هذا الإصدارُ هذا الجهاز؟** (رقعةُ الطرح المرحليّ — §7)
     *  • 'all'        → الأسطولُ كلُّه (الافتراض).
     *  • 'company'    → جهازُ الشركةِ المستهدفة وحدَه.
     *  • 'percentage' → حلقةُ canary **حتميّةٌ لكل جهاز** (نفسُ الجهاز يقع دوماً في
     *    نفس الشريحة — لا رفرفةَ ترقيةٍ ذهاباً وإياباً): الأجهزةُ ذاتُ الشريحة
     *    الأدنى من النسبة تتلقّى الطرحَ، وما فوقها يبقى على الإصدار المستقرّ السابق.
     */
    public function targetsDevice(\App\Models\EndpointDevice $device): bool
    {
        return match ((string) $this->rollout_scope) {
            'company' => $this->rollout_company_id !== null
                && (string) $device->company_id === (string) $this->rollout_company_id,
            'percentage' => $this->rolloutBucket($device) < max(0, min(100, (int) $this->rollout_percentage)),
            default => true, // 'all' وأيُّ قيمةٍ قديمةٍ قبل التصحيح ⇒ الأسطولُ كلُّه
        };
    }

    /** شريحةُ الجهاز [0..99] — حتميّةٌ من هويّته الثابتة (حلقةُ canary لا تتبدّل بين الطلبات) */
    protected function rolloutBucket(\App\Models\EndpointDevice $device): int
    {
        return crc32((string) $device->device_uuid) % 100;
    }
}
