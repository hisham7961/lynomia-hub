<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Support\AiGovernance;
use App\Support\AiProfiles;
use App\Support\AiRouteRun;
use App\Support\AiRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **سلوكُ الاحتياطِ في الإنتاج — ثلاثةُ مصائرَ لا واحد** (المرحلة ٥ · W3).
 *
 * ── **التصنيفُ الذي يُبنى عليه كلُّ شيء** ──
 *
 * | المصير | متى | الثمن إن أُخطئ |
 * |---|---|---|
 * | **إعادةٌ على النموذجِ نفسِه** | عطلٌ عابرٌ يزول بانتظار | نداءٌ ضائعٌ لكلِّ إعادةٍ عن عطلٍ دائم |
 * | **احتياطٌ إلى التالي** | عطلٌ خاصٌّ بهذا النشرِ أو المزوّد | نداءٌ ثانٍ على بابٍ مغلقٍ سلفاً |
 * | **توقّفٌ نهائيّ** | قرارٌ أو عيبٌ عندنا أو مالٌ نفد | إمّا التفافٌ على قرار، وإمّا إنفاقٌ ممنوع |
 *
 * وخلطُ الثلاثةِ ليس نظريّاً: قبولُ إنتاجٍ حقيقيٌّ أنفق **ثلاثةَ نداءاتٍ**
 * على رصيدٍ نفد — قُرئ حدَّ معدّلٍ فانتُظرت مهلتُه، ثمّ احتيط إلى نموذجٍ ثانٍ
 * على **الاعتمادِ الميّتِ نفسِه**.
 *
 * ── **ولا نداءَ شبكةٍ هنا ولا توليدَ ولا دينار** ──
 *
 * `AiRouteRun` محرّكُ قرارٍ خالص: يُغذّى بإشارةِ إخفاقٍ فيُجيب بما يُفعَل.
 * فثلاثةَ عشرَ صنفَ إخفاقٍ تُحاكى هنا **على سلسلةٍ حقيقيّةٍ من ثلاثةِ نماذج**
 * بلا مزوّدٍ ولا كلفة.
 */
class AiFallbackBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();
        Cache::flush();
    }

    // ── البناء ────────────────────────────────────────────────────────

    private function provider(string $suffix): AiProvider
    {
        return AiProvider::create([
            'catalog_key'      => 'openai',
            'label'            => 'مزوّدٌ ' . $suffix,
            'enabled'          => true,
            'credential_name'  => 'hub-fb-' . $suffix . '-' . substr(sha1($suffix . microtime()), 0, 8),
            'credential_state' => 'configured',
        ]);
    }

    private function model(AiProvider $p, string $name, int $ctx = 8000): AiModel
    {
        return AiModel::create([
            'provider_id'        => $p->id,
            'litellm_model_name' => $name,
            'upstream_model'     => 'fake/' . $name,
            'display_name'       => $name,
            'enabled'            => true,
            'health'             => 'UNKNOWN',
            'capabilities'       => ['chat'  => ['v' => true, 'src' => 'litellm'],
                                     'tools' => ['v' => true, 'src' => 'litellm']],
            'limits'             => ['context_window' => ['v' => $ctx, 'src' => 'litellm']],
            'params'             => [],
            'pricing'            => ['input_per_1k'  => ['v' => 0.001, 'src' => 'litellm'],
                                     'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                                     'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);
    }

    /**
     * **ثلاثةُ نماذجَ على ثلاثةِ مزوّدين** — فالاحتياطُ يُقاس لا تُهدّئه التهدئة.
     *
     * **وتُبنى مرّةً في الاختبارِ الواحد**: اسمُ النموذجِ فريدٌ عالميّاً، فبناءٌ
     * ثانٍ داخلَ حلقةٍ يصطدم بالفهرسِ الفريد — وهو درسُ v2.583.0 نفسُه.
     */
    private ?AiProfile $built = null;

    private function chain(array $contexts = [8000, 32000, 128000]): AiProfile
    {
        if ($this->built !== null) return $this->built->fresh();

        $g = AiProfile::query()->where('key', 'general')->firstOrFail();

        foreach ($contexts as $i => $ctx) {
            AiProfiles::attach($g, $this->model($this->provider('p' . $i),
                ['alpha', 'beta', 'gamma'][$i] ?? ('m' . $i), $ctx));
        }

        return ($this->built = $g->fresh());
    }

    private function journey(array $opts = []): AiRouteRun
    {
        return AiRouteRun::for($this->chain(), $opts);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① المصيرُ الأوّل — إعادةٌ على النموذجِ نفسِه
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **عطلٌ عابرٌ (٥٠٠/٥٠٢/٥٠٣/٥٠٤): إعادتان بتراجعٍ أُسّيٍّ ثمّ احتياط.**
     *
     * والتراجعُ الأُسّيُّ لا تزيينٌ: إعادةٌ فوريّةٌ على خادمٍ يتعافى تُصادف
     * الحالةَ نفسَها، فتُنفق نداءً وتُطيل العطل.
     */
    public function test_العطلُ_العابرُ_يُعاد_مرّتين_ثمّ_يُحتاط(): void
    {
        foreach ([500, 502, 503, 504] as $code) {
            Cache::flush();
            $run = $this->journey();

            $d1 = $run->fail(['code' => $code]);
            $this->assertSame('retry', $d1['action'], "الرمزُ {$code} لم يُعَد");
            $this->assertNotNull($d1['delay'], 'إعادةٌ بلا مهلة');

            $d2 = $run->fail(['code' => $code]);
            $this->assertSame('retry', $d2['action']);
            $this->assertGreaterThan((int) $d1['delay'], (int) $d2['delay'],
                '**المهلةُ لا تتراجع أُسّيّاً — إعادةٌ فوريّةٌ على خادمٍ يتعافى**');

            $d3 = $run->fail(['code' => $code]);
            $this->assertSame('fallback', $d3['action'], 'الإعادةُ بلا نهاية');
            $this->assertSame('beta', (string) $run->model()?->litellm_model_name);
        }
    }

    /** **وحدُّ المعدّلِ الحقيقيُّ يُعاد مرّةً بالمهلةِ المُعلَنةِ لا بتراجعٍ نختلقه** */
    public function test_حدُّ_المعدّلِ_يحترم_المهلةَ_المُعلَنة(): void
    {
        $run = $this->journey();

        $d = $run->fail(['code' => 429, 'body' => 'rate limit exceeded', 'retry_after' => 7]);

        $this->assertSame('retry', $d['action']);
        $this->assertSame(7, $d['delay'], '**`Retry-After` المُعلَنةُ أُهملت**');
        $this->assertSame('rate_limited', $d['cause']);

        $this->assertSame('fallback', $run->fail(['code' => 429, 'body' => 'rate limit'])['action']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② المصيرُ الثاني — احتياطٌ إلى التالي
    // ═══════════════════════════════════════════════════════════════════

    /** **نموذجٌ أُزيل من المنبع: لا إعادةَ، ويُوسَم `UNAVAILABLE` فلا يُعاد إليه** */
    public function test_نموذجٌ_أُزيل_يُوسَم_ولا_يُعاد_إليه(): void
    {
        $run = $this->journey();
        $gone = $run->model();

        $d = $run->fail(['code' => 404]);

        $this->assertSame('fallback', $d['action']);
        $this->assertSame('model_gone', $d['cause']);
        $this->assertSame('UNAVAILABLE', (string) $gone->fresh()->health,
            '**نموذجٌ مُزالٌ من المنبعِ بقي مرشَّحاً للطلبِ التالي**');
    }

    /**
     * **وتجاوزُ السياقِ يُحتاط إلى نافذةٍ أوسعَ مُثبَتةٍ وحدَها.**
     *
     * ونافذةٌ **مجهولةٌ ليست أوسع**: قفزةٌ إليها تُعيد الفشلَ نفسَه بكلفةٍ ثانية.
     */
    public function test_تجاوزُ_السياقِ_يقفز_إلى_نافذةٍ_أوسعَ_مُثبَتة(): void
    {
        $run = $this->journey();

        $d = $run->fail(['code' => 400, 'body' => 'maximum context length exceeded']);

        $this->assertSame('fallback', $d['action']);
        $this->assertSame('context_overflow', $d['cause']);
        $this->assertSame('beta', (string) $run->model()?->litellm_model_name,
            'قفز إلى نافذةٍ ليست أوسع');
    }

    /** **ونافذةٌ مجهولةٌ لا تُعَدّ أوسعَ — فيتوقّف بدل أن يُنفق نداءً ثانياً** */
    public function test_نافذةٌ_مجهولةٌ_ليست_أوسعَ_فلا_يُقفَز_إليها(): void
    {
        $g = AiProfile::query()->where('key', 'general')->firstOrFail();
        $p = $this->provider('ctx');

        AiProfiles::attach($g, $this->model($p, 'narrow', 8000));
        $blind = $this->model($p, 'blind', 8000);
        $blind->forceFill(['limits' => ['context_window' => ['v' => null, 'src' => 'unknown']]])->save();
        AiProfiles::attach($g, $blind->fresh());

        $run = AiRouteRun::for($g->fresh());
        $d   = $run->fail(['code' => 400, 'body' => 'context window exceeded']);

        $this->assertSame('stop', $d['action'],
            '**قفزَ إلى نافذةٍ مجهولةٍ — والفشلُ نفسُه يعود بكلفةٍ ثانية**');
    }

    /**
     * **ونفادُ الرصيدِ يُحتاط إلى مزوّدٍ آخرَ وحدَه.**
     *
     * الرصيدُ رصيدُ حسابٍ عند مزوّدٍ بعينِه، فكلُّ نموذجٍ على اعتمادِه يسقط
     * سقوطَه — **وقفزةٌ إليه إنفاقُ نداءٍ على بابٍ مغلقٍ سلفاً**.
     */
    public function test_نفادُ_الرصيدِ_يقفز_إلى_مزوّدٍ_آخرَ_لا_إلى_جارٍ(): void
    {
        $g = AiProfile::query()->where('key', 'general')->firstOrFail();
        $same = $this->provider('same');

        AiProfiles::attach($g, $this->model($same, 'first'));
        AiProfiles::attach($g, $this->model($same, 'sibling'));      // الاعتمادُ الميّتُ نفسُه
        AiProfiles::attach($g, $this->model($this->provider('other'), 'elsewhere'));

        $run = AiRouteRun::for($g->fresh());
        $d   = $run->fail(['code' => 402]);

        $this->assertSame('fallback', $d['action']);
        $this->assertSame('provider_credits', $d['cause']);
        $this->assertSame('elsewhere', (string) $run->model()?->litellm_model_name,
            '**قفزَ إلى نموذجٍ على الاعتمادِ الذي نفد رصيدُه**');
    }

    /** **ومزوّدٌ في تهدئةٍ يُتخطّى قبل إنفاقِ مهلةٍ عليه** */
    public function test_المزوّدُ_المُستبعَدُ_يُتخطّى_قبل_النداء(): void
    {
        $g = $this->chain();
        $first = AiProfiles::chain($g)->first();

        for ($i = 0; $i < AiRouting::COOLDOWN_AFTER; $i++) {
            AiRouting::noteFailure((string) $first->provider_id);
        }

        $run = AiRouteRun::for($g);

        $this->assertNotSame((string) $first->litellm_model_name,
            (string) $run->model()?->litellm_model_name,
            '**رحلةٌ فُتحت على مزوّدٍ في تهدئةٍ — مهلةٌ تُهدَر على بابٍ مغلق**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ المصيرُ الثالث — توقّفٌ نهائيّ
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **اعتمادٌ خاطئ: لا إعادةَ ولا احتياط.**
     *
     * والاحتياطُ هنا **يُخفي العطل**: النموذجُ الثاني قد يعمل، فيبقى الاعتمادُ
     * الأوّلُ مكسوراً شهوراً ولا أحدَ يعلم.
     */
    public function test_الاعتمادُ_الخاطئُ_يتوقّف_ولا_يُخفى_باحتياط(): void
    {
        foreach ([401, 403] as $code) {
            Cache::flush();
            $run = $this->journey();
            $d   = $run->fail(['code' => $code]);

            $this->assertSame('stop', $d['action'], "الرمزُ {$code} احتاط فأخفى عطلَ اعتماد");
            $this->assertSame('auth', $d['cause']);
            $this->assertTrue($run->closed());
        }
    }

    /** **وعيبٌ عندنا (٤٠٠) لا يُصلحه نموذجٌ آخر** */
    public function test_العيبُ_عندنا_لا_يُصلحه_نموذجٌ_آخر(): void
    {
        $d = $this->journey()->fail(['code' => 400, 'body' => 'invalid field "foo"']);

        $this->assertSame('stop', $d['action']);
        $this->assertSame('bad_request', $d['cause']);
    }

    /** **ومنعُ المحتوى نتيجةٌ لا عطل — فلا يُعاد ولا يُحتاط** */
    public function test_منعُ_المحتوى_نتيجةٌ_لا_عطل(): void
    {
        $d = $this->journey()->fail(['code' => 400, 'body' => 'content policy violation']);

        $this->assertSame('stop', $d['action']);
        $this->assertSame('content_policy', $d['cause']);
    }

    /**
     * **وبثٌّ انقطع بعد أن أُنتج مخرجٌ جزئيّ: لا إعادةَ بحال.**
     *
     * فقد يُكرِّر أثراً جانبيّاً — **والصوابُ أهمُّ من الإتمام**.
     */
    public function test_البثُّ_المنقطعُ_لا_يُعاد_ولا_يُحتاط(): void
    {
        $d = $this->journey()->fail(['code' => 500, 'streamed' => true]);

        $this->assertSame('stop', $d['action'],
            '**بثٌّ أنتج مخرجاً جزئيّاً أُعيد — فقد يُكرَّر أثرٌ جانبيّ**');
        $this->assertSame('stream_interrupted', $d['cause']);
    }

    /** **وسقفُ الكلفةِ يمنع قفزةً تتجاوزه — والتوقّفُ أرخصُ من التجاوز** */
    public function test_سقفُ_الكلفةِ_يمنع_القفزةَ_لا_يُؤجّلها(): void
    {
        $run = AiRouteRun::for($this->chain(),
            ['ceiling' => 0.0025, 'in_tokens' => 1000, 'out_tokens' => 1000]);

        $d = $run->fail(['code' => 404]);

        $this->assertSame('stop', $d['action'], '**قفزةٌ تجاوزت سقفَ الطلب**');
        $this->assertStringContainsString('سقف', (string) $run->why());
    }

    /** **وكلفةٌ مجهولةٌ مع سقفٍ مفروضٍ لا تمرّ — `null` ليست صفراً** */
    public function test_الكلفةُ_المجهولةُ_لا_تمرّ_تحت_سقفٍ_مفروض(): void
    {
        $g = AiProfile::query()->where('key', 'general')->firstOrFail();
        $m = $this->model($this->provider('free'), 'priceless');
        $m->forceFill(['pricing' => [
            'input_per_1k'  => ['v' => null, 'src' => 'unknown'],
            'output_per_1k' => ['v' => null, 'src' => 'unknown'],
            'currency' => 'USD', 'unit' => 'per_1k_tokens',
        ]])->save();
        AiProfiles::attach($g, $m->fresh());

        $run = AiRouteRun::for($g->fresh(),
            ['ceiling' => 1.0, 'in_tokens' => 1000, 'out_tokens' => 1000]);

        $this->assertTrue($run->closed(),
            '**كلفةٌ مجهولةٌ مرّت تحت سقفٍ وُضع عمداً — فالسقفُ أُبطل صمتاً**');
        $this->assertStringContainsString('مجهولة', (string) $run->why());
    }

    /** **وعمقُ الاحتياطِ قفزتان — لا رابعَ للثلاثة** */
    public function test_عمقُ_الاحتياطِ_قفزتانِ_لا_أكثر(): void
    {
        $run = $this->journey();

        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);
        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);

        $d = $run->fail(['code' => 404]);
        $this->assertSame('stop', $d['action'], '**الرحلةُ تجاوزت عمقَ الاحتياطِ الأقصى**');
        $this->assertSame(AiRouting::MAX_DEPTH, $run->depth());
    }

    // ═══════════════════════════════════════════════════════════════════
    // ④ السياسةُ تُعاد قراءتُها عند كلِّ قفزةٍ — لا عند الفتحِ وحدَه
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **السماحُ بالنموذجِ «أ» ليس سماحاً بالنموذجِ «ب».**
     *
     * ولو قُرئت السياسةُ مرّةً عند الفتحِ وحدَها لصار **الاحتياطُ بابَ التفافٍ
     * عليها**: يُمنَع نموذجٌ في السياسة، ثمّ يُبلَغ بإسقاطِ الأوّلِ قصداً.
     */
    public function test_الحوكمةُ_تُعاد_عند_كلِّ_قفزةٍ_لا_عند_الفتحِ_وحدَه(): void
    {
        $g     = $this->chain();
        $links = AiProfiles::chain($g);
        $head  = (string) $links[0]->litellm_model_name;

        $seen = [];
        $run  = AiRouteRun::for($g)->governBy(function (AiModel $m) use (&$seen) {
            $seen[] = (string) $m->litellm_model_name;

            // يسمح بالأوّلِ وحدَه — فالقفزةُ يجب أن تُمنَع لا أن تمرّ
            return false;
        });

        $d = $run->fail(['code' => 404]);

        $this->assertNotEmpty($seen, '**الحاكمُ لم يُستشَر عند القفزةِ أصلاً**');
        $this->assertNotContains($head, $seen, 'الحاكمُ استُشير عن النموذجِ الحاليِّ لا عن الهدف');
        $this->assertSame('stop', $d['action'],
            '**السياسةُ مُنعت عن كلِّ هدفٍ والاحتياطُ مرّ — التفافٌ على الحوكمة**');
    }

    /** **وسلسلةٌ كلُّها على مزوّدٍ واحدٍ تُقال كما هي — نقصُ تهيئةٍ لا عطلُ احتياط** */
    public function test_سلسلةٌ_بلا_تكرارٍ_حقيقيٍّ_تُشخَّص_بصراحة(): void
    {
        $g    = AiProfile::query()->where('key', 'general')->firstOrFail();
        $solo = $this->provider('solo');

        foreach (['one', 'two', 'three'] as $n) AiProfiles::attach($g, $this->model($solo, $n));

        $run = AiRouteRun::for($g->fresh());
        $run->fail(['code' => 500]);
        $run->fail(['code' => 500]);
        $run->fail(['code' => 500]);   // ← المزوّدُ يُهدَّأ هنا

        $d = $run->fail(['code' => 500]);

        $this->assertSame('stop', $d['action']);
        $this->assertStringContainsString('تكرارٍ حقيقيّ', (string) $run->why(),
            '**«لا احتياط» و«كلُّ الاحتياطِ على مزوّدٍ مُستبعَد» خُلطتا — فعيبُ التهيئةِ يختفي**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑤ الأثرُ عند كلِّ قفزةٍ لا عند الإغلاق
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **كلُّ قفزةٍ تُسجَّل عند وقوعِها.**
     *
     * وإغلاقٌ متأخّرٌ يضيع إن مات الطابورُ في منتصفِ الرحلة — **والكلفةُ تكون
     * قد أُنفقت بالفعل**.
     */
    public function test_كلُّ_إخفاقٍ_يُسجَّل_فورَ_وقوعِه(): void
    {
        $run = $this->journey();

        $run->fail(['code' => 404]);
        $this->assertCount(1, $run->trail(), '**قفزةٌ وقعت ولم تُسجَّل**');

        $run->fail(['code' => 404]);
        $this->assertCount(2, $run->trail());

        foreach ($run->trail() as $hop) {
            foreach (['model', 'cause', 'why', 'depth', 'estimated_spend', 'at'] as $k) {
                $this->assertArrayHasKey($k, $hop, "أثرُ القفزةِ بلا `{$k}`");
            }
        }
    }

    /** **والنجاحُ لا يُسجَّل شيئاً — ويمسح تهدئةَ المزوّد** */
    public function test_النجاحُ_لا_يترك_أثرَ_قفزةٍ_ويمسح_التهدئة(): void
    {
        $run = $this->journey();
        $run->succeed();

        $this->assertSame([], $run->trail(), '**نجاحٌ كتب أثرَ إخفاق**');
        $this->assertTrue($run->closed());
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑥ التغطيةُ تُعَدّ — فلا صنفَ إخفاقٍ يبقى بلا قياس
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **كلُّ صفٍّ في جدولِ القرارِ مقيسٌ هنا بصنفِ إشارةٍ حقيقيّ.**
     *
     * وحارسٌ يعدّ الصفوفَ لا يكفي: لو أُضيف صفٌّ غداً بلا محاكاةٍ لمرّ.
     * فالقائمةُ أدناه **مكتوبةٌ بأسبابِ الجدولِ نفسِها** — وصفٌّ جديدٌ بلا سطرٍ
     * هنا يُسقِط هذا الاختبار.
     */
    public function test_كلُّ_صنفِ_إخفاقٍ_في_الجدولِ_له_محاكاةٌ_هنا(): void
    {
        $simulated = [
            'auth'                   => ['code' => 401],
            'bad_request'            => ['code' => 400, 'body' => 'invalid field'],
            'context_overflow'       => ['code' => 400, 'body' => 'maximum context length'],
            'unsupported_capability' => null,   // يُمنَع عند الاختيارِ لا عند الفشل
            'budget_exceeded'        => null,   // يُقاس بحارسِ السقفِ لا بإشارةِ مزوّد
            'content_policy'         => ['code' => 400, 'body' => 'content policy'],
            'rate_limited'           => ['code' => 429, 'body' => 'rate limit'],
            'provider_credits'       => ['code' => 402],
            'policy_denied'          => null,   // يُقاس بحاكمِ الحوكمةِ المحقون
            'transient'              => ['code' => 503],
            'model_gone'             => ['code' => 404],
            'provider_cooldown'      => null,   // حالةٌ محلّيّةٌ لا إشارةُ ردّ
            'stream_interrupted'     => ['code' => 500, 'streamed' => true],
        ];

        $this->assertSame(
            array_keys(AiRouting::TABLE),
            array_keys($simulated),
            '**صفٌّ في جدولِ القرارِ بلا محاكاةٍ — أو محاكاةٌ لصفٍّ حُذف**'
        );

        foreach ($simulated as $cause => $signal) {
            if ($signal === null) continue;

            $this->assertSame($cause, AiRouting::classify($signal),
                "الإشارةُ لا تُصنَّف `{$cause}` — فالمحاكاةُ تقيس غيرَ ما تدّعي");
        }
    }

    /** **وثلاثةُ مصائرَ لا رابعَ لها** */
    public function test_مصائرُ_الرحلةِ_أربعةُ_أفعالٍ_معروفة(): void
    {
        $this->assertSame(['retry', 'fallback', 'stop', 'done'], AiRouteRun::ACTIONS);

        $run     = $this->journey();
        $actions = [
            $run->fail(['code' => 503])['action'],      // retry
            $run->fail(['code' => 404])['action'],      // fallback
            $run->succeed()['action'],                  // done
        ];

        foreach ($actions as $a) {
            $this->assertContains($a, AiRouteRun::ACTIONS, "فعلٌ خارجَ المفرداتِ: {$a}");
        }
        $this->assertSame(['retry', 'fallback', 'done'], $actions);
    }
}
