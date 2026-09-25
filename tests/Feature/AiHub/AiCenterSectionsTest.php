<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Center\AiAccess;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Gateway\AiUsage;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ الذكاء — سبعةُ أقسامٍ وبابان** (المرحلة ٢ · W8 · §١٠ · §١١).
 *
 * وأثقلُ ما هنا ثلاثةُ حرّاس:
 *
 *  ① **`aiView` يقرأ ولا يكتب** — ولا يُرقَّى بالصمتِ إلى إدارة.
 *  ② **شرطُ العرضِ = شرطُ الباب** — قسمٌ يُعرَض في الشريطِ يُفتَح، وزرٌّ
 *     يُعرَض يُنفَّذ. **وزرٌّ يُصَدُّ ٤٠٣ أسوأُ من غيابِه**: يَعِد بقدرةٍ لا
 *     يملكها صاحبُه، فيظنّ العطلَ في النظامِ لا في صلاحيّتِه.
 *  ③ **لا سرَّ في أيِّ قسمٍ من السبعة** — زرعاً وإثباتاً.
 */
class AiCenterSectionsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-W8CENTER4d9e2f7a11bb5533cc88';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
    }

    private function actor(array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ' . Str::random(6), 'scope' => 'all',
            'flags' => $flags, 'matrix' => []]);

        return User::create(['name' => 'مُختبِر', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-w8-' . substr(sha1(self::SECRET), 0, 10),
            'credential_state' => 'configured',
        ]);
    }

    private function model(AiProvider $p, string $name = 'alpha', bool $enabled = true): AiModel
    {
        return AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => $name,
            'upstream_model' => 'fake/' . $name, 'display_name' => $name,
            'enabled' => $enabled,
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [], 'pricing' => [],
        ]);
    }

    /**
     * **الأقسامُ التسعةُ بمساراتِها وترتيبِها** — مكتوبةً باليدِ لا مقروءةً من المصدر.
     *
     * فاختبارٌ يقرأ `sections()` ويؤكّد أنّها تساوي نفسَها لا يُثبت شيئاً.
     * **والنسخةُ الثانيةُ هنا هي الشاهد**، وأيُّ انحرافٍ بينهما يُسقط الحزمة.
     *
     * وسبعةٌ صارت تسعةً في المرحلة ٤ (السياساتُ والميزانيّات) — **إضافةٌ
     * مُعلَنةٌ في الحارسِ لا تمريرٌ بتوسيعِ عدّاد**.
     */
    private static function routes(): array
    {
        return ['ai.index', 'ai.providers.index', 'ai.models.all',
                'ai.profiles.index', 'ai.policies.index', 'ai.budgets.index',
                'ai.usage', 'ai.settings', 'ai.diagnostics'];
    }

    // ═══ ① الرايةُ الجديدةُ: يقرأ ولا يكتب ═══

    public function test_الأقسامُ_تسعةٌ_ولا_عاشرَ(): void
    {
        $this->assertCount(9, AiAccess::sections($this->owner),
            '**عددُ الأقسامِ انحرف** — تسعةٌ لا أكثرَ ولا أقلّ');
        $this->assertSame(self::routes(),
            array_column(AiAccess::sections($this->owner), 'route'));
    }

    public function test_حاملُ_الاطّلاعِ_يقرأ_الأقسامَ_المفتوحةَ_له(): void
    {
        $u = $this->actor(['aiView' => 1]);

        foreach (['ai.index', 'ai.providers.index', 'ai.models.all',
                  'ai.profiles.index', 'ai.diagnostics'] as $r) {
            $this->actingAs($u)->get(route($r))->assertOk();
        }
    }

    /** **ولا يكتب حرفاً** — كلُّ مسارِ كتابةٍ يُصَدّ */
    public function test_حاملُ_الاطّلاعِ_لا_يكتب_شيئاً(): void
    {
        $u = $this->actor(['aiView' => 1]);
        $p = $this->provider();
        $m = $this->model($p);
        AiProfiles::seed();
        $g = AiProfile::query()->where('key', 'general')->firstOrFail();

        $writes = [
            ['post',   route('ai.save'),                     []],
            ['post',   route('ai.forget'),                   []],
            ['post',   route('ai.test'),                     []],
            ['post',   route('ai.providers.store'),          ['catalog_key' => 'openai']],
            ['post',   route('ai.providers.toggle', $p),     ['enabled' => 0]],
            ['post',   route('ai.providers.revoke', $p),     []],
            ['delete', route('ai.providers.destroy', $p),    []],
            ['post',   route('ai.providers.probe', $p),      ['mode' => 'chat', 'ack' => 1]],
            ['post',   route('ai.models.discover', $p),      []],
            ['post',   route('ai.models.import', $p),        ['models' => ['alpha']]],
            ['post',   route('ai.models.refresh', $p),       []],
            ['post',   route('ai.models.toggle', $m),        ['enabled' => 0]],
            ['post',   route('ai.models.configure', $m),     ['display_name' => 'س']],
            ['post',   route('ai.models.override', $m),      ['group' => 'capabilities', 'key' => 'vision', 'value' => '1']],
            ['post',   route('ai.models.probe', $m),         ['level' => 'C']],
            ['post',   route('ai.profiles.seed'),            []],
            ['post',   route('ai.profiles.attach', $g),      ['model_id' => $m->id]],
            ['post',   route('ai.profiles.toggle', $g),      ['enabled' => 0]],
        ];

        foreach ($writes as [$verb, $uri, $payload]) {
            $this->actingAs($u)->{$verb}($uri, $payload)
                ->assertForbidden("**تسريبُ كتابة**: حاملُ الاطّلاعِ نفذ إلى {$uri}");
        }

        $this->assertSame('configured', (string) $p->fresh()->credential_state);
        $this->assertTrue($m->fresh()->enabled, 'النموذجُ تغيّر بيدِ قارئ');
    }

    /** وموظّفٌ بلا رايةٍ يُصَدُّ عن الأقسامِ السبعةِ جميعاً */
    public function test_موظّفٌ_بلا_رايةٍ_يُصَدُّ_عن_السبعة(): void
    {
        foreach (self::routes() as $r) {
            $this->actingAs($this->employee)->get(route($r))
                ->assertForbidden("القسمُ {$r} انفتح لموظّفٍ بلا راية");
        }
    }

    // ═══ ② شرطُ العرضِ = شرطُ الباب ═══

    /**
     * **كلُّ قسمٍ يُعلنه الشريطُ يُفتَح فعلاً، وكلُّ محجوبٍ يُصَدّ.**
     *
     * والفحصُ يمرّ على **أربعِ شخصيّاتٍ** لا على واحدة: المالكُ والمديرُ
     * والقارئُ والمراقبُ الماليّ. فبوّابةٌ تصحّ لشخصيّةٍ وتنحرف لأخرى هي
     * بالضبط ما يمرّ من اختبارٍ بشخصيّةٍ واحدة.
     */
    public function test_كلُّ_قسمٍ_معروضٍ_يُفتَح_وكلُّ_محجوبٍ_يُصَدّ(): void
    {
        $cast = [
            'المالك'        => $this->owner,
            'مديرُ الذكاء'  => $this->actor(['aiAdmin' => 1]),
            'القارئ'        => $this->actor(['aiView' => 1]),
            'مراقبٌ ماليّ'  => $this->actor(['aiView' => 1, 'finAnalytics' => 1]),
        ];

        foreach ($cast as $who => $u) {
            foreach (AiAccess::sections($u) as $s) {
                $status = $this->actingAs($u)->get(route($s['route']))->getStatusCode();

                $s['ok']
                    ? $this->assertSame(200, $status,
                        "**{$who}**: القسمُ «{$s['label']}» يُعرَض ويُصَدّ")
                    : $this->assertSame(403, $status,
                        "**{$who}**: القسمُ «{$s['label']}» محجوبٌ في الشريطِ ومفتوحٌ بالعنوان");
            }
        }
    }

    /** **والإعداداتُ والاستهلاكُ محجوبان عن القارئ** — بابان مختلفان */
    public function test_الإعداداتُ_والاستهلاكُ_ليسا_للقارئ(): void
    {
        $u = $this->actor(['aiView' => 1]);

        $this->actingAs($u)->get(route('ai.settings'))->assertForbidden();
        $this->actingAs($u)->get(route('ai.usage'))->assertForbidden();
    }

    /** ورقابةُ التكلفةِ القائمةُ تفتح الاستهلاك — **ولا رايةَ سادسةَ تُخترَع** */
    public function test_الاستهلاكُ_يُفتَح_برقابةِ_التكلفةِ_القائمة(): void
    {
        $this->actingAs($this->actor(['aiView' => 1, 'finAnalytics' => 1]))
            ->get(route('ai.usage'))->assertOk();

        $this->actingAs($this->actor(['aiView' => 1, 'monitor' => 1]))
            ->get(route('ai.usage'))->assertOk();
    }

    /** **وزرُّ الكتابةِ لا يُعرَض لمن يُصَدُّ عنه** */
    public function test_أزرارُ_الكتابةِ_لا_تُعرَض_للقارئ(): void
    {
        $p = $this->provider();
        $this->model($p);
        AiProfiles::seed();

        $reader = $this->actor(['aiView' => 1]);
        $admin  = $this->actor(['aiAdmin' => 1]);

        $pairs = [
            ['ai.providers.index', 'إضافةُ مزوّد'],
            ['ai.profiles.index',  'زرعُ الأغراضِ الناقصة'],
        ];

        foreach ($pairs as [$route, $needle]) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee($needle, false);
            $this->actingAs($reader)->get(route($route))->assertOk()->assertDontSee($needle, false);
        }
    }

    /** **وحالةُ الاعتمادِ لا تُعرَض للقارئ** (§١٠) — خريطةُ من يملك المفاتيح */
    public function test_حالةُ_الاعتمادِ_محجوبةٌ_عن_القارئ(): void
    {
        $p = $this->provider();

        $this->actingAs($this->actor(['aiAdmin' => 1]))->get(route('ai.providers.index'))
            ->assertOk()->assertSee('اعتمادٌ مضبوطٌ ولم يُختبر', false);

        $reader = $this->actingAs($this->actor(['aiView' => 1]))
            ->get(route('ai.providers.index'))->assertOk();

        $reader->assertDontSee('اعتمادٌ مضبوطٌ ولم يُختبر', false);
        $reader->assertDontSee($p->credential_name, false);
    }

    // ═══ ③ لا سرَّ في أيِّ قسم ═══

    public function test_لا_سرَّ_في_أيٍّ_من_الأقسامِ_السبعة(): void
    {
        Settings::put('ai.gateway_key', self::SECRET, 'test');
        $p = $this->provider();
        $this->model($p);
        AiProfiles::seed();

        foreach (self::routes() as $r) {
            $html = $this->actingAs($this->owner)->get(route($r))->assertOk()->getContent();
            $this->assertMaskedValueAbsent(self::SECRET, (string) $html,
                "**تسريب**: السرُّ ظهر في القسمِ {$r}");
        }
    }

    // ═══ الصدقُ في «نظرة» ═══

    /** **الحالةُ الفارغةُ تقول الخطوةَ التالية** لا «لا توجد بيانات» */
    public function test_النظرةُ_تقول_الخطوةَ_التاليةَ_لا_لا_توجد_بيانات(): void
    {
        $html = $this->actingAs($this->owner)->get(route('ai.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('لا توجد بيانات', (string) $html,
            'جملةٌ تُنهي المحادثةَ ولا تبدأ عملاً');
        $this->assertStringContainsString('الخطوةُ التالية', (string) $html);
    }

    /** وترتيبُ الخطواتِ ترتيبُ الاعتماد — أوّلُ ناقصٍ هو المعروض */
    public function test_الخطوةُ_التاليةُ_تتدرّج_مع_اكتمالِ_التهيئة(): void
    {
        Settings::put('ai.gateway_url', '', 'test');
        Settings::put('ai.gateway_key', '', 'test');
        $this->actingAs($this->owner)->get(route('ai.index'))->assertOk()
            ->assertSee('ابدأ بضبطِ عنوانِ البوّابة', false);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        $this->actingAs($this->owner)->get(route('ai.index'))->assertOk()
            ->assertSee('اختبر الاتصالَ الآن', false);
    }

    /** **وطابورُ الانتباهِ يرتّب المانعَ قبل التحذير** */
    public function test_طابورُ_الانتباهِ_يرتّب_بالشدّةِ_لا_بالزمن(): void
    {
        $p = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'بلا اعتماد', 'enabled' => true,
            'credential_name' => 'hub-noc-' . Str::random(8), 'credential_state' => 'missing',
        ]);
        AiProfiles::seed();   // أغراضٌ مُفعَّلةٌ بسلاسلَ فارغة ⇒ تحذيرات

        $rows = \App\Support\Ai\Center\AiOverview::attention();
        $this->assertNotSame([], $rows);

        $seenWarn = false;
        foreach ($rows as $r) {
            if ($r['severity'] === 'warn' || $r['severity'] === 'info') $seenWarn = true;
            if ($r['severity'] === 'blocker') {
                $this->assertFalse($seenWarn,
                    '**مانعٌ بعد تحذير** — وصفٌّ خطيرٌ بين هيّنين يضيع');
            }
        }

        $titles = array_column($rows, 'title');
        $this->assertContains('مزوّدٌ مُشغَّلٌ بلا اعتماد: ' . $p->label, $titles);
    }

    /** وغرضٌ جاهزٌ ما سلسلتُه صالحةٌ — لا ما هو موجود */
    public function test_الغرضُ_الجاهزُ_ما_سلسلتُه_صالحة(): void
    {
        AiProfiles::seed();
        $this->assertSame(0, \App\Support\Ai\Center\AiOverview::counts()['profiles_ready'],
            '**غرضٌ بسلسلةٍ فارغةٍ عُدَّ جاهزاً** — والشاشةُ تطمئنّ حيث تُنذر');

        $g = AiProfile::query()->where('key', 'general')->firstOrFail();
        AiProfiles::attach($g, $this->model($this->provider()));

        $this->assertSame(1, \App\Support\Ai\Center\AiOverview::counts()['profiles_ready']);
    }

    // ═══ التشخيص ═══

    /**
     * **أوّلُ حلقةٍ مقطوعةٍ تُوقِف القراءة** — وما بعدَها لا يُقرَأ دليلاً.
     *
     * والحالةُ المُختبَرةُ هي الحقيقيّةُ التي بُنيت الشاشةُ لأجلِها: **بوّابةٌ
     * مُهيَّأةٌ لم يُثبَت أنّها تردّ**. وليست «بلا عنوانٍ محفوظ» — تلك
     * `unknown` لا `broken`، ولا تُوقِف شيئاً لأنّه لا شيءَ يُقاس أصلاً.
     * **والفرقُ بينهما هو الفرقُ بين «لم نبدأ» و«بدأنا وانقطع».**
     */
    public function test_الحلقةُ_المقطوعةُ_توقِف_قراءةَ_ما_بعدَها(): void
    {
        Settings::put('ai.probe_ok', false, 'test');   // مُهيَّأةٌ ولم تُثبِت أنّها تردّ
        $this->provider();   // مزوّدٌ موجودٌ — ولا يُقرَأ ما دامت البوّابةُ مقطوعة

        $chain  = \App\Support\Ai\Center\AiDiagnostics::chain();
        $halted = false;

        foreach ($chain as $link) {
            if ($halted) {
                $this->assertSame('unknown', $link['state'],
                    '**حلقةٌ قُرِئت بعد قطع** — و«الاعتمادُ سليم» حينئذٍ كذبةٌ مطمئنّة');
            }
            if ($link['halts']) $halted = true;
        }

        $this->assertTrue($halted, 'بوّابةٌ بلا عنوانٍ ولم تُعَدّ حلقةً مقطوعة');
    }

    public function test_شاشةُ_التشخيصِ_تعرض_السلسلةَ_الرباعيّة(): void
    {
        $this->actingAs($this->owner)->get(route('ai.diagnostics'))->assertOk()
            ->assertSee('سلسلةُ الاعتماد', false)
            ->assertSee('بوّابةُ النماذج', false)
            ->assertSee('اعتماداتُ المزوّدين', false);
    }

    // ═══ الاستهلاك — ولا جدولَ في Hub ═══

    public function test_شاشةُ_الاستهلاكِ_تقول_إنّ_التكلفةَ_تقديريّة(): void
    {
        /*
         * **الوعدُ تغيّر في المرحلة ٤ ولم يَضعُف.**
         *
         * كانت الشاشةُ تقول «التكلفةُ تقديريّةٌ لا مفوترة» و«لا جدولَ استهلاكٍ
         * في Hub» — وكلاهما صارا **ناقصَين لا كاذبَين**: صار في Hub سجلُّ
         * حوكمةٍ (لا نسخةٌ من الفاتورة)، وصارت الكلفةُ **أربعَ درجاتٍ** لا
         * درجةً واحدة. فالحارسُ يُطالب بالوعدِ الأدقّ: أن تقول الشاشةُ
         * **من أين جاء كلُّ رقم**، و**أنّ المجهولَ ليس صفراً**.
         */
        $this->actingAs($this->owner)->get(route('ai.usage'))->assertOk()
            ->assertSee('كلُّ رقمٍ يقول من أين جاء', false)
            ->assertSee('المجهولُ ليس صفراً', false)
            ->assertSee(\App\Support\Ai\Governance\AiCost::REPORTED, false)
            ->assertSee(\App\Support\Ai\Governance\AiCost::UNKNOWN, false);
    }

    /** **ولا تتّصل بالبوّابةِ مع فتحِ الصفحة** */
    public function test_الاستهلاكُ_لا_يتّصل_إلّا_بطلبٍ_صريح(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $this->actingAs($this->owner)->get(route('ai.usage'))->assertOk();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    // ═══ مفاتيحُ النسبة — غيرُ شخصيّةٍ بالبناء ═══

    /**
     * **لا اسمَ ولا بريدَ ولا معرّفَ خامّ في حمولةِ النسبة.**
     *
     * وزرعُ الثلاثةِ ثمّ البحثُ عنها أصدقُ من قراءةِ الشيفرة: صيغةٌ تتغيّر غداً
     * وتُسرّب، والاختبارُ يمسك التغيّر.
     */
    public function test_مفاتيحُ_النسبةِ_لا_تحمل_هويّة(): void
    {
        $u    = $this->actor([]);
        $meta = AiUsage::metadata('ask-hub', $u, 'company-777123');
        $blob = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $this->assertSame(AiUsage::ATTRIBUTION, array_keys($meta));

        foreach ([(string) $u->id, (string) $u->email, (string) $u->name, 'company-777123'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $blob,
                '**تسريبُ هويّة**: قيمةٌ خامٌّ في حمولةِ النسبة');
        }

        $this->assertSame('ask-hub', $meta['hub_feature']);
        $this->assertSame(AiUsage::REF_LEN, mb_strlen((string) $meta['hub_user_ref']));
    }

    /** والبصمةُ ثابتةٌ للقيمةِ الواحدةِ — وإلّا لا يصحّ التجميع */
    public function test_البصمةُ_ثابتةٌ_وتفرّق_بين_القيم(): void
    {
        $this->assertSame(AiUsage::ref('a-1'), AiUsage::ref('a-1'));
        $this->assertNotSame(AiUsage::ref('a-1'), AiUsage::ref('a-2'));
        $this->assertNull(AiUsage::ref(''), 'قيمةٌ فارغةٌ أنتجت بصمةً — وهي ليست هويّة');
    }

    // ═══ الرايةُ في كتالوجِ الأدوارِ وفي الشريط ═══

    public function test_الرايةُ_الجديدةُ_معروضةٌ_في_شاشةِ_الأدوار(): void
    {
        $this->assertArrayHasKey('aiView',
            \App\Http\Controllers\Web\RoleController::FLAGS,
            '**رايةٌ تُفرَض ولا تُمنَح**: `aiView` تحرس ولا تظهر في شاشةِ الأدوار');
    }

    /** **ورابطُ الشريطِ يظهر لحاملِها** — شرطُ العرضِ = شرطُ الباب */
    public function test_رابطُ_الشريطِ_يظهر_لحاملِ_الاطّلاع(): void
    {
        $u = $this->actor(['aiView' => 1]);

        $links = collect(hub_admin_links($u))->firstWhere('key', 'ai');
        $this->assertNotNull($links);
        $this->assertTrue((bool) $links['ok'],
            'الرابطُ محجوبٌ عمّن يُفتَح له الباب — ميزةٌ مخفيّة');

        $this->assertFalse((bool) collect(hub_admin_links($this->employee))
            ->firstWhere('key', 'ai')['ok'],
            'الرابطُ ظاهرٌ لمن يُصَدُّ عنه');
    }
}
