<?php

namespace Tests\Feature\AiHub;

use App\Models\AiFinding;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\Ai\Auditor\Auditor;
use App\Support\Ai\Auditor\AuditorIdentity;
use App\Support\Ai\Auditor\AuditorSignals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المدقّقُ العامّ — الكواشفُ الحسابيّة وحدودُ الرؤية** (`docs/ai-hub/46-ai-roadmap.md` §٣).
 *
 * كلُّ كاشفٍ يُمتحن بوجهَيه: ما يجب أن يرصده، وما يشبهه ويجب **ألّا** يرصده — فكاشفٌ
 * يرصد كلَّ شيءٍ ضجيجٌ يُتجاهَل. وحدودُ الرؤية تُمتحن **بمستخدمين معزولين** (CLAUDE.md):
 * صاحبُ التقرير، ومراجعٌ في شركةٍ أخرى، ومن حُجب عنه حقل، وحسابُ عميل، ومشاهدٌ بلا تعديل.
 */
class AuditorTest extends TestCase
{
    private User $author;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->author = $this->userWithRole('author@audit.local', $this->employee->role_id);
    }

    // ═══ أدوات ═══

    private function userWithRole(string $email, string $roleId, array $extra = []): User
    {
        return User::create(['name' => 'مستخدم ' . Str::random(4), 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $roleId, 'status' => 'نشط', 'password_changed_at' => now()] + $extra);
    }

    private function report(string $day, array $cols = [], ?User $by = null): string
    {
        $id = (string) Str::uuid();
        DB::table('work_updates')->insert(array_merge([
            'id' => $id, 'done' => 'عملتُ على شاشة الفواتير وأنهيتُ جدولَ البنود', 'work_date' => $day,
            'created_by' => ($by ?? $this->author)->id, 'company_id' => $this->alpha->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $cols));

        return $id;
    }

    private function daysAgo(int $n): string
    {
        return now()->subDays($n)->toDateString();
    }

    private function findings(string $detector): \Illuminate\Support\Collection
    {
        return AiFinding::query()->where('detector', $detector)->orderBy('id')->get();
    }

    /**
     * الشاهدُ كقائمةِ مراجعَ نصّيّة — **لا مقارنةَ كائناتٍ مقروءةٍ من عمود JSON**: MySQL 8 يُعيد
     * ترتيبَ مفاتيحِ الكائن عند التخزين (`id` قبل `module`) فيسقط `assertContains` على CI وحدَه
     * (CLAUDE.md: مفاتيحُ كائنِ JSON قرعة).
     *
     * @return list<string>
     */
    private function refs(AiFinding $f): array
    {
        return array_map(fn ($e) => $e['module'] . ':' . $e['id'], (array) $f->evidence);
    }

    private function asUser(User $u): void
    {
        $this->actingAs($u);
        auth()->setUser($u);
    }

    // ═══ ① تقريرٌ منسوخ ═══

    public function test_تقريرٌ_يكرّر_إنجازَ_الأمسِ_حرفيّاً_يُرصَد_بشاهدَيه(): void
    {
        $first = $this->report($this->daysAgo(2));
        $copy = $this->report($this->daysAgo(1), ['done' => 'عملتُ على شاشة الفواتير، وأنهيتُ جدولَ البنود.']);

        Auditor::run();

        $f = $this->findings('copied_report');
        $this->assertCount(1, $f, 'نسخٌ بعد التطبيع (فاصلةٌ ونقطة) نسخٌ');
        $this->assertSame($copy, $f[0]->subject_id);
        $this->assertSame(['updates:' . $first, 'updates:' . $copy], $this->refs($f[0]));
        $this->assertSame('rule', $f[0]->source);
    }

    /**
     * **اليومُ يُقارَن باليومِ السابقِ بمجموع بنوده** — لا بـ«آخرِ بند» يحدّده ترتيبُ معرّفاتٍ
     * عشوائيّة. يُكرَّر السيناريو بمعرّفاتٍ جديدةٍ كلَّ مرّة، فلو تعلّقت النتيجةُ بالترتيب لتقلّبت.
     */
    public function test_نسخُ_أحدِ_بنودِ_الأمسِ_يُرصَد_أيّاً_كان_ترتيبُ_المعرّفات(): void
    {
        foreach (range(1, 4) as $round) {
            AiFinding::query()->delete();
            DB::table('work_updates')->delete();
            $this->report($this->daysAgo(2), ['done' => 'تصميمُ شاشةِ تسجيل الدخول للتطبيق']);
            $this->report($this->daysAgo(2), ['done' => 'مراجعةُ طلباتِ الدمج المعلّقة في المستودع']);
            $copy = $this->report($this->daysAgo(1), ['done' => 'مراجعةُ طلباتِ الدمج المعلّقة في المستودع']);
            $this->report($this->daysAgo(1), ['done' => 'إصلاحُ عطلٍ في تصدير الفواتير']);

            Auditor::run();

            $f = $this->findings('copied_report');
            $this->assertCount(1, $f, "الجولة {$round}: النسخُ عن أحدِ بنودِ الأمس يُرصَد");
            $this->assertSame($copy, $f[0]->subject_id);
        }
    }

    public function test_إنجازٌ_قصيرٌ_متكرّرٌ_أو_مختلفٌ_لا_يُعَدّ_نسخاً(): void
    {
        $this->report($this->daysAgo(3), ['done' => 'اجتماعات']);
        $this->report($this->daysAgo(2), ['done' => 'اجتماعات']);
        $this->report($this->daysAgo(1), ['done' => 'راجعتُ عقودَ المورّدين الثلاثة']);

        Auditor::run();

        $this->assertCount(0, $this->findings('copied_report'));
    }

    // ═══ ② عائقٌ متكرّر ═══

    public function test_العائقُ_نفسُه_في_ثلاثةِ_تقاريرَ_يُرصَد_والفراغُ_بصيغِه_لا(): void
    {
        foreach ([5, 4, 3] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "إنجازٌ مختلفٌ رقم {$i} في الوحدة", 'problems' => 'الخادمُ التجريبيُّ لا يعمل']);
        }
        foreach ([9, 8, 7, 6] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "عملٌ آخرُ رقم {$i} على التقارير", 'problems' => 'لا يوجد']);
        }

        Auditor::run();

        $f = $this->findings('repeated_blocker');
        $this->assertCount(1, $f, '«لا يوجد» أربعَ مرّاتٍ ليس عائقاً متكرّراً');
        $this->assertCount(3, $f[0]->evidence);
        $this->assertStringContainsString('الخادمُ التجريبيُّ لا يعمل', $f[0]->summary);
        $this->assertStringContainsString('3 أيّامٍ', $f[0]->summary);
    }

    public function test_العائقُ_يُعَدّ_بالأيّامِ_لا_بالبنود_ولا_يُقال_«ما_زال»_عن_عائقٍ_غاب_عن_آخرِ_يوم(): void
    {
        // ثلاثةُ بنودٍ في يومٍ واحدٍ بالعائقِ نفسِه ⇒ يومٌ واحدٌ لا تكرار
        foreach (['أ', 'ب', 'ج'] as $p) {
            $this->report($this->daysAgo(1), ['done' => "عملٌ على المشروع {$p} اليوم", 'problems' => 'انتظارُ صلاحيّاتِ الخادم']);
        }
        // وعائقٌ تكرّر ثلاثةَ أيّامٍ ثمّ غاب عن آخرِ يومٍ لصاحبه ⇒ لعلّه عولج
        $other = $this->userWithRole('other@audit.local', $this->employee->role_id);
        foreach ([6, 5, 4] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "مهمّةٌ رقم {$i} في الحسابات", 'problems' => 'بطءُ الشبكةِ في المكتب'], $other);
        }
        $this->report($this->daysAgo(2), ['done' => 'إقفالُ حساباتِ الشهر', 'problems' => 'لا يوجد'], $other);

        Auditor::run();

        $this->assertCount(0, $this->findings('repeated_blocker'));
    }

    public function test_رفضُ_المديرِ_لإشارةِ_العائق_يبقى_وإن_تكرّر_العائقُ_في_تقريرٍ_جديد(): void
    {
        $f = $this->blockerFinding();
        $this->asUser($this->owner);
        $this->assertTrue(ActionCenter::disposition($f->signalKey(), 'dismiss', null, 'نعرفه ونتابعه'));

        // يومٌ جديدٌ بالعائقِ نفسِه — الشرطُ نفسُه لا شرطٌ جديد
        $this->report($this->daysAgo(0), ['done' => 'إنجازٌ مختلفٌ اليوم في الوحدة', 'problems' => 'الخادمُ التجريبيُّ لا يعمل']);
        Auditor::run();

        $again = $this->findings('repeated_blocker');
        $this->assertCount(1, $again, 'نتيجةٌ واحدةٌ للشرطِ الواحد');
        $this->assertSame($f->signalKey(), $again[0]->signalKey(), 'المفتاحُ ثابتٌ وإن انتقل الموضوعُ إلى أحدثِ تقرير');
        $this->assertNotSame($f->subject_id, $again[0]->subject_id, 'والموضوعُ صار أحدثَ تقرير');
        $this->assertNull(collect(ActionCenter::signals(true)['visible'])->firstWhere('key', $f->signalKey()),
            'والرفضُ باقٍ');
    }

    // ═══ ③ ساعاتٌ بلا تقدّم ═══

    private function task(array $cols = []): string
    {
        $id = (string) Str::uuid();
        DB::table('tasks')->insert(array_merge(['id' => $id, 'title' => 'ربطُ بوّابةِ الدفع', 'status' => 'قيد التنفيذ',
            'progress' => 40, 'company_id' => $this->alpha->id, 'created_at' => now(), 'updated_at' => now()], $cols));

        return $id;
    }

    public function test_ساعاتٌ_كثيرةٌ_ونسبةٌ_ثابتةٌ_تُرصَد_والنسبةُ_المتحرّكةُ_أو_الغائبةُ_لا(): void
    {
        $stuck = $this->task();
        foreach ([3, 2, 1] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "محاولةٌ رقم {$i} لإصلاح الربط", 'task_id' => $stuck, 'hours' => 5, 'progress' => 40 + $i]);
        }

        $moving = $this->task(['title' => 'شاشةُ العملاء']);
        foreach ([3, 2, 1] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "تقدّمٌ رقم {$i} في الشاشة", 'task_id' => $moving, 'hours' => 6, 'progress' => 20 * ($i + 1)]);
        }

        $unmeasured = $this->task(['title' => 'توثيقُ الواجهة']);
        foreach ([3, 2, 1] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "كتابةُ القسم {$i} من التوثيق", 'task_id' => $unmeasured, 'hours' => 6, 'progress' => null]);
        }

        $done = $this->task(['title' => 'مهمّةٌ منجزة', 'status' => 'منجزة']);
        foreach ([3, 2, 1] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "مراجعةٌ أخيرة {$i}", 'task_id' => $done, 'hours' => 6, 'progress' => 90]);
        }

        Auditor::run();

        $f = $this->findings('hours_no_progress');
        $this->assertCount(1, $f, 'واحدةٌ فقط: المتحرّكةُ تقدّمت، وغيرُ المقيسةِ لا حكمَ عليها، والمنجزةُ خارجَ النظر');
        $this->assertContains('tasks:' . $stuck, $this->refs($f[0]));
        $this->assertStringContainsString('15 ساعة', $f[0]->summary);
        $this->assertStringContainsString('3 أيّامٍ', $f[0]->summary);
    }

    // ═══ ④ قرارٌ بلا مهمّة ═══

    private function decision(array $cols = []): string
    {
        $id = (string) Str::uuid();
        DB::table('decisions')->insert(array_merge(['id' => $id, 'title' => 'اعتمادُ مورّدٍ ثانٍ للخوادم',
            'status' => 'قيد التنفيذ', 'company_id' => $this->alpha->id,
            'created_at' => now()->subDays(10), 'updated_at' => now()], $cols));

        return $id;
    }

    public function test_قرارٌ_عمرُه_أسبوعٌ_بلا_مهمّةٍ_يُرصَد_ويُحَلُّ_حين_تُربَط_به_مهمّة(): void
    {
        $orphan = $this->decision();
        $this->decision(['title' => 'قرارٌ حديث', 'created_at' => now()->subDays(2)]);
        $this->decision(['title' => 'قرارٌ مغلق', 'status' => 'منفذ']);

        Auditor::run();
        $f = $this->findings('decision_without_task');
        $this->assertCount(1, $f, 'الحديثُ لم يحن سؤالُه، والمغلقُ انتهى');
        $this->assertSame($orphan, $f[0]->subject_id);
        $this->assertSame('open', $f[0]->status);

        $this->task(['decision_id' => $orphan]);
        Auditor::run();
        $this->assertSame('resolved', $this->findings('decision_without_task')[0]->status, 'زال الشرطُ فحُلَّت');

        DB::table('tasks')->where('decision_id', $orphan)->update(['deleted_at' => now()]);
        Auditor::run();
        $again = $this->findings('decision_without_task');
        $this->assertCount(1, $again, 'تُعاد فتحاً لا تتكرّر');
        $this->assertSame('open', $again[0]->status);
    }

    // ═══ ⑤ الدورةُ والمفاتيح ═══

    public function test_المعاينةُ_لا_تكتب_والإطفاءُ_يوقف_الجولةَ_ويُخفي_النتائج(): void
    {
        $this->decision();

        $dry = Auditor::run(dry: true);
        $this->assertSame(1, $dry['decision_without_task']['found']);
        $this->assertSame(1, $dry['decision_without_task']['opened'], 'المعاينةُ تقول كم سيُفتح');
        $this->assertSame(0, AiFinding::count(), 'المعاينةُ لا تكتب');

        Auditor::run();
        $this->assertSame(1, AiFinding::count());

        $this->hubSetting('auditor.enabled', '0');
        $this->assertSame([], Auditor::run(), 'مطفأٌ فلا جولة');
        $this->asUser($this->owner);
        $this->assertSame([], AuditorSignals::visibleTo($this->owner), 'مطفأٌ فلا عرض');
        $this->assertSame(1, AiFinding::count(), 'والإطفاءُ لا يمسح');
    }

    public function test_هويّةُ_المدقّق_لا_تُحفَظ_ولا_ترى_حقلاً_حسّاساً_ولا_تكتب(): void
    {
        $users = User::count();
        $a = AuditorIdentity::user();

        $this->assertFalse($a->exists, 'لا صفَّ في users');
        $this->assertFalse(hub_can($a, 'updates', 'e'));
        $this->assertFalse(hub_can($a, 'hr', 'v'), 'لا يقرأ الموارد البشريّة أصلاً');
        $this->assertTrue(hub_can($a, 'updates', 'v'));
        $this->assertSame('hide', hub_field_mode($a, 'projects', 'budget'), 'ميزانيّةُ المشروع حقلٌ حسّاسٌ محجوب');

        $this->decision();
        Auditor::run();
        $this->assertSame($users, User::count(), 'الجولةُ لا تُنشئ مستخدماً');
    }

    /**
     * **«المدقّقُ لا يكتب في سجلّ»** — وعدٌ يُقاس لا يُكتَب: بصمةُ كلِّ صفٍّ في جداولِ العمل
     * قبل الجولة وبعدها واحدة. ما يتغيّر هو `ai_findings` وحدَه.
     */
    public function test_الجولةُ_لا_تمسّ_صفّاً_واحداً_في_جداولِ_العمل(): void
    {
        foreach ([5, 4, 3] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "إنجازٌ رقم {$i} في الوحدة", 'problems' => 'الخادمُ التجريبيُّ لا يعمل']);
        }
        $task = $this->task();
        foreach ([3, 2, 1] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "محاولةٌ {$i}", 'task_id' => $task, 'hours' => 6, 'progress' => 10]);
        }
        $this->decision();

        $tables = ['work_updates', 'tasks', 'decisions', 'meetings', 'projects', 'users', 'roles', 'notifications_hub', 'audit_log'];
        $print = fn () => collect($tables)->filter(fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => md5(json_encode(DB::table($t)->orderBy('id')->get()))])->all();

        $before = $print();
        $stats = Auditor::run();
        $this->assertGreaterThan(0, array_sum(array_column($stats, 'opened')), 'الجولةُ رصدت شيئاً فعلاً');
        $this->assertSame($before, $print(), 'جدولٌ من جداولِ العمل تغيّر');
    }

    public function test_نتائجُ_المدقّق_ليست_حالةَ_نظامٍ_ولا_تصل_مستوى_التحكّم(): void
    {
        $f = $this->blockerFinding();
        $this->asUser($this->owner);
        $visible = ActionCenter::signals(true)['visible'];

        $this->assertNotNull(collect($visible)->firstWhere('key', $f->signalKey()));
        $control = \App\Support\AttentionQueue::forControl($visible);
        $this->assertSame([], array_values(array_filter(array_map(fn ($s) => $s['key'] ?? '', $control),
            fn ($k) => str_starts_with((string) $k, 'audit:'))), 'حكمٌ على عملِ فريقٍ ليس حالةَ نظام');
        $this->assertNotContains(AuditorSignals::TYPE, \App\Support\AttentionQueue::TYPES);
    }

    // ═══ ⑥ حدودُ الرؤية — بمستخدمين معزولين ═══

    private function blockerFinding(): AiFinding
    {
        foreach ([5, 4, 3] as $i => $d) {
            $this->report($this->daysAgo($d), ['done' => "إنجازٌ مختلفٌ رقم {$i} في الوحدة", 'problems' => 'الخادمُ التجريبيُّ لا يعمل']);
        }
        Auditor::run();

        return $this->findings('repeated_blocker')[0];
    }

    public function test_المراجعُ_يرى_النتيجةَ_وصاحبُ_التقرير_لا_يرى_حكمَ_المدقّقِ_على_عمله(): void
    {
        $f = $this->blockerFinding();
        $reviewer = $this->employee;   // تعديلٌ على كلِّ الوحدات ⇒ يراجع تقاريرَ غيرِه

        $this->assertTrue(AuditorSignals::canSee($reviewer, $f));
        $this->assertTrue(AuditorSignals::canSee($this->owner, $f));
        $this->assertFalse(AuditorSignals::canSee($this->author, $f), 'صاحبُ التقرير يصله ما اعتمده مديرُه لا الحكمُ الخام');
    }

    public function test_لا_يراها_مشاهدٌ_بلا_تعديلٍ_ولا_حسابُ_عميلٍ_ولا_من_حُجب_عنه_حقلُها(): void
    {
        $f = $this->blockerFinding();

        $this->assertFalse(AuditorSignals::canSee($this->viewer, $f), 'المشاهدُ لا يراجع');

        $client = $this->userWithRole('client@audit.local', $this->employee->role_id, ['account_type' => 'client']);
        $this->assertFalse(AuditorSignals::canSee($client, $f));

        $hidden = Role::create(['name' => 'مراجعٌ محجوب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'hr' => ['v' => 1, 'a' => 0, 'e' => 1, 'd' => 0]],
            'field_rules' => ['updates' => ['problems' => 'hide']]]);
        $blind = $this->userWithRole('blind@audit.local', $hidden->id);
        $this->assertFalse(AuditorSignals::canSee($blind, $f), 'الملخّصُ يحمل نصَّ حقلٍ محجوبٍ عنه');
    }

    public function test_مراجعٌ_في_شركةٍ_أخرى_لا_يرى_نتيجةً_على_تقاريرِ_غيرِ_شركته(): void
    {
        $f = $this->blockerFinding();
        $beta = Company::create(['name_ar' => 'شركةُ باء']);
        $other = $this->userWithRole('beta@audit.local', $this->employee->role_id, ['companies' => [$beta->id]]);

        $this->assertFalse(AuditorSignals::canSee($other, $f), 'اتّساعُ قراءةِ المدقّق لا يصير اتّساعَ رؤية');
    }

    /**
     * **السقفُ بعد الترشيح لا قبله:** مراجعٌ في شركةٍ أخرى يرى نتيجةَ شركته القديمةَ وإن
     * سبقتها في الرصد صفحاتٌ من نتائجِ غيرها.
     */
    public function test_نتيجةٌ_قديمةٌ_تصل_مراجعَها_وإن_سبقتها_نتائجُ_شركاتٍ_أخرى(): void
    {
        $beta = Company::create(['name_ar' => 'شركةُ باء']);
        $reviewer = $this->userWithRole('beta-rev@audit.local', $this->employee->role_id, ['companies' => [$beta->id]]);
        $d = $this->decision(['company_id' => $beta->id]);
        Auditor::run();
        AiFinding::query()->update(['detected_at' => now()->subDays(3)]);

        foreach (range(1, AuditorSignals::PAGE + 10) as $i) {
            AiFinding::create(['detector' => 'decision_without_task', 'dedup_key' => sha1("noise{$i}"),
                'subject_module' => 'decisions', 'subject_id' => (string) Str::uuid(), 'company_id' => $this->alpha->id,
                'evidence' => [['module' => 'decisions', 'id' => (string) Str::uuid()]], 'fields' => ['decisions' => ['title']],
                'summary' => "ضجيج {$i}", 'fingerprint' => str_repeat('0', 64), 'status' => 'open', 'detected_at' => now()]);
        }

        $keys = array_column(AuditorSignals::visibleTo($reviewer, null, true), 'record_id');
        $this->assertContains($d, $keys);
    }

    /**
     * **كلفةُ العرض لا تكبر بعدد النتائج على الكتّابِ أنفسِهم.** المشاهدُ مديرُ مشروعٍ محدودُ
     * النطاق — وحكمُ مراجعته يكلّف استعلاماً (نطاقُ المشاريع) — والكتّابُ اثنان ثابتان،
     * ونتائجُ كلٍّ منهما واحدةٌ ثمّ أربع. بلا حفظِ الحكمِ لكلِّ (كاتب · مشروع) يتضاعف الاستعلام.
     */
    public function test_كلفةُ_العرض_لا_تكبر_بعدد_النتائج_على_الكتّابِ_أنفسِهم(): void
    {
        $role = Role::create(['name' => 'مديرُ مشروع', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'projects' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]]);
        $pm = $this->userWithRole('pm@audit.local', $role->id);
        $project = \App\Models\Project::create(['name' => 'مشروعُ القياس', 'company_id' => $this->alpha->id, 'manager_id' => $pm->id]);
        $authors = [$this->author, $this->userWithRole('author2@audit.local', $this->employee->role_id)];

        $measure = function (int $perAuthor) use ($pm, $project, $authors): int {
            AiFinding::query()->delete();
            DB::table('work_updates')->delete();
            foreach ($authors as $a => $u) {
                foreach (range(1, $perAuthor) as $k) {
                    foreach ([5, 4, 3] as $i => $d) {
                        $this->report($this->daysAgo($d), ['project_id' => $project->id,
                            'done' => "بندٌ {$k}-{$i} للكاتب {$a}", 'problems' => "عائقٌ رقم {$k} للكاتب {$a}"], $u);
                    }
                }
            }
            Auditor::run();
            $this->assertCount(2 * $perAuthor, $this->findings('repeated_blocker'));

            AuditorSignals::visibleTo($pm, null, true);   // تسخينُ ما يُخبَّأ خارجَنا (الدور، مشاريعُ المدير)
            DB::flushQueryLog();
            DB::enableQueryLog();
            $n = count(AuditorSignals::visibleTo($pm, null, true));
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame(2 * $perAuthor, $n, 'مديرُ المشروع يرى نتائجَ مشروعه كلَّها');

            return $q;
        };

        $one = $measure(1);
        $four = $measure(4);
        $this->assertSame($one, $four, "استعلاماتُ العرض: {$one} لنتيجتين، {$four} لثماني — يجب أن تتساويا");
    }

    // ═══ ⑦ مركزُ الفعل ═══

    public function test_النتيجةُ_إشارةٌ_في_مركز_الفعل_تُرفَض_بسكّةِ_التصرّف_القائمة(): void
    {
        $f = $this->blockerFinding();
        $this->asUser($this->owner);

        $feed = ActionCenter::signals(true);
        $sig = collect($feed['visible'])->firstWhere('key', $f->signalKey());
        $this->assertNotNull($sig, 'النتيجةُ في صفِّ المالك');
        $this->assertSame('مهم', $sig['sev'], 'لا «حرج» من المدقّق — فيبقى رفضُها ممكناً');
        $this->assertSame('updates', $sig['module']);

        $this->assertTrue(ActionCenter::disposition($f->signalKey(), 'dismiss', null, 'مُعالَج خارج النظام'));
        $after = ActionCenter::signals(true);
        $this->assertNull(collect($after['visible'])->firstWhere('key', $f->signalKey()));
        $this->assertSame(1, $after['dismissed']);

        $this->asUser($this->author);
        $this->assertNull(collect(ActionCenter::signals(true)['visible'])->firstWhere('key', $f->signalKey()),
            'ولا تظهر لصاحبِ التقرير في صفِّه');
    }

    public function test_الأمرُ_اليدويُّ_والدورةُ_اليوميّةُ_يشغّلان_الجولة(): void
    {
        $this->decision();

        $this->assertSame(0, Artisan::call('hub:auditor', ['--dry' => true]));
        $this->assertSame(0, AiFinding::count());

        // `Artisan::output()` يحمل آخرَ أمرٍ فقط، والدورةُ تنادي أوامرَ داخلها — فيُقاس المخرَجُ بالمُعترِض
        $this->artisan('hub:automation')->expectsOutputToContain('المدقّق: 1 نتيجةٌ جديدة')->assertSuccessful();
        $this->assertSame(1, AiFinding::count());
    }
}
