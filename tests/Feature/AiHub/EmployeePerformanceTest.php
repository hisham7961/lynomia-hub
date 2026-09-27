<?php

namespace Tests\Feature\AiHub;

use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePerformanceReport;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Reports\EmployeePerformance;
use App\Support\Ai\Reports\PerformancePeriod;
use App\Support\Ai\Reports\ProjectReportDigest;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\FeatureRegistry;
use App\Support\Platform\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\AskHub\LiteLlmFixtures;
use Tests\TestCase;

/**
 * **تقريرُ أداء الموظّف بالذكاء** — بلا دينارٍ واحد: `Http::fake` يعترض كلَّ نداء.
 *
 * المشهد: «اليوم» الجمعة 2026-09-18 والفترةُ أسبوعيّة (أسهلُ عدّاً) — السابقةُ المكتملة W37
 * (الاثنين 09-07 ← الأحد 09-13؛ العطلةُ الجمعةُ والسبت) والجاريةُ W38 حتى أمس.
 *
 * ما يُقاس: صحّةُ الحقائق مصدراً مصدراً، وما يغادر الخادم (لا اسمَ ولا راتبَ ولا سرّ)، والتزايد
 * (لا نداءَ بلا جديد، والسابقةُ مرّةً)، والإخفاقُ الصادق، ومصفوفةُ الرؤية، و«تحديث»، ومبدّلُ الفترة،
 * وتكرارُ ملخّص المشاريع.
 */
class EmployeePerformanceTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    /** @var list<array|string|int> */
    private array $replies = [];

    private int $at = 0;

    private Company $alpha;

    private Company $beta;

    private User $staff;

    private User $manager;

    private Employee $emp;

    private string $p1;

    private string $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-09-18 10:00:00', config('app.timezone')));

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف QWXZ-COMPANY']);
        $this->beta = Company::create(['name_ar' => 'شركةُ باء']);
        $this->manager = $this->member([], [], [], 'المديرُ المباشر');
        // الموظّفُ نفسُه يملك hr:v (دورُ «موظف» في البذرة كاملُ العرض) — ومع ذلك لا يرى السردَ عن نفسِه
        $this->staff = User::create(['name' => 'سلمى الموظفة', 'email' => 'salma.perf@perf.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $cols = ['name' => 'سلمى الموظفة', 'user_id' => $this->staff->id, 'company_id' => $this->alpha->id, 'status' => 'نشط',
            'manager_id' => $this->manager->id, 'salary' => 727272.125, 'allow' => 515151.5, 'iqama' => 'QWXZ-IQAMA-9911',
            'passport' => 'QWXZ-PASSPORT-7733', 'title' => 'مطوّرة'];
        if (Schema::hasColumn('employees', 'civil_id')) $cols['civil_id'] = 'QWXZ-CIVIL-2288';
        if (Schema::hasColumn('employees', 'iban')) $cols['iban'] = 'KW81QWXZIBAN0000000000000000';
        $this->emp = Employee::create($cols);

        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'test');
        Settings::put('ai.gateway_key', 'sk-admin-test-key-000111222333', 'test');
        foreach (['ai.enabled', 'ai.probe_ok', 'ai.generation_ok'] as $k) Settings::put($k, '1', 'test');
        Settings::put('ai.probe_fp', AiGateway::fingerprint(), 'test');
        Settings::put('ai.generation_fp', AiGateway::fingerprint(), 'test');
        FeatureRegistry::flush();
        AiProfiles::seed();

        $provider = AiProvider::create(['catalog_key' => 'openai', 'label' => 'مزوّد', 'enabled' => true,
            'credential_name' => 'hub-pf-' . substr(sha1((string) microtime(true)), 0, 10), 'credential_state' => 'configured']);
        $model = AiModel::create(['provider_id' => $provider->id, 'litellm_model_name' => 'hub-general',
            'upstream_model' => 'fake/hub-general', 'display_name' => 'hub-general', 'enabled' => true, 'health' => 'UNKNOWN',
            'capabilities' => ['chat' => ['v' => true, 'src' => 'litellm'], 'tools' => ['v' => true, 'src' => 'litellm']],
            'limits' => ['context_window' => ['v' => 32000, 'src' => 'litellm']], 'params' => [],
            'pricing' => ['input_per_1k' => ['v' => 0.001, 'src' => 'litellm'], 'output_per_1k' => ['v' => 0.001, 'src' => 'litellm'],
                          'currency' => 'USD', 'unit' => 'per_1k_tokens']]);
        AiProfiles::attach(AiProfile::query()->where('key', 'general')->firstOrFail(), $model);

        Http::fake(function ($req) {
            $this->sent[] = json_decode((string) $req->body(), true);
            $reply = $this->replies[min($this->at, max(0, count($this->replies) - 1))] ?? $this->narrative('خلاصةٌ افتراضيّة');
            $this->at++;
            if (is_int($reply)) return Http::response(LiteLlmFixtures::providerDown(), $reply);
            $text = is_string($reply) ? $reply : json_encode($reply, JSON_UNESCAPED_UNICODE);

            return Http::response(LiteLlmFixtures::answer($text, LiteLlmFixtures::usage()), 200);
        });

        $this->p1 = $this->project('مشروعُ المنصّة QWXZ-PROJNAME', $this->alpha);
        $this->p2 = $this->project('مشروعُ التكامل', $this->alpha);
        $this->hubSetting('hr.performance_period', 'week');
    }

    // ── أدوات ──────────────────────────────────────────────────────────

    private function on(): void
    {
        $this->hubSetting('hr.performance_ai', '1');
        $this->hubSetting('hr.performance_profile', 'general');
    }

    private function narrative(string $summary, array $over = []): array
    {
        return array_merge(['summary' => $summary, 'strengths' => ['[E] أنجز في [PR1] بثبات'], 'improve' => ['التقارير الناقصة'],
            'commitment' => ['امتثالٌ ٥٠٪'], 'productivity' => ['١٢ ساعةً مُبلَّغة'], 'recommendations' => ['متابعةٌ أسبوعيّة']], $over);
    }

    private function project(string $name, Company $co): string
    {
        $id = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $id, 'name' => $name, 'company_id' => $co->id, 'status' => 'قيد التنفيذ',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function report(string $date, string $done, array $cols = []): string
    {
        $id = (string) Str::uuid();
        DB::table('work_updates')->insert(array_merge(['id' => $id, 'done' => $done, 'project_id' => $this->p1, 'work_date' => $date,
            'created_by' => $this->staff->id, 'company_id' => $this->alpha->id, 'hours' => 3, 'review_status' => 'pending_review',
            'created_at' => $date . ' 15:00:00', 'updated_at' => $date . ' 15:00:00'], $cols));

        return $id;
    }

    private function attend(string $date, string $in, string $out, string $status = 'حاضر'): void
    {
        Attendance::create(['emp_id' => $this->emp->id, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'status' => $status, 'hours' => 8]);
    }

    /** المشهدُ الكامل لأسبوع W37 (09-07 ← 09-13) مصدراً مصدراً */
    private function scene(): void
    {
        // الحضور: الإثنين والثلاثاء في الوقت، والأحدُ متأخّراً؛ الأربعاءُ غياب؛ الخميسُ إجازةٌ معتمدة
        $this->attend('2026-09-07', '08:00', '16:00');
        $this->attend('2026-09-08', '08:00', '16:00');
        $this->attend('2026-09-13', '10:30', '18:30', 'متأخر');
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $this->emp->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-09-10', 'date_to' => '2026-09-10', 'days' => 1, 'status' => 'معتمد', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $this->emp->id, 'type' => 'إجازة مرضية',
            'date_from' => '2026-09-13', 'date_to' => '2026-09-14', 'days' => 2, 'status' => 'مقدّم', 'created_at' => now(), 'updated_at' => now()]);

        // التقارير: الإثنين (مقبول، متأخّرُ التقديم) · الثلاثاء بندان (تنقيح · بانتظار) · والأحدُ بلا تقرير
        $this->report('2026-09-07', 'أنجزتُ شاشةَ الفواتير مع سلمى الموظفة QWXZ-DONE1', ['hours' => 6, 'review_status' => 'accepted',
            'submitted_at' => '2026-09-08 09:00:00']);
        $this->report('2026-09-08', 'ربطتُ الدفعَ QWXZ-DONE2', ['hours' => 4, 'review_status' => 'needs_revision']);
        $this->report('2026-09-08', 'تكاملُ المخزون QWXZ-DONE3', ['hours' => 2, 'project_id' => $this->p2,
            'problems' => 'ألصقتُ المفتاح sk-live-ABCDEFGHIJKLMNOPQRSTUV0123 في الإعداد']);
        $this->report('2026-09-09', '-', ['hours' => 5]);   // نائبٌ رمزيّ — ليس تقريراً صالحاً

        // المهامّ: أُسندت وأُنجزت في الموعد · أُنجزت متأخّرةً (أُسندت قبل الفترة) · أُسندت ولم تُنجَز وفات موعدُها
        $task = fn (array $c) => DB::table('tasks')->insert(array_merge(['id' => (string) Str::uuid(), 'title' => 'مهمّة ' . Str::random(4),
            'assignee_id' => $this->staff->id, 'company_id' => $this->alpha->id, 'status' => 'منجزة', 'updated_at' => now()], $c));
        $task(['created_at' => '2026-09-08 09:00:00', 'due' => '2026-09-10', 'completed_at' => '2026-09-09 12:00:00']);
        $task(['created_at' => '2026-09-01 09:00:00', 'due' => '2026-09-09', 'completed_at' => '2026-09-11 12:00:00']);
        $task(['created_at' => '2026-09-07 09:00:00', 'due' => '2026-09-12', 'completed_at' => null, 'status' => 'قيد التنفيذ']);

        // العهدة والتذاكر ونتائجُ المدقّق
        DB::table('assets')->insert(['id' => (string) Str::uuid(), 'name' => 'حاسوبٌ محمول', 'holder_id' => $this->staff->id,
            'company_id' => $this->alpha->id, 'price' => 999999.5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tickets')->insert(['id' => (string) Str::uuid(), 'subject' => 'عطلُ الطابعة', 'assignee_id' => $this->staff->id,
            'status' => 'مغلقة', 'company_id' => $this->alpha->id, 'created_at' => '2026-09-08 10:00:00', 'updated_at' => '2026-09-09 10:00:00']);
        DB::table('ai_findings')->insert(['id' => (string) Str::uuid(), 'detector' => 'copy', 'dedup_key' => sha1('x'), 'source' => 'rule',
            'severity' => 'high', 'subject_module' => 'updates', 'subject_id' => (string) Str::uuid(), 'subject_user_id' => $this->staff->id,
            'evidence' => '[]', 'fields' => '[]', 'summary' => 'نسخُ تقرير QWXZ-FINDING-TEXT', 'fingerprint' => hash('sha256', 'x'),
            'status' => 'open', 'detected_at' => '2026-09-09 08:00:00', 'created_at' => now(), 'updated_at' => now()]);

        // الفترةُ الجارية W38: تقريرٌ واحدٌ الإثنين
        $this->report('2026-09-14', 'بدأتُ الأسبوعَ الجديد QWXZ-CUR1', ['hours' => 5]);
    }

    private function sentText(int $i = -1): string
    {
        return json_encode($i < 0 ? $this->sent : [$this->sent[$i]], JSON_UNESCAPED_UNICODE);
    }

    private function row(string $period): ?EmployeePerformanceReport
    {
        return EmployeePerformance::row((string) $this->emp->id, $period);
    }

    private function member(array $matrix = [], array $companies = [], array $extra = [], ?string $name = null): User
    {
        $none = collect(array_keys(config('hub.modules')))->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(array_merge(['name' => 'دورٌ ' . Str::random(5), 'scope' => 'all', 'flags' => [],
            'matrix' => array_merge($none, $matrix)], $extra));

        return User::create(['name' => $name ?? 'عضو ' . Str::random(4), 'email' => Str::random(9) . '@pf.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => $companies]);
    }

    private function hrViewer(array $companies, array $extra = [], array $more = []): User
    {
        return $this->member(array_merge(['hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0],
            'projects' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]], $more), $companies, $extra);
    }

    // ═══ الإطفاءُ الافتراضيّ ═══

    public function test_مطفأٌ_افتراضاً_فلا_نداءَ_ولا_صفّ(): void
    {
        $this->scene();
        $this->assertSame('cheap', EmployeePerformance::profileKey());
        $this->assertStringContainsString('مطفأ', (string) EmployeePerformance::whyNot());
        $this->assertNotNull(EmployeePerformance::run()['stopped']);
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->artisan('hub:performance')->assertExitCode(0);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, EmployeePerformanceReport::query()->count());

        // والتبويبُ يقول الحالَ بصدق
        $html = $this->actingAs($this->owner)->get(route('portal.employee', $this->emp->id) . '?tab=performance')->assertOk()->getContent();
        $this->assertStringContainsString('تقرير الأداء (ذكاء اصطناعي)', $html);
        $this->assertStringContainsString('hr.performance_ai', $html);
    }

    // ═══ صحّةُ الحقائق وما يغادر الخادم ═══

    public function test_الحقائقُ_صحيحةٌ_مصدراً_مصدراً_ولا_يغادر_اسمٌ_ولا_راتبٌ_ولا_سرّ(): void
    {
        $this->on();
        $this->scene();
        $this->replies = [$this->narrative('أداءٌ متوسّط QWXZ-SUM-PREV'), $this->narrative('بدايةُ أسبوع QWXZ-SUM-CUR')];

        $s = EmployeePerformance::run();
        $this->assertSame(2, $s['updated'], 'السابقةُ المكتملةُ والجارية');
        $this->assertCount(2, $this->sent);

        $prev = $this->row('2026-W37');
        $this->assertNotNull($prev);
        $f = $prev->facts;
        $this->assertSame('2026-09-07', $f['period']['from']);
        $this->assertSame('2026-09-13', $f['period']['as_of']);
        $this->assertTrue($f['period']['complete']);

        // التقارير اليوميّة
        $this->assertSame(5, $f['reports']['workdays']);
        $this->assertSame(2, $f['reports']['submitted']);
        $this->assertSame(2, $f['reports']['missing'], 'الأربعاءُ غائباً والأحدُ حاضراً بلا تقرير');
        $this->assertSame(1, $f['reports']['late'], 'تقريرُ الإثنين قُدِّم صباحَ الثلاثاء');
        $this->assertSame(1, $f['reports']['excused'], 'الخميسُ إجازةٌ معتمدة');
        $this->assertSame(50, $f['reports']['compliance_pct']);
        $this->assertSame(3, $f['reports']['items']);
        $this->assertSame(1, $f['reports']['review']['accepted']);
        $this->assertSame(1, $f['reports']['review']['needs_revision']);
        $this->assertSame(1, $f['reports']['review']['pending']);
        // الحضور
        $this->assertSame(3, $f['attendance']['present']);
        $this->assertSame(1, $f['attendance']['absent']);
        $this->assertSame(1, $f['attendance']['late']);
        $this->assertSame(1, $f['attendance']['leave']);
        $this->assertEquals(24.0, $f['attendance']['hours']);
        // الساعاتُ والمشاريع — النائبُ الرمزيّ لا يُحسب
        $this->assertEquals(12.0, $f['work']['reported_hours']);
        $this->assertSame(2, $f['work']['projects_count']);
        $this->assertSame('PR1', $f['work']['projects'][0]['code']);
        $this->assertSame($this->p1, $f['work']['projects'][0]['id']);
        $this->assertEquals(10.0, $f['work']['projects'][0]['hours']);
        $this->assertSame(2, $f['work']['projects'][0]['reports']);
        $this->assertSame($this->p2, $f['work']['projects'][1]['id']);
        // المهامّ
        $this->assertSame(2, $f['tasks']['assigned']);
        $this->assertSame(2, $f['tasks']['completed']);
        $this->assertSame(50, $f['tasks']['on_time_pct']);
        $this->assertSame(2, $f['tasks']['overdue']);
        // الإجازات والعهدة والتذاكر والمدقّق
        $this->assertSame(1, $f['leaves']['approved_days']);
        $this->assertSame(1, $f['leaves']['by_type']['إجازة سنوية']);
        $this->assertSame(1, $f['leaves']['pending']);
        $this->assertSame(1, $f['custody']['assets_held']);
        $this->assertSame(1, $f['tickets']['tickets_assigned']);
        $this->assertSame(1, $f['tickets']['tickets_resolved']);
        $this->assertSame(1, $f['findings']['total']);
        $this->assertSame(1, $f['findings']['high']);

        $this->assertSame('ok', $prev->status);
        $this->assertSame('أداءٌ متوسّط QWXZ-SUM-PREV', $prev->narrative['summary']);
        $this->assertSame('hub-general', $prev->model);
        $this->assertNotNull($prev->usage_event_id);
        $this->assertSame(2, DB::table('ai_usage_events')->where('feature', 'employee_performance')->count());

        // الجاريةُ حتى أمس — تقريرُ الإثنين فيها
        $cur = $this->row('2026-W38');
        $this->assertSame('2026-09-17', $cur->facts['period']['as_of']);
        $this->assertFalse($cur->facts['period']['complete']);
        $this->assertStringContainsString('QWXZ-CUR1', $this->sentText(1));
        $this->assertStringNotContainsString('QWXZ-CUR1', $this->sentText(0), 'كلُّ فترةٍ بتقاريرها');

        // ── ما يغادر ──
        $sent = $this->sentText();
        foreach (['QWXZ-DONE1', 'QWXZ-DONE2', 'QWXZ-DONE3', '[E]', '[PR1]', 'sk-***', 'HUB-CONTEXT'] as $yes) {
            $this->assertStringContainsString($yes, $sent, $yes);
        }
        foreach (['سلمى', 'salma.perf', 'QWXZ-PROJNAME', 'مشروعُ التكامل', 'QWXZ-COMPANY', 'sk-live-ABCDEFGHIJKLMNOPQRSTUV0123',
                     '727272', '515151', '999999', 'QWXZ-IQAMA', 'QWXZ-PASSPORT', 'QWXZ-CIVIL', 'QWXZIBAN', 'QWXZ-FINDING-TEXT',
                     $this->emp->id, $this->staff->id, $this->p1, $this->manager->id, 'المديرُ المباشر'] as $no) {
            $this->assertStringNotContainsString((string) $no, $sent, 'لا يغادر: ' . $no);
        }
    }

    // ═══ التزايد ═══

    public function test_لا_نداءَ_بلا_جديد_والسابقةُ_مرّةً_والجديدُ_يوقظ(): void
    {
        $this->on();
        $this->scene();
        EmployeePerformance::run();
        $this->assertCount(2, $this->sent);

        // اليومَ نفسَه: حدُّ اليوم
        EmployeePerformance::run();
        $this->assertCount(2, $this->sent, 'مرّةً في اليوم على الأكثر');

        // السبت: أمسُ الجمعةُ عطلة ⇒ الحقائقُ لم تتغيّر ⇒ لا نداء؛ والسابقةُ المكتملةُ لا تُعاد
        $this->travelTo(Carbon::parse('2026-09-19 10:00:00', config('app.timezone')));
        $s = EmployeePerformance::run();
        $this->assertCount(2, $this->sent, 'لا جديد ⇒ لا نداء');
        $this->assertSame(1, $s['nothing']);
        $this->assertSame(1, $s['skipped'], 'السابقةُ وُلِّدت مرّةً');

        // تقريرٌ جديدٌ يوم الجمعة ⇒ الأحدُ يوقظ النموذجَ للجارية وحدَها
        $this->report('2026-09-18', 'عملٌ في العطلة QWXZ-NEWDATA', ['hours' => 2]);
        $this->travelTo(Carbon::parse('2026-09-20 10:00:00', config('app.timezone')));
        EmployeePerformance::run();
        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('QWXZ-NEWDATA', $this->sentText(2));

        // والأمرُ اليدويّ بالمعاينة: لا نداءَ ولا كتابة
        $n = EmployeePerformanceReport::query()->count();
        $this->artisan('hub:performance', ['--dry' => true])->assertExitCode(0);
        $this->assertCount(3, $this->sent);
        $this->assertSame($n, EmployeePerformanceReport::query()->count());
    }

    public function test_أمرٌ_لموظّفٍ_وفترةٍ_مسمّاة(): void
    {
        $this->on();
        $this->scene();
        $this->artisan('hub:performance', ['--employee' => $this->emp->id, '--period' => '2026-W37'])->assertExitCode(0);
        $this->assertCount(1, $this->sent);
        $this->assertSame('ok', $this->row('2026-W37')->status);
        $this->assertNull($this->row('2026-W38'));
        $this->assertNull(PerformancePeriod::parse('2026-W99'));
        $this->assertNull(PerformancePeriod::parse('2027-01'), 'فترةٌ مستقبليّة');
    }

    // ═══ الإخفاقُ الصادق ═══

    public function test_الإخفاقُ_لا_يمسّ_التقريرَ_السابق(): void
    {
        $this->on();
        $this->scene();
        $this->replies = [$this->narrative('السابقُ السليم'), $this->narrative('الجاريةُ السليمة QWXZ-GOOD')];
        EmployeePerformance::run();

        $this->report('2026-09-18', 'جديد', ['hours' => 1]);
        $this->travelTo(Carbon::parse('2026-09-19 10:00:00', config('app.timezone')));
        $this->replies = ['هذا ليس JSON'];
        $this->at = 0;
        $s = EmployeePerformance::run();
        $this->assertSame(1, $s['failed']);
        $cur = $this->row('2026-W38');
        $this->assertSame('failed', $cur->status);
        $this->assertSame('MALFORMED_MODEL_RESPONSE', $cur->error_code);
        $this->assertSame('الجاريةُ السليمة QWXZ-GOOD', $cur->narrative['summary'], 'لا يُكتَب نصفُ ردّ');
        $this->assertSame('2026-09-17', $cur->facts['period']['as_of'], 'والحقائقُ حقائقُ السرد المعروض');

        // والصفحةُ تقول ذلك
        $html = $this->actingAs($this->owner)->get(route('reports.performance.show', ['id' => $this->emp->id, 'period' => '2026-W38']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('QWXZ-GOOD', $html);
        $this->assertStringContainsString('آخرُ تحديثٍ أخفق', $html);
    }

    public function test_نفادُ_الميزانيّة_يوقف_الجولةَ_والحقائقُ_تُحفظ_بلا_سرد(): void
    {
        $this->on();
        $this->scene();
        AiBudget::create(['key' => 'b-' . Str::random(6), 'label' => 'سقفٌ مستنفَد', 'scope_type' => 'global', 'scope_id' => null,
            'period' => 'monthly', 'limit_micro' => 1, 'limit_requests' => null, 'limit_tokens' => null,
            'currency' => 'USD', 'enforce' => true, 'enabled' => true]);

        $s = EmployeePerformance::run();
        $this->assertSame([], $this->sent);
        $this->assertSame(1, $s['failed']);
        $this->assertNotNull($s['stopped']);
        $rows = EmployeePerformanceReport::query()->orderBy('id')->get();
        $this->assertCount(1, $rows, 'تقف عند أوّل فترة');
        $this->assertSame('failed', $rows[0]->status);
        $this->assertNull($rows[0]->narrative);
        $this->assertSame(2, $rows[0]->facts['reports']['submitted'], 'الأرقامُ الحتميّةُ محفوظةٌ للعرض');

        $html = $this->actingAs($this->owner)->get(route('reports.performance.show', $this->emp->id))->assertOk()->getContent();
        $this->assertStringContainsString('تعذّر توليدُ السرد', $html);
    }

    // ═══ الرؤية ═══

    public function test_مصفوفةُ_الرؤية(): void
    {
        $this->on();
        $this->scene();
        $this->replies = [$this->narrative('خلاصةُ السابقة QWXZ-NARR-PREV'), $this->narrative('خلاصةُ الجارية QWXZ-NARR')];
        EmployeePerformance::run();
        $show = route('reports.performance.show', $this->emp->id);
        $tab = route('portal.employee', $this->emp->id) . '?tab=performance';

        // المالك: التبويبُ والصفحة، والرمزان يُستبدلان بالاسمين
        $html = $this->actingAs($this->owner)->get($tab)->assertOk()->getContent();
        $this->assertStringContainsString('QWXZ-NARR', $html);
        $this->assertStringContainsString('مولَّدٌ بالذكاء الاصطناعي', $html);
        $this->assertStringContainsString('hub-general', $html);
        $this->assertStringContainsString('سلمى الموظفة أنجز في مشروعُ المنصّة QWXZ-PROJNAME', $html);
        $this->assertStringNotContainsString('[E]', $html);
        $this->assertStringNotContainsString('727,272', $html);
        $this->actingAs($this->owner)->get($show)->assertOk()->assertSee('QWXZ-NARR');

        // hr:v في الشركة نفسِها
        $same = $this->hrViewer([$this->alpha->id]);
        $this->actingAs($same)->get($show)->assertOk()->assertSee('QWXZ-NARR');
        $this->assertStringContainsString('tab=performance', $this->actingAs($same)->get(route('portal.employee', $this->emp->id))->getContent());
        $this->actingAs($same)->get($tab)->assertOk()->assertSee('QWXZ-NARR');
        $this->actingAs($same)->get(route('reports.performance'))->assertOk()->assertSee('سلمى الموظفة');

        // hr:v في شركةٍ أخرى: ٤٠٤ في البابين، ولا اسمَ في القائمة
        $other = $this->hrViewer([$this->beta->id]);
        $this->actingAs($other)->get($show)->assertNotFound();
        $this->actingAs($other)->get($tab)->assertNotFound();
        $this->actingAs($other)->post(route('reports.performance.refresh', $this->emp->id))->assertNotFound();
        $this->assertStringNotContainsString('سلمى الموظفة', $this->actingAs($other)->get(route('reports.performance'))->getContent());

        // الموظّفُ نفسُه — ولو ملك hr:v: لا صفحةَ ولا تبويبَ ولا شيءَ في «بوابتي»
        $this->actingAs($this->staff)->get($show)->assertNotFound();
        $own = $this->actingAs($this->staff)->get(route('portal.employee', $this->emp->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('tab=performance', $own);
        $this->actingAs($this->staff)->get($tab)->assertForbidden();
        $me = $this->actingAs($this->staff)->get(route('portal.me'))->assertOk()->getContent();
        $this->assertStringNotContainsString('QWXZ-NARR', $me);
        $this->assertStringNotContainsString(route('reports.performance.show', $this->emp->id),
            $this->actingAs($this->staff)->get(route('reports.performance'))->getContent(), 'ولا في القائمة');

        // بلا hr:v وليس مديراً: ٤٠٣
        $none = $this->member(['projects' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]], [$this->alpha->id]);
        $this->actingAs($none)->get($show)->assertForbidden();
        $this->actingAs($none)->get(route('reports.performance'))->assertForbidden();

        // المديرُ المباشر (بلا hr:v): يرى الصفحةَ لا الملفَّ الشامل
        $this->actingAs($this->manager)->get($show)->assertOk()->assertSee('QWXZ-NARR')->assertSee('أنت مديرُه المباشر');
        $this->actingAs($this->manager)->get(route('reports.performance'))->assertOk()->assertSee('سلمى الموظفة');
        $this->actingAs($this->manager)->get($tab)->assertForbidden();

        // حسابُ العميل: ٤٠٤
        $client = $this->hrViewer([]);
        $client->forceFill(['account_type' => 'client'])->save();
        $this->actingAs($client)->get($show)->assertNotFound();
        $this->actingAs($client)->get(route('reports.performance'))->assertNotFound();

        // من حُجب عنه «آخر تقييم أداء»: لا سرد
        $masked = $this->hrViewer([$this->alpha->id], ['field_rules' => ['hr' => ['perf' => 'hide']]]);
        $html = $this->actingAs($masked)->get($show)->assertOk()->getContent();
        $this->assertStringNotContainsString('QWXZ-NARR', $html);
        $this->assertStringContainsString('محجوبٌ عنك', $html);
    }

    // ═══ «تحديث» ═══

    public function test_التحديثُ_للمالك_ولـhr_e_مدقَّقاً_ومحدوداً(): void
    {
        $this->on();
        $this->scene();
        $url = route('reports.performance.refresh', $this->emp->id);

        // hr:v وحدَه ⇒ ٤٠٣ — والمديرُ كذلك
        $this->actingAs($this->hrViewer([$this->alpha->id]))->post($url)->assertForbidden();
        $this->actingAs($this->manager)->post($url)->assertForbidden();
        $this->assertSame([], $this->sent);

        $editor = $this->hrViewer([$this->alpha->id], [], ['hr' => ['v' => 1, 'a' => 0, 'e' => 1, 'd' => 0]]);
        $this->actingAs($editor)->post($url)->assertRedirect(route('reports.performance.show', ['id' => $this->emp->id]))->assertSessionHas('ok');
        $this->assertCount(1, $this->sent, 'الفترةُ الجاريةُ وحدَها');
        $this->assertSame('ok', $this->row('2026-W38')->status);
        $this->assertTrue(DB::table('audits')->where('action', EmployeePerformance::AUDIT_ACTION)->where('record_id', $this->emp->id)->exists());
        $this->assertSame(1, DB::table('ai_usage_events')->where('feature', 'employee_performance')->where('user_id', $editor->id)->count(),
            'النقرةُ تُحسب على صاحبها');

        // نقرةٌ ثانيةٌ فوراً: حدُّ الموظّف
        $this->actingAs($this->owner)->post($url)->assertSessionHas('err');
        $this->assertCount(1, $this->sent);

        // بعد الحدّ وبلا جديد: لا نداء (ولو تخطّى حدَّ اليوم)
        Cache::flush();
        $this->actingAs($this->owner)->post($url)->assertSessionHas('ok', 'لا بياناتٍ جديدةً منذ آخر تقرير — لم يُستدعَ النموذج');
        $this->assertCount(1, $this->sent);

        // وفترةٌ مسمّاة
        Cache::flush();
        $this->actingAs($this->owner)->post($url, ['period' => '2026-W37'])
            ->assertRedirect(route('reports.performance.show', ['id' => $this->emp->id, 'period' => '2026-W37']));
        $this->assertCount(2, $this->sent);
        $this->assertSame('ok', $this->row('2026-W37')->status);
    }

    // ═══ مبدّلُ الفترة ═══

    public function test_مبدّلُ_الفترة_والتاريخ(): void
    {
        $this->on();
        $this->scene();
        $this->replies = [$this->narrative('خلاصةٌ قديمة QWXZ-OLDP'), $this->narrative('خلاصةٌ حديثة QWXZ-NEWP')];
        EmployeePerformance::run();

        // الافتراضُ الأحدثُ فترةً، والقائمةُ فيها الفترتان
        $html = $this->actingAs($this->owner)->get(route('reports.performance.show', $this->emp->id))->assertOk()->getContent();
        $this->assertStringContainsString('QWXZ-NEWP', $html);
        $this->assertStringNotContainsString('QWXZ-OLDP', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="period"/', $html);
        $this->assertStringContainsString('value="2026-W37"', $html);
        $this->assertStringContainsString('value="2026-W38"', $html);
        $this->assertStringContainsString('data-performance-history', $html);

        // اختيارُ السابقة
        $html = $this->actingAs($this->owner)->get(route('reports.performance.show', ['id' => $this->emp->id, 'period' => '2026-W37']))->getContent();
        $this->assertStringContainsString('QWXZ-OLDP', $html);
        $this->assertStringNotContainsString('QWXZ-NEWP', $html);
        // وفي تبويب الملفّ الشامل
        $tab = $this->actingAs($this->owner)->get(route('portal.employee', $this->emp->id) . '?tab=performance&period=2026-W37')->getContent();
        $this->assertStringContainsString('QWXZ-OLDP', $tab);
        $this->assertStringContainsString('name="tab" value="performance"', $tab);
        // فترةٌ مجهولة ⇒ الأحدث لا خطأ
        $this->actingAs($this->owner)->get(route('reports.performance.show', ['id' => $this->emp->id, 'period' => 'x<y']))->assertOk()->assertSee('QWXZ-NEWP');
    }

    // ═══ تكرارُ ملخّص المشاريع ═══

    public function test_تكرارُ_ملخّص_المشاريع_يوميٌّ_افتراضاً_وساعيٌّ_بالإعداد(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'hub:report-digest'));
        $this->assertNotNull($event, 'hub:report-digest مجدولٌ');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $this->assertSame('daily', ProjectReportDigest::frequency());
        $this->assertFalse($event->filtersPass($this->app), 'يوميٌّ ⇒ لا يجري كلَّ ساعة');
        $this->hubSetting('reports.project_digest_frequency', 'hourly');
        $this->assertFalse($event->filtersPass($this->app), 'ساعيٌّ والملخّصُ مطفأ ⇒ لا يجري');
        $this->hubSetting('reports.project_digest', '1');
        $this->assertTrue($event->filtersPass($this->app));

        // وفي الدورة اليوميّة يُتخطّى حين يكون ساعيّاً — ويجري حين يكون يوميّاً
        $this->hubSetting('reports.project_digest_profile', 'general');
        DB::table('work_updates')->insert(['id' => (string) Str::uuid(), 'done' => 'عملٌ في المشروع', 'project_id' => $this->p1,
            'work_date' => now()->subDay()->toDateString(), 'created_by' => $this->staff->id, 'company_id' => $this->alpha->id,
            'hours' => 2, 'created_at' => now()->subHour(), 'updated_at' => now()]);
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame([], $this->sent, 'ساعيٌّ ⇒ لا جولةَ في hub:automation');
        $this->hubSetting('reports.project_digest_frequency', 'daily');
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertCount(1, $this->sent, 'يوميٌّ ⇒ الجولةُ في hub:automation');
    }
}
