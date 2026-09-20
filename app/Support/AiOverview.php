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
            // **اعتمادٌ مُدخَلٌ** ≠ **اعتمادٌ مقبول**: الأوّلُ درجةُ السلّمِ قبل B،
            // والثاني ما يكتبه B نفسُه بعد أن يقبلَه المزوّدُ على نموذجٍ مسجَّل
            'providers_credentialed' => AiProvider::query()->where('credential_state', '!=', 'missing')->count(),
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

        // نموذجٌ لا يُوجَّه إليه — أُزيل من المنبعِ أو لا تُعلنه البوّابة
        foreach (AiModel::query()->whereIn('health', AiProfiles::UNROUTABLE)
                     ->orderBy('litellm_model_name')->orderBy('id')->get() as $m) {
            $orphan = mb_strtoupper((string) $m->health) === 'ORPHANED';
            $add('warn', ($orphan ? 'نموذجٌ لا تُعلنه البوّابة: ' : 'نموذجٌ أُزيل من المنبع: ')
                . $m->litellm_model_name,
                $orphan
                    ? 'وُسم يتيماً في آخرِ تصالحٍ — **واستعادةٌ جزئيّةٌ أشهرُ أسبابِه**'
                    : 'وُسم `UNAVAILABLE` بعد إخفاقِ توجيهٍ — راجِع تسجيلَه عند البوّابة',
                'ai.diagnostics');
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

        // غرضُ المساعدِ مضبوطٌ على غرضٍ لا سلسلةَ له — إطفاءٌ صامتٌ للمساعد
        if ($c['profiles_ready'] > 0 && AskPolicy::profile() === null) {
            $add('warn', 'غرضُ «اسأل Hub» بلا سلسلةٍ صالحة',
                'المفتاحُ `ask.profile` يشير إلى «' . AskPolicy::profileKey()
                . '» ولا سلسلةَ له — والمساعدُ مُطفأٌ بلا أن يُقال ذلك في مكانٍ آخر',
                'ai.profiles.index');
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

        /*
         * ── **ذيلُ المسارِ حتّى «اسأل Hub»** (جاهزيّةُ الإنتاج) ──
         *
         * كان السلّمُ ينتهي عند التوجيهِ فيُترَك المالكُ عند آخرِ درجةٍ بلا
         * درجةٍ تالية، **والميزةُ التي بُنيت لأجلِ المسارِ كلِّه لا تُذكَر**.
         * فمُدّ إلى منتهاه: توليدٌ مُثبَتٌ ← قدرةٌ مرفوعةٌ ← أوّلُ سؤالٍ حقيقيّ.
         *
         * **وكلُّ درجةٍ وجهةٌ تُنقَر** — فلا طرفيّةَ بين خطوتين ولا تعديلَ ملفّ.
         */
        if (! AiGateway::generationVerified()) {
            return ['title' => 'السلسلةُ جاهزة — أثبِت التوليدَ بالفاحص D (يُنفق رصيداً بإقرارِك)',
                    'route' => 'ai.models.all', 'param' => null];
        }
        if (! hub_capability(AskPolicy::CAPABILITY)) {
            return ['title' => 'التوليدُ مُثبَتٌ وقدرةُ المساعدِ مُطفأةٌ من سجلِّ القدرات',
                    'route' => 'features.index', 'param' => null];
        }
        if (AskGeneratorFactory::which() !== AskGeneratorFactory::LIVE) {
            return ['title' => (string) (AskGeneratorFactory::why() ?? 'التهيئةُ لم تكتمل بعد'),
                    'route' => 'ai.profiles.index', 'param' => null];
        }

        return ['title' => 'كلُّ شيءٍ جاهز — اسأل أوّلَ سؤالٍ حقيقيّ في «اسأل Hub»',
                'route' => 'ask.index', 'param' => null];
    }

    /**
     * **مسارُ قبولِ الإنتاجِ مرئيّاً** — تسعُ درجاتٍ كلٌّ منها **حالةٌ مقروءة**
     * لا مربّعٌ يُؤشَّر يدويّاً.
     *
     * ولمَ يُعرَض السلّمُ كلُّه والخطوةُ التاليةُ واحدة؟ لأنّ الخطوةَ الواحدةَ
     * تقول **ما الآن** ولا تقول **كم بقي**. ومالكٌ يوشك أن يُدخل اعتماداً
     * مدفوعاً يستحقّ أن يرى الطريقَ كاملاً قبل أن يخطو.
     *
     * **وترتيبُ الدرجاتِ صُحِّح بالمصدر.** كان السلّمُ يضع B قبلَ الاكتشافِ
     * والاستيراد، فيُرسَل المالكُ ليفحصَ اعتماداً **بلا نموذج** — وفحصُ
     * الاعتمادِ عند البوّابةِ يختبر **(اعتماداً × نموذجاً)**: لا مسارَ فيها
     * يختبر اعتماداً مجرّداً، ومسارُ الفحصِ يقرأ اسمَ النموذجِ قراءةً لا
     * تحتمل غيابَه فينكسر داخليّاً. فصارت درجةُ «الاعتماد» أوّلاً (إدخالُه
     * وحده)، ثمّ الاكتشافُ والاستيراد، ثمّ B إثباتاً.
     *
     * @return list<array{key:string, title:string, done:bool, route:?string}>
     */
    public static function acceptancePath(): array
    {
        $c = self::counts();

        return [
            ['key' => 'A', 'title' => 'البوّابةُ مهيّأةٌ والاتصالُ مُختبَر',
             'done' => AiGateway::probePassed(), 'route' => 'ai.settings'],
            ['key' => 'Credential', 'title' => 'اعتمادُ مزوّدٍ مُدخَلٌ في خزنةِ البوّابة',
             'done' => $c['providers_credentialed'] > 0, 'route' => 'ai.providers.index'],
            ['key' => 'Discovery', 'title' => 'نماذجُ المزوّدِ مُكتشَفة',
             'done' => $c['models'] > 0, 'route' => 'ai.providers.index'],
            ['key' => 'Import', 'title' => 'نماذجُ مُستورَدةٌ إلى سجلِّ Hub',
             'done' => $c['models'] > 0, 'route' => 'ai.models.all'],
            ['key' => 'B', 'title' => 'المزوّدُ قبِل اعتمادَنا على نموذجٍ مسجَّل (يُنفق بإقرارِك)',
             'done' => $c['providers_verified'] > 0, 'route' => 'ai.providers.index'],
            ['key' => 'Enable', 'title' => 'نموذجٌ مُفعَّلٌ ومربوطٌ بغرض',
             'done' => $c['profiles_ready'] > 0, 'route' => 'ai.profiles.index'],
            ['key' => 'C', 'title' => 'النموذجُ مُعلَنٌ عند البوّابةِ (فحصٌ بكلفةِ صفر)',
             'done' => $c['models_enabled'] > 0, 'route' => 'ai.models.all'],
            ['key' => 'D', 'title' => 'توليدٌ حقيقيٌّ تحقّق (يُنفق بإقرارِك)',
             'done' => AiGateway::generationVerified(), 'route' => 'ai.models.all'],
            ['key' => 'Ask', 'title' => 'مساعدُ «اسأل Hub» حيٌّ ويُجيب',
             'done' => AskGeneratorFactory::which() === AskGeneratorFactory::LIVE, 'route' => 'ask.index'],
        ];
    }
}
