<?php

namespace Tests\Feature\AskHub;

use App\Models\AiFinding;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use App\Support\Ai\Auditor\Auditor;
use App\Support\Insights\ActionCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **`hub_findings` — «اسأل Hub» يقرأ ملاحظاتِ المدقّق كما يراها السائلُ في مركز الفعل، لا أكثر**
 * (المرحلة ٢ · `docs/ai-hub/46-ai-roadmap.md` §٤).
 *
 * الأداةُ لا تستعلم `ai_findings` بنفسها — تمرّ بـ`AuditorSignals` وشروطه الخمسة. فتُمتحن هنا
 * **بمستخدمين معزولين** (CLAUDE.md): المراجع، وصاحبُ التقرير، وحسابُ عميل، ومن لا يملك المساعد،
 * وما رفضه مدير — وأنّ المسودةَ ورابطَها لا يُسلَّمان للنموذج.
 */
class AskFindingsToolTest extends TestCase
{
    private User $author;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);

        // المساعدُ لدورِ الموظّف (المراجعُ وصاحبُ التقرير كلاهما عليه) — فالفرقُ بينهما الرؤيةُ وحدَها
        $role = Role::find($this->employee->role_id);
        $role->flags = [...(array) $role->flags, AskPolicy::FLAG => 1];   // الراياتُ خريطةٌ (hub_flag)
        $role->save();
        $this->employee->refresh();
        $this->author = User::create(['name' => 'كاتبُ التقرير', 'email' => 'author@ask.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** عائقٌ متكرّرٌ في ثلاثة أيّام ⇒ نتيجةٌ مفتوحةٌ موضوعُها أحدثُ تقرير */
    private function finding(): AiFinding
    {
        foreach ([5, 4, 3] as $i => $d) {
            DB::table('work_updates')->insert([
                'id' => (string) Str::uuid(), 'done' => "إنجازٌ مختلفٌ رقم {$i} في الوحدة",
                'problems' => 'الخادمُ التجريبيُّ لا يعمل', 'work_date' => now()->subDays($d)->toDateString(),
                'created_by' => $this->author->id, 'company_id' => $this->alpha->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Auditor::run();

        return AiFinding::query()->where('detector', 'repeated_blocker')->orderBy('id')->firstOrFail();
    }

    public function test_المراجعُ_يقرأ_الملاحظةَ_بسجلِّها_ولا_تصله_المسودةُ_ولا_رابطُها(): void
    {
        $f = $this->finding();

        $r = AskTools::run('hub_findings', [], $this->employee);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertCount(1, $r['rows']);
        $row = $r['rows'][0];
        $this->assertSame((string) $f->subject_id, $row['id'], 'المرجعُ هو السجلُّ موضوعُ الملاحظة');
        $this->assertSame('updates', $row['module']);
        $this->assertNotSame('', $row['detector']);
        $this->assertStringNotContainsString('🔎', $row['finding']);
        $this->assertSame(['id', 'module', 'detector', 'severity', 'finding', 'context', 'key'], array_keys($row),
            'لا مسودةَ ولا رابطَ في ما يصل النموذج — والمفتاحُ هويّةٌ لإعادة المصادقة لا بيانات');
        $this->assertSame($f->signalKey(), $row['key']);
    }

    public function test_صاحبُ_التقرير_لا_يقرأ_حكمَ_المدقّقِ_على_عمله_ولو_سأل_المساعد(): void
    {
        $this->finding();

        $r = AskTools::run('hub_findings', [], $this->author);

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['rows'], 'قرارُ المالك §٣.٦: يصل الموظّفَ ما اعتمده مديرُه لا الحكمُ الخام');
    }

    /**
     * **شرطُ «ليس عملَه» على موضوعٍ غيرِ تقرير** — تقريرُ الموظّف يحجبه عنه شرطُ المراجعة أيضاً،
     * فاختبارُ التقرير وحدَه لا يمتحن الشرطَ الثالث. قرارٌ منفّذُه صاحبُ الجلسة يمتحنه وحدَه:
     * المراجعُ يرى الملاحظة وصاحبُ القرار لا.
     */
    public function test_منفّذُ_القرار_لا_يقرأ_ملاحظةَ_المدقّقِ_على_قراره_والمراجعُ_يقرؤها(): void
    {
        DB::table('decisions')->insert(['id' => (string) Str::uuid(), 'title' => 'اعتمادُ مورّدٍ ثانٍ للخوادم',
            'status' => 'قيد التنفيذ', 'company_id' => $this->alpha->id, 'exec_id' => $this->author->id,
            'created_at' => now()->subDays(10), 'updated_at' => now()]);
        Auditor::run();
        $this->assertSame(1, AiFinding::query()->where('detector', 'decision_without_task')->count());

        $this->assertCount(1, AskTools::run('hub_findings', ['module' => 'decisions'], $this->employee)['rows'],
            'المراجعُ يراها — فالخلوُّ عند صاحبها ليس من البوّابة');
        $this->assertSame([], AskTools::run('hub_findings', ['module' => 'decisions'], $this->author)['rows'],
            'منفّذُ القرار لا يقرأ حكمَ المدقّق على عمله');
    }

    /** **الجوابُ المحفوظُ المبنيُّ على ملاحظة** يُعاد إلى حارس المدقّق نفسِه — لا إلى موضوعها وحدَه */
    public function test_جوابٌ_محفوظٌ_على_ملاحظةٍ_يُخفى_حين_تُرفَض_أو_يغيب_شاهدُها(): void
    {
        $f = $this->finding();
        $save = function () {
            $ctx = \App\Support\Ai\Ask\AskContext::open();
            $ctx->addResult(AskTools::run('hub_findings', [], $this->employee));

            return \App\Support\Ai\Ask\AskMemory::record($this->employee, null, 'ماذا رصد المدقّق؟',
                ['ok' => true, 'answer' => 'عائقٌ متكرّر', 'sources' => $ctx->sources()]);
        };

        $t = $save();
        $this->assertFalse(\App\Support\Ai\Ask\AskMemory::turns($this->employee, $t)[0]['hidden']);

        // رفضها المالك ⇒ لا تُعرَض في أيِّ مكان — ولا في الذاكرة
        $this->actingAs($this->owner);
        ActionCenter::disposition($f->signalKey(), 'dismiss', null, 'ليس عائقاً');
        $this->assertTrue(\App\Support\Ai\Ask\AskMemory::turns($this->employee, $t)[0]['hidden'], 'ملاحظةٌ مرفوضة');

        // وشاهدٌ غاب (غيرُ الموضوع) ⇒ كذلك
        ActionCenter::disposition($f->signalKey(), 'reopen', null, null);
        $t2 = $save();
        $this->assertFalse(\App\Support\Ai\Ask\AskMemory::turns($this->employee, $t2)[0]['hidden']);
        $others = collect((array) $f->evidence)->pluck('id')->reject(fn ($id) => $id === $f->subject_id)->values()->all();
        $this->assertNotEmpty($others);
        DB::table('work_updates')->whereIn('id', $others)->update(['company_id' => Company::create(['name_ar' => 'باء'])->id]);
        \Illuminate\Support\Facades\Cache::flush();
        $u = User::query()->find($this->employee->id);
        $u->forceFill(['companies' => [$this->alpha->id]])->save();
        $this->assertTrue(\App\Support\Ai\Ask\AskMemory::turns($u->fresh(), $t2)[0]['hidden'], 'شاهدٌ خرج من النطاق');
    }

    public function test_حسابُ_العميل_ومن_لا_يملك_المساعد_لا_يقرآن_شيئاً(): void
    {
        $this->finding();

        $client = User::create(['name' => 'عميل', 'email' => 'client@ask.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $this->assertSame([], AskTools::run('hub_findings', [], $client)['rows']);

        $r = AskTools::run('hub_findings', [], $this->viewer);
        $this->assertFalse($r['ok'], 'مشاهدٌ بلا رايةِ المساعد');
        $this->assertSame([], $r['rows']);
        $this->assertNotNull(AskPipeline::authorize('hub_findings', [], $this->viewer));
    }

    public function test_ما_رفضه_مديرٌ_لا_يعود_من_المساعد_كما_لا_يعود_في_الملخّص(): void
    {
        $f = $this->finding();
        $this->actingAs($this->owner);
        $this->assertTrue(ActionCenter::disposition($f->signalKey(), 'dismiss', null, 'ليس عائقاً حقيقيّاً'));

        $this->assertSame([], AskTools::run('hub_findings', [], $this->employee)['rows']);
    }

    public function test_الترشيحُ_بالوحدة_من_الكتالوج_وحدَه(): void
    {
        $this->finding();

        $this->assertCount(1, AskTools::run('hub_findings', ['module' => 'updates'], $this->employee)['rows']);
        $this->assertSame([], AskTools::run('hub_findings', ['module' => 'tasks'], $this->employee)['rows']);

        $r = AskTools::run('hub_findings', ['module' => 'no_such_module'], $this->employee);
        $this->assertFalse($r['ok']);
        $this->assertSame('وحدةٌ غيرُ متاحةٍ لك', $r['error'], 'الرسالةُ نفسُها لغيرِ الموجود وغيرِ المسموح');
    }

    public function test_الأداةُ_مُعلَنةٌ_للنموذج_قارئةً_ومراجَعةً_في_بوّابة_الإصدار(): void
    {
        $names = array_map(fn ($t) => $t['function']['name'], AskTools::schema(AskTools::catalog($this->employee)));
        $this->assertContains('hub_findings', $names);
        // كلُّ مُعلَنةٍ منفَّذة؛ والبحثُ بالمعنى وحدَه يُعلَن حين يعمل العقلُ الثاني
        $expected = \App\Support\Ai\Brain\Brain::ready() ? AskTools::TOOLS : array_values(array_diff(AskTools::TOOLS, ['hub_semantic']));
        $this->assertSame($expected, $names, 'كلُّ أداةٍ مُعلَنةٍ منفَّذة، وكلُّ منفَّذةٍ مُعلَنة');
        $this->assertSame([], AskTools::WRITE_TOOLS);
        $this->assertNull(AskPipeline::authorize('hub_findings', [], $this->employee));
    }
}
