<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Client;
use App\Models\Comment;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * جولةُ التحصين الرابعة (v2.399) — نتائجُ تدقيق المعمارية والأداء: ARCH-01/02/03، PERF-01/02/04/09.
 * كلُّ اختبارٍ كُتب فاشلاً على الشجرة قبل الإصلاح.
 */
class EnterpriseHardeningRound4Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** ARCH-01: حاملُ العهدة لا يُكتب من النموذج العامّ — دفترُ العهدة وحده */
    public function test_asset_holder_is_not_writable_through_the_generic_form(): void
    {
        $a = Asset::create(['name' => 'لابتوب', 'type' => 'لابتوب', 'status' => 'متاح']);
        $this->actingAs($this->owner)->put('/m/assets/' . $a->id, ['name' => 'لابتوب', 'holderId' => $this->employee->id])->assertRedirect();
        $this->assertNull($a->fresh()->holder_id, 'حاملُ العهدة كُتب بلا قيدٍ في دفترها');
        $this->assertSame(0, AssetCustody::where('asset_id', $a->id)->count());

        // والطريقُ الصحيح يعمل
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover', ['userId' => $this->employee->id, 'at' => now()->toDateString()])->assertRedirect();
        $this->assertSame($this->employee->id, $a->fresh()->holder_id);
        $this->assertSame(1, AssetCustody::where('asset_id', $a->id)->count());
    }

    /** ARCH-02: إجماليُّ العرض ذي البنود يُحسَب من بنوده — كتابةٌ عامّة لا تدوسه */
    public function test_quote_totals_with_lines_are_not_overwritten_by_generic_update(): void
    {
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['doc_no' => 'QT-L-1', 'client_id' => $c->id, 'amount' => 0, 'tax' => 0, 'total' => 0]);
        $q->lines()->create(['title' => 'بند', 'qty' => 1, 'unit_price' => 1000, 'sort' => 1]);
        $q->recalc();
        $this->assertSame(1000.0, (float) $q->fresh()->total);

        $this->actingAs($this->owner)->put('/m/quotes/' . $q->id, ['no' => 'QT-L-1', 'clientId' => $c->id, 'total' => '5', 'amount' => '5'])->assertRedirect();
        $this->assertSame(1000.0, (float) $q->fresh()->total, 'الإجماليُّ دِيس بكتابةٍ عامّة بلا إعادة حساب');

        // عرضٌ بلا بنود (نصٌّ حرّ) يبقى قابلاً للإدخال اليدويّ
        $free = Quote::create(['doc_no' => 'QT-F-1', 'client_id' => $c->id, 'amount' => 100, 'tax' => 0, 'total' => 100]);
        $this->actingAs($this->owner)->put('/m/quotes/' . $free->id, ['no' => 'QT-F-1', 'clientId' => $c->id, 'amount' => '250', 'total' => '250'])->assertRedirect();
        $this->assertSame(250.0, (float) $free->fresh()->total);
    }

    /** ARCH-03: «مدفوعة» لا تُزرع بسحب بطاقة — تُشتقّ من المبلغ المدفوع */
    public function test_invoice_cannot_be_marked_paid_by_dragging_a_card(): void
    {
        $inv = FinDocument::create(['kind' => 'فاتورة', 'total' => 100, 'paid' => 0, 'state' => 'مرسلة']);
        $this->actingAs($this->owner)->post("/m/fin/{$inv->id}/status", ['status' => 'مدفوعة'])->assertStatus(422);
        $this->assertSame('مرسلة', $inv->fresh()->state, 'فاتورةٌ صارت «مدفوعة» بلا مبلغ');
        $this->assertSame(0.0, (float) $inv->fresh()->paid);
        // حالةٌ عادية تبقى بالسحب
        $this->actingAs($this->owner)->post("/m/fin/{$inv->id}/status", ['status' => 'مسودة'])->assertOk();
        $this->assertSame('مسودة', $inv->fresh()->state);
    }

    /** PERF-04: فتحُ سجلٍّ عليه خمسون تعليقاً غيرَ مقروء يكتب إيصالَ القراءة باستعلامٍ واحد */
    public function test_read_receipts_are_written_in_a_single_statement(): void
    {
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        for ($i = 0; $i < 50; $i++) {
            Comment::create(['module' => 'tasks', 'record_id' => $t->id, 'user_id' => $this->employee->id, 'body' => "تعليق {$i}", 'created_at' => now()->subMinutes(50 - $i)]);
        }
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/m/tasks/' . $t->id)->assertOk();
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => stripos($q['query'], 'update') === 0 && str_contains($q['query'], 'comments'))->count();
        DB::disableQueryLog();
        $this->assertSame(1, $updates, "إيصالاتُ القراءة كُتبت في {$updates} استعلاماً");
        $this->assertSame(50, Comment::where('record_id', $t->id)->get()->filter(fn ($c) => in_array($this->owner->id, (array) $c->read_by, true))->count());
    }

    /** PERF-01/02/09: فهارسُ الترتيب والمسوح الساخنة موجودة */
    public function test_ordering_and_hot_indexes_exist(): void
    {
        $starts = fn (string $table, array $cols) => collect(Schema::getIndexes($table))
            ->contains(fn ($i) => array_slice(array_map('strtolower', (array) $i['columns']), 0, count($cols)) === $cols);
        foreach (['tasks', 'clients', 'projects', 'fin_documents', 'contracts', 'tickets', 'quotes', 'assets'] as $t) {
            $this->assertTrue($starts($t, ['created_at']), "$t بلا فهرس created_at — قائمتُها تمسح الجدولَ كلَّه");
        }
        $this->assertTrue($starts('audits', ['action']));
        $this->assertTrue($starts('audits', ['created_at', 'ip']));
        $this->assertTrue($starts('notifications_hub', ['user_id', 'read']));
        $this->assertTrue($starts('fin_documents', ['date']));
    }
}
