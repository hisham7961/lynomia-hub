<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Models\AiProvider;

/**
 * **نظرةٌ تقول أينَ الخلل — لا لوحةَ أرقامٍ تُزيّن** (المرحلة ٢ · W8 · §١١).
 *
 * والفرقُ عمليّ: لوحةٌ تقول «٣ مزوّدين · ٧ نماذج» تُرضي العينَ ولا تُجيب عن
 * السؤالِ الوحيدِ الذي يُفتَح المركزُ لأجلِه: **«أيعمل؟ وإن لم يعمل، فما
 * الخطوةُ التالية؟»**
 *
 * ── **الحالةُ الفارغةُ تقول الخطوةَ التالية** ──
 *
 * «لا توجد بيانات» جملةٌ تُنهي المحادثةَ ولا تبدأ عملاً. أمّا «البوّابةُ تعمل
 * ولا مزوّدَ مربوط — ابدأ بإضافةِ مزوّد» فهي **أمرٌ قابلٌ للتنفيذ**. ولذلك
 * `nextStep()` تُعيد وجهةً لا نصّاً وحدَه.
 *
 * ── **وطابورُ «يحتاج انتباهاً» يُرتَّب بالخطورةِ لا بالزمن** ──
 *
 * صفٌّ واحدٌ خطيرٌ بين عشرةِ صفوفٍ هيّنةٍ يضيع إن رُتّبت بالزمن. فالترتيبُ
 * بالشدّةِ أوّلاً، **والترتيبُ يُطلَب صراحةً** فلا قرعةَ بين المحرّكين.
 *
 * **ولا نداءَ شبكةٍ في هذا الصنف.** صفحةٌ تتّصل بالبوّابةِ عند كلِّ عرضٍ تصير
 * بطيئةً ومزعجةً لخدمةٍ متوقّفة — وهي القاعدةُ نفسُها التي فرضتها المرحلةُ
 * الأولى على شاشةِ الإعدادات. الفحصُ بزرٍّ صريح.
 */
final class AiOverview
{
    /** شدّةُ بندٍ في الطابور — مرتّبةً بالخطورة */
    public const SEVERITY = ['blocker', 'warn', 'info'];

    /**
     * صورةُ المركزِ كاملةً.
     *
     * @return array{status: string, reason: string, counts: array<string,int>,
     *               attention: list<array{severity: string, title: string, why: string,
     *                                     route: ?string, param: ?string}>,
     *               next: ?array{title: string, route: ?string, param: ?string}}
     */
    public static function snapshot(): array
    {
        $feature = self::readiness();
        $counts  = self::counts();

        return [
            'status'    => (string) $feature['status'],
            'reason'    => (string) $feature['reason'],
            'counts'    => $counts,
            'attention' => self::attention($counts),
            'next'      => self::nextStep($counts),
        ];
    }

    /**
     * **سلّمُ الجاهزيّةِ من سجلِّ القدرات** — لا سلّمٌ ثانٍ يُشتقّ هنا.
     *
     * `ai.gateway` مُعرَّفٌ في `config/hub_features.php` ومشتقٌّ في
     * `FeatureRegistry`. واشتقاقُ حالةٍ ثانيةٍ في هذه الشاشةِ كان سيُنتج
     * شاشتين تقولان قولين عن الشيءِ نفسِه.
     *
     * @return array{status: string, reason: string}
     */
    public static function readiness(): array
    {
        $r = rescue(fn () => FeatureRegistry::status('ai.gateway'), null, false);

        if (is_array($r) && isset($r['status'])) {
            return ['status' => (string) $r['status'], 'reason' => (string) ($r['reason'] ?? '')];
        }

        // تعذّر السجلُّ ⇒ **يُقال ذلك** ولا يُخترَع حالٌ مطمئنّ
        return ['status' => 'UNKNOWN', 'reason' => 'تعذّرت قراءةُ سجلِّ القدرات'];
    }

    /** العدّاداتُ السبعةُ — كلُّها استعلامُ عدٍّ لا جلبُ صفوف */
    public static function counts(): array
    {
        return [
            'providers'          => AiProvider::query()->count(),
            'providers_enabled'  => AiProvider::query()->where('enabled', true)->count(),
            'providers_verified' => AiProvider::query()->where('credential_state', 'verified')->count(),
            'models'             => AiModel::query()->count(),
            'models_enabled'     => AiModel::query()->where('enabled', true)->count(),
            'profiles'           => AiProfile::query()->count(),
            'profiles_ready'     => self::readyProfiles(),
        ];
    }

    /**
     * **أغراضٌ سلسلتُها صالحةٌ فعلاً** — لا أغراضٌ موجودة.
     *
     * وغرضٌ بسلسلةٍ فارغةٍ موجودٌ ولا يُجيب طلباً. فعدُّه «مُهيَّأً» كذبةٌ
     * تجعل الشاشةَ تطمئنّ حيث يجب أن تُنذر.
     */
    private static function readyProfiles(): int
    {
        $n = 0;
        foreach (AiProfile::query()->where('enabled', true)->orderBy('key')->get() as $p) {
            if (AiProfiles::chain($p)->isNotEmpty()) $n++;
        }

        return $n;
    }

    /**
     * **طابورُ «يحتاج انتباهاً»** — بنمطِ «مركزِ التحكّم» القائم.
     *
     * وكلُّ بندٍ يحمل **وجهةً** لا وصفاً: بندٌ يقول «هناك عطل» ولا يقول أين
     * يُصلَح يترك القارئَ يبحث.
     */
    public static function attention(?array $counts = null): array
    {
        $c    = $counts ?? self::counts();
        $out  = [];
        $add  = static function (string $sev, string $title, string $why, ?string $route = null, ?string $param = null) use (&$out) {
            $out[] = ['severity' => $sev, 'title' => $title, 'why' => $why, 'route' => $route, 'param' => $param];
        };

        if (! AiGateway::configured()) {
            $add('blocker', 'البوّابةُ غيرُ مهيّأة',
                (string) (AiGateway::whyNotReady() ?? 'لا عنوانَ ولا مفتاحَ إدارة'), 'ai.settings');
        } elseif (! AiGateway::probePassed()) {
            $add('blocker', 'الاتصالُ لم يُختبر',
                'الإعدادُ مكتملٌ ولا دليلَ أنّ البوّابةَ تردّ — افحصها', 'ai.settings');
        } elseif (! AiGateway::enabled()) {
            $add('warn', 'التكاملُ مُطفأ',
                'الفحصُ ناجحٌ والتكاملُ مطفأٌ من الإعدادات', 'ai.settings');
        }

        // مزوّدٌ مُشغَّلٌ بلا اعتماد — وعدٌ لا يُنفَّذ
        foreach (AiProvider::query()->where('enabled', true)
                     ->where('credential_state', 'missing')->orderBy('label')->get() as $p) {
            $add('blocker', 'مزوّدٌ مُشغَّلٌ بلا اعتماد: ' . $p->label,
                'لا اعتمادَ في خزنةِ البوّابة — فلا طلبَ يصل المزوّد', 'ai.providers.index');
        }

        // نموذجٌ وُسم متعطّلاً — أُزيل من المنبع
        foreach (AiModel::query()->where('health', 'UNAVAILABLE')
                     ->orderBy('litellm_model_name')->get() as $m) {
            $add('warn', 'نموذجٌ أُزيل من المنبع: ' . $m->litellm_model_name,
                'وُسم `UNAVAILABLE` بعد إخفاقِ توجيهٍ — راجِع تسجيلَه عند البوّابة',
                'ai.models.all');
        }

        // غرضٌ مُفعَّلٌ بسلسلةٍ فارغة — طلبٌ لا يُوجَّه إلى أحد
        foreach (AiProfile::query()->where('enabled', true)->orderBy('key')->get() as $p) {
            if (AiProfiles::chain($p)->isEmpty()) {
                $add('warn', 'غرضٌ مُفعَّلٌ بسلسلةٍ فارغة: ' . $p->label,
                    'لا نموذجَ صالحٌ في سلسلتِه — وطلبٌ بلا سلسلةٍ لا يُوجَّه إلى أحد',
                    'ai.profiles.index');
            }
        }

        // حلقةٌ تشير إلى نموذجٍ محذوف — انحرافُ حالةٍ يُصلَح لا يُخفى
        $orphans = AiProfileModel::query()
            ->whereNotIn('model_id', AiModel::query()->select('id'))->count();
        if ($orphans > 0) {
            $add('warn', 'حلقاتُ توجيهٍ بلا نموذج: ' . $orphans,
                'حلقةٌ تشير إلى نموذجٍ لم يعد موجوداً — تُخرَج من سلسلتِها', 'ai.profiles.index');
        }

        if ($c['models'] > 0 && $c['models_enabled'] === 0) {
            $add('info', 'لا نموذجَ مُفعَّل',
                'اسْتُوردت نماذجُ ولم يُفعَّل منها شيءٌ — والاستيرادُ لا يُفعِّل', 'ai.models.all');
        }

        // **الترتيبُ بالشدّةِ صراحةً** — فصفٌّ خطيرٌ لا يضيع بين هيّنين
        usort($out, static fn ($a, $b) => array_search($a['severity'], self::SEVERITY, true)
            <=> array_search($b['severity'], self::SEVERITY, true));

        return $out;
    }

    /**
     * **الخطوةُ التاليةُ الواحدة** — لا قائمةُ أمنياتٍ تُربك.
     *
     * وترتيبُها ترتيبُ الاعتماد: بوّابةٌ ← مزوّدٌ ← نماذجُ ← تفعيلٌ ← توجيه.
     * فالخطوةُ الأولى الناقصةُ هي الوحيدةُ التي تُعرَض.
     */
    public static function nextStep(?array $counts = null): ?array
    {
        $c = $counts ?? self::counts();

        if (! AiGateway::configured()) {
            return ['title' => 'ابدأ بضبطِ عنوانِ البوّابةِ ومفتاحِ الإدارة', 'route' => 'ai.settings', 'param' => null];
        }
        if (! AiGateway::probePassed()) {
            return ['title' => 'الإعدادُ مكتملٌ — اختبر الاتصالَ الآن', 'route' => 'ai.settings', 'param' => null];
        }
        if ($c['providers'] === 0) {
            return ['title' => 'البوّابةُ تعمل ولا مزوّدَ مربوط — ابدأ بإضافةِ مزوّد', 'route' => 'ai.providers.index', 'param' => null];
        }
        if ($c['models'] === 0) {
            return ['title' => 'مزوّدٌ مربوطٌ ولا نموذجَ مُسجَّل — اكتشف نماذجَه', 'route' => 'ai.providers.index', 'param' => null];
        }
        if ($c['models_enabled'] === 0) {
            return ['title' => 'نماذجُ مُسجَّلةٌ ولا واحدَ مُفعَّل — فعّل ما تحتاجه', 'route' => 'ai.models.all', 'param' => null];
        }
        if ($c['profiles_ready'] === 0) {
            return ['title' => 'لا غرضَ سلسلتُه جاهزة — اربط نموذجاً بغرض', 'route' => 'ai.profiles.index', 'param' => null];
        }

        return null;
    }
}
