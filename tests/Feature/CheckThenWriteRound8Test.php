<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AuthController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٥) — **قفلٌ قبل الكتابة.**
 *
 *  ١) **عدّادُ المحاولات الفاشلة غير ذرّيّ** (`AuthController`): كان
 *     `$u->failed_attempts = $u->failed_attempts + 1; save()` قراءةً في الذاكرة ثم
 *     كتابة — فطلبان متوازيان يقرآن القيمةَ نفسها فتُكتب زيادةٌ واحدةٌ عن اثنتين،
 *     فيضعف قفلُ الحساب أمام التخمين المتوازي. الآن الزيادةُ على مستوى القاعدة.
 *     **هذا يُثبَت حتميّاً بنسختين قديمتين.**
 *
 *  ٢) **حذفُ سطر قيدٍ بلا قفل** (`EntryController::dropLine`): القراءةُ الطازجة
 *     تمنع الحذفَ عن قيدٍ مُرحَّلٍ سلفاً — والقفلُ يُضاف ليُغلق الشقَّ بين الفحص
 *     والحذف تحت التزامن (نمطَ addLineTo/post). هنا اختبارُ انحدارٍ يحرس السلوكَ
 *     التسلسليّ؛ وحمايةُ التزامن تُطابق نمطاً مُختبَراً سلفاً في الملف نفسه.
 *
 * (وحارسُ «آخر مالكٍ نشط» نال القفلَ نفسَه في `UserController::destroy` — سباقٌ
 *  لا يُحاكى حتميّاً في حزمةٍ تسلسلية، فيُثبَّت بالمطابقة للنمط لا باختبارِ تزامن.)
 */
class CheckThenWriteRound8Test extends TestCase
{
    /* ── ١) العدّادُ ذرّيّ: نسختان قديمتان تُنتجان ٢ لا ١ ── */

    public function test_failed_attempts_increment_is_atomic_under_two_stale_reads(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.max_fail', '9');   // فوق العدّ كي لا يُصفَّر بالقفل

        $id = $this->employee->id;
        // نسختان قُرئتا و`failed_attempts = 0` في ذاكرتيهما — تحاكيان طلبين متوازيين
        $stale1 = User::find($id);
        $stale2 = User::find($id);
        $this->assertSame(0, (int) $stale1->failed_attempts);

        $ctrl = new AuthController();
        $ref = new \ReflectionMethod($ctrl, 'bumpFailedAttempts');
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $stale1);
        $ref->invoke($ctrl, $stale2);

        $this->assertSame(2, (int) User::find($id)->failed_attempts,
            'زيادتان من نسختين قديمتين أنتجتا واحدةً لا اثنتين — العدّادُ يقرأ الذاكرةَ '
            . 'ثم يكتب، فتُدهَس زيادةٌ ويضعف قفلُ الحساب أمام التخمين المتوازي');
    }

    /** والقفلُ ينعقد عند السقف رغم التزامن (لا يُصفَّر مرّتين متسابقتين) */
    public function test_the_lock_engages_at_the_ceiling(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.max_fail', '3');
        $this->hubSetting('auth.lock_min', '15');

        $id = $this->employee->id;
        $ctrl = new AuthController();
        $ref = new \ReflectionMethod($ctrl, 'bumpFailedAttempts');
        $ref->setAccessible(true);
        for ($i = 0; $i < 3; $i++) $ref->invoke($ctrl, User::find($id));

        $fresh = User::find($id);
        $this->assertNotNull($fresh->locked_until, 'لم يُقفل الحساب عند بلوغ السقف');
        $this->assertSame(0, (int) $fresh->failed_attempts, 'العدّادُ يُصفَّر عند القفل');
    }

    /* ── ٢) حذفُ سطرٍ من قيدٍ مُرحَّلٍ يُرفض والسطرُ يبقى (اختبارُ انحدار) ── */

    public function test_dropping_a_line_from_a_posted_entry_is_refused_and_kept(): void
    {
        $this->seedCore();
        foreach ([['1010', 'الصندوق', 'أصول'], ['4100', 'المبيعات', 'إيرادات']] as [$c, $n, $t]) {
            LedgerAccount::create(['code' => $c, 'name' => $n, 'type' => $t]);
        }
        $acc = fn ($c) => LedgerAccount::where('code', $c)->value('id');

        $e = JournalEntry::create(['doc_no' => 'JE-D-1', 'date' => now()->toDateString(), 'state' => 'مسودة']);
        $l1 = JournalLine::create(['entry_id' => $e->id, 'acc_id' => $acc('1010'), 'debit' => 100, 'credit' => 0]);
        JournalLine::create(['entry_id' => $e->id, 'acc_id' => $acc('4100'), 'debit' => 0, 'credit' => 100]);

        // رُحّل القيد ثم حاولنا حذفَ سطرٍ منه
        $this->actingAs($this->owner)->post("/entry/{$e->id}/post")->assertRedirect();
        $this->assertSame('مرحّل', $e->fresh()->state);

        $this->actingAs($this->owner)->delete("/entry/{$e->id}/line/{$l1->id}")->assertStatus(422);

        $this->assertSame(2, JournalLine::where('entry_id', $e->id)->count(),
            'حُذف سطرٌ من قيدٍ مُرحَّل — ميزانٌ مختلٌّ لا مسار واجهة لإصلاحه');
        $this->assertEqualsWithDelta(100.0, (float) JournalLine::where('entry_id', $e->id)->sum('debit'), 0.001);
    }
}
