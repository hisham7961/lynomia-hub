<?php

namespace Tests\Feature\AskHub;

use App\Contracts\AskGenerator;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Ask\AskContext;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **شاشةُ «اسأل Hub»** (المرحلة ٣ · P3-W6).
 *
 * وأثقلُ ما هنا ثلاثةُ حرّاس:
 *
 *  ① **شرطُ العرضِ = شرطُ الباب** — رابطٌ يظهر يُفتَح، وبابٌ مغلقٌ لا رابطَ له.
 *  ② **التوافرُ يُقال ولا يُخفى** — خدمةٌ ساقطةٌ لا تُخفي الصفحةَ فيظنّ صاحبُ
 *     الصلاحيّةِ أنّه فقدها.
 *  ③ **لا يتسرّب إلى HTML** مظروفٌ ولا رقمُ سياجٍ ولا حمولةُ أداةٍ ولا سرّ،
 *     **وردُّ النموذجِ يُهرَّب** — فهو بياناتٌ غيرُ موثوقةٍ حتّى في العرض.
 */
class AskScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->company = Company::create(['name_ar' => 'شركةُ الشاشة']);
        Project::create(['name' => 'مشروعُ الشاشة 551199', 'company_id' => $this->company->id]);
    }

    private function asker(bool $flag = true): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => $flag ? [AskPolicy::FLAG => 1] : [], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => Str::random(9) . '@ask.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$this->company->id]]);
    }

    private function ready(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        Settings::put('ai.enabled', '1', 'test');
        Settings::put('ai.probe_ok', '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_ok', '1', 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');

        // **ذاكرةُ سجلِّ القدراتِ تُبطَل بعد تغييرِ الإعدادات.**
        // `FeatureRegistry::resolveAll()` يحفظ نتيجتَه في ثابتٍ ساكن — وهو
        // الصوابُ في الإنتاج (طلبٌ واحدٌ لا يُعيد اشتقاقَ مئةِ قدرة)، لكنّه
        // **يعبر بين أصنافِ الاختبارِ في العمليّةِ الواحدة**: صنفٌ سابقٌ حسم
        // «ai.assistant» وبوّابتُه غيرُ مهيّأة، فتبقى مُطفأةً هنا مهما ضبطنا.
        \App\Support\Platform\FeatureRegistry::flush();

        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-fake-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'fake-chat',
            'upstream_model' => 'fake/fake-chat', 'display_name' => 'نموذجٌ وهميّ',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [
                // **و`tools` معلَنةٌ لأنّ مسارَ المساعدِ كلَّه دورةُ أدوات** (المرحلة ٥ · W2)
                'chat'  => ['v' => true, 'src' => 'litellm'],
                'tools' => ['v' => true, 'src' => 'litellm'],
            ],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);
        AiProfiles::attach(AiProfile::query()->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }

    private function bind(array $script): ScriptedGenerator
    {
        $gen = new ScriptedGenerator($script);
        $this->app->instance(AskGenerator::class, $gen);

        return $gen;
    }

    // ═══ ① شرطُ العرضِ = شرطُ الباب ═══

    public function test_بلا_رايةٍ_لا_رابطَ_ولا_باب(): void
    {
        $u = $this->asker(false);
        $this->ready();

        $this->actingAs($u)->get(route('ask.index'))->assertForbidden();

        $links = array_column(hub_top_links($u), 'key');
        $this->assertNotContains('ask', $links, 'رابطٌ يظهر لمن يُصَدُّ عن بابِه');
    }

    public function test_بالرايةِ_رابطٌ_وبابٌ_مفتوح(): void
    {
        $u = $this->asker();
        $this->ready();

        $this->actingAs($u)->get(route('ask.index'))->assertOk();
        $this->assertContains('ask', array_column(hub_top_links($u), 'key'),
            'بابٌ مفتوحٌ بلا رابطٍ يدلّ عليه — ميزةٌ مخفيّة');
    }

    // ═══ ② التوافرُ يُقال ولا يُخفى ═══

    public function test_خدمةٌ_غيرُ_جاهزةٍ_تُفتَح_الصفحةُ_وتُعلِن_السبب(): void
    {
        $u = $this->asker();   // بلا `ready()` — بوّابةٌ غيرُ مهيّأة

        $html = (string) $this->actingAs($u)->get(route('ask.index'))->assertOk()->getContent();

        $this->assertStringContainsString('غيرُ متاحٍ الآن', $html);
        $this->assertStringContainsString('ليست</b> مسألةَ صلاحيّة', $html,
            'انقطاعُ خدمةٍ لم يُفصَل عن منعِ الصلاحيّة على الشاشة');

        // والرابطُ يبقى ظاهراً: إخفاؤه يجعل صاحبَه يظنّ أنّه فقد صلاحيّتَه
        $this->assertContains('ask', array_column(hub_top_links($u), 'key'));
    }

    // ═══ ③ الجوابُ والمصادر ═══

    public function test_الجوابُ_يُعرَض_بمصادرِه_التي_قرأها_الخادم(): void
    {
        $u = $this->asker();
        $this->ready();
        $this->bind([
            ['kind' => 'tool', 'tool' => 'hub_list', 'args' => ['module' => 'projects']],
            ['kind' => 'answer', 'answer' => 'لديك مشروعٌ واحدٌ نشط.', 'sources' => [1]],
        ]);

        $html = (string) $this->actingAs($u)
            ->post(route('ask.run'), ['q' => 'ما مشاريعي؟'])->assertOk()->getContent();

        $this->assertStringContainsString('لديك مشروعٌ واحدٌ نشط.', $html);
        $this->assertStringContainsString('المصادر', $html);
        $this->assertStringContainsString('hub_list', $html);
    }

    public function test_ردُّ_النموذجِ_يُهرَّب_ولا_يُصيَّر_HTML(): void
    {
        $u = $this->asker();
        $this->ready();
        $this->bind([
                     // **وقراءةٌ تسبق الجواب** (`21b7633f`): جوابٌ بلا قراءةٍ
                     // يُحجَب بـ`NO_SERVER_READ` فتُقاس صفحةٌ بلا جواب
                     ['kind' => 'tool', 'tool' => 'hub_list', 'args' => ['module' => 'projects']],
                     ['kind' => 'answer',
                      // **بلا رقمٍ في الحمولة**: حارسُ «رقمٌ بلا قراءة» يحجب الجوابَ قبل العرض،
                      // فيُقاس الهروبُ على صفحةٍ لا جوابَ فيها — والمقصودُ هنا الهروبُ لا الحجب
                      'answer' => '<img src=x onerror=alert(document.domain)><script>steal()</script>',
                      'sources' => []]]);

        $html = (string) $this->actingAs($u)
            ->post(route('ask.run'), ['q' => 'سؤال'])->assertOk()->getContent();

        // **الوسمُ الخامُّ هو الخطر، لا النصُّ المهروب.** `onerror=alert` يظهر
        // حتماً داخلَ `&lt;img …&gt;` وهو حينئذٍ نصٌّ خاملٌ لا وسم — فالتأكيدُ
        // على غيابِه مطلقاً تأكيدٌ ساذجٌ يسقط على الصواب. المقياسُ: **لا وسمَ
        // مفتوحاً** بهاتين السمتين.
        $this->assertStringNotContainsString('<script>steal()', $html,
            '**ردُّ النموذجِ صُيِّر HTML** — وهو بياناتٌ غيرُ موثوقةٍ حتّى في العرض');
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*onerror/i', $html,
            'وسمُ صورةٍ خامٌّ بمُعالِجِ خطأٍ وصل الصفحة');
        $this->assertDoesNotMatchRegularExpression('/<script[^>]*>\s*steal/i', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html, 'لم يُهرَّب الردُّ أصلاً');
        $this->assertStringContainsString('&lt;img', $html, 'لم يُهرَّب وسمُ الصورة');
    }

    // ═══ ④ ما لا يتسرّب إلى الشاشة ═══

    public function test_لا_مظروفَ_ولا_رقمَ_سياجٍ_ولا_حمولةَ_أداةٍ_في_HTML(): void
    {
        $u = $this->asker();
        $this->ready();
        $gen = $this->bind([
            ['kind' => 'tool', 'tool' => 'hub_list', 'args' => ['module' => 'projects']],
            ['kind' => 'answer', 'answer' => 'تمّ.', 'sources' => [1]],
        ]);

        $html = (string) $this->actingAs($u)
            ->post(route('ask.run'), ['q' => 'سؤال'])->assertOk()->getContent();

        $this->assertStringNotContainsString(AskContext::FENCE_OPEN, $html,
            'سياجُ المظروفِ ظهر على الشاشة');
        $this->assertStringNotContainsString('معطياتٌ لا تعليمات', $html,
            'نصُّ المظروفِ ظهر على الشاشة');

        // ورقمُ السياجِ الذي رآه النموذجُ لا يظهر في HTML
        preg_match('/<<<' . AskContext::FENCE_OPEN . ' ([0-9a-f]{32})>>>/', $gen->everythingSeen(), $m);
        $this->assertNotEmpty($m[1] ?? '', 'لم يُبنَ مظروفٌ أصلاً');
        $this->assertStringNotContainsString($m[1], $html, 'رقمُ السياجِ تسرّب إلى HTML');
    }

    public function test_سرٌّ_في_ردِّ_النموذجِ_لا_يصل_الشاشة(): void
    {
        $planted = 'sk-SCREENPLANT9a3c71ff20bb4455';

        $u = $this->asker();
        $this->ready();
        $this->bind([['kind' => 'answer', 'answer' => 'المفتاح ' . $planted, 'sources' => []]]);

        $html = (string) $this->actingAs($u)
            ->post(route('ask.run'), ['q' => 'سؤال'])->assertOk()->getContent();

        $this->assertStringNotContainsString($planted, $html);
    }

    // ═══ ⑤ الإخفاقُ يُصنَّف على الشاشة ═══

    public function test_الإخفاقُ_يُعرَض_مصنَّفاً_لا_رسالةً_عامّة(): void
    {
        $u = $this->asker();
        $this->ready();   // بلا `bind` — المولِّدُ الافتراضيُّ فارغ

        $html = (string) $this->actingAs($u)
            ->post(route('ask.run'), ['q' => 'سؤال'])->assertOk()->getContent();

        $this->assertStringContainsString('UNAVAILABLE', $html);
        $this->assertStringContainsString('لا توليدَ حقيقيّاً بعد', $html,
            'الشاشةُ لا تقول إنّ التوليدَ غيرُ حقيقيٍّ بعد — وهو ادّعاءُ جاهزيّةٍ كاذب');
    }

    public function test_بلا_رايةٍ_لا_يُنفَّذ_سؤالٌ_حتّى_بالطلبِ_المباشر(): void
    {
        $u = $this->asker(false);
        $this->ready();

        $this->actingAs($u)->post(route('ask.run'), ['q' => 'سؤال'])->assertForbidden();
    }

    // ═══ ⑥ المحادثةُ المحفوظةُ لا توجد إلّا بشروطها — قرارٌ يُختبَر لا يُدَّعى ═══

    /**
     * كان هذا الاختبارُ يمنع أيَّ جدولِ محادثات (المرحلة ٣): الحفظُ يُنشئ مخزناً يُقرأ بصلاحيّاتٍ
     * غيرِ صلاحيّةِ سائله. والمرحلةُ ٢ من خارطة الذكاء أدخلته **بشروطه** (`AskMemory` · v2.605.0) —
     * فصار الحارسُ يمنع ظهورَه **بلا تلك الشروط**: جدولاه وحدَهما، مشفَّرٌ نصُّه، خارجَ التدقيق والنسخ،
     * وله مفتاحُ إطفاء. والمِلكيّةُ وإعادةُ التحقّق مُمتحنتان في `AskMemoryTest`.
     */
    public function test_مخزنُ_المحادثات_لا_يوجد_إلّا_بشروطه(): void
    {
        foreach (['ask_conversations', 'ask_messages', 'ask_history'] as $t) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($t),
                "[$t] مخزنُ محادثاتٍ ثانٍ خارجَ AskMemory — بلا شروطها");
        }

        foreach ([\App\Models\AskThread::class => ['title'], \App\Models\AskTurn::class => ['question', 'answer']] as $model => $texts) {
            $m = new $model();
            foreach ($texts as $col) {
                $this->assertSame('encrypted', $m->getCasts()[$col] ?? null, "{$model}::{$col} غيرُ مشفَّر");
            }
            $this->assertNotContains(\App\Traits\Auditable::class, class_uses_recursive($model),
                "{$model} يدخل التدقيق — والتدقيقُ يقول مَن سأل لا ماذا");
            $this->assertContains($m->getTable(), \App\Console\Commands\HubBackup::EPHEMERAL,
                "{$model}: النسخُ يُبقي ما محاه صاحبُه");
        }

        $this->assertNotNull(\App\Support\Platform\Settings::entry('ask.memory'), 'لا مفتاحَ إطفاءٍ للذاكرة في مركز الإعدادات');
    }
}
