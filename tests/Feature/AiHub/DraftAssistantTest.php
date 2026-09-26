<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Assist\DraftAssistant;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **المساعدُ التنفيذيّ — «مسودةٌ ثمّ تأكيد»** (المرحلة ٣ · `DraftAssistant`). يُمتحن بما يعد به:
 * يقرأ المصدرَ بعين السائل (المحجوبُ لا يغادر)، ويُصفّي المقترحَ بقائمةٍ بيضاء (لا مراجعَ من النموذج،
 * ولا خيارٌ خارج قائمته، ولا تاريخٌ فاسد)، والروابطُ من المصدر، **ولا يكتب شيئاً**.
 */
class DraftAssistantTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    /** @var list<array> */
    private array $replies = [];

    private int $at = 0;

    private Company $alpha;

    private User $asker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-aud-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);

        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $reply = $this->replies[min($this->at, max(0, count($this->replies) - 1))] ?? ['items' => []];
            $this->at++;

            return Http::response(LiteLlmFixtures::answer(json_encode($reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });
    }

    private function asker(array $matrix = [], array $fieldRules = []): User
    {
        $all = collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'مساعَدٌ ' . Str::random(5), 'scope' => 'all', 'flags' => [AskPolicy::FLAG => 1],
            'matrix' => array_merge($all, $matrix), 'field_rules' => $fieldRules]);

        return User::create(['name' => 'مستعملُ المساعد', 'email' => Str::random(9) . '@assist.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$this->alpha->id]]);
    }

    private function ticket(array $cols = []): string
    {
        $id = (string) Str::uuid();
        $pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $pid, 'name' => 'مشروعُ التذاكر', 'company_id' => $this->alpha->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('tickets')->insert(array_merge(['id' => $id, 'subject' => 'الفاتورةُ لا تُطبع من الجوال QWXZ-SUBJECT',
            'body' => 'العميلُ يطلب إصلاحَ طباعة الفواتير قبل نهاية الشهر', 'project_id' => $pid, 'notes' => 'ملاحظةٌ داخليّة QWXZ-HIDDEN-NOTE',
            'company_id' => $this->alpha->id, 'created_at' => now(), 'updated_at' => now()], $cols));

        return $id;
    }

    private function sentText(): string
    {
        return json_encode($this->sent, JSON_UNESCAPED_UNICODE);
    }

    public function test_مهمّةٌ_من_تذكرة_بالقائمة_البيضاء_والروابطُ_من_المصدر_ولا_كتابة(): void
    {
        $u = $this->asker([], ['tickets' => ['notes' => 'hide']]);
        $tid = $this->ticket();
        $pid = (string) DB::table('tickets')->where('id', $tid)->value('project_id');
        $prio = (string) collect(hub_mod('tasks')['fields'])->firstWhere('key', 'priority')['options'][0];
        $this->replies = [['items' => [[
            'title' => 'إصلاحُ طباعة الفواتير من الجوال', 'desc' => 'تتبّعُ سببِ فشل الطباعة وإصلاحُه',
            'priority' => $prio, 'due' => '2031-01-15', 'estH' => '6',
            'assigneeId' => (string) Str::uuid(), 'projectId' => (string) Str::uuid(), 'bogus' => 'x',
        ]]]];
        $tasksBefore = DB::table('tasks')->count();

        $r = DraftAssistant::draft($u, 'task', 'tickets', $tid);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $f = $r['drafts'][0]['fields'];
        $this->assertSame('إصلاحُ طباعة الفواتير من الجوال', $f['title']);
        $this->assertSame($prio, $f['priority']);
        $this->assertSame('2031-01-15', $f['due']);
        $this->assertSame('6', $f['estH']);
        $this->assertSame($tid, $f['ticketId'], 'التذكرةُ من المصدر');
        $this->assertSame($pid, $f['projectId'], 'المشروعُ من المصدر — لا الذي اخترعه النموذج');
        $this->assertArrayNotHasKey('assigneeId', $f, 'لا مرجعَ يخترعه النموذج');
        $this->assertContains('assigneeId', $r['dropped']);
        $this->assertContains('bogus', $r['dropped']);
        $this->assertStringStartsWith(route('m.create', ['module' => 'tasks']) . '?', $r['drafts'][0]['url']);

        $this->assertSame($tasksBefore, DB::table('tasks')->count(), 'المساعدُ لا يكتب سجلّاً');
        $this->assertStringContainsString('QWXZ-SUBJECT', $this->sentText(), 'المصدرُ وصل النموذج');
        $this->assertStringNotContainsString('QWXZ-HIDDEN-NOTE', $this->sentText(), 'الحقلُ المحجوبُ عن السائل لا يغادر');
        $this->assertSame(1, DB::table('ai_usage_events')->where('feature', 'assist')->count(), 'النداءُ محكومٌ ومسجَّل');
    }

    public function test_خيارٌ_خارج_قائمته_وتاريخٌ_فاسدٌ_يُسقطان(): void
    {
        $u = $this->asker();
        $tid = $this->ticket();
        $this->replies = [['items' => [['title' => 'مهمّة', 'priority' => 'عاجلٌ جدّاً جدّاً', 'due' => 'غداً']]]];

        $r = DraftAssistant::draft($u, 'task', 'tickets', $tid);

        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('priority', $r['drafts'][0]['fields']);
        $this->assertArrayNotHasKey('due', $r['drafts'][0]['fields']);
        $this->assertEqualsCanonicalizing(['priority', 'due'], $r['dropped']);
    }

    public function test_قراراتٌ_من_محضرٍ_مربوطةٌ_بالاجتماع(): void
    {
        $u = $this->asker();
        $mid = (string) Str::uuid();
        DB::table('meetings')->insert(['id' => $mid, 'title' => 'اجتماعُ الإطلاق', 'notes' => 'اتُّفق على تأجيل الإطلاق أسبوعاً واعتماد المورّد الثاني',
            'company_id' => $this->alpha->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->replies = [['items' => [['title' => 'تأجيلُ الإطلاق أسبوعاً'], ['title' => 'اعتمادُ المورّد الثاني', 'meetingId' => (string) Str::uuid()]]]];

        $r = DraftAssistant::draft($u, 'decisions', 'meetings', $mid);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertCount(2, $r['drafts']);
        foreach ($r['drafts'] as $d) $this->assertSame($mid, $d['fields']['meetingId'], 'الاجتماعُ من المصدر لا من النموذج');
        $this->assertSame(0, DB::table('decisions')->count());
    }

    public function test_مسودةُ_الردّ_نصٌّ_ينسخه_صاحبُه_ولا_يُرسَل_شيء(): void
    {
        $u = $this->asker();
        $tid = $this->ticket();
        $this->replies = [['reply' => ['مرحباً،', 'نعمل على إصلاح الطباعة وسنبلغكم فور انتهائه.']]];
        $comments = DB::table('comments')->count();

        $html = $this->actingAs($u)->post(route('assist.draft'), ['kind' => 'reply', 'module' => 'tickets', 'id' => $tid])
            ->assertOk()->getContent();

        $this->assertStringContainsString('نعمل على إصلاح الطباعة', $html);
        $this->assertStringContainsString('data-assist-reply', $html);
        $this->assertSame($comments, DB::table('comments')->count(), 'لا تعليقَ يُكتب');
        $this->assertSame(0, DB::table('outbox')->count(), 'ولا رسالةَ تُرسل');
    }

    public function test_سجلٌّ_خارج_النطاق_لا_يُقرأ_ولا_يُسأل_عنه_النموذج(): void
    {
        $beta = Company::create(['name_ar' => 'باء']);
        $u = $this->asker();
        $tid = $this->ticket(['company_id' => $beta->id]);

        $r = DraftAssistant::draft($u, 'task', 'tickets', $tid);

        $this->assertFalse($r['ok']);
        $this->assertSame('NOT_FOUND', $r['code']);
        $this->assertSame([], $this->sent, 'لم يُنادَ النموذج');
    }

    public function test_الأنواعُ_والبطاقة_لمن_يملك_الهدفَ_والمساعد_وحدَه(): void
    {
        $tid = $this->ticket();
        $noTasks = $this->asker(['tasks' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]);
        $this->assertSame(['reply'], array_keys(DraftAssistant::kindsFor($noTasks, 'tickets')), 'لا «مهمّة» لمن لا يضيف مهامّ');
        $this->assertSame([], DraftAssistant::kindsFor($noTasks, 'projects'), 'ولا شيءَ على وحدةٍ لا نوعَ فيها يملكه');
        $this->assertSame(['task'], array_keys(DraftAssistant::kindsFor($this->asker(), 'projects')), 'المهمّةُ من أيِّ سجلٍّ يراه');

        $full = $this->asker();
        $this->actingAs($full)->get(route('m.show', ['tickets', $tid]))->assertOk()->assertSee('data-assist-card', false);

        $role = $full->role;
        $role->flags = [];
        $role->save();
        $this->actingAs($full->fresh())->get(route('m.show', ['tickets', $tid]))->assertOk()->assertDontSee('data-assist-card', false);
        $this->actingAs($full->fresh())->post(route('assist.draft'), ['kind' => 'reply', 'module' => 'tickets', 'id' => $tid])->assertForbidden();

        Settings::put('assist.enabled', '0', 'test');
        $this->assertSame([], DraftAssistant::kindsFor($this->asker(), 'tickets'), 'المفتاحُ يطفئه');
    }
}
