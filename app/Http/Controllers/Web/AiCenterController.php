<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AiGateway;
use App\Support\ConnectionProbe;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * **مركزُ الذكاء الاصطناعيّ** (المرحلة ١ · الأساس).
 *
 * بوّابةُ LiteLLM تعمل على **خادمِ Hub نفسِه** مربوطةً بـ`127.0.0.1`، وهنا
 * تُضبَط ويُفحَص اتصالُها. والمرحلةُ الأولى **أساسٌ لا وعد**: لا مزوّدين ولا
 * نماذجَ ولا محادثة — وأقسامُها تُعرَض موسومةً بمرحلتِها **بلا زرٍّ يوهم أنّها
 * تعمل**، لأنّ زرّاً لا يفعل شيئاً أسوأُ من غيابِه.
 */
class AiCenterController extends Controller
{
    /**
     * حارسُ الكتابة — **مُفوَّضٌ إلى `AiAccess` لا منسوخٌ** (W8).
     *
     * كان خمسةُ متحكّماتٍ تكتب هذا الشرطَ نسخاً. والنسخُ يعمل اليوم ويفترق
     * غداً: يُضاف علمٌ في أربعةٍ ويُنسى في الخامس.
     */
    protected function gate(): void
    {
        \App\Support\AiAccess::gateManage();
    }

    /**
     * **نظرةٌ — القسمُ الأوّلُ من السبعة** (W8 · §١١).
     *
     * تجيب عن سؤالٍ واحد: **أيعمل؟ وإن لم يعمل فما الخطوةُ التالية؟** ولذلك
     * ليست لوحةَ أرقامٍ بل سلّمُ جاهزيّةٍ وطابورُ انتباهٍ وخطوةٌ واحدةٌ تالية.
     *
     * **وبابُها القراءةُ** — فحاملُ `aiView` يراها ولا يكتب فيها شيئاً.
     */
    public function index()
    {
        \App\Support\AiAccess::gateView();

        return view('ai.center', [
            'snap'     => \App\Support\AiOverview::snapshot(),
            // **مسارُ القبولِ كاملاً** — ثماني درجاتٍ تُقرأ حالتُها من النظامِ لا تُؤشَّر
            'path'     => \App\Support\AiOverview::acceptancePath(),
            'sections' => \App\Support\AiAccess::sections(),
            'section'  => 'overview',
            'manage'   => \App\Support\AiAccess::canManage(),
        ]);
    }

    /**
     * **الإعداداتُ — شاشةُ المرحلةِ الأولى بموضعِها الجديد** (W8 · §١١).
     *
     * الخطّةُ تسمّيها «شاشةُ المرحلة ١ الحاليّة»، فهي تُنقَل ولا تُكتَب ثانيةً.
     * **ومساراتُ الكتابةِ الثلاثةُ بعناوينِها كما هي** (`ai.save` · `ai.test` ·
     * `ai.forget`) فلا عقدَ يُكسَر.
     */
    public function settings()
    {
        $this->gate();

        return view('ai.index', [
            // **لا مفتاحَ يبلغ القالبَ البتّة** — القناعُ وحدَه، وهو لا يكشف
            // حتّى طولَ السرّ (النقاطُ ثابتةُ العدد).
            'url'        => AiGateway::baseUrl(),
            'keyMask'    => AiGateway::mask(),
            'hasKey'     => AiGateway::key() !== '',
            // **ثلاثُ حقائقَ مفترقةٌ لا واحدة** (تصحيحُ المالك · ١)
            'configured' => AiGateway::configured(),
            'probeOk'    => AiGateway::probePassed(),
            'probedAt'   => AiGateway::probedAt(),
            'genOk'      => AiGateway::generationVerified(),
            'enabled'    => AiGateway::enabled(),
            'port'       => AiGateway::port(),
            'whyNot'     => AiGateway::whyNotReady(),
            'loopback'   => AiGateway::isLoopback(),
            'timeouts'   => AiGateway::timeouts(),
            // الفحصُ **لا يُطلَق مع فتحِ الصفحة**: صفحةٌ تتّصل بالشبكة عند كلِّ
            // عرضٍ تصير بطيئةً ومزعجةً لخدمةٍ متوقّفة. الفحصُ بزرٍّ صريح.
            'probe'      => session('ai.probe'),
            'sections'   => \App\Support\AiAccess::sections(),
            'section'    => 'settings',
        ]);
    }

    /**
     * **الاستهلاك — ولا جدولَ استهلاكٍ في Hub** (§١٣).
     *
     * البوّابةُ تملك `LiteLLM_SpendLogs` وأخواتِها، فبناءُ جدولِ قياسٍ هنا
     * **تكرارٌ يفترق عن الأصلِ خلال أسابيع** ثمّ يُصدَّق أحدُهما عشوائيّاً.
     * فالشاشةُ تقرأ من المصدرِ وتعرض، **ولا تخزّن رقماً واحداً**.
     *
     * **والتكلفةُ مقدَّرةٌ ويُقال ذلك** — من خريطةِ أسعارٍ لا من فاتورةِ
     * مزوّد. وإخفاءُ هذا الحدِّ يجعل الشاشةَ تكذب.
     *
     * **والقراءةُ بزرٍّ لا مع فتحِ الصفحة**: صفحةٌ تتّصل بالبوّابةِ عند كلِّ
     * عرضٍ تصير بطيئةً ومزعجةً لخدمةٍ متوقّفة — قاعدةُ المرحلةِ الأولى نفسُها.
     */
    public function usage(Request $r)
    {
        \App\Support\AiAccess::gateCost();

        $pull   = $r->boolean('pull');
        $spend  = $pull ? \App\Support\LiteLlmAdmin::spendByModel()   : null;
        $active = $pull ? \App\Support\LiteLlmAdmin::activityByModel() : null;

        return view('ai.usage', [
            'sections'   => \App\Support\AiAccess::sections(),
            'section'    => 'usage',
            'configured' => AiGateway::configured(),
            'whyNot'     => AiGateway::whyNotReady(),
            'pulled'     => $pull,
            'spend'      => $spend,
            'activity'   => $active,
            'attribution' => \App\Support\AiUsage::ATTRIBUTION,
        ]);
    }

    /**
     * **التشخيص — لماذا فشل، بلا سرّ** (§١١).
     *
     * وشجرةُ القرارِ تقول **أين انقطع الخيط** بدل أن تعرض رسالةَ خطأٍ خاماً:
     * البوّابةُ؟ الاعتمادُ؟ النموذجُ؟ الشبكة؟ ورسالةٌ خامٌ بلا موضعٍ تجعل
     * المديرَ يُصلح ما ليس معطوباً.
     *
     * **وكلُّ نصٍّ معروضٍ مرّ بـ`Redactor` عند مصدرِه** — `ConnectionProbe::row()`
     * هي نقطةُ الاختناقِ الوحيدةُ التي تصنع حقلَ الخطأ.
     */
    public function diagnostics()
    {
        \App\Support\AiAccess::gateView();

        return view('ai.diagnostics', [
            'sections' => \App\Support\AiAccess::sections(),
            'section'  => 'diagnostics',
            'chain'    => \App\Support\AiDiagnostics::chain(),
            'probes'   => \App\Support\AiDiagnostics::recentProbes(),
            'manage'   => \App\Support\AiAccess::canManage(),
            'recon'    => session('ai.recon'),
        ]);
    }

    /**
     * **تصالحُ الحالةِ مع البوّابة** (§١٧ · R3) — معاينةٌ ثمّ كتابةٌ بقرار.
     *
     * والمعاينةُ **قراءةٌ محضةٌ مجّانيّة** فلا تصعيدَ عليها؛ أمّا الكتابةُ
     * فتُغيّر ما يُوجَّه إليه الطلبُ، وهي من صنفِ ما يُحرَس بالهويّةِ الطازجة.
     *
     * **ولا يُستورَد نموذجٌ ولا يُحذَف صفّ** — الوسمُ عكوسٌ والحذفُ ليس كذلك.
     */
    public function reconcile(Request $r)
    {
        $apply = $r->boolean('apply');

        $apply ? $this->gate() : \App\Support\AiAccess::gateView();
        if ($apply && ($resp = hub_require_stepup())) return $resp;

        $res = \App\Support\AiReconcile::run($apply);

        if (! $res['ok']) {
            return back()->withErrors(['recon' => (string) $res['error']]);
        }

        return back()->with('ai.recon', $res)->with('ok', $res['applied']
            ? 'تمّ التصالح — ووُسم ' . $res['counts']['orphaned'] . ' يتيماً، ورُفع الوسمُ عن '
                . $res['counts']['restored']
            : 'معاينةُ تصالحٍ — **ولم يُكتَب شيء**');
    }

    /**
     * حفظُ إعدادِ البوّابة.
     *
     * **والمفتاحُ الفارغُ يُبقي المخزون** — وهي ليست راحةً بل ضرورة: الشاشةُ
     * لا تعرض السرَّ (قناعٌ فقط)، فلو كان الفارغُ يمسح لمحا كلُّ حفظٍ لحقلٍ
     * آخرَ المفتاحَ المحفوظ. والمسحُ المتعمَّدُ له زرُّه.
     */
    public function save(Request $r)
    {
        $this->gate();

        /*
         * **تغييرُ المفتاحِ يحتاج هويّةً طازجة** (تصحيحُ المالك · ٦) — بآليّةِ
         * Hub القائمةِ نفسِها (`hub_require_stepup`) لا بآليّةٍ ثانية. وجلسةٌ
         * مسروقةٌ أو جهازٌ تُرك مفتوحاً لا يكفي لاستبدالِ مفتاحٍ يحمل الإنفاقَ
         * كلَّه. والتحقّقُ **عند تغييرِ المفتاحِ وحدَه**، فضبطُ مهلةٍ لا يستحقّه.
         */
        if (filled($r->input('key'))) {
            if ($redirect = hub_require_stepup()) return $redirect;
        }

        $d = $r->validate([
            'url'             => ['nullable', 'url', 'max:300'],
            'key'             => ['nullable', 'string', 'max:500'],
            'enabled'         => ['nullable', 'boolean'],
            'timeout_connect' => ['nullable', 'integer', 'between:1,30'],
            'timeout_read'    => ['nullable', 'integer', 'between:1,300'],
        ], [], [
            'url' => 'عنوان البوّابة', 'key' => 'مفتاح الإدارة',
            'timeout_connect' => 'مهلة الاتصال', 'timeout_read' => 'مهلة القراءة',
        ]);

        $url = rtrim(trim((string) ($d['url'] ?? '')), '/');

        // العنوانُ يمرّ ببوّابةِ الخروج قبل أن يُحفَظ — فلا يُخزَّن هدفٌ لا
        // يُسمَح بطلبِه أصلاً، ولا يُكتشَف المنعُ بعد الحفظ عند أوّلِ فحص.
        if ($url !== '') {
            $probe = AiGateway::baseUrl() === $url ? $url . '/v1/models' : null;
            $gate = $probe !== null
                ? AiGateway::outboundGate($probe)
                : self::gateForCandidate($url);
            if (! $gate['ok']) {
                // **`withInput()` عارٍ كان سيُفلِش المفتاحَ إلى النموذج** —
                // فيعود السرُّ إلى HTML في `old('key')`. يُستثنى صراحةً.
                return back()->withErrors(['url' => 'رُفض العنوان: ' . $gate['why']
                    . ' — البوّابةُ المتوقَّعةُ على http://127.0.0.1:4000'])
                    ->withInput($r->except(['key', '_token']));
            }
        }

        // الكاتبُ الواحد: التشفيرُ والإبطالُ وصفُّ التاريخ والتدقيقُ عنده
        Settings::batch('ai', function () use ($d, $url) {
            Settings::put('ai.gateway_url', $url, 'ai');
            Settings::put('ai.enabled', (bool) ($d['enabled'] ?? false), 'ai');
            if (isset($d['timeout_connect'])) Settings::put('ai.timeout_connect', (int) $d['timeout_connect'], 'ai');
            if (isset($d['timeout_read']))    Settings::put('ai.timeout_read', (int) $d['timeout_read'], 'ai');
            // السرُّ: المكتوبُ يُشفَّر عند الكاتب، والفارغُ يُبقي المخزون
            if (filled($d['key'] ?? null))    Settings::put('ai.gateway_key', trim((string) $d['key']), 'ai');
        }, ['name' => 'ai.* — من مركز الذكاء الاصطناعيّ']);

        return back()->with('ok', 'حُفظ إعدادُ البوّابة — اختبر الاتصالَ الآن');
    }

    /** مسحُ المفتاح — فعلٌ صريحٌ لا أثرٌ جانبيٌّ لحفظِ حقلٍ آخر */
    public function forgetKey()
    {
        $this->gate();
        if ($redirect = hub_require_stepup()) return $redirect;   // تصحيحُ المالك · ٦

        Settings::batch('ai', function () {
            Settings::put('ai.gateway_key', '', 'ai');
            // بلا مفتاحٍ لا تكامل — يُطفأ صراحةً بدل أن يبقى «مُشغَّلاً» عاطلاً
            Settings::put('ai.enabled', false, 'ai');
            // ونتيجةُ الفحصِ تسقط معه: فحصٌ نجح بمفتاحٍ مُسح ليس دليلاً على شيء
            Settings::put('ai.probe_ok', false, 'ai');
        }, ['name' => 'ai.gateway_key — مسحٌ من مركز الذكاء الاصطناعيّ']);

        return back()->with('ok', 'مُسح مفتاحُ الإدارة وأُطفئ التكامل');
    }

    /**
     * فحصُ الاتصال — بالفاحصِ الواحدِ لا بفاحصٍ ثانٍ ينحرف.
     *
     * والنتيجةُ تُحفَظ في الجلسةِ لتُعرَض بعد التحويل، **بعد المرورِ بمُطهِّرِ
     * الرسائل داخل `ConnectionProbe`** — فلا يخرج سرٌّ في نصِّ خطأ.
     */
    public function test()
    {
        $this->gate();
        // الفحصُ يستعمل المفتاحَ ويكشف صحّتَه، فيُحرَس كما يُحرَس تغييرُه
        if ($redirect = hub_require_stepup()) return $redirect;   // تصحيحُ المالك · ٦

        $res = ConnectionProbe::litellm();
        $line = ConnectionProbe::line($res);

        /*
         * **النتيجةُ تُختَم ببصمةِ الإعدادِ الذي فُحص** (تصحيحُ المالك · ١):
         * فلو غُيّر العنوانُ أو المفتاحُ بعدها اختلفت البصمةُ، و`probePassed()`
         * تعود `false` تلقائيّاً — **بلا خطوةِ إبطالٍ يدويّةٍ تُنسى**. ونجاحٌ
         * قديمٌ على إعدادٍ آخرَ لا يُعرَض دليلاً على الإعدادِ الجديد.
         */
        Settings::batch('ai', function () use ($res) {
            Settings::put('ai.probe_ok', $res['up'] === true, 'ai');
            Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'ai');
            Settings::put('ai.probe_at', now()->toDateTimeString(), 'ai');
        }, ['name' => 'ai.probe_* — نتيجةُ فحصِ البوّابة']);

        // أثرٌ يقول **مَن فحص ومتى وبأيِّ نتيجة** — بلا عنوانٍ ولا مفتاح
        hub_audit('فحصُ بوّابة الذكاء الاصطناعيّ', null, null, null, [
            'up' => $res['up'] === null ? 'لم يُجرَّب' : ($res['up'] ? 'ناجح' : 'فاشل'),
            'code' => $res['code'], 'ms' => $res['ms'],
        ]);

        return $res['up'] === true
            ? back()->with('ok', $line)->with('ai.probe', $res)
            : back()->withErrors(['probe' => $line])->with('ai.probe', $res);
    }

    /**
     * بوّابةُ الخروجِ لعنوانٍ **مرشَّحٍ لم يُحفَظ بعد**.
     *
     * `AiGateway::outboundGate` تقيس بالعنوانِ المحفوظ، والمرشَّحُ ليس محفوظاً
     * بعد — فتُقاس شروطُه نفسُها عليه هو: loopback حرفيٌّ يُقبَل، وما عداه
     * يمرّ بالحارسِ العامّ كاملاً.
     */
    protected static function gateForCandidate(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (is_string($host) && in_array($host, ['127.0.0.1', '::1', '[::1]'], true)
            && in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => true, 'why' => '', 'ip' => null];
        }

        return hub_outbound_ok($url);
    }
}
