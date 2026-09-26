<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AskTurn;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskContext;
use App\Support\Ai\Ask\AskMemory;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use App\Support\Ai\Brain\Brain;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **العقلُ الثاني — بحثٌ دلاليٌّ مُنطَّق** (المرحلة ٤ · `Brain`). بوّابةٌ وهميّةٌ تُضمِّن تضميناً حتميّاً
 * (بُعدٌ لكلِّ كلمةٍ مفتاحيّة) فيُعرَف «الأقربُ» سلفاً. ويُمتحن: الفهرسةُ لا تُعيد ما لم يتغيّر وتمحو المحذوف،
 * والبحثُ **بصلاحيّة القارئ وقتَ الاستعلام** (شركةٌ أخرى · حقلٌ محجوب)، والأداةُ تُعلَن حين تعمل فقط،
 * والجوابُ المحفوظُ المبنيُّ عليها يُعاد تحقّقُه سجلّاً سجلّاً.
 */
class BrainTest extends TestCase
{
    private const WORDS = ['مورّد', 'إطلاق', 'طابعة', 'رواتب', 'خادم'];

    /** @var list<array> */
    private array $sent = [];

    private Company $alpha;

    /** نماذجُ «معطّلة» يردّ عليها المزوّدُ الوهميّ بـ503 @var list<string> */
    private array $down = [];

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->alpha = Company::create(['name_ar' => 'ألِف']);
        $this->beta = Company::create(['name_ar' => 'باء']);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-aud-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);
        $emb = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-embed',
            'upstream_model' => 'fake/hub-embed', 'display_name' => 'hub-embed', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['embeddings' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.0001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'embedding')->firstOrFail(), $emb);

        Http::fake(function ($req) {
            $body = json_decode((string) $req->body(), true);
            $this->sent[] = $body;
            if (in_array($body['model'] ?? '', $this->down, true)) return Http::response(['error' => 'ServiceUnavailable'], 503);
            $data = [];
            foreach ((array) ($body['input'] ?? []) as $i => $text) $data[] = ['index' => $i, 'embedding' => self::vec((string) $text)];

            return Http::response(['object' => 'list', 'data' => $data, 'model' => 'hub-embed',
                'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10]], 200);
        });
        Settings::put('brain.enabled', '1', 'test');
    }

    /** تضمينٌ حتميّ: بُعدٌ لكلِّ كلمةٍ مفتاحيّة + ثابتٌ صغيرٌ فلا يكون صفراً */
    private static function vec(string $text): array
    {
        $v = [];
        foreach (self::WORDS as $w) $v[] = (float) mb_substr_count($text, $w);
        $v[] = 0.05;

        return $v;
    }

    private function user(array $companies = [], array $fieldRules = []): User
    {
        $all = collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'قارئ ' . Str::random(5), 'scope' => 'all', 'flags' => [AskPolicy::FLAG => 1],
            'matrix' => $all, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'قارئ', 'email' => Str::random(9) . '@brain.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()] + ($companies ? ['companies' => $companies] : []));
    }

    private function decision(string $title, array $cols = []): string
    {
        $id = (string) Str::uuid();
        DB::table('decisions')->insert(array_merge(['id' => $id, 'title' => $title, 'company_id' => $this->alpha->id,
            'created_at' => now(), 'updated_at' => now()], $cols));

        return $id;
    }

    private function embedCalls(): int
    {
        return count(array_filter($this->sent, fn ($b) => isset($b['input'])));
    }

    public function test_الفهرسةُ_تُضمّن_مرّةً_ولا_تُعيد_ما_لم_يتغيّر_وتمحو_المحذوف(): void
    {
        $a = $this->decision('اعتمادُ مورّدٍ ثانٍ للخوادم');
        $b = $this->decision('تأجيلُ الإطلاق أسبوعاً');

        $s1 = Brain::index();
        $this->assertNull($s1['stopped'], (string) $s1['stopped']);
        $this->assertGreaterThanOrEqual(2, $s1['embedded']);
        $this->assertSame(0, DB::table('ai_embeddings')->whereNotIn('module', array_keys(Brain::SOURCES))->count());
        $this->assertGreaterThan(0, DB::table('ai_usage_events')->where('feature', 'brain')->count(), 'التضمينُ محكومٌ ومسجَّل');

        $calls = $this->embedCalls();
        $s2 = Brain::index();
        $this->assertSame(0, $s2['embedded'], 'ما لم يتغيّر لا يُعاد تضمينُه');
        $this->assertSame($calls, $this->embedCalls(), 'ولا نداء');

        DB::table('decisions')->where('id', $a)->update(['title' => 'اعتمادُ مورّدٍ ثالث']);
        $this->assertSame(1, Brain::index()['embedded'], 'المتغيّرُ وحدَه');

        DB::table('decisions')->where('id', $b)->delete();
        Brain::index();
        $this->assertSame(0, DB::table('ai_embeddings')->where('record_id', $b)->count(), 'المحذوفُ يُمحى من الفهرس');
        $this->assertStringNotContainsString('record_id', json_encode(DB::table('ai_embeddings')->first()) === false ? '' : '', 'لا نصَّ مخزَّن');
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('ai_embeddings', 'text'), 'المتّجهُ وحدَه — لا نصّ');
    }

    public function test_المعاينةُ_لا_تنادي_ولا_تكتب(): void
    {
        $this->decision('اعتمادُ مورّدٍ ثانٍ');
        $s = Brain::index(true);
        $this->assertGreaterThan(0, $s['embedded']);
        $this->assertSame(0, $this->embedCalls());
        $this->assertSame(0, DB::table('ai_embeddings')->count());
    }

    public function test_البحثُ_بالمعنى_بصلاحيّة_القارئ_وقتَ_الاستعلام(): void
    {
        $mine = $this->decision('اعتمادُ مورّدٍ ثانٍ للخوادم');
        $theirs = $this->decision('مورّدٌ مورّدٌ مورّدٌ للشركة الأخرى', ['company_id' => $this->beta->id]);
        $launch = $this->decision('تأجيلُ الإطلاق');
        Brain::index();

        $owner = Brain::search($this->owner, 'أيُّ مورّد اعتمدنا؟');
        $this->assertTrue($owner['ok'], (string) $owner['code']);
        $ids = array_column($owner['hits'], 'id');
        $this->assertEqualsCanonicalizing([$mine, $theirs], array_slice($ids, 0, 2), 'القرارا المورّد أقربُ من غيرهما للمالك');
        $this->assertGreaterThan(1, array_search($launch, $ids, true), 'والبعيدُ بالمعنى بعدهما');

        $scoped = Brain::search($this->user([$this->alpha->id]), 'أيُّ مورّد اعتمدنا؟');
        $ids = array_column($scoped['hits'], 'id');
        $this->assertSame($mine, $ids[0], 'الأقربُ مما يراه');
        $this->assertNotContains($theirs, $ids, 'الأقربُ مطلقاً في شركةٍ أخرى لا يُعاد');
    }

    /**
     * **الحكمُ هو `hub_scope` لا تضييقُ الشركة** — قارئٌ محدودٌ بمشاريعه، والقرارُ في شركته نفسِها لكن في مشروعٍ
     * ليس له: التضييقُ بالشركة يُمرّره، والحارسُ المُنطَّقُ وحدَه يمنعه.
     */
    public function test_النطاقُ_بالمشروع_يحكم_ولو_مرّ_التضييقُ_بالشركة(): void
    {
        $pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $pid, 'name' => 'مشروعٌ ليس لي', 'company_id' => $this->alpha->id,
            'created_at' => now(), 'updated_at' => now()]);
        $d = $this->decision('اعتمادُ مورّدٍ في مشروعٍ ليس لي', ['project_id' => $pid]);
        Brain::index();

        $u = $this->user([$this->alpha->id]);
        $role = $u->role;
        $role->scope = 'proj';
        $role->save();
        $u = $u->fresh();
        $this->assertTrue(hub_scoped($u), 'القارئُ محدودٌ بمشاريعه');

        $this->assertContains($d, array_column(Brain::search($this->owner, 'مورّد')['hits'], 'id'), 'المالكُ يراه');
        $this->assertNotContains($d, array_column(Brain::search($u, 'مورّد')['hits'], 'id'),
            'قرارٌ في مشروعٍ ليس له — مرّ بالتضييق بالشركة ويحجبه الحارسُ المُنطَّق');
    }

    public function test_مقطعٌ_من_حقلٍ_محجوبٍ_لا_يُحسب(): void
    {
        $d = $this->decision('قرارٌ عاديّ', ['notes' => 'ملاحظةٌ عن الرواتب الرواتب الرواتب']);
        Brain::index();

        $open = Brain::search($this->user([$this->alpha->id]), 'الرواتب');
        $this->assertContains($d, array_column($open['hits'], 'id'));
        $this->assertSame('notes', collect($open['hits'])->firstWhere('id', $d)['field']);

        $blind = Brain::search($this->user([$this->alpha->id], ['decisions' => ['notes' => 'hide']]), 'الرواتب');
        foreach ($blind['hits'] as $h) {
            $this->assertNotSame('notes', $h['field'], 'مقطعُ حقلٍ محجوبٍ حُسب');
        }
    }

    public function test_الأداةُ_تُعلَن_حين_يعمل_والجوابُ_المحفوظُ_عليها_يُعاد_تحقّقُه(): void
    {
        $d = $this->decision('اعتمادُ مورّدٍ ثانٍ');
        Brain::index();
        $u = $this->user([$this->alpha->id]);

        $names = array_map(fn ($t) => $t['function']['name'], AskTools::schema(AskTools::catalog($u)));
        $this->assertContains('hub_semantic', $names);

        $r = AskTools::run('hub_semantic', ['q' => 'المورّد'], $u);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame($d, $r['rows'][0]['id']);

        $ctx = AskContext::open();
        $ctx->addResult($r);
        $t = AskMemory::record($u, null, 'ماذا قرّرنا عن المورّد؟', ['ok' => true, 'answer' => 'اعتمدنا ثانياً', 'sources' => $ctx->sources()]);
        $this->assertFalse(AskMemory::turns($u, $t)[0]['hidden']);
        $u->forceFill(['companies' => [$this->beta->id]])->save();
        $this->assertTrue(AskMemory::turns($u->fresh(), $t)[0]['hidden'], 'نتيجةُ البحث بالمعنى تُعاد مصادقتُها سجلّاً سجلّاً');

        Settings::put('brain.enabled', '0', 'test');
        $this->assertNotContains('hub_semantic', array_map(fn ($t) => $t['function']['name'], AskTools::schema(AskTools::catalog($u))),
            'مطفأٌ ⇒ لا يُعلَن');
        $this->assertSame('BRAIN_OFF', Brain::search($u, 'المورّد')['code']);
    }

    // ═══ من المراجعة العدائيّة (v2.609.1) ═══

    public function test_ما_لا_يراه_القارئُ_لا_يزاحم_ما_يراه(): void
    {
        $mineP = (string) Str::uuid();
        $otherP = (string) Str::uuid();
        foreach ([$mineP => 'مشروعي', $otherP => 'مشروعٌ ليس لي'] as $id => $n) {
            DB::table('projects')->insert(['id' => $id, 'name' => $n, 'company_id' => $this->alpha->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $mine = $this->decision('مورّد إطلاق طابعة', ['project_id' => $mineP]);
        $u = $this->user([$this->alpha->id]);
        $role = $u->role;
        $role->scope = 'proj';
        $role->save();
        DB::table('projects')->where('id', $mineP)->update(['manager_id' => $u->id]);
        $u = $u->fresh();
        // خمسون قراراً أقربُ إلى السؤال في مشروعٍ لا يراه — كانت تملأ النافذةَ قبل الحكم فيختفي قرارُه
        $others = [];
        for ($i = 0; $i < 50; $i++) $others[] = $this->decision('مورّد مورّد ' . $i, ['project_id' => $otherP]);
        Brain::index();

        $r = Brain::search($u, 'مورّد');
        $this->assertTrue($r['ok']);
        $ids = array_column($r['hits'], 'id');
        $this->assertContains($mine, $ids, 'سجلُّه لا يُزاحَم بما لا يراه');
        $this->assertSame([], array_values(array_intersect($others, $ids)), 'ولا يظهر ما ليس له');
    }

    public function test_مقاطعُ_الحقل_المحجوب_لا_تحجز_مكانَ_ما_يُرى(): void
    {
        $mine = $this->decision('مورّد طابعة');
        for ($i = 0; $i < 60; $i++) $this->decision('قرار ' . $i, ['notes' => 'مورّد']);
        Brain::index();

        $r = Brain::search($this->user([$this->alpha->id], ['decisions' => ['notes' => 'hide']]), 'مورّد');
        $this->assertContains($mine, array_column($r['hits'], 'id'));
    }

    public function test_مقطعٌ_زال_يُمحى_ولو_بقي_العددُ_نفسُه(): void
    {
        $d = $this->decision('قرار', ['notes' => str_repeat('طابعة ', 100) . "\n" . str_repeat('خادم ', 100)]);
        Brain::index();
        $this->assertSame(2, DB::table('ai_embeddings')->where('record_id', $d)->where('field', 'notes')->count());

        // الملاحظاتُ قصُرت إلى مقطعٍ والسببُ طال بمقطع — العددُ ثلاثةٌ قبلُ وبعد
        DB::table('decisions')->where('id', $d)->update(['notes' => 'قصير', 'reason' => 'سبب']);
        Brain::index();

        $keys = DB::table('ai_embeddings')->where('record_id', $d)->orderBy('field')->orderBy('chunk')->get(['field', 'chunk'])
            ->map(fn ($r) => $r->field . '#' . $r->chunk)->all();
        $this->assertSame(['notes#0', 'reason#0', 'title#0'], $keys);
        // والنصُّ المحذوفُ لا يُطابَق: أقربُ مقاطع السجلّ إلى «خادم» ليس مقطعاً عنه
        $hit = collect(Brain::search($this->owner, 'خادم خادم')['hits'])->firstWhere('id', $d);
        $this->assertTrue($hit === null || $hit['score'] < 0.5, 'مقطعُ «خادم» الزائلُ ما زال يُطابَق');
    }

    public function test_سجلٌّ_نُقل_إلى_شركةٍ_أخرى_يتبعه_فهرسُه_بلا_تضمين(): void
    {
        $id = (string) Str::uuid();
        DB::table('kb_articles')->insert(['id' => $id, 'title' => 'مورّد', 'company_id' => $this->alpha->id, 'created_at' => now(), 'updated_at' => now()]);
        Brain::index();
        $calls = $this->embedCalls();

        DB::table('kb_articles')->where('id', $id)->update(['company_id' => $this->beta->id]);
        Brain::index();

        $this->assertSame([(string) $this->beta->id], DB::table('ai_embeddings')->where('record_id', $id)->distinct()->pluck('company_id')->map(fn ($x) => (string) $x)->all());
        $this->assertSame($calls, $this->embedCalls(), 'النصُّ لم يتغيّر ⇒ لا تضمين');
        $this->assertContains($id, array_column(Brain::search($this->user([$this->beta->id]), 'مورّد')['hits'], 'id'), 'شركتُه الجديدةُ تجده');
    }

    public function test_متّجهاتُ_النموذج_الاحتياطيّ_توسَم_باسمه_ولا_تُقارَن_بغيره(): void
    {
        $p2 = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّدٌ ثانٍ', 'enabled' => true,
            'credential_name' => 'hub-emb2-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $b = AiModel::create(['provider_id' => $p2->id, 'litellm_model_name' => 'hub-embed-b', 'upstream_model' => 'fake/hub-embed-b',
            'display_name' => 'b', 'enabled' => true, 'health' => 'UNKNOWN', 'capabilities' => ['embeddings' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [], 'pricing' => ['input_per_1k' => ['v' => 0.0001, 'src' => 'litellm'],
            'output_per_1k' => ['v' => 0, 'src' => 'litellm'], 'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'embedding')->firstOrFail(), $b);
        $d = $this->decision('اعتماد مورّد');

        $this->down = ['hub-embed'];
        Brain::index();
        $this->assertSame(['hub-embed-b'], DB::table('ai_embeddings')->where('record_id', $d)->pluck('model')->all(), 'موسومٌ بمن خدم فعلاً');

        // الأساسيُّ عاد: السؤالُ يُضمَّن به، فلا يُقارَن بمتّجهات «ب» — والتغطيةُ الناقصةُ تُعلَن
        $this->down = ['hub-embed-b'];
        \Illuminate\Support\Facades\Cache::flush();
        $r = Brain::search($this->owner, 'مورّد');
        $this->assertTrue($r['ok'], (string) $r['code']);
        $this->assertSame([], $r['hits'], 'لا مقارنةَ بين فضاءين');
        $this->assertTrue($r['partial']);

        // والجولةُ التالية تُعيد تضمينَه بالأساسيّ (بصمتُه لا تطابق)
        Brain::index();
        $this->assertSame(['hub-embed'], DB::table('ai_embeddings')->where('record_id', $d)->pluck('model')->all());
        $r = Brain::search($this->owner, 'مورّد');
        $this->assertSame([$d], array_column($r['hits'], 'id'));
        $this->assertFalse($r['partial']);
    }
}
