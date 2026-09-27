<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **النداءُ المحكومُ الواحد** (`docs/ai-hub/46-ai-roadmap.md` §٢).
 *
 * «اسأل Hub» كلُّه يحرس سلوكَ الحلقةِ بعد استخراجها (حزمةُ `AskHub/` لم يتغيّر فيها
 * سطرٌ واحد). وهذا الملفُّ يحرس ما صار جديداً: أنّ **ميزةً ثانيةً** تنالها كما هي —
 * متنٌ حرٌّ يُمرَّر بلا تحويل، وحوكمةٌ وسقفٌ وسجلٌّ مجاناً — وأنّ **لا بابَ آخر**
 * إلى البوّابة.
 */
class GovernedCompletionTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    /** @var list<array{0:array,1:int}> */
    private array $steps = [];

    private int $at = 0;

    private AiProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) {
            Settings::put($k, '1', 'test');
        }
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $this->profile = AiProfile::query()->where('key', 'general')->firstOrFail();
        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدُ الاختبار', 'enabled' => true,
            'credential_name' => 'hub-gc-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);
        AiProfiles::attach($this->profile, $model);

        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $step = $this->steps[min($this->at, max(0, count($this->steps) - 1))]
                ?? [LiteLlmFixtures::answer('{"ok":true}'), 200];
            $this->at++;

            return Http::response($step[0], $step[1] ?? 200);
        });
    }

    private function open(array $gov = [], int $maxCalls = 3): GovernedCompletion
    {
        return GovernedCompletion::open($this->profile, $gov, [
            'feature' => 'audit', 'max_calls' => $maxCalls, 'max_output' => 400, 'in_tokens' => 100,
        ]);
    }

    public function test_متنُ_الميزةِ_يُمرَّر_كما_هو_والنموذجُ_أوّلاً_والسقفُ_آخراً(): void
    {
        $this->steps = [[LiteLlmFixtures::answer('{"findings":[]}'), 200]];

        $r = $this->open()->call([
            'messages' => [['role' => 'user', 'content' => 'افحص']],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
        ], 20);

        $this->assertTrue($r['ok']);
        $this->assertSame('chat.completion', $r['data']['object']);
        $this->assertCount(1, $this->sent);
        // ترتيبُ المفاتيحِ كما كان قبل الاستخراج: النموذج · متنُ الميزة · السقف
        // (و`AiChat` تُلحق بعدها ما تفرضه هي — `stream=false` — فلا يُقاس ما بعد السقف)
        $keys = array_keys($this->sent[0]);
        $this->assertSame(['model', 'messages', 'response_format', 'temperature', 'max_tokens'],
            array_slice($keys, 0, 5));
        $this->assertSame(['type' => 'json_object'], $this->sent[0]['response_format'], 'مفتاحُ الميزةِ يصل بلا تحويل');
        $this->assertSame(400, $this->sent[0]['max_tokens']);
    }

    public function test_سقفُ_النداءاتِ_للطلبِ_كلِّه_يُفحَص_قبل_النداء(): void
    {
        $gc = $this->open([], 1);
        $this->assertTrue($gc->call(['messages' => [['role' => 'user', 'content' => 'أ']]], 5)['ok']);

        $second = $gc->call(['messages' => [['role' => 'user', 'content' => 'ب']]], 5);

        $this->assertFalse($second['ok']);
        $this->assertSame(AskFailures::TOOL_BUDGET, $second['code']);
        $this->assertCount(1, $this->sent, 'النداءُ الثاني لم يغادر الخادم');
        $this->assertSame(1, $gc->usage()['calls']);
    }

    public function test_الحوكمةُ_تكتب_صفّاً_لكلِّ_محاولةٍ_باسمِ_الميزة(): void
    {
        $auth = GovernedCompletion::authorize($this->owner, $this->profile, 'audit', 'corr-gc-1');
        $this->assertTrue($auth['ok'], (string) $auth['code']);

        $this->steps = [[LiteLlmFixtures::answer('{"findings":[]}', LiteLlmFixtures::usage()), 200]];
        $r = $this->open($auth['gov'])->call(['messages' => [['role' => 'user', 'content' => 'افحص']]], 20);

        $this->assertTrue($r['ok']);
        $rows = DB::table('ai_usage_events')->where('feature', 'audit')->orderBy('id')->get();
        $this->assertCount(1, $rows, 'صفٌّ واحدٌ لمحاولةٍ واحدة');
        $this->assertSame('general', $rows[0]->purpose);
        $this->assertSame('corr-gc-1', $rows[0]->correlation);
        $this->assertNotSame('reserved', $rows[0]->status, 'الحجزُ سُوِّي بعد النداء');
    }

    /**
     * **لا بابَ آخر إلى البوّابة.** نداءُ `AiChat::complete` خارج `GovernedCompletion`
     * ميزةٌ تتجاوز السياسةَ والميزانيّةَ والسجلّ — فتُسقط الحزمة.
     */
    public function test_لا_نداءَ_للبوّابةِ_إلّا_عبر_النداءِ_المحكوم(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($f->getPathname())) as $t) {
                if (! in_array($t->id, [T_COMMENT, T_DOC_COMMENT], true)) $code .= $t->text;
            }
            if (preg_match('/AiChat\s*::\s*complete\s*\(/', $code)) {
                $offenders[] = str_replace(base_path() . '/', '', $f->getPathname());
            }
        }

        $this->assertSame(['app/Support/Ai/GovernedCompletion.php'], $offenders,
            'نداءٌ للبوّابةِ يتجاوز الحوكمة: ' . implode(' · ', $offenders));
    }
}
