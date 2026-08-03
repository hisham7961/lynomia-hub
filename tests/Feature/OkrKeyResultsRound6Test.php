<?php

namespace Tests\Feature;

use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المرحلة ٦ — OKR حقيقي: كانت الوحدة اسمها OKR وفيها O بلا KR — هدفٌ بلا
 * نتائج قابلة للقياس، و`progress` رقمٌ يدويٌّ منفصل عن الواقع رغم أن محرك
 * المؤشرات (`hub_kpi_value`) مكتوبٌ وجاهز ولا يقرؤه هدفٌ واحد.
 */
class OkrKeyResultsRound6Test extends TestCase
{
    public function test_objective_progress_is_weighted_average_of_key_results(): void
    {
        $this->seedCore();
        $o = Objective::create(['title' => 'نمو الإيراد', 'level' => 'الشركة', 'progress' => 10]);

        // ٥٠٪ بوزن ٣ + ١٠٠٪ بوزن ١ = (١٥٠+١٠٠)/٤ = ٦٢.٥ ≈ ٦٣
        KeyResult::create(['title' => 'عملاء جدد', 'objective_id' => $o->id,
            'start_value' => 0, 'target_value' => 10, 'current_value' => 5, 'weight' => 3]);
        KeyResult::create(['title' => 'إطلاق الباقة', 'objective_id' => $o->id,
            'start_value' => 0, 'target_value' => 1, 'current_value' => 1, 'weight' => 1]);

        $p = hub_okr_progress($o->id);
        $this->assertSame(63, $p['pct'], 'التقدّم متوسطٌ موزون لا رقمٌ يدوي');
        $this->assertSame(2, $p['count']);
        $this->assertSame(2, $p['measured']);
    }

    public function test_descending_target_and_unmeasured_are_handled(): void
    {
        $this->seedCore();
        $o = Objective::create(['title' => 'خفض الأعطال', 'level' => 'الشركة']);

        // اتجاه نازل: من ٢٠ إلى ٥، والحالية ١٠ ⟵ أنجزنا ٦٧٪ من الطريق
        $down = KeyResult::create(['title' => 'أعطال الشهر', 'objective_id' => $o->id,
            'start_value' => 20, 'target_value' => 5, 'current_value' => 10]);
        $this->assertSame(67, $down->pct(), 'الهدف النازل يُحتسب باتجاهه');

        // بلا هدف رقمي: لا نخترع نسبة
        KeyResult::create(['title' => 'نتيجة بلا أرقام', 'objective_id' => $o->id]);
        $p = hub_okr_progress($o->id);
        $this->assertSame(2, $p['count']);
        $this->assertSame(1, $p['measured'], 'غير المقيسة تُعدّ صراحةً ولا تُحسب صفراً');
        $this->assertSame(67, $p['pct']);
    }

    public function test_kpi_linked_result_refreshes_from_engine(): void
    {
        $this->seedCore();
        // مؤشر: عدد المهام — المحرك القائم يحسبه باحترام الصلاحيات والنطاق
        $kpiId = (string) Str::uuid();
        DB::table('kpi_defs')->insert(['id' => $kpiId, 'name' => 'عدد المهام', 'unit' => 'مهمة',
            'formula' => json_encode(['a' => ['agg' => 'count', 'module' => 'tasks'], 'combine' => 'none']),
            'good' => 'up', 'created_at' => now(), 'updated_at' => now()]);

        Task::create(['title' => 'مهمة ١', 'status' => 'جديدة']);
        Task::create(['title' => 'مهمة ٢', 'status' => 'جديدة']);
        Task::create(['title' => 'مهمة ٣', 'status' => 'جديدة']);

        $o = Objective::create(['title' => 'هدف مربوط بمؤشر', 'level' => 'الشركة']);
        $kr = KeyResult::create(['title' => 'إنجاز المهام', 'objective_id' => $o->id,
            'kpi_id' => $kpiId, 'start_value' => 0, 'target_value' => 6, 'current_value' => 0]);

        $p = hub_okr_progress($o->id, true);
        $this->assertEquals(3.0, (float) $kr->fresh()->current_value,
            'القيمة الحالية تُشتق من محرك المؤشرات — كان المحرك جاهزاً بلا قارئ في الأهداف');
        $this->assertSame(50, $p['pct']);
    }

    public function test_krs_module_is_usable_and_shown_on_objective(): void
    {
        $this->seedCore();
        $o = Objective::create(['title' => 'هدف الصفحة', 'level' => 'الشركة']);

        $this->actingAs($this->owner)->get('/m/krs')->assertOk();
        $this->actingAs($this->owner)->post('/m/krs', [
            'title' => 'نتيجة من النموذج', 'objectiveId' => $o->id,
            'startValue' => 0, 'targetValue' => 100, 'currentValue' => 25,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/m/okrs/' . $o->id)
            ->assertOk()->assertSee('النتائج الرئيسية')->assertSee('نتيجة من النموذج')->assertSee('25٪');
    }
}
