<?php

namespace Tests\Feature\AiHub;

use App\Support\AiAuthSchemas;
use App\Support\AiCatalog;
use App\Support\AiProviderCoverage;
use App\Support\AiProviderRegistry;
use App\Support\AiProviders;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **اختباراتُ عقدِ التغطيةِ الكاملة** — المرحلة ٢ · إغلاق.
 *
 * ما تُثبته هذه الحزمةُ ليس أنّ الشيفرةَ تعمل، بل أنّ **الوعدَ صحيح**:
 * أنّ كلَّ مزوّدٍ يُصنَّف، وأنّ كلَّ مخطَّطٍ يُعرَض، وأنّ **لا سرَّ يستقرّ في
 * Hub**، وأنّ نهايةً يكتبها مستخدِمٌ لا تصير باباً إلى شبكةٍ داخليّة، وأنّ
 * مزوّداً لم يُقَس قطّ **يعمل** بلا تغييرِ شيفرة.
 */
class AiProviderCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'sk-COV-PLANTED-9f31c7ab5520de84';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    private function gatewayOk(): void
    {
        Http::fake(['*' => Http::response(['credential_name' => 'ok'], 200)]);
    }

    // ═══ ① كلُّ مزوّدٍ معروفٍ يُصنَّف ═══

    public function test_كلُّ_مزوّدٍ_في_القياسِ_له_حالةٌ_من_الأربع(): void
    {
        $rows = AiProviderCoverage::matrix();
        $this->assertGreaterThan(100, count($rows), 'المصفوفةُ لم تُبنَ');

        foreach ($rows as $r) {
            $this->assertContains($r['status'], AiProviderRegistry::STATUSES,
                "[{$r['slug']}] حالةٌ خارجَ الأربع");
        }
    }

    public function test_لا_مزوّدَ_غيرَ_قابلٍ_للإعدادِ_بلا_سببٍ_مقروء(): void
    {
        foreach (AiProviderCoverage::byStatus('NOT_CONFIGURABLE_FROM_HUB') as $r) {
            $this->assertNotNull($r['reason'], "[{$r['slug']}] استثناءٌ بلا سبب");
            $text = AiProviderCoverage::reasonText($r['reason']);
            $this->assertNotSame('بلا سببٍ مسجَّل', $text, "[{$r['slug']}] سببٌ لا يُقرأ");
            $this->assertNotSame($r['reason'], $text,
                "[{$r['slug']}] السببُ رمزٌ خامٌّ بلا ترجمةٍ للقارئ — و«لم يُضَف بعد» تختبئ خلف رمزٍ لا يُقرأ");
        }
    }

    public function test_المصفوفةُ_تطابق_القياسَ_عدداً(): void
    {
        $files = glob(base_path('deploy/litellm/measured/*.json')) ?: [];
        sort($files);
        $measured = json_decode((string) file_get_contents((string) end($files)), true);

        $this->assertSame((int) $measured['provider_count'], count(AiProviderCoverage::matrix()),
            'المصفوفةُ تصف عدداً غيرَ الذي قاسه المقياس');
    }

    // ═══ ② كلُّ مخطَّطِ مصادقةٍ يُعرَض صحيحاً ═══

    public function test_كلُّ_عائلةٍ_تُنتج_حقولاً_صالحةً_بمُصادِقِ_الكتالوج(): void
    {
        $this->assertSame([], AiAuthSchemas::validate());
        $this->assertNotEmpty(AiAuthSchemas::names());

        foreach (AiAuthSchemas::names() as $family) {
            $fields = AiAuthSchemas::fields($family);
            $this->assertNotEmpty($fields, "[$family] بلا حقول");
            foreach ($fields as $f) {
                $this->assertArrayHasKey('sends_to', $f, "[$family] حقلٌ بلا وجهة");
                $this->assertContains($f['sends_to'], AiCatalog::DESTINATIONS, "[$family]");
            }
        }
    }

    public function test_كلُّ_مزوّدٍ_قابلٍ_للإعدادِ_يُنتج_مدخلاً_صالحاً(): void
    {
        $all = AiCatalog::all();
        $this->assertGreaterThan(100, count($all), 'الكتالوجُ لم يتوسّع');
        $this->assertSame([], AiCatalog::validate(), 'الكتالوجُ الموسَّعُ غيرُ سليم');

        foreach ($all as $k => $entry) {
            $this->assertNotEmpty(AiCatalog::fields($k), "[$k] بلا حقول");
            $this->assertNotEmpty(AiCatalog::requiredFields($k), "[$k] بلا حقلٍ إلزاميٍّ واحد");
        }
    }

    public function test_غيرُ_القابلِ_للإعدادِ_لا_يظهر_في_الكتالوجِ_ولا_يُوصَف(): void
    {
        $slugs = [];
        foreach (AiCatalog::all() as $entry) $slugs[(string) $entry['litellm_key']] = true;

        foreach (AiProviderCoverage::byStatus('NOT_CONFIGURABLE_FROM_HUB') as $r) {
            $this->assertArrayNotHasKey($r['slug'], $slugs,
                "[{$r['slug']}] غيرُ قابلٍ للإعدادِ ومع ذلك يُعرَض في الكتالوج");
            $this->assertNull(AiProviderRegistry::describe($r['slug']),
                "[{$r['slug']}] السجلُّ يصفه وهو غيرُ قابلٍ للإعداد");
        }
    }

    // ═══ ③ لا سرَّ في Hub ═══

    public function test_لا_حقلَ_سرّيٍّ_وجهتُه_إعدادُ_Hub_في_مزوّدٍ_واحد(): void
    {
        foreach (AiCatalog::all() as $k => $entry) {
            foreach (AiCatalog::fields($k) as $f) {
                if (empty($f['secret'])) continue;
                $this->assertSame('credential', $f['sends_to'],
                    "[$k][{$f['key']}] سرٌّ وجهتُه Hub — والسرُّ لا يستقرّ عندنا");
                $this->assertSame('password', $f['type'], "[$k][{$f['key']}] سرٌّ بنوعٍ مكشوف");
            }
        }
    }

    public function test_السرُّ_يمرّ_إلى_البوّابةِ_ولا_يُكتَب_في_صفِّ_Hub(): void
    {
        $this->gatewayOk();

        $r = AiProviders::add('openai_compatible', 'نهايةٌ عامّة', [
            'api_base' => 'https://gateway.example.com/v1',
            'api_key'  => self::PLANTED,
        ]);

        $this->assertTrue($r['ok'], (string) $r['error']);

        $row = json_encode(
            \Illuminate\Support\Facades\DB::table('ai_providers')->where('id', $r['provider']->id)->first(),
            JSON_UNESCAPED_UNICODE
        );
        $this->assertStringNotContainsString(self::PLANTED, (string) $row,
            'السرُّ استقرَّ في صفِّ Hub — والعقدُ أنّه يمرّ ولا يستقرّ');

        // والنهايةُ **ليست سرّاً** فتبقى مقروءةً في الاعتمادِ عند البوّابة
        $sent = false;
        Http::assertSent(function ($request) use (&$sent) {
            $body = (array) $request->data();
            if (($body['credential_values']['api_key'] ?? null) === self::PLANTED) $sent = true;

            return true;
        });
        $this->assertTrue($sent, 'السرُّ لم يصل البوّابةَ — فأين ذهب؟');
    }

    // ═══ ④ النهايةُ العامّةُ لا تفتح باباً إلى الداخل ═══

    public static function internalEndpoints(): array
    {
        return [
            'بيانات سحابة'   => ['http://169.254.169.254/latest/meta-data/'],
            'حلقة محلّيّة'    => ['http://127.0.0.1:8080/v1'],
            'شبكة خاصّة'     => ['https://10.0.0.5/v1'],
            'اسم داخليّ'      => ['https://vault.internal/v1'],
            'بروتوكول ممنوع' => ['file:///etc/passwd'],
        ];
    }

    /**
     * **حارسُ SSRF عبر البوّابة** — وهو أدقُّ ما في الوضعِ العامّ.
     *
     * النهايةُ هنا يتّصل بها **مُحرّكُ البوّابة** لا Hub، فلا تمرّ بحارسِ
     * الصادرِ في مسارِ النداء. ولو قُبلت بقاعدةِ `url` وحدَها لصار حقلُ
     * إعدادٍ باباً إلى شبكةٍ داخليّةٍ من داخلِها.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('internalEndpoints')]
    public function test_نهايةٌ_داخليّةٌ_تُرَدّ_بلا_نداء(string $url): void
    {
        Http::fake();

        $r = AiProviders::add('openai_compatible', 'محاولة', [
            'api_base' => $url,
            'api_key'  => self::PLANTED,
        ]);

        $this->assertFalse($r['ok'], "نهايةٌ داخليّةٌ قُبلت: $url");
        $this->assertNull($r['provider']);
        Http::assertNothingSent();
    }

    public function test_نهايةٌ_عامّةٌ_تُقبَل(): void
    {
        $this->gatewayOk();

        $r = AiProviders::add('openai_compatible', 'نهايةٌ عامّة', [
            'api_base' => 'https://gateway.example.com/v1',
            'api_key'  => self::PLANTED,
        ]);

        $this->assertTrue($r['ok'], (string) $r['error']);
    }

    public function test_التدويرُ_يفحص_النهايةَ_كما_يفحصها_الإنشاء(): void
    {
        $this->gatewayOk();
        $r = AiProviders::add('openai_compatible', 'نهايةٌ عامّة', [
            'api_base' => 'https://gateway.example.com/v1',
            'api_key'  => self::PLANTED,
        ]);
        $this->assertTrue($r['ok'], (string) $r['error']);

        Http::fake();
        $rot = AiProviders::rotate($r['provider'], [
            'api_base' => 'http://169.254.169.254/v1',
            'api_key'  => 'sk-COV-ROTATED-3311bbaa7788ccdd',
        ]);

        $this->assertFalse($rot['ok'], 'التدويرُ فتح ما أغلقه الإنشاء');
        Http::assertNothingSent();
    }

    // ═══ ⑤ مزوّدٌ مجهولٌ يعمل بلا تغييرِ شيفرة ═══

    public function test_مزوّدٌ_لم_يُقَس_قطُّ_يسقط_إلى_الافتراضِ_ويعمل(): void
    {
        $slug = 'provider_from_a_future_release';
        $this->assertArrayNotHasKey($slug, AiProviderRegistry::map(),
            'الاسمُ المُصطنَعُ موجودٌ في الخريطة — فالاختبارُ لا يختبر شيئاً');

        $entry = AiProviderRegistry::describe($slug);

        $this->assertNotNull($entry, 'مزوّدٌ مجهولٌ رُفض — والعقدُ أنّه يسقط إلى الافتراض');
        $this->assertSame(AiProviderRegistry::DEFAULT_AUTH, $entry['auth']);
        $this->assertSame('default', $entry['source']);
        $this->assertSame([], AiCatalog::validate(['future_one' => [
            'label'       => $entry['label'],
            'litellm_key' => $entry['litellm_key'],
            'auth'        => $entry['auth'],
            'discovery'   => $entry['discovery'],
            'fields'      => $entry['fields'],
        ]]), 'المدخلُ المشتقُّ من الافتراضِ غيرُ صالح');
    }

    public function test_إضافةُ_مزوّدٍ_جديدٍ_لا_تكسر_القائم(): void
    {
        $before = AiCatalog::all();
        $this->assertArrayHasKey('openai', $before);

        // مزوّدٌ جديدٌ يصل من البوّابةِ في ترقيةٍ لاحقة
        \Illuminate\Support\Facades\Cache::put(
            AiProviderRegistry::CACHE_KEY,
            array_merge(AiProviderRegistry::snapshot(), ['brand_new_provider']),
            now()->addMinutes(5)
        );

        $after = AiCatalog::all();

        $this->assertArrayHasKey('brand_new_provider', $after, 'الجديدُ لم يظهر');
        foreach (array_keys($before) as $k) {
            $this->assertArrayHasKey($k, $after, "[$k] اختفى بعد وصولِ مزوّدٍ جديد");
            $this->assertSame($before[$k], $after[$k], "[$k] تغيّر وصفُه بوصولِ مزوّدٍ جديد");
        }
        $this->assertSame([], AiCatalog::validate());
    }

    // ═══ ⑥ وضعُ الاكتشافِ صحيحٌ بالقياس ═══

    public function test_لا_وضعَ_اكتشافٍ_خارجَ_الثلاثة(): void
    {
        foreach (AiCatalog::all() as $k => $entry) {
            $this->assertContains($entry['discovery'] ?? null, AiCatalog::DISCOVERY_MODES, "[$k]");
        }
    }

    public function test_المجهولُ_يبقى_غيرَ_المدعوم(): void
    {
        // مزوّدٌ لم يُقَس وضعُ اكتشافِه `manual` — «لا نعلم» تُعامَل بالإضافةِ
        // اليدويّةِ التي تعمل دائماً، لا بادّعاءِ قدرةٍ ولا بمنعِ المزوّد.
        $entry = AiProviderRegistry::describe('another_unmeasured_provider');
        $this->assertNotNull($entry);
        $this->assertSame('manual', $entry['discovery']);
        $this->assertNotSame('NOT_CONFIGURABLE_FROM_HUB', $entry['status'],
            '«لا نعلم» صارت «غيرُ مدعوم» — وهما ليستا واحدة');
    }

    // ═══ ⑦ الشاشةُ تتصفّح ولا تُغرِق ═══

    private function admin(): User
    {
        $this->seedCore();
        $role = Role::create(['name' => 'دورٌ' . Str::random(6), 'scope' => 'all',
            'flags' => ['aiAdmin' => 1], 'matrix' => []]);

        return User::create(['name' => 'مُدير', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /**
     * **البطاقاتُ كلُّها، والنموذجُ واحد** — وهما شرطانِ لا واحد.
     *
     * أوّلُ تنفيذٍ خلط بينهما فقصَّ **البطاقاتِ** عند أربعٍ وعشرين وقال «ضيِّق
     * البحثَ لترى الباقي». وكان ذلك عطلاً لا احترازاً: مَن يتصفّح لا يعرف ما
     * يبحث عنه بعد، فالقطعُ يُخفي عنه المتاحَ بدل أن ينظّمه له. والإغراقُ
     * الحقيقيُّ عددُ **النماذجِ** المفتوحةِ معاً لا عددُ البطاقات.
     */
    public function test_الشاشةُ_تعرض_كلَّ_المزوّدين_ونموذجاً_واحداً(): void
    {
        $html = (string) $this->actingAs($this->admin())->get(route('ai.providers.index'))
            ->assertOk()->getContent();

        // ① نموذجٌ واحدٌ على الأكثر — لا مئةٌ وستّةٌ وعشرون
        $forms = substr_count($html, 'name="catalog_key"');
        $this->assertLessThanOrEqual(1, $forms,
            "الشاشةُ تعرض {$forms} نموذجَ إعدادٍ دفعةً واحدة — وهذا إغراقٌ لا تغطية");

        // ② وكلُّ مزوّدٍ قابلٍ للإعدادِ له بطاقةٌ — بلا قطعٍ ولا «ضيِّق البحث»
        $cards = substr_count($html, 'class="pvcard');
        $this->assertSame(count(AiCatalog::all()), $cards,
            "ظهر {$cards} بطاقةً من " . count(AiCatalog::all()) . ' — والقطعُ يُخفي المتاحَ لا ينظّمه');

        foreach (array_keys(AiCatalog::all()) as $key) {
            $this->assertStringContainsString('add=' . rawurlencode($key), $html,
                "[$key] غائبٌ عن الشبكة");
        }

        $this->assertStringContainsString('name="q"', $html, 'لا حقلَ بحثٍ في الشاشة');
        $this->assertStringContainsString('name="auth"', $html, 'لا تصفيةَ بشكلِ المصادقة');
    }

    public function test_لكلِّ_مزوّدٍ_علامةٌ_ثابتةٌ_مشتقّةٌ_من_اسمِه(): void
    {
        $a = AiProviderRegistry::mark('some_provider');
        $b = AiProviderRegistry::mark('some_provider');
        $c = AiProviderRegistry::mark('other_provider');

        $this->assertSame($a, $b, 'العلامةُ تتبدّل بين نداءين — فلونُ المزوّدِ يقفز بين صفحتين');
        $this->assertNotSame($a['hue'], $c['hue'], 'مزوّدانِ مختلفانِ بلونٍ واحد');
        $this->assertSame('SP', $a['initials']);
        $this->assertSame('OP', $c['initials']);
        $this->assertGreaterThanOrEqual(0, $a['hue']);
        $this->assertLessThan(360, $a['hue']);

        // ومزوّدٌ لم يُقَس قطُّ يأخذ علامتَه كغيرِه — لا مربّعٌ فارغ
        $new = AiProviderRegistry::mark('a_provider_from_the_future');
        $this->assertNotSame('', $new['initials']);
    }

    public function test_اختيارُ_مزوّدٍ_يفتح_نموذجَه_وحدَه(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('ai.providers.index', ['add' => 'openai_compatible']))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="catalog_key"'), 'لم يُفتَح نموذجٌ واحدٌ بالضبط');
        $this->assertStringContainsString('value="openai_compatible"', $html);
        $this->assertStringContainsString('name="f[api_base]"', $html, 'الحقولُ لم تُبنَ من العائلة');
    }

    public function test_مفتاحٌ_غيرُ_معروفٍ_في_العنوانِ_لا_يبني_نموذجاً(): void
    {
        $html = (string) $this->actingAs($this->admin())
            ->get(route('ai.providers.index', ['add' => '../../etc/passwd']))
            ->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'name="catalog_key"'),
            'مفتاحٌ من العنوانِ بنى نموذجاً — والمصادقةُ على الكتالوجِ هي الحارس');
    }

    public function test_التصفيةُ_تُضيّق_القائمةَ_فعلاً(): void
    {
        $wide   = AiProviderCoverage::browse([]);
        $narrow = AiProviderCoverage::browse(['auth' => 'aws_signature']);

        $this->assertGreaterThan($narrow['total'], $wide['total'], 'التصفيةُ لم تُضيّق شيئاً');
        $this->assertGreaterThan(0, $narrow['total'], 'التصفيةُ أفرغت القائمةَ كلَّها');
        foreach ($narrow['rows'] as $r) {
            $this->assertSame('aws_signature', $r['auth']);
        }
    }

    public function test_القارئُ_لا_يملك_زرَّ_تحديثِ_القائمة(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'قارئٌ' . Str::random(6), 'scope' => 'all',
            'flags' => ['aiView' => 1], 'matrix' => []]);
        $reader = User::create(['name' => 'قارئ', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $html = (string) $this->actingAs($reader)->get(route('ai.providers.index'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('ai.providers.refresh'), $html,
            'زرٌّ يُعرَض للقارئِ ثمّ يُصَدُّ — شرطُ العرضِ = شرطُ الباب');

        // **وشرطُ البابِ يُختبَر لا يُفترَض**
        $this->actingAs($reader)->post(route('ai.providers.refresh'))->assertForbidden();
    }
}
