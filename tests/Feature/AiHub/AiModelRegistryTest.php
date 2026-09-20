<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\AiModelFacts;
use App\Support\AiModels;
use App\Support\AiProviders;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W5** — سجلُّ النماذجِ والاكتشاف، بكتالوجٍ **وهميٍّ محلّيّ**.
 *
 * والخطّةُ تُسمّي ثلاثةَ ثوابتَ تُختبَر بعينِها:
 *   ① **الاكتشافُ لا يُفعِّل شيئاً**
 *   ② `unknown` **لا يصير** `false`
 *   ③ التجاوزُ اليدويُّ يُوسَم `hub_override`
 *
 * وأثقلُها الثاني: تحويلُ «لا نعرف» إلى «لا يدعم» يُغلق باباً مفتوحاً فيظنّ
 * المديرُ أنّ النموذجَ لا يقدر وهو يقدر — **خسارةٌ لا تُرى ولا تُصلَح**.
 */
class AiModelRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-W5PLANTED4d9a2c7e66bb5588f1a3';

    /** ما تُعلنه البوّابةُ الوهميّةُ الآن — يتغيّر بالحالةِ لا بمُرصِدٍ ثانٍ */
    private array $entries = [];

    /** أيَقبل `/model/new` ويُضيف المُسجَّلَ إلى ما تُعلنه البوّابة؟ */
    private bool $acceptRegister = true;

    /**
     * **مُرصِدٌ واحدٌ يُنصَب مرّةً** — و`Http::fake` **يُراكِم** المُرصِدات ولا
     * يستبدلها، فنداءٌ ثانٍ بـ`'*'` لا يُلغي الأوّل بل يبقى الأوّلُ هو المُجيب.
     * (سقط هذا الملفُّ أوّلَ تشغيلٍ بهذا بعينِه — والدرسُ مكتوبٌ منذ W4.)
     */
    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/model/info')) {
                return Http::response(['data' => $this->entries], 200);
            }

            if (str_contains($url, '/model/new')) {
                if (! $this->acceptRegister) return Http::response(['detail' => 'refused'], 400);
                $body = (array) $req->data();
                $lp   = (array) ($body['litellm_params'] ?? []);
                $this->entries[] = $this->entry((string) ($body['model_name'] ?? ''),
                    (string) ($lp['litellm_credential_name'] ?? ''), ['mode' => 'chat'],
                    (string) ($lp['model'] ?? ''));

                return Http::response(['model_id' => 'x'], 200);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    /** مدخلُ `/model/info` كما تعيده البوّابة — **بلا اسمِ نموذجٍ حقيقيّ** */
    private function entry(string $name, string $credential, array $info = [], string $upstream = 'fake/upstream-a'): array
    {
        return [
            'model_name'     => $name,
            'litellm_params' => ['model' => $upstream, 'litellm_credential_name' => $credential],
            'model_info'     => $info,
        ];
    }

    private function seedProvider(bool $enabled = true): AiProvider
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        if ($enabled) AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    /** ما تُعلنه البوّابةُ من الآن — تبديلُ حالةٍ لا مُرصِدٌ جديد */
    private function gatewayWith(AiProvider $p, array $entries): void
    {
        $this->entries = $entries;
    }

    // ═══ ① الاكتشافُ لا يكتب ولا يُفعِّل ═══

    public function test_الاكتشافُ_لا_يكتب_صفّاً(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);

        $r = AiModels::discover($p);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertCount(1, $r['candidates']);
        $this->assertSame(0, AiModel::count(), '**الاكتشافُ كتب صفّاً** — وهو قراءةٌ محضة');
    }

    public function test_الاستيرادُ_يُنتج_نموذجاً_مُعطَّلاً(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);

        $r = AiModels::import($p, ['hub-alpha']);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $m = AiModel::firstOrFail();
        $this->assertFalse($m->enabled,
            '**الاستيرادُ فعّل النموذجَ** — وحسابٌ يتيح ستّين نموذجاً كان سيُفعّلها كلَّها');
        $this->assertSame('UNKNOWN', (string) $m->health);
    }

    public function test_ما_ليس_لهذا_المزوّدِ_لا_يُنسَب_إليه(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [
            $this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat']),
            $this->entry('foreign-model', 'cred-of-someone-else', ['mode' => 'chat']),
        ]);

        $r = AiModels::discover($p);

        $this->assertCount(1, $r['candidates'], 'نموذجُ اعتمادٍ آخرَ نُسب إلى مزوّدِنا');
        $this->assertSame(1, $r['unowned'], 'الشاشةُ ستدّعي أنّ البوّابةَ فارغةٌ وفيها نماذج');
    }

    public function test_الاستيرادُ_لا_يُكرّر_المستورَد(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);

        AiModels::import($p, ['hub-alpha']);
        $again = AiModels::import($p, ['hub-alpha']);

        $this->assertFalse($again['ok']);
        $this->assertSame(1, AiModel::count(), 'تكرّر الصفُّ — والقيدُ الفريدُ كان سيسقط');
    }

    // ═══ ② `unknown` لا يصير `false` — الثابتُ الأثقل ═══

    public function test_العَلَمُ_الغائبُ_يبقى_مجهولاً_لا_غيرَ_مدعوم(): void
    {
        // حمولةٌ لا تذكر `supports_vision` ولا `supports_function_calling` إطلاقاً
        $caps = AiModelFacts::capabilities(['mode' => 'chat']);

        foreach (['vision', 'tools', 'reasoning', 'structured_output', 'audio_in'] as $cap) {
            $this->assertNull($caps[$cap]['v'],
                "**تحوَّل المجهولُ إلى غيرِ مدعوم** في `{$cap}` — بابٌ مفتوحٌ أُغلق بلا دليل");
            $this->assertSame('unknown', $caps[$cap]['src'],
                "قيمةٌ بلا دليلٍ وُسمت بمصدرٍ في `{$cap}`");
        }
    }

    public function test_العَلَمُ_الحاضرُ_بقيمةِ_false_يبقى_false_بمصدرِه(): void
    {
        $caps = AiModelFacts::capabilities(['mode' => 'chat', 'supports_vision' => false]);

        $this->assertFalse($caps['vision']['v'], 'دليلٌ صريحٌ بالنفيِ ضاع');
        $this->assertSame('litellm', $caps['vision']['src']);
    }

    public function test_الوضعُ_الغائبُ_لا_يُنتج_نفياً_للقدراتِ_الحصريّة(): void
    {
        $caps = AiModelFacts::capabilities(['supports_vision' => true]);   // لا `mode`

        foreach (['chat', 'embeddings', 'image_generation', 'speech_to_text'] as $cap) {
            $this->assertNull($caps[$cap]['v'], "وضعٌ مجهولٌ أنتج نفياً في `{$cap}`");
        }
        $this->assertTrue($caps['vision']['v'], 'العَلَمُ الصريحُ ضاع مع غيابِ الوضع');
    }

    /** والوضعُ الحاضرُ **يُنتج نفياً صادقاً** — نشرٌ وضعُه محادثةٌ لا يخدم التضمين */
    public function test_الوضعُ_الحاضرُ_يُنتج_نفياً_صادقاً(): void
    {
        $caps = AiModelFacts::capabilities(['mode' => 'embedding']);

        $this->assertTrue($caps['embeddings']['v']);
        $this->assertSame('litellm', $caps['embeddings']['src']);
        $this->assertFalse($caps['chat']['v'], 'نشرُ تضمينٍ أُعلن قادراً على المحادثة');
    }

    public function test_الحدودُ_تحفظ_الرقمَ_لا_تُحوّله_إلى_نعم(): void
    {
        $lim = AiModelFacts::limits(['max_input_tokens' => 128000, 'max_output_tokens' => 16384]);

        $this->assertSame(128000, $lim['context_window']['v'],
            '**تحوّل الرقمُ إلى منطقيّ** — `Tri::fact` تفعل ذلك ولا تصلح للأرقام');
        $this->assertSame(16384, $lim['max_output_tokens']['v']);
        $this->assertNull($lim['max_images']['v']);
        $this->assertSame('unknown', $lim['rate_limits']['src'],
            'حدودُ المعدّلِ تبقى مجهولةً عن قصد — ورقمٌ مخمَّنٌ يجعل المديرَ يخطّط على كذبة');
    }

    public function test_الوسائطُ_بلا_قائمةٍ_تبقى_مجهولةً_وبقائمةٍ_تُنفى_صادقةً(): void
    {
        $none = AiModelFacts::params([]);
        $this->assertNull($none['temperature']['supported'], 'غيابُ القائمةِ أنتج نفياً');
        $this->assertSame('unknown', $none['temperature']['src']);

        $some = AiModelFacts::params(['supported_openai_params' => ['temperature', 'max_tokens']]);
        $this->assertTrue($some['temperature']['supported']);
        $this->assertFalse($some['seed']['supported'], 'تعدادٌ صريحٌ ولم يُنتج نفياً لما خرج عنه');
        $this->assertSame('litellm', $some['seed']['src']);
    }

    public function test_السعرُ_يُحوَّل_للألفِ_ولا_يُعرَض_بلا_وحدة(): void
    {
        $pr = AiModelFacts::pricing(['input_cost_per_token' => 0.0000025, 'output_cost_per_token' => 0.00001]);

        $this->assertEqualsWithDelta(0.0025, $pr['input_per_1k']['v'], 1e-9);
        $this->assertEqualsWithDelta(0.01, $pr['output_per_1k']['v'], 1e-9);
        $this->assertSame('USD', $pr['currency'], 'رقمٌ بلا عملةٍ كذبةٌ تنتظر');
        $this->assertSame('per_1k_tokens', $pr['unit']);
        $this->assertNotEmpty($pr['fetched_at']);
        $this->assertNull($pr['image_per_unit']['v'], 'سعرٌ غائبٌ صار صفراً');
    }

    // ═══ ③ التجاوزُ اليدويُّ يعلو ولا يُدهَس ═══

    public function test_التجاوزُ_اليدويُّ_يُوسَم_ويَنجو_من_التحديث(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name,
            ['mode' => 'chat', 'supports_vision' => false])]);

        AiModels::import($p, ['hub-alpha']);
        $m = AiModel::firstOrFail();
        $this->assertFalse($m->capabilities['vision']['v']);

        // المديرُ يعرف أنّ النموذجَ يرى فعلاً — وقرارُه يعلو
        AiModels::override($m, 'capabilities', 'vision', true);
        $m->refresh();
        $this->assertTrue($m->capabilities['vision']['v']);
        $this->assertSame('hub_override', $m->capabilities['vision']['src']);

        // والبوّابةُ تُعيد `false` مرّةً أخرى — ولا تدهس القرار
        AiModels::refresh($p);
        $m->refresh();
        $this->assertTrue($m->capabilities['vision']['v'],
            '**دُهس قرارُ المدير** — والتجاوزُ يعلو الكلَّ ولا يُدهَس بتحديث');
        $this->assertSame('hub_override', $m->capabilities['vision']['src']);
    }

    /**
     * **وما أثبته اختبارٌ حقيقيٌّ يُحفَظ أيضاً** — لا لأنّه قرارُ إنسان، بل لأنّ
     * إعادةَ اشتقاقِه تُنفق رصيداً (المستوى E في W6). فدهسُه بتحديثٍ مجّانيٍّ
     * يجعل التحديثَ مُكلِفاً في صمت.
     */
    public function test_ما_أثبته_اختبارٌ_حقيقيٌّ_لا_يُدهَس_بتحديثٍ_من_الخريطة(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);

        $m = AiModel::firstOrFail();
        $caps = $m->capabilities;
        $caps['tools'] = ['v' => true, 'src' => 'verified'];
        $m->forceFill(['capabilities' => $caps])->save();

        AiModels::refresh($p);
        $m->refresh();

        $this->assertTrue($m->capabilities['tools']['v']);
        $this->assertSame('verified', $m->capabilities['tools']['src'],
            'أُعيد المُثبَتُ إلى مجهولٍ — وإعادةُ إثباتِه تُنفق رصيداً');
    }

    public function test_التحديثُ_لا_يمسّ_قرارَ_الإنسان(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);

        $m = AiModel::firstOrFail();
        AiModels::configure($m, ['display_name' => 'اسمٌ اختاره المدير', 'priority' => 7, 'tags' => 'سريع,رخيص']);
        AiModels::setEnabled($m->fresh(), true);

        AiModels::refresh($p);
        $m->refresh();

        $this->assertSame('اسمٌ اختاره المدير', (string) $m->display_name, 'المزامنةُ دهست اسماً اختاره إنسان');
        $this->assertSame(7, $m->priority);
        $this->assertSame(['سريع', 'رخيص'], $m->tags);
        $this->assertTrue($m->enabled, 'المزامنةُ أطفأت نموذجاً فعّله إنسان');
    }

    // ═══ ④ التفعيلُ مشروط ═══

    public function test_لا_يُفعَّل_نموذجٌ_على_مزوّدٍ_مُطفأ(): void
    {
        $p = $this->seedProvider(enabled: false);
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);

        $r = AiModels::setEnabled(AiModel::firstOrFail(), true);

        $this->assertFalse($r['ok']);
        $this->assertFalse(AiModel::firstOrFail()->enabled);
    }

    public function test_لا_يُفعَّل_نموذجٌ_على_مزوّدٍ_بلا_اعتماد(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);
        AiProviders::revoke($p->fresh());

        $r = AiModels::setEnabled(AiModel::firstOrFail()->fresh(), true);

        $this->assertFalse($r['ok']);
    }

    // ═══ ⑤ التسجيلُ اليدويُّ — لمزوّدٍ لا اكتشافَ له ═══

    public function test_التسجيلُ_اليدويُّ_يُسجّل_ثمّ_يستورد_مُعطَّلاً(): void
    {
        $p = $this->seedProvider();
        // البوّابةُ تبدأ فارغةً — فلا اكتشافَ يسبق التسجيل
        $this->assertCount(0, AiModels::discover($p)['candidates']);

        $r = AiModels::register($p, 'hub-manual', 'fake/manual-upstream');

        $this->assertTrue($r['ok'], (string) $r['error']);
        $m = AiModel::firstOrFail();
        $this->assertSame('fake/manual-upstream', (string) $m->upstream_model);
        $this->assertSame('hub-manual', (string) $m->litellm_model_name);
        $this->assertFalse($m->enabled, 'التسجيلُ اليدويُّ فعّل النموذج');
    }

    public function test_رفضُ_البوّابةِ_للتسجيلِ_لا_يترك_صفّاً(): void
    {
        $p = $this->seedProvider();
        $this->acceptRegister = false;

        $r = AiModels::register($p, 'hub-manual', 'fake/manual-upstream');

        $this->assertFalse($r['ok']);
        $this->assertSame(0, AiModel::count(), 'صفٌّ لنموذجٍ رفضته البوّابة');
    }

    public function test_التسجيلُ_يُرَدّ_بلا_اعتماد(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, []);
        AiProviders::revoke($p->fresh());

        $r = AiModels::register($p->fresh(), 'hub-manual', 'fake/manual-upstream');

        $this->assertFalse($r['ok']);
        $this->assertSame(0, AiModel::count());
    }

    // ═══ ⑥ الأثرُ والسرّ ═══

    public function test_الأثرُ_يقول_إنّ_الاستيرادَ_لم_يُفعّل(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);

        $after = json_decode((string) DB::table('audits')->where('module', 'ai_models')
            ->orderByDesc('id')->value('after'), true);

        $this->assertContains('hub-alpha', (array) ($after['imported'] ?? []));
        $this->assertFalse($after['enabled'] ?? true, 'الأثرُ لا يشهد أنّ الاستيرادَ لم يُفعّل');
    }

    public function test_لا_سرَّ_في_صفِّ_النموذجِ_ولا_في_أثرِه(): void
    {
        $p = $this->seedProvider();
        $this->gatewayWith($p, [$this->entry('hub-alpha', $p->credential_name, ['mode' => 'chat'])]);
        AiModels::import($p, ['hub-alpha']);

        $rows = json_encode(DB::table('ai_models')->get(), JSON_UNESCAPED_UNICODE)
            . json_encode(DB::table('audits')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRET, $rows,
            '**تسريب**: سرُّ المزوّدِ بلغ صفَّ النموذجِ أو أثرَه');
    }

    // ═══ ⑦ لا كتالوجَ نماذجَ في الشيفرة ═══

    /**
     * **لا كتالوجَ نماذجَ في Hub — يُثبَت بالسلوكِ لا بتعبيرٍ نمطيّ.**
     *
     * حمولةٌ **فارغةٌ تماماً** من البوّابة: لو كان في Hub معرفةٌ مخبوءةٌ عن نموذجٍ
     * لخرجت هنا قيمةٌ من عنده. فخروجُ **كلِّ** الحقائقِ مجهولةً هو الدليلُ —
     * وهو دليلٌ لا يُخدَع بإعادةِ صياغةِ سطرٍ كما يُخدَع مسحُ النصّ.
     *
     * وقد أثبت W0 لماذا لا تُنسَخ معرفةُ البوّابةِ إلى PHP: ٧٦ حقلَ تسعيرٍ
     * وأكثرُ من ثلاثين عَلَمَ قدرةٍ تتغيّر مع كلِّ ترقية — فأيُّ نسخةٍ تتقادم
     * قبل أن تُدفَع.
     */
    public function test_حمولةٌ_فارغةٌ_لا_تُنتج_معرفةً_من_عندِ_Hub(): void
    {
        foreach (AiModelFacts::capabilities([]) as $k => $f) {
            $this->assertNull($f['v'], "قدرةُ `{$k}` خرجت بقيمةٍ والبوّابةُ لم تقل شيئاً");
            $this->assertSame('unknown', $f['src']);
        }

        foreach (AiModelFacts::limits([]) as $k => $f) {
            $this->assertNull($f['v'], "حدُّ `{$k}` خرج بقيمةٍ والبوّابةُ لم تقل شيئاً");
        }

        foreach (AiModelFacts::params([]) as $k => $f) {
            $this->assertNull($f['supported'], "وسيطُ `{$k}` خرج بحكمٍ والبوّابةُ لم تقل شيئاً");
        }

        $pricing = AiModelFacts::pricing([]);
        foreach ($pricing as $k => $f) {
            if (in_array($k, ['currency', 'unit', 'fetched_at'], true)) continue;   // وحدةٌ لا سعر
            $this->assertNull($f['v'], "سعرُ `{$k}` خرج برقمٍ والبوّابةُ لم تقل شيئاً");
        }
    }

    /** ولا اسمَ نموذجٍ مُعدَّدٍ في الشيفرة — الأسماءُ تأتي من البوّابةِ أو من المدير */
    public function test_لا_قائمةَ_أسماءِ_نماذجَ_في_الشيفرة(): void
    {
        $src = file_get_contents(app_path('Support/AiModelFacts.php'))
            . file_get_contents(app_path('Support/AiModels.php'));

        // أسماءُ النماذجِ تحمل رقمَ إصدارٍ أو نقطةً بين كلمتَين — نمطٌ لا يظهر في مفاتيحِ العقد
        $this->assertSame(0, preg_match_all('/[\'"][a-z]+-[0-9]+(\.[0-9]+)?[a-z-]*[\'"]/i', $src),
            'اسمُ نموذجٍ مُعدَّدٌ في الشيفرة — والمعرفةُ تُقرأ من البوّابةِ وقتَ الحاجة');
    }
}
