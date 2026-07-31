<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * مركز المراقبة: كان يعرض «الحمل» رقماً خاماً بلا عدد أنوية — فلا يُعرف أهو ٥٪
 * أم ٥٠٠٪ — و«الذاكرة» ذروةَ *هذا الطلب* من PHP لا ذاكرة النظام. ولا جواب
 * لسؤال **من يستهلك**: أي مجلد ملأ القرص، أي جدول ينمو، أي مسار يبتلع الوقت.
 * (وأثقل الجداول كانت تظهر على MySQL فقط فتغيب كلياً عن SQLite.)
 */
class MonitorDepthTest extends TestCase
{
    protected function visits(string $path, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
                       'path' => $path, 'route' => 'x', 'at' => now()->subHours($i % 20)];
        }
        DB::table('page_visits')->insert($rows);
    }

    public function test_cpu_is_a_share_of_cores_not_a_bare_number(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('طاقة المعالج', $html, 'الحمل يُنسب لطاقة المعالج لا رقماً خاماً');
        $this->assertStringContainsString('نواة', $html, 'عدد الأنوية معروض — بدونه لا معنى للحمل');
        $this->assertMatchesRegularExpression('/\d+٪<\/b>\s*<span>[^<]*طاقة المعالج/u', $html,
            'النسبة المئوية ظاهرة');
    }

    public function test_memory_is_the_system_not_this_request(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('ذاكرة النظام', $html,
            'ذاكرة النظام لا ذروة طلب PHP الواحد');

        $m = \App\Support\SysMonitor::memory();
        if ($m['ok']) {
            $this->assertGreaterThan($m['php_peak'], $m['total'], 'ذاكرة النظام أكبر من ذروة الطلب');
            $this->assertSame($m['total'] - $m['avail'], $m['used']);
        }
    }

    public function test_heaviest_tables_are_named_even_on_sqlite(): void
    {
        $this->seedCore();
        $this->visits('/m/tasks', 40);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('page_visits', $html, 'أثقل الجداول تظهر على أي محرّك');
        $this->assertStringContainsString('زيارات الصفحات', $html, 'باسمٍ مفهوم لا باسم الجدول وحده');
    }

    public function test_who_eats_the_time_slow_and_busy_routes(): void
    {
        $this->seedCore();
        $this->visits('/m/fin', 30);
        ErrorEvent::create([
            'hash' => hash('sha256', 'slow1'), 'kind' => 'slow',
            'message' => 'طلب استغرق 4200ms', 'url' => 'https://hub.test/reports/heavy',
            'count' => 9, 'status' => 'جديد', 'first_seen' => now()->subDay(), 'last_seen' => now(),
        ]);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('/reports/heavy', $html, 'أبطأ المسارات مُسمّاة');
        $this->assertStringContainsString('/m/fin', $html, 'وأكثر الصفحات استدعاءً');
        $this->assertStringContainsString('من يستهلك', $html, 'الشاشة تجيب سؤال «من المستهلك»');
    }

    public function test_disk_consumers_are_broken_down(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('النسخ الاحتياطية', $html);
        $this->assertStringContainsString('سجلات النظام', $html, 'تفصيل ما يشغل القرص لا نسبةٌ صمّاء');
    }

    public function test_monitor_is_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/ops')->assertForbidden();
    }
}
