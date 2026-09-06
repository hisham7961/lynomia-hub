<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **صدقُ العيّنة في قارئ الاختناقات (WP-7.4).**
 *
 * كلُّ قراءةٍ مسقوفةٍ في `ExecutionStats` تُعلن سقفَها (`capped`) وتُظهره الشاشةُ
 * في نصّها — إلا اثنتين انزلقتا، وكلتاهما من صنفٍ واحد: **رقمٌ ناقصٌ يبدو
 * كاملاً**. وهذا أخطرُ من رقمٍ غائب، لأنّ قارئَه لا يعرف أن يشكّ فيه.
 *
 *  ١) **مكوثُ الحالات** كان يقرأ سقفاً واحداً مشتركاً مرتَّباً بالوحدة
 *     (`ORDER BY module, …  LIMIT 2000`) — فأكثرُ الوحدتين قيوداً يبتلع السقفَ
 *     كلَّه و**تختفي الأخرى من الجدول بلا كلمة**. منشأةٌ نشطةٌ على المهامّ لا
 *     ترى اختناقَ تذاكرها أبداً، والشاشةُ تقول «لا شيء» وهي لم تنظر.
 *  ٢) **إعادةُ الفتح** كانت مسقوفةً بمئتَي تذكرةٍ **بلا إعلان**: «٣ مرات على
 *     ٣ تذاكر» تُقرأ حصراً وهي عيّنة.
 */
class Phase7SamplingHonestyTest extends TestCase
{
    /** قيودُ تدقيقٍ مبذورةٌ بالجملة — كلُّ قيدٍ سجلٌّ مستقلٌّ دخل حالتَه */
    private function seedAudits(string $module, string $status, int $n, $at): void
    {
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            DB::table('audits')->insert(array_map(fn () => [
                'action' => 'تعديل', 'module' => $module, 'record_id' => (string) Str::uuid(),
                'after' => json_encode(['status' => $status], JSON_UNESCAPED_UNICODE),
                'created_at' => $at,
            ], $chunk));
        }
    }

    /* ── ١) السقفُ لا يبتلع وحدةً كاملة ── */

    public function test_a_busy_module_never_swallows_the_whole_dwell_sample(): void
    {
        $this->seedCore();
        DB::table('audits')->delete();          // بذرٌ نظيفٌ: التاريخُ ما نبذره وحده

        // منشأةٌ نشطةٌ على المهامّ: قيودٌ تتجاوز السقفَ وحدَها
        $this->seedAudits('tasks', 'قيد التنفيذ',
            ExecutionStats::DWELL_SAMPLE_CAP + 1, now()->subDays(2));
        // وتذاكرُها قليلةٌ لكنها مختنقة — وهي بالضبط ما تبحث عنه الشاشة
        $this->seedAudits('tickets', 'بانتظار العميل', 3, now()->subDays(3));

        $this->actingAs($this->owner);
        $dwell = ExecutionStats::bottlenecks(hub_range())['dwell'];

        $this->assertTrue($dwell['capped'], 'العيّنةُ مسقوفةٌ فعلاً في هذه البذور');

        $tickets = collect($dwell['rows'])->firstWhere('module', 'tickets');
        $this->assertNotNull($tickets,
            'وحدةُ التذاكر اختفت من جدول المكوث لأن المهامَّ ابتلعت السقف');
        $this->assertSame('بانتظار العميل', $tickets['status']);
        $this->assertSame(3, $tickets['n'], 'قيودُ التذاكر الثلاثةُ كلُّها في العيّنة');

        // والمهامُّ حاضرةٌ كذلك — الإصلاحُ يوسّع العيّنة ولا يقايض وحدةً بأخرى
        $this->assertNotNull(collect($dwell['rows'])->firstWhere('module', 'tasks'));
    }

    /* ── ٢) سقفُ إعادة الفتح مُعلَن ── */

    public function test_the_reopen_counter_declares_its_sample_cap(): void
    {
        $this->seedCore();

        // بذرةٌ صغيرة: غيرُ مسقوفةٍ فالرقمُ حصرٌ لا عيّنة
        Ticket::create(['subject' => 'ارتدّت', 'status' => 'قيد المعالجة',
            'meta' => ['reopened' => 2]]);
        $this->actingAs($this->owner);

        $small = ExecutionStats::bottlenecks(hub_range())['reopened'];
        $this->assertArrayHasKey('capped', $small, 'قراءةٌ مسقوفةٌ بلا إعلانِ سقف');
        $this->assertFalse($small['capped']);
        $this->assertSame(1, $small['n']);
        $this->assertSame(2, $small['total']);

        // وبذرةٌ تتجاوز السقف: الرقمُ يبقى مفيداً لكنه يُعلن أنه عيّنة
        $rows = [];
        for ($i = 0; $i < ExecutionStats::REOPEN_SAMPLE_CAP; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'subject' => 'ت' . $i,
                'status' => 'قيد المعالجة',
                'meta' => json_encode(['reopened' => 1], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) DB::table('tickets')->insert($chunk);

        $big = ExecutionStats::bottlenecks(hub_range())['reopened'];
        $this->assertTrue($big['capped'], 'تجاوزُ السقف لم يُعلَن');

        // والشاشةُ تقول ذلك بنصّها — لا يُعلَن في المصفوفة ويُكتم في العرض
        $this->get('/workforce/overview')->assertOk()->assertSee('عيّنة بسقف');
    }
}
