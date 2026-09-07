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

    /** الأنظمةُ المنشورُ لها — allowlist مفروضٌ في `saving` (C10) */
    public const OSES = ['windows', 'macos'];

    /** المعماريّتان — allowlist مفروضٌ في `saving` (C10) */
    public const ARCHES = ['amd64', 'arm64'];

    /** حالتا التوقيع لا غير — و'signed' خلف إقرارِ التحقّق الصريح وحدَه (C15) */
    public const SIGNING_STATUSES = ['unsigned-dev', 'signed'];

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

            // الإقرارُ لحفظةٍ واحدة — لا يبقى عالقاً فيُشرعن حفظاً لاحقاً خلسة
            $r->signatureAttested = false;
        });
    }

    /** اسمُ التنزيل الصادق: يقول المنصّةَ والنسخةَ — و.exe لويندوز وحدَها (ثنائيّاتٌ خام، لا حِزَمَ مثبِّتٍ زائفة) */
    public function fileName(): string
    {
        return 'lynomia-agent-' . $this->version . '-' . $this->os . '-' . $this->arch
            . ($this->os === 'windows' ? '.exe' : '');
    }
}
