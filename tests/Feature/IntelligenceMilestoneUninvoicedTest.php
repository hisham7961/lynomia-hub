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
 * طبقةُ الذكاء — معلمُ دفعٍ بُلغ ولم يُفوتَر (v2.399): البندُ الذي كان مؤجَّلاً
 * بصدقٍ لأن `quote_milestones` لم يحمل حالةَ «بُلغ» ولا رابطَ فاتورة.
 *
 * البنيةُ أُضيفت لا اختُلقت: البلوغُ فعلٌ بشريٌّ مسجَّل (`ms.reach`)، والفاتورةُ
 * تُسكّ من المعلم (`ms.invoice`) بتصليب `toInvoice` نفسِه (fin:a، معاملةٌ على صفٍّ
 * مقفول، لا فاتورتان). والإشارةُ ١٥ تُرصَد بعد ٣ أيامٍ من البلوغ بلا فاتورةٍ حيّة،
 * وتنطفئ وحدها بالفوترة وتعود إن أُلغيت — منطَّقةً بعروضها.
 */
class IntelligenceMilestoneUninvoicedTest extends TestCase
{
    private function quote(string $status = 'مقبول', array $extra = []): Quote
    {
        $client = Client::create(['name' => 'عميل ' . uniqid(), 'stage' => 'عميل حالي']);

        return Quote::create(array_merge(['doc_no' => 'Q-MS-' . strtoupper(substr(uniqid(), -5)), 'title' => 'عرضٌ بجدول',
            'status' => $status, 'accepted_at' => $status === 'مقبول' ? now()->subDays(10) : null,
            'client_id' => $client->id, 'total' => 1000, 'tax' => 100, 'amount' => 900, 'currency' => 'د.ك'], $extra));
    }

    private function ms(Quote $q, array $attrs = []): QuoteMilestone
    {
        return QuoteMilestone::create(array_merge(['quote_id' => $q->id, 'title' => 'دفعةٌ أولى', 'pct' => 30, 'sort' => 1], $attrs));
    }

    private function keys(): array
    {
        return collect(hub_recommendations(true)['items'])->filter(fn ($i) => str_starts_with((string) $i['key'], 'milestone.uninvoiced:'))->keyBy('key')->all();
    }

    /* ────────── الإشارة ────────── */

    public function test_signal_fires_after_three_days_and_escalates_after_seven(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();

        $fresh   = $this->ms($q, ['title' => 'بُلغ أمس', 'reached_at' => now()->subDay()]);
        $due     = $this->ms($q, ['title' => 'بُلغ منذ ٤ أيام', 'reached_at' => now()->subDays(4), 'sort' => 2]);
        $late    = $this->ms($q, ['title' => 'بُلغ منذ ٩ أيام', 'reached_at' => now()->subDays(9), 'sort' => 3]);
        $unreach = $this->ms($q, ['title' => 'لم يُبلَغ', 'sort' => 4]);
        $draftMs = $this->ms($this->quote('مسودة'), ['title' => 'في مسودة', 'reached_at' => now()->subDays(9)]);

        $items = $this->keys();
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $fresh->id, $items, 'رُصد معلمٌ بُلغ أمس — قبل مهلة الثلاثة أيام');
        $this->assertArrayHasKey('milestone.uninvoiced:' . $due->id, $items, 'معلمٌ بُلغ منذ ٤ أيامٍ بلا فاتورة لم يُرصَد');
        $this->assertSame('مهم', $items['milestone.uninvoiced:' . $due->id]['sev']);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $late->id, $items);
        $this->assertSame('حرج', $items['milestone.uninvoiced:' . $late->id]['sev'], 'تسعةُ أيامٍ بلا فاتورة ليست «حرجاً»');
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $unreach->id, $items, 'اختُلقت إشارةٌ لمعلمٍ لم يُبلَغ');
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $draftMs->id, $items, 'معلمُ عرضٍ غيرِ مقبولٍ ليس التزاماً');

        // السببُ بالأرقام: القيمةُ بقاعدة الشاشة (٣٠٪ من ١٠٠٠) والأيام
        $this->assertStringContainsString('300.000', $items['milestone.uninvoiced:' . $due->id]['why']);
        $this->assertStringContainsString('4 يوماً', $items['milestone.uninvoiced:' . $due->id]['why']);
        $this->assertSame('quotes', $items['milestone.uninvoiced:' . $due->id]['module']);
        $this->assertSame($q->id, $items['milestone.uninvoiced:' . $due->id]['recordId']);
    }

    public function test_signal_clears_on_live_invoice_and_returns_when_invoice_dies(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys());

        // سُكّت الفاتورة → تنطفئ وحدها
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $m->refresh();
        $this->assertNotNull($m->invoice_id, 'لم يُربَط المعلمُ بفاتورته');
        Cache::flush();
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'بقيت الإشارةُ بعد الفوترة');

        // أُلغيت الفاتورة → تعود
        FinDocument::whereKey($m->invoice_id)->update(['state' => 'ملغاة']);
        Cache::flush();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'فاتورةٌ ملغاةٌ أطفأت الإشارة');
        $this->assertStringContainsString('أُلغيت أو حُذفت', $this->keys()['milestone.uninvoiced:' . $m->id]['why']);

        // حُذفت بنعومة → تبقى عائدة
        FinDocument::whereKey($m->invoice_id)->update(['state' => 'مرسلة']);
        FinDocument::find($m->invoice_id)->delete();
        Cache::flush();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'فاتورةٌ محذوفةٌ بنعومة أطفأت الإشارة');
    }

    public function test_signal_is_scoped_by_quote_visibility(): void
    {
        $this->seedCore();
        $mine  = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ']);
        $other = Project::create(['name' => 'مشروعُ غيري', 'status' => 'قيد التنفيذ']);
        $qMine  = $this->quote('محوّل', ['project_id' => $mine->id]);
        $qOther = $this->quote('محوّل', ['project_id' => $other->id]);
        $mMine  = $this->ms($qMine, ['reached_at' => now()->subDays(5)]);
        $mOther = $this->ms($qOther, ['reached_at' => now()->subDays(5)]);

        // قائدٌ محصورٌ بمشاريعه: يرى معلمَ عرضِ مشروعه فقط
        $lead = User::create(['name' => 'قائد', 'email' => 'lead@test.local', 'password' => bcrypt('x'),
            'role_id' => Role::create(['name' => 'قائد', 'scope' => 'proj', 'flags' => ['monitor' => 1],
                'matrix' => Role::first()->matrix])->id]);
        $mine->update(['manager_id' => $lead->id]);
        $this->actingAs($lead);
        $keys = $this->keys();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $mMine->id, $keys, 'القائدُ لا يرى معلمَ مشروعه');
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $mOther->id, $keys, 'تسرّب معلمُ عرضٍ خارجَ نطاق القائد');

        // مستخدمٌ بلا رؤيةٍ للعروض: لا إشارةَ إطلاقاً
        $this->actingAs($this->viewer);
        $matrix = Role::find($this->viewer->role_id)->matrix;
        unset($matrix['quotes']);
        Role::where('id', $this->viewer->role_id)->update(['matrix' => json_encode($matrix)]);
        Cache::flush();
        $this->assertEmpty($this->keys(), 'وصلت معالمُ العروض لمن لا يرى العروض');
    }

    /* ────────── الأفعال ────────── */

    public function test_reach_records_who_and_when_and_requires_acceptance(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q);

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.reach', 'ms' => $m->id])->assertRedirect();
        $m->refresh();
        $this->assertNotNull($m->reached_at);
        $this->assertSame($this->owner->id, $m->reached_by, 'لم يُسجَّل من أعلن البلوغ');
        $this->assertDatabaseHas('audits', ['action' => 'بلوغ معلم دفع', 'record_id' => $q->id]);

        // التراجع
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.unreach', 'ms' => $m->id])->assertRedirect();
        $this->assertNull($m->refresh()->reached_at);

        // عرضٌ مسودةٌ: جدولُه ليس التزاماً بعد
        $draft = $this->quote('مسودة');
        $dm = $this->ms($draft);
        $this->post("/quote/{$draft->id}/act", ['do' => 'ms.reach', 'ms' => $dm->id])->assertStatus(422);
        $this->assertNull($dm->refresh()->reached_at);

        // معلمٌ من عرضٍ آخر عبر عرضٍ مقبولٍ مغاير: 404 لا تسريب (المعلمُ يُقرأ بنطاق عرضه فقط)
        $other = $this->quote();
        $this->post("/quote/{$other->id}/act", ['do' => 'ms.reach', 'ms' => $m->id])->assertNotFound();
        $this->assertNull($m->refresh()->reached_at, 'بُلغ معلمٌ عبر عرضٍ لا يملكه');
    }

    public function test_milestone_invoice_is_minted_once_with_proportional_tax(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['pct' => 30, 'sort' => 2]);

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $m->refresh();
        $inv = FinDocument::find($m->invoice_id);
        $this->assertNotNull($inv, 'لم تُسكّ فاتورةُ المعلم');
        $this->assertSame('INV-' . $q->doc_no . '-M2', $inv->doc_no);
        $this->assertSame('فاتورة مبيعات', $inv->kind);
        $this->assertEquals(300.0, (float) $inv->total, 'قيمةُ الدفعة ≠ ٣٠٪ من الإجماليّ');
        $this->assertEquals(30.0, (float) $inv->tax, 'الضريبةُ ليست بنسبتها من العرض (١٠٪)');
        $this->assertEquals(270.0, (float) $inv->amount);
        $this->assertSame($q->client_id, $inv->client_id);
        $this->assertSame('مرسلة', $inv->state);
        $this->assertSame($q->id, $inv->meta['quote_id'] ?? null);
        $this->assertSame($m->id, $inv->meta['milestone_id'] ?? null);
        $this->assertNotNull($m->reached_at, 'السكُّ يُعلن البلوغَ ضمناً');
        $this->assertDatabaseHas('audits', ['action' => 'سكّ فاتورة معلم', 'record_id' => $q->id]);

        // نقرةٌ ثانية: لا فاتورةَ ثانية — تُعيد إلى الأولى
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])
            ->assertRedirect(route('m.show', ['fin', $inv->id]));
        $this->assertSame(1, FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '-M%')->count());

        // التراجعُ عن البلوغ ممنوعٌ ما دامت الفاتورةُ حيّة
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.unreach', 'ms' => $m->id])->assertStatus(422);
        $this->assertNotNull($m->refresh()->reached_at);

        // معلمٌ بلا قيمة: لا فاتورةَ صفريّة
        $zero = $this->ms($q, ['pct' => null, 'amount' => null, 'sort' => 3]);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $zero->id])->assertStatus(422);
        $this->assertNull($zero->refresh()->invoice_id);
    }

    public function test_milestone_invoice_doc_no_stays_unique_after_soft_delete(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m1 = $this->ms($q, ['sort' => 1]);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m1->id])->assertRedirect();
        $first = FinDocument::find($m1->refresh()->invoice_id);

        // حُذف المعلمُ وأُعيد بالترتيب نفسه (عرضٌ آخرُ بالرقم نفسه مستحيلٌ، لكن الترتيبَ يتكرّر)
        $m2 = $this->ms($q, ['sort' => 1, 'title' => 'دفعةٌ مُعادة']);
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m2->id])->assertRedirect();
        $second = FinDocument::find($m2->refresh()->invoice_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->doc_no . '-2', $second->doc_no, 'اصطدامُ رقم الفاتورة لم يُفَضّ');
    }

    public function test_quotes_editor_without_fin_cannot_mint_but_can_reach(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مندوب مبيعات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'مندوب', 'email' => 'sales@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $q = $this->quote();
        $m = $this->ms($q);

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'ms.reach', 'ms' => $m->id])->assertRedirect();
        $this->assertNotNull($m->refresh()->reached_at, 'محرّرُ العروض لا يُعلن البلوغ');

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertForbidden();
        $this->assertNull($m->refresh()->invoice_id);
        $this->assertSame(0, FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '%')->count(),
            'محرّرُ عروضٍ بلا صلاحية مالية سكّ فاتورةً تدخل MRR');

        // مستخدمٌ بلا تعديل العروض: لا بلوغَ أصلاً
        $this->actingAs($this->viewer)->post("/quote/{$q->id}/act", ['do' => 'ms.unreach', 'ms' => $m->id])->assertForbidden();
        $this->assertNotNull($m->refresh()->reached_at);
    }

    public function test_milestone_actions_render_only_after_acceptance(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $draft = $this->quote('مسودة');
        $this->ms($draft);
        $this->get(route('m.show', ['quotes', $draft->id]))->assertOk()
            ->assertDontSee('ms.reach')->assertDontSee('ms.invoice');

        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);
        $html = $this->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertSee('ms.invoice')->assertSee('ms.unreach')->assertSee('بُلغ ' . $m->reached_at->toDateString());

        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id]);
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertSee('INV-' . $q->doc_no . '-M1')->assertDontSee('ms.invoice')->assertDontSee('ms.unreach');
    }

    /**
     * لا ازدواجَ فوترة: العرضُ المفوتَر كاملاً (`do=invoice` القائم) إيرادُه مُطالَبٌ به،
     * فلا تُسكّ فاتورةُ دفعةٍ فوقه ولا تُرصَد إشارةُ «بلا فاتورة» لمعالمه — وحين تُلغى
     * الفاتورةُ الكاملة يعود المعلمُ مستحقّاً فتعود الإشارةُ ويُسمح بالسكّ.
     */
    public function test_no_milestone_invoice_nor_signal_over_a_live_full_invoice(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->quote();
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys());

        // فاتورةُ العرض الكاملة بالمسار القائم
        $this->post("/quote/{$q->id}/act", ['do' => 'invoice'])->assertRedirect();
        $full = FinDocument::where('doc_no', 'INV-' . $q->doc_no)->firstOrFail();
        $this->assertSame($full->id, $q->refresh()->meta['invoice_id']);

        Cache::flush();
        $this->assertArrayNotHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'رُصد معلمٌ «بلا فاتورة» وعرضُه مفوتَرٌ كاملاً');
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertStatus(422);
        $this->assertNull($m->refresh()->invoice_id, 'سُكّت فاتورةُ دفعةٍ فوق فاتورةٍ كاملةٍ حيّة — ازدواجُ فوترة');
        $this->assertSame(1, FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '%')->count());
        // والشاشةُ لا تعرض زرَّ السكّ فوق فاتورةٍ كاملة (البلوغُ يبقى)
        $this->get(route('m.show', ['quotes', $q->id]))->assertOk()
            ->assertDontSee('ms.invoice')->assertSee('ms.unreach')->assertSee('فاتورةٌ كاملةٌ حيّة');

        // أُلغيت الفاتورةُ الكاملة → المعلمُ مستحقٌّ من جديد
        $full->update(['state' => 'ملغاة']);
        Cache::flush();
        $this->assertArrayHasKey('milestone.uninvoiced:' . $m->id, $this->keys(), 'لم تعد الإشارةُ بعد إلغاء الفاتورة الكاملة');
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $this->assertNotNull($m->refresh()->invoice_id);
        $this->assertSame(2, FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '%')->count());
    }
}
