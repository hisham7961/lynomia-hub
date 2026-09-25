<?php

namespace Tests\Feature\AiHub;

use App\Models\AiProvider;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **شاشةُ المزوّدين** (المرحلة ٢ · W4) — البابُ والهويّةُ والحجب.
 *
 * وأثقلُ ما هنا `test_السرُّ_لا_يعود_إلى_النموذجِ_عند_خطأِ_تحقّق`: `withInput()`
 * عارياً يُفلِش ما أُرسل إلى `old()`، فيعود مفتاحُ المزوّدِ إلى HTML عند أوّلِ
 * خطأٍ — وهو الصنفُ الذي أصابَ `AiCenterController` في المرحلة ١ فاستُثني
 * صراحةً. والحارسُ هنا **مشتقٌّ من الكتالوج** لا من قائمةٍ مكتوبةٍ تتقادم.
 */
class AiProviderScreenTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'sk-W4SCREEN5c2e8a1f77bb3344d9e0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    /** هويّةٌ طازجةٌ مختومةٌ في الجلسة — اصطلاحُ الحزمةِ نفسُه */
    private function stepped()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response(['credential_name' => 'ok'], 200)]);
    }

    private function seedProvider(): AiProvider
    {
        $this->fakeOk();

        return AiProviders::add('openai', 'OpenAI وهميّ', ['api_key' => self::PLANTED])['provider'];
    }

    // ═══ ① البابُ — حارسُ الرابطِ حرفاً بحرف ═══

    public function test_موظّفٌ_عاديٌّ_يُصَدّ_عن_الشاشة(): void
    {
        $this->actingAs($this->employee)->get('/admin/ai/providers')->assertForbidden();
    }

    public function test_المالكُ_يفتح_الشاشة(): void
    {
        $this->actingAs($this->owner)->get('/admin/ai/providers')->assertOk();
    }

    public function test_موظّفٌ_عاديٌّ_لا_يكتب(): void
    {
        $this->fakeOk();
        $this->actingAs($this->employee)->stepped()
            ->post('/admin/ai/providers', ['catalog_key' => 'openai', 'f' => ['api_key' => self::PLANTED]])
            ->assertForbidden();

        $this->assertSame(0, AiProvider::count());
        Http::assertNothingSent();
    }

    // ═══ ② الهويّةُ الطازجةُ على كلِّ كتابة ═══

    /**
     * **كلُّ** كتابةٍ خلف التصعيد — لا إدخالُ السرِّ وحدَه: جلسةٌ مسروقةٌ تستطيع
     * إبطالَ اعتمادٍ وإطفاءَ مزوّدٍ كما تستطيع تبديلَه، والضررُ في الثلاثةِ حقيقيّ.
     */
    public function test_كلُّ_كتابةٍ_تحتاج_هويّةً_طازجة(): void
    {
        $p = $this->seedProvider();
        Http::fake();   // لتصفيرِ السجلّ — والمُرصِدُ الأوّلُ يبقى المُجيب

        $writes = [
            ['post', '/admin/ai/providers', ['catalog_key' => 'openai', 'f' => ['api_key' => self::PLANTED]]],
            ['post', "/admin/ai/providers/{$p->id}/rotate", ['f' => ['api_key' => self::PLANTED]]],
            ['post', "/admin/ai/providers/{$p->id}/revoke", []],
            ['post', "/admin/ai/providers/{$p->id}/toggle", ['enabled' => 1]],
            ['delete', "/admin/ai/providers/{$p->id}", []],
        ];

        foreach ($writes as [$verb, $uri, $payload]) {
            $res = $this->actingAs($this->owner)->{$verb}($uri, $payload);
            $res->assertRedirect();
            $this->assertStringContainsString('/stepup', (string) $res->headers->get('Location'),
                "الكتابةُ {$verb} {$uri} مرّت بلا تصعيدِ هويّة");
        }

        Http::assertNothingSent();
    }

    // ═══ ③ الدورةُ كاملةً من الشاشة ═══

    public function test_الدورةُ_كاملةً_تعمل_من_الشاشة(): void
    {
        $this->fakeOk();

        // إضافة
        $this->actingAs($this->owner)->stepped()->post('/admin/ai/providers', [
            'catalog_key' => 'openai', 'label' => 'مزوّدُ الجولة',
            'f' => ['api_key' => self::PLANTED],
        ])->assertRedirect(route('ai.providers.index'));

        $p = AiProvider::firstOrFail();
        $this->assertSame('configured', (string) $p->credential_state);
        $this->assertFalse($p->enabled, 'الإضافةُ شغّلت المزوّدَ من الشاشة');

        // تشغيل
        $this->actingAs($this->owner)->stepped()
            ->post("/admin/ai/providers/{$p->id}/toggle", ['enabled' => 1]);
        $this->assertTrue($p->fresh()->enabled);

        // تدوير — والاسمُ لا يتغيّر
        $name = (string) $p->credential_name;
        $this->actingAs($this->owner)->stepped()
            ->post("/admin/ai/providers/{$p->id}/rotate", ['f' => ['api_key' => 'sk-W4ROTATEDscreen9911aabbccdd']]);
        $this->assertSame($name, (string) $p->fresh()->credential_name);

        // إبطال
        $this->actingAs($this->owner)->stepped()->post("/admin/ai/providers/{$p->id}/revoke", []);
        $this->assertSame('missing', (string) $p->fresh()->credential_state);
        $this->assertFalse($p->fresh()->enabled);

        // حذف
        $this->actingAs($this->owner)->stepped()->delete("/admin/ai/providers/{$p->id}");
        $this->assertNull(AiProvider::find($p->id));
    }

    // ═══ ④ لا سرَّ في صفحةٍ ولا في جلسة ═══

    public function test_لا_سرَّ_في_صفحةِ_المزوّدين(): void
    {
        $this->seedProvider();

        $html = $this->actingAs($this->owner)->get('/admin/ai/providers')->assertOk()->getContent();

        $this->assertMaskedValueAbsent(self::PLANTED, (string) $html,
            '**تسريب**: سرُّ المزوّدِ ظهر في صفحةِ المزوّدين');
    }

    /**
     * **خطأُ التحقّقِ لا يُعيد السرَّ إلى النموذج.**
     *
     * يُرسَل مفتاحٌ سرّيٌّ مع حقلٍ إلزاميٍّ ناقص، فيسقط التحقّق — ويجب ألّا يبلغ
     * المفتاحُ `old()` ولا HTML. والحقلُ غيرُ السرّيِّ يعود كما أُرسل، فلا يُعاقَب
     * المستخدمُ بإعادةِ كتابةِ نموذجٍ كامل.
     */
    public function test_السرُّ_لا_يعود_إلى_النموذجِ_عند_خطأِ_تحقّق(): void
    {
        $this->fakeOk();

        $res = $this->actingAs($this->owner)->stepped()->post('/admin/ai/providers', [
            'catalog_key' => 'azure_openai',
            'f' => [
                'api_key'     => self::PLANTED,
                'api_base'    => 'https://fake-local.invalid',
                'api_version' => '2024-10-21',
                // `deployment` ناقصٌ عمداً — إلزاميٌّ فيسقط التحقّق
            ],
        ]);

        $res->assertRedirect();
        $this->assertSame(0, AiProvider::count());

        $flashed = json_encode(session('_old_input'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::PLANTED, (string) $flashed,
            '**تسريب**: سرُّ المزوّدِ فُلِش إلى `old()` فيعود إلى HTML');
        $this->assertStringContainsString('2024-10-21', (string) $flashed,
            'الحقلُ غيرُ السرّيِّ لم يعُد — والمستخدمُ يُعاقَب بإعادةِ كتابةِ النموذج');
    }

    /** ولا يُسرَّب السرُّ في رسالةِ خطأٍ من البوّابة */
    public function test_رفضُ_البوّابةِ_لا_يُسرِّب_السرَّ_إلى_الشاشة(): void
    {
        // جسمُ الردِّ يردّد المفتاحَ — وهو ما تفعله بوّاباتٌ حقيقيّةٌ عند ٤٠١
        Http::fake(['*' => Http::response(['detail' => 'invalid key: ' . self::PLANTED], 401)]);

        $this->actingAs($this->owner)->stepped()->post('/admin/ai/providers', [
            'catalog_key' => 'openai', 'f' => ['api_key' => self::PLANTED],
        ])->assertRedirect();

        $html = $this->actingAs($this->owner)->get('/admin/ai/providers')->getContent();
        $this->assertMaskedValueAbsent(self::PLANTED, (string) $html,
            '**تسريب**: رسالةُ خطأِ البوّابةِ حملت المفتاحَ إلى الشاشة');
    }

    /** والشاشةُ تعرض اسمَ الاعتمادِ مرجعاً — فهو ليس سرّاً ويُقرأ للتصالح */
    public function test_الشاشةُ_تعرض_المرجعَ_لا_القيمة(): void
    {
        $p = $this->seedProvider();

        $this->actingAs($this->owner)->get('/admin/ai/providers')->assertOk()
            ->assertSee((string) $p->credential_name)
            ->assertSee('اعتمادٌ مضبوطٌ ولم يُختبر');
    }
}
