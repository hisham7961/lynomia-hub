<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Ownership;
use Tests\TestCase;

/**
 * **المالكُ يُقترَح من الأدلّة، ولا يُخترَع** (قرارُ المالك · بعد v2.539.0).
 *
 * قيل في تقريرِ v2.539.0 إنّ إسنادَ مالكٍ لكلِّ مؤشّرٍ «قرارُ إنسانٍ لا يُتّخَذ
 * آليّاً» — وهو نصفُ الحقيقة. الحقيقةُ الأخرى أنّ **النظامَ يطرح السؤالَ ولا
 * يعين على جوابه**: إحدى وخمسون قائمةً منسدلةً فارغة ليست قراراً، هي عبء.
 * فتُترَك كلُّها، ويبقى «خارج الهدف» بلا من يُسأل عنه — وهو ما أرادت بطاقةُ
 * المالكِ منعَه أصلاً.
 *
 * والمخرجُ ليس اختراعَ مالك. النظامُ **يعرف من يعمل في كلِّ وحدة**: أثرُ
 * التدقيق يسجّل كلَّ فعل، وأعمدةُ الإسناد تقول لمن العمل. فيُقترَح الأكثرُ
 * فعلاً **ومعه دليلُه**، ويبقى القرارُ نقرةَ مراجعةٍ لا تأليفاً من فراغ.
 *
 * **وثلاثةُ حرّاسٍ تحفظ صدقَ الاقتراح:**
 *  ١) لا يُقترَح من **لا يبلغ** الوحدةَ — وإلّا أُعيد عيبُ M-A1 حرفيّاً:
 *     كُلِّف بالمساءلةِ من لا يفتح الباب.
 *  ٢) لا مرشّحَ من البيانات ⇒ **يُقال ذلك**، ولا يُملأ الحقلُ بأوّلِ اسم.
 *  ٣) الترتيبُ حتميّ (العددُ ثمّ `id`) فلا يختلف الاقتراحُ بين محرّكين.
 */
class OwnershipIsSuggestedFromEvidenceTest extends TestCase
{
    private function worker(string $email, array $matrix, string $name): User
    {
        $role = Role::create(['name' => 'دورُ ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'internal',
            'password_changed_at' => now()]);
    }

    /** إسنادُ مهامٍّ حقيقيّة — الدليلُ الذي يُبنى عليه الاقتراح */
    private function assign(User $u, int $n, string $prefix): void
    {
        for ($i = 0; $i < $n; $i++) {
            Task::create(['title' => "{$prefix} {$i}", 'status' => 'جديدة', 'assignee_id' => (string) $u->id]);
        }
    }

    public function test_the_busiest_hand_in_a_module_is_proposed_with_its_evidence(): void
    {
        $this->seedCore();
        $full = ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1]];

        $busy = $this->worker('busy@test.local', $full, 'مَن يعمل كثيراً');
        $few  = $this->worker('few@test.local', $full, 'مَن يعمل قليلاً');
        $this->assign($busy, 7, 'مهمّةُ الكثير');
        $this->assign($few, 2, 'مهمّةُ القليل');

        $s = Ownership::suggest('tasks');

        $this->assertSame((string) $busy->id, $s['user_id'], 'لم يُقترَح صاحبُ أكثرِ العمل');
        $this->assertSame('strong', $s['confidence'], 'الفارقُ ضِعفٌ ونصف — والثقةُ ليست قويّة');
        $this->assertStringContainsString('7', $s['why'], 'الدليلُ لا يذكر عددَ ما يحمله');
    }

    /**
     * **ولا يُقترَح من لا يبلغ الوحدة** — درسُ M-A1 حرفيّاً: من كُلِّف بعملٍ
     * لا يفتح بابَه يصمت، والصمتُ يُقرأ انتظاراً.
     */
    public function test_someone_who_cannot_reach_the_module_is_never_proposed(): void
    {
        $this->seedCore();

        $blind = $this->worker('blind@test.local', ['tasks' => ['v' => 0]], 'من لا يرى المهام');
        $this->assign($blind, 9, 'مهمّةُ الأعمى');

        $this->assertFalse(hub_can($blind, 'tasks', 'v'), 'هو يرى الوحدةَ أصلاً — الاختبارُ يقيس مساراً لا يُسلَك');

        $s = Ownership::suggest('tasks');

        $this->assertNotSame((string) $blind->id, $s['user_id'] ?? null,
            'اقتُرح مالكاً من لا يفتح الوحدةَ — وهو عيبُ M-A1 معاداً');
    }

    /** ولا مرشّحَ ⇒ **يُقال ذلك**، ولا يُملأ الحقلُ بأوّلِ اسمٍ في الدليل */
    public function test_no_evidence_yields_no_candidate_and_says_so(): void
    {
        $this->seedCore();
        $this->worker('idle@test.local', ['suppliers' => ['v' => 1, 'a' => 1]], 'موظّفٌ بلا أثر');

        $s = Ownership::suggest('suppliers');

        $this->assertNull($s['user_id'], 'اختُرع مالكٌ لوحدةٍ لا عملَ فيها');
        $this->assertSame('none', $s['confidence']);
        $this->assertNotSame('', trim((string) $s['why']), 'ولا قيل لماذا لا مرشّح');
    }

    /** واقتراحُ المؤشّرِ يُشتقّ من وحدةِ معادلته — لا من اسمِه ولا من ترتيبه */
    public function test_a_kpi_inherits_the_proposal_of_the_module_it_measures(): void
    {
        $this->seedCore();
        $busy = $this->worker('kpi@test.local', ['tasks' => ['v' => 1, 'a' => 1]], 'صاحبُ المهام');
        $this->assign($busy, 5, 'مهمّةُ المؤشّر');

        $s = Ownership::suggestForKpi(['a' => ['agg' => 'count', 'module' => 'tasks', 'st' => ''],
                                       'combine' => 'none']);

        $this->assertSame((string) $busy->id, $s['user_id'], 'المؤشّرُ لم يرث اقتراحَ وحدته');
    }

    /** والترتيبُ حتميّ: تعادلُ العددِ يُفصَل بـ`id` لا بقرعةِ المحرّك */
    public function test_a_tie_is_broken_deterministically_not_by_the_engine(): void
    {
        $this->seedCore();
        $a = $this->worker('tie-a@test.local', ['tasks' => ['v' => 1]], 'متعادلٌ أ');
        $b = $this->worker('tie-b@test.local', ['tasks' => ['v' => 1]], 'متعادلٌ ب');
        $this->assign($a, 4, 'أ');
        $this->assign($b, 4, 'ب');

        $first = Ownership::suggest('tasks')['user_id'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($first, Ownership::suggest('tasks')['user_id'], 'الاقتراحُ يتأرجح عند التعادل');
        }
        $this->assertSame('weak', Ownership::suggest('tasks')['confidence'],
            'تعادلٌ تامٌّ وتُعلَن ثقةٌ قويّة');
    }
}
