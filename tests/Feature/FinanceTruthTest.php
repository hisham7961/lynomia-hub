<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صدق الأرقام المالية — عيوب الموجة الثانية من الفحص:
 * hub_project_pl كان يجمع الإيراد بنوع «فاتورة» غير الموجود (النوع الحقيقي
 * «فاتورة مبيعات» من config('hub.fin.income'))، فإيراد كل مشروع حقيقي = صفر.
 * ومعه: الحالات الميتة تدخل الأرقام، وNULL في state يُسقط المستند من كل
 * التقارير، وpaid=NULL يُسقط الفاتورة غير المدفوعة كلياً من «غير المحصَّل»،
 * وبطاقة الامتثال تستعلم عموداً غير موجود فتموت صامتة.
 */
class FinanceTruthTest extends TestCase
{
    protected function project(): Project
    {
        return Project::create(['name' => 'مشروع الصدق', 'status' => 'قيد التنفيذ',
            'currency' => 'د.ك', 'start_date' => now()->subDays(30)->toDateString()]);
    }

    protected function fin(array $a): FinDocument
    {
        return FinDocument::create($a + ['date' => now()->toDateString()]);
    }

    /** الإيراد بالنوع الحقيقي الذي يكتبه النظام لا بحرفي قديم */
    public function test_project_pl_counts_real_invoice_kind(): void
    {
        $this->seedCore();
        $p = $this->project();
        // النوع الذي يكتبه مسار «تحويل عرض السعر» فعلياً — QuoteController
        $this->fin(['doc_no' => 'INV-R', 'kind' => 'فاتورة مبيعات',
            'project_id' => $p->id, 'total' => 4000, 'paid' => 2500]);

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(4000.0, $pl['revenue']['invoiced'],
            'فاتورة مبيعات حقيقية لا تدخل إيراد المشروع — الحرفي القديم «فاتورة» ما زال هو الشرط');
        $this->assertSame(2500.0, $pl['revenue']['collected']);
    }

    /** الملغاة والمسودة لا تدخلان إيراد المشروع ولا مصروفه */
    public function test_project_pl_excludes_dead_states(): void
    {
        $this->seedCore();
        $p = $this->project();
        $this->fin(['doc_no' => 'INV-1', 'kind' => 'فاتورة مبيعات', 'project_id' => $p->id, 'total' => 1000]);
        $this->fin(['doc_no' => 'INV-X', 'kind' => 'فاتورة مبيعات', 'project_id' => $p->id, 'total' => 9999, 'state' => 'ملغاة']);
        $this->fin(['doc_no' => 'INV-D', 'kind' => 'فاتورة مبيعات', 'project_id' => $p->id, 'total' => 5555, 'state' => 'مسودة']);
        $this->fin(['doc_no' => 'EXP-X', 'kind' => 'مصروف', 'project_id' => $p->id, 'total' => 7777, 'state' => 'ملغاة']);

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(1000.0, $pl['revenue']['invoiced'], 'الملغاة/المسودة دخلت الإيراد');
        $this->assertSame(0.0, $pl['cost']['external'], 'المصروف الملغى دخل التكلفة');
    }

    /** المصروف بتعريفه المعتمد الثلاثي لا بنوع «مصروف» وحده */
    public function test_project_pl_counts_all_expense_kinds(): void
    {
        $this->seedCore();
        $p = $this->project();
        $this->fin(['doc_no' => 'EXP-1', 'kind' => 'مصروف', 'project_id' => $p->id, 'total' => 300]);
        $this->fin(['doc_no' => 'PUR-1', 'kind' => 'فاتورة مشتريات', 'project_id' => $p->id, 'total' => 120]);
        $this->fin(['doc_no' => 'OUT-1', 'kind' => 'دفعة صادرة', 'project_id' => $p->id, 'total' => 80]);

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(500.0, $pl['cost']['external'],
            'فاتورة المشتريات والدفعة الصادرة لا تدخلان تكلفة المشروع');
    }

    /** «دفعة واردة» محصَّلة بطبيعتها، وpaid=NULL لا يفسد الجمع */
    public function test_incoming_payment_and_null_paid(): void
    {
        $this->seedCore();
        $p = $this->project();
        $this->fin(['doc_no' => 'INV-N', 'kind' => 'فاتورة مبيعات', 'project_id' => $p->id, 'total' => 1000]); // paid NULL
        $this->fin(['doc_no' => 'PAY-IN', 'kind' => 'دفعة واردة', 'project_id' => $p->id, 'total' => 250]);

        $pl = hub_project_pl($p->id, true);
        $this->assertSame(1250.0, $pl['revenue']['invoiced']);
        $this->assertSame(250.0, $pl['revenue']['collected'],
            'الدفعة الواردة بلا paid لم تُحسب محصَّلة');
        $this->assertSame(1000.0, $pl['revenue']['uncollected']);
    }

    /** الاشتراك الملغى/المنتهي لا يُحمَّل على المشروع كامل عمره */
    public function test_cancelled_subscription_is_not_charged(): void
    {
        $this->seedCore();
        $p = $this->project();
        foreach ([['نشطة', 30], ['ملغي', 500], ['منتهي', 900]] as [$st, $amt]) {
            DB::table('subscriptions')->insert(['id' => (string) Str::uuid(), 'service' => 'أداة ' . $st,
                'project_id' => $p->id, 'amount' => $amt, 'cycle' => 'شهري', 'status' => $st,
                'renew' => now()->addMonth()->toDateString(),
                'created_at' => now(), 'updated_at' => now()]);
        }

        $pl = hub_project_pl($p->id, true);
        // شهر واحد من التشغيل × ٣٠ للأداة النشطة وحدها — الملغاة والمنتهية صفر
        $this->assertLessThan(100, $pl['cost']['tools'],
            'الاشتراك الملغى/المنتهي حُمّل على المشروع');
    }

    /** «غير المحصَّل» في لوحة CEO يشمل فاتورة لم يُدفع منها فلس (paid=NULL) */
    public function test_ceo_unpaid_includes_null_paid_invoices(): void
    {
        $this->seedCore();
        $this->fin(['doc_no' => 'INV-U', 'kind' => 'فاتورة مبيعات', 'total' => 61734, 'state' => 'مرسلة']); // paid NULL

        $html = $this->actingAs($this->owner)->get('/ceo')->assertOk()->getContent();
        // بلاطة المؤشر تحديداً — الرقم يظهر في جدول «أعلى المستحقات» على أي حال
        $this->assertStringContainsString('<b>61,734</b><span>مستحقات لم تُحصَّل</span>', $html,
            'SUM(total - paid) يتجاهل paid=NULL فتسقط أسوأ الفواتير من «غير المحصَّل»');
    }

    /** مستند بلا حالة (NULL) لا يسقط من مجاميع التقارير */
    public function test_null_state_documents_are_counted(): void
    {
        $this->seedCore();
        $this->fin(['doc_no' => 'INV-S', 'kind' => 'فاتورة مبيعات', 'total' => 52981]); // state NULL

        // hub_fin_sum هي قلب المجاميع (الموجز، الصحة، اللوحات)
        $this->assertSame(52981.0, hub_fin_sum(['فاتورة مبيعات']),
            'NULL NOT IN (...) تُقيَّم NULL فيختفي المستند بلا حالة من كل التقارير');

        // والتقرير المالي الحي كذلك
        $html = $this->actingAs($this->owner)->get('/reports/finance')->assertOk()->getContent();
        $this->assertStringContainsString('52,981', $html);
    }

    /** بطاقة «الامتثال» في صحة الشركة تعيش: العمود date_end لا end */
    public function test_health_compliance_card_appears(): void
    {
        $this->seedCore();
        DB::table('contracts')->insert(['id' => (string) Str::uuid(), 'title' => 'عقد متجاوز',
            'type' => 'خدمات', 'date_end' => now()->subDays(10)->toDateString(), 'status' => 'ساري',
            'created_at' => now(), 'updated_at' => now()]);

        $h = hub_health(true);
        $this->assertArrayHasKey('الامتثال', $h,
            'استعلام الامتثال يموت على عمود end غير الموجود ويُبتلع الاستثناء صامتاً');
        $this->assertStringContainsString('1 عقد', $h['الامتثال']['note']);
    }

    /** دلاء الأشهر الستة لا تتكرر ولا تسقط آخرَ الشهر (فيضان subMonths) */
    public function test_month_buckets_never_duplicate_at_month_end(): void
    {
        $this->seedCore();
        // 31 أغسطس: subMonths(2) بلا NoOverflow يفيض إلى 1 يوليو فيتكرر يوليو ويختفي يونيو
        $this->travelTo(now()->setDate(now()->year, 8, 31)->setTime(12, 0));

        $labels = [];
        for ($i = 5; $i >= 0; $i--) {
            $labels[] = now()->subMonthsNoOverflow($i)->startOfMonth()->format('Y-m');
        }
        $this->assertSame($labels, array_unique($labels), 'التقويم نفسه مكسور');

        // المسار الحي: لوحة CEO والتقرير المالي يعرضان ٦ أشهر متمايزة
        foreach (['/ceo', '/reports/finance'] as $url) {
            $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            // ستة أشهر متمايزة تعني ظهور اسم شهر يونيو (الشهر ٦) في السلسلة
            $this->assertStringContainsString(now()->subMonthsNoOverflow(2)->translatedFormat('M'), $html,
                "$url يكرر شهراً ويسقط آخر عند نهاية الشهر");
        }
        $this->travelBack();
    }
}
