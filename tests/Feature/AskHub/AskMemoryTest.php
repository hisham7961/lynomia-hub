<?php

namespace Tests\Feature\AskHub;

use App\Contracts\AskGenerator;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AskThread;
use App\Models\AskTurn;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskMemory;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **ذاكرةُ «اسأل Hub»** (المرحلة ٢ · `AskMemory`) — الشروطُ التي كتبتها المرحلةُ ٣ لقبول الحفظ،
 * كلٌّ باختبارٍ بمستخدمين معزولين (CLAUDE.md):
 *  ① الخيطُ لصاحبه وحدَه — غيرُه (والمالكُ) ٤٠٤ عرضاً وسؤالاً ومحواً.
 *  ② الجوابُ المحفوظُ يُعاد تحقّقُه عند كلِّ عرض: سجلٌّ خرج من النطاق أو حقلٌ حُجب ⇒ يُخفى.
 *  ③ المتابعةُ تحمل **الأسئلةَ** السابقة لا الأجوبة.
 *  ④ مشفَّرٌ في القاعدة، ولا أثرَ لنصّه في التدقيق.
 *  ⑤ احتفاظٌ ومحو، والإطفاءُ يوقف الحفظ والعرض.
 */
class AskMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->company = Company::create(['name_ar' => 'شركةُ الذاكرة']);
        $this->other = Company::create(['name_ar' => 'شركةٌ أخرى']);
    }

    private function asker(): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => Str::random(9) . '@mem.local',
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

    /** قراءةٌ حقيقيّةٌ ثمّ جواب — فالمسارُ يرفض جواباً لم يقرأ شيئاً (`NO_SERVER_READ`) */
    private function answer(string $text): array
    {
        return ['kind' => 'answer', 'answer' => $text, 'sources' => [1]];
    }

    private function bindAnswer(string $text): ScriptedGenerator
    {
        return $this->bind([['kind' => 'tool', 'tool' => 'hub_list', 'args' => ['module' => 'projects']], $this->answer($text)]);
    }

    // ═══ ③ + ④ الحفظُ مشفَّراً، والمتابعةُ بالأسئلة لا بالأجوبة ═══

    public function test_يُحفظ_مشفَّراً_والمتابعةُ_تحمل_الأسئلةَ_السابقةَ_لا_أجوبتَها(): void
    {
        $u = $this->asker();
        $this->ready();

        $this->bindAnswer('الجوابُ الأوّلُ السرّيُّ QWXZ-ANSWER-ONE');
        $this->actingAs($u)->post(route('ask.run'), ['q' => 'السؤالُ الأوّلُ QWXZ-QUESTION-ONE'])->assertOk();

        $thread = AskThread::query()->where('user_id', $u->id)->sole();
        $this->assertSame(1, AskTurn::query()->where('thread_id', $thread->id)->count());

        // مشفَّرٌ في مكانه: القيمةُ الخامُ لا تحمل النصّ
        $raw = DB::table('ask_turns')->where('thread_id', $thread->id)->first();
        $this->assertStringNotContainsString('QWXZ-QUESTION-ONE', (string) $raw->question);
        $this->assertStringNotContainsString('QWXZ-ANSWER-ONE', (string) $raw->answer);
        $this->assertStringNotContainsString('QWXZ-QUESTION-ONE', (string) DB::table('ask_threads')->value('title'));

        // المتابعة: السؤالُ السابقُ يصل النموذج، وجوابُه لا
        $gen = $this->bindAnswer('جوابُ المتابعة');
        $this->actingAs($u)->post(route('ask.run'), ['q' => 'وماذا عن الشهر الماضي؟', 'thread' => $thread->id])
            ->assertOk()->assertSee('QWXZ-ANSWER-ONE');   // التاريخُ يُعرَض لصاحبه
        $this->assertStringContainsString('QWXZ-QUESTION-ONE', $gen->askedWith, 'السؤالُ السابقُ سياقُ المتابعة');
        $this->assertStringContainsString('وماذا عن الشهر الماضي؟', $gen->askedWith);
        $this->assertStringNotContainsString('QWXZ-ANSWER-ONE', $gen->askedWith,
            'جوابٌ قُرئ بصلاحيّاتِ أمس لا يعود إلى النموذج — البياناتُ تُقرأ من جديد بالأدوات');
        $this->assertSame(2, AskTurn::query()->where('thread_id', $thread->id)->count(), 'المتابعةُ في الخيط نفسِه');
    }

    public function test_لا_أثرَ_لنصِّ_السؤالِ_في_سجلِّ_التدقيق(): void
    {
        $u = $this->asker();
        $this->ready();
        $this->bindAnswer('جوابٌ عاديّ');
        $this->actingAs($u)->post(route('ask.run'), ['q' => 'سؤالٌ خاصٌّ QWXZ-AUDIT-PROBE'])->assertOk();

        foreach (DB::table('audits')->get() as $row) {
            $this->assertStringNotContainsString('QWXZ-AUDIT-PROBE', json_encode($row, JSON_UNESCAPED_UNICODE),
                'التدقيقُ يقول مَن سأل ومتى — لا ماذا');
        }
    }

    // ═══ ① المِلكيّة ═══

    public function test_خيطُ_غيري_٤٠٤_عرضاً_وسؤالاً_ومحواً_ولو_كنتُ_المالك(): void
    {
        $alice = $this->asker();
        $bob = $this->asker();
        $this->ready();
        $this->bindAnswer('جوابُ أليس QWXZ-ALICE');
        $this->actingAs($alice)->post(route('ask.run'), ['q' => 'سؤالُ أليس'])->assertOk();
        $thread = AskThread::query()->where('user_id', $alice->id)->sole();

        foreach ([$bob, $this->owner] as $intruder) {
            $this->actingAs($intruder)->get(route('ask.index', ['thread' => $thread->id]))->assertNotFound();
            $this->bindAnswer('x');
            $this->actingAs($intruder)->post(route('ask.run'), ['q' => 'تسلّل', 'thread' => $thread->id])->assertNotFound();
            $this->actingAs($intruder)->post(route('ask.forget'), ['thread' => $thread->id])->assertNotFound();
            // ولا يظهر في قائمته، ولا يمحوه «امحُ الكلّ» عنده
            $this->actingAs($intruder)->get(route('ask.index'))->assertOk()->assertDontSee('سؤالُ أليس');
            $this->actingAs($intruder)->post(route('ask.forget'))->assertRedirect();
        }

        $this->assertSame(1, AskTurn::query()->where('thread_id', $thread->id)->count(), 'لم يُضَف دورٌ ولم يُمحَ شيء');
        $this->actingAs($alice)->get(route('ask.index', ['thread' => $thread->id]))->assertOk()->assertSee('QWXZ-ALICE');
    }

    // ═══ ② إعادةُ التحقّق عند كلِّ قراءة ═══

    public function test_الجوابُ_يُخفى_حين_يخرج_مصدرُه_من_نطاق_صاحبه(): void
    {
        $u = $this->asker();
        $p = Project::create(['name' => 'مشروعُ الذاكرة', 'company_id' => $this->company->id]);
        $t = AskMemory::record($u, null, 'ما حالُ المشروع؟', ['ok' => true, 'answer' => 'حالُه QWXZ-STATUS',
            'sources' => [['module' => 'projects', 'ids' => [$p->id], 'refs' => []]]]);

        $this->assertSame('حالُه QWXZ-STATUS', AskMemory::turns($u, $t)[0]['answer'], 'في النطاق ⇒ يُعرَض');

        // نُقل السائلُ إلى شركةٍ أخرى — المشروعُ خرج من نطاقه
        $u->forceFill(['companies' => [$this->other->id]])->save();
        $turn = AskMemory::turns($u->fresh(), $t)[0];
        $this->assertNull($turn['answer'], 'جوابٌ بُني على سجلٍّ خرج من نطاقه لا يُعرَض');
        $this->assertTrue($turn['hidden']);
        $this->assertSame('ما حالُ المشروع؟', $turn['question'], 'السؤالُ نصُّه هو فيبقى');

        $this->actingAs($u->fresh())->get(route('ask.index', ['thread' => $t->id]))->assertOk()
            ->assertDontSee('QWXZ-STATUS')->assertSee('أُخفي الجواب');
    }

    public function test_الجوابُ_يُخفى_حين_يُحجب_حقلٌ_كان_مرئيّاً_أو_تُسحب_الوحدة(): void
    {
        $u = $this->asker();
        $p = Project::create(['name' => 'مشروعٌ بحقول', 'company_id' => $this->company->id]);
        $t = AskMemory::record($u, null, 'كم ميزانيّتُه؟', ['ok' => true, 'answer' => 'ميزانيّتُه QWXZ-BUDGET',
            'sources' => [['module' => 'projects', 'ids' => [$p->id], 'refs' => []]]]);
        $this->assertNotNull(AskMemory::turns($u, $t)[0]['answer']);

        $field = collect(hub_visible_fields($u, 'projects', hub_mod('projects')))->pluck('key')->first();
        $role = $u->role;
        $role->field_rules = ['projects' => [$field => 'hide']];
        $role->save();
        $this->assertTrue(AskMemory::turns($u->fresh(), $t)[0]['hidden'], "حقلُ «{$field}» حُجب بعد الجواب");

        $role->field_rules = [];
        $role->matrix = array_merge((array) $role->matrix, ['projects' => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]]);
        $role->save();
        $this->assertTrue(AskMemory::turns($u->fresh(), $t)[0]['hidden'], 'سُحب عرضُ الوحدة كلُّها');
    }

    public function test_مصدرٌ_عابرٌ_للوحدات_يُتحقَّق_من_كلِّ_سجلٍّ_فيه_وما_لا_يُتحقَّق_منه_يُحجَب(): void
    {
        $u = $this->asker();
        $mine = Project::create(['name' => 'لي', 'company_id' => $this->company->id]);
        $theirs = Project::create(['name' => 'لغيري', 'company_id' => $this->other->id]);

        $ok = AskMemory::record($u, null, 'بحث', ['ok' => true, 'answer' => 'وُجد',
            'sources' => [['module' => null, 'ids' => [$mine->id], 'refs' => ['projects:' . $mine->id]]]]);
        $this->assertFalse(AskMemory::turns($u, $ok)[0]['hidden']);

        $leak = AskMemory::record($u, null, 'بحث', ['ok' => true, 'answer' => 'وُجد',
            'sources' => [['module' => null, 'ids' => [$theirs->id], 'refs' => ['projects:' . $theirs->id]]]]);
        $this->assertTrue(AskMemory::turns($u, $leak)[0]['hidden'], 'سجلٌّ خارجَ النطاق في نتيجة بحث');

        $blind = AskMemory::record($u, null, 'بحث', ['ok' => true, 'answer' => 'وُجد',
            'sources' => [['module' => null, 'ids' => [$mine->id], 'refs' => []]]]);
        $this->assertTrue(AskMemory::turns($u, $blind)[0]['hidden'], 'معرّفاتٌ بلا وحداتها لا يُفترَض أمانُها');
    }

    /** **عدٌّ بلا معرّفات** — يُحفظ شكلُ النطاق ويُخفى الجوابُ إن ضاق بعده (والتوسيعُ لا يُخفيه) */
    public function test_جوابُ_العدِّ_يُخفى_حين_يضيق_النطاقُ_ويبقى_حين_يتّسع(): void
    {
        $u = $this->asker();
        $t = AskMemory::record($u, null, 'كم مشروعاً؟', ['ok' => true, 'answer' => 'عددٌ ما',
            'sources' => [['tool' => 'hub_count', 'module' => 'projects', 'ids' => [], 'refs' => []]]]);
        $this->assertFalse(AskMemory::turns($u, $t)[0]['hidden']);

        $u->forceFill(['companies' => [$this->company->id, $this->other->id]])->save();
        $this->assertFalse(AskMemory::turns($u->fresh(), $t)[0]['hidden'], 'اتّسع النطاق ⇒ العدُّ ما زال ممّا يراه');

        $u->forceFill(['companies' => [$this->other->id]])->save();
        $this->assertTrue(AskMemory::turns($u->fresh(), $t)[0]['hidden'], 'ضاق النطاق ⇒ عدٌّ على ما لم يعد يراه');
    }

    public function test_سؤالٌ_فارغٌ_في_خيطٍ_لا_يُسقط_آخرَ_دورٍ_حقيقيٍّ_من_التاريخ(): void
    {
        $u = $this->asker();
        $this->ready();
        $t = AskMemory::record($u, null, 'السؤالُ الحقيقيّ', ['ok' => true, 'answer' => 'جوابٌ QWXZ-KEEP', 'sources' => []]);
        $this->bind([]);
        $this->actingAs($u)->post(route('ask.run'), ['q' => '   ', 'thread' => $t->id])->assertOk()->assertSee('QWXZ-KEEP');
        $this->assertSame(1, AskTurn::query()->where('thread_id', $t->id)->count());
    }

    public function test_سحبُ_رايةِ_المساعد_يُخفي_الأجوبةَ_ويغلق_الباب(): void
    {
        $u = $this->asker();
        $t = AskMemory::record($u, null, 'سؤال', ['ok' => true, 'answer' => 'جواب', 'sources' => []]);
        $role = $u->role;
        $role->flags = [];
        $role->save();

        $this->assertTrue(AskMemory::turns($u->fresh(), $t)[0]['hidden']);
        $this->actingAs($u->fresh())->get(route('ask.index', ['thread' => $t->id]))->assertForbidden();
    }

    // ═══ ⑤ الاحتفاظُ والمحوُ والإطفاء ═══

    public function test_المحوُ_لصاحبه_ومقصُّ_العمر_يمحو_الخامدَ_وحدَه(): void
    {
        $u = $this->asker();
        $a = AskMemory::record($u, null, 'أ', ['ok' => true, 'answer' => '١', 'sources' => []]);
        $b = AskMemory::record($u, null, 'ب', ['ok' => true, 'answer' => '٢', 'sources' => []]);

        $this->actingAs($u)->post(route('ask.forget'), ['thread' => $a->id])->assertRedirect(route('ask.index'));
        $this->assertNull(AskThread::find($a->id));
        $this->assertSame(0, AskTurn::query()->where('thread_id', $a->id)->count());
        $this->assertNotNull(AskThread::find($b->id));

        $old = AskMemory::record($u, null, 'قديم', ['ok' => true, 'answer' => '٣', 'sources' => []]);
        $old->forceFill(['last_at' => now()->subDays(AskMemory::days() + 1)])->save();
        $this->assertSame(1, AskMemory::prune());
        $this->assertNull(AskThread::find($old->id));
        $this->assertNotNull(AskThread::find($b->id), 'النشطُ باقٍ');

        $this->actingAs($u)->post(route('ask.forget'))->assertRedirect();
        $this->assertSame(0, AskThread::query()->where('user_id', $u->id)->count());
    }

    public function test_الذاكرةُ_المطفأةُ_لا_تحفظ_ولا_تعرض(): void
    {
        $u = $this->asker();
        $this->ready();
        Settings::put('ask.memory', '0', 'test');
        $this->bindAnswer('جواب');

        $this->actingAs($u)->post(route('ask.run'), ['q' => 'سؤالٌ بلا ذاكرة'])->assertOk()->assertDontSee('محادثاتُك');
        $this->assertSame(0, AskThread::query()->count());
        $this->assertSame(0, AskTurn::query()->count());
    }
}
