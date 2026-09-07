<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Support\CustodyPostingService;
use App\Support\HubEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (Work OS · الطور E · WP-E.2 · §21a/b) خدمةُ الترحيل المشترَكة للعهدة —
 * سِبطُ `PayrollJournalTest` في العهدة: كلُّ أثرٍ ماليٍّ يُرحَّل في `JournalEntry`
 * الوحيد عبر الخدمة المشترَكة نفسِها، لا في دفترٍ محاسبيٍّ ثانٍ.
 *
 *  ١) شحنُ عهدةٍ يُنتج قيداً **متوازناً** مقفلاً بـ`meta.custody_move_id` عبر رمز
 *     `custody`، والحركةُ تحمل `entry_id` — رباطٌ ثنائيّ.
 *  ٢) ترحيلُ المصدرِ نفسِه مرّتين (اعتمادُ مصروفٍ مرّتين) → **حركةٌ واحدةٌ وقيدٌ
 *     واحد** (فحصٌ تحت القفل + حاجزُ UNIQUE).
 *  ٣) حاجزُ الترحيل المزدوج مفروضٌ على القاعدة نفسِها (UNIQUE يرمي عند التكرار).
 *  ٤) الترحيلُ خلفَ بوابة `finance.auto_journal`: مطفأةً تُكتب الحركةُ (مصدرُ الرصيد)
 *     بلا قيد.
 *  ٥) الحدثان الدلاليّان `custody.charged`/`custody.approved` يُبثّان مع الخام.
 *  ٦) الرافدُ الواحد: الترحيلُ الماليُّ والرواتبُ والعهدةُ كلُّها عبر
 *     `JournalPostingService::postBalanced` — لا نسخةَ محرّكٍ ثالثة.
 */
class WorkOsCustodyPostingTest extends TestCase
{
    /** يبذر حسابات الدفتر (عهدة/بنك/مصروف) ويُفعّل الترحيل عبر hub:set (يخزّن عدداً) */
    protected function enableAutoJournal(): void
    {
        LedgerAccount::create(['code' => '1250', 'name' => 'عُهَد الموظفين', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروفات تشغيلية', 'type' => 'مصروفات']);
        $this->artisan('hub:set', ['key' => 'finance.accounts',
            'value' => json_encode(['custody' => '1250', 'bank' => '1020', 'exp' => '5200'],
                JSON_UNESCAPED_UNICODE)]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
    }

    private function employee(): Employee
    {
        return Employee::create(['name' => 'موظفُ العهدة', 'status' => 'نشط']);
    }

    private function svc(): CustodyPostingService
    {
        return new CustodyPostingService();
    }

    /* ── ١) شحنُ عهدةٍ يُنتج قيداً متوازناً مقفلاً بحركة العهدة ── */

    public function test_a_custody_charge_posts_a_balanced_entry_locked_by_the_move(): void
    {
        $this->seedCore();
        $this->enableAutoJournal();
        $e = $this->employee();

        $move = $this->svc()->record([
            'employee_id' => $e->id, 'kind' => 'charge', 'sign' => 1, 'amount' => 500,
            'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid(),
        ]);

        // الحركةُ تحمل قيدها، والقيدُ يحمل معرّفَ الحركة — رباطٌ ثنائيٌّ يمنع ترحيلاً مزدوجاً
        $this->assertNotNull($move->entry_id, 'حركةُ الشحن لم تُربط بقيدها — meta.custody_move_id بلا نظير');
        $entry = JournalEntry::find($move->entry_id);
        $this->assertNotNull($entry, 'لم يُرحّل قيدُ الشحن رغم اكتمال الخريطة');
        $this->assertSame($move->id, $entry->meta['custody_move_id'] ?? null,
            'القيدُ غيرُ مقفولٍ بـmeta.custody_move_id — لا رباطَ يمنع الترحيلَ المزدوج');

        // قيدٌ موزونٌ بسطرين، مبلغُ الحركة على الطرفين
        $lines = JournalLine::where('entry_id', $entry->id)->get();
        $this->assertSame(2, $lines->count(), 'قيدُ العهدة ليس بسطرين');
        $this->assertSame(500.0, (float) $lines->sum('debit'));
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'),
            'قيدُ العهدة غيرُ موزون');

        // حسابُ العهدة (رمز custody) يُدان عند الشحن (sign +1 — أصلٌ يزيد)
        $custodyAccId = LedgerAccount::where('code', '1250')->value('id');
        $bankAccId = LedgerAccount::where('code', '1020')->value('id');
        $custodyLine = $lines->firstWhere('acc_id', $custodyAccId);
        $bankLine = $lines->firstWhere('acc_id', $bankAccId);
        $this->assertNotNull($custodyLine, 'القيدُ لم يستعمل حسابَ العهدة (رمز custody)');
        $this->assertNotNull($bankLine, 'القيدُ لم يستعمل الطرفَ المقابل (البنك)');
        $this->assertSame(500.0, (float) $custodyLine->debit, 'حسابُ العهدة يُدان عند الشحن');
        $this->assertSame(500.0, (float) $bankLine->credit, 'الطرفُ المقابل يُدان دائناً');
    }

    /* ── ٢) اعتمادُ المصروفِ مرّتين → حركةٌ واحدةٌ وقيدٌ واحد ── */

    public function test_posting_the_same_source_twice_yields_one_move_and_one_entry(): void
    {
        $this->seedCore();
        $this->enableAutoJournal();
        $e = $this->employee();

        $src = (string) Str::uuid();
        $spec = [
            'employee_id' => $e->id, 'kind' => 'expense', 'sign' => -1, 'amount' => 120,
            'source_module' => 'expenses', 'source_id' => $src,
        ];

        $m1 = $this->svc()->record($spec);
        $m2 = $this->svc()->record($spec);   // اعتمادٌ ثانٍ لنفس المصروف

        $this->assertSame($m1->id, $m2->id, 'الترحيلُ الثاني أنشأ حركةً جديدةً — لا فحصَ idempotent');
        $this->assertSame(1, EmployeeCustodyMove::where('source_module', 'expenses')
            ->where('source_id', $src)->count(), 'رُحّل المصدرُ مرّتين — حركتان لمصروفٍ واحد');
        $this->assertSame(1, JournalEntry::count(), 'قيدان لمصروفٍ واحد — الدفترُ ضُوعف');
    }

    /* ── ٣) حاجزُ الترحيل المزدوج مفروضٌ على القاعدة (UNIQUE) ── */

    public function test_the_double_post_guard_is_enforced_at_the_database(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $src = (string) Str::uuid();
        $row = fn () => EmployeeCustodyMove::create([
            'employee_id' => $e->id, 'kind' => 'expense', 'sign' => -1, 'amount' => 10,
            'source_module' => 'expenses', 'source_id' => $src, 'at' => now(), 'posted_at' => now(),
        ]);

        $row();   // الأولى تمرّ
        $threw = false;
        try {
            $row();   // الثانية بنفس (source_module, source_id, kind) — تصطدم بـUNIQUE
        } catch (QueryException $ex) {
            $threw = true;
        }

        $this->assertTrue($threw, 'كُتب صفٌّ ثانٍ لنفس المصدر — حاجزُ UNIQUE غائبٌ عن القاعدة');
        $this->assertSame(1, EmployeeCustodyMove::where('source_module', 'expenses')
            ->where('source_id', $src)->count(), 'أُدرج ترحيلٌ مزدوجٌ للمصدر نفسِه');
    }

    /* ── ٤) الترحيلُ خلفَ بوابة finance.auto_journal — الحركةُ تُكتب دائماً ── */

    public function test_posting_is_gated_by_the_auto_journal_flag(): void
    {
        $this->seedCore();
        // لا تفعيلَ — الحسابات موجودةٌ لكن البوابةُ مطفأة
        LedgerAccount::create(['code' => '1250', 'name' => 'عُهَد الموظفين', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصول']);
        $this->hubSetting('finance.accounts',
            json_encode(['custody' => '1250', 'bank' => '1020'], JSON_UNESCAPED_UNICODE));
        $e = $this->employee();

        $move = $this->svc()->record([
            'employee_id' => $e->id, 'kind' => 'charge', 'sign' => 1, 'amount' => 300,
            'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid(),
        ]);

        // دفترُ العهدة (مصدرُ الرصيد) يُكتب، والقيدُ المحاسبيُّ لا — تماماً كالدفعةِ والرواتب
        $this->assertNull($move->entry_id, 'رُحّل قيدٌ رغم إطفاء البوابة');
        $this->assertSame(0, JournalEntry::count(), 'رُحّل قيدٌ محاسبيٌّ والبوابةُ مطفأة');
        $this->assertSame(1, EmployeeCustodyMove::count(),
            'دفترُ العهدة لم يُكتب رغم أنّ الرصيدَ مصدرُه الحركاتُ لا القيد');
        $this->assertSame(300.0, $e->fresh()->custody_balance, 'الرصيدُ المشتقُّ لم يُحتسب');
    }

    /* ── ٥) الحدثان الدلاليّان يُبثّان مع الخام ── */

    public function test_semantic_custody_events_are_emitted(): void
    {
        $this->seedCore();
        $this->enableAutoJournal();
        $e = $this->employee();

        $seen = [];
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $ev, string $mod, $m, ?string $to) use (&$seen) {
            if ($mod === 'custody') $seen[] = $ev;
        });

        $this->svc()->record([
            'employee_id' => $e->id, 'kind' => 'charge', 'sign' => 1, 'amount' => 100,
            'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid(),
        ]);
        $this->svc()->record([
            'employee_id' => $e->id, 'kind' => 'expense', 'sign' => -1, 'amount' => 40,
            'source_module' => 'expenses', 'source_id' => (string) Str::uuid(),
        ]);

        HubEvents::forgetListeners();

        $this->assertContains('charged', $seen, 'الحدثُ الخامُّ للشحن لم يُبثّ');
        $this->assertContains('custody.charged', $seen,
            'الدلاليُّ custody.charged لم يُبثّ — غيرُ مُعلَنٍ في config(hub.events.custody)');
        $this->assertContains('approved', $seen, 'الحدثُ الخامُّ لاعتماد المصروف لم يُبثّ');
        $this->assertContains('custody.approved', $seen,
            'الدلاليُّ custody.approved لم يُبثّ — غيرُ مُعلَنٍ في config(hub.events.custody)');
    }

    /* ── ٦) الرافدُ الواحد: لا نسخةَ محرّكِ قيدٍ ثالثة ── */

    public function test_all_three_rails_post_through_the_one_shared_service(): void
    {
        $fin = (string) file_get_contents(app_path('Http/Controllers/Web/FinController.php'));
        $pay = (string) file_get_contents(app_path('Http/Controllers/Web/PayrollController.php'));
        $cust = (string) file_get_contents(app_path('Support/CustodyPostingService.php'));

        foreach (['FinController' => $fin, 'PayrollController' => $pay, 'CustodyPostingService' => $cust] as $n => $src) {
            $this->assertStringContainsString('postBalanced', $src,
                "{$n} لا يمرّ عبر خدمة الترحيل الواحدة (postBalanced) — نسخةٌ محتملةٌ من محرّك القيد");
        }

        // ولا نسخةَ ثالثة: المتحكّمان لا يُنشئان القيدَ ولا سطورَه مباشرةً بعد الاستخراج
        foreach (['FinController' => $fin, 'PayrollController' => $pay] as $n => $src) {
            $this->assertStringNotContainsString('JournalEntry::create', $src,
                "{$n} ما زال ينشئ القيدَ مباشرةً — لم يُستخرَج إلى الخدمة المشترَكة");
            $this->assertStringNotContainsString('JournalLine::create', $src,
                "{$n} ما زال ينشئ سطورَ القيد مباشرةً — نسخةٌ من محرّك القيد باقية");
        }
    }
}
