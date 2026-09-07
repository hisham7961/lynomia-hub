<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use App\Models\PayrollLine;
use App\Support\CustodyPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * **عهدةُ الموظف المالية — دورةُ الحياة والمركز** (Work OS · الطور E · WP-E.3 · §19/§28/§98).
 *
 * محفظةُ موظفٍ مشتقّةُ الرصيد (SUM بإشارتها، لا عمودَ رصيدٍ يُحرَّر) تُرحِّل في دفتر
 * Lynomia الوحيد عبر `CustodyPostingService` — لا دفترَ محاسبيٍّ ثانٍ. هذا المتحكّم
 * نماذجُ الحركات فقط؛ التوازنُ والقفلُ وحاجزُ الترحيل المزدوج في الخدمة والنموذج.
 *
 * **منفصلٌ تماماً عن عهدة الأصول** (`CustodyController` — وحدةُ `assets`): تلك عهدةُ
 * أجهزةٍ بأكوادٍ وملصقاتٍ وتصاريح، وهذه عهدةُ **مالٍ** بوحدة `custody`. اسمان
 * متجاوران في العربية، مسارٌ ومتحكّمٌ ووحدةُ صلاحيّةٍ متمايزةٌ لكلٍّ.
 *
 * **الحرّاسُ على كلّ مسار (لا إخفاءَ رابط):**
 *   · `hub_can('custody', v/e/approve)` — الصلاحيةُ من مصفوفة الدور.
 *   · عزلُ الشركة: الموظفُ يُحسَم عبر `hub_scope(...,'hr')` (لعهدةِ شركةٍ أجنبيةٍ ٤٠٤)،
 *     والحركاتُ تُقرأ محصورةً بشركاتِ القارئ — نظيرُ عزلِ بقيّة الشاشات.
 *   · العميلُ (`account_type=client`) ٤٠٤ على كلّ مسارٍ هنا: `PortalGuard` قائمةٌ
 *     بيضاء، والعهدةُ **ليست** فيها — فوق المصفوفة.
 *   · العكسُ/التصحيحُ خلفَ `hub_require_stepup` — لا يُصحَّح أثرٌ ماليٌّ مُرحَّلٌ بجلسةٍ
 *     مسروقةٍ وحدَها.
 *   · IBAN والمبلغُ في الشاشة عبر `hub_field_mode` — لا كشفَ خارج حجبِ الدور.
 */
class EmployeeCustodyController extends Controller
{
    protected function svc(): CustodyPostingService
    {
        return new CustodyPostingService();
    }

    /** بوّابةُ الصلاحية على وحدة العهدة المالية */
    protected function can(string $op): void
    {
        abort_unless(hub_can(auth()->user(), 'custody', $op), 403, 'لا تملك صلاحيةَ العهدة المالية');
    }

    /**
     * الموظفُ بنطاق القارئ — بوّابةُ العزل الأولى: يُحسَم عبر `hub_scope(...,'hr')`
     * (لِـhr عمودُ شركةٍ يعزله الرصيف)، فعهدةُ موظفِ شركةٍ أجنبيةٍ ٤٠٤ لا كشفَ وجود.
     */
    protected function employeeScoped(?string $id): Employee
    {
        abort_unless(filled($id), 404);

        return hub_scope(Employee::query(), 'hr')->findOrFail($id);
    }

    /** استعلامُ حركاتٍ محصورٌ بشركاتِ القارئ — نظيرُ فقرةِ الشركة في `hub_scope` */
    protected function moves()
    {
        $q = EmployeeCustodyMove::query();
        // العهدةُ ليست وحدةَ `hub.modules` (عمداً — كي لا يُفتَح لها CRUD عامّ على
        // `/m/custody` يلتفّ على القفل)، فيُطبَّق عزلُ الشركة صراحةً هنا: من له قائمةُ
        // شركاتٍ مسموحة لا يقرأ عهدةَ غيرها؛ ومن لا قائمةَ له (المالك) يقرأ الكلّ.
        if (($cids = hub_company_ids()) !== null) {
            $q->whereIn('company_id', $cids);
        }

        return $q;
    }

    /** حركةٌ بنطاق القارئ (للعكس) — عزلُ شركةٍ صريحٌ ثمّ findOrFail */
    protected function moveScoped(?string $id): EmployeeCustodyMove
    {
        abort_unless(filled($id), 404);

        return $this->moves()->whereKey($id)->firstOrFail();
    }

    /* ══════════════════════ المركز والكشف ══════════════════════ */

    /** مركزُ العهدة: أحدثُ الحركات محصورةً بشركاتِ القارئ + أرصدةُ الموظفين */
    public function center()
    {
        $this->can('v');

        // ترتيبٌ حتميّ (C13): زمنُ الحركة ثمّ id — لا قرعةَ عند تساوي الأزمنة
        $recent = $this->moves()->orderByDesc('at')->orderByDesc('id')->limit(50)->get();

        // أرصدةُ الموظفين ذوي الحركات — تجميعٌ ONLY_FULL_GROUP_BY-safe: مفتاحُ
        // التجميع (employee_id) + مُجمَّعٌ فقط، مرتَّبٌ بالمفتاح حتميّاً (C13).
        $sums = $this->moves()
            ->selectRaw('employee_id, COALESCE(SUM(sign * amount), 0) AS bal')
            ->groupBy('employee_id')->orderBy('employee_id')->get();
        $emps = Employee::whereIn('id', $sums->pluck('employee_id'))->get()->keyBy('id');

        return view('custody-wallet.center', [
            'recent' => $recent,
            'sums'   => $sums,
            'emps'   => $emps,
            'cur'    => setting('app.currency', 'د.ك'),
        ]);
    }

    /** كشفُ عهدةِ موظفٍ واحد — الرصيدُ المشتقُّ وحركاتُه، مع نماذج الأفعال */
    public function employee(string $id)
    {
        $this->can('v');
        $emp = $this->employeeScoped($id);

        // كلُّ حركاتِ الموظف بترتيبٍ حتميّ (الأحدثُ أولاً ثمّ id) — الكشفُ يمرّ على الكلّ
        $moves = EmployeeCustodyMove::where('employee_id', $emp->id)
            ->orderByDesc('at')->orderByDesc('id')->get();

        // مدخلاتُ النماذج، كلُّها منطَّقةٌ بنطاق القارئ (بنوكه، وموظفو شركته، وسلفُ راتبه):
        $banks = hub_scope(BankAccount::query(), 'banks')->orderBy('name')->orderBy('id')->get();
        $peers = hub_scope(Employee::query(), 'hr')->where('id', '!=', $emp->id)
            ->orderBy('name')->orderBy('id')->get();
        $advLines = PayrollLine::where('emp_id', $emp->id)->where('advance', '>', 0)
            ->orderBy('id')->get();

        return view('custody-wallet.employee', [
            'emp'      => $emp,
            'moves'    => $moves,
            'balance'  => $emp->custody_balance,
            'banks'    => $banks,
            'peers'    => $peers,
            'advLines' => $advLines,
            'cur'      => setting('app.currency', 'د.ك'),
        ]);
    }

    /* ══════════════════════ حركاتُ دورة الحياة ══════════════════════ */

    /** سلفة: مالٌ بيد الموظف يُزيد ما بذمّته (+1) */
    public function advance(Request $r)
    {
        $this->can('e');
        $emp = $this->employeeScoped($r->input('employee_id'));
        $d = $this->amount($r);

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'advance', 'sign' => 1, 'amount' => $d,
            'source_module' => 'custody_advance', 'source_id' => (string) Str::uuid(),
            'at' => now(), 'meta' => $this->note($r),
        ]);
        hub_audit('سلفة عهدة', 'custody', $emp->id, number_format($d, 3) . ' — ' . $emp->name);

        return back()->with('ok', '💰 سُجّلت السلفة');
    }

    /**
     * شحنُ عهدةٍ مموَّلٌ من بنك — عبر سكّةِ البنك القائمة (`FinController::pay` ~72-92):
     * الحرّاسُ على البنك الفعليّ الذي يتحرّك رصيدُه (`banks:e` + النطاق)، ورصيدُه
     * يُنقص بالمبلغ داخل قفلٍ، ثمّ تُرحَّل حركةُ الشحن (+1) بقيدها عبر الخدمة. الكلّ
     * في معاملةٍ واحدةٍ فلا شحنٌ بلا صرفٍ ولا صرفٌ بلا شحن.
     */
    public function charge(Request $r)
    {
        $this->can('e');
        $emp = $this->employeeScoped($r->input('employee_id'));
        $d = $this->amount($r);
        $bankId = (string) $r->input('bank_id');

        // الحرّاسُ على البنك الفعليّ لا على وسيط الطلب (نمطُ FinController)
        abort_unless(hub_can(auth()->user(), 'banks', 'e'), 422,
            'شحنُ العهدة من بنكٍ يحرّك رصيده — ويتطلب صلاحيةَ تعديل البنوك');
        abort_unless(hub_scope(BankAccount::query(), 'banks')->whereKey($bankId)->exists(), 422,
            'الحساب البنكيُّ خارج نطاقك — اختر حساباً من شركاتك');

        $move = DB::transaction(function () use ($emp, $d, $bankId, $r) {
            if ($bank = BankAccount::lockForUpdate()->find($bankId)) {
                $bank->balance = (float) ($bank->balance ?? 0) - $d;   // صرفٌ من البنك للعهدة
                $bank->saveQuietly();
            }

            return $this->svc()->record([
                'employee_id' => $emp->id, 'company_id' => $emp->company_id,
                'kind' => 'charge', 'sign' => 1, 'amount' => $d,
                'source_module' => 'custody_charge', 'source_id' => (string) Str::uuid(),
                'at' => now(), 'meta' => $this->note($r, ['bank_id' => $bankId]),
            ]);
        });
        hub_audit('شحن عهدة', 'custody', $emp->id, number_format($d, 3) . ' — ' . $emp->name);

        return back()->with('ok', '💰 شُحنت العهدة من البنك');
    }

    /**
     * مصروفٌ باعتمادٍ وإيصال — يُنقص العهدة (−1). الإيصالُ مرفقٌ عبر البوّابةِ
     * المُنطَّقةِ القائمة (`AttachmentController` على وحدة `hr` لملفّ الموظف)؛ هنا
     * يُشار إليه في الحركة (`receipt_module`/`receipt_id`) بعد التأكّد من نطاقه.
     */
    public function expense(Request $r)
    {
        $this->can('approve');
        $emp = $this->employeeScoped($r->input('employee_id'));
        $d = $this->amount($r);
        $receiptId = (string) $r->input('receipt_id');

        // الإيصالُ إلزاميٌّ ومُنطَّق: مرفقٌ على ملفّ هذا الموظف (لا رابطٌ يُخمَّن)
        $receipt = Attachment::where('id', $receiptId)->where('module', 'hr')
            ->where('record_id', $emp->id)->first();
        abort_unless($receipt, 422, 'المصروفُ باعتمادٍ يتطلب إيصالاً مرفقاً على ملفّ الموظف');

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'expense', 'sign' => -1, 'amount' => $d,
            'source_module' => 'custody_expense', 'source_id' => (string) Str::uuid(),
            'receipt_module' => 'attachments', 'receipt_id' => $receipt->id,
            'approval_state' => 'approved', 'at' => now(), 'meta' => $this->note($r),
        ]);
        hub_audit('مصروف عهدة', 'custody', $emp->id, number_format($d, 3) . ' — ' . $emp->name);

        return back()->with('ok', '🧾 اعتُمد المصروفُ بإيصاله');
    }

    /** سدادٌ من الموظف — يُنقص ما بذمّته (−1) */
    public function repayment(Request $r)
    {
        $this->can('e');
        $emp = $this->employeeScoped($r->input('employee_id'));
        $d = $this->amount($r);

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'repayment', 'sign' => -1, 'amount' => $d,
            'source_module' => 'custody_repayment', 'source_id' => (string) Str::uuid(),
            'at' => now(), 'meta' => $this->note($r),
        ]);
        hub_audit('سداد عهدة', 'custody', $emp->id, number_format($d, 3) . ' — ' . $emp->name);

        return back()->with('ok', '💵 سُجّل السداد');
    }

    /**
     * تحويلُ عهدةٍ بين موظفَين — صادرٌ (−1) من الأول ووارِدٌ (+1) للثاني، بمعرّفِ
     * تحويلٍ واحدٍ يجمع الطرفين (النوعُ في مفتاح UNIQUE فيمرّ الطرفان بمصدرٍ واحد).
     * كلا الموظفَين بنطاق القارئ — فلا تحويلَ عبر حدود الشركة.
     */
    public function transfer(Request $r)
    {
        $this->can('e');
        $from = $this->employeeScoped($r->input('from_id'));
        $to = $this->employeeScoped($r->input('to_id'));
        abort_if($from->id === $to->id, 422, 'التحويلُ يكون بين موظفَين مختلفَين');
        $d = $this->amount($r);
        $tid = (string) Str::uuid();

        // الطرفُ المقابلُ لكلِّ ساقٍ حسابُ العهدة نفسُه (مالٌ لا يغادر المنشأة) —
        // فيصفو القيدُ داخل حساب العهدة بلا مساسِ نقدٍ أو مصروف.
        $custody = (new \App\Support\JournalPostingService())->accountsMap()['custody'] ?? null;

        DB::transaction(function () use ($from, $to, $d, $tid, $r, $custody) {
            $this->svc()->record([
                'employee_id' => $from->id, 'company_id' => $from->company_id,
                'kind' => 'transfer_out', 'sign' => -1, 'amount' => $d,
                'source_module' => 'custody_transfer', 'source_id' => $tid,
                'counterpart' => $custody, 'at' => now(),
                'meta' => $this->note($r, ['to' => $to->id]),
            ]);
            $this->svc()->record([
                'employee_id' => $to->id, 'company_id' => $to->company_id,
                'kind' => 'transfer_in', 'sign' => 1, 'amount' => $d,
                'source_module' => 'custody_transfer', 'source_id' => $tid,
                'counterpart' => $custody, 'at' => now(),
                'meta' => $this->note($r, ['from' => $from->id]),
            ]);
        });
        hub_audit('تحويل عهدة', 'custody', $from->id, number_format($d, 3) . " → {$to->name}");

        return back()->with('ok', '🔁 حُوّلت العهدة');
    }

    /**
     * خصمٌ يصالح سلفةً في مسيّر الرواتب (`PayrollLine.advance`) — يُنقص العهدة (−1)
     * بمقدار السلفة، ومصدرُه سطرُ المسيّر فلا يُخصَم مرّتين (UNIQUE على المصدر).
     */
    public function deduction(Request $r)
    {
        $this->can('e');
        $line = PayrollLine::find((string) $r->input('line_id'));
        abort_unless($line, 404);
        // العزلُ عبر الموظف: سطرُ مسيّرٍ لموظفِ شركةٍ أجنبيةٍ لا يُبلَغ
        $emp = $this->employeeScoped($line->emp_id);
        $adv = round((float) $line->advance, 3);
        abort_if($adv <= 0, 422, 'لا سلفةَ في سطر المسيّر تُصالَح');

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'deduction', 'sign' => -1, 'amount' => $adv,
            'source_module' => 'payroll_lines', 'source_id' => $line->id,
            'at' => now(), 'meta' => $this->note($r, ['run_id' => $line->run_id]),
        ]);
        hub_audit('خصم سلفة راتب', 'custody', $emp->id, number_format($adv, 3) . ' — ' . $emp->name);

        return back()->with('ok', '📉 صولِحت السلفةُ بخصمٍ من الراتب');
    }

    /** تسويةٌ ختامية — تُصفّر الرصيدَ المشتقّ بحركةٍ معاكسةِ اتّجاهِ الرصيد */
    public function settlement(Request $r)
    {
        $this->can('e');
        $emp = $this->employeeScoped($r->input('employee_id'));
        $bal = round((float) $emp->custody_balance, 3);
        abort_if(abs($bal) < 0.001, 422, 'الرصيدُ صفرٌ أصلاً — لا شيءَ يُسوّى');

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'settlement', 'sign' => $bal > 0 ? -1 : 1, 'amount' => abs($bal),
            'source_module' => 'custody_settlement', 'source_id' => (string) Str::uuid(),
            'at' => now(), 'meta' => $this->note($r),
        ]);
        hub_audit('تسوية عهدة', 'custody', $emp->id, number_format(abs($bal), 3) . ' — ' . $emp->name);

        return back()->with('ok', '✅ سُوّيت العهدةُ إلى صفر');
    }

    /**
     * تصحيحٌ يدويّ (step-up) — حركةُ تعديلٍ صريحةٌ لا تعديلَ صامتٌ لصفٍّ مُرحَّل.
     * خلفَ `hub_require_stepup`: لا يُصحَّح رصيدٌ ماليٌّ بجلسةٍ مسروقةٍ وحدَها.
     */
    public function correct(Request $r)
    {
        $this->can('approve');
        if ($resp = hub_require_stepup()) return $resp;
        $emp = $this->employeeScoped($r->input('employee_id'));
        $d = $this->amount($r);
        $sign = (int) $r->input('sign') === -1 ? -1 : 1;
        $reason = mb_substr((string) $r->input('reason'), 0, 300);
        abort_if($reason === '', 422, 'التصحيحُ يتطلب سبباً موثَّقاً');

        $this->svc()->record([
            'employee_id' => $emp->id, 'company_id' => $emp->company_id,
            'kind' => 'correction', 'sign' => $sign, 'amount' => $d,
            'source_module' => 'custody_correction', 'source_id' => (string) Str::uuid(),
            'at' => now(), 'meta' => ['reason' => $reason, 'by' => auth()->id()],
        ]);
        hub_audit('تصحيح عهدة', 'custody', $emp->id, number_format($d, 3) . ' — ' . $reason);

        return back()->with('ok', '🛠️ سُجّل التصحيح');
    }

    /**
     * عكسٌ (step-up) — يُنشئ صفاً معاكسَ الإشارة **وقيداً معاكساً** (مرآةُ الأصل)
     * عبر `CustodyPostingService::reverse`. لا حذفَ: الأثرُ يُصافى لا يُمحى؛ والعكسُ
     * المزدوجُ للحركةِ نفسِها محجوبٌ في النموذج فيُلتقَط هنا رسالةً لا انفجاراً.
     */
    public function reverse(Request $r, string $id)
    {
        $this->can('approve');
        if ($resp = hub_require_stepup()) return $resp;
        $move = $this->moveScoped($id);

        try {
            $rev = $this->svc()->reverse($move, (string) $r->input('reason'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
        hub_audit('عكس حركة عهدة', 'custody', $move->id, 'عُكست بحركة ' . $rev->id);

        return back()->with('ok', '↩️ عُكست الحركةُ بقيدٍ معاكس');
    }

    /* ══════════════════════ مساعِدات ══════════════════════ */

    /** المبلغُ: مقدارٌ موجبٌ ضمن decimal(16,3) — الاتجاهُ في الإشارة لا في المبلغ */
    protected function amount(Request $r): float
    {
        $d = $r->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
        ]);

        return round((float) $d['amount'], 3);
    }

    /** ملاحظةٌ اختياريّةٌ في meta (مقصوصةٌ لعرضٍ معقول) + حقولٌ إضافية */
    protected function note(Request $r, array $extra = []): array
    {
        $n = trim((string) $r->input('note'));

        return array_filter(array_merge($extra, [
            'note' => $n !== '' ? mb_substr($n, 0, 300) : null,
        ]), fn ($v) => $v !== null);
    }
}
