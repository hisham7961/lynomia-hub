<?php

namespace Tests\Feature;

use App\Models\SessionLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تدقيقٌ عميق — مركزُ النشاط: التجميعُ المسبق (بديلُ N+1) يُنتج الخرجَ نفسَه.
 *
 * الفهرسُ كان يُصدر ~٥ استعلاماتٍ لكل مستخدمٍ داخل `map()` (~5N). استُبدل بثلاثةِ
 * استعلاماتٍ مُجمَّعةٍ تُسقَط على المستخدمين. هذا الاختبارُ يثبت أنّ القيمَ
 * (أول/آخر/زيارات/أفعال/متّصل) مطابقةٌ عبر عدّةِ مستخدمين ببياناتٍ مختلطة.
 */
class ActivityIndexAggregationTest extends TestCase
{
    public function test_aggregated_index_matches_per_user_values(): void
    {
        $this->seedCore();

        // مستخدمٌ ثانٍ كي يظهر أثرُ التجميع عبر أكثر من صف
        $u2 = User::create([
            'name' => 'ثانٍ', 'email' => 't2@x.co', 'password' => bcrypt('x'),
            'role_id' => $this->employee->role_id,
        ]);

        $today = now()->startOfDay();

        // الموظف: زيارتان اليوم + فعلان + نبضةُ جلسةٍ حيّة (متّصل)
        DB::table('page_visits')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->employee->id, 'path' => '/m/tasks', 'at' => $today->copy()->addHours(9)],
            ['id' => (string) Str::uuid(), 'user_id' => $this->employee->id, 'path' => '/m/clients', 'at' => $today->copy()->addHours(11)],
        ]);
        $this->actingAs($this->employee);
        Task::create(['title' => 'أ']);
        Task::create(['title' => 'ب']);
        SessionLog::create([
            'user_id' => $this->employee->id, 'device' => 'ج', 'ip' => '1.1.1.1',
            'user_agent' => 'ua', 'started_at' => now()->subHours(3), 'last_seen_at' => now()->subMinutes(2),
        ]);

        // الثاني: زيارةٌ واحدةٌ اليوم، جلسةٌ قديمةٌ (غيرُ متّصل)، بلا أفعال
        DB::table('page_visits')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $u2->id, 'path' => '/m/fin', 'at' => $today->copy()->addHours(10)],
        ]);
        SessionLog::create([
            'user_id' => $u2->id, 'device' => 'ج٢', 'ip' => '2.2.2.2',
            'user_agent' => 'ua', 'started_at' => now()->subHours(9), 'last_seen_at' => now()->subHours(8),
        ]);

        $rows = $this->actingAs($this->owner)->get('/admin/activity')->assertOk()->viewData('rows');
        $byId = collect($rows)->keyBy(fn ($r) => $r->u->id);

        $emp = $byId[$this->employee->id];
        $this->assertSame(2, (int) $emp->visits, 'عددُ زيارات الموظف خاطئ');
        $this->assertSame(2, (int) $emp->actions, 'عددُ أفعال الموظف خاطئ');
        $this->assertTrue((bool) $emp->online, 'الموظف نشطٌ ولا يظهر متّصلاً');
        // «آخر» من نبضة الجلسة (أحدثُ من آخر زيارة)
        $this->assertNotNull($emp->last);
        $this->assertNotNull($emp->first);

        $two = $byId[$u2->id];
        $this->assertSame(1, (int) $two->visits, 'عددُ زيارات الثاني خاطئ');
        $this->assertSame(0, (int) $two->actions, 'الثاني بلا أفعالٍ ومع ذلك عُدَّ له فعل');
        $this->assertFalse((bool) $two->online, 'الثاني غيرُ متّصلٍ ويظهر متّصلاً');

        // المالكُ نفسُه بلا زياراتٍ اليوم: أصفارٌ لا أخطاء
        $own = $byId[$this->owner->id] ?? null;
        $this->assertNotNull($own, 'المالكُ غائبٌ من القائمة');
        $this->assertSame(0, (int) $own->visits);
    }
}
