<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\Project;
use App\Models\StockItem;
use App\Support\DataQuality;
use App\Support\Delivery;
use Tests\TestCase;

/**
 * الموجة السابعة (الدفعة الثانية من أسطول «الخيارات العقيمة») — **رقمٌ يُعرض
 * وهو لا يعني ما يقوله، وفحصٌ لا يمكن أن يجد شيئاً أبداً.**
 *
 *  ١) `CeoController` — بطاقة «مستحقات لم تُحصَّل» تجمع **ما علينا مع ما لنا**:
 *     الاستعلامُ بلا فلترِ نوعٍ إطلاقاً، ففاتورةُ المشتريات غيرُ المسدَّدة
 *     تُعدّ ديناً **لنا**. صاحبُ القرار يقرأ رقمَ ما يُنتظر تحصيلُه وفيه ما
 *     عليه هو أن يدفع — وهو أسوأُ خطأٍ ممكنٍ في هذه البطاقة بعينها.
 *  ٢) `DataQuality` — فحصُ «فاتورةٌ بلا طرف» مشروطٌ بنوعٍ **لا يكتبه النظام
 *     أصلاً** (`فاتورة` المجرّدة؛ والحقيقيُّ «فاتورة مبيعات»/«فاتورة مشتريات»).
 *     فالفحصُ يُعرض في مركز الجودة وعدُّه صفرٌ أبداً — وصفرٌ يُقرأ «لا نقص»
 *     وهو «لم يُبحث». والعلّةُ نفسُها في توصية «مستحقات متأخرة» بـ`helpers`.
 *  ٣) `DataQuality::apply('req')` — `orWhere($col, '')` على عمودٍ عدديّ: MySQL
 *     يحوّل `''` إلى صفر، فكميةٌ نافدة (0) وباقةٌ مجانية (0) تُعدّان «حقلٌ
 *     مطلوبٌ وفارغ». مركزُ الجودة يُنذر على بياناتٍ سليمة، والإنذارُ الكاذب
 *     يُدرَّب المستخدمُ على تجاهله فيضيع الصادقُ معه.
 *  ٤) `DataQuality` — فحصُ «حالةٌ خارج الخيارات» يقارن **مفتاحَ** حقل الحالة
 *     بأسماء الأعمدة، فيسقط كلياً عن كل وحدةٍ يخالف مفتاحُها عمودَها.
 *  ٥) `Delivery` — بطاقة «مشروعٌ جارٍ صامت» تقصّ ثلاثين مشروعاً **قبل** أن
 *     تُرشِّح الصامتَ وبلا ترتيب: فمشاريعُ نشطةٌ تملأ الحصّة والصامتُ — وهو
 *     المقصود — لا يظهر.
 */
class LyingMetricsRound7Test extends TestCase
{
    /* ── ١) «مستحقات لم تُحصَّل» = ما لنا وحده ── */

    public function test_uncollected_receivables_excludes_what_we_owe(): void
    {
        $this->seedCore();

        FinDocument::create(['doc_no' => 'INV-1', 'kind' => 'فاتورة مبيعات', 'total' => 1000,
            'paid' => 0, 'state' => 'مرسلة', 'date' => now()->toDateString()]);
        FinDocument::create(['doc_no' => 'BILL-1', 'kind' => 'فاتورة مشتريات', 'total' => 700,
            'paid' => 0, 'state' => 'مرسلة', 'date' => now()->toDateString()]);

        $res = $this->actingAs($this->owner)->get('/ceo');
        $res->assertOk();
        $kpi = $res->viewData('kpi');

        $this->assertSame(1000.0, round((float) $kpi['unpaid'], 2),
            'بطاقةُ «مستحقات لم تُحصَّل» جمعت فاتورةَ مشترياتٍ غير مسدَّدة مع '
            . 'فاتورة المبيعات — فما علينا يُعرض على أنه ما لنا، وصاحبُ القرار '
            . 'يبني على رقمٍ يقول عكسَ ما يعنيه');
    }

    /* ── ٢) فحصٌ بشرطٍ لا يقع = فحصٌ لا وجود له ── */

    public function test_the_invoice_without_party_check_can_actually_fire(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        FinDocument::create(['doc_no' => 'INV-NP', 'kind' => 'فاتورة مبيعات', 'total' => 50,
            'partner' => null, 'state' => 'مرسلة', 'date' => now()->toDateString()]);

        $q = DataQuality::apply(FinDocument::query(), 'fin', 'partner');

        $this->assertSame(1, $q->count(),
            'فحصُ «فاتورةٌ بلا طرف» مشروطٌ بنوعِ مستندٍ لا يكتبه النظام أصلاً — '
            . 'فعدُّه صفرٌ أبداً، وصفرٌ في مركز الجودة يُقرأ «لا نقص» وهو «لم يُبحث»');
    }

    /* ── ٣) الصفرُ قيمةٌ لا فراغ ── */

    public function test_a_zero_value_is_not_reported_as_a_missing_required_field(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        StockItem::create(['name' => 'صنفٌ نفد', 'sku' => 'Z-0', 'qty' => 0]);

        $rules = DataQuality::rules('stock');
        $qtyRule = collect($rules)->first(fn ($r) => ($r['col'] ?? '') === 'qty' && $r['kind'] === 'req');
        if (! $qtyRule) { $this->markTestSkipped('لا قاعدةَ «مطلوبٌ وفارغ» على الكمية'); }

        $key = collect($rules)->search(fn ($r) => $r === $qtyRule);
        $n = DataQuality::apply(StockItem::query(), 'stock', (string) $key)->count();

        $this->assertSame(0, $n,
            'كميةٌ نافدة (صفر) تُعدّ «حقلاً مطلوباً وفارغاً»: `orWhere($col, \'\')` '
            . 'على عمودٍ عدديّ يُحوّل MySQL فيه الفراغَ إلى صفر. إنذارٌ كاذبٌ '
            . 'يُدرَّب المستخدمُ على تجاهله فيضيع الصادقُ معه');
    }

    /* ── ٤) فحصُ الحالة يقع على كل وحدةٍ لها حالة ── */

    public function test_the_status_rule_exists_for_modules_whose_key_differs_from_its_column(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $offenders = [];
        foreach (hub_modules() as $mk => $def) {
            if (empty($def['status'])) continue;
            $col = hub_status_col($mk);
            if (! $col || $col === $def['status']) continue;      // المفتاحُ يطابق العمود — لا التباس
            $opts = collect($def['fields'] ?? [])->firstWhere('key', $def['status'])['options'] ?? [];
            if (count((array) $opts) < 2) continue;
            if (! isset(DataQuality::rules($mk)['status'])) $offenders[] = $mk . " ({$def['status']} ↔ {$col})";
        }

        $this->assertSame([], $offenders,
            'فحصُ «حالةٌ خارج الخيارات» يقارن **مفتاحَ** الحقل بأسماء الأعمدة، '
            . 'فيسقط كلياً عن كل وحدةٍ يخالف مفتاحُها عمودَها: ' . implode(' · ', $offenders));
    }

    /* ── ٥) الصامتُ يُرشَّح قبل القصّ ── */

    public function test_the_silent_project_card_filters_before_it_truncates(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // ٣٥ مشروعاً نشطاً بتحديثٍ حديث + مشروعٌ صامتٌ واحد
        for ($i = 0; $i < 35; $i++) {
            $p = Project::create(['name' => 'مشروع نشط ' . $i, 'status' => 'قيد التنفيذ']);
            \App\Models\WorkUpdate::create(['project_id' => $p->id, 'done' => 'تحديثُ عمل',
                'date' => now()->toDateString()]);
        }
        $silent = Project::create(['name' => 'المشروعُ الصامت', 'status' => 'قيد التنفيذ']);

        $rows = collect(Delivery::breaks());
        $hit = $rows->first(fn ($r) => ($r['id'] ?? '') === $silent->id);

        $this->assertNotNull($hit,
            'بطاقةُ «مشروعٌ جارٍ صامت» تقصّ ثلاثين مشروعاً **قبل** أن تُرشِّح '
            . 'الصامت: فالمشاريعُ النشطةُ تملأ الحصّة والصامتُ — وهو كلُّ المقصود '
            . 'من البطاقة — لا يظهر أبداً');
    }
}
