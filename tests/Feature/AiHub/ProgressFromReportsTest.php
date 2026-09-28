<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProposal;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\ReportDigest;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Reports\ProgressFromReports;
use App\Support\Ai\Reports\ProjectReportDigest;
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
 * **التقدّمُ من التقارير** (docs/ai-hub/47 — المرحلة ٢): نداءُ ملخّص المشروع نفسُه يُعيد تقديرَ التقدّم،
 * والتقديرُ يصير **اقتراحاً** بدليلٍ يتحقّق الخادمُ منه — لا كتابةً في المشروع ولا المهمّة.
 * بلا دينارٍ واحد: `Http::fake` يعترض كلَّ نداء.
 */
class ProgressFromReportsTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    private array $reply = [];

    private Company $co;

    private User $author;

    private string $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Cache::flush();
        $this->co = Company::create(['name_ar' => 'شركةُ التقدّم']);
        $this->author = User::create(['name' => 'كاتبُ التقارير', 'email' => 'writer@progress.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-pg-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);

        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);

            return Http::response(LiteLlmFixtures::answer(json_encode($this->reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });

        $this->hubSetting('reports.project_digest', '1');
        $this->hubSetting('reports.project_digest_profile', 'general');

        $this->pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $this->pid, 'name' => 'منصّةُ الحجوزات', 'company_id' => $this->co->id,
            'status' => 'قيد التنفيذ', 'progress' => 20, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function report(string $done, array $cols = []): string
    {
        $id = (string) Str::uuid();
        DB::table('work_updates')->insert(array_merge(['id' => $id, 'done' => $done, 'project_id' => $this->pid,
            'work_date' => now()->subDay()->toDateString(), 'created_by' => $this->author->id, 'company_id' => $this->co->id,
            'hours' => 3, 'created_at' => now()->subMinutes(30), 'updated_at' => now()], $cols));

        return $id;
    }

    private function summary(array $extra = []): array
    {
        return array_merge(['overview' => 'المشروعُ يتقدّم', 'achievements' => ['[P1] أنهى الدفع'], 'blockers' => [],
            'risks' => [], 'next' => [], 'team' => ['[P1] الخلفيّة']], $extra);
    }

    public function test_a_distant_estimate_becomes_a_proposal_with_verified_evidence_not_a_write(): void
    {
        $rid = $this->report('أطلقنا بوابةَ الدفع في بيئة الإنتاج وأنهينا لوحةَ الحجوزات كاملةً');
        $this->reply = $this->summary(['progress' => ['estimate' => 70, 'why' => 'البوابةُ واللوحةُ اكتملتا',
            'quotes' => [['n' => 1, 'quote' => 'أطلقنا بوابةَ الدفع في بيئة الإنتاج']]], 'task_progress' => []]);

        ProjectReportDigest::run();

        $this->assertNotNull(ReportDigest::query()->where('project_id', $this->pid)->first(), 'الملخّصُ كُتب كالمعتاد');
        $this->assertEquals(20, (float) DB::table('projects')->where('id', $this->pid)->value('progress'), 'لا كتابةَ في المشروع');

        $p = AiProposal::query()->where('kind', 'project_progress')->where('record_id', $this->pid)->first();
        $this->assertNotNull($p, 'التقديرُ صار اقتراحاً');
        $this->assertSame('70', $p->proposed_value);
        $this->assertEquals(20, (float) $p->current_value);
        $this->assertSame($rid, $p->evidence[0]['record_id']);
        $this->assertSame(ProgressFromReports::SOURCE, $p->source);

        // النموذجُ رأى الرقمين والتعليماتِ الإضافيّة — ولا اسمَ مشروع
        $sent = json_encode($this->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('recorded_progress', $sent);
        $this->assertStringContainsString('task_progress', $sent);
        $this->assertStringNotContainsString('منصّةُ الحجوزات', $sent);
    }

    public function test_one_invented_quote_among_true_ones_is_dropped_not_the_whole_estimate(): void
    {
        $rid = $this->report('أنهينا لوحةَ الحجوزات وربطنا الإشعارات بالبريد');
        $this->reply = $this->summary(['progress' => ['estimate' => 65, 'why' => 'لوحةٌ وإشعارات',
            'quotes' => [['n' => 1, 'quote' => 'سلّمنا المشروعَ للعميل كاملاً اليوم'], ['n' => 1, 'quote' => 'أنهينا لوحةَ الحجوزات']]]]);

        ProjectReportDigest::run();

        $p = AiProposal::query()->where('kind', 'project_progress')->firstOrFail();
        $this->assertCount(1, $p->evidence, 'الاقتباسُ المختلَقُ يُحذف ولا يُعرض دليلاً');
        $this->assertSame('أنهينا لوحةَ الحجوزات', $p->evidence[0]['quote']);
        $this->assertSame($rid, $p->evidence[0]['record_id']);
    }

    public function test_an_invented_quote_or_a_close_estimate_proposes_nothing(): void
    {
        $this->report('راجعنا واجهةَ الحجوزات مع العميل واتفقنا على التعديلات');
        $this->reply = $this->summary(['progress' => ['estimate' => 90, 'why' => 'x',
            'quotes' => [['n' => 1, 'quote' => 'سلّمنا المشروعَ كاملاً للعميل اليوم']]]]);
        ProjectReportDigest::run();
        $this->assertSame(0, AiProposal::count(), 'اقتباسٌ مختلَقٌ لا يصير اقتراحاً');
        $this->assertNotNull(ReportDigest::query()->where('project_id', $this->pid)->first(), 'والملخّصُ لا يتأثّر');

        $this->report('أنهينا اختبارَ صفحة الحجز على الجوال بنجاح', ['created_at' => now()->subMinutes(10)]);
        $this->reply = $this->summary(['progress' => ['estimate' => 25, 'why' => 'قريب',
            'quotes' => [['n' => 1, 'quote' => 'أنهينا اختبارَ صفحة الحجز على الجوال']]]]);
        ProjectReportDigest::run();
        $this->assertSame(0, AiProposal::count(), 'تقديرٌ ضمن العتبة لا يُقترح');
    }

    public function test_a_report_whose_text_contradicts_its_number_proposes_the_task_progress(): void
    {
        $tid = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $tid, 'title' => 'تصميمُ شاشة الحجز', 'project_id' => $this->pid, 'status' => 'قيد التنفيذ',
            'progress' => 80, 'assignee_id' => $this->author->id, 'created_at' => now(), 'updated_at' => now()]);
        $rid = $this->report('لم أبدأ العمل على التصميم بعد بسبب انتظار المحتوى من العميل', ['task_id' => $tid, 'progress' => 80]);
        $this->reply = $this->summary(['progress' => null, 'task_progress' => [
            ['n' => 1, 'estimate' => 0, 'why' => 'النصُّ يقول لم يبدأ', 'quote' => 'لم أبدأ العمل على التصميم بعد'],
            ['n' => 7, 'estimate' => 50, 'why' => 'بندٌ لا وجود له', 'quote' => 'لا شيء'],
        ]]);

        ProjectReportDigest::run();

        $p = AiProposal::query()->where('kind', 'task_progress')->where('record_id', $tid)->first();
        $this->assertNotNull($p);
        $this->assertSame('0', $p->proposed_value);
        $this->assertSame($rid, $p->evidence[0]['record_id']);
        $this->assertSame(1, AiProposal::count(), 'البندُ ذو الرقم المجهول يُسقَط');
        $this->assertEquals(80, (float) DB::table('tasks')->where('id', $tid)->value('progress'));
    }

    public function test_malformed_progress_is_ignored_and_the_switch_turns_it_off(): void
    {
        $this->report('أنهينا ربطَ الإشعارات مع خدمة الرسائل القصيرة');
        $this->reply = $this->summary(['progress' => ['estimate' => 'كثير'], 'task_progress' => 'x']);
        ProjectReportDigest::run();
        $this->assertSame(0, AiProposal::count());
        $this->assertNotNull(ReportDigest::query()->where('project_id', $this->pid)->first());

        $this->hubSetting('reports.progress_proposals', '0');
        $this->sent = [];
        $this->report('أنهينا لوحةَ التقارير الإداريّة للحجوزات', ['created_at' => now()->subMinutes(5)]);
        $this->reply = $this->summary(['progress' => ['estimate' => 90, 'why' => 'x',
            'quotes' => [['n' => 1, 'quote' => 'أنهينا لوحةَ التقارير الإداريّة']]]]);
        ProjectReportDigest::run();
        $this->assertSame(0, AiProposal::count(), 'الإطفاءُ يوقف الاقتراح');
        $this->assertStringNotContainsString('recorded_progress', json_encode($this->sent, JSON_UNESCAPED_UNICODE),
            'ولا يُرسل سياقُ التقدّم أصلاً');
    }
}
