<?php

namespace Tests\Feature\AiHub;

use App\Models\AiUsageEvent;
use App\Support\AiCost;
use App\Support\AiLedger;
use App\Support\AskAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **عقدُ الرصد: ما يُسجَّل مُعدودٌ، وما عداه ممنوع** (المرحلة ٥ · W5 + W7).
 *
 * ── **قائمةُ سماحٍ لا قائمةَ منع** ──
 *
 * الحرّاسُ القائمةُ تبحث عن **إبرةٍ بعينِها** في الأثر: «أليس فيه نصُّ
 * السؤال؟» — وهو حارسٌ يمرّ عليه أوّلُ حقلٍ جديدٍ لم يخطر لكاتبِه. فحقلٌ
 * `prompt_preview` يُضاف غداً بحسنِ نيّةٍ لا يُسقِط شيئاً.
 *
 * **فالحارسُ هنا مقلوب:** أعمدةُ الدفترِ مُعدودةٌ حرفاً، وعمودٌ يُضاف بلا
 * قرارٍ يُسقِط هذا الاختبارَ ويُقرأ في المراجعة. وهذا هو الفرقُ بين حارسٍ
 * يعمل اليومَ وحارسٍ يعمل بعد سنة.
 *
 * ── **وW7: الكلفةُ موسومةٌ بمصدرِها، والمجهولُ ليس صفراً** ──
 *
 * أربعةُ مصادرَ لا واحد: **مبلَّغةٌ** من المزوّد · **محسوبةٌ** من سعرٍ
 * مُثبَت · **مقدَّرةٌ** قبل النداء · **مجهولة**. وتحويلُ المجهولِ إلى صفرٍ
 * يجعل الشاشةَ تقول «لم يكلّف شيئاً» عن نداءٍ كلّف، **ويجعل حارسَ الميزانيّةِ
 * يمرّر ما لا يُقاس**.
 */
class AiObservabilityContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **كلُّ عمودٍ في دفترِ الاستهلاك — مُعدودٌ ومُصنَّف.**
     *
     * والتصنيفُ ثلاثةٌ: **هويّةُ الطلب** · **قرارٌ ونتيجة** · **أرقامٌ لا
     * محتوى**. ولا صنفَ رابعاً اسمُه «محتوى».
     */
    private const LEDGER_COLUMNS = [
        // ① هويّةُ الطلبِ وربطُه — معرّفاتٌ لا تحمل بياناتِ أحد
        'id', 'request_id', 'correlation', 'parent_id', 'attempt', 'relation',
        'company_id', 'user_id', 'purpose', 'feature',

        // ② القرارُ والنتيجة — تصنيفاتٌ من مفرداتٍ مغلقة
        'provider_id', 'model_id', 'model_name', 'status', 'failure', 'cause',
        'http_status', 'finish_reason', 'tool_requested',

        // ③ أرقامٌ — ولا محرفَ من سؤالٍ ولا جوابٍ ولا تفكير
        'input_tokens', 'output_tokens', 'total_tokens', 'cached_tokens',
        'reasoning_tokens', 'max_output_tokens',
        'cost_micro', 'cost_source', 'currency', 'reserved_micro',
        'budget_id', 'period_key', 'latency_ms',
        'started_at', 'settled_at', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    // ═══════════════════════════════════════════════════════════════════
    // W5 — ما يُسجَّل مُعدود
    // ═══════════════════════════════════════════════════════════════════

    /** **عمودٌ يُضاف إلى الدفترِ بلا قرارٍ يُسقِط هذا الاختبار** */
    public function test_أعمدةُ_دفترِ_الاستهلاكِ_هي_المُعدودةُ_لا_غير(): void
    {
        $actual = Schema::getColumnListing('ai_usage_events');

        sort($actual);
        $expected = self::LEDGER_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $actual,
            '**عمودٌ في دفترِ الاستهلاكِ خارجَ العقد** — يُراجَع: أيحمل محتوًى؟');
    }

    /** **ولا عمودَ اسمُه يشي بمحتوًى** — حزامٌ ثانٍ بالمفردات */
    public function test_لا_عمودَ_يحمل_اسمَ_محتوًى(): void
    {
        foreach (Schema::getColumnListing('ai_usage_events') as $col) {
            foreach (['question', 'answer', 'prompt', 'content', 'message',
                      'reasoning_content', 'body', 'text', 'args', 'payload'] as $bad) {
                $this->assertNotSame($bad, $col, "عمودُ محتوًى في الدفتر: {$col}");
            }
        }
    }

    /** **وأثرُ «اسأل Hub» يُسقِط كلَّ مفتاحِ محتوًى ولو مرّره المستدعي سهواً** */
    public function test_أثرُ_المساعدِ_يُسقِط_مفاتيحَ_المحتوى_عند_الكاتب(): void
    {
        AskAudit::asked([
            'tools'    => ['hub_count'],
            'modules'  => ['projects'],
            'rows'     => 3,
            // ← ما لا يجوز، مُمرَّراً عمداً
            'question' => 'كم راتبُ الموظّفِ رقم 881199؟',
            'answer'   => 'راتبُه 990011 ديناراً',
            'prompt'   => 'أنت مساعدٌ …',
            'rowsData' => [['salary' => 770066]],
            'content'  => 'نصٌّ مسترجَع',
        ]);

        $blob = (string) json_encode(DB::table('audits')
            ->where('action', AskAudit::ACTION_ASKED)->get(), JSON_UNESCAPED_UNICODE);

        foreach (['881199', '990011', '770066', 'أنت مساعدٌ', 'نصٌّ مسترجَع'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob,
                "**محتوًى مرّ إلى الأثر: {$needle}**");
        }

        // والشكلُ يبقى — فالحارسُ يُسقِط المحتوى لا الخبر
        $this->assertStringContainsString('hub_count', $blob);
        $this->assertStringContainsString('projects', $blob);
    }

    /** **وقائمةُ الممنوعِ عند الكاتبِ مكتوبةٌ لا مُفترَضة** */
    public function test_قائمةُ_ما_لا_يُسجَّل_قائمةٌ_مكتوبة(): void
    {
        foreach (['question', 'answer', 'reasoning', 'rows', 'content', 'prompt'] as $k) {
            $this->assertContains($k, AskAudit::NEVER_LOGGED,
                "مفتاحُ محتوًى خارجَ قائمةِ المنع: {$k}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // W7 — الكلفةُ موسومةٌ بمصدرِها
    // ═══════════════════════════════════════════════════════════════════

    /** **أربعةُ مصادرَ للكلفةِ لا ثلاثةٌ ولا خمسة** */
    public function test_مصادرُ_الكلفةِ_أربعةٌ_معروفة(): void
    {
        $this->assertSame(
            [AiCost::REPORTED, AiCost::CALCULATED, AiCost::ESTIMATED, AiCost::UNKNOWN],
            AiCost::SOURCES);
    }

    /** **وصفٌّ يُفتَح مجهولَ الكلفةِ لا صفرَها** — الحجزُ ليس إنفاقاً */
    public function test_الحجزُ_يفتح_الصفَّ_مجهولَ_الكلفةِ_لا_صفرَها(): void
    {
        $e = $this->open(['reserved_micro' => 1500]);

        $this->assertSame('reserved', (string) $e->status);
        $this->assertNull($e->cost_micro, '**حجزٌ كُتب كلفةً — والحجزُ ليس إنفاقاً**');
        $this->assertSame(AiCost::UNKNOWN, (string) $e->cost_source);
        $this->assertSame(1500, (int) $e->reserved_micro);
        $this->assertNull($e->settled_at, 'صفٌّ مفتوحٌ بزمنِ إغلاق');
    }

    /** **وكلفةٌ مبلَّغةٌ من المزوّدِ تُوسَم `reported`** */
    public function test_الكلفةُ_المبلَّغةُ_تُوسَم_بمصدرِها(): void
    {
        $e = AiLedger::succeed($this->open(), [
            'micro'  => 2400,
            'source' => AiCost::REPORTED,
            'tokens' => ['in' => 420, 'out' => 65, 'total' => 485],
        ], 812);

        $this->assertSame('ok', (string) $e->status);
        $this->assertSame(2400, (int) $e->cost_micro);
        $this->assertSame(AiCost::REPORTED, (string) $e->cost_source);
        $this->assertSame(485, (int) $e->total_tokens);
        $this->assertNotNull($e->settled_at);
    }

    /**
     * **ونجاحٌ بلا رقمِ كلفةٍ يبقى مجهولاً — لا صفراً.**
     *
     * والفرقُ يُقرَأ في الشاشةِ «—» لا «٠٫٠٠»: الأولى تقول «لا نعرف»،
     * والثانيةُ تقول «لم يكلّف» — **وإحداهما كذبٌ على الفاتورة**.
     */
    public function test_نجاحٌ_بلا_رقمٍ_يبقى_مجهولاً_لا_صفراً(): void
    {
        $e = AiLedger::succeed($this->open(), ['tokens' => ['in' => 100, 'out' => 20]], 300);

        $this->assertNull($e->cost_micro, '**كلفةٌ مجهولةٌ حُوّلت إلى صفر**');
        $this->assertSame(AiCost::UNKNOWN, (string) $e->cost_source);
    }

    /** **وإخفاقٌ لا يُحسَب كلفةَ نجاحٍ — والمحاولةُ تبقى مُسجَّلةً لأنّها وقعت** */
    public function test_الإخفاقُ_يُسجَّل_ولا_يُحسَب_إنفاقاً_مؤكَّداً(): void
    {
        $e = AiLedger::fail($this->open(), 'PROVIDER_FAILURE', 'transient', 503, 120);

        $this->assertSame('failed', (string) $e->status);
        $this->assertSame('PROVIDER_FAILURE', (string) $e->failure);
        $this->assertSame('transient', (string) $e->cause);
        $this->assertNull($e->cost_micro);
        $this->assertSame(AiCost::UNKNOWN, (string) $e->cost_source);
        $this->assertNotNull($e->settled_at, '**صفٌّ أخفق وبقي مفتوحاً — فلا يُقفَل حسابُه**');
    }

    /** **وتخلٍّ عن حجزٍ يُغلِق الصفَّ ولا يمحوه** */
    public function test_التخلّي_عن_الحجزِ_يُغلِق_ولا_يمحو(): void
    {
        $e = AiLedger::release($this->open(['reserved_micro' => 900]), 'لم يُرسَل الطلب');

        $this->assertSame('released', (string) $e->status);
        $this->assertSame(1, AiUsageEvent::query()->count(),
            '**صفٌّ مُتخلًّى عنه مُحي — فالمحاولةُ لا تُقرَأ في التشخيص**');
    }

    /**
     * **والإعادةُ والاحتياطُ صفّانِ يُفرَّقان — لا صفٌّ واحدٌ يُخفي محاولةً أُنفقت.**
     *
     * وصفٌّ واحدٌ للطلبِ يجعل `A فشل → B نجح` تبدو نجاحاً واحداً بكلفةِ
     * الناجحِ وحدَه — **كذبٌ على الفاتورةِ وعلى التشخيصِ معاً**.
     */
    public function test_الإعادةُ_والاحتياطُ_يُفرَّقان_في_الطلبِ_الواحد(): void
    {
        $request = (string) Str::uuid();

        $a = $this->open(['request_id' => $request, 'attempt' => 1, 'relation' => 'initial']);
        AiLedger::fail($a, 'PROVIDER_FAILURE', 'transient', 503, 90);

        $b = $this->open(['request_id' => $request, 'attempt' => 2,
                          'relation' => 'retry', 'parent_id' => $a->id]);
        AiLedger::fail($b, 'PROVIDER_FAILURE', 'transient', 503, 95);

        $c = $this->open(['request_id' => $request, 'attempt' => 1,
                          'relation' => 'fallback', 'parent_id' => $b->id]);
        AiLedger::succeed($c, ['micro' => 1200, 'source' => AiCost::CALCULATED,
                               'tokens' => ['in' => 400, 'out' => 60]], 640);

        $rows = AiUsageEvent::query()->where('request_id', $request)->get();
        $this->assertCount(3, $rows, '**محاولةٌ أُنفقت ولم تُسجَّل**');

        /*
         * **والترتيبُ يُستخرج من روابطِ البياناتِ لا من `id`.**
         *
         * المعرّفُ هنا `uuid` لا رقمٌ تزايديّ، و`created_at` بدقّةِ الثانيةِ
         * فالثلاثةُ في ثانيةٍ واحدة. فـ`orderBy('id')` **قرعةٌ** تخضرُّ على
         * محرّكٍ وتسقط على آخر. و`parent_id` هو الرابطُ الحقيقيُّ الذي يقول
         * «هذه بعد تلك».
         */
        $byId  = $rows->keyBy('id');
        $chain = [];
        $node  = $rows->firstWhere('parent_id', null);
        $this->assertNotNull($node, '**سلسلةٌ بلا رأس — فلا تُقرَأ رحلةُ الطلب**');

        while ($node !== null) {
            $chain[] = $node;
            $node    = $rows->firstWhere('parent_id', $node->id);
        }

        $this->assertCount(3, $chain, '**حلقةٌ منقطعةٌ في سلسلةِ المحاولات**');
        $this->assertSame(['initial', 'retry', 'fallback'],
            array_map(static fn ($r) => (string) $r->relation, $chain));
        $this->assertSame(['failed', 'failed', 'ok'],
            array_map(static fn ($r) => (string) $r->status, $chain));
        $this->assertSame([1, 2, 1],
            array_map(static fn ($r) => (int) $r->attempt, $chain));

        // وكلُّ أبٍ موجودٌ فعلاً — فلا إحالةَ إلى صفٍّ لا يُقرَأ
        foreach ($chain as $r) {
            if ($r->parent_id !== null) $this->assertTrue($byId->has($r->parent_id));
        }

        // والكلفةُ المؤكَّدةُ كلفةُ الناجحِ وحدَه — والمحاولتانِ مرئيّتان
        $this->assertSame(1200, (int) $rows->sum('cost_micro'));
        $this->assertSame(2, $rows->whereNull('cost_micro')->count());
    }

    /** **ورموزُ التفكيرِ رقمٌ يُسجَّل — لا محتواه** */
    public function test_رموزُ_التفكيرِ_رقمٌ_في_الدفترِ_لا_نصّ(): void
    {
        $e = AiLedger::succeed($this->open(), [
            'micro'  => 900, 'source' => AiCost::REPORTED,
            'tokens' => ['in' => 400, 'out' => 700],
        ], 500, 200, [
            // مفاتيحُ عقدِ التشخيصِ كما يُرسلها المولِّدُ — لا كأسماءِ الأعمدة
            'finish'     => 'length',
            'reasoning'  => 688,
            'max_output' => 700,
            'tool'       => null,
        ]);

        $this->assertSame(688, (int) $e->reasoning_tokens);
        $this->assertSame('length', (string) $e->finish_reason);
        $this->assertSame(700, (int) $e->max_output_tokens);

        $this->assertStringNotContainsString('reasoning_content',
            (string) json_encode($e->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    /** **واسمُ الأداةِ من مفرداتٍ مغلقةٍ — فلا يحمل بياناتِ صاحبِ الجلسة** */
    public function test_اسمُ_الأداةِ_المُسجَّلُ_من_المفرداتِ_المغلقة(): void
    {
        $e = AiLedger::succeed($this->open(), ['tokens' => ['in' => 10, 'out' => 5]], 40,
            200, ['tool' => 'hub_count']);

        $this->assertContains((string) $e->tool_requested, \App\Support\AskTools::TOOLS);
    }

    /** **والعملةُ مُعلَنةٌ لا مفترَضةٌ** — فرقمٌ بلا عملةٍ لا يُجمَع */
    public function test_كلُّ_صفٍّ_يحمل_عملتَه(): void
    {
        $this->assertSame(AiCost::CURRENCY, (string) $this->open()->currency);
    }

    // ── البناء ────────────────────────────────────────────────────────

    private function open(array $ctx = []): AiUsageEvent
    {
        return AiLedger::open($ctx + [
            'request_id'  => (string) Str::uuid(),
            'correlation' => (string) Str::uuid(),
            'purpose'     => 'general',
            'feature'     => 'ask',
            'model_name'  => 'hub-general',
        ]);
    }
}
