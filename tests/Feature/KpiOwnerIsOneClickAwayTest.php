<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * **مالكُ المؤشّر: مراجعةٌ بنقرة، لا تأليفٌ من فراغ** (قرارُ المالك · بعد v2.539.0).
 *
 * قلتُ إنّ إسنادَ مالكٍ «قرارُ إنسانٍ» فتركتُ إحدى وخمسين قائمةً منسدلةً فارغة.
 * وذلك ليس احتراماً لقرارِ الإنسان بل **تركٌ له بلا عون**: من يفتح الشاشةَ
 * يجد واحداً وخمسين حقلاً فارغاً وثلاثين اسماً في كلٍّ — فيغلقها، ويبقى
 * «خارج الهدف» بلا من يُسأل عنه، وهو ما أرادت البطاقةُ منعَه أصلاً.
 *
 * فصارت الشاشةُ تعرض **المرشَّحَ ودليلَه وزرَّ اعتماد**. والقرارُ باقٍ للإنسان —
 * لكنّه صار نظرةً ونقرة.
 *
 * **وما لا يُساوَم عليه:** لا يُعتمَد مرشّحٌ تلقائيّاً، ولا يُقترَح من لا يبلغ
 * ما يُسأل عنه، ولا يُعتمَد «الكلُّ» إلّا على ما ثقتُه **قويّة** بدليلٍ مقيس.
 */
class KpiOwnerIsOneClickAwayTest extends TestCase
{
    /** موظّفٌ له عملٌ حقيقيٌّ في وحدةٍ — فيصير مرشّحاً بدليل */
    private function busyInTasks(string $email, int $n): User
    {
        $role = Role::create(['name' => 'دورُ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'صاحبُ ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'internal',
            'password_changed_at' => now()]);

        for ($i = 0; $i < $n; $i++) {
            Task::create(['title' => "عملُ {$email} {$i}", 'status' => 'جديدة', 'assignee_id' => (string) $u->id]);
        }

        return $u;
    }

    private function kpiOnTasks(): KpiDef
    {
        return KpiDef::create(['name' => 'مؤشّرُ المهام', 'good' => 'up', 'sort' => 1, 'unit' => 'مهمة',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null, 'st' => ''],
                          'combine' => 'none']]);
    }

    public function test_the_screen_proposes_an_owner_with_the_evidence_behind_it(): void
    {
        $this->seedCore();
        $busy = $this->busyInTasks('busy@test.local', 6);
        $this->kpiOnTasks();

        $this->actingAs($this->owner)->get(route('kpis.index'))->assertOk()
            ->assertSee('المرشَّح', false)
            ->assertSee($busy->name, false)
            ->assertSee('6 سجلاً مُسنَداً إليه', false);
    }

    /** **ولا يُعتمَد من تلقاء نفسه** — العرضُ اقتراحٌ، والكتابةُ لا تقع إلّا بطلب */
    public function test_merely_looking_at_the_screen_assigns_nobody(): void
    {
        $this->seedCore();
        $this->busyInTasks('busy@test.local', 6);
        $k = $this->kpiOnTasks();

        $this->actingAs($this->owner)->get(route('kpis.index'))->assertOk();

        $this->assertNull($k->fresh()->owner_id, 'أُسنِد مالكٌ بمجرّد فتحِ الشاشة');
    }

    /** والنقرةُ تُسنِد — وتُختَم في سجلّ التدقيق كأيّ قرارٍ إداريّ */
    public function test_one_click_assigns_the_proposed_owner_and_is_audited(): void
    {
        $this->seedCore();
        $busy = $this->busyInTasks('busy@test.local', 6);
        $k = $this->kpiOnTasks();

        $this->actingAs($this->owner)->post(route('kpis.adopt', $k->id))->assertRedirect();

        $this->assertSame((string) $busy->id, (string) $k->fresh()->owner_id, 'النقرةُ لم تُسنِد');
        $this->assertDatabaseHas('audits', ['module' => null, 'record_id' => (string) $k->id,
            'action' => 'إسناد مالك مؤشر']);
    }

    /** ولا يُسنَد من لا يبلغ الوحدةَ — ولو طُلب صراحةً بمعرّفه */
    public function test_a_blind_candidate_is_refused_even_when_named(): void
    {
        $this->seedCore();
        $blindRole = Role::create(['name' => 'دورٌ أعمى', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 0]]]);
        $blind = User::create(['name' => 'من لا يرى', 'email' => 'blind@test.local',
            'password' => 'Secret!2026x', 'role_id' => $blindRole->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);
        $k = $this->kpiOnTasks();

        $this->actingAs($this->owner)
            ->post(route('kpis.adopt', $k->id), ['owner_id' => (string) $blind->id])
            ->assertStatus(422);

        $this->assertNull($k->fresh()->owner_id, 'أُسنِد المؤشّرُ لمن لا يفتح وحدتَه');
    }

    /** و«اعتمد المؤكّد» لا يمسّ إلّا ما ثقتُه قويّة — ولا يدهس مالكاً قائماً */
    public function test_bulk_adopt_touches_only_strong_proposals_and_spares_the_assigned(): void
    {
        $this->seedCore();
        $busy = $this->busyInTasks('busy@test.local', 6);
        Artisan::call('hub:kpis-starter');

        // مؤشّرٌ له مالكٌ سلفاً — لا يُمَسّ
        $already = KpiDef::orderBy('sort')->first();
        $already->update(['owner_id' => (string) $this->owner->id]);

        $this->actingAs($this->owner)->post(route('kpis.adoptAll'))->assertRedirect();

        $this->assertSame((string) $this->owner->id, (string) $already->fresh()->owner_id,
            'الاعتمادُ الجماعيُّ داس مالكاً مُسنَداً');

        $tasksKpi = KpiDef::whereRaw("formula like '%\"module\":\"tasks\"%'")->first();
        if ($tasksKpi) {
            $this->assertSame((string) $busy->id, (string) $tasksKpi->fresh()->owner_id,
                'لم يُعتمَد المرشَّحُ القويُّ لوحدةٍ فيها عملٌ حقيقيّ');
        }

        // وما لا دليلَ له يبقى فارغاً — لا يُملأ بأوّلِ اسم
        $noEvidence = KpiDef::whereRaw("formula like '%\"module\":\"suppliers\"%'")->first();
        if ($noEvidence) {
            $this->assertNull($noEvidence->fresh()->owner_id,
                'أُسنِد مالكٌ لمؤشّرٍ لا دليلَ على صاحبه');
        }
    }

    /** والبابُ لغيرِ المخوَّل مردود: إسنادُ المساءلةِ سلطةٌ لا عرض */
    public function test_a_plain_employee_cannot_assign_owners(): void
    {
        $this->seedCore();
        $k = $this->kpiOnTasks();

        $this->actingAs($this->employee)->post(route('kpis.adopt', $k->id))->assertForbidden();
        $this->assertNull($k->fresh()->owner_id);
    }
}
