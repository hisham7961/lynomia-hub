<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiPolicyRule;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use App\Support\Ai\Governance\AiBudgets;
use App\Support\Ai\Governance\AiGovernance;
use App\Support\Ai\Governance\AiPolicy;
use App\Support\Ai\Catalog\AiProviders;
use App\Support\Ai\Routing\AiRouteRun;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **التزامنُ و TOCTOU** (المرحلة ٤ · P4-W10) — الحالاتُ الصعبةُ لا المسارُ السعيد.
 *
 * ── **كيف يُختبَر سباقٌ في حزمةٍ ذاتِ عمليّةٍ واحدة؟** ──
 *
 * **بتقطيرِ السباقِ لا بتمثيلِه.** جوهرُ السباقِ أنّ طلبين يتّخذان قرارَهما
 * **من لقطةٍ واحدة**: كلاهما رأى «بقي دولار»، وكلاهما حكم أنّ دولاراً يكفي.
 * وما يحسم الأمرَ ليس تزامنُهما في الزمنِ بل **أين يُقيَّم الشرط**:
 *
 *  · في «اقرأ ثمّ اكتب» يُقيَّم الشرطُ **على القراءةِ القديمة** — فيمرّ الاثنان.
 *  · وفي `UPDATE … WHERE` يُقيَّم **لحظةَ الكتابةِ في القاعدة** — فيمرّ واحد.
 *
 * فاختبارٌ يبني اللقطةَ الواحدةَ ثمّ يطلب حجزين منها **يُنتج السقوطَ نفسَه
 * الذي يُنتجه السباقُ الحقيقيّ** — والأصلُ يُختبَر لا أثرُه. ويُدعَم ذلك
 * بحارسٍ يقرأ **شكلَ الجملة** فيمنع عودةَ الاصطلاحِ الخطِر في أيِّ تعديلٍ لاحق.
 *
 * **وحدُّ هذا الاختبارِ يُقال ولا يُخفى:** لا يُثبِت سلوكَ محرّكٍ تحت أحمالٍ
 * حقيقيّة — يُثبِت أنّ القرارَ يُتَّخذ في القاعدةِ لا في الذاكرة، وذاك هو
 * الفرقُ بين الصوابِ والعطب.
 */
class AiBudgetConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-RACE9f3b2c7e44aa1188';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');

        Http::fake(['*' => function ($req) {
            if (str_contains($req->url(), '/chat/completions')
                || str_contains($req->url(), '/health/test_connection')) {
                $this->fail('**اتّصالٌ مدفوعٌ من مسارِ تزامن**: ' . $req->url());
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

    private function model(AiProvider $p, string $alias, bool $on = true): AiModel
    {
        return AiModel::create([
            'provider_id' => $p->id, 'litellm_model_name' => $alias,
            'upstream_model' => 'fam/' . $alias, 'display_name' => $alias,
            'enabled' => $on, 'health' => 'OK',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm']],
            'limits' => [], 'params' => [],
            'pricing' => ['input_per_1k'  => ['v' => 0.001, 'src' => 'litellm'],
                          'output_per_1k' => ['v' => 0.002, 'src' => 'litellm']],
        ]);
    }

    private function ctx(mixed $u = null, string $purpose = 'general'): array
    {
        $c = AiGovernance::context($u ?? $this->owner, $purpose, 'ask');
        $c['hub_allowed'] = true;

        return $c;
    }

    private function budget(array $a = []): AiBudget
    {
        return AiBudget::create(array_merge([
            'key' => 'b-' . \Illuminate\Support\Str::random(6), 'label' => 'ميزانيّة',
            'scope_type' => 'global', 'period' => 'monthly',
            'limit_micro' => 100_000, 'enforce' => true, 'enabled' => true,
        ], $a));
    }

    // ═══ ① السباقُ مُقطَّراً: لقطةٌ واحدةٌ وطلبان ═══

    /**
     * **طلبان عند الحافّةِ من لقطةٍ واحدة — واحدٌ يمرّ لا اثنان.**
     *
     * والسقفُ ١٠٠٬٠٠٠ والطلبُ ٦٠٬٠٠٠ لكلٍّ: مجموعُهما ١٢٠٬٠٠٠ **فوقَ السقف**،
     * وكلاهما يرى عند لقطتِه أنّ ١٠٠٬٠٠٠ متاحةٌ فيحكم أنّ ٦٠٬٠٠٠ تكفي.
     */
    public function test_طلبان_عند_الحافّةِ_لا_يتجاوزان_السقفَ_معاً(): void
    {
        $b = $this->budget(['limit_micro' => 100_000]);

        // **اللقطةُ الواحدة**: كلا الطلبين يقرأ الحالَ قبل أن يكتب أحدُهما
        $snapshot = AiBudgets::status($b->fresh());
        $this->assertSame(100_000, $snapshot['available_micro']);

        $a = AiBudgets::reserve($this->ctx(), 60_000);
        $c = AiBudgets::reserve($this->ctx(), 60_000);

        $won = (int) $a['ok'] + (int) $c['ok'];

        $this->assertSame(1, $won, '**مرّ الاثنان فوقَ السقف** — القرارُ في الذاكرةِ لا في القاعدة');
        $this->assertSame(AskFailures::BUDGET_EXCEEDED, $c['code']);

        $after = AiBudgets::status($b->fresh());
        $this->assertLessThanOrEqual(100_000,
            $after['reserved_micro'] + $after['spent_micro'],
            '**تجاوزَ المحجوزُ السقفَ** — وهذا هو العطبُ بعينِه');
    }

    /** وعشرةُ طلباتٍ على سقفٍ يسع ثلاثةً ⇒ ثلاثةٌ بالضبطِ لا أكثر */
    public function test_عشرةُ_طلباتٍ_على_سقفٍ_يسع_ثلاثةً(): void
    {
        $b = $this->budget(['limit_micro' => 30_000]);

        $won = 0;
        for ($i = 0; $i < 10; $i++) {
            if (AiBudgets::reserve($this->ctx(), 10_000)['ok']) $won++;
        }

        $this->assertSame(3, $won);
        $this->assertSame(30_000, AiBudgets::status($b->fresh())['reserved_micro']);
    }

    // ═══ ② حارسُ الشكل: القرارُ في القاعدةِ لا في الذاكرة ═══

    /**
     * **جملةٌ واحدةٌ شرطيّةٌ لكلِّ حجز** — ولا قراءةَ تسبقها تُبنى عليها.
     *
     * والحارسُ يقرأ ما نفّذته القاعدةُ فعلاً، **فلا يُطمئنه تعليقٌ في شيفرة**:
     * من أعاد «اقرأ ثمّ قرّر ثمّ اكتب» غداً يُسقط هذا الصفَّ فوراً.
     */
    public function test_الحجزُ_جملةُ_تحديثٍ_شرطيّةٌ_لا_قراءةٌ_ثمّ_كتابة(): void
    {
        $this->budget(['limit_micro' => 100_000]);

        $sql = [];
        DB::listen(static function ($q) use (&$sql) { $sql[] = $q->sql; });

        AiBudgets::reserve($this->ctx(), 10_000);

        $updates = array_values(array_filter($sql,
            static fn ($s) => str_starts_with(strtolower(trim($s)), 'update ai_budget_periods')));

        $this->assertCount(1, $updates, 'حجزٌ واحدٌ يكتب مرّةً واحدة');
        $this->assertStringContainsString('spent_micro + reserved_micro', $updates[0],
            '**الشرطُ ليس في الجملة** — فهو يُقيَّم على قراءةٍ قديمةٍ ويمرّ الاثنان');

        foreach ($sql as $s) {
            $this->assertStringNotContainsStringIgnoringCase('for update', $s,
                '`SELECT … FOR UPDATE` لا تعرفه SQLite — فيُختبَر على محرّكٍ ويعمل على آخر');
        }
    }

    // ═══ ③ TOCTOU: ما يتغيّر بين الاختيارِ والتنفيذ ═══

    /** **سُحبت صلاحيّتُه بعد فتحِ الطلب** — فالقبولُ التالي يسقط */
    public function test_سحبُ_التخويلِ_أثناءَ_الطلبِ_يُسقط_ما_بعدَه(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-toctou');

        $ctx = $this->ctx();
        $this->assertTrue(AiGovernance::admit($ctx, $m, 1000, 100)['ok']);

        // **التخويلُ يُقرأ من السياقِ لا من ذاكرةِ أوّلِ الطلب**
        $ctx['hub_allowed'] = false;

        $r = AiGovernance::admit($ctx, $m, 1000, 100);
        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::UNAUTHORIZED, $r['code']);
    }

    /** **تغيّرت السياسةُ أثناءَ التنفيذ** — فالقفزةُ التاليةُ تُقاس بالجديدةِ لا بالقديمة */
    public function test_تغيُّرُ_السياسةِ_أثناءَ_التنفيذِ_يحكم_القفزةَ_التالية(): void
    {
        $p  = $this->provider();
        $m1 = $this->model($p, 'hub-first');
        $m2 = $this->model($p, 'hub-second');
        $c  = $this->ctx();

        $this->assertTrue(AiGovernance::admitModel($c, $m2)['ok']);

        AiPolicyRule::create(['key' => 'ban-second', 'label' => 'امنع الثاني',
            'effect' => AiPolicy::DENY, 'scope_type' => 'global',
            'model_id' => (string) $m2->id, 'enabled' => true]);

        $this->assertTrue(AiGovernance::admitModel($c, $m1)['ok']);
        $this->assertFalse(AiGovernance::admitModel($c, $m2)['ok'],
            '**السياسةُ قُرئت مرّةً وحُفظت** — فصار الاحتياطُ بابَ التفافٍ عليها');
    }

    /** **عُطِّل النموذجُ بين الاختيارِ والتنفيذ** — فلا يُنادى */
    public function test_تعطيلُ_النموذجِ_بين_الاختيارِ_والتنفيذِ_يمنع_النداء(): void
    {
        $p = $this->provider();
        $m = $this->model($p, 'hub-live');
        $c = $this->ctx();

        $this->assertTrue(AiGovernance::admitModel($c, $m)['ok']);

        $m->forceFill(['enabled' => false])->save();

        $r = AiGovernance::admitModel($c, $m->fresh());
        $this->assertFalse($r['ok']);
        $this->assertSame(AskFailures::MODEL_UNAVAILABLE, $r['code']);
    }

    // ═══ ④ الاحتياطُ لا يلتفّ على السياسة ═══

    /**
     * **قفزةُ احتياطٍ إلى نموذجٍ تمنعه السياسةُ لا تُؤخَذ.**
     *
     * وهذا هو الثابتُ الذي بُني `governBy` كلُّه لأجلِه: السماحُ بـ«أ» ليس
     * سماحاً بـ«ب».
     */
    public function test_الاحتياطُ_يتخطّى_نموذجاً_تمنعه_السياسة(): void
    {
        $p  = $this->provider();
        $m1 = $this->model($p, 'hub-a');
        $m2 = $this->model($p, 'hub-banned');
        $m3 = $this->model($p, 'hub-c');
        $c  = $this->ctx();

        AiPolicyRule::create(['key' => 'ban-b', 'label' => 'امنع الثاني',
            'effect' => AiPolicy::DENY, 'scope_type' => 'global',
            'model_id' => (string) $m2->id, 'enabled' => true]);

        $run = AiRouteRun::over([$m1, $m2, $m3])->governBy(AiGovernance::gateFor($c));
        $d   = $run->fail(['code' => 404]);

        $this->assertSame('fallback', $d['action']);
        $this->assertSame('hub-c', $d['model'],
            '**قفز الاحتياطُ إلى نموذجٍ تمنعه السياسة**');
    }

    /** وبلا حاكمٍ محقونٍ تعمل الرحلةُ كما كانت — فالحقنُ إضافةٌ لا كسر */
    public function test_رحلةٌ_بلا_حاكمٍ_تعمل_كما_كانت(): void
    {
        $p  = $this->provider();
        $m1 = $this->model($p, 'hub-a2');
        $m2 = $this->model($p, 'hub-b2');

        $d = AiRouteRun::over([$m1, $m2])->fail(['code' => 404]);

        $this->assertSame('fallback', $d['action']);
        $this->assertSame('hub-b2', $d['model']);
    }

    // ═══ ⑤ التكرارُ وإعادةُ الإرسال ═══

    /** **التزامٌ مكرَّرٌ على الحجزِ نفسِه لا يُنقص المحجوزَ تحت الصفر** */
    public function test_التزامٌ_مكرَّرٌ_لا_يُفسد_العدّاد(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 200_000);

        AiBudgets::commit($r['holds'], 150_000, 100);
        AiBudgets::commit($r['holds'], 150_000, 100);   // إرسالٌ مكرَّر

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(0, $s['reserved_micro'], '**المحجوزُ دون الصفرِ يجعل السقفَ بلا معنى**');
        $this->assertGreaterThanOrEqual(0, $s['reserved_micro']);
    }

    public function test_إفراجٌ_مكرَّرٌ_لا_يُنقص_الطلباتِ_تحت_الصفر(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 100_000);

        AiBudgets::releaseAll($r['holds']);
        AiBudgets::releaseAll($r['holds']);
        AiBudgets::releaseAll($r['holds']);

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(0, $s['requests']);
        $this->assertSame(0, $s['reserved_micro']);
    }

    /** **وانتهاءٌ مكرَّرٌ لا يُفرج مرّتين عن حجزٍ واحد** */
    public function test_انتهاءٌ_مكرَّرٌ_لا_يُفرج_مرّتين(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $p = $this->provider();
        $m = $this->model($p, 'hub-exp');

        $a = AiGovernance::admit($this->ctx(), $m, 300_000, 100);
        $a['event']->forceFill(['started_at' => now()->subHours(3)])->save();

        $this->assertSame(1, \App\Support\Ai\Governance\AiLedger::expireStale());
        $this->assertSame(0, \App\Support\Ai\Governance\AiLedger::expireStale(),
            'صفٌّ انتهى مرّةً لا يُلتقَط ثانيةً');

        $this->assertSame(0, AiBudgets::status($b->fresh())['reserved_micro']);
    }

    // ═══ ⑥ أعدادٌ شاذّة ═══

    /** **رموزٌ سالبةٌ من ردٍّ مشوَّهٍ لا تُنقص المُنفَق** */
    public function test_رموزٌ_سالبةٌ_لا_تُنقص_العدّاد(): void
    {
        $b = $this->budget(['limit_micro' => 1_000_000]);
        $r = AiBudgets::reserve($this->ctx(), 100_000);

        AiBudgets::commit($r['holds'], -999_999, -5000);

        $s = AiBudgets::status($b->fresh());
        $this->assertSame(0, $s['spent_micro'], 'كلفةٌ سالبةٌ تُقصّ إلى صفرٍ لا تُضاف بالسالب');
        $this->assertSame(0, $s['tokens']);
    }

    public function test_حجزٌ_بقيمةٍ_سالبةٍ_يُعامَل_صفراً(): void
    {
        $b = $this->budget(['limit_micro' => 1000]);

        $this->assertTrue(AiBudgets::reserve($this->ctx(), -50_000)['ok']);
        $this->assertSame(0, AiBudgets::status($b->fresh())['reserved_micro']);
    }

    // ═══ ⑦ عزلُ الشركات ═══

    /** **ميزانيّةُ شركةٍ لا تُصرَف على عمليّةِ شركةٍ أخرى** */
    public function test_ميزانيّةُ_شركةٍ_لا_تحكم_عمليّةَ_غيرِها(): void
    {
        $mine = $this->budget(['key' => 'mine', 'scope_type' => 'company',
            'scope_id' => 'co-mine', 'limit_micro' => 10]);

        $ctx = $this->ctx();
        $ctx['company_id'] = 'co-other';

        $this->assertTrue(AiBudgets::reserve($ctx, 900_000)['ok'],
            'ميزانيّةُ شركةٍ أخرى منعت عمليّةً ليست لها');
        $this->assertSame(0, AiBudgets::status($mine->fresh())['reserved_micro']);
    }

    public function test_ميزانيّةُ_شركةٍ_تحكم_عمليّةَ_شركتِها(): void
    {
        $this->budget(['key' => 'co', 'scope_type' => 'company',
            'scope_id' => 'co-mine', 'limit_micro' => 10]);

        $ctx = $this->ctx();
        $ctx['company_id'] = 'co-mine';

        $this->assertFalse(AiBudgets::reserve($ctx, 900_000)['ok']);
    }

    // ═══ ⑧ الفتراتُ تنقضي ═══

    public function test_فترةٌ_جديدةٌ_تبدأ_بعدّادٍ_نظيف(): void
    {
        $b = $this->budget(['period' => 'daily', 'limit_micro' => 10_000]);

        \Illuminate\Support\Carbon::setTestNow('2026-09-20 10:00:00');
        $this->assertTrue(AiBudgets::reserve($this->ctx(), 10_000)['ok']);
        $this->assertFalse(AiBudgets::reserve($this->ctx(), 10_000)['ok']);

        \Illuminate\Support\Carbon::setTestNow('2026-09-21 10:00:00');
        $this->assertTrue(AiBudgets::reserve($this->ctx(), 10_000)['ok'],
            '**يومٌ جديدٌ وسقفٌ قديم** — الفترةُ لم تنقضِ');

        $this->assertSame('2026-09-21', AiBudgets::status($b->fresh())['period_key']);
        \Illuminate\Support\Carbon::setTestNow();
    }

    /** **وفترةٌ منقضيةٌ تبقى في السجلِّ** — فالتاريخُ لا يُمحى بانقضاءِ شهر */
    public function test_الفترةُ_المنقضيةُ_تبقى_مقروءة(): void
    {
        $b = $this->budget(['period' => 'daily', 'limit_micro' => 10_000]);

        \Illuminate\Support\Carbon::setTestNow('2026-09-20 10:00:00');
        AiBudgets::reserve($this->ctx(), 5000);

        \Illuminate\Support\Carbon::setTestNow('2026-09-21 10:00:00');
        AiBudgets::reserve($this->ctx(), 5000);

        $this->assertSame(2, $b->fresh()->periods()->count());
        \Illuminate\Support\Carbon::setTestNow();
    }

    // ═══ ⑨ ولا نموذجَ يُنادى بلا صفٍّ في السجلّ ═══

    public function test_كلُّ_قبولٍ_يفتح_صفّاً_وكلُّ_رفضٍ_لا_يفتح(): void
    {
        $this->budget(['limit_micro' => 100_000]);
        $p = $this->provider();
        $m = $this->model($p, 'hub-count');

        $this->assertTrue(AiGovernance::admit($this->ctx(), $m, 40_000, 10)['ok']);
        $this->assertTrue(AiGovernance::admit($this->ctx(), $m, 40_000, 10)['ok']);
        $this->assertFalse(AiGovernance::admit($this->ctx(), $m, 40_000, 10)['ok']);

        $this->assertSame(2, AiUsageEvent::query()->count(),
            'صفٌّ لكلِّ نداءٍ أُذن به — ولا صفَّ لرفض');
    }
}
