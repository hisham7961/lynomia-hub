<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteMilestone;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * الجولةُ الثانية لمعلم الدفع بلا فاتورة (v2.399.1) — ما أثبتته المراجعةُ العدائية:
 *
 * - ازدواجُ الفوترة في الاتجاه المعاكس: `do=invoice` كان يسكّ فاتورةً كاملةً فوق
 *   فواتيرِ دفعاتٍ حيّة.
 * - طريقٌ مسدود: معلمٌ أُلغيت فاتورتُه أو حُذفت تعود إشارتُه وزرُّه، لكن السكَّ كان
 *   يرفض («سُكّت من قبل») — فالتوصيةُ لا تُحَلّ بفعلها.
 * - عمى السقف: حدُّ الأربعين كان يُطبَّق قبل استبعاد المفوتَر، فتعمى الإشارةُ متى
 *   تراكمت معالمُ مفوتَرةٌ أقدم.
 * - عدسةُ المشروع: العرضُ المحوَّل يحفظ مشروعَه في `meta.project_id` لا في العمود.
 * - معلمٌ بلا قيمة: كان يُرصَد ولا يُفوتَر.
 * - الشاشة: زرُّ السكّ لمن يملك fin:a بلا quotes:e (والمسارُ يرفضه)، ورقمُ الفاتورة
 *   لمن لا يرى الماليّة.
 */
class IntelligenceMilestoneUninvoicedRound2Test extends TestCase
{
    private function quote(string $status = 'مقبول', array $extra = []): Quote
    {
        $client = Client::create(['name' => 'عميل ' . uniqid(), 'stage' => 'عميل حالي']);

        return Quote::create(array_merge(['doc_no' => 'Q-R2-' . strtoupper(substr(uniqid(), -5)), 'title' => 'عرضٌ بجدول',
            'status' => $status, 'accepted_at' => now()->subDays(10),
            'client_id' => $client->id, 'total' => 1000, 'tax' => 100, 'amount' => 900, 'currency' => 'د.ك'], $extra));
    }

    private function ms(Quote $q, array $attrs = []): QuoteMilestone
    {
        return QuoteMilestone::create(array_merge(['quote_id' => $q->id, 'title' => 'دفعةٌ', 'pct' => 30, 'sort' => 1], $attrs));
    }

    private function keys(?string $projectId = null): array
    {
        Cache::flush();

        return collect(hub_recommendations(true, $projectId)['items'])
            ->filter(fn ($i) => str_starts_with((string) $i['key'], 'milestone.uninvoiced:'))->keyBy('key')->all();
    }

    private function msInvoiceCount(Quote $q): int
    {
        return FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '-M%')->count();
    }

    /** ازدواجُ الفوترة معكوساً: لا فاتورةَ كاملةً فوق فواتيرِ دفعاتٍ حيّة — وتُسمح متى ماتت */
    public function test_full_invoice_is_refused_over_a_live_milestone_invoice_until_it_dies(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $msInv = FinDocument::findOrFail($m->refresh()->invoice_id);

        // الكاملةُ مرفوضةٌ ما دامت فاتورةُ الدفعة حيّة — والشاشةُ لا تعرض زرَّها بل تقول «يُفوتَر بالدفعات»
        $this->post("/quote/{$q->id}/act", ['do' => 'invoice'])->assertStatus(422);
        $this->assertNull($q->refresh()->meta['invoice_id'] ?? null, 'سُكّت فاتورةٌ كاملةٌ فوق فاتورةِ دفعةٍ حيّة');
        $this->assertSame(0, FinDocument::withTrashed()->where('doc_no', 'INV-' . $q->doc_no)->count());
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertDontSee('value="invoice"', false)->assertSee('يُفوتَر بالدفعات');

        // أُلغيت فاتورةُ الدفعة → الكاملةُ تُسكّ (ويعود زرُّها)
        $msInv->update(['state' => 'ملغاة']);
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertSee('value="invoice"', false);
        $this->post("/quote/{$q->id}/act", ['do' => 'invoice'])->assertRedirect();
        $this->assertNotNull($q->refresh()->meta['invoice_id'] ?? null, 'رُفضت الكاملةُ رغم موتِ فاتورةِ الدفعة');
        $this->assertSame(1, FinDocument::withTrashed()->where('doc_no', 'INV-' . $q->doc_no)->count());

        // الإحياءُ معكوساً: أُلغيت الكاملةُ فسُكّت دفعةٌ من جديد — فلا تُحيا الكاملةُ فوقها من مسار الحالة العام
        $full = FinDocument::findOrFail($q->meta['invoice_id']);
        $full->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $msInv2 = FinDocument::findOrFail($m->refresh()->invoice_id);
        $this->assertNotSame($msInv->id, $msInv2->id);
        $this->postJson(route('m.status', ['fin', $full->id]), ['status' => 'مرسلة'])
            ->assertStatus(422)->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'فواتيرُ دفعاتٍ حيّة'));
        $this->assertSame('ملغاة', $full->refresh()->state, 'أُحييت الكاملةُ فوق فاتورةِ دفعةٍ حيّة');
        // وماتت الدفعةُ → تُحيا الكاملة
        $msInv2->update(['state' => 'ملغاة']);
        $this->postJson(route('m.status', ['fin', $full->id]), ['status' => 'مرسلة'])->assertOk();
        $this->assertSame('مرسلة', $full->refresh()->state);
        $this->assertTrue($q->refresh()->hasLiveFullInvoice());
    }

    /** الطريقُ المسدود: فاتورةُ المعلم ماتت (ملغاة/محذوفة) → يُعاد السكُّ بتاريخٍ محفوظ، ولا يُعاد فوق حيّة */
    public function test_milestone_is_reinvoiced_after_its_invoice_is_cancelled_or_soft_deleted(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $first = FinDocument::findOrFail($m->refresh()->invoice_id);

        // أُلغيت → الإشارةُ تعود، والزرُّ يعود، والسكُّ يجب أن يُجاب لا أن يُردّ إلى الملغاة
        $first->update(['state' => 'ملغاة']);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys());
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertSee('ms.invoice');

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])
            ->assertRedirect()->assertRedirectContains('/fin/');
        $m->refresh();
        $this->assertNotSame($first->id, $m->invoice_id, 'أُعيد المعلمُ إلى فاتورته الملغاة بدل سكِّ بديل');
        $second = FinDocument::findOrFail($m->invoice_id);
        $this->assertSame($first->doc_no . '-2', $second->doc_no, 'رقمُ البديل لم يتفادَ رقمَ الملغاة');
        $this->assertSame([$first->id], $m->meta['prev_invoices'] ?? null, 'تاريخُ الفواتير السابقة لم يُحفَظ');
        $this->assertSame(2, $this->msInvoiceCount($q));
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'بقيت الإشارةُ بعد إعادة السكّ');

        // حُذفت الثانيةُ بنعومة → ثالثة، والتاريخُ يتراكم
        $second->delete();
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $m->refresh();
        $third = FinDocument::findOrFail($m->invoice_id);
        $this->assertSame($first->doc_no . '-3', $third->doc_no);
        $this->assertSame([$first->id, $second->id], $m->meta['prev_invoices'] ?? null);
        $this->assertSame(3, $this->msInvoiceCount($q));

        // فوق فاتورةٍ حيّة: لا رابعة — يُردّ إلى الحيّة
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])
            ->assertRedirect(route('m.show', ['fin', $third->id]));
        $this->assertSame(3, $this->msInvoiceCount($q));
        $this->assertSame($third->id, $m->refresh()->invoice_id);
    }

    /** عمى السقف: أربعون معلماً مفوتَراً أقدمَ لا تحجب معلماً أحدثَ بلا فاتورة */
    public function test_signal_is_not_blinded_by_forty_older_invoiced_milestones(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();

        for ($i = 1; $i <= 40; $i++) {
            $inv = FinDocument::create(['doc_no' => 'INV-BLIND-' . $i, 'kind' => 'فاتورة مبيعات', 'client_id' => $q->client_id,
                'date' => now()->toDateString(), 'due' => now()->addDays(14)->toDateString(),
                'amount' => 10, 'tax' => 0, 'total' => 10, 'paid' => 0, 'currency' => 'د.ك', 'state' => 'مرسلة']);
            $this->ms($q, ['title' => 'مفوتَر ' . $i, 'sort' => $i, 'reached_at' => now()->subDays(20), 'invoice_id' => $inv->id]);
        }
        $open = $this->ms($q, ['title' => 'الأحدثُ بلا فاتورة', 'sort' => 41, 'reached_at' => now()->subDays(5)]);

        $keys = $this->keys();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $open->id, $keys, 'حجبت المعالمُ المفوتَرةُ الأقدمُ المعلمَ المفتوح');
        $this->assertCount(1, $keys, 'رُصد معلمٌ مفوتَر');
    }

    /** عدسةُ المشروع: العرضُ المحوَّل يحمل مشروعَه في meta.project_id — يُرى من صفحة المشروع */
    public function test_project_lens_sees_milestones_of_converted_quotes(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p1 = Project::create(['name' => 'مشروعٌ محوَّلٌ إليه', 'status' => 'قيد التنفيذ']);
        $p2 = Project::create(['name' => 'مشروعٌ آخر', 'status' => 'قيد التنفيذ']);
        $converted = $this->quote('محوّل', ['meta' => ['project_id' => $p1->id, 'converted_at' => now()->toIso8601String()]]);
        $columned  = $this->quote('مقبول', ['project_id' => $p2->id]);
        $mConv = $this->ms($converted, ['reached_at' => now()->subDays(5)]);
        $mCol  = $this->ms($columned, ['reached_at' => now()->subDays(5)]);
        $this->assertNull($converted->project_id, 'الاختبارُ يفترض أنّ التحويل لا يكتب العمود');

        $k1 = $this->keys($p1->id);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $mConv->id, $k1, 'عدسةُ المشروع لا ترى معلمَ العرض المحوَّل إليه');
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $mCol->id, $k1, 'تسرّب معلمُ مشروعٍ آخرَ إلى العدسة');

        $k2 = $this->keys($p2->id);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $mCol->id, $k2);
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $mConv->id, $k2);
    }

    /** معلمٌ بلا قيمة: لا إيرادَ يُحصَّل فلا إشارةَ ولا زرَّ سكّ */
    public function test_zero_value_milestone_is_neither_signalled_nor_offered_for_invoicing(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $zero = $this->ms($q, ['pct' => null, 'amount' => null, 'reached_at' => now()->subDays(9)]);

        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $zero->id, $this->keys(), 'رُصد معلمٌ بلا قيمة — توصيةٌ لا تُحَلّ بفعلها');
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertDontSee('ms.invoice')->assertSee('ms.unreach');
    }

    /** زرُّ السكّ يحتاج quotes:e (المسارُ يرفض بدونه)، ورقمُ الفاتورة يحتاج fin:v */
    public function test_invoice_button_needs_quotes_edit_and_invoice_number_needs_fin_view(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);
        $open = $this->ms($q, ['title' => 'مفتوحة', 'sort' => 2, 'reached_at' => now()->subDays(5)]);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $docNo = FinDocument::findOrFail($m->refresh()->invoice_id)->doc_no;

        // محاسبٌ يرى العروضَ ويُنشئ المستندات لكنه لا يعدّل العروض: المسارُ 403 فلا يُعرَض الزرّ
        $acct = User::create(['name' => 'محاسب', 'email' => 'acct-r2@test.local', 'password' => 'Secret!2026x', 'status' => 'نشط',
            'password_changed_at' => now(), 'role_id' => Role::create(['name' => 'محاسب', 'scope' => 'all', 'flags' => [],
                'matrix' => ['quotes' => ['v' => 1], 'fin' => ['v' => 1, 'a' => 1]]])->id]);
        $this->actingAs($acct)->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $open->id])->assertForbidden();
        $this->actingAs($acct)->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertDontSee('ms.invoice')->assertSee($docNo);

        // محرّرُ عروضٍ بلا رؤيةٍ للماليّة: يعلم أنّ الدفعةَ فُوترت ولا يرى رقمَ المستند ولا رابطَه
        $sales = User::create(['name' => 'مندوب', 'email' => 'sales-r2@test.local', 'password' => 'Secret!2026x', 'status' => 'نشط',
            'password_changed_at' => now(), 'role_id' => Role::create(['name' => 'مندوب', 'scope' => 'all', 'flags' => [],
                'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]])->id]);
        $this->actingAs($sales)->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertDontSee($docNo)->assertDontSee('/fin/' . $m->invoice_id)->assertSee('فاتورةٌ مسكوكة')->assertDontSee('ms.invoice');
    }

    /**
     * سقفُ العقد: مجموعُ فواتير الدفعات الحيّة لا يتجاوز إجماليَّ العرض — جدولٌ أُسيء
     * بناؤه (٦٠٠ + ٦٠٠ على ١٠٠٠) لا يُفوتِر ١٢٠٠. الدفعةُ المتجاوِزة تُقصّ إلى المتبقّي
     * بإعلانٍ صريح، ومتى لم يبقَ شيءٌ يُرفض السكُّ وتصمت الإشارةُ ويختفي الزرّ —
     * وحين تموت فاتورةٌ يعود المتبقّي.
     */
    public function test_milestone_invoices_never_exceed_the_quote_total(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $a = $this->ms($q, ['title' => 'أولى', 'pct' => null, 'amount' => 600, 'sort' => 1, 'reached_at' => now()->subDays(5)]);
        $b = $this->ms($q, ['title' => 'ثانية', 'pct' => null, 'amount' => 600, 'sort' => 2, 'reached_at' => now()->subDays(5)]);
        $c = $this->ms($q, ['title' => 'ثالثة', 'pct' => null, 'amount' => 100, 'sort' => 3, 'reached_at' => now()->subDays(5)]);

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $this->assertEquals(600, (float) FinDocument::findOrFail($a->refresh()->invoice_id)->total);

        // الثانيةُ تتجاوز: تُقصّ إلى المتبقّي (٤٠٠) بإعلان، وضريبتُها بنسبة العرض من المقصوص
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $b->id])
            ->assertRedirect()->assertSessionHas('ok', fn ($msg) => str_contains((string) $msg, 'المتبقّي'));
        $invB = FinDocument::findOrFail($b->refresh()->invoice_id);
        $this->assertEquals(400, (float) $invB->total, 'فُوترت الدفعةُ فوق إجماليّ العرض');
        $this->assertEquals(40, (float) $invB->tax);
        $this->assertEquals(360, (float) $invB->amount);
        $this->assertStringContainsString('المتبقّي', (string) $invB->description);
        $this->assertEquals(1000, (float) FinDocument::query()->whereIn('id', [$a->invoice_id, $b->invoice_id])->sum('total'));

        // لم يبقَ شيء: الثالثةُ تُرفض، والإشارةُ لا تُشير إليها، والزرُّ لا يُعرَض
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $c->id])->assertStatus(422);
        $this->assertNull($c->refresh()->invoice_id);
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $c->id, $this->keys(), 'الإشارةُ تطلب سكَّ ما لا يُسكّ');
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertDontSee('ms.invoice');

        // ماتت الأولى (٦٠٠) → عاد المتبقّي: الثالثةُ تُشار إليها وتُسكّ كاملةً (١٠٠ ≤ ٦٠٠)
        FinDocument::findOrFail($a->invoice_id)->update(['state' => 'ملغاة']);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $c->id, $this->keys());
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $c->id])->assertRedirect();
        $this->assertEquals(100, (float) FinDocument::findOrFail($c->refresh()->invoice_id)->total);
    }

    /**
     * الفاتورةُ المُستعادة: فاتورةُ معلمٍ أُلغيت فسُكّ بدلُها (وبقيت في `meta.prev_invoices`)،
     * ثم طُلب إحياؤها من الماليّة (حالةٌ تُرجَع من الكانبان أو بالجملة، أو استعادةٌ من
     * السلّة). حارسُ السكّ وحده كان يحرس بابَ الدخول ويترك بابَ العودة مفتوحاً: تصير
     * للمعلم فاتورتان حيّتان (١٢٠٠ على ١٠٠٠) لا يمنعهما شيءٌ ولا يقولهما أحد.
     *
     * فالإحياءُ يمرّ بالحارس نفسِه: يُرفض ما دامت للمعلم فاتورةٌ حيّةٌ أخرى، أو كان
     * يرفع مجموعَ الدفعات الحيّة فوق إجماليّ العرض — وبرسالةٍ تقول ماذا يُلغى أولاً.
     * ومتى صارت هي الوحيدةَ عادت، وعُدّت في كلّ قارئ وإن لم تكن `invoice_id` الحاليّ.
     */
    public function test_a_resurrected_previous_milestone_invoice_is_guarded_then_counts_everywhere(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $a = $this->ms($q, ['title' => 'أولى', 'pct' => null, 'amount' => 600, 'sort' => 1, 'reached_at' => now()->subDays(5)]);
        $c = $this->ms($q, ['title' => 'ثانية', 'pct' => null, 'amount' => 400, 'sort' => 2, 'reached_at' => now()->subDays(5)]);
        $d = $this->ms($q, ['title' => 'ثالثة', 'pct' => null, 'amount' => 100, 'sort' => 3, 'reached_at' => now()->subDays(5)]);

        // سُكّت الأولى، أُلغيت، فسُكّ بدلُها
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $inv1 = FinDocument::findOrFail($a->refresh()->invoice_id);
        $inv1->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $inv2 = FinDocument::findOrFail($a->refresh()->invoice_id);
        $this->assertSame([$inv1->id], $a->meta['prev_invoices'] ?? null);

        // إحياءُ الملغاة والبديلُ حيّ: مرفوضٌ من الكانبان (بالرسالة) ومن الإجراء الجماعيّ — لا ١٢٠٠ على ١٠٠٠
        $this->postJson(route('m.status', ['fin', $inv1->id]), ['status' => 'مرسلة'])
            ->assertStatus(422)->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'فاتورةٌ حيّةٌ أخرى') && str_contains((string) $msg, $inv2->doc_no));
        $this->assertSame('ملغاة', $inv1->refresh()->state, 'أُحييت فاتورةٌ سابقةٌ فوق البديل الحيّ');
        $this->post(route('m.bulk', 'fin'), ['do' => 'status', 'ids' => [$inv1->id], 'status' => 'مرسلة'])
            ->assertRedirect()->assertSessionHas('err', fn ($msg) => str_contains((string) $msg, $inv1->doc_no));
        $this->assertSame('ملغاة', $inv1->refresh()->state, 'الإجراءُ الجماعيُّ التفّ على الحارس');
        $this->assertEquals(600, $q->refresh()->liveMilestoneInvoicedTotal());

        // أُلغي البديلُ → تُحيا السابقة (هي الوحيدةُ الآن)، وتُعدّ في كلّ قارئ وإن لم تكن `invoice_id`
        $inv2->update(['state' => 'ملغاة']);
        $this->post(route('m.status', ['fin', $inv1->id]), ['status' => 'مرسلة'])->assertSuccessful();
        $this->assertSame('مرسلة', $inv1->refresh()->state);
        $this->assertEquals(600, $q->refresh()->liveMilestoneInvoicedTotal(), 'الفاتورةُ المُستعادةُ لا تُحسَب في المجموع');
        $this->assertTrue($q->hasLiveMilestoneInvoice(), 'فاتورةُ دفعةٍ حيّةٌ لا يراها القارئ');
        $this->post("/quote/{$q->id}/act", ['do' => 'invoice'])->assertStatus(422);
        $this->assertNull($q->refresh()->meta['invoice_id'] ?? null, 'سُكّت الكاملةُ فوق فاتورةِ دفعةٍ حيّةٍ مُستعادة');
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertDontSee('value="invoice"', false)->assertSee('يُفوتَر بالدفعات')
            ->assertSee($inv1->doc_no)->assertDontSee('value="' . $a->id . '"', false);
        // إعادةُ سكّ الأولى تُردّ إلى فاتورتها الحيّة (المُستعادة) لا إلى الملغاة ولا إلى ثالثة
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])
            ->assertRedirect(route('m.show', ['fin', $inv1->id]));
        $this->assertSame(2, $this->msInvoiceCount($q));
        $keys = $this->keys();
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $a->id, $keys, 'مُعلمٌ بفاتورةٍ حيّةٍ مُستعادةٍ يُطلَب سكُّه');
        $this->assertArrayHasKey('milestone.uninvoiced:' . $c->id, $keys, 'المتبقّي ٤٠٠ ولا إشارةَ للثانية');
        // المتبقّي ٤٠٠: الثانيةُ تُسكّ كاملةً، ثم لا يبقى شيءٌ للثالثة
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $c->id])->assertRedirect();
        $inv3 = FinDocument::findOrFail($c->refresh()->invoice_id);
        $this->assertEquals(400, (float) $inv3->total);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $d->id])->assertStatus(422);

        // السلّة: حُذفت المُستعادةُ بنعومة → عاد ٦٠٠ فسُكّ للأولى بديلٌ رابع — فلا تُستعاد من السلّة فوقه
        $this->delete(route('m.destroy', ['fin', $inv1->id]))->assertRedirect();
        $this->assertNotNull(FinDocument::withTrashed()->findOrFail($inv1->id)->deleted_at, 'الاختبارُ يفترض حذفاً بنعومة');
        $this->assertArrayHasKey('milestone.uninvoiced:' . $a->id, $this->keys());
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $inv4 = FinDocument::findOrFail($a->refresh()->invoice_id);
        $this->assertEquals(600, (float) $inv4->total);
        $this->assertSame([$inv1->id, $inv2->id], $a->meta['prev_invoices'] ?? null);
        $this->post(route('m.restore', ['fin', $inv1->id]))->assertRedirect()->assertSessionHasErrors('state');
        $this->assertNotNull(FinDocument::withTrashed()->findOrFail($inv1->id)->deleted_at, 'استُعيدت من السلّة فوق بديلٍ حيّ — ١٦٠٠ على ١٠٠٠');
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());

        // أُلغي الرابعُ → تُستعاد من السلّة (الوحيدةُ للمعلم، والسقفُ يسعها: ٤٠٠ + ٦٠٠)
        $inv4->update(['state' => 'ملغاة']);
        $this->post(route('m.restore', ['fin', $inv1->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(FinDocument::withTrashed()->findOrFail($inv1->id)->deleted_at);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal(), 'المُستعادةُ من السلّة لا تُحسَب');
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $d->id])->assertStatus(422);
        $this->assertNull($d->refresh()->invoice_id);
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $d->id, $this->keys());
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertDontSee('ms.invoice')->assertSee('تغطّي إجماليَّ العرض');

        // سقفُ العقد عند الإحياء: لا فاتورةَ أخرى للمعلم، لكنّ الإحياءَ يرفع المجموعَ فوق الإجماليّ (٤٠٠ + ١٠٠ + ٦٠٠)
        $inv1->refresh()->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $d->id])->assertRedirect();
        $inv5 = FinDocument::findOrFail($d->refresh()->invoice_id);
        $this->assertEquals(500, $q->refresh()->liveMilestoneInvoicedTotal());
        $this->postJson(route('m.status', ['fin', $inv1->id]), ['status' => 'مرسلة'])
            ->assertStatus(422)->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'إجماليّ العرض'));
        $this->assertSame('ملغاة', $inv1->refresh()->state, 'أُحييت فاتورةٌ فوق سقف العقد');
        $inv5->update(['state' => 'ملغاة']);
        $this->postJson(route('m.status', ['fin', $inv1->id]), ['status' => 'مرسلة'])->assertOk();
        $this->assertSame('مرسلة', $inv1->refresh()->state);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());
    }

    /**
     * عرضٌ إجماليُّه صفر (لم يُسعَّر) ومعلمٌ بمبلغٍ صريح: السكُّ مرفوضٌ (سقفُ العقد صفر)
     * — لكنّ الرفضَ والشاشةَ كانا يقولان «فواتيرُ الدفعات الحيّةُ تغطّي الإجماليّ —
     * أَلغِ إحداها» ولا فاتورةَ هناك أصلاً. السببُ الحقيقيُّ يُقال: الإجماليُّ صفر.
     */
    public function test_zero_total_quote_explains_itself_instead_of_blaming_nonexistent_invoices(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote('مقبول', ['total' => 0, 'tax' => 0, 'amount' => 0]);
        $m = $this->ms($q, ['pct' => null, 'amount' => 500, 'reached_at' => now()->subDays(5)]);

        try {
            $this->withoutExceptionHandling()->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id]);
            $this->fail('سُكّت دفعةٌ على عرضٍ إجماليُّه صفر');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('صفر', $e->getMessage(), 'الرفضُ لا يذكر السببَ الحقيقيّ');
            $this->assertStringNotContainsString('أَلغِ', $e->getMessage(), 'الرفضُ يطلب إلغاءَ فاتورةٍ لا وجودَ لها');
        }
        $this->assertNull($m->refresh()->invoice_id);
        $this->assertSame(0, $this->msInvoiceCount($q));
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $m->id, $this->keys());
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertDontSee('ms.invoice')
            ->assertDontSee('تغطّي إجماليَّ العرض')->assertSee('إجماليُّ العرض صفر');
    }

    private function liveInvoice(Quote $q, string $docNo, float $total = 10): FinDocument
    {
        return FinDocument::create(['doc_no' => $docNo, 'kind' => 'فاتورة مبيعات', 'client_id' => $q->client_id,
            'date' => now()->toDateString(), 'due' => now()->addDays(14)->toDateString(),
            'amount' => $total, 'tax' => 0, 'total' => $total, 'paid' => 0, 'currency' => 'د.ك', 'state' => 'مرسلة']);
    }

    /**
     * عمى السقف — الوجهُ الثاني: الاستبعاداتُ التي كانت تُحسَب في PHP بعد الجلب (عرضٌ
     * مفوتَرٌ كاملاً، عرضٌ استوفى سقفَه، معلمٌ عادت سابقتُه حيّة) تتراكم في أقدم
     * الصفوف ولا تُصنَّف أبداً، فتستنفد صفحاتِ المسح (٤٠ × ٥) ويُحجَب معلمٌ أحدثُ
     * سكُّه يُنجَز. الاستبعادُ يُنقَل إلى الاستعلام نفسه فلا يُنفِق مرشَّحٌ لا يُشار
     * إليه شيئاً من ميزانيّة المسح.
     */
    public function test_signal_is_not_starved_by_two_hundred_older_rows_excluded_after_fetch(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // ٣ عروضٍ مفوتَرةٍ كاملاً بفاتورةٍ حيّة، في كلٍّ ٤٠ معلماً بُلغ قبل ٢٠ يوماً بلا فاتورة
        for ($k = 1; $k <= 3; $k++) {
            $q = $this->quote();
            $q->update(['meta' => ['invoice_id' => $this->liveInvoice($q, 'INV-FULL-' . $k, 1000)->id]]);
            for ($i = 1; $i <= 40; $i++) $this->ms($q, ['title' => 'مفوتَرٌ كاملاً ' . $i, 'pct' => 1, 'sort' => $i, 'reached_at' => now()->subDays(20)]);
        }
        // عرضان استوفى كلٌّ منهما سقفَه بفاتورةِ دفعةٍ حيّة (١٠٠٠/١٠٠٠)، وفي كلٍّ ٤٠ معلماً بُلغ بلا فاتورة
        for ($k = 1; $k <= 2; $k++) {
            $q = $this->quote();
            $this->ms($q, ['title' => 'الكاملة', 'pct' => null, 'amount' => 1000, 'sort' => 0, 'reached_at' => now()->subDays(25),
                'invoice_id' => $this->liveInvoice($q, 'INV-CAP-' . $k, 1000)->id]);
            for ($i = 1; $i <= 40; $i++) $this->ms($q, ['title' => 'فوق السقف ' . $i, 'pct' => 1, 'sort' => $i, 'reached_at' => now()->subDays(20)]);
        }
        // ومعلمٌ ماتت فاتورتُه الحاليّةُ وعادت سابقتُه حيّة (مفوتَرٌ بها)
        $qPrev = $this->quote();
        $mPrev = $this->ms($qPrev, ['title' => 'سابقتُه حيّة', 'pct' => null, 'amount' => 600, 'reached_at' => now()->subDays(20)]);
        $this->post("/quote/{$qPrev->id}/act", ['do' => 'ms.invoice', 'ms' => $mPrev->id])->assertRedirect();
        $prevInv = FinDocument::findOrFail($mPrev->refresh()->invoice_id);
        $prevInv->update(['state' => 'ملغاة']);
        $this->post("/quote/{$qPrev->id}/act", ['do' => 'ms.invoice', 'ms' => $mPrev->id])->assertRedirect();
        FinDocument::findOrFail($mPrev->refresh()->invoice_id)->update(['state' => 'ملغاة']);
        $prevInv->update(['state' => 'مرسلة']);
        $this->assertSame($prevInv->id, $mPrev->refresh()->liveInvoiceId(), 'الاختبارُ يفترض سابقةً حيّة');

        // الأحدث: معلمٌ مكشوفٌ فعلاً على عرضٍ سليم — سكُّه يُنجَز، فيجب أن يُشار إليه
        $qOpen = $this->quote();
        $open = $this->ms($qOpen, ['title' => 'الأحدثُ المكشوف', 'reached_at' => now()->subDays(5)]);

        $keys = $this->keys();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $open->id, $keys, 'استنفدت الصفوفُ المستبعَدةُ بعد الجلب ميزانيّةَ المسح فحُجب المعلمُ المكشوف');
        $this->assertCount(1, $keys, 'رُصد معلمٌ لا يُسكّ');
        $this->post("/quote/{$qOpen->id}/act", ['do' => 'ms.invoice', 'ms' => $open->id])
            ->assertRedirect()->assertRedirectContains('/fin/');
    }

    /**
     * قاعدةُ القيمة واحدة: نسبةٌ ضئيلةٌ تُقرَّب قيمتُها إلى صفر (٠٫٠٠١٪ من ٢٠) كان
     * المتحكّم يرفض سكَّها (المعلمُ بلا قيمة) بينما الزرُّ والإشارةُ — بحسابٍ غير
     * مقرَّب — يطالبان بها. الشاشةُ والإشارةُ تحسبان بقاعدة `amountDue` نفسها.
     */
    public function test_tiny_pct_that_rounds_to_zero_is_neither_offered_nor_nagged(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote('مقبول', ['total' => 20, 'tax' => 0, 'amount' => 20]);
        $m = $this->ms($q, ['pct' => 0.001, 'reached_at' => now()->subDays(5)]);
        $this->assertSame(0.0, $m->refresh()->amountDue($q), 'الاختبارُ يفترض قيمةً تُقرَّب إلى صفر');

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertStatus(422);
        $this->assertNull($m->refresh()->invoice_id);
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'الإشارةُ تطالب بسكٍّ يرفضه المتحكّم');
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()->assertDontSee('ms.invoice')->assertSee('ms.unreach');
    }

    /**
     * العرضُ في السلّة لا يُعطّل حارسَ الإحياء: قرّاءُ «فواتير دفعات العرض» يقرؤون
     * `DB::table` فيعدّون فواتيرَ عرضٍ محذوفٍ بنعومة (مطالبةٌ قائمةٌ للعميل)، وحارسُ
     * السكّ مغلقٌ على المحذوف (٤٠٤) — فبقي بابُ الإحياء وحدَه مفتوحاً: قراءةُ العرض
     * بنطاق SoftDeletes تُعيد لا شيء، فتُتخطّى فحوصُ السقف والفاتورة الكاملة بصمت.
     */
    public function test_revive_guard_still_holds_while_the_quote_is_in_the_trash(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // السقف: ٦٠٠ حيّة + إحياءُ ٦٠٠ على عرضٍ بألف — والعرضُ في السلّة
        $q = $this->quote();
        $a = $this->ms($q, ['title' => 'أولى', 'pct' => null, 'amount' => 600, 'sort' => 1, 'reached_at' => now()->subDays(5)]);
        $b = $this->ms($q, ['title' => 'ثانية', 'pct' => null, 'amount' => 600, 'sort' => 2, 'reached_at' => now()->subDays(5)]);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $invA = FinDocument::findOrFail($a->refresh()->invoice_id);
        $invA->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $b->id])->assertRedirect();
        $this->assertEquals(600, (float) FinDocument::findOrFail($b->refresh()->invoice_id)->total);
        $this->delete(route('m.destroy', ['quotes', $q->id]))->assertRedirect();
        $this->assertNotNull(Quote::withTrashed()->findOrFail($q->id)->deleted_at, 'الاختبارُ يفترض حذفاً بنعومة');

        $this->postJson(route('m.status', ['fin', $invA->id]), ['status' => 'مرسلة'])
            ->assertStatus(422)->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'إجماليّ العرض'));
        $this->assertSame('ملغاة', $invA->refresh()->state, 'أُحييت فوق السقف لأنّ العرضَ في السلّة');
        $this->post(route('m.bulk', 'fin'), ['do' => 'status', 'ids' => [$invA->id], 'status' => 'مرسلة'])->assertRedirect()->assertSessionHas('err');
        $this->assertSame('ملغاة', $invA->refresh()->state);
        $this->assertEquals(600, Quote::withTrashed()->findOrFail($q->id)->liveMilestoneInvoicedTotal());

        // الكاملةُ فوق دفعةٍ حيّة — والعرضُ في السلّة
        $q2 = $this->quote();
        $m = $this->ms($q2, ['reached_at' => now()->subDays(5)]);
        $this->post("/quote/{$q2->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        FinDocument::findOrFail($m->refresh()->invoice_id)->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q2->id}/act", ['do' => 'invoice'])->assertRedirect();
        $full = FinDocument::findOrFail($q2->refresh()->meta['invoice_id']);
        $full->update(['state' => 'ملغاة']);
        $this->post("/quote/{$q2->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $this->assertTrue($q2->refresh()->hasLiveMilestoneInvoice());
        $this->delete(route('m.destroy', ['quotes', $q2->id]))->assertRedirect();

        $this->post(route('m.restore', ['fin', $full->id]));   // ليست في السلّة — لا أثر
        $this->postJson(route('m.status', ['fin', $full->id]), ['status' => 'مرسلة'])
            ->assertStatus(422)->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'فواتيرُ دفعاتٍ حيّة'));
        $this->assertSame('ملغاة', $full->refresh()->state, 'أُحييت الكاملةُ فوق دفعةٍ حيّة لأنّ العرضَ في السلّة');

        // عاد العرضان من السلّة والثوابتُ قائمة
        $this->post(route('m.restore', ['quotes', $q->id]))->assertRedirect();
        $this->post(route('m.restore', ['quotes', $q2->id]))->assertRedirect();
        $this->assertEquals(600, $q->refresh()->liveMilestoneInvoicedTotal());
        $this->assertFalse($q2->refresh()->hasLiveFullInvoice());
        $this->assertTrue($q2->hasLiveMilestoneInvoice());
    }

    /**
     * السقفُ ليس فحصَ سكٍّ فحسب: رفعُ قيمةِ فاتورةِ دفعةٍ **حيّة** من نموذج التعديل
     * (أو أيِّ بابٍ ينتهي بـ`save`) كان يرفع مجموعَ الدفعات الحيّة فوق إجماليّ العرض
     * بلا حارس (٤٠٠ → ٩٠٠ على ٦٠٠ حيّةٍ وعرضٍ بألف = ١٥٠٠). الخفضُ حرّ، والرفعُ في
     * حدود المتبقّي حرّ، والرفعُ فوقه مرفوض.
     */
    public function test_raising_a_live_milestone_invoice_total_cannot_exceed_the_quote_total(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $a = $this->ms($q, ['title' => 'أولى', 'pct' => null, 'amount' => 600, 'sort' => 1, 'reached_at' => now()->subDays(5)]);
        $c = $this->ms($q, ['title' => 'ثانية', 'pct' => null, 'amount' => 400, 'sort' => 2, 'reached_at' => now()->subDays(5)]);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $a->id])->assertRedirect();
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $c->id])->assertRedirect();
        $invA = FinDocument::findOrFail($a->refresh()->invoice_id);
        $invC = FinDocument::findOrFail($c->refresh()->invoice_id);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());

        try {
            $invC->update(['total' => 900]);
            $this->fail('رُفعت قيمةُ فاتورةِ دفعةٍ حيّةٍ فوق إجماليّ العرض');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('إجماليّ العرض', collect($e->errors())->flatten()->first());
        }
        $this->assertEquals(400, (float) $invC->refresh()->total);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());

        // الخفضُ حرّ، ثم الرفعُ في حدود المتبقّي حرّ (٣٠٠ → ٤٠٠ على ٦٠٠ حيّة)
        $invC->update(['total' => 300]);
        $this->assertEquals(900, $q->refresh()->liveMilestoneInvoicedTotal());
        $invC->update(['total' => 400]);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());
        // وماتت الأولى → يُرفَع حتى الإجماليّ كلِّه
        $invA->update(['state' => 'ملغاة']);
        $invC->update(['total' => 1000]);
        $this->assertEquals(1000, $q->refresh()->liveMilestoneInvoicedTotal());
    }

    /**
     * صورةُ القراءة (InnoDB REPEATABLE READ): أوّلُ قراءةٍ غيرِ قافلةٍ في المعاملة تثبّت
     * صورتَها، وكلُّ قراءةٍ غيرِ قافلةٍ بعدها — ولو بعد قفلِ صفّ العرض — تُجيب من تلك
     * الصورة. فسكٌّ متزامنٌ أُودع بينما الإحياءُ ينتظر القفلَ كان **غيرَ مرئيٍّ** لقرارات
     * الحارس: فاتورتان حيّتان لمعلمٍ واحد. قراءاتُ القرار يجب أن تكون قافلةً (ترى
     * الأحدثَ المودَع دائماً). تُحاكى بمعاملةٍ ثبّتت صورتَها قبل أن يُودِع اتصالٌ ثانٍ
     * السكَّ كلَّه — لا يعمل على SQLite (لا صورَ قراءةٍ ولا أقفالَ صفوف).
     */
    public function test_guard_decision_reads_see_a_mint_committed_after_the_snapshot_on_mysql(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('صورةُ القراءة وقفلُ الصفوف خاصّيّةُ InnoDB');
        }
        $this->seedCore();
        $this->actingAs($this->owner);
        // اتصالُ الاختبار (داخل معاملة RefreshDatabase) يثبّت صورتَه الآن
        $this->assertSame(0, Quote::query()->count());

        config(['database.connections.mysql_b' => config('database.connections.mysql')]);
        $b = \Illuminate\Support\Facades\DB::connection('mysql_b');
        $ids = ['q' => (string) \Illuminate\Support\Str::uuid(), 'm' => (string) \Illuminate\Support\Str::uuid(),
            'inv1' => (string) \Illuminate\Support\Str::uuid(), 'inv2' => (string) \Illuminate\Support\Str::uuid()];
        $now = now()->toDateTimeString();
        // التنظيفُ بعد تراجع معاملة الاختبار لا قبلَه: قراءاتُ الحارس القافلةُ تحجز الصفوفَ حتى التراجع
        $this->beforeApplicationDestroyed(function () use ($b, $ids) {
            $b->table('fin_documents')->whereIn('id', [$ids['inv1'], $ids['inv2']])->delete();
            $b->table('quote_milestones')->where('id', $ids['m'])->delete();
            $b->table('quotes')->where('id', $ids['q'])->delete();
        });
        $b->statement('set session innodb_lock_wait_timeout = 3');
        {
            // اتصالٌ ثانٍ يُودِع بعد الصورة: عرضٌ ومعلمٌ وفاتورةٌ ملغاة، ثم بديلٌ حيٌّ — كما يفعل سكٌّ متزامن
            $b->table('quotes')->insert(['id' => $ids['q'], 'doc_no' => 'Q-SNAP', 'title' => 'عرض', 'status' => 'مقبول', 'total' => 1000, 'currency' => 'د.ك', 'created_at' => $now, 'updated_at' => $now]);
            $fin = fn (string $id, string $no, string $state) => ['id' => $id, 'doc_no' => $no, 'kind' => 'فاتورة مبيعات', 'total' => 600, 'amount' => 600, 'tax' => 0, 'paid' => 0,
                'state' => $state, 'currency' => 'د.ك', 'meta' => json_encode(['quote_id' => $ids['q'], 'milestone_id' => $ids['m']]), 'created_at' => $now, 'updated_at' => $now];
            $b->table('fin_documents')->insert($fin($ids['inv1'], 'INV-SNAP-1', 'ملغاة'));
            $b->table('fin_documents')->insert($fin($ids['inv2'], 'INV-SNAP-2', 'مرسلة'));
            $b->table('quote_milestones')->insert(['id' => $ids['m'], 'quote_id' => $ids['q'], 'title' => 'دفعة', 'amount' => 600, 'sort' => 1,
                'reached_at' => $now, 'invoice_id' => $ids['inv2'], 'meta' => json_encode(['prev_invoices' => [$ids['inv1']]]), 'created_at' => $now, 'updated_at' => $now]);

            // الصورةُ القديمة لا ترى شيئاً من ذلك — وهذا عينُ الفخّ؛ والقراءةُ القافلةُ تراه
            $this->assertNull(FinDocument::find($ids['inv1']), 'الاختبارُ يفترض صورةَ قراءةٍ ثابتة');
            $doc = FinDocument::withTrashed()->lockForUpdate()->findOrFail($ids['inv1']);
            $doc->state = 'مرسلة';
            try {
                $doc->save();
                $this->fail('أُحييت فاتورةٌ فوق بديلٍ حيٍّ أُودع بعد الصورة — الحارسُ قرأ صورةً قديمة');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('فاتورةٌ حيّةٌ أخرى', collect($e->errors())->flatten()->first());
            }
            $this->assertSame('ملغاة', $b->table('fin_documents')->where('id', $ids['inv1'])->value('state'));
        }
    }
}
