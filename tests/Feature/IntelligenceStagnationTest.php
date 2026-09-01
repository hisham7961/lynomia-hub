<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — المرحلة ٢: كاشفُ ركودِ المشاريع.
 *
 * مشروعٌ نشطٌ (غيرُ مغلقٍ ولا متوقّف) بلا حِراكٍ فعليّ (مهامٌ أو تدقيق) منذ مدّة =
 * إشارةُ ركود، تُشتقّ من آخرِ أثرٍ لا من محرّكِ صحّةٍ ثانٍ، وتُحَلّ بأوّلِ نشاط.
 */
class IntelligenceStagnationTest extends TestCase
{
    /** يُدرَج خاماً بتاريخٍ قديمٍ وبلا تدقيقٍ (Eloquent يكتب تدقيقاً بتاريخ اليوم) */
    private function staleProject(string $status = 'قيد التنفيذ', int $daysAgo = 40): string
    {
        $id = (string) Str::uuid();
        DB::table('projects')->insert([
            'id' => $id, 'name' => 'مشروعٌ راكد', 'status' => $status,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $id;
    }

    public function test_stalled_active_project_is_flagged_and_resolves_on_activity(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $pid = $this->staleProject();

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('proj.stalled:' . $pid, $keys, 'المشروعُ الراكدُ لم يُرصَد');

        // نشاطٌ حديث (مهمّةٌ محدَّثةُ اليوم) → يزول الركود تلقائياً
        DB::table('tasks')->insert(['id' => (string) Str::uuid(), 'title' => 'حِراك',
            'project_id' => $pid, 'status' => 'قيد التنفيذ', 'created_at' => now(), 'updated_at' => now()]);
        $keys2 = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.stalled:' . $pid, $keys2, 'الركودُ لم يُحَلّ رغم النشاط');
    }

    public function test_paused_and_closed_projects_are_not_flagged_as_stalled(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $paused = $this->staleProject('متوقف');
        $closed = $this->staleProject('مكتمل');

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.stalled:' . $paused, $keys, 'المتوقّفُ عمداً ليس راكداً');
        $this->assertNotContains('proj.stalled:' . $closed, $keys, 'المغلقُ ليس راكداً');
    }

    public function test_recently_active_project_is_not_flagged(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // مشروعٌ حُدِّث اليوم (٣ أيام) — دون العتبة (٧)
        $id = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $id, 'name' => 'حيّ', 'status' => 'قيد التنفيذ',
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.stalled:' . $id, $keys);
    }

    public function test_stalled_signal_respects_client_isolation(): void
    {
        $this->seedCore();
        // مشروعٌ راكدٌ لعميلٍ «ب»؛ مستخدمٌ محصورٌ بعميلٍ «أ» لا يراه
        $a = \App\Models\Client::create(['name_ar' => 'أ', 'name' => 'A']);
        $b = \App\Models\Client::create(['name_ar' => 'ب', 'name' => 'B']);
        $pid = (string) Str::uuid();
        DB::table('projects')->insert(['id' => $pid, 'name' => 'راكدُ ب', 'status' => 'قيد التنفيذ',
            'client_id' => $b->id, 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);

        $role = \App\Models\Role::create(['name' => 'مراقبٌ عامّ', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => ['projects' => ['v' => 1]]]);
        $scoped = \App\Models\User::create(['name' => 'محصور', 'email' => 'sc2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'clients' => [$a->id],
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($scoped);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('proj.stalled:' . $pid, $keys, 'تسريبُ عزل: راكدُ عميلٍ آخر ظهر');
    }
}
