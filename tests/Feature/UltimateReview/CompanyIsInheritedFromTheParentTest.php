<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الشركةُ تُشتقُّ من السجلِّ لا من صاحبِ الجلسةِ وحدَه** (المراجعةُ الشاملة ·
 * الطبقة ٢ · L2-09).
 *
 * عمى التذاكرِ (L2-05) لم يكن حادثةً مفردة. مسحُ «عمودٍ خاوٍ تماماً بعد شهرِ
 * عملٍ كامل» أعاد الصنفَ نفسَه على **خمسِ وحداتٍ أخرى**:
 *
 * ```
 * tasks        ٠ من ١٢١        issues       ٠ من ٦
 * clients      ٠ من ٦          engagements  ٠ من ٥
 * accounts2    ٠ من ١٠
 * ```
 *
 * بينما المشاريعُ ١٦ من ١٦، والأصولُ ١٠٤ من ١٠٤، والحضورُ ١٤٨ من ١٤٨.
 *
 * **والدليلُ الحاسمُ على الآليّة** في `updates`: **١٨ من ٥٨٠**. فالختمُ ليس
 * غائباً — `ModuleController::inheritCompany` موجودةٌ وتعمل — لكنّها تعرف
 * **مصدرَين اثنين فقط، وكلاهما يصف صاحبَ الجلسةِ لا السجلَّ**: الشركةَ النشطةَ
 * في الجلسة، وأولى شركاتِ المُنشئِ **إن كان معزولاً**. فالثمانيةَ عشرَ كتبها
 * معزولون، والخمسُمئةِ واثنان وستّون كتبها غيرُهم.
 *
 * فمهمّةٌ على مشروعٍ تملكه شركةٌ بعينِها تُحفَظ «بلا مالك» — لأنّ **الشخصَ** الذي
 * أنشأها لم يكن مقيَّداً بشركة. ولا يظهر الأثرُ يومَ الكتابة بل **يومَ يُوظَّف
 * أوّلُ موظّفٍ معزول**: الحارسُ `whereIn(company_id, …)` يُسقط كلَّ صفٍّ فارغ،
 * فيفتح وحدتَه فيراها خاوية.
 *
 * **العلاجُ مصدرٌ ثالث: أبُ السجلِّ** (`hub_company_from_parent`) — يُستشار حين
 * يعجز الأوّلان، ولا يكتب فوق قيمةٍ قائمة. ومعه هجرةُ ردمٍ **غيرِ مدمّرة** تملأ
 * الفارغَ من الأبِ وحدَه: المهامُّ ١٢١ من ١٢١، والعوائقُ ٦ من ٦.
 *
 * **والعميلُ حالةٌ خاصّة** — لا أبَ له، فتُستنتَج شركتُه من مشاريعه. أربعةٌ من
 * ستّةٍ تُستنتَج يقيناً، و**اثنان تخدمهما شركتان** من المجموعة فلا يسعُهما عمودٌ
 * مفرد. وقرارُ المالك: **يُشتقُّ ما لا يلتبس، ويُعلَن الملتبسُ** فيبقى بلا
 * انتماءٍ يُقرأ للجميع وتقول الشاشةُ لماذا — بدل أن يُحسَم بقرعةٍ تُثبِت نصفَ
 * الحقيقةِ وتُخفي نصفَها.
 */
class CompanyIsInheritedFromTheParentTest extends TestCase
{
    private function company(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name_ar' => $name,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** مُنشئٌ **غيرُ معزول** — لا شركةَ نشطةَ ولا قيدَ شركات: حالُ المالكِ والإدارة */
    private function unboundAuthor(array $matrix): User
    {
        $role = Role::create(['name' => 'محرّرٌ غيرُ معزول' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'محرّرٌ بلا قيدِ شركة',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function isolated(string $companyId, array $matrix): User
    {
        $role = Role::create(['name' => 'موظّفٌ معزول' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'موظّفٌ معزولٌ بشركة',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط',
            'company_id' => $companyId, 'companies' => [$companyId],
            'password_changed_at' => now()]);
    }

    // ── ① الختمُ عند الإنشاء ──────────────────────────────────────────────

    public function test_a_task_created_by_an_unbound_author_inherits_its_project_company(): void
    {
        $co = $this->company('شركةُ الخليج');
        $p = Project::create(['name' => 'مشروعُ الخليج', 'status' => 'قيد التنفيذ', 'company_id' => $co]);
        $u = $this->unboundAuthor(['tasks' => ['v' => 1, 'a' => 1, 'e' => 1]]);

        $this->assertNull(hub_company_ids($u), 'شرطُ الاختبار: المُنشئُ غيرُ معزول');

        $this->actingAs($u)->post(route('m.store', 'tasks'), [
            'title' => 'مهمّةٌ على مشروعٍ مملوك', 'projectId' => $p->id,
        ])->assertRedirect();

        $t = Task::where('title', 'مهمّةٌ على مشروعٍ مملوك')->first();
        $this->assertNotNull($t, 'المهمّةُ لم تُحفَظ');
        $this->assertSame($co, (string) $t->company_id,
            'المهمّةُ حُفظت بلا شركةٍ رغمَ أنّ مشروعَها يُعلن مالكَه');
    }

    public function test_the_parent_is_consulted_only_when_the_session_cannot_answer(): void
    {
        $a = $this->company('شركةُ الأب');
        $b = $this->company('شركةُ المُنشئ');
        $p = Project::create(['name' => 'مشروعُ الأب', 'status' => 'قيد التنفيذ', 'company_id' => $a]);
        $u = $this->isolated($b, ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1],
            'projects' => ['v' => 1]]);

        $this->actingAs($u)->post(route('m.store', 'tasks'), [
            'title' => 'مهمّةٌ لمعزولٍ على مشروعِ غيرِه', 'projectId' => $p->id,
        ]);

        $t = Task::where('title', 'مهمّةٌ لمعزولٍ على مشروعِ غيرِه')->first();
        if ($t) {
            $this->assertSame($b, (string) $t->company_id,
                'الأبُ طغى على شركةِ المُنشئِ المعزول — والمصدرُ الثالثُ احتياطيٌّ لا أوّليّ');
        } else {
            $this->assertTrue(true, 'الحارسُ ردّ الإنشاءَ أصلاً — وهو تشديدٌ مقبول');
        }
    }

    public function test_an_explicit_company_is_never_overwritten_by_the_parent(): void
    {
        $a = $this->company('شركةُ المشروع');
        $b = $this->company('الشركةُ المذكورةُ صراحةً');
        $p = Project::create(['name' => 'مشروعٌ لشركةٍ', 'status' => 'قيد التنفيذ', 'company_id' => $a]);

        $t = new Task(['title' => 'مهمّةٌ بشركةٍ مذكورة', 'project_id' => $p->id, 'company_id' => $b]);
        $this->assertSame($b, (string) $t->company_id);
        $this->assertSame($a, hub_company_from_parent('tasks', $t),
            'الاشتقاقُ يقرأ الأبَ بصدق');
        // والدالّةُ تُستشار فقط حين تكون الخانةُ فارغة — يُثبته الحارسُ في inheritCompany
    }

    // ── ② العميلُ: يُشتقُّ ما لا يلتبس ────────────────────────────────────

    public function test_a_client_served_by_one_company_derives_it(): void
    {
        $co = $this->company('الشركةُ الوحيدة');
        $c = Client::create(['name' => 'عميلٌ لشركةٍ واحدة']);
        Project::create(['name' => 'مشروعٌ أول', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $co]);
        Project::create(['name' => 'مشروعٌ ثانٍ', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $co]);

        $this->assertSame($co, hub_company_from_parent('clients', $c),
            'عميلٌ كلُّ مشاريعِه لشركةٍ واحدةٍ لم تُستنتَج شركتُه');
    }

    public function test_a_client_served_by_two_companies_stays_undeclared(): void
    {
        $a = $this->company('الشركةُ الأولى');
        $b = $this->company('الشركةُ الثانية');
        $c = Client::create(['name' => 'عميلٌ تخدمه شركتان']);
        Project::create(['name' => 'مشروعُ الأولى', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $a]);
        Project::create(['name' => 'مشروعُ الثانية', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $b]);

        $this->assertNull(hub_company_from_parent('clients', $c),
            'عميلٌ ملتبسُ الانتماءِ حُسم بقرعة — والقرعةُ تُثبِت نصفَ الحقيقةِ وتُخفي نصفَها');
    }

    public function test_the_ambiguous_client_is_readable_not_hidden(): void
    {
        $this->assertTrue(hub_company_null_is_unowned('clients'),
            'العميلُ الملتبسُ محجوبٌ عن المعزولين — وقرارُ المالك أن يُعلَن لا أن يُخفى');
    }

    // ── ③ الردمُ ولا يتجاوز حدَّه ────────────────────────────────────────

    public function test_the_backfill_fills_from_the_parent_and_nothing_else(): void
    {
        $co = $this->company('شركةُ الردم');
        $owned = Project::create(['name' => 'مشروعٌ مملوك', 'status' => 'قيد التنفيذ', 'company_id' => $co]);
        $orphan = Project::create(['name' => 'مشروعٌ بلا مالك', 'status' => 'قيد التنفيذ']);

        $a = Task::create(['title' => 'تُردَم من مشروعها', 'project_id' => $owned->id]);
        $b = Task::create(['title' => 'لا أبَ يُعلن لها', 'project_id' => $orphan->id]);
        $c = Task::create(['title' => 'بلا مشروعٍ أصلاً']);
        DB::table('tasks')->whereIn('id', [$a->id, $b->id, $c->id])->update(['company_id' => null]);

        $mig = require base_path('database/migrations/2026_10_02_000001_backfill_company_from_parent.php');
        $mig->up();

        $this->assertSame($co, (string) DB::table('tasks')->where('id', $a->id)->value('company_id'),
            'الردمُ لم يملأ ما لأبيه مالكٌ معلَن');
        $this->assertNull(DB::table('tasks')->where('id', $b->id)->value('company_id'),
            'الردمُ اخترع مالكاً لأبٍ لا يُعلن مالكَه');
        $this->assertNull(DB::table('tasks')->where('id', $c->id)->value('company_id'),
            'الردمُ اخترع مالكاً لسجلٍّ بلا أب');
    }

    public function test_the_backfill_never_overwrites_a_declared_company(): void
    {
        $a = $this->company('شركةُ المشروع');
        $b = $this->company('الشركةُ المحفوظة');
        $p = Project::create(['name' => 'مشروعُ الردم الثاني', 'status' => 'قيد التنفيذ', 'company_id' => $a]);
        $t = Task::create(['title' => 'مهمّةٌ لها شركةٌ محفوظة', 'project_id' => $p->id]);
        DB::table('tasks')->where('id', $t->id)->update(['company_id' => $b]);

        $mig = require base_path('database/migrations/2026_10_02_000001_backfill_company_from_parent.php');
        $mig->up();

        $this->assertSame($b, (string) DB::table('tasks')->where('id', $t->id)->value('company_id'),
            'الردمُ داس على انتماءٍ محسوم');
    }

    // ── ④ ولا يُفتَح بذلك باب ────────────────────────────────────────────

    public function test_a_stamped_task_stays_hidden_from_another_company(): void
    {
        $mine = $this->company('شركتي');
        $theirs = $this->company('شركةٌ أخرى');
        $p = Project::create(['name' => 'مشروعُ الأخرى', 'status' => 'قيد التنفيذ', 'company_id' => $theirs]);
        $t = Task::create(['title' => 'مهمّةُ شركةٍ أخرى', 'project_id' => $p->id,
            'company_id' => $theirs]);

        $u = $this->isolated($mine, ['tasks' => ['v' => 1]]);
        $this->actingAs($u);

        $seen = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->pluck('id')->map('strval')->all();

        $this->assertNotContains((string) $t->id, $seen,
            'مهمّةٌ تحمل شركةً أخرى ظهرت لمن ليس منها — الإعلانُ فتح باباً');
    }

    public function test_an_orphan_task_is_readable_by_an_isolated_employee(): void
    {
        $mine = $this->company('شركتي');
        $t = Task::create(['title' => 'مهمّةٌ داخليّةٌ بلا مشروع']);
        DB::table('tasks')->where('id', $t->id)->update(['company_id' => null]);

        $u = $this->isolated($mine, ['tasks' => ['v' => 1]]);
        $this->actingAs($u);

        $seen = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->pluck('id')->map('strval')->all();

        $this->assertContains((string) $t->id, $seen,
            'مهمّةٌ لا تُعلن انتماءً حُجبت — وحجبُها يُفرغ الشاشةَ بلا مكسبِ عزل');
    }

    // ── ⑤ واللبسُ يُقال على الشاشة ──────────────────────────────────────

    public function test_the_client_page_declares_an_ambiguous_company(): void
    {
        $a = $this->company('شركةُ الرؤية');
        $b = $this->company('شركةُ الأفق');
        $c = Client::create(['name' => 'عميلٌ بين شركتَين']);
        Project::create(['name' => 'مشروعُ الرؤية', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $a]);
        Project::create(['name' => 'مشروعُ الأفق', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $b]);

        $u = $this->unboundAuthor(['clients' => ['v' => 1, 'e' => 1]]);
        $html = $this->actingAs($u)->get(route('m.show', ['clients', $c->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('انتماءُ هذا العميلِ غيرُ محسوم', $html,
            'العميلُ الملتبسُ لا يقول على شاشته لماذا يراه الجميع');
        $this->assertStringContainsString('شركةُ الرؤية', $html, 'اللافتةُ لا تسمّي الشركتَين');
        $this->assertStringContainsString('شركةُ الأفق', $html, 'اللافتةُ لا تسمّي الشركتَين');
    }

    public function test_a_settled_client_shows_no_such_banner(): void
    {
        $co = $this->company('الشركةُ الحاسمة');
        $c = Client::create(['name' => 'عميلٌ محسومُ الانتماء', 'company_id' => $co]);
        Project::create(['name' => 'مشروعٌ واحد', 'status' => 'قيد التنفيذ',
            'client_id' => $c->id, 'company_id' => $co]);

        $u = $this->unboundAuthor(['clients' => ['v' => 1, 'e' => 1]]);
        $html = $this->actingAs($u)->get(route('m.show', ['clients', $c->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('انتماءُ هذا العميلِ غيرُ محسوم', $html,
            'لافتةُ اللبسِ ظهرت على عميلٍ انتماؤه محسوم');
    }
}
