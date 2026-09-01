<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — المرحلة ٥: إشارةُ الحواجب المبلَّغة.
 *
 * لا كيانَ حاجبٍ جديد: تُقرأ من `work_updates.problems` (نفسُ ما يعدّه `teamCalc`)،
 * مجمَّعةً بالمشروع، منطَّقةً بـhub_scope، بعمرِ أقدمِ بلاغ، وتُحَلّ حين تنقطع البلاغات.
 */
class IntelligenceBlockersTest extends TestCase
{
    public function test_reported_blocker_surfaces_and_clears(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروعٌ محجوب', 'status' => 'قيد التنفيذ']);

        WorkUpdate::create(['project_id' => $p->id, 'done' => 'أنجزتُ جزءاً', 'problems' => 'عائقٌ فنيّ يمنع التكامل', 'hours' => 2]);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('proj.blockers:' . $p->id, $keys, 'الحاجبُ المبلَّغُ لم يُرصَد');
    }

    public function test_report_without_problems_is_not_a_blocker(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروعٌ سليم', 'status' => 'قيد التنفيذ']);
        WorkUpdate::create(['project_id' => $p->id, 'done' => 'يومٌ نظيف', 'problems' => '', 'hours' => 3]);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.blockers:' . $p->id, $keys, 'تقريرٌ بلا مشكلاتٍ عُدّ حاجباً');
    }

    public function test_blocker_signal_respects_client_isolation(): void
    {
        $this->seedCore();
        $a = Client::create(['name_ar' => 'أ', 'name' => 'A']);
        $b = Client::create(['name_ar' => 'ب', 'name' => 'B']);

        $pb = Project::create(['name' => 'محجوبُ ب', 'status' => 'قيد التنفيذ', 'client_id' => $b->id]);
        // بلاغُ حاجبٍ خام (بلا created_by مقيِّد) لعميلٍ «ب»
        DB::table('work_updates')->insert(['id' => (string) Str::uuid(), 'project_id' => $pb->id,
            'done' => 'x', 'problems' => 'عائق', 'work_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $role = Role::create(['name' => 'مراقبٌ عامّ', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => ['projects' => ['v' => 1]]]);
        $scoped = User::create(['name' => 'محصور', 'email' => 'blk@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'clients' => [$a->id],
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($scoped);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.blockers:' . $pb->id, $keys, 'تسريبُ عزل: حاجبُ عميلٍ آخر ظهر');
    }
}
