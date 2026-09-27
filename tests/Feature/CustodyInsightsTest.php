<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Asset;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Assets\Custody;
use App\Support\Assets\CustodyFacts;
use App\Support\Assets\CustodyInsights;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **كاتالوج العهد: صفحةٌ لكلِّ نوع عهدة، مدروسةٌ بالذكاء** (`CustodyFacts` · `CustodyInsights`).
 *
 *  1) الحقائقُ الحتميّة صحيحةٌ عدّاً عدّاً — بلا نموذج.
 *  2) بنطاق القارئ: مستخدمُ شركةٍ لا يرى أعدادَ شركةٍ أخرى، ولا التحليلَ العامّ المحسوبَ على الكلّ؛
 *     والحقلُ المحجوبُ يُسقط حقيقتَه؛ والعميلُ لا يرى التحليلَ أبداً.
 *  3) النداءُ المحكوم: لا يغادر اسمُ حائزٍ ولا IP ولا سيريال؛ ولا نداءَ والإعدادُ مطفأ أو البصمةُ لم تتغيّر؛
 *     والإخفاقُ يُبقي التحليلَ السابق.
 *  4) «تحديث التحليل»: صلاحيّةٌ + حدُّ معدّل + قيدُ تدقيق.
 */
class CustodyInsightsTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    private mixed $reply = null;

    private Company $alpha;

    private Company $beta;

    private array $good = [
        'summary' => 'أسطولُ السيرفرات متوسّطُ العمر وفيه ضمانٌ يوشك أن ينتهي.',
        'risks' => ['ضمانُ سيرفرٍ ينتهي قريباً', 'تركّزُ عهدتين عند حائز 1'],
        'recommendations' => ['جدولةُ استبدالِ الأقدم'],
        'data_gaps' => ['تاريخُ الشراء غيرُ مسجَّلٍ لسيرفرٍ واحد'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->beta = Company::create(['name_ar' => 'شركةُ باء']);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-cust-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);

        $this->reply = $this->good;
        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $text = is_string($this->reply) ? $this->reply : json_encode($this->reply, JSON_UNESCAPED_UNICODE);

            return Http::response(LiteLlmFixtures::answer($text, LiteLlmFixtures::usage()), 200);
        });
    }

    private function on(): void
    {
        $this->hubSetting('custody.ai_insights', '1');
    }

    private function sentText(): string
    {
        return json_encode($this->sent, JSON_UNESCAPED_UNICODE);
    }

    private function member(array $companies = [], array $matrix = ['assets' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]],
                            array $fieldRules = [], string $type = 'internal'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . Str::random(6), 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'عضو ' . Str::random(4), 'email' => Str::random(9) . '@cust.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies, 'account_type' => $type]);
    }

    private function asset(array $a): Asset
    {
        return Asset::create($a + ['type' => 'سيرفر', 'company_id' => $this->alpha->id]);
    }

    /** خمسةُ سيرفراتٍ في «ألِف» بحالاتٍ وحائزين وضماناتٍ معلومة، وسيرفرٌ في «باء»، ولابتوبٌ خارج الصنف */
    private function fleet(): array
    {
        $h1 = $this->member([$this->alpha->id]);
        $h1->forceFill(['name' => 'زيدُ الحائز'])->save();
        $today = now()->startOfDay();

        $s = [];
        $s[] = $this->asset(['name' => 'خادم الملفات', 'status' => 'قيد الاستخدام', 'holder_id' => $h1->id,
            'buy_date' => $today->copy()->subYears(6)->toDateString(), 'warranty' => $today->copy()->subDays(10)->toDateString(),
            'specs' => ['cpu' => 'Xeon Silver 4210', 'ram' => '64GB', 'ip' => '10.20.30.199']]);
        $s[] = $this->asset(['name' => 'خادم النسخ', 'status' => 'قيد الاستخدام', 'holder_id' => $h1->id,
            'buy_date' => $today->copy()->subYears(2)->toDateString(), 'warranty' => $today->copy()->addDays(30)->toDateString(),
            'specs' => ['cpu' => 'EPYC 7302']]);
        $s[] = $this->asset(['name' => 'خادم البريد', 'status' => 'صيانة',
            'buy_date' => $today->copy()->subMonths(3)->toDateString(), 'warranty' => $today->copy()->addYears(2)->toDateString()]);
        $s[] = $this->asset(['name' => 'خادم الاختبار', 'status' => 'متاح']);
        $s[] = $this->asset(['name' => 'خادمٌ مستبعد', 'status' => 'مستبعد',
            'warranty' => $today->copy()->subYears(3)->toDateString()]);
        $foreign = $this->asset(['name' => 'QZX-FOREIGN-SERVER', 'status' => 'تالف', 'company_id' => $this->beta->id]);
        $this->asset(['name' => 'لابتوب خارج الصنف', 'type' => 'لابتوب', 'status' => 'متاح']);

        return ['servers' => $s, 'foreign' => $foreign, 'holder' => $h1];
    }

    // ═══ ١) الحقائق ═══

    public function test_الحقائقُ_الحتميّةُ_صحيحةٌ_عدّاً_عدّاً(): void
    {
        $f = $this->fleet();
        DB::table('asset_custody')->insert(['id' => (string) Str::uuid(), 'asset_id' => $f['servers'][0]->id,
            'user_id' => $f['holder']->id, 'action' => 'تسليم', 'at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner);

        $q = Custody::scoped();
        $this->assertTrue(CustodyFacts::filterType($q, 'SV'));
        $x = CustodyFacts::compute($q, $this->owner, 'SV');

        $this->assertSame(6, $x['total'], 'ستّةُ سيرفرات (المالك يرى الشركتين) — واللابتوب خارج الصنف');
        $this->assertSame(5, $x['active'], 'المستبعدُ خارج الخدمة');
        $this->assertSame(2, $x['status']['قيد الاستخدام']);
        $this->assertSame(1, $x['status']['صيانة']);
        $this->assertSame(1, $x['status']['متاح']);
        $this->assertSame(1, $x['status']['مستبعد']);
        $this->assertSame(1, $x['status']['تالف']);
        $this->assertSame(2, $x['repair']['n'], 'صيانةٌ + تالف');
        $this->assertSame(2, $x['holders']['held']);
        $this->assertSame(3, $x['holders']['unheld']);
        $this->assertSame('زيدُ الحائز', $x['holders']['top'][0]['name']);
        $this->assertSame(2, $x['holders']['top'][0]['n']);
        $this->assertSame(1, $x['warranty']['expired'], 'المستبعدُ لا يُحسب في مخاطر الضمان');
        $this->assertSame(1, $x['warranty']['expiring']);
        $this->assertSame(2, $x['warranty']['unknown']);
        $this->assertSame((string) $f['servers'][1]->code, $x['warranty']['items'][0]['code']);
        $this->assertSame(1, $x['age']['buckets']['أكثر من ٥ سنوات']);
        $this->assertSame(1, $x['age']['buckets']['١–٣ سنوات']);
        $this->assertSame(1, $x['age']['buckets']['أقل من سنة']);
        $this->assertSame(2, $x['age']['buckets']['غير معروف']);
        $this->assertSame(3, $x['specs']['empty'], 'ثلاثةٌ نشطةٌ بلا مواصفات');
        $this->assertSame(4, $x['specs']['missing']['الذاكرة (RAM)']);
        $this->assertSame(1, count($x['moves']));
        $this->assertSame('زيدُ الحائز', $x['moves'][0]['who']);
    }

    // ═══ ٢) النطاق والرؤية ═══

    public function test_مستخدمُ_شركةٍ_لا_يرى_أعدادَ_غيرها_ولا_التحليلَ_العامّ(): void
    {
        $this->fleet();
        $this->on();
        CustodyInsights::run();
        $iso = $this->member([$this->alpha->id]);

        $html = $this->actingAs($iso)->get('/custody/cat/SV')->assertOk()->getContent();

        $this->assertStringNotContainsString('QZX-FOREIGN-SERVER', $html);
        $this->actingAs($iso);
        $q = Custody::scoped();
        CustodyFacts::filterType($q, 'SV');
        $x = CustodyFacts::compute($q, $iso, 'SV');
        $this->assertSame(5, $x['total'], 'سيرفرُ «باء» لا يُعدّ');
        $this->assertArrayNotHasKey('تالف', $x['status'], 'حالةُ السيرفر الأجنبيّ لا تظهر');

        $this->assertFalse(CustodyInsights::visibleTo($iso));
        $this->assertStringNotContainsString($this->good['summary'], $html, 'التحليلُ المحسوبُ على الكلّ لا يُعرض لمعزول');
        $this->assertStringContainsString('يُعرض لمن يرى الصنفَ كلَّه', $html);

        // والمالكُ يراه
        $this->actingAs($this->owner)->get('/custody/cat/SV')->assertOk()
            ->assertSee($this->good['summary'])->assertSee('مولَّدٌ بالذكاء الاصطناعي')->assertSee('hub-general');
    }

    public function test_الحقلُ_المحجوبُ_يُسقط_حقيقتَه_ويحجب_التحليل(): void
    {
        $f = $this->fleet();
        // (الحائزُ والمحطّةُ حقلان مقفلان — «قراءةٌ فقط» للجميع لا حجب؛ فالحارسُ على الضمان وتاريخ الشراء)
        $u = $this->member([], fieldRules: ['assets' => ['warranty' => 'hide', 'buyDate' => 'hide']]);
        $this->actingAs($u);
        $q = Custody::scoped();
        CustodyFacts::filterType($q, 'SV');
        $x = CustodyFacts::compute($q, $u, 'SV');

        $this->assertArrayNotHasKey('warranty', $x);
        $this->assertArrayNotHasKey('age', $x);
        $this->assertArrayHasKey('holders', $x);
        $this->assertFalse(CustodyInsights::visibleTo($u), 'التحليلُ مبنيٌّ على الضمان — فلا يُعرض لمن حُجب عنه');
        $html = $this->get('/custody/cat/SV')->assertOk()->getContent();
        $this->assertStringNotContainsString((string) $f['servers'][1]->code . '</a> خادم النسخ —', $html);
        $this->assertStringNotContainsString('ضمانٌ منتهٍ', $html);
        $this->assertStringNotContainsString('العمر (من تاريخ الشراء)', $html);
    }

    public function test_العميلُ_لا_يرى_التحليلَ_أبداً_وغيرُ_المعزولِ_يراه(): void
    {
        $client = $this->member([], type: 'client');
        $this->assertFalse(CustodyInsights::visibleTo($client));
        $this->assertFalse(CustodyInsights::panel($client, 'SV')['show']);
        $this->assertNull(CustodyInsights::panel($client, 'SV')['note']);

        $this->assertTrue(CustodyInsights::visibleTo($this->viewer), 'داخليٌّ بلا عزلٍ ولا حجب');
        $this->assertFalse(CustodyInsights::canRefresh($this->viewer), 'العرضُ وحدَه لا يُحدِّث');
        $this->assertTrue(CustodyInsights::canRefresh($this->employee));
    }

    // ═══ ٣) النداءُ المحكوم ═══

    public function test_التوليدُ_يخزّن_التحليلَ_ولا_يُرسل_اسماً_ولا_IP_ولا_سيريال(): void
    {
        $f = $this->fleet();
        $f['servers'][0]->forceFill(['serial' => 'SN-QZX-778899'])->save();
        $this->on();

        $s = CustodyInsights::run();

        $this->assertSame('generated', $s['codes']['SV']);
        $this->assertSame('generated', $s['codes']['LT']);
        $this->assertCount(2, $this->sent, 'نداءٌ لكلِّ صنفٍ موجود');
        $row = CustodyInsights::row('SV');
        $this->assertSame('ok', $row->status);
        $this->assertSame('hub-general', $row->model);
        $this->assertSame(6, (int) $row->items);
        $an = json_decode($row->analysis, true);
        $this->assertSame($this->good['summary'], $an['summary']);
        $this->assertSame($this->good['risks'], $an['risks']);

        $sent = $this->sentText();
        $this->assertStringContainsString('Xeon Silver 4210', $sent, 'المواصفاتُ العتاديّة تصل');
        $this->assertStringContainsString('حائز 1', $sent, 'الحائزُ مجهّل');
        foreach (['زيدُ الحائز', '10.20.30.199', 'SN-QZX-778899', $f['holder']->id, $f['servers'][0]->id] as $no) {
            $this->assertStringNotContainsString($no, $sent, "لا يغادر: {$no}");
        }
        $this->assertSame(2, DB::table('ai_usage_events')->where('feature', CustodyInsights::FEATURE)->count(), 'محكومٌ ومحسوب');
    }

    public function test_لا_نداءَ_والإعدادُ_مطفأ(): void
    {
        $this->fleet();

        $s = CustodyInsights::run();
        $this->assertNotNull($s['stopped']);
        $this->assertSame([], $this->sent);
        $this->assertNull(CustodyInsights::row('SV'));
        $this->assertSame('off', CustodyInsights::refresh('SV', force: true)['action']);
        $this->assertSame([], $this->sent);

        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame([], $this->sent, 'الدورةُ اليوميّةُ لا تسأل والإعدادُ مطفأ');

        $this->actingAs($this->owner)->get('/custody/cat/SV')->assertOk()->assertSee('التحليلُ الآليّ مطفأ');
    }

    public function test_الدورةُ_اليوميّةُ_تُحلّل_والإعدادُ_مفعَّل(): void
    {
        $this->fleet();
        $this->on();

        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame('ok', CustodyInsights::row('SV')?->status);
        $n = count($this->sent);
        $this->assertGreaterThanOrEqual(2, $n);

        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertCount($n, $this->sent, 'الجولةُ الثانيةُ بلا تغيّرٍ لا تسأل');
    }

    public function test_لا_نداءَ_والبصمةُ_لم_تتغيّر_ونداءٌ_حين_تتغيّر(): void
    {
        $f = $this->fleet();
        $this->on();
        CustodyInsights::run('SV');
        $this->assertCount(1, $this->sent);
        $hash = CustodyInsights::row('SV')->facts_hash;

        $this->assertSame('unchanged', CustodyInsights::run('SV')['codes']['SV']);
        $this->artisan('hub:custody-insights', ['--type' => 'SV'])->assertExitCode(0);
        $this->assertCount(1, $this->sent, 'حقائقُ لم تتغيّر لا تُسأل مرّتين');

        $f['servers'][3]->forceFill(['holder_id' => $f['holder']->id])->save();
        $this->artisan('hub:custody-insights', ['--type' => 'SV', '--dry' => true])->assertExitCode(0);
        $this->assertCount(1, $this->sent, 'المعاينةُ لا تسأل');

        CustodyInsights::run('SV');
        $this->assertCount(2, $this->sent, 'تغيّرُ الحقائق يُعيد التوليد');
        $this->assertNotSame($hash, CustodyInsights::row('SV')->facts_hash);
    }

    public function test_الإخفاقُ_يُبقي_التحليلَ_السابق_ويُقال_بصدق(): void
    {
        $this->fleet();
        $this->on();
        CustodyInsights::run('SV');
        $before = CustodyInsights::row('SV');

        $this->reply = 'ليس JSON أصلاً';
        $r = CustodyInsights::refresh('SV', force: true);

        $this->assertSame('failed', $r['action']);
        $row = CustodyInsights::row('SV');
        $this->assertSame('failed', $row->status);
        $this->assertSame('MALFORMED_MODEL_RESPONSE', $row->error_code);
        $this->assertSame($before->analysis, $row->analysis, 'التحليلُ السابقُ باقٍ');
        $this->assertSame($before->generated_at, $row->generated_at);
        $this->assertSame($before->facts_hash, $row->facts_hash);

        $this->actingAs($this->owner)->get('/custody/cat/SV')->assertOk()
            ->assertSee($this->good['summary'])->assertSee('فشلت آخرُ محاولة');

        // والمحاولةُ الفاشلةُ تُعاد في الجولة التالية ولو لم تتغيّر الحقائق
        $this->reply = $this->good;
        $this->assertSame('generated', CustodyInsights::run('SV')['codes']['SV']);
        $this->assertSame('ok', CustodyInsights::row('SV')->status);
    }

    // ═══ ٤) «تحديث التحليل» ═══

    public function test_التحديثُ_اليدويّ_صلاحيّةٌ_وتدقيقٌ_وحدُّ_معدّل(): void
    {
        $this->fleet();
        $this->on();

        $this->actingAs($this->viewer)->post('/custody/cat/SV/insight')->assertForbidden();
        $iso = $this->member([$this->alpha->id], ['assets' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]);
        $this->actingAs($iso)->post('/custody/cat/SV/insight')->assertForbidden();
        $this->assertSame([], $this->sent, 'المرفوضُ لا يسأل');

        $keeper = $this->member([], ['assets' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0, 'custodyAssign' => 1]]);
        $this->actingAs($keeper)->from('/custody/cat/SV')->post('/custody/cat/SV/insight')
            ->assertRedirect('/custody/cat/SV')->assertSessionHas('ok');
        $this->assertCount(1, $this->sent);
        $this->assertTrue(AuditEntry::query()->where('action', CustodyInsights::AUDIT_REFRESH)->exists());

        $this->actingAs($this->employee)->post('/custody/cat/ZZ/insight')->assertNotFound();

        $codes = [];
        for ($i = 0; $i < 5; $i++) $codes[] = $this->actingAs($this->employee)->post('/custody/cat/SV/insight')->status();
        $this->assertContains(429, $codes, 'حدُّ المعدّل على المسار');
    }
}
