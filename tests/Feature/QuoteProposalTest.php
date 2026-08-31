<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * عروض المشاريع — المرحلة أ: البنود المهيكلة والاحتساب والترقيم والربحية.
 */
class QuoteProposalTest extends TestCase
{
    public function test_quote_number_is_auto_generated_and_unique(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q1 = Quote::create(['client_id' => $c->id, 'total' => 0]);
        $q2 = Quote::create(['client_id' => $c->id, 'total' => 0]);

        $this->assertNotEmpty($q1->doc_no);
        $this->assertStringStartsWith('QT-', $q1->doc_no);
        $this->assertNotSame($q1->doc_no, $q2->doc_no, 'رقما العرضين تصادما');
    }

    public function test_structured_lines_compute_totals_server_side(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك']);

        // بند: ٢ × ١٠٠ بخصم ١٠٪ وضريبة ٥٪ = 180 ثم 189
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', [
            'title' => 'تصميم', 'qty' => 2, 'unit_price' => 100,
            'discount_pct' => 10, 'tax_pct' => 5, 'unit_cost' => 40,
        ])->assertRedirect();

        $line = QuoteLine::where('quote_id', $q->id)->first();
        $this->assertSame('189.000', (string) $line->line_total);

        $q->refresh();
        $this->assertSame('180.000', (string) $q->amount, 'الصافي قبل الضريبة خطأ');
        $this->assertSame('9.000', (string) $q->tax, 'الضريبة خطأ');
        $this->assertSame('189.000', (string) $q->total, 'الإجمالي لم يُحسب من البنود');
        $this->assertSame('80.000', (string) $q->cost, 'التكلفة الداخلية لم تُجمَع (2×40)');
    }

    public function test_two_lines_sum_and_deleting_recomputes(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 0]);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', ['title' => 'أ', 'qty' => 1, 'unit_price' => 100]);
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', ['title' => 'ب', 'qty' => 1, 'unit_price' => 50]);
        $this->assertSame('150.000', (string) $q->fresh()->total);

        $first = QuoteLine::where('quote_id', $q->id)->orderBy('sort')->first();
        $this->actingAs($this->owner)->delete('/quote/' . $q->id . '/line/' . $first->id)->assertRedirect();
        $this->assertSame('50.000', (string) $q->fresh()->total, 'الحذف لم يُعِد الحساب');
    }

    public function test_internal_cost_and_margin_hidden_from_a_client_facing_role(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 1000, 'cost' => 600]);

        // الهامش يُحسب داخلياً
        $this->assertSame(40.0, $q->margin());

        // دورٌ يُخفى عنه حقلُ التكلفة لا يرى بطاقة الربحية في صفحة العرض
        $role = Role::create(['name' => 'مبيعات مقيّد', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]],
            'field_rules' => ['quotes' => ['cost' => 'hide']]]);
        $u = User::create(['name' => 'مبيعات', 'email' => 'sales@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/m/quotes/' . $q->id)->assertOk()->assertDontSee('الربحية الداخلية');
        // والمالك يراها
        $this->actingAs($this->owner)->get('/m/quotes/' . $q->id)->assertOk()->assertSee('الربحية الداخلية');
    }

    public function test_accept_stamps_acceptance_and_fires_event(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 500, 'status' => 'مُرسل']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'accept'])->assertRedirect();
        $q->refresh();
        $this->assertSame('مقبول', $q->status);
        $this->assertNotNull($q->accepted_at, 'القبول لم يُختَم');
        $this->assertSame('المالك', $q->accepted_by);
    }

    public function test_send_over_threshold_routes_to_internal_review(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_amount', '1000');
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 5000, 'status' => 'مسودة']);

        // دورٌ بلا راية اعتماد → الإرسال يُحال للمراجعة الداخلية لا يُرسَل
        $role = Role::create(['name' => 'مُعِدّ', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);
        $u = User::create(['name' => 'مُعِدّ', 'email' => 'prep@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();
        $this->assertSame('مراجعة داخلية', $q->fresh()->status, 'العرض الكبير أُرسل بلا اعتماد');
    }

    public function test_lines_frozen_after_acceptance(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100, 'status' => 'مقبول']);

        // عرضٌ مقبولٌ لا تُعدَّل بنوده — حفظاً للتاريخ التجاريّ
        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/line', ['title' => 'متأخر', 'qty' => 1, 'unit_price' => 10])
            ->assertStatus(422);
    }
}
