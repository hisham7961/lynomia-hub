<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FinDocument;
use App\Models\Project;
use App\Support\Sheet;
use App\Support\WebhookDispatcher;
use Tests\Concerns\FakesOdoo;
use Tests\TestCase;

/**
 * الموجة الرابعة — عيوب التصحيح المتنوّعة المنخفضة:
 * (١٢) حقل event في حمولة الويبهوك مسبوقٌ باسم الوحدة مرّتين للأحداث الدلالية
 *      (invoice.paid ← fin.invoice.paid) — بخلاف ترويسة X-Hub-Event المُطبَّعة.
 * (١٣) نتائج البحث الشامل تمرّر مفتاح الحالة لا عمودها الفيزيائي، فتختفي شارة
 *      حالة وحدة «الوثائق» (docStatus ≠ doc_status) وحدها بصمت.
 * (١٤) إجماليات أودو تُجمع عبر قنواتٍ قد تختلف عملاتها وأوضاعها (أوامر/فواتير)
 *      وتُعرض «بعملة أودو» بلا وسم اختلاط.
 * (٦) قارئ الجداول يُراكم كل الصفوف في الذاكرة قبل سقف الاستيراد (٥٠٠٠) — فملفٌّ
 *      بملايين الصفوف يُحمَّل كاملاً قبل رفضه. الحدّ المبكّر يبوّئ الذاكرة.
 */
class MiscCorrectnessLowRound4Test extends TestCase
{
    use FakesOdoo;

    /** (١٢) حدثٌ دلاليّ لا يُضاعف بادئة الوحدة في حمولة الويبهوك */
    public function test_webhook_payload_event_is_not_double_prefixed(): void
    {
        $this->seedCore();
        $m = FinDocument::create(['doc_no' => 'INV-W1', 'kind' => 'فاتورة مبيعات',
            'date' => now()->toDateString(), 'total' => 100, 'paid' => 0, 'state' => 'مرسلة']);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'payload');
        $ref->setAccessible(true);
        $payload = $ref->invoke(null, 'invoice.paid', 'fin', $m, 'مدفوعة');

        $this->assertSame('invoice.paid', $payload['event'],
            'حقل event ضاعف بادئة الوحدة للحدث الدلاليّ');
    }

    /** (١٣) نتائج بحث «الوثائق» تحمل عمود الحالة الفيزيائيّ لا مفتاحه */
    public function test_search_results_carry_physical_status_column(): void
    {
        $this->seedCore();
        Document::create(['name' => 'وثيقةٌ فريدةٌ للبحث', 'doc_status' => 'ساري']);

        $resp = $this->actingAs($this->owner)->get('/search?q=' . urlencode('وثيقةٌ فريدةٌ'))->assertOk();
        $groups = collect($resp->viewData('groups'));
        $files = $groups->firstWhere('module', 'files');

        $this->assertNotNull($files, 'وحدة الوثائق لم تظهر في نتائج البحث');
        $this->assertSame('doc_status', $files['status'],
            'نتائج البحث تحمل مفتاح الحالة (docStatus) لا عمودها (doc_status) — الشارة تختفي');
    }

    /** (١٤) بطاقة قنوات أودو تُنبّه لاختلاط العملات عند تعدّد القنوات */
    public function test_odoo_channels_card_warns_on_currency_mixing(): void
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://main.example.com', 'odoo.db' => 'db',
                  'odoo.user' => 'ro@x.c', 'odoo.key' => 'k'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        $p = Project::create(['name' => 'مشروع مختلط العملات', 'status' => 'نشط']);
        $meta = (array) $p->meta;
        $meta['odoo']['channels'] = [
            ['key' => 'c_team', 'label' => 'فريق', 'icon' => '🛒', 'mode' => 'team', 'ref_id' => 5, 'ref_name' => 'R5'],
            ['key' => 'c_jrn', 'label' => 'دفتر', 'icon' => '🧾', 'mode' => 'journal', 'ref_id' => 9, 'ref_name' => 'R9'],
        ];
        $p->meta = $meta;
        $p->save();

        $this->fakeOdoo('https://main.example.com', [
            'common.authenticate' => 7,
            'sale.order.search_count' => 4,
            'sale.order.read_group' => [['amount_total' => 500.0]],
            'account.move.search_count' => 9,
            'account.move.read_group' => [['amount_total' => 1200.0]],
        ]);

        $this->actingAs($this->owner)->get("/m/projects/{$p->id}")->assertOk()
            ->assertSee('قد تختلط', false);
    }

    /** (٦) قارئ الجداول يبوّئ عدد الصفوف مبكّراً فلا يُحمَّل الملف الضخم كاملاً */
    public function test_sheet_read_caps_row_count_early(): void
    {
        $lines = ['title,val'];
        for ($i = 0; $i < 7000; $i++) $lines[] = "row{$i},{$i}";
        $path = tempnam(sys_get_temp_dir(), 'sheet') . '.csv';
        file_put_contents($path, implode("\n", $lines));

        try {
            $rows = Sheet::read($path, 'csv');
            $this->assertLessThanOrEqual(Sheet::MAX_ROWS, count($rows),
                'قارئ الجداول حمّل صفوفاً بلا حدّ — ملفٌّ ضخمٌ يستنزف الذاكرة قبل سقف الاستيراد');
            $this->assertLessThan(7000, count($rows), 'لم يُقتطع الملف الضخم أصلاً');
        } finally {
            @unlink($path);
        }
    }
}
