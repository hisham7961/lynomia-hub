<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Models\AiProvider;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiRouteRun;
use App\Support\Ai\Routing\AiRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **ملفّاتُ السياسةِ وسلاسلُها ورحلةُ الطلبِ فيها** (المرحلة ٢ · W7 · §٨ · §٩).
 *
 * وأهمُّ ما تحرسه هذه الحزمة:
 *
 *  ① **ملفٌّ يشترط قدرةً لا يقبل `false` ولا `unknown`** — فلا تُرقَّى «لا
 *     نعرف» إلى «مدعوم» بالصمت، ولا تُمنَع ميزةٌ بلا سببٍ يُقال.
 *  ② **الحرّاسُ الأربعةُ يعملون على سلسلةٍ حقيقيّة** لا في الوثيقةِ وحدَها.
 *  ③ **صفوفُ §٨ تُحاكى على سلسلةٍ من ثلاثةِ نماذج** فيُرى أثرُ كلِّ صفٍّ
 *     في مَن يُجيب بعدَه — لا في مصفوفةِ قرارٍ مجرّدة.
 */
class AiProfileChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProfiles::seed();
    }

    // ── أدواتُ البناء ─────────────────────────────────────────────────

    private function provider(string $suffix = 'a', bool $enabled = true, string $state = 'configured'): AiProvider
    {
        return AiProvider::create([
            'catalog_key'      => 'openai',
            'label'            => 'مزوّدٌ وهميّ ' . $suffix,
            'enabled'          => $enabled,
            'credential_name'  => 'hub-fake-' . $suffix . '-' . substr(sha1($suffix . microtime()), 0, 8),
            'credential_state' => $state,
        ]);
    }

    /**
     * نموذجٌ بحقائقَ موسومةِ المصدر. و`$caps` تأخذ `true`/`false`/`null`،
     * و`null` تعني **مجهولةً** لا منفيّة.
     */
    private function model(AiProvider $p, string $name, array $caps = ['chat' => true],
                          ?int $ctx = null, ?float $in = null, ?float $out = null): AiModel
    {
        $capFacts = [];
        foreach ($caps as $k => $v) {
            $capFacts[$k] = ['v' => $v, 'src' => $v === null ? 'unknown' : 'litellm'];
        }

        return AiModel::create([
            'provider_id'        => $p->id,
            'litellm_model_name' => $name,
            'upstream_model'     => 'fake/' . $name,
            'display_name'       => $name,
            'enabled'            => true,
            'health'             => 'UNKNOWN',
            'capabilities'       => $capFacts,
            'limits'             => ['context_window' => $ctx === null
                ? ['v' => null, 'src' => 'unknown'] : ['v' => $ctx, 'src' => 'litellm']],
            'params'             => [],
            'pricing'            => [
                'input_per_1k'  => ['v' => $in,  'src' => $in === null ? 'unknown' : 'litellm'],
                'output_per_1k' => ['v' => $out, 'src' => $out === null ? 'unknown' : 'litellm'],
                'currency'      => 'USD',
                'unit'          => 'per_1k_tokens',
            ],
        ]);
    }

    private function profile(string $key): AiProfile
    {
        return AiProfile::query()->where('key', $key)->firstOrFail();
    }

    /** سلسلةٌ من ثلاثةِ نماذجَ على غرضٍ عامّ — المادّةُ التي تُحاكى عليها §٨ */
    private function threeLinkChain(?float $price = 0.001): AiProfile
    {
        $g = $this->profile('general');
        $p = $this->provider('chain');

        foreach (['alpha' => 8000, 'beta' => 32000, 'gamma' => 128000] as $n => $ctx) {
            AiProfiles::attach($g, $this->model($p, $n, ['chat' => true], $ctx, $price, $price));
        }

        return $g->fresh();
    }

    /**
     * **السلسلةُ نفسُها لكن على ثلاثةِ مزوّدين.**
     *
     * والفرقُ ليس تجميليّاً: `transient` يُحسَب على المزوّد، فثلاثةُ إخفاقاتٍ
     * على سلسلةٍ **مزوّدُها واحد** تُهدّئه فتسقط النماذجُ الثلاثةُ معاً — وهو
     * سلوكٌ صحيحٌ يكشف **سلسلةً بلا تكرارٍ حقيقيّ**، لا عطلٌ في الاحتياط.
     */
    private function threeProviderChain(?float $price = 0.001): AiProfile
    {
        $g = $this->profile('general');

        foreach (['alpha' => 8000, 'beta' => 32000, 'gamma' => 128000] as $n => $ctx) {
            AiProfiles::attach($g, $this->model($this->provider('p-' . $n), $n,
                ['chat' => true], $ctx, $price, $price));
        }

        return $g->fresh();
    }

    // ═══ البذرُ والأغراضُ السبعة ═══

    public function test_الأغراضُ_السبعةُ_تُزرَع_ولا_تُكرَّر(): void
    {
        $this->assertSame(7, AiProfile::count());
        $this->assertSame(0, AiProfiles::seed(), 'إعادةُ البذرِ ولدت أغراضاً ثانيةً');

        $keys = AiProfiles::all()->pluck('key')->all();
        $this->assertSame(['general', 'fast', 'reasoning', 'coding', 'vision', 'cheap', 'embedding'], $keys,
            'ترتيبُ الأغراضِ قرعةٌ — والترتيبُ يُطلَب صراحةً');
    }

    /** وإعادةُ البذرِ لا تدهس سلسلةً رتّبها المديرُ ولا وصفاً حرّره */
    public function test_إعادةُ_البذرِ_لا_تمسّ_ما_هيّأه_المدير(): void
    {
        $g = $this->threeLinkChain();
        $g->forceFill(['description' => 'وصفٌ حرّرتُه بيدي'])->save();

        AiProfiles::seed();

        $this->assertSame('وصفٌ حرّرتُه بيدي', (string) $g->fresh()->description);
        $this->assertCount(3, AiProfiles::chain($g->fresh()));
    }

    // ═══ ① الثابتُ الأهمّ — `unknown` لا يُرقَّى إلى «مدعوم» ═══

    /**
     * **ملفُّ `vision` لا يقبل نموذجاً قدرتُه مجهولة.**
     *
     * وهذا صلبُ §K: الخطرُ ليس أن يُرفَض النموذجُ بل أن **يُقبَل** بلا دليل،
     * فتُبنى ميزةٌ على قدرةٍ لم تُثبَت وتفشل عند أوّلِ مستخدم.
     */
    public function test_غرضٌ_يشترط_قدرةً_يرفض_المجهولَ_كما_يرفض_المنفيّ(): void
    {
        $v = $this->profile('vision');
        $p = $this->provider('vis');

        $unknown = $this->model($p, 'cap-unknown', ['chat' => true]);                 // لا ذكرَ للرؤية
        $denied  = $this->model($p, 'cap-denied',  ['chat' => true, 'vision' => false]);
        $proven  = $this->model($p, 'cap-proven',  ['chat' => true, 'vision' => true]);

        $r1 = AiProfiles::attach($v, $unknown);
        $this->assertFalse($r1['ok'], '**قدرةٌ مجهولةٌ رُقّيت إلى مدعومةٍ بالصمت**');
        $this->assertStringContainsString('غيرُ مثبتة', (string) $r1['error'],
            'الرفضُ لم يفرّق المجهولَ من المنفيّ — والفرقُ هو ما يُرشد إلى الفاحصِ E');

        $r2 = AiProfiles::attach($v, $denied);
        $this->assertFalse($r2['ok']);
        $this->assertStringContainsString('لا يدعم', (string) $r2['error']);

        $this->assertTrue(AiProfiles::attach($v, $proven)['ok']);
        $this->assertCount(1, AiProfiles::chain($v->fresh()));
    }

    /** والبابُ مفتوحٌ صراحةً: قدرةٌ أُثبتت بفاحصٍ أو تُوُوجزت يدويّاً تُقبَل */
    public function test_القدرةُ_المُثبَتةُ_باختبارٍ_أو_بتجاوزٍ_يدويٍّ_تفتح_الباب(): void
    {
        $v = $this->profile('vision');
        $p = $this->provider('vis2');

        $verified = $this->model($p, 'by-probe', ['chat' => true]);
        $verified->forceFill(['capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'],
            'vision' => ['v' => true, 'src' => 'verified']]])->save();

        $override = $this->model($p, 'by-hand', ['chat' => true]);
        $override->forceFill(['capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'],
            'vision' => ['v' => true, 'src' => 'hub_override']]])->save();

        $this->assertTrue(AiProfiles::attach($v, $verified->fresh())['ok']);
        $this->assertTrue(AiProfiles::attach($v, $override->fresh())['ok']);
    }

    /** **والسلسلةُ تُصفّى عند كلِّ قراءةٍ** — فتجاوزٌ أُلغي يُخرِج نموذجَه فوراً */
    public function test_سحبُ_التجاوزِ_يُخرِج_النموذجَ_من_السلسلةِ_حالاً(): void
    {
        $v = $this->profile('vision');
        $m = $this->model($this->provider('vis3'), 'later-revoked',
            ['chat' => true, 'vision' => true]);

        AiProfiles::attach($v, $m);
        $this->assertCount(1, AiProfiles::chain($v->fresh()));

        $m->forceFill(['capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'],
            'vision' => ['v' => null, 'src' => 'unknown']]])->save();

        $this->assertCount(0, AiProfiles::chain($v->fresh()),
            '**سلسلةٌ فُحصت عند الضمِّ وحدَه** — والقدرةُ تتغيّر بعدَه');

        $why = AiProfiles::excluded($v->fresh());
        $this->assertCount(1, $why);
        $this->assertStringContainsString('غيرُ مثبتة', (string) $why[0]['why'],
            'الحلقةُ أُخفيت بلا سبب — والشاشةُ تقول لماذا');
    }

    // ═══ ترتيبُ السلسلةِ وحدُّ طولِها ═══

    public function test_السلسلةُ_مرتّبةٌ_بالمرتبةِ_لا_بالإدراج(): void
    {
        $g = $this->threeLinkChain();

        $this->assertSame(['alpha', 'beta', 'gamma'],
            AiProfiles::chain($g)->pluck('litellm_model_name')->all());

        $ids = AiProfileModel::query()->where('profile_id', $g->id)->orderBy('rank')->pluck('id')->all();
        $this->assertTrue(AiProfiles::reorder($g, [$ids[2], $ids[0], $ids[1]])['ok']);

        $this->assertSame(['gamma', 'alpha', 'beta'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());

        // ولا مرتبتان متساويتان — القيدُ الفريدُ يحرسه، والقراءةُ تثبته
        $ranks = AiProfileModel::query()->where('profile_id', $g->id)->orderBy('rank')->pluck('rank')->all();
        $this->assertSame([0, 1, 2], array_map('intval', $ranks));
    }

    /** **الحارسُ ①** يُفرَض عند البناءِ لا عند التنفيذِ وحدَه */
    public function test_السلسلةُ_لا_تتجاوز_ثلاثةَ_نماذج(): void
    {
        $g = $this->threeLinkChain();
        $extra = $this->model($this->provider('four'), 'delta');

        $r = AiProfiles::attach($g, $extra);

        $this->assertFalse($r['ok'], '**الحارسُ ① مكسور**: سلسلةٌ رابعةٌ قُبلت');
        $this->assertStringContainsString('الحارسُ ①', (string) $r['error']);
    }

    public function test_الحذفُ_يرصّ_المراتبَ_فلا_فجوة(): void
    {
        $g = $this->threeLinkChain();
        $mid = AiProfileModel::query()->where('profile_id', $g->id)->where('rank', 1)->firstOrFail();

        AiProfiles::detach($mid);

        $ranks = AiProfileModel::query()->where('profile_id', $g->id)->orderBy('rank')->pluck('rank')->all();
        $this->assertSame([0, 1], array_map('intval', $ranks));
        $this->assertSame(['alpha', 'gamma'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());
    }

    /** ونموذجٌ لا يُضمّ مرّتين إلى سلسلةٍ واحدة */
    public function test_لا_نموذجَ_مرّتين_في_سلسلة(): void
    {
        $g = $this->profile('general');
        $m = $this->model($this->provider('dup'), 'once');

        $this->assertTrue(AiProfiles::attach($g, $m)['ok']);
        $this->assertFalse(AiProfiles::attach($g->fresh(), $m)['ok']);
    }

    // ═══ ما يُخرَج من السلسلةِ ولماذا ═══

    public function test_المُعطَّلُ_والمتعطّلُ_وبلا_اعتمادٍ_خارجَ_السلسلة(): void
    {
        $g = $this->threeLinkChain();
        $models = AiProfiles::chain($g)->keyBy('litellm_model_name');

        $models['alpha']->forceFill(['enabled' => false])->save();
        $models['beta']->forceFill(['health' => 'UNAVAILABLE'])->save();

        $this->assertSame(['gamma'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());

        // ومزوّدٌ مُعطَّلٌ يُفرِغ السلسلةَ كلَّها
        AiProvider::query()->update(['enabled' => false]);
        $this->assertCount(0, AiProfiles::chain($g->fresh()));
    }

    public function test_غرضٌ_مُعطَّلٌ_سلسلتُه_فارغة(): void
    {
        $g = $this->threeLinkChain();
        AiProfiles::setEnabled($g, false);

        $this->assertCount(0, AiProfiles::chain($g->fresh()));
    }

    // ═══ ③ محاكاةُ §٨ على سلسلةٍ حقيقيّة ═══

    /**
     * **كلُّ صفٍّ من §٨ يُحاكى على سلسلةٍ من ثلاثةِ نماذجَ** — ويُنظَر في
     * **مَن يُجيب بعدَه**، لا في مصفوفةِ قرارٍ مجرّدة.
     */
    public static function rows(): array
    {
        return [
            // [إشارة, الفعلُ المتوقَّع, النموذجُ بعدَه]
            'اعتمادٌ خاطئ'   => [['code' => 401], 'stop',     null],
            'ممنوع'          => [['code' => 403], 'stop',     null],
            'طلبٌ مشوّه'     => [['code' => 400, 'body' => 'invalid parameter'], 'stop', null],
            'سياسةُ محتوى'   => [['code' => 400, 'body' => 'content policy'],    'stop', null],
            'بثٌّ منقطع'     => [['code' => 500, 'streamed' => true],            'stop', null],
            'معدّلٌ متجاوَز' => [['code' => 429], 'retry',    'alpha'],
            'عابر'           => [['code' => 503], 'retry',    'alpha'],
            'نموذجٌ مفقود'   => [['code' => 404], 'fallback', 'beta'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function test_صفُّ_القرارِ_يُنتج_أثرَه_على_السلسلة(array $signal, string $action, ?string $after): void
    {
        $run = AiRouteRun::for($this->threeLinkChain());
        $this->assertSame('alpha', (string) $run->model()->litellm_model_name);

        $r = $run->fail($signal);

        $this->assertSame($action, $r['action'], 'الفعلُ انحرف عن §٨');
        $this->assertSame($after, $run->model()?->litellm_model_name,
            'من يُجيب بعد القرارِ ليس من ينصّ عليه §٨');
    }

    /** **الحارسُ ①** على الرحلة: قفزتان ثمّ توقّف */
    public function test_الرحلةُ_تتوقّف_عند_عمقِ_قفزتين(): void
    {
        $run = AiRouteRun::for($this->threeLinkChain());

        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);   // → beta
        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);   // → gamma
        $this->assertSame(AiRouting::MAX_DEPTH, $run->depth());

        $last = $run->fail(['code' => 404]);
        $this->assertSame('stop', $last['action'], '**الحارسُ ① مكسور**: قفزةٌ ثالثة');
        $this->assertStringContainsString('عمقَ الاحتياطِ الأقصى', (string) $run->why());
    }

    /** و`model_gone` **حقيقةٌ دائمةٌ تُكتَب** فلا يُعاد إلى النموذجِ أبداً */
    public function test_النموذجُ_المُزالُ_يُوسَم_متعطّلاً_فلا_يعود(): void
    {
        $g   = $this->threeLinkChain();
        $run = AiRouteRun::for($g);
        $run->fail(['code' => 404]);

        $this->assertSame('UNAVAILABLE',
            (string) AiModel::query()->where('litellm_model_name', 'alpha')->value('health'));

        $this->assertSame(['beta', 'gamma'],
            AiProfiles::chain($g->fresh())->pluck('litellm_model_name')->all());
    }

    /** والإعادةُ تستنفد ما يأذن به الجدولُ ثمّ تُحتاط — لا تُكرِّر بلا حدّ */
    public function test_العابرُ_يُعاد_مرّتين_ثمّ_يُحتاط(): void
    {
        $run = AiRouteRun::for($this->threeProviderChain());

        $this->assertSame(2, $run->fail(['code' => 503])['delay']);
        $this->assertSame(4, $run->fail(['code' => 503])['delay']);

        $third = $run->fail(['code' => 503]);
        $this->assertSame('fallback', $third['action']);
        $this->assertSame('beta', (string) $run->model()->litellm_model_name);
    }

    /**
     * **سلسلةٌ ثلاثيّةٌ على مزوّدٍ واحدٍ ليست ثلاثةَ احتياطات — بل واحد.**
     *
     * ثلاثةُ إخفاقاتٍ عابرةٍ تُهدّئ المزوّدَ (الحارس ③)، فيسقط النماذجُ الثلاثةُ
     * معاً. وهذا **صوابٌ لا عطل**: القفزُ إلى نموذجٍ ثانٍ على مزوّدٍ قرّرنا
     * لتوِّنا أنّه غيرُ سليمٍ إنفاقُ رحلةِ شبكةٍ على فشلٍ متوقَّع. **والرسالةُ
     * تقول ذلك حرفاً** بدل «لا احتياطَ» التي تُوهم نقصَ تهيئة.
     */
    public function test_سلسلةٌ_بمزوّدٍ_واحدٍ_تسقط_كلُّها_ويُقال_السببُ(): void
    {
        $run = AiRouteRun::for($this->threeLinkChain());

        $run->fail(['code' => 503]);
        $run->fail(['code' => 503]);
        $third = $run->fail(['code' => 503]);

        $this->assertSame('stop', $third['action']);
        $this->assertStringContainsString('تهدئة', (string) $run->why(),
            '**سببٌ مُضلِّل**: قيل «لا احتياطَ» والحقيقةُ أنّ المزوّدَ استُبعد');
        $this->assertStringContainsString('بلا تكرارٍ حقيقيّ', (string) $run->why());
    }

    // ═══ الاحتياطُ المشروط — تجاوزُ السياق ═══

    /**
     * **تجاوزُ السياقِ يقفز إلى نافذةٍ أوسعَ مُثبَتةٍ — ويتخطّى ما دونها.**
     *
     * وسلسلتُنا مرتّبةٌ ٨ آلاف ← ٣٢ ألفاً ← ١٢٨ ألفاً، فالقفزُ من الأوّلِ يقع
     * على الثاني. أمّا لو كان التالي أضيقَ لكانت القفزةُ إعادةَ الفشلِ نفسِه
     * بكلفةٍ ثانية.
     */
    public function test_تجاوزُ_السياقِ_يقفز_إلى_الأوسعِ_ويتخطّى_الأضيق(): void
    {
        $g = $this->profile('general');
        $p = $this->provider('ctx');

        AiProfiles::attach($g, $this->model($p, 'wide-start', ['chat' => true], 32000, 0.001, 0.001));
        AiProfiles::attach($g, $this->model($p, 'narrower',   ['chat' => true], 8000,  0.001, 0.001));
        AiProfiles::attach($g, $this->model($p, 'widest',     ['chat' => true], 200000, 0.001, 0.001));

        $run = AiRouteRun::for($g->fresh());
        $r   = $run->fail(['code' => 400, 'body' => 'maximum context length exceeded']);

        $this->assertSame('fallback', $r['action']);
        $this->assertSame('widest', (string) $run->model()->litellm_model_name,
            '**قُفِز إلى نافذةٍ أضيق** — فيُعاد الفشلُ نفسُه بكلفةٍ ثانية');
    }

    /** ونافذةٌ **مجهولةٌ** ليست أوسع — فلا قفزةَ إليها */
    public function test_نافذةٌ_مجهولةٌ_ليست_أوسعَ_فلا_يُقفَز_إليها(): void
    {
        $g = $this->profile('general');
        $p = $this->provider('ctx2');

        AiProfiles::attach($g, $this->model($p, 'known-8k',  ['chat' => true], 8000, 0.001, 0.001));
        AiProfiles::attach($g, $this->model($p, 'ctx-unknown', ['chat' => true], null, 0.001, 0.001));

        $run = AiRouteRun::for($g->fresh());
        $r   = $run->fail(['code' => 400, 'body' => 'context length exceeded']);

        $this->assertSame('stop', $r['action'],
            '**نافذةٌ مجهولةٌ عُدَّت أوسع** — والمجهولُ لا يمرّ عند التنفيذ');
    }

    // ═══ ② الحارسُ الثاني — ميزانيّةُ الطلب ═══

    public function test_السقفُ_يوقف_الرحلةَ_ولا_يُحتاط(): void
    {
        // كلُّ قفزةٍ ‎0.0015 دولار تقريباً (ألفُ داخلٍ وخمسُمئةِ خارجٍ بسعرِ 0.001/ألف)
        $g   = $this->threeLinkChain(0.001);
        $run = AiRouteRun::for($g, ['ceiling' => 0.002, 'in_tokens' => 1000, 'out_tokens' => 500]);

        $this->assertNotNull($run->model(), 'الأساسيُّ وحدَه تحت السقفِ فيجب أن تبدأ الرحلة');

        $r = $run->fail(['code' => 404]);

        $this->assertSame('stop', $r['action'], '**الحارسُ ② مكسور**: قُفِز فوق السقف');
        $this->assertStringContainsString('سقفَ الطلب', (string) $run->why());
    }

    /** **وكلفةٌ مجهولةٌ لا تمرّ تحت سقف** — فالسقفُ لا يُلتَفُّ حوله بنموذجٍ بلا سعر */
    public function test_قفزةٌ_مجهولةُ_الكلفةِ_لا_تمرّ_تحت_سقف(): void
    {
        $g = $this->profile('general');
        $p = $this->provider('pricey');

        AiProfiles::attach($g, $this->model($p, 'priced',  ['chat' => true], 8000, 0.0001, 0.0001));
        AiProfiles::attach($g, $this->model($p, 'unpriced', ['chat' => true], 8000, null, null));

        $run = AiRouteRun::for($g->fresh(), ['ceiling' => 1.0, 'in_tokens' => 1000, 'out_tokens' => 500]);
        $r   = $run->fail(['code' => 404]);

        $this->assertSame('stop', $r['action']);
        $this->assertStringContainsString('مجهول', (string) $run->why());
    }

    /** وبلا سقفٍ لا شيءَ يُقاس — فالمجهولُ يمرّ */
    public function test_بلا_سقفٍ_تمرّ_القفزةُ_مجهولةُ_الكلفة(): void
    {
        $g = $this->profile('general');
        $p = $this->provider('free');

        AiProfiles::attach($g, $this->model($p, 'first-free',  ['chat' => true], 8000, null, null));
        AiProfiles::attach($g, $this->model($p, 'second-free', ['chat' => true], 8000, null, null));

        $run = AiRouteRun::for($g->fresh());

        $this->assertSame('fallback', $run->fail(['code' => 404])['action']);
        $this->assertSame('second-free', (string) $run->model()->litellm_model_name);
    }

    // ═══ ③ الحارسُ الثالث — تهدئةُ المزوّد ═══

    /** **مزوّدٌ في تهدئةٍ يُتخطّى فوراً** — لا تُهدَر عليه مهلةٌ ولا رحلةُ شبكة */
    public function test_مزوّدٌ_في_تهدئةٍ_يُتخطّى_عند_فتحِ_الرحلة(): void
    {
        $g  = $this->profile('general');
        $p1 = $this->provider('cool1');
        $p2 = $this->provider('cool2');

        AiProfiles::attach($g, $this->model($p1, 'cooling', ['chat' => true], 8000, 0.001, 0.001));
        AiProfiles::attach($g, $this->model($p2, 'healthy', ['chat' => true], 8000, 0.001, 0.001));

        for ($i = 0; $i < AiRouting::COOLDOWN_AFTER; $i++) AiRouting::noteFailure((string) $p1->id);

        $run = AiRouteRun::for($g->fresh());

        $this->assertSame('healthy', (string) $run->model()->litellm_model_name,
            '**الحارسُ ③ مكسور**: بدأت الرحلةُ بمزوّدٍ مُستبعَدٍ سلفاً');
    }

    /** وثلاثةُ إخفاقاتٍ في رحلةٍ واحدةٍ تُهدّئ المزوّدَ فعلاً */
    public function test_الإخفاقاتُ_المتتاليةُ_تُهدّئ_المزوّد(): void
    {
        $g   = $this->threeLinkChain();
        $pid = (string) AiProfiles::chain($g)->first()->provider_id;
        $run = AiRouteRun::for($g);

        $run->fail(['code' => 503]);
        $run->fail(['code' => 503]);
        $this->assertFalse(AiRouting::inCooldown($pid));

        $run->fail(['code' => 503]);
        $this->assertTrue(AiRouting::inCooldown($pid), '**الحارسُ ③ لم يعمل** بعد ثلاثةِ إخفاقات');
    }

    /** ونجاحٌ يمسح السلسلةَ — فلا يُعاقَب مزوّدٌ تعافى */
    public function test_النجاحُ_يمسح_تهدئةَ_المزوّد(): void
    {
        $g   = $this->threeLinkChain();
        $pid = (string) AiProfiles::chain($g)->first()->provider_id;

        AiRouting::noteFailure($pid);
        AiRouting::noteFailure($pid);

        AiRouteRun::for($g)->succeed();

        AiRouting::noteFailure($pid);
        AiRouting::noteFailure($pid);
        $this->assertFalse(AiRouting::inCooldown($pid), 'عدّادُ الإخفاقِ لم يُمسَح بالنجاح');
    }

    // ═══ ④ الحارسُ الرابع — كلُّ قفزةٍ تُسجَّل ═══

    public function test_كلُّ_قفزةٍ_تُسجَّل_بالنموذجِ_والسببِ_والكلفة(): void
    {
        $run = AiRouteRun::for($this->threeLinkChain(0.001),
            ['in_tokens' => 1000, 'out_tokens' => 500]);

        $run->fail(['code' => 503]);
        $run->fail(['code' => 404]);

        $trail = $run->trail();
        $this->assertCount(2, $trail, '**الحارسُ ④ مكسور**: قفزةٌ لم تُسجَّل');

        foreach ($trail as $hop) {
            $this->assertArrayHasKey('model', $hop);
            $this->assertArrayHasKey('cause', $hop);
            $this->assertArrayHasKey('estimated_spend', $hop);
        }

        $this->assertSame('transient', (string) $trail[0]['cause']);
        $this->assertSame('model_gone', (string) $trail[1]['cause']);

        $logged = \App\Models\AuditEntry::query()
            ->where('action', 'قفزةُ توجيهٍ بعد إخفاق')->count();
        $this->assertGreaterThanOrEqual(2, $logged, 'القفزاتُ لم تبلغ سجلَّ التدقيق');
    }

    /** **والنجاحُ لا يُسجَّل شيئاً** — فالسجلُّ لأثرِ العطلِ لا لعدِّ الطلبات */
    public function test_النجاحُ_لا_يُنتج_أثراً(): void
    {
        $chain  = $this->threeLinkChain();          // بناءُ السلسلةِ نفسُه يُسجَّل — فاللقطةُ بعدَه
        $before = \App\Models\AuditEntry::query()->count();

        AiRouteRun::for($chain)->succeed();

        $this->assertSame($before, \App\Models\AuditEntry::query()->count());
    }

    // ═══ حدودُ الرحلة ═══

    public function test_سلسلةٌ_فارغةٌ_تُغلق_الرحلةَ_بسببٍ_مقروء(): void
    {
        $run = AiRouteRun::for($this->profile('general'));

        $this->assertTrue($run->closed());
        $this->assertNull($run->model());
        $this->assertStringContainsString('فارغة', (string) $run->why());
    }

    public function test_الرحلةُ_المُغلَقةُ_لا_تُستأنَف(): void
    {
        $run = AiRouteRun::for($this->threeLinkChain());
        $run->fail(['code' => 401]);

        $again = $run->fail(['code' => 503]);
        $this->assertSame('stop', $again['action']);
        $this->assertNull($run->model());
    }
}
