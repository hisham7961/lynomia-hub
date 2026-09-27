<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiModelLifecycle;
use App\Support\Ai\Catalog\AiModels;
use App\Support\Ai\Catalog\AiModelSources;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **حذفُ نموذجٍ لا يُطفئ الكتالوج** (قبولُ الإنتاج · عطبُ ما بعد الحذف).
 *
 * ── **البلاغ** ──
 *
 * حُذف النموذجُ المسجَّلُ الوحيدُ من الشاشة، فاختفت **بقيّةُ الكتالوج** ولم
 * يعد يظهر ما يُضاف. **والكتالوجُ معرفةٌ عن السوقِ لا عن سجلِّنا** — فعددُ
 * نماذجِنا صفراً أو مئةً لا يغيّر منه حرفاً.
 */
class AiModelDeletionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-DEL7c2a91f4e88b03dd51';

    private array $costMap = [];

    private array $registry = [];

    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/public/litellm_model_cost_map')) {
                return Http::response($this->costMap, 200);
            }
            if (str_contains($url, '/model/delete')) {
                $this->deleted[] = (array) json_decode((string) $req->body(), true);

                return Http::response(['deleted' => true], 200);
            }
            if (str_contains($url, '/model/info')) {
                return Http::response(['data' => $this->registry], 200);
            }
            if (str_contains($url, '/model/new')) {
                return Http::response(['model_id' => 'dep-new'], 200);
            }
            if (str_contains($url, '/chat/completions') || str_contains($url, '/health/test_connection')) {
                $this->fail('**نداءٌ مدفوعٌ من مسارِ حذفٍ أو اكتشاف**: ' . $url);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function provider(): AiProvider
    {
        $p = AiProviders::add('openai', 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    private function catalogEntry(array $extra = []): array
    {
        return array_merge([
            'litellm_provider'          => 'openai',
            'mode'                      => 'chat',
            'max_input_tokens'          => 128000,
            'max_output_tokens'         => 4096,
            'input_cost_per_token'      => 0.0000005,
            'output_cost_per_token'     => 0.0000015,
            'supports_function_calling' => true,
        ], $extra);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① الرحلةُ كاملةً — نموذجٌ واحدٌ ⟵ حذفٌ ⟵ صفر ⟵ الكتالوجُ باقٍ ⟵ تبنٍّ
    // ═══════════════════════════════════════════════════════════════════

    public function test_حذفُ_آخرِ_نموذجٍ_يُبقي_الكتالوجَ_ويُبقي_التبنّيَ_ممكناً(): void
    {
        $p = $this->provider();

        $this->costMap = [
            'fam/alpha-1' => $this->catalogEntry(),
            'fam/beta-2'  => $this->catalogEntry(),
            'fam/gamma-3' => $this->catalogEntry(),
        ];
        $this->registry = [[
            'model_name'     => 'hub-alpha-1',
            'litellm_params' => ['model' => 'fam/alpha-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat', 'id' => 'dep-alpha'],
        ]];

        // ① نموذجٌ واحدٌ مسجَّل
        $adopted = AiModels::adopt($p, [AiModelSources::GATEWAY . '|fam/alpha-1']);
        $this->assertTrue((bool) $adopted['ok'], 'لم يُتبنَّ النموذجُ الأوّل');
        $this->assertSame(1, AiModel::query()->where('provider_id', $p->id)->count());

        // ② حُذف
        $model = AiModel::query()->where('provider_id', $p->id)->firstOrFail();
        $res   = AiModelLifecycle::remove($model);
        $this->assertTrue((bool) $res['ok'], 'تعذّر الحذف: ' . (string) ($res['error'] ?? ''));

        // ③ صفرُ نماذجَ مسجّلة
        $this->assertSame(0, AiModel::query()->where('provider_id', $p->id)->count(),
            'بقي صفٌّ بعد الحذف');

        // والبوّابةُ لم تعد تُعلن نشراً — كما يقع في الإنتاج بعد `model/delete`
        $this->registry = [];

        // ④ الكتالوجُ ما زال يُتصفَّح
        $found = AiModelSources::discover($p->fresh());

        $this->assertTrue((bool) $found['ok'], 'سقط الاكتشافُ كلُّه بعد حذفِ آخرِ نموذج');
        $this->assertTrue((bool) $found['sources'][AiModelSources::CATALOG]['ok'],
            'الكتالوجُ لم يُقرَأ بعد الحذف');
        $this->assertCount(3, $found['candidates'],
            '**الكتالوجُ اختفى بعد حذفِ آخرِ نموذجٍ مسجَّل** — وهو معرفةٌ عن السوقِ لا عن سجلِّنا');

        // ⑤ وتبنّي نموذجٍ آخرَ ينجح
        $again = AiModels::adopt($p->fresh(), [AiModelSources::CATALOG . '|fam/beta-2']);
        $this->assertTrue((bool) $again['ok'], 'تعذّر تبنّي نموذجٍ بعد الحذف: '
            . (string) ($again['error'] ?? ''));
        $this->assertSame(1, AiModel::query()->where('provider_id', $p->id)->count());
    }

    /** **والشاشةُ نفسُها تعرضه** — لا الطبقةُ وحدَها */
    public function test_شاشةُ_التصفّحِ_تعرض_الكتالوجَ_عند_صفرِ_نماذجَ_مسجّلة(): void
    {
        $p = $this->provider();
        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry(), 'fam/beta-2' => $this->catalogEntry()];
        $this->registry = [];

        $this->assertSame(0, AiModel::query()->count(), 'الاختبارُ يقيس حالةَ الصفرِ فليكن صفراً');

        $html = $this->actingAs($this->admin())
            ->get(route('ai.models.browse', $p))->assertOk()->getContent();

        $this->assertStringContainsString('fam/alpha-1', $html,
            '**شاشةُ التصفّحِ فارغةٌ بلا نماذجَ مسجّلة** — والكتالوجُ لا يحتاج سجلّاً');
        $this->assertStringContainsString('fam/beta-2', $html);
        $this->assertStringNotContainsString('لا اكتشافَ تلقائيَّ لهذا المزوّد', $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② إعادةُ الاكتشافِ وإعادةُ التبنّي
    // ═══════════════════════════════════════════════════════════════════

    public function test_حذفٌ_ثمّ_إعادةُ_اكتشافٍ_ثمّ_إعادةُ_تبنٍّ_للنموذجِ_نفسِه(): void
    {
        $p = $this->provider();
        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry()];
        $this->registry = [[
            'model_name'     => 'hub-alpha-1',
            'litellm_params' => ['model' => 'fam/alpha-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat', 'id' => 'dep-alpha'],
        ]];

        AiModels::adopt($p, [AiModelSources::GATEWAY . '|fam/alpha-1']);
        $model = AiModel::query()->where('provider_id', $p->id)->firstOrFail();
        $this->assertTrue((bool) AiModelLifecycle::remove($model)['ok']);

        $this->registry = [];

        // يُكتشَف ثانيةً من الكتالوج — و**لا يُعَدُّ مستورَداً** فقد حُذف
        $found = AiModelSources::discover($p->fresh());
        $again = collect($found['candidates'])->firstWhere('upstream_model', 'fam/alpha-1');

        $this->assertNotNull($again, 'النموذجُ المحذوفُ لم يعد يُكتشَف — والحذفُ ليس حجباً');
        $this->assertFalse((bool) $again['already_imported'],
            'المحذوفُ ما زال يُعَدّ «في Hub» — فلا يُعاد تبنّيه أبداً');

        $res = AiModels::adopt($p->fresh(), [AiModelSources::CATALOG . '|fam/alpha-1']);
        $this->assertTrue((bool) $res['ok'], 'تعذّرت إعادةُ تبنّي النموذجِ نفسِه: '
            . (string) ($res['error'] ?? ''));
        $this->assertSame(1, AiModel::query()->where('provider_id', $p->id)->count());
    }

    /**
     * **وإعادةُ تبنّي النموذجِ نفسِه من المصدرِ نفسِه** — وهنا يقع التصادم.
     *
     * الاسمُ الداخليُّ يُولَّد **حتميّاً** من معرّفِ المزوّد، وعمودُه **فريدٌ**،
     * والحذفُ **ناعم**. فالصفُّ المحذوفُ يحتفظ باسمِه في الفهرسِ الفريد،
     * والمولِّدُ لا يراه — فيُعيد الاسمَ نفسَه ويُصادم.
     */
    public function test_إعادةُ_تبنّي_النموذجِ_المحذوفِ_من_المصدرِ_نفسِه(): void
    {
        $p = $this->provider();
        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry()];
        $this->registry = [];

        $first = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/alpha-1']);
        $this->assertTrue((bool) $first['ok'], 'لم يُتبنَّ أوّلَ مرّة');
        $alias = (string) $first['models'][0]->litellm_model_name;

        $this->assertTrue((bool) AiModelLifecycle::remove($first['models'][0])['ok']);
        $this->assertSame(0, AiModel::query()->count());

        // **وهنا كان يسقط الطلبُ بخرقِ فهرسٍ فريد** — والنشرُ قد وقع سلفاً
        $again = AiModels::adopt($p->fresh(), [AiModelSources::CATALOG . '|fam/alpha-1']);

        $this->assertTrue((bool) $again['ok'],
            'تعذّرت إعادةُ تبنّي النموذجِ نفسِه بعد حذفِه: ' . (string) ($again['error'] ?? ''));
        $this->assertSame(1, AiModel::query()->count());
        $this->assertSame($alias, (string) $again['models'][0]->litellm_model_name,
            'الاسمُ الداخليُّ لم يعد حتميّاً بعد الحذف');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ الاعتمادُ لا يُمَسّ
    // ═══════════════════════════════════════════════════════════════════

    public function test_حذفُ_النموذجِ_لا_يحذف_اعتمادَ_المزوّدِ_ولا_يُبطله(): void
    {
        $p = $this->provider();
        $before = [
            'credential_name'  => (string) $p->credential_name,
            'credential_state' => (string) $p->credential_state,
            'enabled'          => (bool) $p->enabled,
        ];

        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry()];
        $this->registry = [[
            'model_name'     => 'hub-alpha-1',
            'litellm_params' => ['model' => 'fam/alpha-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat', 'id' => 'dep-alpha'],
        ]];

        AiModels::adopt($p, [AiModelSources::GATEWAY . '|fam/alpha-1']);
        AiModelLifecycle::remove(AiModel::query()->where('provider_id', $p->id)->firstOrFail());

        $after = AiProvider::query()->whereKey($p->id)->firstOrFail();

        $this->assertSame($before['credential_name'], (string) $after->credential_name,
            'تغيّر مرجعُ الاعتمادِ بحذفِ نموذج');
        $this->assertSame($before['credential_state'], (string) $after->credential_state,
            '**حذفُ نموذجٍ أبطل الاعتماد** — وهما طبقتان لا واحدة');
        $this->assertSame($before['enabled'], (bool) $after->enabled);

        // ولا نداءَ حذفِ اعتمادٍ غادر الخادم
        foreach ($this->deleted as $body) {
            $this->assertArrayNotHasKey('credential_name', $body,
                'مسارُ حذفِ النموذجِ لمس الاعتماد');
        }
    }

    public function test_المزوّدُ_يبقى_قائماً_بعد_حذفِ_كلِّ_نماذجِه(): void
    {
        $p = $this->provider();
        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry()];
        $this->registry = [[
            'model_name'     => 'hub-alpha-1',
            'litellm_params' => ['model' => 'fam/alpha-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat', 'id' => 'dep-alpha'],
        ]];

        AiModels::adopt($p, [AiModelSources::GATEWAY . '|fam/alpha-1']);
        AiModelLifecycle::remove(AiModel::query()->where('provider_id', $p->id)->firstOrFail());

        $this->assertSame(1, AiProvider::query()->count(), 'اختفى المزوّدُ بحذفِ نماذجِه');
        $this->assertTrue(AiProvider::query()->whereKey($p->id)->exists());
    }

    // ═══════════════════════════════════════════════════════════════════
    // ④ الترشيحُ لا يحجب ما لا يخصّه
    // ═══════════════════════════════════════════════════════════════════

    public function test_الاكتشافُ_يعمل_وسجلُّ_Hub_فارغٌ_تماماً(): void
    {
        $p = $this->provider();
        $this->costMap  = ['fam/alpha-1' => $this->catalogEntry(), 'fam/beta-2' => $this->catalogEntry()];
        $this->registry = [];

        $this->assertSame(0, AiModel::query()->count());

        $found = AiModelSources::discover($p);

        $this->assertTrue((bool) $found['ok']);
        $this->assertCount(2, $found['candidates'],
            'الكتالوجُ لا يُقرَأ إلّا إذا كان لدينا نموذجٌ سلفاً — وهذا دورٌ مغلق');
    }

    /** **وكونُ نموذجٍ مستورَداً لا يحجب جيرانَه** */
    public function test_وجودُ_نموذجٍ_مستورَدٍ_لا_يحجب_بقيّةَ_الكتالوج(): void
    {
        $p = $this->provider();
        $this->costMap = [
            'fam/alpha-1' => $this->catalogEntry(),
            'fam/beta-2'  => $this->catalogEntry(),
            'fam/gamma-3' => $this->catalogEntry(),
        ];
        $this->registry = [[
            'model_name'     => 'hub-alpha-1',
            'litellm_params' => ['model' => 'fam/alpha-1', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['mode' => 'chat', 'id' => 'dep-alpha'],
        ]];

        AiModels::adopt($p, [AiModelSources::GATEWAY . '|fam/alpha-1']);

        $found = AiModelSources::discover($p->fresh());
        $ids   = array_column($found['candidates'], 'upstream_model');

        sort($ids);
        $this->assertSame(['fam/alpha-1', 'fam/beta-2', 'fam/gamma-3'], $ids,
            'استيرادُ نموذجٍ حجب جيرانَه في الكتالوج');

        $imported = collect($found['candidates'])->firstWhere('upstream_model', 'fam/alpha-1');
        $this->assertTrue((bool) $imported['already_imported']);
        foreach (['fam/beta-2', 'fam/gamma-3'] as $other) {
            $this->assertFalse(
                (bool) collect($found['candidates'])->firstWhere('upstream_model', $other)['already_imported'],
                "«{$other}» وُسم مستورَداً وهو ليس كذلك");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑤ والاسمُ المحجوزُ لغيرِه يبقى محجوزاً — الاسترجاعُ ليس استيلاءً
    // ═══════════════════════════════════════════════════════════════════

    /** **صفٌّ مُزالٌ بمعرّفٍ آخرَ يقبض على اسمِه** — فيُولَّد اسمٌ مبصوم */
    public function test_اسمٌ_يقبض_عليه_مُزالٌ_بمعرّفٍ_آخرَ_يُولَّد_له_بديلٌ_حتميّ(): void
    {
        $p = $this->provider();
        $this->registry = [];

        // نموذجٌ معرّفُه `fam/x` يُولَّد له اسمٌ ثمّ يُزال
        $this->costMap = ['fam/x' => $this->catalogEntry()];
        $first = AiModels::adopt($p, [AiModelSources::CATALOG . '|fam/x']);
        $this->assertTrue((bool) $first['ok']);
        $taken = (string) $first['models'][0]->litellm_model_name;
        AiModelLifecycle::remove($first['models'][0]);

        // ونُجبِر التصادمَ: صفٌّ مُزالٌ آخرُ يحمل الاسمَ الذي سيُولَّد لـ`fam/y`
        $ghost = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'hub-openai-fam-y',
            'upstream_model' => 'fam/OTHER', 'display_name' => 'شبح', 'enabled' => false,
            'health' => 'UNKNOWN',
        ]);
        $ghost->delete();

        $this->costMap = ['fam/y' => $this->catalogEntry()];
        $res = AiModels::adopt($p->fresh(), [AiModelSources::CATALOG . '|fam/y']);

        $this->assertTrue((bool) $res['ok'], 'سقط التبنّي بدل أن يُولَّد اسمٌ بديل: '
            . (string) ($res['error'] ?? ''));
        $alias = (string) $res['models'][0]->litellm_model_name;
        $this->assertNotSame('hub-openai-fam-y', $alias, 'استولى على اسمٍ يقبض عليه صفٌّ آخر');
        $this->assertSame('hub-openai-fam-y-' . substr(sha1('fam/y'), 0, 8), $alias,
            'الاسمُ البديلُ ليس حتميّاً');
        $this->assertNotSame($taken, $alias);
    }

    /** **والتسجيلُ اليدويُّ يُرَدُّ بجملةٍ لا بخمسِمئة** */
    public function test_التسجيلُ_اليدويُّ_باسمٍ_يقبض_عليه_مُزالٌ_يُرَدُّ_بجملة(): void
    {
        $p = $this->provider();
        $this->registry = [];

        $ghost = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'hub-manual-held',
            'upstream_model' => 'fam/held', 'display_name' => 'محجوز', 'enabled' => false,
            'health' => 'UNKNOWN',
        ]);
        $ghost->delete();

        $res = AiModels::register($p->fresh(), 'hub-manual-held', 'fam/DIFFERENT');

        $this->assertFalse((bool) $res['ok'], 'مرّ الاسمُ المحجوزُ ثمّ يسقط الإدراجُ بخرقِ قيد');
        $this->assertStringContainsString('محجوز', (string) $res['error']);

        // **ولا نشرَ وقع عند البوّابة** — فالحارسُ يسبق الكتابةَ الخارجيّة
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/model/new'));
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    private function admin(): \App\Models\User
    {
        $role = \App\Models\Role::create(['name' => 'مالكٌ ' . \Illuminate\Support\Str::random(5),
            'scope' => 'all', 'is_owner' => true, 'flags' => [], 'matrix' => []]);

        return \App\Models\User::create(['name' => 'مالك', 'email' => 'owner-del@ai.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }
}
