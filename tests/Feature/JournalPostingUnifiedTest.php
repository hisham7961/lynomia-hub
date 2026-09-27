<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Support\Assets\CustodyPostingService;
use App\Support\Finance\JournalPosting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **ترحيلُ القيود الموحَّد** (TECH_DEBT #29 · ARCH-06..09 · `JournalPosting`): كلُّ بابٍ
 * يُنتج قيداً مرحَّلاً — دفعةُ المستند وعكسُها، واعتمادُ الرواتب، وحركةُ العهدة وعكسُها،
 * وزرُّ «ترحيل»، والنموذجُ العامّ — يمرّ بمحرّكٍ واحد فيحمل الثوابتَ نفسَها: توازنٌ
 * عشريٌّ، وترحيلٌ واحدٌ للمصدر الواحد، ورقمٌ لا يتكرّر، وقيدُ تدقيقٍ واحد، وختمُ الترحيل.
 *
 * والفجواتُ التي كان يتخطّاها بابٌ صامتاً — كلٌّ باختبارٍ فشل أولاً:
 *  (أ) المحرّكُ الآليّ يرفع رايةَ التجاوز ولا يفحص التوازن بنفسه — قيدٌ أعرجُ يمرّ.
 *  (ب) مسيّرٌ أُعيد إلى المسودة فاعتُمد ثانيةً ⇒ قيدُ رواتبٍ ثانٍ (مصروفٌ مضاعف).
 *  (ج) دفعتان في الثانية نفسِها ⇒ قيدان برقمٍ واحد.
 *  (د) الترحيلُ الآليّ والنموذجُ العامّ بلا قيدِ تدقيق «ترحيل قيد».
 *  (هـ) النموذجُ العامّ يُرحّل بلا ختم `posted_at`.
 */
class JournalPostingUnifiedTest extends TestCase
{
    protected function ledger(?string $companyId = null): void
    {
        foreach ([['1010', 'الصندوق'], ['1020', 'البنك'], ['4100', 'المبيعات'],
                  ['5200', 'مصروفات تشغيلية'], ['1250', 'عُهَد الموظفين']] as [$c, $n]) {
            LedgerAccount::create(['code' => $c, 'name' => $n, 'type' => 'أصول', 'company_id' => $companyId]);
        }
        $this->artisan('hub:set', ['key' => 'finance.accounts', 'value' => json_encode(
            ['cash' => '1010', 'bank' => '1020', 'sales' => '4100', 'exp' => '5200', 'custody' => '1250'])]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
    }

    protected function acc(string $code): string
    {
        return (string) LedgerAccount::where('code', $code)->orderBy('id')->value('id');
    }

    protected function postingAudits(JournalEntry $e): int
    {
        return AuditEntry::where('action', JournalPosting::AUDIT_ACTION)
            ->where('module', 'entries')->where('record_id', $e->id)->count();
    }

    protected function payrollRun(string $name): PayrollRun
    {
        Employee::create(['name' => 'موظف ' . $name, 'salary' => 1000, 'allow' => 200, 'status' => 'على رأس العمل']);
        $run = PayrollRun::create(['name' => $name, 'month' => '2026-07', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'generate']);

        return $run->fresh();
    }

    /** قيدٌ مسودةٌ بسطرين موزونين — يُرحَّل من بابٍ يدويّ */
    protected function draft(string $no, ?string $companyId = null): JournalEntry
    {
        $e = JournalEntry::create(['doc_no' => $no, 'date' => now()->toDateString(), 'state' => 'مسودة',
            'company_id' => $companyId]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $this->acc('1010'), 'debit' => 0.1, 'credit' => 0]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $this->acc('1010'), 'debit' => 0.2, 'credit' => 0]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 0.3]);

        return $e;
    }

    /**
     * **كلُّ بابٍ يمرّ بالمحرّك:** سبعةُ أبوابٍ تُنتج سبعةَ قيودٍ مرحَّلة — لكلٍّ قيدُ تدقيقٍ
     * «ترحيل قيد» واحد وختمُ `posted_at`، وسطورٌ موزونة؛ والآليّةُ منها تحمل رابطَ مصدرها.
     */
    public function test_every_posting_path_goes_through_the_one_engine(): void
    {
        $this->seedCore();
        $this->ledger();

        // (١) دفعة (٢) عكسُها
        $doc = FinDocument::create(['doc_no' => 'INV-UNI', 'kind' => 'فاتورة مبيعات',
            'total' => 250, 'paid' => 0, 'state' => 'مرسلة']);
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 250])->assertRedirect();
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act",
            ['do' => 'reverse', 'amount' => 50, 'reason' => 'خطأ إدخال'])->assertRedirect();

        // (٣) اعتمادُ الرواتب
        $run = $this->payrollRun('مسيّر الموحَّد');
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve'])->assertRedirect();

        // (٤) حركةُ عهدة (٥) عكسُها
        $emp = Employee::create(['name' => 'موظفُ العهدة', 'status' => 'نشط']);
        $this->actingAs($this->owner);
        $move = (new CustodyPostingService())->record(['employee_id' => $emp->id, 'kind' => 'charge',
            'sign' => 1, 'amount' => 75.5, 'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid()]);
        $rev = (new CustodyPostingService())->reverse($move, 'خطأ');

        // (٦) زرُّ «ترحيل» (٧) النموذجُ العامّ
        $manual = $this->draft('JE-MAN-1');
        $this->actingAs($this->owner)->post("/entry/{$manual->id}/post")->assertRedirect();
        $form = $this->draft('JE-FORM-1');
        $this->actingAs($this->owner)->put("/m/entries/{$form->id}", [
            'no' => 'JE-FORM-1', 'date' => now()->toDateString(), 'state' => 'مرحّل', '_version' => $form->version,
        ])->assertSessionHasNoErrors();
        $this->assertSame('مرحّل', $form->fresh()->state, 'خطُّ الأساس: النموذجُ العامّ رحّل القيد');

        $entries = JournalEntry::orderBy('id')->get();
        $this->assertCount(7, $entries, 'سبعةُ أبوابٍ لم تُنتج سبعةَ قيود');
        foreach ($entries as $e) {
            $this->assertSame('مرحّل', $e->state, $e->doc_no);
            $this->assertSame(1, $this->postingAudits($e), 'قيدُ التدقيق «ترحيل قيد» ليس واحداً: ' . $e->doc_no);
            $this->assertNotEmpty(((array) $e->meta)['posted_at'] ?? null, 'بلا ختم posted_at: ' . $e->doc_no);
            $t = JournalPosting::entryTotals($e->id);
            $this->assertGreaterThan(0, $t['debit'], $e->doc_no);
            $this->assertSame($t['debit'], $t['credit'], 'قيدٌ غير موزون: ' . $e->doc_no);
        }

        // رابطُ المصدر المتعدّد الأشكال على الآليّة كلِّها — والمفتاحُ يميّز الحدث
        $src = fn (JournalEntry $e) => [$e->source_module, $e->source_id, $e->source_key];
        $this->assertSame(['fin', $doc->id, 'payment:1'], $src(JournalEntry::where('fin_id', $doc->id)
            ->where('description', 'not like', 'عكس%')->firstOrFail()));
        $this->assertSame(['fin', $doc->id, 'reversal:2'], $src(JournalEntry::where('fin_id', $doc->id)
            ->where('description', 'like', 'عكس%')->firstOrFail()));
        $this->assertSame(['payroll', $run->id, 'approval'], $src(JournalEntry::where('reference', $run->name)->firstOrFail()));
        $this->assertSame(['custody', $move->id, 'move'], $src(JournalEntry::findOrFail($move->entry_id)));
        $this->assertSame(['custody', $move->id, 'reversal'], $src(JournalEntry::findOrFail($rev->entry_id)));
        $this->assertSame([null, null, null], $src($manual->fresh()));

        // والختمُ اليدويّ يحمل فاعلَه — من الزرّ ومن النموذج العامّ
        $this->assertSame($this->owner->id, ((array) $manual->fresh()->meta)['posted_by'] ?? null);
        $this->assertSame($this->owner->id, ((array) $form->fresh()->meta)['posted_by'] ?? null);
    }

    /** (أ) المحرّكُ نفسُه يرفض قيداً لا يوازن — لا يعتمد على نزاهة المستدعي */
    public function test_the_engine_refuses_unbalanced_or_lame_lines(): void
    {
        $this->seedCore();
        $this->ledger();
        $svc = new \App\Support\Finance\JournalPostingService();   // البابُ القديم يفوّض للمحرّك

        foreach ([
            'غير موزون' => [['acc_id' => $this->acc('1010'), 'debit' => 100, 'credit' => 0],
                            ['acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 60]],
            'بلا حساب'  => [['acc_id' => null, 'debit' => 100, 'credit' => 0],
                            ['acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 100]],
            'طرفان'     => [['acc_id' => $this->acc('1010'), 'debit' => 100, 'credit' => 100],
                            ['acc_id' => $this->acc('4100'), 'debit' => 100, 'credit' => 100]],
            'صفري'      => [['acc_id' => $this->acc('1010'), 'debit' => 0, 'credit' => 0],
                            ['acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 0]],
        ] as $case => $lines) {
            try {
                $svc->postBalanced(['doc_no' => 'JE-BAD', 'date' => now()->toDateString(),
                    'state' => 'مرحّل'], $lines);
                $this->fail('المحرّكُ رحّل قيداً أعرج: ' . $case);
            } catch (ValidationException $e) {
                // المتوقَّع
            }
        }
        $this->assertSame(0, JournalEntry::count(), 'بقي قيدٌ أعرجُ في الدفتر');
        $this->assertSame(0, JournalLine::count());
    }

    /** التوازنُ عشريٌّ لا عائم: ٠٫١ + ٠٫٢ = ٠٫٣ تماماً، و٠٫٣٠١ ليست ٠٫٣ */
    public function test_balance_is_decimal_safe(): void
    {
        $this->seedCore();
        $this->ledger();

        $ok = JournalPosting::postBalanced(['doc_no' => 'JE-DEC'], [
            ['acc_id' => $this->acc('1010'), 'debit' => 0.1, 'credit' => 0],
            ['acc_id' => $this->acc('1010'), 'debit' => 0.2, 'credit' => 0],
            ['acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 0.3],
        ]);
        $this->assertSame('مرحّل', $ok->state);
        $this->assertSame(300, JournalPosting::mills('0.3'));
        $this->assertSame(JournalPosting::mills(0.1 + 0.2), JournalPosting::mills('0.300'));

        $this->expectException(ValidationException::class);
        JournalPosting::postBalanced(['doc_no' => 'JE-DEC-2'], [
            ['acc_id' => $this->acc('1010'), 'debit' => 0.3, 'credit' => 0],
            ['acc_id' => $this->acc('4100'), 'debit' => 0, 'credit' => 0.301],
        ]);
    }

    /**
     * (ب) **ترحيلٌ واحدٌ للمصدر الواحد:** مسيّرٌ أُعيد إلى «مسودة» (تعديلٌ مباشر) ثم اعتُمد
     * ثانيةً كان يُرحّل قيدَ رواتبٍ ثانياً — مصروفٌ مضاعفٌ في دفترٍ مقفول. والنداءُ المباشر
     * للمحرّك بالمصدر نفسِه يعيد القيدَ القائم.
     */
    public function test_one_source_is_posted_once(): void
    {
        $this->seedCore();
        $this->ledger();
        $run = $this->payrollRun('مسيّر مكرّر');

        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve'])->assertRedirect();
        $run->fresh()->forceFill(['status' => 'مسودة'])->saveQuietly();
        $this->actingAs($this->owner)->post("/payroll/{$run->id}/act", ['do' => 'approve'])->assertRedirect();

        $this->assertSame(1, JournalEntry::where('reference', $run->name)->count(),
            'اعتمادٌ ثانٍ للمسيّر نفسِه رحّل قيدَ رواتبٍ ثانياً — مصروفٌ مضاعف');

        $first = JournalEntry::where('reference', $run->name)->firstOrFail();
        $amount = (string) \App\Models\JournalLine::where('entry_id', $first->id)->sum('debit');
        $again = JournalPosting::postBalanced(['doc_no' => 'X'], [
            ['acc_id' => $this->acc('5200'), 'debit' => $amount, 'credit' => 0],
            ['acc_id' => $this->acc('1020'), 'debit' => 0, 'credit' => $amount],
        ], ['module' => 'payroll', 'id' => $run->id, 'key' => 'approval']);
        $this->assertSame($first->id, $again->id, 'المحرّكُ رحّل المصدرَ نفسَه مرّتين');
        $this->assertSame(1, JournalEntry::count());
    }

    /**
     * (مراجعة) **المصدرُ المرحَّلُ بمبلغٍ آخر لا يُعاد قيدُه القديمُ صامتاً** — مسيّرٌ عُدّل بعد
     * ترحيله فأُعيد اعتمادُه كان يُعاد له القيدُ القديم فيختلف الدفترُ عن الرواتب بلا أثر.
     */
    public function test_same_source_with_a_different_amount_is_refused_not_silently_reused(): void
    {
        $this->seedCore();
        $this->ledger();
        $src = ['module' => 'payroll', 'id' => 'run-x', 'key' => 'approval'];
        $lines = fn ($a) => [
            ['acc_id' => $this->acc('5200'), 'debit' => $a, 'credit' => 0],
            ['acc_id' => $this->acc('1020'), 'debit' => 0, 'credit' => $a],
        ];
        JournalPosting::postBalanced(['doc_no' => 'JE-A'], $lines('100.000'), $src);

        try {
            JournalPosting::postBalanced(['doc_no' => 'JE-B'], $lines('150.000'), $src);
            $this->fail('مصدرٌ مرحَّلٌ بمبلغٍ آخر أُعيد قيدُه القديمُ صامتاً');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('بمبلغٍ مختلف', implode(' ', \Illuminate\Support\Arr::flatten($e->errors())));
        }
        $this->assertSame(1, JournalEntry::count());
    }

    /**
     * (مراجعة) **لا قفلَ فجوةٍ على مفتاح المصدر**: `SELECT … FOR UPDATE` على مفتاحٍ غائبٍ في فهرسٍ
     * فريد يأخذ قفلَ فجوةٍ في InnoDB، فترحيلان لمصدرين مختلفين يتقاطعان في الفجوة نفسِها يتعاطلان —
     * والتعاطلُ ليس خطأَ تفرّدٍ فيسقط القيدُ صامتاً خلف `report()`. الفهرسُ الفريدُ وحده الحاجز.
     */
    public function test_source_lookup_takes_no_gap_lock(): void
    {
        $this->seedCore();
        $this->ledger();
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = strtolower($q->sql); });
        JournalPosting::postBalanced(['doc_no' => 'JE-L'], [
            ['acc_id' => $this->acc('5200'), 'debit' => 5, 'credit' => 0],
            ['acc_id' => $this->acc('1020'), 'debit' => 0, 'credit' => 5],
        ], ['module' => 'fin', 'id' => 'doc-1', 'key' => 'payment:1']);

        $locked = array_filter($sql, fn ($q) => str_contains($q, 'journal_entries') && str_contains($q, 'for update'));
        $this->assertSame([], array_values($locked), 'البحثُ عن قيد المصدر يقفل فجوةَ الفهرس');
    }

    /**
     * (مراجعة) **رقمُ القيد فريدٌ في القاعدة لا بالفحص وحده**: `exists()` ثم الإدراجُ سباقٌ بين
     * معاملتين؛ الفهرسُ الفريدُ يُسقط الثاني، والمحرّكُ يعيد التخصيصَ بلاحقة.
     */
    public function test_doc_no_race_is_caught_by_the_index_and_retried(): void
    {
        $this->seedCore();
        $this->ledger();
        $fired = false;
        JournalEntry::creating(function ($e) use (&$fired) {
            if ($fired || $e->doc_no !== 'JE-RACE') return;
            $fired = true;   // معاملةٌ أخرى التزمت بالرقم نفسِه بين الفحص والإدراج
            DB::table('journal_entries')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'doc_no' => 'JE-RACE',
                'date' => now()->toDateString(), 'state' => 'مسودة', 'created_at' => now(), 'updated_at' => now()]);
        });

        $e = JournalPosting::postBalanced(['doc_no' => 'JE-RACE'], [
            ['acc_id' => $this->acc('5200'), 'debit' => 7, 'credit' => 0],
            ['acc_id' => $this->acc('1020'), 'debit' => 0, 'credit' => 7],
        ]);

        // (المحاكاةُ داخل معاملتنا فيرتدّ صفُّها مع المحاولة الأولى — والمقصودُ: لا رقمَ مكرّرٌ يُكتب، والترحيلُ يتمّ)
        $this->assertTrue($fired);
        $this->assertTrue($e->exists && JournalEntry::whereKey($e->id)->exists(), 'الترحيلُ سقط بدل إعادة التخصيص');
        $this->assertSame(1, JournalEntry::where('doc_no', $e->doc_no)->count(), 'رقمُ قيدٍ مكرّرٌ كُتب');
        $this->assertSame(1, JournalEntry::where('doc_no', 'JE-RACE')->count());
    }

    /** (ج) دفعتان على المستند نفسِه في الثانية نفسِها ⇒ رقمان مختلفان لا رقمٌ مكرّر */
    public function test_numbers_are_unique_within_the_same_second(): void
    {
        $this->seedCore();
        $this->ledger();
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:11:12'));
        try {
            $doc = FinDocument::create(['doc_no' => 'INV-SEC', 'kind' => 'فاتورة مبيعات',
                'total' => 300, 'paid' => 0, 'state' => 'مرسلة']);
            $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 100]);
            $this->actingAs($this->owner)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 100]);
        } finally {
            Carbon::setTestNow();
        }

        $nos = JournalEntry::where('fin_id', $doc->id)->orderBy('id')->pluck('doc_no')->all();
        $this->assertCount(2, $nos);
        $this->assertCount(2, array_unique($nos), 'قيدان بالرقم نفسِه: ' . implode(' · ', $nos));
        $this->assertContains('JE-INV-SEC-101112', $nos, 'الصيغةُ القائمة للرقم تغيّرت');
    }

    /**
     * **التنطيقُ على باب الترحيل اليدويّ:** مستخدمٌ معزولٌ بشركته يُرحّل قيدَ شركته عبر
     * المحرّك، ولا يرى قيدَ شركةٍ أخرى (٤٠٤) — فلا ترحيلَ ولا تدقيقَ عليه.
     */
    public function test_manual_posting_respects_scope_for_an_isolated_user(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $this->ledger($a->id);

        $role = Role::create(['name' => 'محاسب أ', 'scope' => 'all', 'flags' => [],
            'matrix' => ['entries' => ['v' => 1, 'a' => 1, 'e' => 1], 'accounts2' => ['v' => 1]]]);
        $iso = User::create(['name' => 'محاسب معزول', 'email' => 'iso-je@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(), 'companies' => [$a->id]]);

        $mine = $this->draft('JE-ISO-A', $a->id);
        $theirs = $this->draft('JE-ISO-B', $b->id);

        $this->actingAs($iso)->post("/entry/{$theirs->id}/post")->assertNotFound();
        $this->assertSame('مسودة', $theirs->fresh()->state);
        $this->assertSame(0, $this->postingAudits($theirs));

        $this->actingAs($iso)->post("/entry/{$mine->id}/post")->assertRedirect();
        $this->assertSame('مرحّل', $mine->fresh()->state);
        $this->assertSame(1, $this->postingAudits($mine));
        $this->assertSame($iso->id, ((array) $mine->fresh()->meta)['posted_by'] ?? null);
    }

    /**
     * **حارسُ المصدر:** لا كاتبَ للقيد المرحَّل خارج المحرّك — لا `JournalEntry::create`
     * ولا إدراجٌ خامٌّ في الجدولين؛ و`JournalLine::create` خارجه لسطور **المسودة** وحدها
     * (`EntryController::addLineTo` تحت قفلٍ يرفض المرحَّل).
     */
    public function test_no_journal_writer_outside_the_engine(): void
    {
        $allowed = ['JournalLine::create(' => ['app/Http/Controllers/Web/EntryController.php']];
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = 'app/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(app_path()))), '/');
            if ($rel === 'app/Support/Finance/JournalPosting.php') continue;
            $src = (string) file_get_contents($f->getPathname());
            foreach (['JournalEntry::create(', 'JournalLine::create(', "table('journal_entries')->insert",
                      "table('journal_lines')->insert"] as $needle) {
                if (str_contains($src, $needle) && ! in_array($rel, $allowed[$needle] ?? [], true)) {
                    $found[] = $rel . ' ⟵ ' . $needle;
                }
            }
        }
        $this->assertSame([], $found, 'كاتبُ قيدٍ خارج المحرّك الواحد JournalPosting');
    }
}
