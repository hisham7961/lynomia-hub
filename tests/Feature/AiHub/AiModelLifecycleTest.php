<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiModelLifecycle;
use App\Support\Ai\Catalog\AiModels;
use App\Support\Ai\Catalog\AiModelSources;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **دورةُ حياةِ النموذجِ كاملةً — والإزالةُ منها** (LIFE).
 *
 * ── **العيبُ الذي وُلدت منه هذه الحزمة** ──
 *
 * تبنّى المالكُ نموذجاً غيرَ مناسبٍ من الاكتشاف، فوجد نفسَه **محبوساً**:
 *
 *  ① **لا مسارَ حذفٍ في المنتجِ أصلاً.** `LiteLlmAdmin::deleteModel()` مكتوبةٌ
 *     منذ W3 و**لا مُستدعيَ لها**: لا مسارَ ولا مُتحكِّمَ ولا زرّ. فالدورةُ
 *     بُنيت إضافةً وتهيئةً وتفعيلاً وفحصاً — **بلا باب خروج**.
 *  ② **والشاشةُ لا تُظهر بابَ الإضافةِ الحقيقيّ**: زرُّ «اكتشاف» البارزُ يقرأ
 *     سجلَّ البوّابةِ وحدَه، وكلُّ ما فيه مستورَدٌ سلفاً فيُعرَض **مُعطَّلاً**.
 *     فبدا النظامُ وكأنّه يرفض إضافةَ نموذجٍ ثانٍ.
 *
 * ── **وما يُحرَس هنا** ──
 *
 * الإزالةُ **مُتَّسِقةُ الطرفَين ولا تترك يتيماً**، و**تُردّ إن كان النموذجُ
 * مستعمَلاً** مع بيانِ ما يمنع، و**تُعاد بلا أثرٍ جانبيّ** (idempotent).
 */
class AiModelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-LIFE2b7d9e4a11ff8833cc';

    /** سجلُّ البوّابةِ المُحاكى — `GET /model/info` */
    private array $registry = [];

    /** معرّفاتُ النشرِ التي طُلب حذفُها */
    private array $deleted = [];

    /** رمزُ ردِّ الحذفِ عند البوّابة */
    private int $deleteStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            $url = $req->url();

            if (str_contains($url, '/model/delete')) {
                $this->deleted[] = (string) (($this->sentBody($req))['id'] ?? '');

                return Http::response($this->deleteStatus === 200
                    ? ['message' => 'deleted'] : ['detail' => 'boom'], $this->deleteStatus);
            }
            if (str_contains($url, '/model/info'))  return Http::response(['data' => $this->registry], 200);
            if (str_contains($url, '/model/new'))   return Http::response(['model_id' => 'dep-new'], 200);
            if (str_contains($url, '/public/litellm_model_cost_map')) return Http::response([], 200);
            if (str_contains($url, '/chat/completions') || str_contains($url, '/health/test_connection')) {
                $this->fail('**اتّصالٌ مدفوعٌ من مسارِ دورةِ حياة**: ' . $url);
            }

            return Http::response(['credential_name' => 'ok'], 200);
        }]);
    }

    private function provider(string $key = 'openai'): AiProvider
    {
        $p = AiProviders::add($key, 'مزوّدٌ وهميّ', ['api_key' => self::SECRET])['provider'];
        AiProviders::setEnabled($p->fresh(), true);

        return $p->fresh();
    }

    private function model(AiProvider $p, string $alias, string $upstream, string $depId): AiModel
    {
        $m = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => $alias,
            'upstream_model' => $upstream, 'discovery_source' => AiModelSources::CATALOG,
            'display_name' => $alias, 'enabled' => false, 'health' => 'UNKNOWN',
            // القدرةُ مُثبَتةٌ عمداً: الربطُ بغرضٍ يشترطها، وغيابُها يجعل
            // الاختبارَ يقيس بابَ الأهليّةِ بدل ما جاء يقيسه
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        $this->registry[] = [
            'model_name'     => $alias,
            'litellm_params' => ['model' => $upstream, 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['id' => $depId, 'mode' => 'chat'],
        ];

        return $m;
    }

    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    // ═══ ① تعدّدُ النماذجِ لمزوّدٍ واحد ═══

    /**
     * **مزوّدٌ واحدٌ يملك نماذجَ عدّة** — لا واحداً.
     *
     * والحارسُ يمسّ المخطَّطَ لا الواجهةَ وحدَها: عمودُ الاسمِ فريدٌ **عالميّاً**،
     * فلو كان الفريدُ على `(provider_id)` وحدَه لَمنع الثانيَ من نفسِ المزوّد.
     */
    public function test_مزوّدٌ_واحدٌ_يحمل_نماذجَ_عدّة(): void
    {
        $p = $this->provider();

        $this->model($p, 'hub-a', 'fam/a', 'dep-a');
        $this->model($p, 'hub-b', 'fam/b', 'dep-b');
        $this->model($p, 'hub-c', 'fam/c', 'dep-c');

        $this->assertSame(3, $p->models()->count());
    }

    /** **ونموذجٌ فاشلٌ لا يُقفِل المزوّدَ على غيرِه** */
    public function test_نموذجٌ_فاشلٌ_لا_يمنع_إضافةَ_غيرِه(): void
    {
        $p = $this->provider();
        $bad = $this->model($p, 'hub-bad', 'fam/bad', 'dep-bad');
        $bad->forceFill(['health' => 'FAILED', 'last_error' => 'HTTP 404'])->save();

        $good = $this->model($p, 'hub-good', 'fam/good', 'dep-good');

        $this->assertSame(2, $p->models()->count());
        $this->assertNotNull($good->id);
    }

    // ═══ ② التبعيّاتُ تُعرَض قبل الإزالة ═══

    /** **نموذجٌ حرٌّ لا مانعَ لإزالتِه** */
    public function test_نموذجٌ_غيرُ_مستعمَلٍ_بلا_موانع(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        $deps = AiModelLifecycle::dependencies($m);

        $this->assertSame([], $deps['blocking']);
        $this->assertTrue($deps['removable']);
    }

    /** **ومربوطٌ بغرضٍ يُعلَن مانعُه بالاسم** — لا «تعذّر» مبهمة */
    public function test_المربوطُ_بغرضٍ_يُعلِن_مانعَه_بالاسم(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-bound', 'fam/bound', 'dep-bound');

        AiProfiles::seed();
        $profile = AiProfile::query()->where('key', 'general')->firstOrFail();
        AiProfiles::attach($profile, $m);

        $deps = AiModelLifecycle::dependencies($m);

        $this->assertFalse($deps['removable']);
        $this->assertNotSame([], $deps['blocking']);
        $this->assertStringContainsString((string) $profile->label ?: 'general',
            json_encode($deps['blocking'], JSON_UNESCAPED_UNICODE) ?: '',
            '**مانعٌ بلا اسم**: المديرُ يُمنَع ولا يُقال له بماذا');
    }

    /** **والمُفعَّلُ يُطفأ قبل أن يُزال** — فلا يختفي نموذجٌ تحت حِمل */
    public function test_المُفعَّلُ_يُطفأ_قبل_الإزالة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-on', 'fam/on', 'dep-on');
        $m->forceFill(['enabled' => true])->save();

        $deps = AiModelLifecycle::dependencies($m->fresh());

        $this->assertFalse($deps['removable']);
    }

    // ═══ ③ الإزالةُ — الطرفانِ أو لا شيء ═══

    /** **الإزالةُ تُلغي النشرَ عند البوّابةِ ثمّ تُسقِط الصفّ** */
    public function test_إزالةُ_نموذجٍ_حرٍّ_تمسّ_الطرفَين(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        $r = AiModelLifecycle::remove($m);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(['dep-free'], $this->deleted,
            '**يتيمٌ عند البوّابة**: الصفُّ زال والنشرُ باقٍ');
        $this->assertNull(AiModel::query()->find($m->id));
    }

    /** **والمستعمَلُ يُرَدّ ولا يُحذَف** — ولا يُمَسُّ الطرفانِ */
    public function test_المستعمَلُ_يُرَدّ_ولا_يُمَسّ_طرفٌ(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-bound', 'fam/bound', 'dep-bound');

        AiProfiles::seed();
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $m);

        $r = AiModelLifecycle::remove($m);

        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->deleted, '**حذفٌ أعمى**: النشرُ أُلغي ونموذجٌ مربوطٌ يعتمد عليه');
        $this->assertNotNull(AiModel::query()->find($m->id));
    }

    /** **وفشلُ البوّابةِ يوقف كلَّ شيء** — فلا صفٌّ يزول ونشرُه باقٍ */
    public function test_فشلُ_البوّابةِ_يمنع_إسقاطَ_الصفّ(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');
        $this->deleteStatus = 500;

        $r = AiModelLifecycle::remove($m);

        $this->assertFalse($r['ok']);
        $this->assertNotNull(AiModel::query()->find($m->id),
            '**يتيمٌ في Hub**: الصفُّ زال وحذفُ البوّابةِ فشل');
    }

    /** **وما لا نشرَ له عند البوّابةِ يزول محليّاً بلا شكوى** (idempotent) */
    public function test_مفقودٌ_عند_البوّابةِ_يزول_محليّاً_بلا_عطل(): void
    {
        $p = $this->provider();
        $m = AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'hub-ghost',
            'upstream_model' => 'fam/ghost', 'display_name' => 'شبح', 'enabled' => false,
            'capabilities' => [], 'limits' => [], 'params' => [], 'pricing' => [],
        ]);
        // ولا مُدخَلَ له في السجلّ

        $r = AiModelLifecycle::remove($m);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame([], $this->deleted, 'طُلب حذفُ نشرٍ لا وجودَ له');
        $this->assertNull(AiModel::query()->find($m->id));
    }

    /** **وإعادةُ الطلبِ لا تُنتج أثراً ثانياً** */
    public function test_إعادةُ_الإزالةِ_بلا_أثرٍ_ثانٍ(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        $this->assertTrue(AiModelLifecycle::remove($m)['ok']);
        $again = AiModelLifecycle::remove($m);

        $this->assertTrue($again['ok'], 'الإعادةُ عُدّت عطلاً');
        $this->assertLessThanOrEqual(1, count($this->deleted), 'حُذف النشرُ مرّتين');
    }

    // ═══ ④ فكُّ الارتباطِ ثمّ الإزالة ═══

    public function test_فكُّ_الارتباطِ_يفتح_بابَ_الإزالة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-bound', 'fam/bound', 'dep-bound');

        AiProfiles::seed();
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $m);

        $this->assertFalse(AiModelLifecycle::dependencies($m)['removable']);

        $u = AiModelLifecycle::unlink($m);
        $this->assertTrue($u['ok'], (string) $u['error']);

        $this->assertTrue(AiModelLifecycle::dependencies($m->fresh())['removable']);
        $this->assertTrue(AiModelLifecycle::remove($m->fresh())['ok']);
        $this->assertSame(['dep-bound'], $this->deleted);
    }

    // ═══ ⑤ المصالحةُ في الاتّجاهَين ═══

    /** **صفٌّ في Hub بلا نشرٍ عند البوّابة** — يُكشَف ولا يُخفى */
    public function test_المصالحةُ_تكشف_صفّاً_بلا_نشر(): void
    {
        $p = $this->provider();
        AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'hub-orphan',
            'upstream_model' => 'fam/orphan', 'display_name' => 'يتيم', 'enabled' => false,
            'capabilities' => [], 'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        $r = AiModelLifecycle::reconcile($p);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(['hub-orphan'], array_column($r['hub_only'], 'litellm_model_name'));
        $this->assertSame([], $r['gateway_only']);
    }

    /** **ونشرٌ عند البوّابةِ بلا صفٍّ في Hub** — يُكشَف كذلك */
    public function test_المصالحةُ_تكشف_نشراً_بلا_صفّ(): void
    {
        $p = $this->provider();
        $this->registry[] = [
            'model_name'     => 'hub-stray',
            'litellm_params' => ['model' => 'fam/stray', 'litellm_credential_name' => $p->credential_name],
            'model_info'     => ['id' => 'dep-stray', 'mode' => 'chat'],
        ];

        $r = AiModelLifecycle::reconcile($p);

        $this->assertSame(['hub-stray'], array_column($r['gateway_only'], 'litellm_model_name'));
        $this->assertSame([], $r['hub_only']);
    }

    /** **والمصالحةُ قراءةٌ محضة** — تكشف ولا تُصلِح من نفسِها */
    public function test_المصالحةُ_لا_تكتب_شيئاً(): void
    {
        $p = $this->provider();
        AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => 'hub-orphan',
            'upstream_model' => 'fam/orphan', 'display_name' => 'يتيم', 'enabled' => false,
            'capabilities' => [], 'limits' => [], 'params' => [], 'pricing' => [],
        ]);

        AiModelLifecycle::reconcile($p);

        $this->assertSame([], $this->deleted);
        $this->assertSame(1, AiModel::query()->count());
    }

    // ═══ ⑥ المسارُ من الواجهة ═══

    public function test_مسارُ_الإزالةِ_موجودٌ_ويعمل_من_الواجهة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        $this->actingAs($this->owner)->stepped()
            ->delete(route('ai.models.destroy', $m))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(AiModel::query()->find($m->id));
        $this->assertSame(['dep-free'], $this->deleted);
    }

    /** **والمستعمَلُ يُرَدّ برسالةٍ تقول ما يمنع** */
    public function test_مسارُ_الإزالةِ_يردّ_المستعمَلَ_برسالةٍ_مفهومة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-bound', 'fam/bound', 'dep-bound');

        AiProfiles::seed();
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $m);

        $this->actingAs($this->owner)->stepped()
            ->delete(route('ai.models.destroy', $m))
            ->assertRedirect()->assertSessionHasErrors();

        $this->assertNotNull(AiModel::query()->find($m->id));
    }

    /** **والإزالةُ كتابةٌ** — فخلف الإدارةِ والتصعيد */
    public function test_الإزالةُ_خلفَ_البابِ_والتصعيد(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        // بلا تصعيد
        $this->actingAs($this->owner)->delete(route('ai.models.destroy', $m))->assertRedirect();
        $this->assertNotNull(AiModel::query()->find($m->id));
        $this->assertSame([], $this->deleted);
    }

    // ═══ ⑦ الشاشةُ تعرض الدورةَ كاملة ═══

    /**
     * **بابُ الاكتشافِ بارزٌ في شاشةِ النماذج.**
     *
     * كان مدفوناً في قسمٍ مطويٍّ بينما الزرُّ البارزُ يقرأ سجلَّ البوّابةِ
     * وحدَه — وكلُّ ما فيه مستورَدٌ سلفاً فيُعرَض **مُعطَّلاً**. فبدا النظامُ
     * وكأنّه يرفض إضافةَ نموذجٍ ثانٍ، وهو إنّما يخفي البابَ.
     */
    public function test_شاشةُ_النماذجِ_تعرض_الدورةَ_كاملة(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-free', 'fam/free', 'dep-free');

        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.models.index', $p))->assertOk()->getContent();

        $this->assertStringContainsString(route('ai.models.browse', $p), $html,
            '**بابُ الإضافةِ مخفيّ**: لا اكتشافَ بارزٌ في شاشةِ النماذج');
        $this->assertStringContainsString(route('ai.models.destroy', $m), $html,
            '**لا بابَ خروج**: النموذجُ يُضاف ولا يُزال');
        $this->assertStringContainsString($m->upstream_model, $html);
        $this->assertStringContainsString(route('ai.models.reconcile', $p), $html);
    }
}
