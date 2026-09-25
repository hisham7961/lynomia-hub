<?php

namespace Tests\Feature\AskHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use App\Support\FeatureRegistry;
use App\Support\Ai\Ask\LiteLlmAskGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **ثوابتُ إطلاقِ «اسأل Hub» — السلسلةُ كاملةً في تشغيلةٍ واحدة** (المرحلة ٥ · W1).
 *
 * ── **لماذا حزمةٌ ثانيةٌ والحزمُ حولها عشر؟** ──
 *
 * الحزمُ القائمةُ تقيس **حلقةً حلقة**: هذه تفحص المظروف، وتلك الأدوات،
 * وثالثةٌ الأثر. ولا واحدةَ منها تمرّ بالسلسلةِ **كلِّها في طلبٍ واحد** فتقول:
 *
 * ```
 * سؤال → كتالوج → طلبُ أداة → إذنُ الخادم → تنفيذ → نتيجة
 *       → إجابة → تحقُّقُ المصادر → الاستهلاك → الأثر
 * ```
 *
 * وحلقاتٌ صحيحةٌ منفردةً **لا تُنتج سلسلةً صحيحة**: كلُّ الحلقاتِ كانت خضراءَ
 * يومَ v2.584.0 و**السؤالُ لم يكن يصل النموذجَ قطّ** — لأنّ العطبَ كان في
 * الوصلِ بينها لا فيها.
 *
 * ── **والقياسُ على المُرسَلِ لا على العائد** ──
 *
 * الدرسُ الذي كلّف ثلاثَ محاولاتٍ مدفوعة: `Http::fake` تعيد لقطةً ثابتةً
 * **مهما كانت الحمولة**، فحزمةٌ تقيس ما يعود تخضرُّ على مسارٍ لا يُرسِل شيئاً.
 * فكلُّ ثابتٍ هنا يفتح `messages` و`tools` ويقرأ **ما خرج من عندنا**.
 */
class AskReleaseInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $asker;

    private Company $alpha;

    private Company $beta;

    private Project $mine;

    private Project $theirs;

    /** كلُّ حمولةٍ خرجت إلى البوّابةِ في هذا الاختبار */
    private array $sent = [];

    private array $steps = [];

    private int $at = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha  = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->beta   = Company::create(['name_ar' => 'شركةُ باء']);
        $this->mine   = Project::create(['name' => 'مشروعُ ألِف ٧٧١٢٣٤', 'company_id' => $this->alpha->id]);
        $this->theirs = Project::create(['name' => 'مشروعُ باء ٨٨٩٩٧٧',  'company_id' => $this->beta->id]);

        $this->asker = $this->scopedUser('release@ask.local');
        $this->ready();
        $this->intercept();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ① السلسلةُ كاملةً — حلقةً حلقةً في طلبٍ واحد
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **الطلبُ الكامل: تسعُ حلقاتٍ تُقاس كلُّها في تشغيلةٍ واحدة.**
     *
     * وهذا هو ثابتُ الإطلاقِ الأوّل: ليس «كلُّ جزءٍ يعمل» بل **«الطريقُ يصل»**.
     */
    public function test_السلسلةُ_كاملةً_من_السؤالِ_إلى_الأثرِ_في_طلبٍ_واحد(): void
    {
        $q = 'كم مشروعاً لدينا؟';

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'call-1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لديكم مشروعٌ واحدٌ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        // ── ① السؤالُ خرج من عندنا نصّاً قائماً بذاته
        $this->assertCount(2, $this->sent, 'عددُ النداءاتِ ليس دورتين كما يقتضي المسار');
        $this->assertTrue($this->anyContains($this->roleContents($this->sent[0], 'user'), $q),
            '**الحلقةُ ①: السؤالُ لم يخرج إلى النموذج**');

        // ── ② كتالوجُ الأدواتِ مُعلَنٌ في النداءِ نفسِه
        $declared = $this->toolNames($this->sent[0]);
        $this->assertNotEmpty($declared, '**الحلقةُ ②: لا كتالوجَ أدواتٍ في الحمولة**');
        $this->assertSame([], array_diff($declared, AskTools::TOOLS),
            '**أداةٌ مُعلَنةٌ خارجَ المفرداتِ المغلقة**');

        // ── ③ + ④ + ⑤ طلبُ الأداةِ أُذن له وتُفِّذ — والدورةُ الثانيةُ تحمل نتيجتَه
        $tools = $this->roleContents($this->sent[1], 'tool');
        $this->assertNotEmpty($tools, '**الحلقةُ ⑤: نتيجةُ الأداةِ لم تعد إلى النموذج**');
        // و`hub_count` لا تُعيد صفوفاً — تُعيد إحالةً إلى مصدرٍ في المظروف
        $this->assertTrue($this->anyContains($tools, 'المصدرِ'),
            'رسالةُ النتيجةِ لا تُحيل إلى مصدرٍ في المظروف');

        // ── ⑥ رسالةُ `tool` مربوطةٌ بمعرّفِ الطلبِ — وإلّا لم يفهمها النموذج
        $this->assertSame(['call-1'], $this->toolCallIds($this->sent[1]),
            '**رسالةُ النتيجةِ بلا `tool_call_id` مطابقٍ — عقدُ المحادثةِ مكسور**');

        // ── ⑦ + ⑧ إجابةٌ مقبولةٌ بمصادرَ مُتحقَّقٍ منها
        $this->assertTrue($r['ok'], 'الطلبُ أخفق: ' . (string) ($r['failure'] ?? '—'));
        $this->assertNotEmpty($r['sources'], '**الحلقةُ ⑧: إجابةٌ بلا مصدرٍ واحد**');
        $this->assertSame(['projects'], array_values(array_unique(
            array_column($r['sources'], 'module'))));

        // ── ⑨ استهلاكٌ مُسجَّلٌ لكلِّ محاولة
        $this->assertSame(2, AiUsageEvent::query()->count(),
            '**الحلقةُ ⑨: الدفترُ لا يحمل صفّاً لكلِّ محاولة**');

        // ── ⑩ أثرُ تدقيقٍ واحدٌ للطلبِ المنطقيِّ كلِّه
        $this->assertSame(1, DB::table('audits')
            ->where('action', \App\Support\Ai\Ask\AskAudit::ACTION_ASKED)->count(),
            '**الحلقةُ ⑩: الطلبُ بلا أثر**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ② الخادمُ هو مرجعُ الإذنِ — لا النموذج
    // ═══════════════════════════════════════════════════════════════════

    /** **طلبُ أداةٍ إلى ما لا يملكه السائلُ يُمنَع قبل أن يُنفَّذ** */
    public function test_طلبٌ_إلى_ما_لا_يملكه_السائلُ_لا_يُنفَّذ(): void
    {
        $blind = $this->scopedUser('blind@ask.local', ['tasks']);

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('لا بيانات.', LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask('كم مشروعاً؟', $blind, new LiteLlmAskGenerator());

        $this->assertSame([], $r['sources'], '**قراءةٌ ممنوعةٌ أنتجت مصدراً**');
        $this->assertNotNull($r['failure'] ?? null, 'جوابٌ بلا قراءةٍ مرّ بلا تصنيف');

        foreach ($this->sent as $payload) {
            $this->assertStringNotContainsString((string) $this->theirs->name,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
                '**سجلٌّ خارجَ نطاقِ السائلِ وصل النموذجَ**');
        }
    }

    /** **ونطاقُ الشركةِ يُطبَّق على القراءةِ المأذونِ بها نفسِها** */
    public function test_القراءةُ_المأذونُ_بها_تبقى_داخلَ_نطاقِ_السائل(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحدٌ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask('ما المشاريع؟', $this->asker, new LiteLlmAskGenerator());

        $everything = (string) json_encode($this->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ألِف', $everything, 'ما يملكه السائلُ لم يصل');
        $this->assertStringNotContainsString((string) $this->theirs->name, $everything,
            '**تسرّبُ شركةٍ أخرى عبر مظروفِ النموذج**');
    }

    /**
     * **ولا أداةَ كتابةٍ تُعلَن قطّ.**
     *
     * القرارُ قائمٌ ولا يُوسَّع في هذه المرحلة: `WRITE_TOOLS = []`. والثابتُ
     * يُقاس على **ما خرج إلى البوّابةِ فعلاً** لا على الثابتِ في الشيفرة —
     * فمصدرُ الكتالوجِ قد يتغيّر يوماً والحارسُ يبقى.
     */
    public function test_لا_أداةَ_كتابةٍ_في_أيِّ_حمولةٍ_خرجت(): void
    {
        $this->assertSame([], AskTools::WRITE_TOOLS,
            '**`WRITE_TOOLS` لم تعد فارغةً — وهذا قرارُ مالكٍ لا تفصيلُ تنفيذ**');

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('تمّ [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask('ما المشاريع؟', $this->asker, new LiteLlmAskGenerator());

        foreach ($this->sent as $i => $payload) {
            foreach ($this->toolNames($payload) as $name) {
                $this->assertContains($name, AskTools::TOOLS,
                    "أداةٌ غيرُ معروفةٍ في النداءِ {$i}: {$name}");
                $this->assertNotContains($name, AskTools::WRITE_TOOLS,
                    "**أداةُ كتابةٍ خرجت إلى البوّابة: {$name}**");
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // ③ الجوابُ لا يُقبَل إلّا مسنوداً
    // ═══════════════════════════════════════════════════════════════════

    /** **جوابٌ بلا قراءةٍ واحدةٍ يُصنَّف ولا يُعرَض** */
    public function test_جوابٌ_بلا_قراءةٍ_يُصنَّف_NO_SERVER_READ(): void
    {
        $this->script([[LiteLlmFixtures::answer('لديكم ٣٦ مشروعاً.', LiteLlmFixtures::usage()), 200]]);

        $r = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $this->assertFalse($r['ok']);
        $this->assertContains($r['failure'], [AskFailures::NO_SERVER_READ, AskFailures::UNSOURCED_NUMBER],
            '**جوابٌ مُختلَقٌ مرّ بلا تصنيف**');
        $this->assertNull($r['answer']);
    }

    /** **ومصدرٌ يذكره النموذجُ ولم يُقرَأ لا يصير مصدراً** */
    public function test_مصدرٌ_مُختلَقٌ_في_النصِّ_لا_يصير_مصدراً(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('راجع المصدر [#tasks:999999] لديكم مشروعٌ واحد [#1].',
                LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $modules = array_values(array_unique(array_column((array) $r['sources'], 'module')));
        $this->assertNotContains('tasks', $modules,
            '**إحالةٌ في نصِّ الجوابِ صنعت مصدراً لم يُقرَأ**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ④ ما لا يخرج من عندنا أبداً
    // ═══════════════════════════════════════════════════════════════════

    /** **مفتاحُ البوّابةِ لا يظهر في أثرٍ ولا دفترٍ ولا ردٍّ للمستخدم** */
    public function test_لا_مفتاحَ_بوّابةٍ_في_أثرٍ_ولا_دفترٍ_ولا_ردّ(): void
    {
        $key = 'sk-admin-test-key-000111222333';

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $r = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        foreach (['audits', 'ai_usage_events'] as $table) {
            $this->assertStringNotContainsString($key,
                (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE),
                "**مفتاحُ البوّابةِ دخل `{$table}`**");
        }

        $this->assertStringNotContainsString($key,
            (string) json_encode($r, JSON_UNESCAPED_UNICODE),
            '**مفتاحُ البوّابةِ عاد إلى المستخدم**');
    }

    /** **ولا نصَّ سؤالٍ ولا وسائطَ أداةٍ في الأثرِ ولا الدفتر** */
    public function test_لا_نصَّ_سؤالٍ_ولا_وسائطَ_أداةٍ_في_التليمتري(): void
    {
        // قيمةٌ من ستِّ خاناتٍ فأكثر — فلا تُقارَع معرّفاتُ الصفحةِ والطلب
        $q = 'كم مشروعاً يحمل الرقم 771234 لدينا؟';

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_search',
                                        'args' => ['module' => 'projects', 'q' => '771234']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask($q, $this->asker, new LiteLlmAskGenerator());

        foreach (['audits', 'ai_usage_events'] as $table) {
            $blob = (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('771234', $blob,
                "**نصُّ السؤالِ أو وسيطُ الأداةِ دخل `{$table}`**");
        }
    }

    /** **واسمُ الأداةِ يدخل — لأنّه من مفرداتٍ مغلقةٍ لا يحمل بياناتِ أحد** */
    public function test_اسمُ_الأداةِ_يُسجَّل_ولا_يُعَدّ_تسريباً(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $this->assertTrue(AiUsageEvent::query()->where('tool_requested', 'hub_count')->exists(),
            '**اسمُ الأداةِ لم يُسجَّل — فلا يُشخَّص أيُّ نموذجٍ يطلب ماذا**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑤ الحدودُ مفروضةٌ على المُرسَلِ لا موصوفةٌ في وثيقة
    // ═══════════════════════════════════════════════════════════════════

    /** **سقفُ رموزِ المخرَجِ يُرسَل في كلِّ نداءٍ — لا في الأوّلِ وحدَه** */
    public function test_سقفُ_المخرَجِ_مفروضٌ_في_كلِّ_نداء(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $cap = AskPolicy::maxOutputTokens();
        foreach ($this->sent as $i => $p) {
            $sent = $p['max_tokens'] ?? $p['max_completion_tokens'] ?? null;
            $this->assertNotNull($sent, "النداءُ {$i} خرج بلا سقفِ مخرَج");
            $this->assertLessThanOrEqual($cap, (int) $sent,
                "**النداءُ {$i} خرج بسقفٍ يتجاوز السياسة**");
        }
    }

    /** **وعددُ دوراتِ النموذجِ لا يتجاوز السقفَ مهما ألحَّ النموذج** */
    public function test_نموذجٌ_يطلب_بلا_توقّفٍ_يُوقَف_عند_السقف(): void
    {
        $this->script([[LiteLlmFixtures::toolCall([['id' => 'loop', 'name' => 'hub_count',
                                                   'args' => ['module' => 'projects']]]), 200]]);

        $r = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $this->assertLessThanOrEqual(AskPolicy::maxModelSteps(), count($this->sent),
            '**النموذجُ أدار المسارَ بلا سقف**');
        $this->assertFalse($r['ok']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑥ ثوابتُ التوجيهِ والملاءمة
    // ═══════════════════════════════════════════════════════════════════

    /** **المساعدُ يشترط `chat` و`tools` معاً — لا `chat` وحدَها** */
    public function test_المساعدُ_يشترط_المحادثةَ_والأدواتِ_معاً(): void
    {
        $this->assertSame(['chat', 'tools'], AiPurposes::needs(AiPurposes::ASK));
    }

    /** **ونموذجٌ لا يُصدر أدواتٍ يُغلِق البابَ برسالةٍ تُسمّي القدرةَ** */
    public function test_نموذجٌ_بلا_أدواتٍ_يُغلِق_البابَ_بسببٍ_يُسمّى(): void
    {
        AiModel::query()->update(['capabilities' => [
            'chat'  => ['v' => true,  'src' => 'litellm'],
            'tools' => ['v' => false, 'src' => 'litellm'],
        ]]);

        $this->actingAs($this->asker);

        $this->assertNull(AskPolicy::profile(), '**نموذجٌ لا يُصدر أدواتٍ بقي رأسَ سلسلةِ المساعد**');
        $this->assertStringContainsString('tools', (string) AskPolicy::whyNot($this->asker));
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑦ عقدُ الردِّ ثابتٌ — والشاشةُ تُبنى عليه
    // ═══════════════════════════════════════════════════════════════════

    /** **كلُّ ردٍّ — ناجحاً كان أو مُخفِقاً — يحمل المفاتيحَ الثمانية** */
    public function test_عقدُ_الردِّ_واحدٌ_في_النجاحِ_والإخفاق(): void
    {
        $keys = ['ok', 'answer', 'sources', 'failure', 'message', 'partial', 'budget', 'meta'];

        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);
        $good = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        $this->script([[LiteLlmFixtures::providerDown(), 503]]);
        $bad = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());

        foreach (['ناجح' => $good, 'مُخفِق' => $bad] as $label => $r) {
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $r, "الردُّ ال{$label} بلا المفتاح `{$k}`");
            }
            $this->assertArrayHasKey('correlation', (array) $r['meta']);
            $this->assertNotSame('', (string) $r['meta']['correlation'],
                "الردُّ ال{$label} بلا معرّفِ ارتباطٍ — فلا يُتتبَّع من الشاشةِ إلى الأثر");
        }

        $this->assertTrue($good['ok']);
        $this->assertFalse($bad['ok']);
        $this->assertNotNull($bad['message'], '**رمزُ إخفاقٍ أخرسُ في وجهِ المستخدم**');
    }

    /** **ومعرّفُ الارتباطِ نفسُه يصل الأثرَ والدفتر** */
    public function test_معرّفُ_الارتباطِ_يربط_الشاشةَ_بالأثرِ_وبالدفتر(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_count',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $r  = AskPipeline::ask('كم مشروعاً؟', $this->asker, new LiteLlmAskGenerator());
        $id = (string) $r['meta']['correlation'];

        $this->assertTrue(AiUsageEvent::query()->where('correlation', $id)->exists(),
            '**الدفترُ لا يُعرَف فيه معرّفُ الطلبِ الذي رآه المستخدم**');

        $blob = (string) json_encode(DB::table('audits')
            ->where('action', \App\Support\Ai\Ask\AskAudit::ACTION_ASKED)->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($id, $blob, '**الأثرُ لا يحمل معرّفَ الارتباط**');
    }

    // ═══════════════════════════════════════════════════════════════════
    // ⑧ الأداءُ — أوّلُ قياسٍ لمسارِ الذكاءِ على جانبِنا
    // ═══════════════════════════════════════════════════════════════════

    /**
     * **ما ننفقه نحن في الطلبِ الواحدِ مقيسٌ لا موصوف.**
     *
     * والبوّابةُ محاكاةٌ هنا، فالزمنُ المقيسُ **زمنُ Hub وحدَه**: بناءُ
     * الكتالوجِ والتصريحِ والقراءةِ والمظروفِ والتحقّقِ والأثر. وهذا هو ما
     * نملك إصلاحَه — زمنُ المزوّدِ ليس بيدِنا.
     *
     * **والحدُّ سخيٌّ عمداً**: غرضُه إمساكُ الانحدارِ الجسيم (استعلامٌ في حلقة،
     * قراءةُ جدولٍ كاملٍ في المظروف) لا مطاردةُ أجزاءِ الثانية على آلةِ بناء.
     */
    public function test_كلفةُ_مسارِنا_في_الطلبِ_الواحدِ_مقيسةٌ_ومحدودة(): void
    {
        $this->script([
            [LiteLlmFixtures::toolCall([['id' => 'c1', 'name' => 'hub_list',
                                        'args' => ['module' => 'projects']]]), 200],
            [LiteLlmFixtures::answer('مشروعٌ واحد [#1].', LiteLlmFixtures::usage()), 200],
        ]);

        $queries = 0;
        DB::listen(static function () use (&$queries) { $queries++; });

        $t = microtime(true);
        $r = AskPipeline::ask('ما المشاريع؟', $this->asker, new LiteLlmAskGenerator());
        $ms = (microtime(true) - $t) * 1000;

        $this->assertTrue($r['ok'], 'الطلبُ أخفق فالقياسُ لا معنى له');

        $this->assertLessThan(3000, $ms,
            '**انحدارٌ جسيمٌ في زمنِ مسارِنا** — قِيس: ' . (int) $ms . 'ms');

        /*
         * **وعددُ الاستعلاماتِ أصدقُ من الزمن** على آلةِ بناءٍ متقلّبة: زمنٌ
         * يتضاعف قد يكون حِملاً على المُضيف، أمّا استعلامٌ في حلقةٍ فيظهر
         * هنا رقماً لا يُماري.
         */
        $this->assertLessThan(250, $queries,
            '**عددُ الاستعلاماتِ انفجر — استعلامٌ في حلقةٍ على الأرجح**: ' . $queries);
    }

    // ── أدواتُ القياس ─────────────────────────────────────────────────

    /** @return list<string> */
    private function roleContents(array $payload, string $role): array
    {
        $out = [];
        foreach ((array) ($payload['messages'] ?? []) as $m) {
            if (($m['role'] ?? '') === $role) $out[] = (string) ($m['content'] ?? '');
        }

        return $out;
    }

    /** @return list<string> معرّفاتُ طلباتِ الأدواتِ التي رُدّ عليها */
    private function toolCallIds(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['messages'] ?? []) as $m) {
            if (($m['role'] ?? '') === 'tool' && isset($m['tool_call_id'])) {
                $out[] = (string) $m['tool_call_id'];
            }
        }

        return $out;
    }

    /** @return list<string> أسماءُ الأدواتِ المُعلَنةِ في الحمولة */
    private function toolNames(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['tools'] ?? []) as $t) {
            $n = $t['function']['name'] ?? ($t['name'] ?? null);
            if (is_string($n) && $n !== '') $out[] = $n;
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $haystacks */
    private function anyContains(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $h) {
            if (str_contains($h, $needle)) return true;
        }

        return false;
    }

    // ── أدواتُ التهيئة ────────────────────────────────────────────────

    private function script(array $steps): void
    {
        $this->sent  = [];
        $this->steps = $steps;
        $this->at    = 0;
    }

    private function intercept(): void
    {
        Http::fake(function ($req) {
            $this->sent[] = TestCase::sentBody($req);
            $step = $this->steps[min($this->at, max(0, count($this->steps) - 1))]
                ?? [LiteLlmFixtures::answer('لا شيء.'), 200];
            $this->at++;

            return Http::response($step[0], $step[1] ?? 200);
        });
    }

    private function scopedUser(string $email, ?array $modules = null): User
    {
        $modules = $modules ?? array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(
            fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلُ الإطلاق ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$this->alpha->id]]);
    }

    private function ready(): void
    {
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) {
            Settings::put($k, '1', 'test');
        }
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');

        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create([
            'catalog_key' => 'openai', 'label' => 'مزوّدٌ وهميّ', 'enabled' => true,
            'credential_name' => 'hub-rel-' . substr(sha1((string) microtime(true)), 0, 10),
            'credential_state' => 'configured',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general',
            'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => [
                'chat'  => ['v' => true, 'src' => 'litellm'],
                'tools' => ['v' => true, 'src' => 'litellm'],
            ],
            'limits'  => ['context_window' => ['v' => 32000, 'src' => 'litellm']],
            'params'  => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens'],
        ]);

        AiProfiles::attach(AiProfile::query()
            ->where('key', AskPolicy::PROFILE)->firstOrFail(), $model);
    }
}
