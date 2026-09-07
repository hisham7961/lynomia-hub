<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Support\CustodyPostingService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (Work OS · الطور E · WP-E.3 · §19/§28/§98) دورةُ حياةِ العهدة المالية —
 * سِبطُ `CompanyIsolationTest`/`FieldPermissionBypassTest` في محفظةِ الموظف:
 * كلُّ مسارٍ يمرّ بـ`hub_can('custody')`+عزلِ الشركة، والعميلُ ٤٠٤ على كلّ مسار،
 * والعكسُ/التصحيحُ خلفَ `hub_require_stepup`، ولا عمودَ رصيدٍ يُحرَّر.
 *
 *  ١) عزلُ الشركة (IDOR): مديرُ ألف لا يقرأ/يشحن عهدةَ موظفِ باء — ٤٠٤.
 *  ٢) حسابُ العميل ٤٠٤ على كلّ مسارِ عهدةٍ (PortalGuard — العهدةُ ليست في القائمة البيضاء).
 *  ٣) العكسُ يُنشئ صفاً معاكسَ الإشارة **وقيداً معاكساً**، والعكسُ المزدوجُ محجوب.
 *  ٤) العكسُ/التصحيحُ بلا step-up مرفوضٌ (إعادةُ توجيهٍ إلى تأكيد الهوية).
 *  ٥) لا عمودَ رصيدٍ يُحرَّر — الرصيدُ يتحرّك بحركةٍ مُرحَّلةٍ فقط.
 *  ٦) IBAN والمبلغُ يحترمان `hub_field_mode`.
 */
class WorkOsCustodyLifecycleTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    /** يبذر حسابات الدفتر ويُفعّل الترحيل الآليّ (رمز custody/بنك/مصروف) */
    protected function enableAutoJournal(): void
    {
        LedgerAccount::create(['code' => '1250', 'name' => 'عُهَد الموظفين', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '1020', 'name' => 'البنك', 'type' => 'أصول']);
        LedgerAccount::create(['code' => '5200', 'name' => 'مصروفات', 'type' => 'مصروفات']);
        $this->hubSetting('finance.accounts',
            json_encode(['custody' => '1250', 'bank' => '1020', 'exp' => '5200'], JSON_UNESCAPED_UNICODE));
        $this->hubSetting('finance.auto_journal', '1');
    }

    /** مديرٌ داخليٌّ محصورٌ بشركةٍ واحدة، دورُه يمنح العهدة (v/e/approve) */
    protected function manager(Company $co): User
    {
        $role = Role::create(['name' => 'مديرُ عهدةٍ ' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => [
                'custody' => ['v' => 1, 'e' => 1, 'approve' => 1],
                'hr'      => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0],
                'banks'   => ['v' => 1, 'e' => 1],
            ]]);

        return User::create(['name' => 'مديرُ العهدة', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$co->id], 'password_changed_at' => now()]);
    }

    protected function scene(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
    }

    /* ────────── ١) عزلُ الشركة (IDOR): مديرُ ألف ⇐ ٤٠٤ على عهدةِ باء ────────── */

    public function test_a_manager_cannot_read_or_charge_a_foreign_company_custody(): void
    {
        $this->scene();
        $empA = Employee::create(['name' => 'موظفُ ألف', 'status' => 'نشط', 'company_id' => $this->coA->id]);
        $empB = Employee::create(['name' => 'موظفُ باء', 'status' => 'نشط', 'company_id' => $this->coB->id]);
        $mgrA = $this->manager($this->coA);

        // القراءة: عهدةُ موظفِ شركته تُفتح، وعهدةُ شركةٍ أجنبيةٍ ٤٠٤ (لا كشفَ وجود)
        $this->actingAs($mgrA)->get(route('custody.wallet.employee', $empA->id))->assertOk();
        $this->actingAs($mgrA)->get(route('custody.wallet.employee', $empB->id))->assertNotFound();

        // الكتابة: سلفةٌ لموظفِ شركته تمرّ، ولموظفِ شركةٍ أجنبيةٍ ٤٠٤ (لا تُكتب حركة)
        $this->actingAs($mgrA)->post(route('custody.wallet.advance'),
            ['employee_id' => $empB->id, 'amount' => 100])->assertNotFound();
        $this->assertSame(0, EmployeeCustodyMove::where('employee_id', $empB->id)->count(),
            'كُتبت حركةُ عهدةٍ لموظفِ شركةٍ أجنبية — عزلُ الشركة انثقب');

        $this->actingAs($mgrA)->post(route('custody.wallet.advance'),
            ['employee_id' => $empA->id, 'amount' => 100]);
        $this->assertSame(1, EmployeeCustodyMove::where('employee_id', $empA->id)->count(),
            'سلفةُ موظفِ الشركةِ نفسِها لم تُكتب');
    }

    /* ────────── ٢) حسابُ العميل ⇐ ٤٠٤ على كلّ مسارِ عهدةٍ ────────── */

    public function test_a_client_account_gets_404_on_every_custody_route(): void
    {
        $this->scene();
        // دورُ عميلٍ مُساءُ الضبط يُمنح العهدةَ صراحةً — الحارسُ يفوق المصفوفة
        $role = Role::create(['name' => 'دور عميل ' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['custody' => ['v' => 1, 'e' => 1, 'approve' => 1]]]);
        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(6) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);

        $this->assertTrue(hub_can($client->fresh(), 'custody', 'e'),
            'المصفوفةُ تمنح custody:e فعلاً — فالمنعُ من الحارس لا من غيابِ الصلاحية');

        $dummy = (string) Str::uuid();
        // القراءة
        $this->actingAs($client)->get(route('custody.wallet.center'))->assertNotFound();
        $this->actingAs($client)->get(route('custody.wallet.employee', $dummy))->assertNotFound();
        // الكتابة — كلُّ الأفعال
        foreach (['advance', 'charge', 'expense', 'repayment', 'transfer', 'deduction', 'settlement'] as $act) {
            $this->actingAs($client)->post(route("custody.wallet.$act"), [])
                ->assertNotFound("العميلُ بلغ مسارَ العهدة custody.wallet.$act");
        }
        $this->actingAs($client)->post(route('custody.wallet.correct', $dummy), [])->assertNotFound();
        $this->actingAs($client)->post(route('custody.wallet.reverse', $dummy), [])->assertNotFound();
    }

    /* ────────── ٣) العكسُ: صفٌّ معاكسٌ + قيدٌ معاكس، والعكسُ المزدوجُ محجوب ────────── */

    public function test_a_reversal_creates_an_opposite_row_and_entry_and_blocks_double_reversal(): void
    {
        $this->scene();
        $this->enableAutoJournal();
        $emp = Employee::create(['name' => 'موظفُ العكس', 'status' => 'نشط', 'company_id' => $this->coA->id]);

        // شحنٌ مُرحَّلٌ بقيدٍ متوازن (custody مدين ٥٠٠ / بنك دائن ٥٠٠)
        $move = (new CustodyPostingService())->record([
            'employee_id' => $emp->id, 'company_id' => $this->coA->id,
            'kind' => 'charge', 'sign' => 1, 'amount' => 500,
            'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid(),
        ]);
        $this->assertNotNull($move->entry_id, 'الشحنُ لم يُرحّل قيداً — لا مرآةَ للعكس');
        $this->assertSame(500.0, $emp->fresh()->custody_balance);

        // العكسُ يتطلب step-up — نؤكّد الهويةَ أولاً
        $owner = $this->owner;
        $this->actingAs($owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])->assertRedirect();

        $this->actingAs($owner)->post(route('custody.wallet.reverse', $move->id), ['reason' => 'شحنٌ خاطئ'])
            ->assertRedirect();

        // صفٌّ معاكسُ الإشارة يشير إلى الأصل
        $rev = EmployeeCustodyMove::where('reverses_id', $move->id)->first();
        $this->assertNotNull($rev, 'العكسُ لم يُنشئ صفاً معاكساً');
        $this->assertSame(-1, (int) $rev->sign, 'صفُّ العكس ليس معاكسَ الإشارة');
        $this->assertSame(0.0, $emp->fresh()->custody_balance, 'العكسُ لم يُصافِ الرصيد إلى صفر');

        // قيدٌ معاكس: حسابُ العهدة دُوِّن في الأصل، ويُدان دائناً في العكس
        $this->assertNotNull($rev->entry_id, 'صفُّ العكس بلا قيدٍ معاكس — أثرٌ محاسبيٌّ معلَّق');
        $custodyAcc = LedgerAccount::where('code', '1250')->value('id');
        $origLine = JournalLine::where('entry_id', $move->entry_id)->where('acc_id', $custodyAcc)->first();
        $revLine  = JournalLine::where('entry_id', $rev->entry_id)->where('acc_id', $custodyAcc)->first();
        $this->assertNotNull($revLine, 'قيدُ العكس لم يمسّ حسابَ العهدة');
        $this->assertSame((float) $origLine->debit, (float) $revLine->credit,
            'قيدُ العكس ليس مرآةَ الأصل — مدينُ الأصل يجب أن يكون دائنَ العكس');
        $this->assertSame((float) $origLine->credit, (float) $revLine->debit);

        // العكسُ المزدوجُ للحركةِ نفسِها محجوب — لا صفٌّ ثانٍ ولا قيدٌ ثانٍ
        $this->actingAs($owner)->post(route('custody.wallet.reverse', $move->id), ['reason' => 'مرّةٌ أخرى'])
            ->assertRedirect();
        $this->assertSame(1, EmployeeCustodyMove::where('reverses_id', $move->id)->count(),
            'وُجد أكثرُ من عكسٍ للحركةِ نفسِها — رصيدٌ يُصنَع من عدم');
    }

    /* ────────── ٤) العكسُ/التصحيحُ بلا step-up مرفوض ────────── */

    public function test_reversal_and_correction_are_refused_without_stepup(): void
    {
        $this->scene();
        $this->enableAutoJournal();
        $emp = Employee::create(['name' => 'موظفٌ', 'status' => 'نشط', 'company_id' => $this->coA->id]);
        $move = (new CustodyPostingService())->record([
            'employee_id' => $emp->id, 'company_id' => $this->coA->id,
            'kind' => 'advance', 'sign' => 1, 'amount' => 200,
            'source_module' => 'custody_advance', 'source_id' => (string) Str::uuid(),
        ]);

        // بلا تأكيدِ هوية: العكسُ يُعيد إلى تأكيد الهوية ولا يعكس
        $this->actingAs($this->owner)->post(route('custody.wallet.reverse', $move->id), ['reason' => 'بلا هوية'])
            ->assertRedirect(route('stepup.show', ['next' => route('dashboard', absolute: false)]));
        $this->assertSame(0, EmployeeCustodyMove::where('reverses_id', $move->id)->count(),
            'عُكست حركةٌ بلا تأكيدِ هوية — بابُ step-up مفتوح');

        // والتصحيحُ كذلك — لا حركةَ تصحيحٍ تُكتب بلا هوية
        $this->actingAs($this->owner)->post(route('custody.wallet.correct'),
            ['employee_id' => $emp->id, 'amount' => 50, 'sign' => -1, 'reason' => 'تصحيح'])->assertRedirect();
        $this->assertSame(0, EmployeeCustodyMove::where('kind', 'correction')->count(),
            'كُتبت حركةُ تصحيحٍ بلا تأكيدِ هوية');
    }

    /* ────────── ٥) لا عمودَ رصيدٍ — الرصيدُ يتحرّك بحركةٍ مُرحَّلةٍ فقط ────────── */

    public function test_there_is_no_balance_column_and_balance_moves_only_via_posted_moves(): void
    {
        $this->scene();
        $emp = Employee::create(['name' => 'موظفٌ', 'status' => 'نشط', 'company_id' => $this->coA->id]);
        $mgr = $this->manager($this->coA);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('employee_custody_moves', 'balance'),
            'وُجد عمودُ رصيدٍ في دفترِ العهدة — الرصيدُ يُشتقّ ولا يُخزَّن');
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('employees', 'custody_balance'),
            'وُجد عمودُ رصيدِ عهدةٍ على الموظف — الرصيدُ مشتقٌّ لا مخزَّن');

        $this->assertSame(0.0, $emp->fresh()->custody_balance);
        $this->actingAs($mgr)->post(route('custody.wallet.advance'), ['employee_id' => $emp->id, 'amount' => 300]);
        $this->assertSame(300.0, $emp->fresh()->custody_balance, 'السلفةُ المُرحَّلة لم تُحرّك الرصيدَ المشتقّ');
        $this->actingAs($mgr)->post(route('custody.wallet.repayment'), ['employee_id' => $emp->id, 'amount' => 120]);
        $this->assertSame(180.0, $emp->fresh()->custody_balance, 'السدادُ لم يُنقص الرصيدَ (٣٠٠−١٢٠)');
    }

    /* ────────── ٦) IBAN والمبلغُ يحترمان hub_field_mode ────────── */

    public function test_iban_and_amount_respect_field_mode(): void
    {
        $this->scene();
        $emp = Employee::create(['name' => 'موظفُ الحقول', 'status' => 'نشط',
            'company_id' => $this->coA->id, 'iban' => 'KW00SECRETIBAN0001']);
        EmployeeCustodyMove::create(['employee_id' => $emp->id, 'company_id' => $this->coA->id,
            'kind' => 'advance', 'sign' => 1, 'amount' => 747474, 'approval_state' => 'approved',
            'at' => now(), 'posted_at' => now()]);

        // المالكُ يرى الاثنين (الحجبُ حجبُ دورٍ لا حجبٌ شامل)
        $this->actingAs($this->owner)->get(route('custody.wallet.employee', $emp->id))->assertOk()
            ->assertSee('KW00SECRETIBAN0001')->assertSee('747474');

        // دورٌ يُخفي IBAN (hr) والمبلغَ (custody) — لا يبلغانه ولو خاماً
        $role = Role::create(['name' => 'محدودُ الحقول ' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['custody' => ['v' => 1, 'e' => 1], 'hr' => ['v' => 1]],
            'field_rules' => ['hr' => ['iban' => 'hide'], 'custody' => ['amount' => 'hide']]]);
        $u = User::create(['name' => 'محدودٌ', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($u)->get(route('custody.wallet.employee', $emp->id))->assertOk();
        $res->assertDontSee('KW00SECRETIBAN0001');
        foreach (['747474', '747,474', '747474.000'] as $n) {
            $res->assertDontSee($n);
        }
        $res->assertSee('••• محجوب');   // وليس أجوف: أثرُ المسك ظاهر
    }
}
