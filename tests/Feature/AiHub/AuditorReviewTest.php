<?php

namespace Tests\Feature\AiHub;

use App\Models\AiFinding;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Insights\ActionCenter;
use App\Support\Ai\Auditor\Auditor;
use App\Support\Ai\Auditor\AuditorAccuracy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المدقّق A3 + A4:** مسودةُ الملاحظة في شاشة المراجعة (ولا تصل الموظّفَ إلّا بإرسال المراجع)،
 * وشاشةُ الدقّة (أعدادٌ لا محتوى)، والإطفاءُ الآليُّ واليدويّ، والملخّصُ الأسبوعيّ.
 */
class AuditorReviewTest extends TestCase
{
    private User $author;

    private Employee $emp;

    private string $copy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->author = User::create(['name' => 'كاتبُ التقرير', 'email' => 'author.review@audit.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->emp = Employee::create(['name' => 'كاتبُ التقرير', 'status' => 'نشط', 'user_id' => $this->author->id]);

        foreach ([2, 1] as $d) {
            $id = (string) Str::uuid();
            DB::table('work_updates')->insert(['id' => $id, 'done' => 'أنهيتُ شاشةَ الفواتير ورفعتُها للمراجعة اليوم',
                'work_date' => now()->subDays($d)->toDateString(), 'created_by' => $this->author->id,
                'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($d === 1) $this->copy = $id;
        }
        Auditor::run();
    }

    private function dayPage(User $as)
    {
        return $this->actingAs($as)->get(route('reports.day', ['emp' => $this->emp->id, 'date' => now()->subDay()->toDateString()]));
    }

    // ═══ A3 — مسودةٌ ثمّ تأكيد ═══

    public function test_المراجعُ_يرى_ملاحظةَ_المدقّق_ومسودةً_معبّأةً_ولا_تصل_الموظّفَ_بذاتها(): void
    {
        $this->assertSame(1, AiFinding::where('detector', 'copied_report')->count());

        $res = $this->dayPage($this->owner)->assertOk();
        $res->assertSee('data-auditor-note', false);
        $res->assertSee('data-auditor-draft', false);
        $res->assertSee('صِف ما أنجزتَه فعلاً في هذا اليوم', false);
        // **اقتراحٌ لا قيمة** — حقلُ الملاحظة يُرسَل مع «قبول»، فلا يُعبّأ بالمسودة
        $this->assertDoesNotMatchRegularExpression('/name="feedback"[^>]*value=/u', $res->getContent());

        // لا شيءَ كُتب على التقرير بمجرّد العرض — المسودةُ في حقلٍ لم يُرسَل
        $this->assertNull(WorkUpdate::find($this->copy)->review_feedback);
    }

    public function test_ما_يصل_الموظّفَ_هو_ما_حرّره_المراجعُ_وأرسله_لا_حكمُ_المدقّق(): void
    {
        $edited = 'رجاءً اذكر ما أنجزته أمس تحديداً — أيُّ الشاشات اكتملت؟';
        $this->actingAs($this->owner)
            ->post(route('reports.review.act', $this->copy), ['action' => 'needs_revision', 'feedback' => $edited])
            ->assertRedirect();

        $w = WorkUpdate::find($this->copy);
        $this->assertSame($edited, $w->review_feedback);
        $this->assertStringNotContainsString('المدقّق', (string) $w->review_feedback);
        $this->assertStringNotContainsString('حرفيّاً', (string) $w->review_feedback, 'حكمُ المدقّقِ الخامُ لم يصل');
    }

    /** «قبول» بنصِّ المسودةِ حرفيّاً لا يرسلها — وإن نُسخت إلى الحقل ثمّ ضُغط «قبول» */
    public function test_القبولُ_بنصِّ_المسودة_لا_يرسلها_إلى_الموظّف(): void
    {
        $draft = AiFinding::where('detector', 'copied_report')->firstOrFail()->draft['note'];
        $this->actingAs($this->owner)
            ->post(route('reports.review.act', $this->copy), ['action' => 'accept', 'feedback' => $draft])
            ->assertRedirect();

        $w = WorkUpdate::find($this->copy);
        $this->assertSame('accepted', $w->review_status);
        $this->assertNull($w->review_feedback, 'مسودةُ المدقّق ركبت القبولَ إلى الموظّف');
        $this->actingAs($this->author)->get(route('reports.mine'))->assertDontSee('يكرّر ما كتبتَه', false);

        // و«طلب تنقيح» بها — بعد أن اختارها المراجعُ عمداً — يرسلها
        $this->actingAs($this->owner)
            ->post(route('reports.review.act', $this->copy), ['action' => 'needs_revision', 'feedback' => $draft]);
        $this->assertSame($draft, WorkUpdate::find($this->copy)->review_feedback);
    }

    public function test_ما_رفضه_مديرٌ_لا_يعود_في_صفحة_المراجعة_ولا_في_الملخّص(): void
    {
        $this->actingAs($this->owner);
        $key = AiFinding::where('detector', 'copied_report')->firstOrFail()->signalKey();
        $this->assertTrue(ActionCenter::disposition($key, 'dismiss', null, 'ليس نسخاً'));

        $res = $this->dayPage($this->owner)->assertOk();
        $res->assertDontSee('data-auditor-note', false);
        $res->assertDontSee('data-auditor-draft', false);

        $this->artisan('hub:digest', ['--dry' => true])->doesntExpectOutputToContain('🔎 المدقّق')->assertSuccessful();
    }

    public function test_من_لا_يراجع_لا_يرى_ملاحظةَ_المدقّق_في_صفحة_اليوم(): void
    {
        // يرى الفريقَ (hr:v) ولا يراجع (لا hr:e ولا updates:e)
        $role = Role::create(['name' => 'مطّلعٌ على الفريق', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0], 'updates' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]]);
        $watcher = User::create(['name' => 'مطّلع', 'email' => 'watcher@audit.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $res = $this->dayPage($watcher)->assertOk();
        $res->assertDontSee('data-auditor-note', false);
        $res->assertDontSee('صِف ما أنجزتَه فعلاً', false);
    }

    // ═══ A4 — شاشةُ الدقّة ═══

    private function aiViewer(): User
    {
        $role = Role::create(['name' => 'قارئُ الذكاء', 'scope' => 'all', 'flags' => ['aiView' => 1], 'matrix' => []]);

        return User::create(['name' => 'قارئ', 'email' => 'aiview@audit.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_شاشةُ_المدقّق_أعدادٌ_لا_محتوى_ولقارئِ_المركز_وحدَه(): void
    {
        $viewer = $this->aiViewer();
        $res = $this->actingAs($viewer)->get(route('ai.auditor'))->assertOk();
        $res->assertSee('data-auditor-accuracy', false);
        $res->assertSee('data-detector="copied_report"', false);
        $res->assertDontSee('أنهيتُ شاشةَ الفواتير', false);   // لا نصَّ تقريرٍ ولا ملخّصَ نتيجة
        $res->assertDontSee('كاتبُ التقرير', false);

        $this->actingAs($this->employee)->get(route('ai.auditor'))->assertForbidden();
    }

    public function test_الإطفاءُ_لمن_يدير_المركز_ويُخفي_النتائجَ_ولا_يمسحها_والإعادةُ_تُظهرها(): void
    {
        $this->actingAs($this->aiViewer())->post(route('ai.auditor.toggle', 'copied_report'))->assertForbidden();

        $key = AiFinding::where('detector', 'copied_report')->firstOrFail()->signalKey();
        $this->actingAs($this->owner);
        $this->assertNotNull(collect(ActionCenter::signals(true)['visible'])->firstWhere('key', $key));

        $this->post(route('ai.auditor.toggle', 'copied_report'))->assertRedirect(route('ai.auditor'));
        $this->assertTrue(AuditorAccuracy::isDisabled('copied_report'));
        $this->assertNull(collect(ActionCenter::signals(true)['visible'])->firstWhere('key', $key), 'مخفيّةٌ مع كاشفها');
        $this->assertSame(1, AiFinding::where('detector', 'copied_report')->count(), 'ولم تُمسح');
        $this->assertNotNull(Auditor::run()['copied_report']['skipped'], 'المطفأُ لا يُسأل');
        $this->assertSame('open', AiFinding::where('detector', 'copied_report')->value('status'), 'ولا يحلّ');

        $this->post(route('ai.auditor.toggle', 'copied_report'))->assertRedirect();
        $this->assertFalse(AuditorAccuracy::isDisabled('copied_report'));
        $this->assertNotNull(collect(ActionCenter::signals(true)['visible'])->firstWhere('key', $key), 'عادت بعودته');

        $this->post(route('ai.auditor.toggle', 'not_a_detector'))->assertNotFound();
    }

    // ═══ A4 — الإطفاءُ الآليّ ═══

    /** @param list<string> $by رافضون يتناوبون على الرفض */
    private function dispositions(string $detector, int $dismissed, int $ack, array $by = [], ?string $company = null): void
    {
        $by = $by ?: [(string) $this->owner->id, (string) $this->employee->id];
        foreach (array_merge(array_fill(0, $dismissed, 'dismissed'), array_fill(0, $ack, 'ack')) as $i => $state) {
            DB::table('signal_states')->insert(['id' => (string) Str::uuid(), 'skey' => "audit:{$detector}:" . sha1("k{$detector}{$i}"),
                'state' => $state, 'at' => now()->subMinute(), 'by' => $by[$i % count($by)], 'company_id' => $company,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_رأيُ_شخصٍ_واحدٍ_لا_يُطفئ_كاشفاً(): void
    {
        $this->dispositions('copied_report', 25, 5, [(string) $this->employee->id]);

        $this->assertSame([], AuditorAccuracy::review());
        $this->assertFalse(AuditorAccuracy::isDisabled('copied_report'));
    }

    public function test_إعادةُ_المالكِ_تشغيلَ_كاشفٍ_لا_تنقضها_الجولةُ_التالية(): void
    {
        $this->dispositions('copied_report', 18, 12);
        $this->assertSame(['copied_report'], AuditorAccuracy::review());

        $this->actingAs($this->owner)->post(route('ai.auditor.toggle', 'copied_report'))->assertRedirect();
        $this->assertFalse(AuditorAccuracy::isDisabled('copied_report'));

        Auditor::run();   // تُراجَع الدقّةُ في آخرها
        $this->assertFalse(AuditorAccuracy::isDisabled('copied_report'), 'الرفضُ القديمُ أطفأه من جديد');
        $this->assertSame(1, DB::table('notifications_hub')->where('kind', 'auditor-off:copied_report')
            ->where('user_id', $this->owner->id)->count(), 'ولا إبلاغَ ثانٍ');
    }

    public function test_شاشةُ_الدقّة_لمقيَّدٍ_بشركةٍ_تعدّ_شركتَه_وحدَها(): void
    {
        $alpha = \App\Models\Company::create(['name_ar' => 'ألِف']);
        $beta = \App\Models\Company::create(['name_ar' => 'باء']);
        $this->dispositions('decision_without_task', 7, 0, [], $beta->id);
        $role = Role::create(['name' => 'قارئُ ألِف', 'scope' => 'all', 'flags' => ['aiView' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'قارئ ألف', 'email' => 'alpha.ai@audit.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$alpha->id]]);

        $mine = collect(AuditorAccuracy::stats($u))->firstWhere('key', 'decision_without_task');
        $all = collect(AuditorAccuracy::stats())->firstWhere('key', 'decision_without_task');
        $this->assertSame(0, $mine['dismissed'], 'نشاطُ شركةٍ أخرى ليس له');
        $this->assertSame(7, $all['dismissed']);
    }

    public function test_كاشفٌ_يُرفض_ستّون_بالمئة_من_إشاراته_يُطفأ_آليّاً_ويُبلَّغ_المالك(): void
    {
        $this->dispositions('copied_report', 18, 12);   // ٣٠ تصرّفاً · ٦٠٪ رفض
        $this->dispositions('decision_without_task', 20, 9);   // ٢٩ — دون الحدّ الأدنى للحكم

        $off = AuditorAccuracy::review();

        $this->assertSame(['copied_report'], $off);
        $this->assertTrue(AuditorAccuracy::isDisabled('copied_report'));
        $this->assertFalse(AuditorAccuracy::isDisabled('decision_without_task'), 'لا حكمَ قبل ثلاثين تصرّفاً');
        $this->assertSame(1, DB::table('notifications_hub')->where('user_id', $this->owner->id)
            ->where('kind', 'auditor-off:copied_report')->count());

        $this->assertSame([], AuditorAccuracy::review(), 'لا يُعاد الإطفاءُ ولا الإبلاغ');
    }

    // ═══ A4 — الملخّصُ الأسبوعيّ ═══

    public function test_الملخّصُ_الأسبوعيُّ_يذكر_إشاراتِ_المدقّق_المفتوحة(): void
    {
        $this->artisan('hub:digest', ['--dry' => true])
            ->expectsOutputToContain('🔎 المدقّق: 1 إشارةٌ مفتوحة — تقريرٌ منسوخ ×1')
            ->assertSuccessful();
    }
}
