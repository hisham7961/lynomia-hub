<?php

namespace Tests\Feature\AiHub;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Assist\DraftAssistant;
use App\Support\Ai\Dev\ErrorTriage;
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
 * **مساعدُ التطوير** (المرحلة ٥ — «لا مطوّرٌ ذاتيّ»): ملاحظاتُ الإصدار من الالتزامات الملصقة وما أُنجز
 * **بعين السائل**، وشرحُ الخطأ للمالك بمقتطفٍ **من داخل الجذر حصراً** ونموذجِ «مشكلة» معبّأ. لا يكتب شيئاً.
 */
class DevAssistantTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    /** @var list<array> */
    private array $replies = [];

    private Company $alpha;

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
            'credential_name' => 'hub-dev-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-coding',
            'upstream_model' => 'fake/hub-coding', 'display_name' => 'hub-coding', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        foreach (['general', 'coding'] as $p) AiProfiles::attach(AiProfile::query()->where('key', $p)->firstOrFail(), $model);

        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $reply = $this->replies[0] ?? ['items' => []];

            return Http::response(LiteLlmFixtures::answer(json_encode($reply, JSON_UNESCAPED_UNICODE), LiteLlmFixtures::usage()), 200);
        });
    }

    private function sentText(): string
    {
        return json_encode($this->sent, JSON_UNESCAPED_UNICODE);
    }

    private function member(array $matrix = []): User
    {
        $all = collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'مطوّرٌ ' . Str::random(5), 'scope' => 'all', 'flags' => [AskPolicy::FLAG => 1],
            'matrix' => array_merge($all, $matrix)]);

        return User::create(['name' => 'مطوّر', 'email' => Str::random(9) . '@dev.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$this->alpha->id]]);
    }

    private function row(string $table, array $cols): string
    {
        $id = (string) Str::uuid();
        $base = ['id' => $id, 'created_at' => now(), 'updated_at' => now()];
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) $base['company_id'] = $this->alpha->id;
        DB::table($table)->insert($cols + $base);

        return $id;
    }

    // ═══ ① ملاحظاتُ الإصدار ═══

    public function test_ملاحظاتُ_الإصدار_من_الالتزامات_وما_أُنجز_في_النافذة_بعين_السائل_ولا_كتابة(): void
    {
        $pid = $this->row('projects', ['name' => 'تطبيقُ الجوال', 'company_id' => $this->alpha->id]);
        $other = $this->row('projects', ['name' => 'مشروعٌ آخر', 'company_id' => $this->alpha->id]);
        $this->row('code_releases', ['ver' => '1.0.0', 'project_id' => $pid, 'date' => '2031-01-01']);
        $rel = $this->row('code_releases', ['ver' => '1.1.0', 'project_id' => $pid, 'date' => '2031-02-01']);

        $this->row('tasks', ['title' => 'QWXZ-IN-WINDOW تصديرُ الفواتير', 'status' => 'مكتملة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('tasks', ['title' => 'QWXZ-BEFORE-PREV مهمّةٌ قديمة', 'status' => 'مكتملة', 'project_id' => $pid, 'updated_at' => '2030-12-20 10:00:00']);
        $this->row('tasks', ['title' => 'QWXZ-OPEN مهمّةٌ مفتوحة', 'status' => 'جديدة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('tasks', ['title' => 'QWXZ-OTHER-PROJ', 'status' => 'مكتملة', 'project_id' => $other, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('issues', ['title' => 'QWXZ-ISSUE-FIXED طباعةُ الإيصال', 'status' => 'محلولة', 'project_id' => $pid, 'updated_at' => '2031-01-20 10:00:00']);

        $u = $this->member();
        $this->replies = [['reply' => ['✨ جديد', '- تصديرُ الفواتير']]];
        $before = [DB::table('code_releases')->count(), DB::table('tasks')->count(), DB::table('issues')->count()];

        $this->assertArrayHasKey('notes', DraftAssistant::kindsFor($u, 'code'));
        $r = DraftAssistant::draft($u, 'notes', 'code', $rel, "a1b2c3d QWXZ-COMMIT-LOG add invoice export\ne4f5a6b fix receipt printing");

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertStringContainsString('تصديرُ الفواتير', (string) $r['text']);
        $sent = $this->sentText();
        $this->assertStringContainsString('QWXZ-COMMIT-LOG', $sent, 'السجلُّ الملصَق يصل');
        $this->assertStringContainsString('QWXZ-IN-WINDOW', $sent, 'ما أُنجز في النافذة');
        $this->assertStringContainsString('QWXZ-ISSUE-FIXED', $sent);
        $this->assertStringContainsString('بعد 2031-01-01', $sent, 'النافذةُ من الإصدار السابق');
        foreach (['QWXZ-BEFORE-PREV', 'QWXZ-OPEN', 'QWXZ-OTHER-PROJ'] as $no) $this->assertStringNotContainsString($no, $sent, $no);
        $this->assertSame($before, [DB::table('code_releases')->count(), DB::table('tasks')->count(), DB::table('issues')->count()], 'لا كتابة');
        $this->assertSame(1, DB::table('ai_usage_events')->where('feature', 'assist')->count());
    }

    public function test_ملاحظاتُ_الإصدار_لا_تحمل_ما_لا_يراه_السائل(): void
    {
        $pid = $this->row('projects', ['name' => 'تطبيقُ الجوال', 'company_id' => $this->alpha->id]);
        $rel = $this->row('code_releases', ['ver' => '2.0.0', 'project_id' => $pid, 'date' => '2031-02-01']);
        $this->row('tasks', ['title' => 'QWXZ-TASK-UNSEEN', 'status' => 'منجزة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('issues', ['title' => 'QWXZ-ISSUE-SEEN', 'status' => 'مغلقة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);

        // لا يرى المهامّ أصلاً — فلا عنوانَ منها ولا عدد
        $u = $this->member(['tasks' => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]]);
        $this->replies = [['reply' => ['- إصلاح']]];

        $r = DraftAssistant::draft($u, 'notes', 'code', $rel);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertStringNotContainsString('QWXZ-TASK-UNSEEN', $this->sentText());
        $this->assertStringContainsString('QWXZ-ISSUE-SEEN', $this->sentText());
        $this->assertStringContainsString('مهامُّ أُنجزت: غيرُ متاحٍ لك', $this->sentText());
    }

    public function test_بطاقةُ_الإصدار_تحمل_حقلَ_السجلّ_والمسارُ_يمرّره(): void
    {
        $pid = $this->row('projects', ['name' => 'مشروع', 'company_id' => $this->alpha->id]);
        $rel = $this->row('code_releases', ['ver' => '3.0.0', 'project_id' => $pid, 'date' => '2031-03-01']);
        $u = $this->member();
        $this->replies = [['reply' => ['- سطر']]];

        $this->actingAs($u)->get(route('m.show', ['code', $rel]))->assertOk()->assertSee('name="log"', false);
        $this->actingAs($u)->post(route('assist.draft'), ['kind' => 'notes', 'module' => 'code', 'id' => $rel, 'log' => 'QWXZ-VIA-ROUTE'])
            ->assertOk()->assertSee('مسودةُ ملاحظاتِ الإصدار');
        $this->assertStringContainsString('QWXZ-VIA-ROUTE', $this->sentText());
    }

    // ═══ ② شرحُ الخطأ ═══

    private function error(array $cols = []): ErrorEvent
    {
        return ErrorEvent::create(array_merge(['hash' => Str::random(40), 'kind' => 'php',
            'message' => 'Call to a member function name() on null', 'file' => base_path('app/Models/Task.php'), 'line' => 20,
            'url' => 'https://hub.test/m/tasks?token=QWXZ-QUERY-SECRET', 'method' => 'GET', 'count' => 3, 'status' => 'جديد',
            'first_seen' => now()->subDay(), 'last_seen' => now()], $cols));
    }

    public function test_الشرحُ_للمالك_بمقتطفٍ_من_الجذر_ونموذجُ_مشكلةٍ_معبّأ_ولا_كتابة(): void
    {
        $e = $this->error();
        $sev = (string) collect(hub_mod('issues')['fields'])->firstWhere('key', 'severity')['options'][1];
        $this->replies = [['items' => [['title' => 'استدعاءٌ على قيمةٍ فارغة', 'cause' => 'العلاقةُ غيرُ محمّلة',
            'fix' => 'تحقّق من القيمة قبل الاستدعاء في Task.php:20', 'severity' => $sev, 'priority' => 'غيرُ موجودة',
            'assigneeId' => (string) Str::uuid(), 'affected' => '/etc/passwd']]]];
        $issues = DB::table('issues')->count();

        $res = $this->actingAs($this->owner)->post(route('errors.explain', $e->id))->assertOk();
        $res->assertSee('العلاقةُ غيرُ محمّلة')->assertSee('افتح النموذجَ معبّأً');

        $sent = $this->sentText();
        $taskLine = explode("\n", (string) file_get_contents(base_path('app/Models/Task.php')))[19];
        $this->assertStringContainsString(trim(json_encode(trim($taskLine), JSON_UNESCAPED_UNICODE), '"'), $sent, 'المقتطفُ من الجذر يصل');
        $this->assertStringNotContainsString('QWXZ-QUERY-SECRET', $sent, 'سلسلةُ الاستعلام لا تغادر');

        $r = ErrorTriage::explain($this->owner, $e);
        $f = $r['draft']['fields'];
        $this->assertSame('استدعاءٌ على قيمةٍ فارغة', $f['title']);
        $this->assertSame($sev, $f['severity']);
        $this->assertArrayNotHasKey('priority', $f, 'خيارٌ خارج قائمته يُسقط');
        $this->assertContains('priority', $r['dropped']);
        $this->assertArrayNotHasKey('assigneeId', $f, 'لا مرجعَ من النموذج');
        $this->assertSame('app/Models/Task.php:20', $f['affected'], 'الموضعُ من الخادم لا من النموذج');
        $this->assertSame('مشكلة', $f['kind']);
        $this->assertSame(now()->toDateString(), $f['found']);
        $this->assertStringStartsWith(route('m.create', ['module' => 'issues']) . '?', $r['draft']['url']);

        $this->assertSame($issues, DB::table('issues')->count(), 'لا مشكلةَ تُكتب');
        $this->assertSame('جديد', $e->fresh()->status, 'ولا حالةُ الخطأ');
        $this->assertSame(2, DB::table('ai_usage_events')->where('feature', 'dev')->count(), 'نداءٌ محكومٌ مسجَّل لكلِّ شرح');
    }

    public function test_ملفٌّ_خارج_الجذر_أو_env_لا_يُقرأ_ولا_يغادر(): void
    {
        $probe = base_path('.env.devprobe');
        file_put_contents($probe, "LINE1\nAPP_SECRET=QWXZ-ENV-SECRET\nLINE3\n");
        try {
            $this->replies = [['items' => [['title' => 't', 'cause' => 'c', 'fix' => 'f']]]];
            ErrorTriage::explain($this->owner, $this->error(['file' => $probe, 'line' => 2]));
            ErrorTriage::explain($this->owner, $this->error(['file' => base_path('../../../etc/hostname'), 'line' => 1]));
            ErrorTriage::explain($this->owner, $this->error(['file' => base_path('app/../.env.devprobe'), 'line' => 2]));
        } finally {
            @unlink($probe);
        }
        $this->assertCount(3, $this->sent);
        $this->assertStringNotContainsString('QWXZ-ENV-SECRET', $this->sentText());
        $this->assertStringNotContainsString('الشيفرةُ حول السطر', $this->sentText(), 'لا مقتطفَ لملفٍّ خارج الحارس');
    }

    public function test_غيرُ_المالك_لا_يرى_الزرَّ_ولا_يصل_المسار(): void
    {
        $e = $this->error();
        $u = $this->member();

        $this->assertFalse(ErrorTriage::ready($u));
        $this->actingAs($u)->post(route('errors.explain', $e->id))->assertForbidden();
        $this->assertSame([], $this->sent);

        $this->actingAs($this->owner)->get(route('errors.show', $e->id))->assertOk()->assertSee('data-dev-explain-form', false);
        Settings::put('dev.enabled', '0', 'test');
        $this->actingAs($this->owner)->get(route('errors.show', $e->id))->assertOk()->assertDontSee('data-dev-explain-form', false);
        $this->actingAs($this->owner)->post(route('errors.explain', $e->id))->assertForbidden();
    }

    // ═══ من المراجعة العدائيّة (v2.609.2) ═══

    public function test_مسارٌ_مزروعٌ_عبر_jslog_لا_يُرسل_أسرارَ_المشروع_إلى_النموذج(): void
    {
        $dir = base_path('bootstrap/cache');
        $probe = $dir . '/devprobe_cfg.php';
        file_put_contents($probe, "<?php\nreturn [\n  'key' => 'base64:QWXZAPPKEY123456',\n  'password' => 'QWXZ-DBPASS-9876',\n];\n");
        try {
            // عضوٌ عاديٌّ يزرع الملفَّ والسطر
            $this->actingAs($this->member())->postJson(route('jslog'), ['message' => 'boom', 'source' => $probe, 'line' => 3])->assertSuccessful();
            $e = ErrorEvent::query()->where('kind', 'js')->orderByDesc('last_seen')->orderByDesc('id')->firstOrFail();
            $this->assertSame($probe, $e->file);
            $this->replies = [['items' => [['title' => 't', 'cause' => 'c', 'fix' => 'f']]]];

            $this->actingAs($this->owner)->post(route('errors.explain', $e->id))->assertOk();
            $this->assertStringNotContainsString('QWXZAPPKEY', $this->sentText());
            $this->assertStringNotContainsString('QWXZ-DBPASS', $this->sentText());

            // ولا تعرضه شاشةُ المالك نفسُها — ولو كان الخطأُ php
            $e->forceFill(['kind' => 'php'])->save();
            $this->actingAs($this->owner)->get(route('errors.show', $e->id))->assertOk()->assertDontSee('QWXZ-DBPASS');
            ErrorTriage::explain($this->owner, $e->fresh());
            $this->assertStringNotContainsString('QWXZ-DBPASS', $this->sentText(), 'ولا للنموذج');
        } finally {
            @unlink($probe);
        }
    }

    public function test_رابطُ_المشكلة_المعبّأ_يبقى_تحت_حدِّ_سطر_الطلب(): void
    {
        $long = str_repeat('سببٌ مطوّلٌ للخطأ ', 120);
        $this->replies = [['items' => [['title' => str_repeat('عنوان ', 40), 'cause' => $long, 'fix' => $long]]]];

        $r = ErrorTriage::explain($this->owner, $this->error());
        $this->assertLessThan(7000, strlen($r['draft']['url']));
        $this->assertGreaterThan(1000, mb_strlen($r['explain']['cause']), 'الشرحُ المعروضُ لا يُقصّ بقصِّ الرابط');
    }

    public function test_ملاحظاتُ_الإصدار_لا_تُرشّح_بحقلٍ_محجوب_ولا_تتجاوز_تاريخَ_الإصدار_ولا_تعدّ_المخاطر(): void
    {
        $pid = $this->row('projects', ['name' => 'مشروع', 'company_id' => $this->alpha->id]);
        $this->row('code_releases', ['ver' => '1.0.0', 'project_id' => $pid, 'date' => '2031-01-07']);
        $rel = $this->row('code_releases', ['ver' => '1.1.0', 'project_id' => $pid, 'date' => '2031-02-01']);
        $this->row('code_releases', ['ver' => '1.2.0', 'project_id' => $pid, 'date' => '2031-03-01']);   // لاحقٌ لا سابق
        $this->row('tasks', ['title' => 'QWXZ-IN', 'status' => 'مكتملة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('tasks', ['title' => 'QWXZ-AFTER', 'status' => 'مكتملة', 'project_id' => $pid, 'updated_at' => '2031-02-15 10:00:00']);
        $this->row('issues', ['title' => 'QWXZ-RISK', 'kind' => 'خطر', 'status' => 'مغلقة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->row('issues', ['title' => 'QWXZ-FIX', 'kind' => 'مشكلة', 'status' => 'محلولة', 'project_id' => $pid, 'updated_at' => '2031-01-15 10:00:00']);
        $this->replies = [['reply' => ['- سطر']]];

        $this->assertTrue(DraftAssistant::draft($this->member(), 'notes', 'code', $rel)['ok']);
        $sent = $this->sentText();
        $this->assertStringContainsString('بعد 2031-01-07 حتى 2031-02-01', $sent, 'النافذةُ من السابق إلى تاريخ الإصدار نفسِه');
        $this->assertStringContainsString('QWXZ-IN', $sent);
        $this->assertStringContainsString('QWXZ-FIX', $sent);
        foreach (['QWXZ-AFTER', 'QWXZ-RISK'] as $no) $this->assertStringNotContainsString($no, $sent, $no);

        // حالةُ المهامّ وتاريخُ الإصدار محجوبان ⇒ لا ترشيحَ بهما ولا إفشاء
        $this->sent = [];
        $role = Role::create(['name' => 'محجوب ' . Str::random(4), 'scope' => 'all', 'flags' => [AskPolicy::FLAG => 1],
            'matrix' => collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all(),
            'field_rules' => ['tasks' => ['status' => 'hide'], 'code' => ['date' => 'hide']]]);
        $u = User::create(['name' => 'محجوب', 'email' => Str::random(9) . '@dev.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$this->alpha->id]]);
        $this->assertTrue(DraftAssistant::draft($u, 'notes', 'code', $rel)['ok']);
        $sent = $this->sentText();
        $this->assertStringNotContainsString('QWXZ-IN', $sent, 'حالةٌ محجوبةٌ لا تُرشِّح');
        $this->assertStringNotContainsString('2031-01-07', $sent, 'تاريخٌ محجوبٌ لا يظهر');
        $this->assertStringNotContainsString('2031-02-01', $sent);
        $this->assertStringContainsString('QWXZ-FIX', $sent, 'والمشاكلُ التي يرى حقولَها باقية');
    }
}
