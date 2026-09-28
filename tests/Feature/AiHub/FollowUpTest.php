<?php

namespace Tests\Feature\AiHub;

use App\Models\AiCommitment;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Employee;
use App\Models\HubNotification;
use App\Models\User;
use App\Support\Ai\FollowUp\CommitmentExtractor;
use App\Support\Ai\FollowUp\CommitmentResolver;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\FollowUp\FollowUpSender;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **المتابِع** (docs/ai-hub/47 §العمود ب — المرحلة ٣): «قال إنه سيفعل، ثم صمت: ماذا حدث؟».
 * يُثبت: الاقتباسُ الحرفيّ شرطُ الالتزام، والإغلاقُ بالدليل قبل السؤال، وحدودُ الإزعاج (المهلة · سؤالان في
 * اليوم · الكتم)، والتصعيدُ للمدير المباشر وحدَه، وأنّ الجوابَ لصاحبه والفريقَ لمديره. بلا دينارٍ: `Http::fake`.
 */
class FollowUpTest extends TestCase
{
    /** @var list<array> */
    private array $replies = [];

    private int $at = 0;

    private int $calls = 0;

    private User $worker;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        // يومُ عملٍ ثابت (الاثنين، عطلةُ الأسبوع الافتراضيّة ٥,٦) — لا تتقلّب الأسئلةُ بيوم تشغيل الحزمة
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-28 11:00:00'));
        $this->seedCore();
        Cache::flush();

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();
        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-fu-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);
        Http::fake(function () {
            $this->calls++;
            $reply = $this->replies[min($this->at, max(0, count($this->replies) - 1))] ?? ['commitments' => []];
            $this->at++;

            return Http::response(LiteLlmFixtures::answer(json_encode($reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });

        $this->hubSetting('followup.enabled', '1');
        $this->hubSetting('reports.project_digest_profile', 'general');

        $this->manager = $this->user('المديرة المباشرة');
        $this->worker = $this->user('الموظّف الملتزم');
        Employee::create(['name' => 'المديرة', 'status' => 'نشط', 'user_id' => $this->manager->id]);
        Employee::create(['name' => 'الموظّف', 'status' => 'نشط', 'user_id' => $this->worker->id, 'manager_id' => $this->manager->id]);
    }

    private function user(string $name): User
    {
        return User::create(['name' => $name, 'email' => Str::random(8) . '@fu.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function report(array $cols, int $daysAgo = 3): string
    {
        $id = (string) Str::uuid();
        DB::table('work_updates')->insert(array_merge(['id' => $id, 'done' => 'عملٌ اليوم', 'created_by' => $this->worker->id,
            'work_date' => now()->subDays($daysAgo)->toDateString(), 'hours' => 4,
            'created_at' => now()->subDays($daysAgo)->setTime(16, 0), 'updated_at' => now()], $cols));

        return $id;
    }

    private function commitment(array $over = []): AiCommitment
    {
        return AiCommitment::create(array_merge(['user_id' => $this->worker->id, 'what' => 'إنهاء ربط بوابة الدفع',
            'quote' => 'غداً سأنهي ربط بوابة الدفع', 'source_id' => $this->report(['next' => 'غداً سأنهي ربط بوابة الدفع']),
            'said_on' => now()->subDays(6)->toDateString(), 'due_on' => now()->subDays(5)->toDateString(),
            'status' => 'open', 'dedupe' => Str::random(40)], $over));
    }

    private function gc(): ?\App\Support\Ai\GovernedCompletion
    {
        return null;
    }

    // ═══ الاستخراج ═══

    public function test_a_commitment_needs_a_verbatim_quote_and_the_cursor_reads_each_report_once(): void
    {
        $rid = $this->report(['next' => 'غداً سأنهي ربط بوابة الدفع مع البنك', 'doing' => 'أعمل على واجهة الدفع']);
        $this->replies = [['commitments' => [
            ['n' => 1, 'what' => 'إنهاء ربط بوابة الدفع', 'due' => null, 'quote' => 'غداً سأنهي ربط بوابة الدفع'],
            ['n' => 1, 'what' => 'تسليم المشروع كاملاً', 'due' => null, 'quote' => 'سأسلّم المشروع كاملاً للعميل'],
            ['n' => 9, 'what' => 'بندٌ مجهول', 'due' => null, 'quote' => 'لا وجود له في أيِّ تقرير'],
        ]]];

        $gc = $this->gc();
        $x = CommitmentExtractor::run($gc);

        $this->assertSame(1, $x['found'], 'المختلَقُ والمجهولُ يُسقَطان');
        $c = AiCommitment::query()->firstOrFail();
        $this->assertSame($rid, (string) $c->source_id);
        $this->assertSame(now()->subDays(2)->toDateString(), $c->due_on->toDateString(), 'بلا موعدٍ ⇒ اليومُ التالي للتقرير');

        $calls = $this->calls;
        $gc = $this->gc();
        CommitmentExtractor::run($gc);
        $this->assertSame($calls, $this->calls, 'المؤشّرُ تقدّم: لا يُقرأ التقريرُ مرّتين');
    }

    public function test_extraction_stops_by_itself_when_answers_say_it_is_wrong(): void
    {
        foreach (range(1, 10) as $i) {
            $this->commitment(['status' => 'dismissed', 'answer' => $i <= 7 ? 'wrong' : 'done', 'answered_at' => now()]);
        }
        $this->report(['next' => 'سأجهّز عرضَ الأسعار للعميل الجديد']);
        $this->assertTrue(FollowUp::accuracy()['disabled']);

        $gc = $this->gc();
        $x = CommitmentExtractor::run($gc);
        $this->assertSame('ACCURACY_DISABLED', $x['code']);
        $this->assertSame(0, $this->calls);
    }

    // ═══ الإغلاقُ بالدليل ═══

    public function test_a_closed_linked_task_closes_the_commitment_without_asking(): void
    {
        $tid = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $tid, 'title' => 'ربط البوابة', 'status' => 'مكتملة',
            'project_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->commitment(['task_id' => $tid]);

        $gc = $this->gc();
        CommitmentResolver::run($gc);
        FollowUpSender::run();

        $c->refresh();
        $this->assertSame('done', $c->status);
        $this->assertSame('ai', $c->closed_by);
        $this->assertSame(0, HubNotification::query()->where('kind', 'followup')->count(), 'لا سؤالَ عمّا ثبت');
    }

    public function test_a_later_report_mentioning_it_closes_it_only_with_a_real_quote(): void
    {
        $c = $this->commitment();
        $later = $this->report(['done' => 'أنهيتُ ربطَ بوابة الدفع واختبرتُ ثلاث عمليات ناجحة'], 1);
        $this->replies = [['results' => [['k' => 1, 'verdict' => 'done', 'n' => 1, 'quote' => 'أنهيتُ ربطَ بوابة الدفع']]]];

        $gc = $this->gc();
        CommitmentResolver::run($gc);

        $c->refresh();
        $this->assertSame('done', $c->status);
        $this->assertSame($later, (string) $c->evidence_id);

        $d = $this->commitment(['what' => 'تسليم التصميم', 'dedupe' => Str::random(40)]);
        $this->replies = [['results' => [['k' => 1, 'verdict' => 'done', 'n' => 1, 'quote' => 'سلّمتُ التصميمَ النهائيّ']]]];
        $this->at = 0;
        $gc = $this->gc();
        CommitmentResolver::run($gc);
        $this->assertSame('open', $d->fresh()->status, 'اقتباسٌ غيرُ موجودٍ لا يُغلق');
    }

    // ═══ السؤال والتصعيد ═══

    public function test_asking_waits_for_the_grace_is_grouped_and_capped_per_day(): void
    {
        $this->commitment(['due_on' => now()->subDay()->toDateString()]);             // ضمن المهلة (يومان)
        $this->assertSame(0, FollowUpSender::run()['asked']);

        $a = $this->commitment(['dedupe' => Str::random(40)]);
        $b = $this->commitment(['what' => 'تسليم التصميم', 'dedupe' => Str::random(40)]);
        $s = FollowUpSender::run();
        $this->assertSame(1, $s['users']);
        $this->assertSame(1, HubNotification::query()->where('kind', 'followup')->where('user_id', $this->worker->id)->count(),
            'إشعارٌ واحدٌ مجمَّع');
        $this->assertSame(1, $a->fresh()->asked_count);
        $this->assertSame(1, $b->fresh()->asked_count);

        FollowUpSender::run();
        $this->assertSame(1, $a->fresh()->asked_count, 'لا يُسأل الالتزامُ مرّتين في اليوم');

        // الإشعارُ يفتح «متابعاتي»
        $n = HubNotification::query()->where('kind', 'followup')->firstOrFail();
        $this->actingAs($this->worker)->get(route('notifications.go', $n->id))->assertRedirect(route('followups.mine'));
    }

    public function test_a_muted_employee_is_not_asked_and_not_escalated(): void
    {
        $this->worker->forceFill(['prefs' => ['mute' => ['followup']]])->save();
        $c = $this->commitment();

        FollowUpSender::run();

        $this->assertSame(0, HubNotification::query()->where('kind', 'followup')->count());
        $this->assertSame(0, $c->fresh()->asked_count, 'المكتومُ لا يُحسب سؤالاً');
    }

    public function test_no_question_on_the_weekend_or_on_approved_leave(): void
    {
        $c = $this->commitment();

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-02 11:00:00'));   // الجمعة
        $this->assertSame(0, FollowUpSender::run()['asked'], 'عطلةُ الأسبوع');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-28 11:00:00'));
        $emp = Employee::query()->where('user_id', $this->worker->id)->firstOrFail();
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $emp->id, 'type' => ((array) config('hub.leave.deduct_types', ['سنوية']))[0] ?? 'سنوية',
            'status' => 'معتمد', 'date_from' => '2026-09-27', 'date_to' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, FollowUpSender::run()['asked'], 'إجازةٌ معتمدة');
        $this->assertSame(0, $c->fresh()->asked_count);
    }

    public function test_after_two_unanswered_asks_only_the_direct_manager_is_told(): void
    {
        $c = $this->commitment(['asked_count' => 2, 'asked_at' => now()->subDays(2)]);

        FollowUpSender::run();

        $this->assertSame('escalated', $c->fresh()->status);
        $team = HubNotification::query()->where('kind', 'followup_team')->get();
        $this->assertCount(1, $team);
        $this->assertSame((string) $this->manager->id, (string) $team[0]->user_id);
        $this->assertStringContainsString('الموظّف الملتزم', $team[0]->text);
    }

    // ═══ الإجابة والصفحات ═══

    public function test_the_owner_of_the_commitment_answers_and_nobody_else_can(): void
    {
        $c = $this->commitment();
        $other = $this->user('زميلٌ آخر');

        $this->actingAs($other)->post(route('followups.answer', $c->id), ['answer' => 'done'])->assertNotFound();
        $this->assertSame('open', $c->fresh()->status);

        $this->actingAs($this->worker)->post(route('followups.answer', $c->id),
            ['answer' => 'postponed', 'date' => now()->addDays(5)->toDateString()])->assertRedirect();
        $this->assertSame(now()->addDays(5)->toDateString(), $c->fresh()->due_on->toDateString());
        $this->assertSame(0, $c->fresh()->asked_count);

        $this->actingAs($this->worker)->post(route('followups.answer', $c->id), ['answer' => 'wrong']);
        $this->assertSame('dismissed', $c->fresh()->status);
    }

    public function test_the_team_page_shows_only_direct_reports_and_the_manager_can_close(): void
    {
        $c = $this->commitment(['status' => 'escalated']);
        $stranger = $this->user('غريب');
        AiCommitment::create(['user_id' => $stranger->id, 'what' => 'التزامُ شخصٍ خارج الفريق', 'source_id' => (string) Str::uuid(),
            'said_on' => now()->toDateString(), 'due_on' => now()->toDateString(), 'status' => 'open', 'dedupe' => Str::random(40)]);

        $html = $this->actingAs($this->manager)->get(route('followups.team'))->assertOk()->getContent();
        $this->assertStringContainsString('إنهاء ربط بوابة الدفع', $html);
        $this->assertStringNotContainsString('التزامُ شخصٍ خارج الفريق', $html);

        $this->actingAs($this->worker)->get(route('followups.team'))->assertNotFound();
        $this->actingAs($this->manager)->post(route('followups.close', $c->id), ['status' => 'done'])->assertRedirect();
        $this->assertSame('manager', $c->fresh()->closed_by);

        $mine = $this->actingAs($this->worker)->get(route('followups.mine'))->assertOk()->getContent();
        $this->assertStringContainsString('إنهاء ربط بوابة الدفع', $mine);
    }

    public function test_the_switch_is_off_by_default(): void
    {
        $this->hubSetting('followup.enabled', '0');
        $this->assertFalse(FollowUp::enabled());
        $this->artisan('hub:followup')->assertSuccessful();
        $this->assertSame(0, $this->calls);
    }
}
