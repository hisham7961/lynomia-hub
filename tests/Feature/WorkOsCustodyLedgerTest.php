<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * (Work OS · الطور E · WP-E.1 · §18–20) دفترُ حركاتِ العهدة المالية الثابت —
 * سِبطُ `LedgerAndStockIntegrityTest` في العهدة: كلُّ حركةٍ ماليّةٍ سطرٌ يُضاف ولا
 * يُعدَّل ولا يُحذف، والرصيدُ مشتقٌّ لا مخزَّن، والخطأُ يُصحَّح بعكسٍ لا بمحو.
 *
 *  ١) الرصيد = SUM(sign × amount) على **كل** الصفوف — لا عمودَ رصيدٍ يُحرَّر.
 *  ٢) الحركةُ المُرحَّلة (`posted_at`) ترفض UPDATE وDELETE (٤٢٢) — نمطُ StockMove.
 *  ٣) `amount` decimal(16,3) يقبل قيمةً كبيرةً ضمن المدى بثلاثِ خاناتٍ عشرية.
 *  ٤) allowlist في التطبيق (C10) يرفض نوعاً خارج القائمة العشرة.
 *  ٥) العكسُ يُنشئ صفاً معاكسَ الإشارة يشير إلى الأصل فيتصافى الرصيد؛ والعكسُ
 *     المزدوجُ للحركةِ نفسِها محجوب.
 */
class WorkOsCustodyLedgerTest extends TestCase
{
    private function employee(): Employee
    {
        return Employee::create(['name' => 'موظفُ العهدة', 'status' => 'نشط']);
    }

    /** حركةٌ مُرحَّلةٌ جاهزة للموظف */
    private function posted(Employee $e, string $kind, int $sign, float|string $amount, array $extra = []): EmployeeCustodyMove
    {
        return EmployeeCustodyMove::create(array_merge([
            'employee_id' => $e->id,
            'kind' => $kind,
            'sign' => $sign,
            'amount' => $amount,
            'approval_state' => 'approved',
            'at' => now(),
            'posted_at' => now(),
        ], $extra));
    }

    /** يؤكّد أنّ الإغلاقَ يرمي `ValidationException` (٤٢٢) لا أن يمرّ صامتاً */
    private function assertRejects(callable $fn, string $why): void
    {
        try {
            $fn();
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
    }

    /* ── ١) الرصيدُ مشتقٌّ = مجموعُ المبالغ بإشاراتها على كل الصفوف ── */

    public function test_custody_balance_is_the_sum_of_signed_amounts(): void
    {
        $this->seedCore();
        $e = $this->employee();

        // لا عمودَ رصيد: محفظةٌ بلا حركةٍ رصيدُها صفر
        $this->assertSame(0.0, $e->fresh()->custody_balance, 'المحفظةُ الفارغةُ رصيدُها صفر');

        $this->posted($e, 'advance', +1, 500);     // سلفةٌ بيد الموظف        +500
        $this->posted($e, 'expense', -1, 120);     // مصروفٌ من العهدة        -120
        $this->posted($e, 'expense', -1, 80);      // مصروفٌ آخر              -80
        $this->posted($e, 'repayment', -1, 100);   // سدادٌ من الموظف         -100

        $this->assertSame(4, EmployeeCustodyMove::where('employee_id', $e->id)->count(),
            'الأربعُ حركاتٍ كتبت — الرصيدُ يجمعها كلَّها لا واحدةً منها');
        $this->assertSame(200.0, $e->fresh()->custody_balance,
            'الرصيدُ = 500 − 120 − 80 − 100 = 200 — مشتقٌّ من كل الصفوف بإشاراتها');

        // ولا عمودَ رصيدٍ في الجدول أصلاً (لا شيءَ يُحرَّر)
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('employee_custody_moves', 'balance'),
            'وُجد عمودُ رصيدٍ في دفترِ العهدة — الرصيدُ يُشتقّ ولا يُخزَّن');
    }

    /* ── ٢) الحركةُ المُرحَّلة مقفلة: لا تعديلَ ولا حذف ── */

    public function test_a_posted_move_rejects_update_and_delete(): void
    {
        $this->seedCore();
        $e = $this->employee();
        $m = $this->posted($e, 'advance', +1, 500);

        // تعديلٌ لحركةٍ مُرحَّلة — يُرفض، والقيمةُ في القاعدة لا تتغيّر
        $this->assertRejects(fn () => $m->update(['amount' => 999]),
            'عُدِّلت حركةٌ ماليّةٌ مُرحَّلة — الدفترُ الثابتُ لا يُعدَّل');
        $this->assertSame('500.000', (string) $m->fresh()->amount,
            'رغمَ الرفضِ تغيّر المبلغُ في القاعدة — لم يُحرَس الكتابةَ فعلاً');

        // حذفٌ لحركةٍ مُرحَّلة — يُرفض، والصفُّ باقٍ في الدفتر
        $fresh = $m->fresh();
        $this->assertRejects(fn () => $fresh->delete(),
            'حُذفت حركةٌ ماليّةٌ مُرحَّلة — لا حذفَ فيزيائيٍّ لأثرٍ ماليّ');
        $this->assertSame(1, EmployeeCustodyMove::where('id', $m->id)->count(),
            'رغمَ الرفضِ اختفى الصفُّ من الدفتر — الأثرُ الماليُّ لا يُمحى');
    }

    /* ── ٣) المبلغُ decimal(16,3) يسع قيمةً كبيرةً ضمن المدى ── */

    public function test_amount_accepts_a_large_in_range_decimal(): void
    {
        $this->seedCore();
        $e = $this->employee();

        // مليارٌ وكسرٌ من ثلاثِ خانات — قيمةٌ كبيرةٌ ضمن decimal(16,3)، و١٣ رقماً
        // معنويّاً تدور بدقّةٍ على المحرّكين (SQLite REAL محدودةٌ بدقّة PHP، فتُتجنَّب
        // القيمُ عند حافّةِ الدقّة — درسُ CLAUDE.md في اختلافِ المحرّكَين).
        $m = $this->posted($e, 'charge', +1, '1000000000.125');

        $this->assertSame('1000000000.125', (string) $m->fresh()->amount,
            'المبلغُ الكبيرُ ضمن المدى لم يُخزَّن بدقّته — عرضُ decimal(16,3) أو الكاتب مختلّ');
    }

    /* ── ٤) allowlist في التطبيق (C10) يرفض نوعاً خارج القائمة ── */

    public function test_the_allowlist_rejects_an_off_list_kind(): void
    {
        $this->seedCore();
        $e = $this->employee();

        $this->assertRejects(fn () => EmployeeCustodyMove::create([
            'employee_id' => $e->id, 'kind' => 'siphon', 'sign' => -1, 'amount' => 50,
            'at' => now(), 'posted_at' => now(),
        ]), 'قُبل نوعُ حركةٍ خارج allowlist — بابٌ لنوعٍ لا يعرفه محرّكُ الترحيل');

        $this->assertSame(0, EmployeeCustodyMove::where('kind', 'siphon')->count(),
            'كُتب صفٌّ بنوعٍ خارج القائمة العشرة');
    }

    /* ── ٥) العكسُ يُصافي، والعكسُ المزدوجُ محجوب ── */

    public function test_reversal_nets_the_balance_and_double_reversal_is_blocked(): void
    {
        $this->seedCore();
        $e = $this->employee();
        $m = $this->posted($e, 'advance', +1, 300);
        $this->assertSame(300.0, $e->fresh()->custody_balance);

        $rev = $m->reverse('سلفةٌ خاطئة');

        // صفٌّ معاكسُ الإشارة يشير إلى الأصل — فيتصافى الرصيدُ إلى صفرٍ بلا محو
        $this->assertSame(-1, (int) $rev->sign, 'حركةُ العكس يجب أن تكون معاكسةَ الإشارة');
        $this->assertSame($m->id, $rev->reverses_id, 'العكسُ لا يشير إلى أصله');
        $this->assertSame('reversal', $rev->kind);
        $this->assertSame('300.000', (string) $rev->amount, 'العكسُ يحمل مقدارَ الأصل نفسَه');
        $this->assertSame(0.0, $e->fresh()->custody_balance,
            'العكسُ لم يُصافِ الرصيدَ إلى صفر — الزوجُ (300 و−300) يجب أن يجمعَ صفراً');
        $this->assertSame(2, EmployeeCustodyMove::where('employee_id', $e->id)->count(),
            'العكسُ يُضيف صفاً ولا يمحو — يبقى الأصلُ وحركتُه المعاكسة');

        // الأصلُ عُلِّم مُعكوساً (لا حُذف)
        $this->assertNotEmpty(($m->fresh()->meta['reversed_at'] ?? null),
            'الأصلُ لم يُعلَّم reversed_at — ذاكرةُ العكس ضائعة');

        // العكسُ المزدوجُ للحركةِ نفسِها محجوب
        $this->assertRejects(fn () => $m->fresh()->reverse(),
            'عُكست الحركةُ مرّتين — رصيدٌ يُصنَع من عدم');
        $this->assertSame(1, EmployeeCustodyMove::where('reverses_id', $m->id)->count(),
            'وُجد أكثرُ من عكسٍ للحركةِ نفسِها');

        // وحركةُ العكس نفسُها لا تُعكس
        $this->assertRejects(fn () => $rev->fresh()->reverse(),
            'عُكست حركةُ العكس — لعبةُ دفترٍ لا نهائية');
    }

    public function test_an_unposted_draft_move_cannot_be_reversed(): void
    {
        $this->seedCore();
        $e = $this->employee();

        // مسودةٌ لم تُرحَّل (posted_at = null) — لا أثرَ ماليٍّ يُعكس
        $draft = EmployeeCustodyMove::create([
            'employee_id' => $e->id, 'kind' => 'expense', 'sign' => -1, 'amount' => 40,
            'approval_state' => 'pending', 'at' => now(),
        ]);

        $this->assertRejects(fn () => $draft->reverse(),
            'عُكست مسودةٌ لم تُرحَّل — لا يُعكس إلا أثرٌ وقع');
    }
}
