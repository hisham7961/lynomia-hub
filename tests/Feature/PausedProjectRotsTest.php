<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **المتوقّفُ المرتَّبُ ليس كالمتوقّفِ المتعفّن** (محاكاةُ الشهر · قرارُ المالك).
 *
 * الجولةُ الأولى (F12) أصلحت عيباً حقيقيّاً: مشروعٌ «متوقف» كان يخرج «94 · سليم»
 * لأنّ التوقّفَ لا يولّد تأخّراً ولا تذاكر — فالعواملُ كلُّها هادئةٌ **هدوءَ
 * الموتى**. فوُضع سقفٌ: درجةُ المتوقّفِ ٦٠ فأقلّ.
 *
 * لكنّ السقفَ الواحدَ سوّى بين حالتين لا تستويان:
 *
 *   · مشروعٌ أُوقف **الأسبوعَ الماضي** بقرارٍ واعٍ، مهامُّه مغلقةٌ ومخاطرُه
 *     مُصفّاة — هذا **مرتَّبٌ بانتظار قرار**، و٦٠ وصفٌ عادلٌ له.
 *   · ومشروعٌ أُوقف **قبل ثمانيةِ أشهر** وتُرك: أربعُ مهامَّ فاتت مواعيدُها وهي
 *     مفتوحة، وخطرٌ حرجٌ لم يُغلق، وتذاكرُ عميلٍ معلّقة. هذا **يتعفّن** —
 *     و٦٠ نفسُها تقول عنه ما تقوله عن الأوّل.
 *
 * ولأنّ اللوحةَ تُرتّب بالدرجة، فالمتعفّنُ يقف في الصفِّ نفسِه مع المرتَّبِ
 * فلا يُنادى أحدُهما قبل الآخر. **والسقفُ يهبط الآن بمدّةِ التوقّفِ وبما
 * تراكم من ديونٍ مفتوحة**، ويقول الملاحظُ سببَه لا رقمَه وحدَه.
 */
class PausedProjectRotsTest extends TestCase
{
    /** مشروعٌ متوقّفٌ منذ `$months` — بختمِ أثرِ التدقيقِ الحقيقيّ لا بعمودٍ مخترَع */
    private function pausedProject(string $name, int $months): Project
    {
        $p = Project::create(['name' => $name, 'status' => 'نشط']);
        $p->update(['status' => 'متوقف']);

        if ($months > 0) {
            $id = DB::table('audits')->where('module', 'projects')->where('record_id', (string) $p->id)
                ->orderByDesc('id')->value('id');
            $this->assertNotNull($id, 'لا أثرَ تدقيقٍ لتغيّرِ الحالة — الاختبارُ يقيس فراغاً');
            DB::table('audits')->where('id', $id)->update(['created_at' => now()->subMonths($months)]);
        }

        return $p;
    }

    public function test_a_freshly_paused_tidy_project_keeps_the_sixty_cap(): void
    {
        $this->seedCore();
        $p = $this->pausedProject('مشروعٌ أُوقف هذا الأسبوع', 0);

        $h = hub_project_health($p->id, true);
        $this->assertSame(60, $h['score'], 'المرتَّبُ حديثُ التوقّفِ يبقى عند السقفِ نفسِه — لا عقوبةَ بلا سبب');
        $this->assertSame('متوقف — بانتظار قرار', $h['label']);
    }

    public function test_a_long_neglected_paused_project_scores_below_a_tidy_one(): void
    {
        $this->seedCore();
        $tidy = $this->pausedProject('مرتَّبٌ بانتظار قرار', 0);
        $rot  = $this->pausedProject('متروكٌ منذ ثمانيةِ أشهر', 8);

        // ديونٌ مفتوحةٌ تراكمت أثناءَ التوقّف
        for ($i = 0; $i < 4; $i++) {
            Task::create(['title' => "مهمّةٌ فاتت {$i}", 'status' => 'جديدة',
                'project_id' => $rot->id, 'due' => now()->subDays(200 + $i)->toDateString()]);
        }
        Issue::create(['title' => 'خطرٌ حرجٌ لم يُغلق', 'kind' => 'خطر', 'status' => 'مفتوحة',
            'severity' => 'حرجة', 'project_id' => $rot->id]);

        $hT = hub_project_health($tidy->id, true);
        $hR = hub_project_health($rot->id, true);

        $this->assertLessThan($hT['score'], $hR['score'],
            'المتعفّنُ والمرتَّبُ خرجا بالدرجةِ نفسِها — فاللوحةُ ترتّبهما سواءً ولا يُنادى أحدُهما أوّلاً');
        $this->assertSame('bad', $hR['tone'], 'ما تُرك ثمانيةَ أشهرٍ بديونٍ مفتوحةٍ ليس تحذيراً أصفر');
        $this->assertStringContainsString('متوقف', (string) $hR['label']);
        $this->assertNotSame('متوقف — بانتظار قرار', $hR['label'],
            'لقبُ «بانتظار قرار» يصف المرتَّبَ — والمتروكُ ليس بانتظارِ شيء');
    }

    /**
     * **واللقبُ يسمّي سببَه**: مشروعٌ أُوقف اليومَ وعليه ديونٌ ليس «متروكاً» —
     * «متروك» تصف طولَ السكون، وهذا سكونُه ساعات. هو **متوقّفٌ بديونٍ مفتوحة**،
     * وذاك نداءٌ آخرُ لفعلٍ آخر.
     */
    public function test_a_freshly_paused_project_with_debts_is_not_called_neglected(): void
    {
        $this->seedCore();
        $p = $this->pausedProject('أُوقف اليومَ وعليه ديون', 0);
        for ($i = 0; $i < 5; $i++) {
            Task::create(['title' => "مهمّةٌ فاتت {$i}", 'status' => 'جديدة',
                'project_id' => $p->id, 'due' => now()->subDays(10 + $i)->toDateString()]);
        }

        $h = hub_project_health($p->id, true);
        $this->assertLessThan(60, $h['score'], 'الديونُ لم تخفض السقفَ أصلاً');
        $this->assertSame('متوقف — بديونٍ مفتوحة', $h['label'],
            'وُصف بالمتروكِ وسكونُه ساعات — اللقبُ يصف المقدارَ لا السبب');
    }

    /** ومدّةُ التوقّفِ وحدَها تكفي: مشروعان خاليان تماماً، أحدُهما متروكٌ منذ سنة */
    public function test_pause_duration_alone_lowers_the_ceiling(): void
    {
        $this->seedCore();
        $fresh = $this->pausedProject('أُوقف اليوم', 0);
        $old   = $this->pausedProject('أُوقف قبل سنة', 12);

        $this->assertLessThan(hub_project_health($fresh->id, true)['score'],
            hub_project_health($old->id, true)['score'],
            'سنةٌ من التوقّفِ لا تُغيّر شيئاً في الدرجة — فالسكونُ الطويلُ يُقرأ كالسكونِ القصير');
    }

    /**
     * **وبعينِ قارئٍ حُجبت عنه الميزانيّة** — `hub_project_health_for` يُسقط عاملَها
     * ويُعيد التطبيع، وكان يستعيد حكمَ التوقّفِ **بمطابقةِ نصِّ اللقب**. ولمّا صار
     * للمتوقّفِ ثلاثةُ ألقاب، مطابقةُ واحدٍ تُسقط السقفَ عن الآخرَين — أي تُعيد
     * للمتعفّنِ درجتَه الكاملة أمام من لا يرى الميزانيّة وحدَه.
     */
    public function test_the_ceiling_survives_a_reader_who_cannot_see_the_budget(): void
    {
        $this->seedCore();
        $rot = $this->pausedProject('متروكٌ منذ سنة', 12);

        $role = \App\Models\Role::create(['name' => 'دورٌ بلا ميزانية', 'scope' => 'all',
            'matrix' => ['projects' => ['v' => 1]],
            'fields' => ['projects' => ['budget' => 'hide']]]);
        $u = \App\Models\User::create(['name' => 'قارئٌ بلا ميزانية', 'email' => 'nobudget@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        $this->assertSame('hide', hub_field_mode($u, 'projects', 'budget'),
            'الحقلُ غيرُ محجوبٍ أصلاً — الاختبارُ يقيس مساراً لا يُسلَك');

        $h = hub_project_health_for($u, $rot->id, true);
        $this->assertLessThanOrEqual(30, $h['score'],
            'السقفُ المتعفّنُ ضاع حين أُسقط عاملُ الميزانيّة — القارئُ يرى المتروكَ سليماً');
        $this->assertSame('bad', $h['tone']);
    }

    /** **والسببُ يُقال لا يُختصر رقماً**: بطاقةُ الحالةِ التشغيليّة تسمّي المدّةَ والديون */
    public function test_the_operational_factor_states_its_reason(): void
    {
        $this->seedCore();
        $p = $this->pausedProject('متروكٌ بديون', 7);
        Task::create(['title' => 'مهمّةٌ فاتت', 'status' => 'جديدة',
            'project_id' => $p->id, 'due' => now()->subDays(150)->toDateString()]);

        $h = hub_project_health($p->id, true);
        $op = collect($h['factors'])->firstWhere('k', 'الحالة التشغيلية');
        $this->assertNotNull($op, 'لا بطاقةَ للحالةِ التشغيليّة أصلاً');
        $this->assertStringContainsString('7 أشهر', (string) $op['note'], 'الملاحظُ لا يقول كم طال التوقّف');
        $this->assertStringContainsString('مهمّة', (string) $op['note'], 'ولا يقول ما الذي تراكم');
    }
}
