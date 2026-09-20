<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\AiGateway;
use App\Support\AiModels;
use App\Support\AiProbes;
use App\Support\AiProviders;
use App\Support\ConnectionProbe;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W6** — الفواحصُ الخمسة، بمُحاكٍ لا بمزوّدٍ حقيقيّ.
 *
 * والخطّةُ تُسمّي ما يُختبَر بعينِه:
 *   · **D وE يرفضان التنفيذَ بلا تأكيد**
 *   · **السقفُ مفروض**
 *   · **لا مخرجَ يُخزَّن**
 *   · A وB وC خضراءُ على خادمٍ وهميّ
 *
 * **ولا نداءَ مدفوعٌ حقيقيٌّ في هذا الملفّ ولا في الجلسة** — كلُّ ما هنا
 * `Http::fake`. والمفتاحُ المزروعُ نصٌّ اصطناعيٌّ لا يعمل عند أيِّ مزوّد.
 */
class AiProbesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-W6PROBE2f8c4a1d99ee7733b6c1';

    /** ما تُعلنه البوّابةُ الوهميّة — حالةٌ واحدةٌ يقرؤها مُرصِدٌ واحد */
    private array $entries = [];
    private ?array $completion = null;
    private int $completionStatus = 200;
    /** آخرُ حمولةِ توليدٍ أُرسلت — تُفحَص لا تُخمَّن */
    private ?array $lastBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        $this->completion = ['choices' => [['message' => ['content' => 'ok']]],
                             'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2, 'total_tokens' => 9]];

        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/chat/completions')) {
                $this->lastBody = (array) $req->data();

                return Http::response($this->completion, $this->completionStatus);
            }
            if (str_contains($url, '/model/info'))  return Http::response(['data' => $this->entries], 200);
            if (str_contains($url, '/v1/models'))   return Http::response(['data' => $this->entries], 200);
            if (str_contains($url, '/health/test_connection')) return Http::response(['status' => 'ok'], 200);

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function seedModel(): AiModel
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        $this->entries = [[
            'model_name'     => 'hub-alpha',
            'litellm_params' => ['model' => 'fake/upstream-a', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat'],
        ]];
        AiModels::import($p->fresh(), ['hub-alpha']);

        return AiModel::firstOrFail();
    }

    // ═══ الشكلُ الواحد ═══

    public function test_كلُّ_مستوًى_يعيد_شكلَ_الفحصِ_نفسَه(): void
    {
        $m = $this->seedModel();

        $rows = [
            AiProbes::a(),
            AiProbes::c($m),
            AiProbes::b($m->provider, 'chat'),          // بلا إقرار
            AiProbes::d($m),                            // بلا إقرار
            AiProbes::e($m, 'tools'),                   // بلا إقرار
        ];

        foreach ($rows as $i => $row) {
            $this->assertSame(ConnectionProbe::SHAPE, array_keys($row),
                "المستوى رقم {$i} يعيد شكلاً مختلفاً — والشاشاتُ تفترق");
        }
    }

    // ═══ ① A و C مجّانيّتان وتعملان ═══

    public function test_A_يُثبِت_البوّابةَ_بلا_إقرارٍ_ولا_كلفة(): void
    {
        $r = AiProbes::a();

        $this->assertTrue($r['up'], (string) $r['error']);
        $this->assertFalse(AiProbes::isPaid('A'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    public function test_C_يُثبِت_تسجيلَ_النموذجِ_بلا_كلفة(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::c($m);

        $this->assertTrue($r['up'], (string) $r['error']);
        $this->assertFalse(AiProbes::isPaid('C'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    public function test_C_يقول_الحقيقةَ_حين_لا_تعرف_البوّابةُ_النموذج(): void
    {
        $m = $this->seedModel();
        $this->entries = [];                 // البوّابةُ لم تعُد تُعلنه

        $r = AiProbes::c($m);

        $this->assertFalse($r['up']);
        $this->assertStringContainsString('لا تُعلن هذا النموذج', (string) $r['error']);
    }

    // ═══ ② المدفوعةُ ترفض بلا إقرار — الثابتُ الذي تُسمّيه الخطّة ═══

    public function test_المستوياتُ_المدفوعةُ_معلَنةٌ_ثلاثة(): void
    {
        $this->assertSame(['B', 'D', 'E'], AiProbes::PAID);
        foreach (['B', 'D', 'E'] as $lvl) $this->assertTrue(AiProbes::isPaid($lvl));
        foreach (['A', 'C'] as $lvl)      $this->assertFalse(AiProbes::isPaid($lvl));
    }

    public function test_D_يرفض_التنفيذَ_بلا_إقرارِ_كلفةٍ_ولا_يُرسِل_شيئاً(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::d($m);

        $this->assertNull($r['up'], '«لم يُجرَّب» ليست «فشل»');
        $this->assertStringContainsString('إقرارٌ صريحٌ بالكلفة', (string) $r['error']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    public function test_E_يرفض_التنفيذَ_بلا_إقرارِ_كلفةٍ_ولا_يُرسِل_شيئاً(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::e($m, 'tools');

        $this->assertNull($r['up']);
        $this->assertStringContainsString('إقرارٌ صريحٌ بالكلفة', (string) $r['error']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    public function test_B_يرفض_التنفيذَ_بلا_إقرارِ_كلفة(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::b($m->provider, 'chat');

        $this->assertNull($r['up']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/health/test_connection'));
    }

    /** وB **مدفوعةٌ** — أغلق W0 الفجوةَ G3 بعكسِ ما افترضته الخطّةُ أوّلاً */
    public function test_B_بالإقرارِ_تُنفَّذ_وتُمرِّر_الوضعَ_صراحةً(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::b($m->provider, 'chat', true);

        $this->assertTrue($r['up'], (string) $r['error']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/health/test_connection')
            && ($req->data()['mode'] ?? null) === 'chat');
    }

    // ═══ ③ السقفُ مفروضٌ لا مُستقبَل ═══

    public function test_السقفُ_مفروضٌ_ولا_يُتجاوَز(): void
    {
        $m = $this->seedModel();

        AiProbes::d($m, true);

        $this->assertNotNull($this->lastBody);
        $this->assertSame(AiProbes::MAX_OUTPUT_TOKENS, $this->lastBody['max_tokens'],
            'السقفُ لم يُفرَض على حمولةِ التوليد');
        $this->assertLessThanOrEqual(16, (int) $this->lastBody['max_tokens']);
    }

    /** ولا مُدخلَ مستخدمٍ يبلغ نموذجاً — المُحفِّزُ ثابتٌ من الشيفرة */
    public function test_المُحفِّزُ_ثابتٌ_من_الشيفرةِ_لا_من_مُدخَل(): void
    {
        $m = $this->seedModel();

        AiProbes::d($m, true);

        $this->assertSame(AiProbes::PROMPT,
            $this->lastBody['messages'][0]['content'] ?? null,
            'المُحفِّزُ ليس الثابتَ المكتوبَ في الشيفرة');
    }

    // ═══ ④ لا مخرجَ يُخزَّن ═══

    public function test_لا_مخرجَ_يُخزَّن_بل_طولٌ_وبصمةٌ_وعيّنة(): void
    {
        $m = $this->seedModel();
        $long = str_repeat('نصٌّ طويلٌ من النموذج ', 40);
        $this->completion = ['choices' => [['message' => ['content' => $long]]]];

        $r = AiProbes::d($m, true);

        $this->assertTrue($r['up'], (string) $r['error']);
        $this->assertSame(mb_strlen($long), $r['detail']['len']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{16}$/', (string) $r['detail']['fp']);
        $this->assertLessThanOrEqual(AiProbes::SAMPLE_CHARS, mb_strlen((string) $r['detail']['sample']));

        // ولا يُخزَّن النصُّ كاملاً في أيِّ صفٍّ — لا تدقيقٍ ولا نموذج
        $stored = json_encode(DB::table('audits')->get(), JSON_UNESCAPED_UNICODE)
            . json_encode(DB::table('ai_models')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($long, $stored,
            '**مخرجُ النموذجِ خُزِّن** — والعقدُ طولٌ وبصمةٌ وعيّنةٌ لا غير');
    }

    // ═══ ⑤ D هو ما يكتب شهادةَ التوليد ═══

    public function test_D_الناجحُ_يكتب_شهادةَ_التوليدِ_ببصمةِ_الإعداد(): void
    {
        $m = $this->seedModel();
        $this->assertFalse(AiGateway::generationVerified(), 'الشهادةُ موجودةٌ قبل أيِّ توليد');

        AiProbes::d($m, true);

        $this->assertTrue((bool) setting('ai.generation_ok', false));
        $this->assertSame(AiGateway::fingerprint(), (string) setting('ai.generation_fp', ''));
    }

    /** والبصمةُ تُبطِل الشهادةَ عند أيِّ تغييرٍ في الإعداد — لا شهادةَ على إعدادٍ زال */
    public function test_تغييرُ_إعدادِ_البوّابةِ_يُبطِل_شهادةَ_التوليد(): void
    {
        $m = $this->seedModel();
        AiProbes::d($m, true);
        $this->assertSame(AiGateway::fingerprint(), (string) setting('ai.generation_fp', ''));

        Settings::put('ai.gateway_key', 'sk-admin-test-key-CHANGED9999', 'test');

        $this->assertNotSame(AiGateway::fingerprint(), (string) setting('ai.generation_fp', ''),
            'البصمةُ لم تتغيّر بتغيّرِ المفتاح — فالشهادةُ تبقى على إعدادٍ زال');
        $this->assertFalse(AiGateway::generationVerified());
    }

    public function test_D_الفاشلُ_لا_يكتب_شهادة(): void
    {
        $m = $this->seedModel();
        $this->completionStatus = 500;

        $r = AiProbes::d($m, true);

        $this->assertFalse($r['up']);
        $this->assertFalse((bool) setting('ai.generation_ok', false),
            'فشلُ التوليدِ كتب شهادةَ نجاح');
    }

    // ═══ ⑥ E — الناجحُ وحدَه يكتب `verified` ═══

    public function test_E_الناجحُ_يكتب_verified_في_القدرة(): void
    {
        $m = $this->seedModel();
        $this->assertSame('unknown', $m->capabilities['tools']['src'], 'تهيئةٌ خاطئة');

        $this->completion = ['choices' => [['message' => [
            'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function',
                'function' => ['name' => 'ping', 'arguments' => '{}']]],
        ]]]];

        $r = AiProbes::e($m, 'tools', true);

        $this->assertTrue($r['up'], (string) $r['error']);
        $m->refresh();
        $this->assertTrue($m->capabilities['tools']['v']);
        $this->assertSame('verified', $m->capabilities['tools']['src'],
            'الفحصُ الناجحُ لم يكتب `verified` — وهو مصدرُها الوحيد');
    }

    /**
     * **والفاشلُ لا يكتب `false`.**
     *
     * الطلبُ قد يسقط لمهلةٍ أو حدِّ معدّلٍ أو عطلٍ عابر، فجعلُ ذلك «لا يدعم»
     * يُغلق باباً مفتوحاً بدليلٍ لا يخصّ القدرةَ أصلاً.
     */
    public function test_E_الفاشلُ_لا_يُثبِت_النفي(): void
    {
        $m = $this->seedModel();
        $this->completionStatus = 429;       // حدُّ معدّل — لا علاقةَ له بالقدرة

        $r = AiProbes::e($m, 'tools', true);

        $this->assertFalse($r['up']);
        $m->refresh();
        $this->assertNull($m->capabilities['tools']['v'],
            '**فشلُ طلبٍ صار نفياً لقدرة** — وبابٌ مفتوحٌ أُغلق بدليلٍ لا يخصّه');
        $this->assertSame('unknown', $m->capabilities['tools']['src']);
    }

    /** و٢٠٠ وحدَها لا تُثبِت القدرة — يُقرأ ما يُثبِتها في الردّ */
    public function test_E_لا_يُصدّق_ردّاً_ناجحاً_خالياً_من_الدليل(): void
    {
        $m = $this->seedModel();
        $this->completion = ['choices' => [['message' => ['content' => 'ok']]]];   // بلا tool_calls

        $r = AiProbes::e($m, 'tools', true);

        $this->assertFalse($r['up'], '٢٠٠ بلا استدعاءِ أداةٍ عُدَّت إثباتاً للأدوات');
        $m->refresh();
        $this->assertSame('unknown', $m->capabilities['tools']['src']);
    }

    public function test_E_يرفض_قدرةً_لا_فحصَ_مباشرَ_لها(): void
    {
        $m = $this->seedModel();

        $r = AiProbes::e($m, 'fine_tuning', true);

        $this->assertNull($r['up']);
        $this->assertStringContainsString('لا فحصَ مباشرٌ', (string) $r['error']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    // ═══ ⑦ الحارسُ والسرّ ═══

    public function test_لا_توليدَ_خارجَ_حارسِ_الصادر(): void
    {
        $m = $this->seedModel();
        Settings::put('ai.gateway_url', 'http://10.0.0.5:4000', 'test');

        $r = AiProbes::d($m, true);

        $this->assertNull($r['up'], 'عنوانٌ داخليٌّ غيرُ معتمدٍ مرّ إلى التوليد');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }

    public function test_السرُّ_لا_يظهر_في_نتيجةِ_فحصٍ_فاشل(): void
    {
        $m = $this->seedModel();
        $this->completionStatus = 401;
        $this->completion = ['error' => ['message' => 'invalid api key: ' . self::SECRET]];

        $r = AiProbes::d($m, true);

        $this->assertFalse($r['up']);
        $this->assertStringNotContainsString(self::SECRET,
            json_encode($r, JSON_UNESCAPED_UNICODE),
            '**تسريب**: مفتاحُ المزوّدِ خرج في نتيجةِ الفحص');
    }

    public function test_لا_فحصَ_على_مزوّدٍ_بلا_اعتماد(): void
    {
        $m = $this->seedModel();
        AiProviders::revoke($m->provider->fresh());

        $r = AiProbes::d($m->fresh(), true);

        $this->assertNull($r['up']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/chat/completions'));
    }
}
