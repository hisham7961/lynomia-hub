<?php

namespace Tests\Feature\AiHub;

use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W4** — دورةُ حياةِ الاعتماد، بمزوّدٍ **وهميٍّ محلّيّ**.
 *
 * **ولا مفتاحَ حقيقيٌّ في هذا الملفّ ولا في الشيفرة** (قرارُ المالك): السرُّ
 * المزروعُ أدناه نصٌّ اصطناعيٌّ لا يعمل عند أيِّ مزوّد، وغرضُه إثباتُ أنّه
 * **لا يخرج** — لا إلى صفٍّ، ولا إلى تدقيق، ولا إلى رسالةِ خطأ.
 *
 * وأثقلُ ما هنا `test_التدويرُ_لا_يُغيّر_اسمَ_الاعتماد`: لو وُلِّد اسمٌ جديدٌ
 * عند كلِّ تدويرٍ لانفصل كلُّ نموذجٍ مُسجَّلٍ عن اعتمادِه **صامتاً**.
 */
class AiProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** سرٌّ اصطناعيٌّ طويلٌ — ست عشرةَ خانةً فأكثرَ فلا يقرع على معرّفٍ في صفحة */
    private const PLANTED = 'sk-W4PLANTED3f7c1b9e55aa4477d2e6';
    private const ROTATED = 'sk-W4ROTATED8a2d4c6f11bb9933e7f5';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    /** مُدخَلُ Azure كاملاً — المزوّدُ المركّبُ الذي يُجبِر المخطَّطَ على التفريق */
    private function azureInput(string $secret = self::PLANTED): array
    {
        return [
            'api_key'     => $secret,
            'api_base'    => 'https://fake-local.invalid',
            'api_version' => '2024-10-21',
            'deployment'  => 'fake-deployment',
        ];
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response(['credential_name' => 'ok'], 200)]);
    }

    /**
     * بوّابةٌ وهميّةٌ **بمُوجِّهٍ واحد**.
     *
     * و`Http::fake` **يُراكِم** المُرصِدات ولا يستبدلها: نداءٌ ثانٍ بـ`'*'` لا
     * يُلغي الأوّل، فيبقى الأوّلُ هو من يجيب (ويُصفَّر السجلُّ وحدَه). فاختبارُ
     * «الحذفُ يفشل» كان يخضرّ كاذباً على بوّابةٍ تقول ٢٠٠. مُرصِدٌ واحدٌ يقرّر
     * بالطلبِ نفسِه — فلا يُبنى حكمٌ على مُرصِدٍ لا يعمل.
     */
    private function gateway(callable $responder): void
    {
        Http::fake(['*' => static fn ($request) => $responder($request)]);
    }

    // ═══ ① الإنشاء ═══

    public function test_مزوّدٌ_خارجَ_الكتالوجِ_يُرَدّ_بلا_نداء(): void
    {
        Http::fake();
        $r = AiProviders::add('provider_that_does_not_exist', 'س', ['api_key' => self::PLANTED]);

        $this->assertFalse($r['ok']);
        $this->assertNull($r['provider']);
        Http::assertNothingSent();
        $this->assertSame(0, AiProvider::count(), 'كُتب صفٌّ لمزوّدٍ لا يعرفه الكتالوج');
    }

    public function test_حقلٌ_إلزاميٌّ_ناقصٌ_يُرَدّ_بلا_نداء(): void
    {
        Http::fake();
        $in = $this->azureInput();
        unset($in['deployment']);

        $r = AiProviders::add('azure_openai', 'Azure وهميّ', $in);

        $this->assertFalse($r['ok'], 'مرّ مُدخَلٌ ناقصُ حقلٍ إلزاميّ');
        Http::assertNothingSent();
        $this->assertSame(0, AiProvider::count());
    }

    public function test_الإنشاءُ_يُرسِل_شقَّ_الاعتمادِ_وحدَه_إلى_البوّابة(): void
    {
        $this->fakeOk();
        $r = AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput());

        $this->assertTrue($r['ok'], (string) $r['error']);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), '/credentials')) return false;
            $values = $this->sentBody($req)['credential_values'] ?? [];

            // وجهةُ الحقلِ من المخطَّط: المفتاحُ والعنوانُ إلى الاعتماد
            $this->assertArrayHasKey('api_key', $values, 'المفتاحُ لم يصل البوّابة');
            $this->assertArrayHasKey('api_base', $values,
                'عنوانُ النهايةِ ليس سرّاً ووجهتُه الاعتماد — الوجهةُ ليست السرّيّة');
            // وإعدادُ Hub لا يُرسَل اعتماداً
            $this->assertArrayNotHasKey('api_version', $values);
            $this->assertArrayNotHasKey('deployment', $values);

            return true;
        });
    }

    public function test_لا_سرَّ_في_صفِّ_المزوّد(): void
    {
        $this->fakeOk();
        $r = AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput());
        $p = $r['provider'];

        // الصفُّ كلُّه خاماً — لا عبر النموذج، فلا يُخفي الصبُّ شيئاً
        $row = (array) DB::table('ai_providers')->where('id', $p->id)->first();
        $flat = json_encode($row, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::PLANTED, (string) $flat,
            '**تسريب**: سرُّ المزوّدِ استقرّ في صفِّ `ai_providers`');
        $this->assertStringContainsString('2024-10-21', (string) $flat,
            'الحقلُ غيرُ السرّيِّ لم يُحفَظ — الفرزُ أسقط ما يجب أن يبقى');
    }

    public function test_الإعدادُ_المحفوظُ_لا_يحمل_مفتاحاً_سرّيّاً(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput())['provider'];

        $config = (array) $p->config;
        $this->assertSame(['api_version', 'deployment'], array_keys($config),
            '`config` يحمل غيرَ ما وجهتُه `config`');
    }

    public function test_رفضُ_البوّابةِ_لا_يترك_صفّاً_يتيماً(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'bad request'], 400)]);
        $r = AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput());

        $this->assertFalse($r['ok']);
        $this->assertSame(0, AiProvider::count(),
            'صفٌّ يُعلِن اعتماداً رفضته البوّابة — شاشةٌ تقول «مُهيَّأ» وكلُّ طلبٍ يسقط');
    }

    public function test_الإنشاءُ_لا_يُشغّل(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];

        $this->assertFalse($p->enabled, 'الإنشاءُ شغّل المزوّدَ — والتشغيلُ قرارٌ ثانٍ صريح');
        $this->assertSame('configured', $p->credential_state);
    }

    public function test_اسمُ_الاعتمادِ_مشتقٌّ_لا_مُدخَل_ولا_يتصادم(): void
    {
        $this->fakeOk();
        $a = AiProviders::add('openai', 'أ', ['api_key' => self::PLANTED])['provider'];
        $b = AiProviders::add('openai', 'ب', ['api_key' => self::ROTATED])['provider'];

        $this->assertNotSame($a->credential_name, $b->credential_name,
            'مزوّدانِ باسمِ اعتمادٍ واحد — القيدُ الفريدُ كان سيسقط الثاني');
        $this->assertStringStartsWith('hub-', (string) $a->credential_name,
            'البادئةُ تُميّز ما أنشأه Hub — والتصالحُ لا يمحو ما ليس لنا');
    }

    // ═══ ② التدوير — الثابتُ الحاسم ═══

    public function test_التدويرُ_لا_يُغيّر_اسمَ_الاعتماد(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
        $nameBefore = (string) $p->credential_name;

        $r = AiProviders::rotate($p->fresh(), ['api_key' => self::ROTATED]);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame($nameBefore, (string) $r['provider']->fresh()->credential_name,
            '**انفصالٌ صامت**: تغيّر اسمُ الاعتمادِ بالتدوير، وكلُّ نموذجٍ مُسجَّلٍ '
            . 'يشير إلى الاسمِ القديمِ المحذوف');

        // والنداءُ ذهب إلى الاسمِ نفسِه لا إلى اسمٍ جديد
        Http::assertSent(fn ($req) => $req->method() === 'PATCH'
            && str_contains(rawurldecode($req->url()), $nameBefore));
    }

    public function test_التدويرُ_يُبطِل_تحقّقاً_سابقاً(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
        $p->forceFill(['credential_state' => 'verified'])->save();

        AiProviders::rotate($p->fresh(), ['api_key' => self::ROTATED]);

        $this->assertSame('configured', (string) $p->fresh()->credential_state,
            'سرٌّ جديدٌ ورث «مُتحقَّقاً» من سرٍّ قديم — شاشةٌ تشهد لما لم يُختبَر');
    }

    public function test_تدويرٌ_بلا_قيمٍ_يُرَدّ(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];

        Http::fake();   // **لتصفيرِ السجلّ وحدَه** — والمُرصِدُ الأوّلُ يبقى هو المُجيب
        $r = AiProviders::rotate($p->fresh(), []);

        $this->assertFalse($r['ok']);
        Http::assertNothingSent();
    }

    // ═══ ③ الإبطال والحذف ═══

    public function test_الإبطالُ_يحذف_عند_البوّابةِ_ويُطفئ(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        $r = AiProviders::revoke($p->fresh());

        $this->assertTrue($r['ok'], (string) $r['error']);
        $fresh = $p->fresh();
        $this->assertSame('missing', (string) $fresh->credential_state);
        $this->assertFalse($fresh->enabled, 'مزوّدٌ بلا اعتمادٍ بقي مُشغَّلاً');
        Http::assertSent(fn ($req) => $req->method() === 'DELETE'
            && str_contains($req->url(), '/credentials/'));
    }

    public function test_رفضُ_البوّابةِ_للحذفِ_لا_يُغيّر_الحالة(): void
    {
        $refuseDelete = false;
        $this->gateway(function ($req) use (&$refuseDelete) {
            return $refuseDelete && $req->method() === 'DELETE'
                ? Http::response(['detail' => 'boom'], 500)
                : Http::response(['credential_name' => 'ok'], 200);
        });
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];

        $refuseDelete = true;
        $r = AiProviders::revoke($p->fresh());

        $this->assertFalse($r['ok']);
        $this->assertSame('configured', (string) $p->fresh()->credential_state,
            'الصفُّ يقول «لا اعتماد» والسرُّ حيٌّ في الخزنة — كذبٌ أسوأُ من عطلٍ معلَن');
    }

    public function test_الحذفُ_يُبطِل_قبل_أن_يحذف(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];

        $r = AiProviders::remove($p->fresh());

        $this->assertTrue($r['ok'], (string) $r['error']);
        Http::assertSent(fn ($req) => $req->method() === 'DELETE');
        $this->assertNull(AiProvider::find($p->id), 'الصفُّ لم يُحذَف');
        $this->assertNotNull(AiProvider::withTrashed()->find($p->id), 'الحذفُ لم يكن ناعماً');
    }

    public function test_تعذّرُ_الإبطالِ_يمنع_الحذف(): void
    {
        $refuseDelete = false;
        $this->gateway(function ($req) use (&$refuseDelete) {
            return $refuseDelete && $req->method() === 'DELETE'
                ? Http::response(['detail' => 'boom'], 500)
                : Http::response(['credential_name' => 'ok'], 200);
        });
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];

        $refuseDelete = true;
        $r = AiProviders::remove($p->fresh());

        $this->assertFalse($r['ok']);
        $this->assertNotNull(AiProvider::find($p->id),
            '**تسريبٌ دائم**: حُذف الصفُّ فضاع اسمُ الاعتمادِ وبقي السرُّ في الخزنة');
    }

    // ═══ ④ التشغيل ═══

    public function test_لا_يُشغَّل_مزوّدٌ_بلا_اعتماد(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
        AiProviders::revoke($p->fresh());

        $r = AiProviders::setEnabled($p->fresh(), true);

        $this->assertFalse($r['ok']);
        $this->assertFalse($p->fresh()->enabled);
    }

    // ═══ ⑤ التدقيق — أثرٌ بلا سرّ ═══

    public function test_السرُّ_لا_يبلغ_سجلَّ_التدقيق(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput())['provider'];
        AiProviders::rotate($p->fresh(), $this->azureInput(self::ROTATED));
        AiProviders::revoke($p->fresh());

        $rows = DB::table('audits')->where('module', 'ai_providers')->get();
        $this->assertGreaterThanOrEqual(3, $rows->count(),
            'أفعالُ الحوكمةِ لم تُسجَّل صراحةً — والجدولُ بلا `Auditable` عمداً');

        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        foreach ([self::PLANTED, self::ROTATED] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $flat,
                '**تسريب**: سرُّ المزوّدِ في سجلِّ التدقيق');
        }
    }

    public function test_التدقيقُ_يحمل_بصمةً_تفرّق_سرّاً_عن_سرّ(): void
    {
        $this->fakeOk();
        $p = AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
        AiProviders::rotate($p->fresh(), ['api_key' => self::ROTATED]);

        $digests = DB::table('audits')->where('module', 'ai_providers')->get()
            ->map(fn ($r) => json_decode((string) $r->after, true)['secret_digest'] ?? null)
            ->filter()->values()->all();

        $this->assertCount(2, $digests, 'البصمةُ غائبةٌ عن أحدِ الأثرَين');
        $this->assertNotSame($digests[0], $digests[1],
            'بصمةٌ واحدةٌ لسرَّين مختلفَين — فلا تُجيب «أتغيّر المفتاحُ فعلاً؟»');
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{16}$/', (string) $digests[0]);
    }

    /** والأثرُ يُسمّي الحقولَ ليُقرَأ، ولا يحمل قيمَها */
    public function test_الأثرُ_يُسمّي_الحقولَ_لا_قيمَها(): void
    {
        $this->fakeOk();
        AiProviders::add('azure_openai', 'Azure وهميّ', $this->azureInput());

        $after = json_decode((string) DB::table('audits')->where('module', 'ai_providers')
            ->orderByDesc('id')->value('after'), true);

        $this->assertContains('api_key', (array) ($after['fields'] ?? []),
            'الأثرُ لا يقول أيَّ حقلٍ ضُبط — فلا يُقرَأ');
        $this->assertStringNotContainsString(self::PLANTED, json_encode($after, JSON_UNESCAPED_UNICODE));
    }
}
