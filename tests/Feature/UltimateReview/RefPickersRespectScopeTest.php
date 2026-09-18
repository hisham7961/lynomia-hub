<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **القائمةُ المنسدلةُ لا تتجاوز ما يراه صاحبُها** (المراجعةُ الشاملة · الطبقة ٢ ·
 * L2-07).
 *
 * `hub_ref_options_scoped` هو **القارئُ المنطَّقُ الواحد** للقوائم المرجعيّة،
 * وتوثيقُه يقول غايتَه صراحةً: «فقائمةٌ خامٌّ كانت تسرّب أسماء مشاريع وعملاء لا
 * يراهم صاحب الحساب». لكنّه يضيّق بثلاثةٍ فقط — المشاريعِ المرئيّة، وعمودِ
 * الشركة، وعمودِ العميل — و**لا يطبّق نطاقَ الوحدةِ نفسِها** (`hub_scope`).
 *
 * قِيس على موظّفٍ عاديّ في قاعدةِ المحاكاة (أحمد قاسم · مطوّر):
 *
 * ```
 * /m/tasks يريه          ٣٢ مهمّة
 * مُنتقي المهمّة يسمّي   ١٢١ مهمّة
 * ```
 *
 * وأُثبت بطريقَين مستقلَّين: مهمّةٌ بعينها (`ccbc2340…` من مشروعِ «مستشفى
 * السلام») **تردّ ٤٠٤ إن فُتحت مباشرةً**، و**تُسمّى بعنوانِها الكاملِ** في قائمةِ
 * نموذجِه (١٢٢ خياراً في HTML المُصيَّر). اثنا عشرَ موضعاً متاحاً له،
 * **٢٨١ صفّاً مسرَّباً**.
 *
 * **وعيبٌ ثانٍ في الترتيب:** `hub_ref_options` تقصّ عند **٥٠٠ صفٍّ مرتَّبةً
 * أبجديّاً** ثمّ يُرشِّح التضييقُ ما تبقّى. فعلى منشأةٍ فيها آلافُ المهامّ لا تظهر
 * مهامُّ الموظّفِ أصلاً — لا لأنّها محجوبةٌ بل لأنّ أسماءَها بعد الخمسمئة. فالقصُّ
 * **قبل** التضييق يجعل القائمةَ عديمةَ الفائدةِ مع الكِبَر.
 *
 * **ولمَ لا يُضيَّق كلُّ مرجعٍ:** التضييقُ الشاملُ يكسر سيراً مشروعاً.
 * `hub_scope('hr')` لموظّفٍ عاديٍّ **صفر** — فتضييقُ `leaves.empId` يُفرغ القائمةَ
 * ويمنعه من **طلبِ إجازتِه هو**. فالتضييقُ **يُعلَن مرجعاً مرجعاً بدليل**
 * (`hub_tenancy.ref_scope`)، والباقي يبقى واسعاً **بسببٍ مكتوب** لا بصمت.
 */
class RefPickersRespectScopeTest extends TestCase
{
    /** موظّفٌ محدودُ النطاق على مشروعٍ واحد */
    private function scopedTo(Project $p, array $matrix): User
    {
        $role = Role::create(['name' => 'محدودُ النطاق' . Str::random(4), 'scope' => 'proj',
            'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'موظّفٌ محدودُ النطاق',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $p->members = [$u->id];
        $p->save();

        return $u;
    }

    // ── ① المراجعُ المُعلَنُ تضييقُها ────────────────────────────────────

    public function test_the_task_picker_offers_only_tasks_in_scope(): void
    {
        $mine = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ']);
        $theirs = Project::create(['name' => 'مشروعُ غيري', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($mine, ['tasks' => ['v' => 1], 'updates' => ['v' => 1, 'a' => 1]]);

        $ok = Task::create(['title' => 'مهمّةٌ في مشروعي', 'project_id' => $mine->id]);
        $out = Task::create(['title' => 'مهمّةٌ في مشروعِ غيري', 'project_id' => $theirs->id]);

        $this->actingAs($u);
        $opts = hub_ref_options_scoped('tasks');

        $this->assertArrayHasKey($ok->id, $opts, 'مهمّتُه هو غابت عن قائمته');
        $this->assertArrayNotHasKey($out->id, $opts,
            'القائمةُ تسمّي مهمّةً في مشروعٍ لا يراه — وفتحُها مباشرةً يردّ ٤٠٤');
    }

    public function test_the_ticket_picker_offers_only_tickets_in_scope(): void
    {
        $mine = Project::create(['name' => 'مشروعُ التذاكر', 'status' => 'قيد التنفيذ']);
        $theirs = Project::create(['name' => 'مشروعُ تذاكرِ غيري', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($mine, ['tickets' => ['v' => 1], 'tasks' => ['v' => 1, 'a' => 1]]);

        $ok = Ticket::create(['subject' => 'تذكرةٌ في مشروعي', 'project_id' => $mine->id]);
        $out = Ticket::create(['subject' => 'تذكرةٌ في مشروعِ غيري', 'project_id' => $theirs->id]);

        $this->actingAs($u);
        $opts = hub_ref_options_scoped('tickets');

        $this->assertArrayHasKey($ok->id, $opts, 'تذكرتُه غابت عن قائمته');
        $this->assertArrayNotHasKey($out->id, $opts, 'القائمةُ تسمّي تذكرةً خارجَ نطاقه');
    }

    /** والنموذجُ الحيُّ يعكس ذلك — لا القارئُ وحدَه */
    public function test_the_rendered_form_does_not_name_an_out_of_scope_task(): void
    {
        $mine = Project::create(['name' => 'مشروعُ النموذج', 'status' => 'قيد التنفيذ']);
        $theirs = Project::create(['name' => 'مشروعٌ بعيد', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($mine, ['tasks' => ['v' => 1], 'updates' => ['v' => 1, 'a' => 1],
            'projects' => ['v' => 1]]);
        $out = Task::create(['title' => 'عنوانٌ لا يجوز أن يقرأه', 'project_id' => $theirs->id]);

        $html = $this->actingAs($u)->get(route('m.create', 'updates'))->assertOk()->getContent();

        $this->assertStringNotContainsString('عنوانٌ لا يجوز أن يقرأه', $html,
            'النموذجُ المُصيَّر يطبع عنوانَ مهمّةٍ خارجَ نطاقِ قارئه');
    }

    // ── ② القصُّ قبل التضييق — العيبُ الذي يظهر مع الكِبَر ──────────────

    public function test_a_scoped_task_survives_the_five_hundred_row_cap(): void
    {
        $mine = Project::create(['name' => 'مشروعُ الحدّ', 'status' => 'قيد التنفيذ']);
        $bulk = Project::create(['name' => 'مشروعُ الحشو', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($mine, ['tasks' => ['v' => 1], 'updates' => ['v' => 1, 'a' => 1]]);

        // ستّمئةُ مهمّةٍ خارجَ نطاقه، عناوينُها تسبق عنوانَه أبجديّاً
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'title' => sprintf('AAA-%04d', $i),
                'project_id' => $bulk->id, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $c) DB::table('tasks')->insert($c);

        $mineTask = Task::create(['title' => 'ZZZ — مهمّتي أنا', 'project_id' => $mine->id]);

        $this->actingAs($u);
        $opts = hub_ref_options_scoped('tasks');

        $this->assertArrayHasKey($mineTask->id, $opts,
            'مهمّةُ الموظّفِ سقطت خارجَ الخمسمئة — القصُّ يسبق التضييقَ فتُفرَغ القائمةُ مع الكِبَر');
        $this->assertLessThanOrEqual(5, count($opts),
            'القائمةُ ما زالت تحمل صفوفاً خارجَ النطاق');
    }

    // ── ③ حرّاسٌ يجب أن تبقى خضراء ──────────────────────────────────────

    /** **لا يُفرَّغ مُنتقي الموظّفِ فيُمنَع من طلبِ إجازتِه** — علّةُ عدمِ التعميم */
    public function test_an_ordinary_employee_can_still_pick_an_employee_for_leave(): void
    {
        $p = Project::create(['name' => 'مشروعُ الإجازة', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($p, ['leaves' => ['v' => 1, 'a' => 1]]);
        DB::table('employees')->insert(['id' => (string) Str::uuid(), 'name' => 'موظّفٌ في الملفّ',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($u);
        $this->assertSame(0, hub_scope(DB::table('employees')->whereNull('deleted_at'), 'hr')->count(),
            'شرطُ الاختبار: نطاقُ الموظّفِ على وحدةِ الأفراد صفر');
        $this->assertNotEmpty(hub_ref_options_scoped('hr'),
            'مُنتقي الموظّفِ فُرّغ — فلا يستطيع صاحبُه طلبَ إجازتِه هو');
    }

    /** ومُنتقي العميلِ يبقى واسعاً: وكيلُ دعمٍ يفتح تذكرةً لعميلٍ لا يملكه */
    public function test_the_client_picker_stays_broad_for_an_internal_user(): void
    {
        $p = Project::create(['name' => 'مشروعُ العميل', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($p, ['tickets' => ['v' => 1, 'a' => 1]]);
        Client::create(['name' => 'عميلٌ لا يملكه أحد']);

        $this->actingAs($u);
        $this->assertNotEmpty(hub_ref_options_scoped('clients'),
            'مُنتقي العميلِ فُرّغ — فلا تُفتَح تذكرةٌ لعميل');
    }

    /** ورابطٌ محفوظٌ خارجَ النطاق لا يُمحى صامتاً عند التحرير (`$ensure`) */
    public function test_an_existing_out_of_scope_link_is_kept_in_the_list(): void
    {
        $mine = Project::create(['name' => 'مشروعُ الرابط', 'status' => 'قيد التنفيذ']);
        $theirs = Project::create(['name' => 'مشروعُ الرابطِ البعيد', 'status' => 'قيد التنفيذ']);
        $u = $this->scopedTo($mine, ['tasks' => ['v' => 1], 'updates' => ['v' => 1, 'e' => 1]]);
        $out = Task::create(['title' => 'مهمّةٌ ارتُبط بها قديماً', 'project_id' => $theirs->id]);

        $this->actingAs($u);
        $opts = hub_ref_options_scoped('tasks', $out->id);

        $this->assertArrayHasKey($out->id, $opts,
            'الرابطُ المحفوظُ غاب عن القائمة — فيُرسَل الفراغُ عند الحفظ ويُمحى صامتاً');
    }

    // ── ④ والتضييقُ مُعلَنٌ لا مستنتَج ──────────────────────────────────

    public function test_every_narrowed_ref_is_declared_with_a_reason(): void
    {
        $reg = config('hub_tenancy.ref_scope', []);
        $this->assertNotEmpty($reg, 'سجلُّ تضييقِ المراجع غيرُ مُعلَن');

        foreach ($reg as $ref => $d) {
            $this->assertIsArray($d, "مدخل {$ref} ليس مصفوفةً بحقولها");
            $this->assertArrayHasKey('narrow', $d, "مدخل {$ref} لا يقول أيُضيَّق أم لا");
            $this->assertNotEmpty(trim((string) ($d['why'] ?? '')),
                "مدخل {$ref} بلا سببٍ مكتوب — والسجلُّ بلا تعليلٍ يصير مقبرة");
        }
    }

    /** وكلُّ مرجعٍ مُعلَنٍ للتضييق هو وحدةٌ حقيقيّةٌ لها نطاق */
    public function test_declared_refs_are_real_scopable_modules(): void
    {
        foreach (config('hub_tenancy.ref_scope', []) as $ref => $d) {
            if (empty($d['narrow'])) continue;
            $this->assertNotNull(hub_ref_table($ref), "المرجع {$ref} بلا جدول");
            $this->assertArrayHasKey($ref, config('hub.modules'),
                "المرجع {$ref} ليس وحدةً مسجَّلةً — فلا نطاقَ له يُضيَّق به");
        }
    }
}
