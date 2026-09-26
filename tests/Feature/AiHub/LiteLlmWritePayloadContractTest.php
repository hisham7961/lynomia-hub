<?php

namespace Tests\Feature\AiHub;

use App\Support\Ai\Gateway\LiteLlmAdmin;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **شكلُ الحمولاتِ الكاتبةِ — كما تفرضه نماذجُ الإصدارِ المثبَّت** (422-FIX).
 *
 * ── **العطلُ الذي كشفه القبولُ الحقيقيّ** ──
 *
 * سجّل المالكُ نموذجاً من الواجهة، فعاد:
 *
 * ```
 * HTTP 422 · {"detail":[{"type":"model_type","loc":["body","model_info"],
 *   "msg":"Input should be a valid dictionary or instance of ModelInfo",
 *   "input":[],"ctx":{"class_name":"ModelInfo"}}]}
 * ```
 *
 * **والسببُ لغةٌ لا بوّابة.** في PHP لا فرقَ بين «قائمةٍ فارغة» و«خريطةٍ
 * فارغة» — كلتاهما `[]`. وفي JSON فرقٌ حاسم: `[]` مصفوفة و`{}` كائن. فحقلٌ
 * وصفيٌّ تُرك فارغاً خرج مصفوفةً، والعقدُ يطلب كائناً.
 *
 * ── **والحقلُ ليس واحداً** ──
 *
 * قياسُ نماذجِ الإصدارِ المثبَّتِ (`Deployment` · `CreateCredentialItem` ·
 * `CredentialItem`) أثبت أنّ **ثلاثَ حمولاتٍ** تسقط بالشكلِ القديم لا حمولةً
 * واحدة. ونجَت اثنتان منها في الإنتاج **بالمصادفة** — لأنّ مُستدعيَهما
 * يمرّران وصفاً غيرَ فارغ. فالقاعدةُ تُفرَض هنا لا تُترَك للصدفة:
 *
 * > **كلُّ خريطةٍ حرّةٍ في جسمِ طلبٍ تخرج كائناً — ولو كانت فارغة.**
 *
 * **والتأكيدُ على المتنِ الخامّ لا على `data()`**: الأخيرةُ تفكّ `{}` إلى
 * `[]` في PHP، فتُصدّق ما لا تصدّقه البوّابة — وهي المصيدةُ نفسُها التي
 * أخفت العطلَ أوّلَ مرّة.
 */
class LiteLlmWritePayloadContractTest extends TestCase
{
    use RefreshDatabase;

    /** المتنُ الخامُّ لآخرِ طلبٍ خرج — لا `data()` المفكوكة */
    private ?string $lastBody = null;

    private ?string $lastUrl = null;

    /** رمزُ الردِّ الذي يعيده المُرصِد — يُبدَّل داخلَ الاختبارِ لا بمُرصِدٍ ثانٍ */
    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $this->lastUrl  = $req->url();
            $this->lastBody = (string) $req->body();

            return Http::response($this->status === 422
                ? ['detail' => [['type' => 'model_type', 'loc' => ['body', 'model_info']]]]
                : ['ok' => true], $this->status);
        }]);
    }

    /** لقطةُ العقدِ المقيسةُ من نماذجِ جسمِ الإصدارِ المثبَّت */
    public const FIXTURE = 'Fixtures/ai/litellm-1.101.0-write-contract.json';

    /**
     * **العقدُ يُقرَأ من قياسٍ لا من ذاكرة.**
     *
     * اللقطةُ في `tests/Fixtures/ai/` مولَّدةٌ بتحميلِ نماذجِ الجسمِ نفسِها
     * (`Deployment` · `CreateCredentialItem` · `CredentialItem`) من حزمةِ
     * `litellm==1.101.0` المثبّتةِ وسؤالِها عن حقولِها وأحكامِها. فالاختبارُ
     * هنا لا يعيد كتابةَ العقدِ بل **يُقاس عليه**.
     */
    private function contract(): array
    {
        $path = base_path('tests/' . self::FIXTURE);
        $this->assertFileExists($path, 'لقطةُ العقدِ مفقودة');

        return (array) json_decode((string) file_get_contents($path), true);
    }

    /** الحقولُ الكائنيّةُ لمسارٍ بعينِه — من اللقطةِ لا من الذاكرة */
    private function objectFieldsFor(string $route): array
    {
        return (array) (($this->contract()['routes'][$route] ?? [])['object_fields'] ?? []);
    }

    /** يؤكّد أنّ المفتاحَ خرج كائناً في **المتنِ الخامّ** */
    private function assertJsonObjectAt(string $key): void
    {
        $body = (string) $this->lastBody;

        $this->assertStringContainsString('"' . $key . '":', $body,
            "الحقلُ {$key} غائبٌ عن الحمولة");
        $this->assertDoesNotMatchRegularExpression('/"' . preg_quote($key, '/') . '"\s*:\s*\[/', $body,
            "**مصفوفةٌ حيث يطلب العقدُ كائناً**: {$key} خرج `[` — وهذا نصُّ الأربعمئةِ واثنتين وعشرين");
        $this->assertMatchesRegularExpression('/"' . preg_quote($key, '/') . '"\s*:\s*\{/', $body,
            "الحقلُ {$key} لم يخرج كائناً");
    }

    // ═══ ① `/model/new` — الحمولةُ التي سقطت فعلاً ═══

    /** **الوصفُ الفارغُ يخرج `{}` لا `[]`** — وهذا العطلُ حرفاً بحرف */
    public function test_تسجيلُ_نموذجٍ_بلا_وصفٍ_يُرسِل_كائناً_فارغاً(): void
    {
        LiteLlmAdmin::createModel('hub-general', 'some/upstream-id', 'hub-cred-abc');

        $this->assertStringContainsString('/model/new', (string) $this->lastUrl);
        $this->assertJsonObjectAt('model_info');
        $this->assertJsonObjectAt('litellm_params');
    }

    /** **وبوصفٍ حاضرٍ يبقى كائناً** — فالإصلاحُ لا يكسر الحالةَ العاملة */
    public function test_تسجيلُ_نموذجٍ_بوصفٍ_يبقى_كائناً(): void
    {
        LiteLlmAdmin::createModel('hub-general', 'some/upstream-id', 'hub-cred-abc',
            ['timeout' => 30], ['base_model' => 'x']);

        $this->assertJsonObjectAt('model_info');
        $this->assertJsonObjectAt('litellm_params');

        $body = json_decode((string) $this->lastBody, true);
        $this->assertSame('x', $body['model_info']['base_model']);
        $this->assertSame(30, $body['litellm_params']['timeout']);
    }

    /** **والحقولُ الثلاثةُ الإلزاميّةُ حاضرةٌ بأنواعِها** */
    public function test_حمولةُ_النموذجِ_مطابقةٌ_للعقدِ_حقلاً_حقلاً(): void
    {
        LiteLlmAdmin::createModel('hub-general', 'some/upstream-id', 'hub-cred-abc');

        $body = json_decode((string) $this->lastBody, true);

        $this->assertSame(['model_name', 'litellm_params', 'model_info'], array_keys($body),
            'انحرفت مفاتيحُ الحمولةِ عمّا يقرؤه العقد');
        $this->assertIsString($body['model_name']);
        // `LiteLLM_Params.model` هو الحقلُ الإلزاميُّ الوحيدُ داخلَ الوسائط
        $this->assertSame('some/upstream-id', $body['litellm_params']['model']);
        $this->assertSame('hub-cred-abc', $body['litellm_params']['litellm_credential_name']);
        // **ولا مفتاحَ عارٍ** — المرجعُ وحدَه يعبر
        $this->assertArrayNotHasKey('api_key', $body['litellm_params']);
    }

    /** **واسمُ Hub لا يُرسَل اسمَ نموذجٍ عند المزوّد** */
    public function test_اسمُ_Hub_لا_يحلّ_محلَّ_اسمِ_المزوّد(): void
    {
        LiteLlmAdmin::createModel('hub-general', 'some/upstream-id', 'hub-cred-abc');

        $body = json_decode((string) $this->lastBody, true);

        $this->assertSame('hub-general', $body['model_name']);
        $this->assertNotSame('hub-general', $body['litellm_params']['model']);
    }

    // ═══ ② الاعتماد — الحقلُ نفسُه في مسارَين آخرَين ═══

    /** **`credential_info` الفارغُ كائنٌ أيضاً** — `dict` إلزاميٌّ في العقد */
    public function test_إنشاءُ_اعتمادٍ_بلا_وصفٍ_يُرسِل_كائناً_فارغاً(): void
    {
        LiteLlmAdmin::createCredential('hub-cred-abc', ['api_key' => 'PLACEHOLDER-NOT-A-SECRET']);

        $this->assertJsonObjectAt('credential_info');
        $this->assertJsonObjectAt('credential_values');
    }

    /** **والتدويرُ كذلك** — وهو المسارُ الذي كان سينكسر صامتاً */
    public function test_تدويرُ_اعتمادٍ_بلا_وصفٍ_يُرسِل_كائناً_فارغاً(): void
    {
        LiteLlmAdmin::updateCredential('hub-cred-abc', ['api_key' => 'PLACEHOLDER-NOT-A-SECRET']);

        $this->assertJsonObjectAt('credential_info');
        $this->assertJsonObjectAt('credential_values');
    }

    // ═══ ③ مسحٌ شاملٌ — لا حقلَ كائنيٍّ يخرج مصفوفةً من أيِّ كاتب ═══

    /**
     * **ولا يُترَك المسحُ لمن يتذكّر.** كلُّ كاتبٍ في العميلِ يُشغَّل بأدنى
     * وسائطِه، ثمّ يُفحَص متنُه الخامُّ حقلاً حقلاً **بالحقولِ المقيسة**.
     */
    public function test_كلُّ_كاتبٍ_يُصدِر_خرائطَه_كائناتٍ(): void
    {
        $writers = [
            'POST /model/new'           => fn () => LiteLlmAdmin::createModel('hub-a', 'up/a', 'cred-a'),
            'POST /credentials'         => fn () => LiteLlmAdmin::createCredential('cred-a', ['api_key' => 'PLACEHOLDER']),
            'PATCH /credentials/{name}' => fn () => LiteLlmAdmin::updateCredential('cred-a', ['api_key' => 'PLACEHOLDER']),
            'POST /model/delete'        => fn () => LiteLlmAdmin::deleteModel('dep-1'),
            'POST /model/block'         => fn () => LiteLlmAdmin::blockModel('dep-1'),
            'POST /model/unblock'       => fn () => LiteLlmAdmin::unblockModel('dep-1'),
        ];

        // **ولا كاتبَ خارجَ المسح**: كلُّ مسارِ كتابةٍ في اللقطةِ له مُشغِّلٌ هنا
        $this->assertSame(array_keys($this->contract()['routes']), array_keys($writers),
            '**مسارُ كتابةٍ بلا حارس**: اللقطةُ تعرف مساراً لا يمسحه هذا الاختبار');

        foreach ($writers as $route => $run) {
            $this->lastBody = null;
            $run();

            $body = json_decode((string) $this->lastBody, true);
            $this->assertIsArray($body, "الكاتبُ {$route} لم يُرسل جسماً");

            foreach ($this->objectFieldsFor($route) as $field) {
                if (array_key_exists($field, $body)) {
                    $this->assertJsonObjectAt($field);
                }
            }
        }
    }

    /**
     * **والحقولُ الإلزاميّةُ حاضرةٌ في كلِّ كاتب.**
     *
     * فلا يكفي أن يكون النوعُ صحيحاً — حقلٌ إلزاميٌّ غائبٌ يردّ ٤٢٢ أيضاً،
     * برسالةٍ أخرى وسببٍ آخرَ يُطارَد يوماً كاملاً.
     */
    public function test_كلُّ_كاتبٍ_يحمل_حقولَ_عقدِه_الإلزاميّة(): void
    {
        $writers = [
            'POST /model/new'           => fn () => LiteLlmAdmin::createModel('hub-a', 'up/a', 'cred-a'),
            'POST /credentials'         => fn () => LiteLlmAdmin::createCredential('cred-a', ['api_key' => 'PLACEHOLDER']),
            'PATCH /credentials/{name}' => fn () => LiteLlmAdmin::updateCredential('cred-a', ['api_key' => 'PLACEHOLDER']),
            'POST /model/delete'        => fn () => LiteLlmAdmin::deleteModel('dep-1'),
            'POST /model/block'         => fn () => LiteLlmAdmin::blockModel('dep-1'),
            'POST /model/unblock'       => fn () => LiteLlmAdmin::unblockModel('dep-1'),
        ];

        foreach ($writers as $route => $run) {
            $this->lastBody = null;
            $run();
            $body = (array) json_decode((string) $this->lastBody, true);

            foreach ((array) $this->contract()['routes'][$route]['required'] as $field) {
                $this->assertArrayHasKey($field, $body,
                    "**حقلٌ إلزاميٌّ غائب**: {$route} بلا {$field}");
            }
        }
    }

    /**
     * **واللقطةُ تشهد على نفسِها.**
     *
     * لو وُلّدت من إصدارٍ آخرَ يوماً، أو حُرِّرت بيدٍ، سقط هذا الحارسُ قبل أن
     * يُصدَّق ما لا تصدّقه البوّابة.
     */
    public function test_لقطةُ_العقدِ_تصف_الإصدارَ_المثبَّتَ_وأحكامَه(): void
    {
        $c = $this->contract();

        $this->assertSame('1.101.0', (string) $c['litellm_version']);
        $this->assertSame(['model'], (array) $c['litellm_params_only_required_field']);

        // الأحكامُ التي وُلد منها الإصلاح — الشكلُ القديمُ مرفوضٌ والجديدُ مقبول
        $v = (array) $c['measured_verdicts'];
        $this->assertStringStartsWith('REJECTED', (string) $v['model_new.model_info_EMPTY_ARRAY']);
        $this->assertSame('ACCEPTED', (string) $v['model_new.model_info_EMPTY_OBJECT']);
        $this->assertStringStartsWith('REJECTED', (string) $v['credentials.credential_info_EMPTY_ARRAY']);
        $this->assertSame('ACCEPTED', (string) $v['credentials.credential_info_EMPTY_OBJECT']);
        $this->assertStringStartsWith('REJECTED', (string) $v['model_new.litellm_params_NO_MODEL']);
    }

    // ═══ ④ الرسالةُ تقول ما جرى — والأربعمئةُ واثنتانِ وعشرون ليست «غيرَ معروف» ═══

    public function test_رفضُ_الشكلِ_يُقال_بالعربيّةِ_لا_برمزٍ_وحدَه(): void
    {
        // **ولا مُرصِدٌ ثانٍ**: `Http::fake` بإغلاقٍ **يدمج** ولا يستبدل،
        // فالمُرصِدُ الأوّلُ يبقى هو المُجيب — مصيدةٌ أسقطت دفعةً سابقة
        $this->status = 422;

        $r = LiteLlmAdmin::createModel('hub-a', 'up/a', 'cred-a');

        $this->assertFalse($r['ok']);
        $this->assertSame(422, $r['code']);
        $this->assertStringContainsString('نوع', (string) $r['error'],
            '**رمزٌ بلا معنى**: ٤٢٢ عادت للمدير بلا كلمةٍ تقول ما الخطب');
        // ولا تُقرأ «طلباً مرفوضَ الشكل» كالأربعمئة: الجسمُ وصل وقُرئ ثمّ رُفض حقلٌ منه
        $this->assertStringContainsString('البوّابة', (string) $r['error']);
    }
}
