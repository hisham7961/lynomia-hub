<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * طبقةُ الذكاء — تدهورُ الهامش زمنيّاً (v2.398): آخرُ بندٍ «مؤجَّلٍ لغياب بياناته».
 *
 * السلسلةُ الزمنية تُبنى بلقطةٍ يوميّةٍ من `hub:automation` في `metric_points`
 * القائم (لا مخزنَ جديد)، والإشارةُ تقارن أوّلَ نقطةٍ في ٣٠ يوماً بآخرها.
 * لا تُختلق إشارةٌ من نقطةٍ واحدة، ولا تصل الهوامشُ (كلفةٌ داخلية) لمعزولٍ أو لمن
 * لا يملك رؤيةَ المالية.
 */
class IntelligenceMarginDeclineTest extends TestCase
{
    private function point(string $pid, float $margin, int $daysAgo): void
    {
        hub_metric_put('projects', $pid, 'pl_margin', $margin, now()->subDays($daysAgo)->startOfDay(), 'auto');
    }

    public function test_margin_decline_signal_from_two_snapshots(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $bad    = Project::create(['name' => 'هامشٌ يهبط', 'status' => 'قيد التنفيذ']);
        $worse  = Project::create(['name' => 'انقلب خسارة', 'status' => 'قيد التنفيذ']);
        $stable = Project::create(['name' => 'ثابت', 'status' => 'قيد التنفيذ']);
        $single = Project::create(['name' => 'نقطةٌ واحدة', 'status' => 'قيد التنفيذ']);
        $old    = Project::create(['name' => 'خارجَ النافذة', 'status' => 'قيد التنفيذ']);

        $this->point($bad->id, 40.0, 10);  $this->point($bad->id, 28.0, 0);      // −١٢ نقطة → مهم
        $this->point($worse->id, 5.0, 7);  $this->point($worse->id, -3.0, 0);    // انقلب إلى خسارة → حرج
        $this->point($stable->id, 30.0, 10); $this->point($stable->id, 27.0, 0); // −٣ فقط
        $this->point($single->id, 50.0, 0);                                      // نقطةٌ واحدة
        $this->point($old->id, 60.0, 45);  $this->point($old->id, 20.0, 0);      // الأولى خارجَ ٣٠ يوماً

        $items = collect(hub_recommendations(true)['items'])->filter(fn ($i) => str_starts_with((string) $i['key'], 'margin.decline:'))->keyBy('key');
        $this->assertArrayHasKey('margin.decline:' . $bad->id, $items->all(), 'الهبوطُ الجوهريّ لم يُرصَد');
        $this->assertSame('مهم', $items['margin.decline:' . $bad->id]['sev']);
        $this->assertArrayHasKey('margin.decline:' . $worse->id, $items->all(), 'انقلابُ الهامش إلى خسارةٍ لم يُرصَد');
        $this->assertSame('حرج', $items['margin.decline:' . $worse->id]['sev']);
        $this->assertArrayNotHasKey('margin.decline:' . $stable->id, $items->all(), 'رُصد هبوطٌ طفيفٌ دون العتبة');
        $this->assertArrayNotHasKey('margin.decline:' . $single->id, $items->all(), 'اختُلقت إشارةٌ من نقطةٍ واحدة');
        $this->assertArrayNotHasKey('margin.decline:' . $old->id, $items->all(), 'قورنت نقطةٌ خارجَ نافذة الثلاثين يوماً');
    }

    public function test_snapshot_step_writes_one_point_per_project_per_day(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $p = Project::create(['name' => 'مفوتر', 'status' => 'قيد التنفيذ']);
        FinDocument::create(['doc_no' => 'M1', 'kind' => 'فاتورة مبيعات', 'state' => 'مدفوعة',
            'project_id' => $p->id, 'total' => 1000, 'paid' => 1000, 'date' => now()->toDateString()]);
        $noRev  = Project::create(['name' => 'بلا إيراد', 'status' => 'قيد التنفيذ']);
        $closed = Project::create(['name' => 'مغلق', 'status' => 'مغلق']);

        $this->artisan('hub:automation')->assertExitCode(0);
        $this->artisan('hub:automation')->assertExitCode(0);   // إعادةُ التشغيل في اليوم نفسه تُحدّث لا تُكرّر

        $pts = DB::table('metric_points')->where('module', 'projects')->where('metric', 'pl_margin')->get();
        $this->assertCount(1, $pts->where('record_id', $p->id), 'لقطةٌ واحدةٌ لليوم لا اثنتان');
        $this->assertEquals(100.0, (float) $pts->where('record_id', $p->id)->first()->value);
        $this->assertCount(0, $pts->where('record_id', $noRev->id), 'مشروعٌ بلا إيرادٍ هامشُه null — لا يُكتب صفرٌ كاذب');
        $this->assertCount(0, $pts->where('record_id', $closed->id), 'المغلقُ لا يُلتقَط');
    }

    public function test_margin_decline_hidden_from_scoped_and_no_fin_users(): void
    {
        $this->seedCore();
        $mine  = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ', 'manager_id' => $this->employee->id]);
        $other = Project::create(['name' => 'مشروعُ غيري', 'status' => 'قيد التنفيذ']);
        foreach ([$mine, $other] as $p) { $this->point($p->id, 40.0, 10); $this->point($p->id, 20.0, 0); }

        // قائدٌ محصورٌ بمشاريعه ومعه رؤيةُ المالية: يرى مشروعَه فقط
        $lead = User::create(['name' => 'قائد', 'email' => 'lead@test.local', 'password' => bcrypt('x'),
            'role_id' => Role::create(['name' => 'قائد', 'scope' => 'proj', 'flags' => ['monitor' => 1],
                'matrix' => Role::first()->matrix])->id]);
        $this->actingAs($lead);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('margin.decline:' . $other->id, $keys, 'تسرّب هامشُ مشروعٍ خارجَ نطاق القائد');

        $mine->update(['manager_id' => $lead->id]);
        \Illuminate\Support\Facades\Cache::flush();
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('margin.decline:' . $mine->id, $keys, 'القائدُ لا يرى تدهورَ هامشِ مشروعه');

        // مستخدمٌ بلا رؤيةٍ للمالية: الهامشُ كلفةٌ داخلية — لا إشارةَ إطلاقاً
        $this->actingAs($this->viewer);
        $matrix = Role::find($this->viewer->role_id)->matrix;
        unset($matrix['fin']);
        Role::where('id', $this->viewer->role_id)->update(['matrix' => json_encode($matrix)]);
        \Illuminate\Support\Facades\Cache::flush();
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertEmpty(array_filter($keys, fn ($k) => str_starts_with($k, 'margin.decline:')), 'وصل الهامشُ لمن لا يرى المالية');
    }
}
