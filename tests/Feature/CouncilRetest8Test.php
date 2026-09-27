<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ الثامن — ثلاثةُ عيوبٍ أثبتها على الشاشةِ الحيّة، اثنانِ منها
 * **من صنعِ إصلاحِ v2.511.0**.
 *
 * ن-١ و ن-٢: الخروجُ المبكّرُ الذي كُتب ليُحرّرَ المسيّراتِ القديمةَ المحاصَرة
 *   (`if ($m->exists && $was === $m->month_key) return;`) يتخطّى فحصَ التضارِّ في
 *   **كلِّ** حفظٍ لا يتغيّر فيه الشهر — وبابانِ آخرانِ يُحدثانِ التضارَّ بلا مسِّ
 *   الشهر: **نقلُ المسيّرِ إلى شركةٍ أخرى**، و**استعادتُه من سلّةِ المحذوفات**.
 *   والنتيجةُ هي الضررُ المكتوبُ في رسالةِ الحارسِ نفسِها: «اعتمادُ مسيّرين لشهرٍ
 *   واحد يصرف الراتبَ مرّتين».
 *
 * ن-٣: `Workday::checkOut` تحسبُ عبورَ منتصفِ الليل ضمنيّاً (`$mins += 24*60`)
 *   ولا ترفعُ رايةَ `overnight` أبداً، ونموذجُ `Attendance` يرفضُ الانصرافَ قبل
 *   الدخولِ ما لم تُرفع. فالفرعُ المُعلَّقُ عليه في `Workday` **ميّتٌ لا يُحفَظ**،
 *   ومن بدأ ورديتَه ٢٣:٠٠ لا يستطيع تسجيلَ انصرافِه إطلاقاً.
 */
class CouncilRetest8Test extends TestCase
{
    private function member(string $email): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]], 'companies' => null]);

        return User::create(['name' => 'موظّف ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'name_ar' => $name, 'status' => 'نشطة']);
    }

    private function payrollRun(string $name, string $month, ?string $companyId): PayrollRun
    {
        return PayrollRun::create(['name' => $name, 'month' => $month, 'company_id' => $companyId]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ن-١ — نقلُ الشركةِ يُحدث التضارَّ بلا مسِّ الشهر ═══════════ */

    public function test_moving_a_run_to_a_company_that_already_has_that_month_is_refused(): void
    {
        $this->seedCore();
        $a = $this->company('شركة أ');
        $b = $this->company('شركة ب');

        $mine = $this->payrollRun('يونيو-أ', '2026-06', $a->id);
        $this->payrollRun('يونيو-ب', '2026-06', $b->id);

        // الشهرُ لم يُمسّ — الشركةُ وحدَها تغيّرت، والتضارُّ يقع
        $this->expectException(ValidationException::class);
        $mine->company_id = $b->id;
        $mine->save();
    }

    public function test_moving_a_run_to_a_free_company_still_works(): void
    {
        $this->seedCore();
        $a = $this->company('شركة ج');
        $b = $this->company('شركة د');

        $mine = $this->payrollRun('يوليو-ج', '2026-07', $a->id);
        $mine->company_id = $b->id;
        $mine->save();

        $this->assertSame($b->id, $mine->fresh()->company_id, 'نقلٌ مشروعٌ إلى شركةٍ خاليةٍ لا يُمنع');
    }

    public function test_editing_a_legacy_duplicate_without_touching_month_or_company_still_saves(): void
    {
        $this->seedCore();
        $c = $this->company('شركة هـ');

        // **محاكاةُ قاعدةٍ مُرقّاة:** الفهرسُ الفريدُ يُسقَط كما تفعل الهجرةُ نفسُها
        // حين تجد تضارّاً قائماً — وإلّا فالمكرَّرُ القديمُ لا يمكن وجودُه أصلاً.
        \Illuminate\Support\Facades\Schema::table('payroll_runs', function ($t) {
            try { $t->dropUnique('payroll_runs_company_month_uniq'); } catch (\Throwable $e) { }
        });

        // مكرَّرانِ قديمانِ سابقانِ للحارس — يُكتبانِ خامّاً كما في قاعدةٍ مُرقّاة
        $one = $this->payrollRun('قديمٌ أوّل', '2026-05', $c->id);
        \Illuminate\Support\Facades\DB::table('payroll_runs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'قديمٌ ثانٍ',
            'month' => '2026-05', 'month_key' => '2026-05-01', 'company_id' => $c->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $one->name = 'قديمٌ أوّل — مُحرَّر';
        $one->save();   // لا شهرَ ولا شركةَ تغيّرت ⇒ يمرّ (مكسبُ v2.511.0 محفوظ)

        $this->assertSame('قديمٌ أوّل — مُحرَّر', $one->fresh()->name);
    }

    /* ═══════════ ن-٢ — الاستعادةُ من السلّة تُحدث التضارَّ كذلك ═══════════ */

    public function test_restoring_a_run_into_a_now_occupied_month_is_refused(): void
    {
        $this->seedCore();
        $c = $this->company('شركة و');

        $first = $this->payrollRun('فبراير الأوّل', '2026-02', $c->id);
        $first->delete();                       // إلى السلّة
        $this->payrollRun('فبراير الثاني', '2026-02', $c->id);   // الشهرُ صار مشغولاً

        $this->expectException(ValidationException::class);
        $first->restore();
    }

    public function test_restoring_a_run_into_a_free_month_still_works(): void
    {
        $this->seedCore();
        $c = $this->company('شركة ز');

        $r = $this->payrollRun('مارس', '2026-03', $c->id);
        $r->delete();
        $r->restore();

        $this->assertNull($r->fresh()->deleted_at, 'استعادةٌ مشروعةٌ إلى شهرٍ خالٍ لا تُمنع');
    }

    /* ═══════════ ن-٣ — «انصراف» لوردياتٍ تعبر منتصفَ الليل ═══════════ */

    public function test_check_out_after_midnight_saves_instead_of_being_refused(): void
    {
        $this->seedCore();
        $u = $this->member('night-shift@test.local');
        Employee::create(['name' => 'حارسُ الليل', 'status' => 'نشط', 'user_id' => $u->id]);

        // حضورٌ الساعةَ ٢٣:٠٠
        Carbon::setTestNow(Carbon::parse('2026-09-10 23:00:00'));
        $in = \App\Support\Workforce\Workday::checkIn($u, ['mode' => 'مكتب']);
        $this->assertTrue($in['ok'] ?? false, 'الحضورُ الليليُّ نفسُه يُسجَّل');

        // وانصرافٌ الساعةَ ٠٣:٤٨ من اليومِ التالي — **من اليومِ نفسِه في السجلّ**
        Carbon::setTestNow(Carbon::parse('2026-09-11 03:48:00'));
        $out = \App\Support\Workforce\Workday::checkOut($u);

        $this->assertTrue($out['ok'] ?? false,
            'انصرافُ الوردية الليلية مرفوض — الموظّفُ لا يملك تصحيحَ الوقتَين: ' . ($out['msg'] ?? ''));

        $row = Attendance::query()->orderBy('id')->first();
        $this->assertSame('03:48:00', (string) $row->time_out, 'الانصرافُ لم يُحفَظ');
        $this->assertTrue((bool) $row->overnight, 'العبورُ حُسب ضمنيّاً ولم تُرفع الراية — تعريفان لسؤالٍ واحد');
        $this->assertEqualsWithDelta(4.8, (float) $row->hours, 0.05, 'الساعاتُ تُغذّي الرواتب فلتكن صحيحة');

        // واللحظتانِ المُشتقّتانِ تعبرانِ اليومَ كما يعبر الواقع
        $this->assertSame('2026-09-10 23:00:00', $row->in_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 03:48:00', $row->out_at?->format('Y-m-d H:i:s'));
    }

    public function test_forgetting_to_check_out_does_not_get_yesterdays_row_closed_today(): void
    {
        $this->seedCore();
        $u = $this->member('forgot@test.local');
        Employee::create(['name' => 'الناسي', 'status' => 'نشط', 'user_id' => $u->id]);

        // حضورُ الأمسِ ٠٨:٠٠ ونسيَ الانصراف
        Carbon::setTestNow(Carbon::parse('2026-09-10 08:00:00'));
        \App\Support\Workforce\Workday::checkIn($u, ['mode' => 'مكتب']);

        // واليومَ الخامسةَ مساءً يضغط «انصراف» — الساعةُ لم تلفّ، فلا يُتبنّى صفُّ الأمس
        Carbon::setTestNow(Carbon::parse('2026-09-11 17:00:00'));
        $out = \App\Support\Workforce\Workday::checkOut($u);

        $this->assertFalse($out['ok'] ?? true, 'يومُ الناسي أُغلق بساعاتٍ ملفَّقة');
        $this->assertStringContainsString('سجّل حضورَك أولاً', (string) ($out['msg'] ?? ''));

        $yesterday = Attendance::query()->orderBy('id')->first();
        $this->assertNull($yesterday->time_out, 'صفُّ الأمسِ بقي مفتوحاً كما كان');
        $this->assertFalse((bool) $yesterday->overnight);
    }

    public function test_ordinary_day_check_out_is_unchanged(): void
    {
        $this->seedCore();
        $u = $this->member('day-shift@test.local');
        Employee::create(['name' => 'موظّفُ النهار', 'status' => 'نشط', 'user_id' => $u->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-10 08:00:00'));
        \App\Support\Workforce\Workday::checkIn($u, ['mode' => 'مكتب']);
        Carbon::setTestNow(Carbon::parse('2026-09-10 17:00:00'));
        $out = \App\Support\Workforce\Workday::checkOut($u);

        $this->assertTrue($out['ok'] ?? false);
        $row = Attendance::query()->orderBy('id')->first();
        $this->assertFalse((bool) $row->overnight, 'يومٌ عاديٌّ لا تُرفع له الرايةُ بلا سبب');
        $this->assertEqualsWithDelta(9.0, (float) $row->hours, 0.01);
    }
}
