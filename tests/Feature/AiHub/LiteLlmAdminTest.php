<?php

namespace Tests\Feature\AiHub;

use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Gateway\LiteLlmAdmin;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W3** — عميلُ إدارةِ البوّابة.
 *
 * وأثقلُ ما هنا اختبارانِ يزرعان سرّاً حقيقيَّ الشكلِ ويثبتان أنّه **لا يخرج**:
 * لا في سجلٍّ، ولا في رسالةِ خطأ. وادّعاءُ «السرُّ لا يُسرَّب» بلا زرعٍ ادّعاءٌ
 * لا إثبات.
 */
class LiteLlmAdminTest extends TestCase
{
    /** سرٌّ اصطناعيٌّ طويلُ البصمةِ — فلا يقرع على معرّفٍ أو رمزٍ في الصفحة */
    private const PLANTED = 'sk-PLANTED9f3c7b1e55aa4477d2e6';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    // ═══ ① كلُّ نداءٍ يمرّ بالحارس ═══

    public function test_لا_نداءَ_قبل_التهيئة(): void
    {
        Settings::put('ai.gateway_url', '', 'test');
        Http::fake();

        $r = LiteLlmAdmin::models();
        $this->assertFalse($r['ok']);
        Http::assertNothingSent();
    }

    public function test_عنوانٌ_غيرُ_loopback_يُرَدّ_بلا_نداء(): void
    {
        Settings::put('ai.gateway_url', 'http://10.0.0.5:4000', 'test');
        Http::fake();

        $r = LiteLlmAdmin::models();
        $this->assertFalse($r['ok'], 'عنوانٌ داخليٌّ غيرُ معتمدٍ مرّ — والحارسُ الخماسيُّ يمنعه');
        Http::assertNothingSent();
    }

    public function test_كلُّ_نداءٍ_لا_يتّبع_تحويلاً(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        LiteLlmAdmin::models();

        Http::assertSent(function ($req, $res = null) {
            $opts = $req->toPsrRequest() ? true : true;
            return true;
        });
        // الإثباتُ المباشر: الخيارُ مضبوطٌ في المصدرِ الواحد
        $this->assertFalse(AiGateway::requestOptions()['allow_redirects'],
            'اتّباعُ التحويلِ يقود الطلبَ إلى هدفٍ لم يمرّ بالحارس');
    }

    public function test_المفتاحُ_يُرسَل_ترويسةً_لا_في_العنوان(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        LiteLlmAdmin::models();

        Http::assertSent(function ($req) {
            $this->assertStringContainsString('Bearer ', $req->header('Authorization')[0] ?? '');
            $this->assertStringNotContainsString('sk-', $req->url(), 'المفتاحُ تسرّب إلى العنوان');
            return true;
        });
    }

    // ═══ ② القراءةُ تعمل بالعقدِ الذي أثبته W0 ═══

    public function test_قراءةُ_النماذجِ_وبياناتِها(): void
    {
        Http::fake([
            '*/v1/models'  => Http::response(['data' => [['id' => 'hub-general']]], 200),
            '*/model/info' => Http::response(['data' => [['model_name' => 'hub-general']]], 200),
        ]);

        $m = LiteLlmAdmin::models();
        $this->assertTrue($m['ok']);
        $this->assertSame(200, $m['code']);
        $this->assertSame('hub-general', $m['data']['data'][0]['id']);

        $this->assertTrue(LiteLlmAdmin::modelInfo()['ok']);
    }

    public function test_سردُ_الاعتماداتِ_يعود_مُقنَّعاً_من_البوّابة(): void
    {
        Http::fake(['*/credentials' => Http::response([
            'success' => true,
            'credentials' => [[
                'credential_name'   => 'azure-prod',
                'credential_values' => ['api_key' => '****4477'],
            ]],
        ], 200)]);

        $r = LiteLlmAdmin::credentials();
        $this->assertTrue($r['ok']);
        $this->assertSame('****4477', $r['data']['credentials'][0]['credential_values']['api_key']);
    }

    // ═══ ③ الكتابةُ تمرّر السرَّ ولا تخزّنه ═══

    public function test_إنشاءُ_اعتمادٍ_يُرسِل_مرجعاً_وقيماً(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $r = LiteLlmAdmin::createCredential('azure-prod', ['api_key' => self::PLANTED], ['hub' => 'x']);
        $this->assertTrue($r['ok']);

        Http::assertSent(function ($req) {
            $d = $this->sentBody($req);
            $this->assertSame('azure-prod', $d['credential_name']);
            $this->assertSame(self::PLANTED, $d['credential_values']['api_key'],
                'السرُّ لم يصل البوّابةَ — والعميلُ ناقلٌ لا مُرشِّح');
            return true;
        });
    }

    public function test_إنشاءٌ_بلا_قيمٍ_يُرَدّ_بلا_نداء(): void
    {
        Http::fake();
        $this->assertFalse(LiteLlmAdmin::createCredential('x', [])['ok']);
        Http::assertNothingSent();
    }

    public function test_تسجيلُ_النموذجِ_يحمل_مرجعَ_الاعتمادِ_لا_مفتاحاً(): void
    {
        Http::fake(['*' => Http::response(['model_id' => 'abc'], 200)]);

        LiteLlmAdmin::createModel('hub-general', 'azure/deploy-x', 'azure-prod');

        Http::assertSent(function ($req) {
            $p = $this->sentBody($req)['litellm_params'];
            $this->assertSame('azure-prod', $p['litellm_credential_name']);
            $this->assertSame('azure/deploy-x', $p['model']);
            $this->assertArrayNotHasKey('api_key', $p, 'مفتاحٌ في معاملاتِ النموذج — والمرجعُ يُغني عنه');
            return true;
        });
    }

    // ═══ ④ الكلفةُ مُعلَنةٌ ومحجوبةٌ خلفَ إقرار ═══

    public function test_اختبارُ_الاعتمادِ_لا_يُنفَّذ_بلا_إقرارِ_كلفة(): void
    {
        Http::fake();
        $r = LiteLlmAdmin::testConnection(['model' => 'x'], 'chat');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('يُنفق رصيداً', $r['error']);
        Http::assertNothingSent();
    }

    public function test_اختبارُ_الاعتمادِ_يرفض_وضعاً_غيرَ_مُمرَّر(): void
    {
        Http::fake();
        $this->assertFalse(LiteLlmAdmin::testConnection(['model' => 'x'], '', true)['ok']);
        Http::assertNothingSent();
    }

    public function test_اختبارُ_الاعتمادِ_بالإقرارِ_يُمرِّر_الوضعَ_صراحةً(): void
    {
        Http::fake(['*' => Http::response(['status' => 'healthy'], 200)]);

        $this->assertTrue(LiteLlmAdmin::testConnection(['model' => 'x'], 'chat', true)['ok']);

        Http::assertSent(function ($req) {
            $this->assertSame('chat', $this->sentBody($req)['mode'],
                'الوضعُ لم يُمرَّر — والاستنتاجُ الآليُّ قد يقع على وضعٍ أغلى');
            return true;
        });
    }

    // ═══ ⑤ لا سرَّ يخرج — بزرعٍ لا بادّعاء ═══

    public function test_السرُّ_لا_يظهر_في_رسالةِ_خطأٍ_من_البوّابة(): void
    {
        Http::fake(['*' => Http::response(
            'upstream rejected key ' . self::PLANTED . ' for deployment', 400
        )]);

        $r = LiteLlmAdmin::createCredential('c', ['api_key' => self::PLANTED]);

        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString(self::PLANTED, (string) $r['error'],
            'سرٌّ مزروعٌ عاد في رسالةِ الخطأ — و`Redactor` هو الحاجزُ الأخير');
    }

    public function test_السرُّ_لا_يظهر_في_رسالةِ_استثناء(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('connect failed with token ' . self::PLANTED);
        });

        $r = LiteLlmAdmin::createCredential('c', ['api_key' => self::PLANTED]);
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString(self::PLANTED, (string) $r['error']);
    }

    public function test_السرُّ_لا_يبلغ_السجلّ(): void
    {
        $lines = [];
        Log::listen(function ($m) use (&$lines) { $lines[] = $m->message . json_encode($m->context); });

        Http::fake(['*' => Http::response(['success' => true], 200)]);
        LiteLlmAdmin::createCredential('azure-prod', ['api_key' => self::PLANTED]);

        $this->assertStringNotContainsString(self::PLANTED, implode(' ', $lines),
            'السرُّ بلغ السجلَّ — والسجلُّ يُقرأ ويُصدَّر ويُنسَخ');
    }

    // ═══ ⑥ الأخطاءُ تفرّق الحالات ═══

    public function test_رفضُ_المفتاحِ_يُقال_صراحةً(): void
    {
        Http::fake(['*' => Http::response('unauthorized', 401)]);
        $r = LiteLlmAdmin::models();
        $this->assertFalse($r['ok']);
        $this->assertSame(401, $r['code']);
        $this->assertStringContainsString('مفتاحُ الإدارةِ مرفوض', $r['error']);
    }

    public function test_الشكلُ_موحَّدٌ_في_كلِّ_الردود(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        foreach ([LiteLlmAdmin::models(), LiteLlmAdmin::credentials()] as $r) {
            $this->assertSame(LiteLlmAdmin::SHAPE, array_keys($r));
        }
    }

    // ═══ ⑦ الحارسُ المعماريّ يبقى قائماً ═══

    public function test_العميلُ_لا_يعرف_اسمَ_مزوّد(): void
    {
        $src = \Tests\Support\Source::read(\App\Support\Ai\Gateway\LiteLlmAdmin::class);
        foreach (\App\Support\Ai\Catalog\AiCatalog::keys() as $k) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b' . preg_quote($k, '/') . '\b/i', $src,
                "اسمُ مزوّدٍ `$k` في عميلِ الإدارة — والعقدُ عامٌّ لا خاصّ"
            );
        }
    }
}
