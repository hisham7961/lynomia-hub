<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiModels;
use App\Support\Ai\Catalog\AiModelSources;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **اكتشافُ النماذجِ واختيارُها — بلا كتابةِ معرّفٍ بيد** (DISC).
 *
 * ── **العيبُ الذي وُلدت منه هذه الحزمة** ──
 *
 * كان «الاكتشافُ» يقرأ `GET /model/info` وحدَه — **سجلَّ البوّابةِ عمّا سُجّل
 * فيها**، لا نماذجَ المزوّد. فمزوّدٌ اعتمادُه جديدٌ يُكتشَف منه **صفر**، فيُدفَع
 * المديرُ إلى التسجيلِ اليدويّ: يبحث عن المعرّفِ خارجَ النظامِ ويكتبه بيدِه.
 * وهذا ما جرى حرفيّاً في أوّلِ قبولِ إنتاج.
 *
 * ── **وما يُحرَس هنا** ──
 *
 * ‏① مصدرانِ لا واحد، وكلُّ مرشَّحٍ يحمل مصدرَه.
 * ‏② **لا اتّصالَ مزوّدٍ ولا توليد** — قراءتانِ بكلفةِ صفر.
 * ‏③ ما يأتي من خارجٍ **يُطهَّر** قبل العرضِ والتخزين.
 * ‏④ التكرارُ يُقطَع حتميّاً، والاسمُ الداخليُّ يُولَّد حتميّاً.
 * ‏⑤ **لا اسمَ مزوّدٍ بعينِه** في أيِّ حكمٍ هنا — المفاتيحُ من الكتالوج.
 */
class AiModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-DISC4f1e7a9c22dd6655bb';

    /** المفتاحُ الذي يحمله كتالوجُ البوّابةِ في هذه الاختبارات */
    private string $providerKey = 'openai';

    /** كتالوجُ البوّابةِ المُحاكى — يُبدَّل داخلَ كلِّ اختبار */
    private array $costMap = [];

    /** مُدخَلاتُ `GET /model/info` المُحاكاة */
    private array $registry = [];

    /** الحمولاتُ التي بلغت `POST /model/new` */
    private array $created = [];

    /** رمزُ ردِّ كتالوجِ البوّابة */
    private int $costMapStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/public/litellm_model_cost_map')) {
                return Http::response($this->costMapStatus === 200 ? $this->costMap : ['detail' => 'boom'],
                    $this->costMapStatus);
            }
            if (str_contains($url, '/model/info')) {
                return Http::response(['data' => $this->registry], 200);
            }
            if (str_contains($url, '/model/new')) {
                $this->created[] = (array) json_decode((string) $req->body(), true);

                return Http::response(['model_id' => 'dep-' . count($this->created)], 200);
            }
            if (str_contains($url, '/chat/completions') || str_contains($url, '/health/test_connection')) {
                $this->fail('**اتّصالٌ مدفوعٌ من مسارِ اكتشاف**: ' . $url);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function provider(): AiProvider
    {
        $p = AiProviders::add($this->providerKey, 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    /** مُدخَلُ كتالوجٍ بالشكلِ الذي أثبته قياسُ الحزمةِ المثبّتة */
    private function catalogEntry(string $provider = 'openai', string $mode = 'chat', array $extra = []): array
    {
        return array_merge([
            'litellm_provider'   => $provider,
            'mode'               => $mode,
            'max_input_tokens'   => 128000,
            'max_output_tokens'  => 4096,
            'input_cost_per_token'  => 0.0000005,
            'output_cost_per_token' => 0.0000015,
            'supports_function_calling' => true,
            'supports_vision'    => true,
        ], $extra);
    }

    // ═══ ① المصادرُ تُقرَأ وتُعلَن ═══

    public function test_الاكتشافُ_يجمع_سجلَّ_البوّابةِ_وكتالوجَها(): void
    {
        $p = $this->provider();

        $this->registry = [[
            'model_name'     => 'hub-registered',
            'litellm_params' => ['model' => 'fam/registered-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ]];
        $this->costMap = [
            'fam/catalog-1' => $this->catalogEntry(),
            'fam/catalog-2' => $this->catalogEntry(),
        ];

        $found = AiModelSources::discover($p);

        $this->assertTrue($found['ok']);
        $this->assertCount(3, $found['candidates'], 'لم تُجمَع نماذجُ المصدرَين');

        $bySource = array_count_values(array_column($found['candidates'], 'source'));
        $this->assertSame(1, $bySource[AiModelSources::GATEWAY]);
        $this->assertSame(2, $bySource[AiModelSources::CATALOG]);
    }

    /** **وكلُّ مرشَّحٍ يحمل مصدرَه** — فلا يُقرأ ظنٌّ كأنّه نشرٌ قائم */
    public function test_كلُّ_مرشَّحٍ_يحمل_مصدرَه_ووضعَه(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/catalog-1' => $this->catalogEntry(mode: 'embedding')];

        $c = AiModelSources::discover($p)['candidates'][0];

        $this->assertSame(AiModelSources::CATALOG, $c['source']);
        $this->assertSame('embedding', $c['mode']);
        $this->assertSame('fam/catalog-1', $c['upstream_model']);
    }

    /** **مزوّدٌ لا يعرفه الكتالوجُ يُقال فيه الحقّ** — ولا تُخترَع له قائمة */
    public function test_مزوّدٌ_بلا_مُدخَلاتٍ_يُعيد_صفراً_لا_قائمةً_مخمّنة(): void
    {
        $p = $this->provider();
        $this->costMap = ['other/model' => $this->catalogEntry(provider: 'someone_else')];

        $found = AiModelSources::discover($p);

        $this->assertSame([], $found['candidates']);
        $this->assertTrue(AiModelSources::bothSourcesRead($found));
        $this->assertFalse(AiModelSources::yieldedCandidates($found));
    }

    /** **وسقوطُ الكتالوجِ عُطلٌ يُقال لا فراغٌ يُعرَض** */
    public function test_سقوطُ_الكتالوجِ_يُميَّز_عن_الفراغ(): void
    {
        $p = $this->provider();
        $this->costMapStatus = 500;

        $found = AiModelSources::discover($p);

        $this->assertFalse($found['sources'][AiModelSources::CATALOG]['ok']);
        $this->assertFalse(AiModelSources::bothSourcesRead($found),
            '**عُطلٌ يُقرَأ فراغاً**: المديرُ يُخبَر أنّ المزوّدَ بلا نماذجَ وهو لم يُسأل');
        $this->assertTrue($found['ok'], 'سقوطُ مصدرٍ واحدٍ أسقط الشاشةَ كلَّها');
    }

    // ═══ ② لا كلفةَ ولا سرّ ═══

    /** **الاكتشافُ قراءةٌ محضة** — ولا طلبَ يبلغ مزوّداً ولا يُولَّد حرف */
    public function test_الاكتشافُ_لا_يُنفق_ولا_يُولّد(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/catalog-1' => $this->catalogEntry()];

        AiModelSources::discover($p);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/health/test_connection'));
    }

    /** **ولا سرَّ في أيِّ طلبٍ من مسارِ الاكتشاف** */
    public function test_لا_سرَّ_يعبر_في_طلباتِ_الاكتشاف(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/catalog-1' => $this->catalogEntry()];

        AiModelSources::discover($p);

        Http::assertNotSent(fn ($req) => str_contains((string) $req->body(), self::SECRET)
            && ! str_contains($req->url(), '/credentials'));
    }

    // ═══ ③ ما يأتي من خارجٍ يُطهَّر ═══

    /** **معرّفٌ أطولُ من عمودِه لا يُعرَض** — فلا يُقَصّ عند الحفظِ صامتاً */
    public function test_معرّفٌ_أطولُ_من_العمودِ_يُسقَط(): void
    {
        $p = $this->provider();
        $this->costMap = [
            str_repeat('x', AiModelSources::MAX_ID_CHARS + 1) => $this->catalogEntry(),
            'fam/ok' => $this->catalogEntry(),
        ];

        $ids = array_column(AiModelSources::discover($p)['candidates'], 'upstream_model');

        $this->assertSame(['fam/ok'], $ids);
    }

    /** **ومحرفُ قلبِ اتّجاهٍ يجعل المعرّفَ يُقرَأ غيرَ ما هو** — فيُسقَط */
    public function test_محارفُ_التحكّمِ_والاتّجاهِ_تُسقِط_المرشَّح(): void
    {
        $p = $this->provider();
        $this->costMap = [
            "fam/ev\u{202E}li"   => $this->catalogEntry(),   // قلبُ اتّجاه
            "fam/nu\u{200B}ll"   => $this->catalogEntry(),   // صفرُ عرض
            "fam/ta\tb"          => $this->catalogEntry(),   // محرفُ تحكّم
            'fam/clean'          => $this->catalogEntry(),
        ];

        $ids = array_column(AiModelSources::discover($p)['candidates'], 'upstream_model');

        $this->assertSame(['fam/clean'], $ids,
            '**معرّفٌ مُضلِّلٌ عُرض**: محرفٌ خفيٌّ يجعل الصفَّ يُقرَأ غيرَ ما يُرسَل');
    }

    /** **ووضعٌ خارجَ المفرداتِ المعلومةِ يصير «غيرَ معروف» لا يُعرَض كما جاء** */
    public function test_وضعٌ_مجهولٌ_لا_يُعرَض_كما_جاء(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/x' => $this->catalogEntry(mode: '<b>وضعٌ مُختلَق</b>')];

        $this->assertNull(AiModelSources::discover($p)['candidates'][0]['mode']);
    }

    /** **وتاريخُ الطيِّ بصيغةٍ واحدةٍ أو `null`** — ولا نصَّ حرٌّ من الخارج */
    public function test_تاريخُ_الطيِّ_يُطهَّر(): void
    {
        $p = $this->provider();
        $this->costMap = [
            'fam/a' => $this->catalogEntry(extra: ['deprecation_date' => '2026-02-17']),
            'fam/b' => $this->catalogEntry(extra: ['deprecation_date' => 'قريباً جدّاً']),
        ];

        $by = collect(AiModelSources::discover($p)['candidates'])->keyBy('upstream_model');

        $this->assertSame('2026-02-17', $by['fam/a']['deprecated_on']);
        $this->assertNull($by['fam/b']['deprecated_on']);
    }

    /** **وردٌّ أكبرُ ممّا يُعقَل لا يُقرَأ** — فلا تُستنزَف الذاكرةُ بردٍّ شاذّ */
    public function test_ردٌّ_شاذُّ_الحجمِ_يُرَدّ(): void
    {
        $p = $this->provider();
        $big = [];
        for ($i = 0; $i <= AiModelSources::MAX_CATALOG_ENTRIES; $i++) {
            $big['m' . $i] = ['litellm_provider' => 'x'];
        }
        $this->costMap = $big;

        $found = AiModelSources::discover($p);

        $this->assertFalse($found['sources'][AiModelSources::CATALOG]['ok']);
    }

    /** **والقائمةُ الطويلةُ تُقَصّ ويُعلَن القصّ** — ولا يُقرأ الصمتُ «هذا كلُّ شيء» */
    public function test_القصُّ_يُعلَن_ولا_يقع_صامتاً(): void
    {
        $p = $this->provider();
        $map = [];
        for ($i = 0; $i < AiModelSources::MAX_CANDIDATES + 5; $i++) {
            $map['fam/m' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)] = $this->catalogEntry();
        }
        $this->costMap = $map;

        $found = AiModelSources::discover($p);

        $this->assertTrue($found['truncated']);
        $this->assertCount(AiModelSources::MAX_CANDIDATES, $found['candidates']);
    }

    // ═══ ④ التكرارُ والاسمُ الداخليُّ — حتميّانِ لا قرعة ═══

    /** **نموذجٌ في السجلِّ والكتالوجِ معاً يظهر مرّةً واحدةً**، والسجلُّ يغلب */
    public function test_التكرارُ_يُقطَع_والسجلُّ_يغلب_الكتالوج(): void
    {
        $p = $this->provider();
        $this->registry = [[
            'model_name'     => 'hub-dup',
            'litellm_params' => ['model' => 'fam/dup', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ]];
        $this->costMap = ['fam/dup' => $this->catalogEntry()];

        $c = AiModelSources::discover($p)['candidates'];

        $this->assertCount(1, $c);
        $this->assertSame(AiModelSources::GATEWAY, $c[0]['source']);
    }

    /** **والاسمُ الداخليُّ يُولَّد ولا يُخترَع** — وبالقيدِ نفسِه المفروضِ يدويّاً */
    public function test_الاسمُ_الداخليُّ_يُولَّد_حتميّاً_ومطابقاً_للقيد(): void
    {
        $p = $this->provider();

        $a = AiModels::mintAlias($p, 'fam/Model-X.1');
        $b = AiModels::mintAlias($p, 'fam/Model-X.1');

        $this->assertSame($a, $b, '**قرعةٌ في الاسم**: المُدخَلُ نفسُه أعطى اسمَين');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $a,
            'الاسمُ المولَّدُ يخالف القيدَ المفروضَ على التسجيلِ اليدويّ');
        $this->assertLessThanOrEqual(191, mb_strlen($a));
    }

    /** **والتصادمُ يُحَلّ ببصمةِ المُدخَلِ لا برقمٍ يتبع ترتيبَ الاستيراد** */
    public function test_تصادمُ_الأسماءِ_يُحَلّ_حتميّاً(): void
    {
        $p = $this->provider();
        $first = AiModels::mintAlias($p, 'fam/same');

        AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => $first,
            'upstream_model' => 'fam/other', 'display_name' => 'x',
            'capabilities' => [], 'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        $second = AiModels::mintAlias($p, 'fam/same');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, AiModels::mintAlias($p, 'fam/same'), 'الحلُّ غيرُ حتميّ');
    }

    // ═══ ⑤ التبنّي — اختيارٌ واحدٌ أو عدّة ═══

    public function test_تبنّي_نموذجٍ_واحدٍ_من_الكتالوج(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/one' => $this->catalogEntry()];

        $res = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/one']);

        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertCount(1, $res['models']);

        $m = $res['models'][0];
        $this->assertSame('fam/one', $m->upstream_model);
        $this->assertSame(AiModelSources::CATALOG, $m->discovery_source);
        $this->assertFalse((bool) $m->enabled, '**التبنّي فعّل نموذجاً** — والتفعيلُ قرارٌ ثانٍ');

        // **والمُسجَّلُ عند البوّابةِ يحمل المعرّفَ لا الاسمَ الداخليّ**
        $this->assertSame('fam/one', $this->created[0]['litellm_params']['model']);
        $this->assertSame($m->litellm_model_name, $this->created[0]['model_name']);
        $this->assertNotSame($m->litellm_model_name, $this->created[0]['litellm_params']['model']);
    }

    public function test_تبنّي_عدّةِ_نماذجَ_بضغطةٍ_واحدة(): void
    {
        $p = $this->provider();
        $this->costMap = [
            'fam/a' => $this->catalogEntry(),
            'fam/b' => $this->catalogEntry(),
            'fam/c' => $this->catalogEntry(),
        ];

        $res = AiModels::adopt($p, [
            AiModelSources::CATALOG . '|fam/a',
            AiModelSources::CATALOG . '|fam/c',
        ]);

        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame(['fam/a', 'fam/c'],
            collect($res['models'])->pluck('upstream_model')->sort()->values()->all());
    }

    /** **وإعادةُ التبنّي لا تُنتج صفّاً ثانياً** — الثباتُ على التكرار */
    public function test_إعادةُ_التبنّي_لا_تُكرِّر(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/idem' => $this->catalogEntry()];

        $first = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/idem']);
        $this->assertTrue($first['ok']);

        $again = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/idem']);

        $this->assertFalse($again['ok'], 'تُبنّي النموذجُ مرّتين');
        $this->assertSame(1, AiModel::query()->where('upstream_model', 'fam/idem')->count());
    }

    /** **ومعرّفٌ لم يُكتشَف لا يُتبنّى** — ولا يعبر من المتصفّحِ إلى البوّابة */
    public function test_معرّفٌ_لم_يُكتشَف_لا_يعبر_إلى_البوّابة(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/known' => $this->catalogEntry()];

        $res = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/INVENTED-BY-BROWSER']);

        $this->assertFalse($res['ok']);
        $this->assertSame([], $this->created,
            '**معرّفٌ مُختلَقٌ سُجّل عند البوّابة**: المُدخَلُ لم يُطابَق على ما اكتُشف');
    }

    /** **ومصدرٌ مُختلَقٌ لا يُقبَل** — المفتاحُ يُطابَق كاملاً لا بجزئِه */
    public function test_مصدرٌ_مُختلَقٌ_يُرَدّ(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/known' => $this->catalogEntry()];

        $res = AiModels::adopt($p, ['made_up_source|fam/known']);

        $this->assertFalse($res['ok']);
        $this->assertSame([], $this->created);
    }

    /** **وبلا اعتمادٍ لا تبنّي** — فلا نموذجَ يُسجَّل لمزوّدٍ بلا مفتاح */
    public function test_بلا_اعتمادٍ_لا_تبنّي(): void
    {
        $p = $this->provider();
        $p->forceFill(['credential_state' => 'missing'])->save();

        $res = AiModels::adopt($p->fresh(), [AiModelSources::CATALOG . '|fam/x']);

        $this->assertFalse($res['ok']);
        $this->assertSame([], $this->created);
    }

    // ═══ ⑥ المعرفةُ المنقولةُ صادقة ═══

    /** **وما لا يُعرَف يبقى غيرَ معروفٍ** — ولا يُقرَأ «غيرَ مدعوم» */
    public function test_قدرةٌ_غائبةٌ_تبقى_غيرَ_معروفة(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/bare' => ['litellm_provider' => 'openai', 'mode' => 'chat']];

        $c = AiModelSources::discover($p)['candidates'][0];

        // القدراتُ المشتقّةُ من **الوضعِ** حصريّةٌ عمداً: مُدخَلٌ وضعُه محادثةٌ
        // ليس نموذجَ تضمينٍ حقّاً. فالحارسُ على قدراتِ **الأعلام** وحدَها.
        $modeDerived = \App\Support\Ai\Catalog\AiModelFacts::MODE_MAP;

        foreach ((array) $c['capabilities'] as $k => $fact) {
            if (in_array($k, $modeDerived, true)) continue;
            $v = is_array($fact) ? ($fact['v'] ?? null) : $fact;
            $this->assertNotFalse($v,
                "**نفيٌ بلا دليل**: القدرةُ {$k} أُعلنت غيرَ مدعومةٍ والكتالوجُ صامتٌ عنها");
        }
    }

    /** **والحدودُ والتسعيرُ يُنقلان إلى الصفِّ عند التبنّي** */
    public function test_المعرفةُ_تُنقَل_إلى_الصفِّ_عند_التبنّي(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/rich' => $this->catalogEntry()];

        $m = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/rich'])['models'][0];

        $this->assertNotSame([], (array) $m->limits, 'الحدودُ ضاعت في الطريق');
        $this->assertNotSame([], (array) $m->pricing, 'التسعيرُ ضاع في الطريق');
        $this->assertSame(AiModelSources::CATALOG, (string) $m->pricing_source);
    }

    // ═══ ⑥٫٥ دلالاتُ الإتاحة — «معروفٌ» ليس «متاحاً لحسابِك» ═══

    /**
     * **جذعُ العائلةِ يُميَّز عن النموذج.**
     *
     * ── **العيبُ الذي وُلد منه هذا الحارس** ──
     *
     * عرض الكتالوجُ مُدخَلاً يبدو معرّفَ نموذجٍ كاملاً، فتبنّاه المالكُ بضغطة.
     * وحين بلغ الطلبُ المزوّدَ ردّ **٤٠٤: لا نموذجَ بهذا الاسم**. والسببُ أنّ
     * ذلك المُدخَلَ **مفتاحُ تسعيرٍ لعائلة** لا معرّفُ نموذج: الإصدارُ المثبَّتُ
     * نفسُه يُجرّد المعرّفاتِ الحقيقيّةَ إليه بحذفِ ثلاثةِ مقاطعَ من آخرِها.
     */
    public function test_جذعُ_العائلةِ_يُميَّز_عن_معرّفٍ_كامل(): void
    {
        // جذعٌ — بلا مقاطعِ الحساب
        $this->assertTrue(AiModelSources::isFamilyStem('ft:some-base-2024-07-18'));

        // معرّفاتٌ كاملةٌ — تحمل مقاطعَ الحساب
        $this->assertFalse(AiModelSources::isFamilyStem('ft:some-base-2024-07-18:org:suffix:job123'));
        // **ومقطعٌ فارغٌ في الوسطِ لا يجعله جذعاً** — وهي الحالةُ التي يُخطئ
        // فيها تعبيرُ المصدرِ نفسُه، فلم يُنسَخ حرفاً
        $this->assertFalse(AiModelSources::isFamilyStem('ft:some-base-2024-07-18:org::job123'));

        // ومعرّفٌ عاديٌّ لا علاقةَ له بالعائلات
        $this->assertFalse(AiModelSources::isFamilyStem('fam/plain-model'));
    }

    /** **ودلالةُ الإتاحةِ تُحمَل مع المرشَّحِ لا تُستنتَج في الشاشة** */
    public function test_كلُّ_مرشَّحٍ_يحمل_دلالةَ_إتاحتِه(): void
    {
        $p = $this->provider();

        $this->registry = [[
            'model_name'     => 'hub-registered',
            'litellm_params' => ['model' => 'fam/registered', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ]];
        $this->costMap = [
            'fam/plain'          => $this->catalogEntry(),
            'ft:some-base-2024'  => $this->catalogEntry(),
        ];

        $by = collect(AiModelSources::discover($p)['candidates'])->keyBy('upstream_model');

        $this->assertSame(AiModelSources::AVAIL_REGISTERED, $by['fam/registered']['availability']);
        $this->assertSame(AiModelSources::AVAIL_CATALOG,    $by['fam/plain']['availability']);
        $this->assertSame(AiModelSources::AVAIL_ACCOUNT,    $by['ft:some-base-2024']['availability'],
            '**ادّعاءُ إتاحة**: جذعُ عائلةٍ عُرض كأنّه نموذجٌ متاحٌ لحسابِك');
    }

    /** **ومُدخَلُ الكتالوجِ لا يُقدَّم إثباتَ وصولٍ أبداً** */
    public function test_مُدخَلُ_الكتالوجِ_ليس_إثباتَ_وصول(): void
    {
        $p = $this->provider();
        $this->costMap = ['fam/plain' => $this->catalogEntry()];

        $c = AiModelSources::discover($p)['candidates'][0];

        $this->assertNotSame(AiModelSources::AVAIL_REGISTERED, $c['availability'],
            '**ترقيةٌ بالصمت**: مُدخَلُ كتالوجٍ عُدَّ نشراً مُسجَّلاً');
    }

    /**
     * **والجذعُ لا يُتبنّى بضغطة** — ولو اختاره المتصفّحُ صراحةً.
     *
     * فالحارسُ في الخدمةِ لا في الشاشةِ وحدَها: إخفاءُ مربّعِ الاختيارِ يمنع
     * الزلّةَ، ولا يمنع طلباً مصنوعاً بيد.
     */
    public function test_جذعُ_العائلةِ_لا_يُتبنّى_ولا_يُسجَّل_عند_البوّابة(): void
    {
        $p = $this->provider();
        $this->costMap = ['ft:some-base-2024' => $this->catalogEntry()];

        $res = AiModels::adopt($p, [AiModelSources::CATALOG . '|ft:some-base-2024']);

        $this->assertFalse($res['ok'], '**تُبنّي جذعُ عائلة**: صفٌّ يبدو سليماً ويردّ المزوّدُ ٤٠٤');
        $this->assertSame([], $this->created,
            '**سُجّل جذعٌ عند البوّابة**: تهيئةٌ حيّةٌ لا تُنادى أبداً');
        $this->assertStringContainsString('جذع', (string) $res['error']);
    }

    /** **والمعرّفُ الكاملُ من العائلةِ نفسِها يُتبنّى بلا حَرَج** */
    public function test_معرّفٌ_كاملٌ_من_العائلةِ_يُتبنّى(): void
    {
        $p = $this->provider();
        $this->costMap = ['ft:some-base-2024:org::job123' => $this->catalogEntry()];

        $res = AiModels::adopt($p, [AiModelSources::CATALOG . '|ft:some-base-2024:org::job123']);

        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('ft:some-base-2024:org::job123', $this->created[0]['litellm_params']['model']);
    }

    // ═══ ⑦ لا تبعيّةَ لمزوّدٍ بعينِه ═══

    /**
     * **الآليّةُ واحدةٌ لأيِّ مزوّد.**
     *
     * والاختبارُ يُعيد نفسَه على مفتاحٍ آخرَ من الكتالوجِ نفسِه: لو كان في
     * الشيفرةِ فرعٌ لمزوّدٍ بعينِه لَسقط هنا.
     */
    public function test_الآليّةُ_نفسُها_تعمل_لمزوّدٍ_آخر(): void
    {
        $this->providerKey = 'mistral';
        $p = $this->provider();
        $this->costMap = ['other-fam/model-1' => $this->catalogEntry(provider: 'mistral')];

        $found = AiModelSources::discover($p);

        $this->assertCount(1, $found['candidates']);
        $this->assertSame('other-fam/model-1', $found['candidates'][0]['upstream_model']);
    }
}
