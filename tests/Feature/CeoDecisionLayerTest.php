<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Decision;
use App\Models\FinDocument;
use App\Models\Project;
use App\Support\CeoBoard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * لوحة CEO كانت **جدارَ أرقام**: صافي الشهر، عدد العملاء، مهام مفتوحة… كلها
 * صحيحة وكلها لا تطلب شيئاً. يقرؤها المالك فيخرج بانطباعٍ لا بقرار، لأنها
 * لا تجيب الأسئلة الثلاثة التي تُقرأ لوحةٌ تنفيذية من أجلها: ما ينتظر قراري،
 * وأين ينزف المال، وأين الخطر الذي يبدو نجاحاً.
 */
class CeoDecisionLayerTest extends TestCase
{
    public function test_pending_approvals_and_stalled_decisions_ask_for_the_owner(): void
    {
        $this->seedCore();
        DB::table('approvals')->insert(['id' => (string) Str::uuid(), 'title' => 'صرف',
            'type' => 'مالي', 'status' => 'معلّق', 'created_at' => now()->subDays(9), 'updated_at' => now()]);
        Decision::create(['title' => 'قرار متعثر', 'status' => 'متعثر', 'date' => now()->toDateString()]);

        $this->actingAs($this->owner);
        $a = collect(CeoBoard::awaiting($this->owner));

        $this->assertSame(1, $a->firstWhere('label', 'موافقات معلّقة')['n'] ?? 0);
        $this->assertStringContainsString('٣ أيام', $a->firstWhere('label', 'موافقات معلّقة')['why'],
            'موافقةٌ معلّقة تسعة أيام تُعرض كأنها طازجة');
        $this->assertNotNull($a->firstWhere('label', 'قرارات لم تُنفَّذ'));
    }

    /** النزيف يُقاس بالعملة لا بعدد السجلات */
    public function test_leaks_are_measured_in_money(): void
    {
        $this->seedCore();
        FinDocument::create(['doc_no' => 'A', 'kind' => 'فاتورة', 'total' => 1000, 'paid' => 200,
            'state' => 'متأخرة', 'due' => now()->subDays(90)->toDateString()]);
        Project::create(['name' => 'مشروع متجاوز', 'status' => 'قيد التنفيذ',
            'budget' => 1000, 'cost' => 1750]);

        $leaks = collect(CeoBoard::leaks());

        $aged = $leaks->firstWhere('label', 'مستحقات متأخرة فوق ٦٠ يوماً');
        $this->assertNotNull($aged, 'مستحقٌّ متقادم ٩٠ يوماً لم يُحسب نزيفاً');
        $this->assertSame(800.0, (float) $aged['amount'], 'النزيف هو غير المحصَّل لا إجمالي الفاتورة');

        $over = $leaks->firstWhere('tone', 'bad');
        $this->assertSame(750.0, (float) $leaks->firstWhere('label', '1 مشروع تجاوز ميزانيته')['amount'],
            'تجاوز الميزانية يُقاس بالفرق لا بالتكلفة كلها');
    }

    /** التركّز: عميلٌ واحد نصفُ الإيراد خطرٌ يبدو نجاحاً */
    public function test_revenue_concentration_is_computed_and_judged(): void
    {
        $this->seedCore();
        $big = Client::create(['name' => 'عميل كبير']);
        $small = Client::create(['name' => 'عميل صغير']);

        foreach ([[$big, 8000], [$small, 2000]] as [$c, $amt]) {
            FinDocument::create(['doc_no' => 'F' . $amt, 'kind' => 'فاتورة مبيعات', 'total' => $amt,
                'paid' => $amt, 'state' => 'مدفوعة', 'client_id' => $c->id,
                'date' => now()->subMonth()->toDateString()]);
        }

        $conc = CeoBoard::concentration();

        $this->assertSame(80, $conc['firstPct']);
        $this->assertSame('bad', $conc['tone']);
        $this->assertStringContainsString('أزمة سيولة', $conc['verdict'],
            'تركّزٌ ٨٠٪ عُرض بلا حكم — الرقم بلا حكمٍ لا يُقرَّر عليه');
        $this->assertSame('عميل كبير', $conc['top'][0]['name']);
    }

    /** وعميلٌ واحد فقط لا يصنع «تركّزاً» يُقارَن به */
    public function test_concentration_is_silent_with_a_single_payer(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'وحيد']);
        FinDocument::create(['doc_no' => 'X', 'kind' => 'فاتورة مبيعات', 'total' => 500, 'paid' => 500,
            'state' => 'مدفوعة', 'client_id' => $c->id, 'date' => now()->toDateString()]);

        $this->assertSame([], CeoBoard::concentration());
    }

    /** الاتجاه: الرقم وحده لا يُقرَّر عليه — يُقارَن بالشهر السابق وبالخطّ الأساس */
    public function test_the_trend_compares_to_last_month_and_to_the_baseline(): void
    {
        $months = [
            ['l' => 'يناير', 'i' => 1000, 'e' => 900],   // +100
            ['l' => 'فبراير', 'i' => 1000, 'e' => 800],  // +200
            ['l' => 'مارس', 'i' => 1000, 'e' => 500],    // +500
        ];
        $t = CeoBoard::trend($months);

        $this->assertSame(300.0, $t['delta']);
        $this->assertSame('up', $t['dir']);
        $this->assertSame(150, $t['pct']);
        $this->assertSame(350.0, $t['vsBase'], 'الخطّ الأساس (متوسط ١٥٠) لم يُحسب صحيحاً');
    }

    public function test_a_declining_month_is_named_as_such(): void
    {
        $t = CeoBoard::trend([['l' => 'أ', 'i' => 1000, 'e' => 0], ['l' => 'ب', 'i' => 400, 'e' => 0]]);

        $this->assertSame('down', $t['dir']);
        $this->assertStringContainsString('أضعف', $t['verdict']);
    }

    /** الحوكمة تصعد للمالك: التزامٌ نظاميّ متأخر ليس شأن قسمٍ وحده */
    public function test_overdue_compliance_reaches_the_owner(): void
    {
        $this->seedCore();
        DB::table('compliance_items')->insert(['id' => (string) Str::uuid(), 'title' => 'تجديد رخصة',
            'due' => now()->subDays(5)->toDateString(), 'status' => 'قائم',
            'created_at' => now(), 'updated_at' => now()]);

        $g = collect(CeoBoard::governance());
        $this->assertNotNull($g->firstWhere('label', 'التزامات نظامية متأخرة'));
        $this->assertSame('bad', $g->firstWhere('label', 'التزامات نظامية متأخرة')['tone']);
    }

    /** والشاشة تعرض طبقة القرار قبل الأرقام، وهي للمالك وحده */
    public function test_the_board_renders_the_decision_layer_for_owners_only(): void
    {
        $this->seedCore();
        Decision::create(['title' => 'قرار متعثر', 'status' => 'متعثر', 'date' => now()->toDateString()]);

        $html = $this->actingAs($this->owner)->get('/ceo')->assertOk()->getContent();
        $this->assertStringContainsString('ينتظر قرارك', $html);
        $this->assertLessThan(strpos($html, '📊 الأرقام'), strpos($html, 'ينتظر قرارك'),
            'طبقة القرار تحت جدار الأرقام — فتُقرأ بعد أن يملّ القارئ');

        $this->actingAs($this->employee)->get('/ceo')->assertForbidden();
    }
}
