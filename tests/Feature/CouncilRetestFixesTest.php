<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — ما كذّبه التحقّقُ في v2.509.0 و v2.510.0.**
 *
 * | # | ما وجده المتحقّق | مصدرُه |
 * |---|---|---|
 * | ١ | **زوجٌ متساوٍ + رايةُ الليليّة = ٢٤ ساعةً صامتة** | إصلاحي في v2.510.0 |
 * | ٢ | **ختمُ `document_access_rules` جامد** — المنعُ لا يسري «فوراً» | إصلاحي في v2.509.0 |
 * | ٣ | **مسيّرانِ قديمانِ متضارّانِ صارا غيرَ قابلَين للتعديل** | إصلاحي في v2.508.0 |
 *
 * وثلاثتُها **من صنعِ الإصلاح**، والأوّلُ أخطرُها: الساعاتُ تُغذّي الرواتبَ
 * والامتثال، فنقرةٌ في غيرِ محلِّها تُحوّل يومَ صفرٍ إلى يومٍ كامل **بلا وسمٍ
 * ولا تحذير** — وهو عينُ الخطرِ الذي احتُجَّ به لجعلِ الرايةِ صريحة.
 */
class CouncilRetestFixesTest extends TestCase
{
    // ═════════ ١ · الزوجُ المتساوي بالراية ═════════

    public function test_an_identical_pair_is_zero_even_with_the_overnight_flag(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'حارسُ محطّة', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('m.store', 'attend'), [
            'empId' => $e->id, 'date' => '2026-09-12', 'in' => '09:00', 'out' => '09:00',
            'overnight' => '1',
        ]);

        $row = Attendance::where('emp_id', $e->id)->first();
        $this->assertNotNull($row, 'تهيئةٌ خاطئة: الصفُّ لم يُحفظ');
        $this->assertEqualsWithDelta(0.0, (float) $row->hours, 0.01,
            '**٢٤ ساعةً صامتة**: دخولٌ وانصرافٌ في اللحظةِ نفسِها مع رايةِ الليليّةِ '
            . 'صار يوماً كاملاً — والساعاتُ تُغذّي الرواتبَ والامتثال. '
            . 'و`$out <= $in` تبتلع المساواة؛ الصحيحُ `<` فالمساواةُ صفرٌ لا عبور.');
    }

    public function test_a_real_night_shift_is_still_eight_hours(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'حارسٌ ليليّ', 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('m.store', 'attend'), [
            'empId' => $e->id, 'date' => '2026-09-13', 'in' => '22:00', 'out' => '06:00',
            'overnight' => '1',
        ]);

        $row = Attendance::where('emp_id', $e->id)->firstOrFail();
        $this->assertEqualsWithDelta(8.0, (float) $row->hours, 0.01,
            '**قدرةٌ نُزعت**: الليليّةُ الحقيقيّةُ لم تعد ثمانيَ ساعات');
        $this->assertSame('2026-09-14 06:00:00', (string) $row->out_at,
            'اللحظةُ المطلقةُ لم تعبر منتصفَ الليل');
    }

    // ═════════ ٢ · ختمُ قواعدِ الوثائق ═════════

    public function test_an_access_rule_takes_effect_immediately(): void
    {
        $this->seedCore();
        $stampBefore = (string) \Illuminate\Support\Facades\Cache::get('hub:stamp:document_access_rules', 0);

        Storage::disk('local')->put('hub/r.pdf', 'x');
        $emp = Employee::create(['name' => 'صاحبُ ملفّ', 'status' => 'نشط']);
        $doc = Attachment::create(['module' => 'hr', 'record_id' => $emp->id,
            'path' => 'hub/r.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'r.pdf', 'uploaded_by' => $this->owner->id,
            'kind' => 'id', 'expires_at' => now()->addDays(5)]);

        $this->actingAs($this->owner)->post(route('att.access', $doc->id), [
            'effect' => 'deny', 'action' => '*', 'users' => [$this->owner->id],
        ]);

        $stampAfter = (string) \Illuminate\Support\Facades\Cache::get('hub:stamp:document_access_rules', 0);

        $this->assertNotSame($stampBefore, $stampAfter,
            '**وعدٌ لم يُنفَّذ**: ختمُ `document_access_rules` لا يتحرّك — '
            . '`AttachmentController::access()` يكتب الجدولَ خاماً بـ`DB::table` فلا حدثَ '
            . 'Eloquent ولا ختم. فالمنعُ الصريحُ لا يسري «فوراً» بل بعد خمسِ دقائق، '
            . 'والختمُ المُضاف زينةٌ — وهو نمطُ «زرٍّ يَعِد بما لا يفعل» بعينِه.');
    }

    public function test_clearing_an_access_rule_also_moves_the_stamp(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/r2.pdf', 'x');
        $emp = Employee::create(['name' => 'صاحبُ ملفٍّ ٢', 'status' => 'نشط']);
        $doc = Attachment::create(['module' => 'hr', 'record_id' => $emp->id,
            'path' => 'hub/r2.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'r2.pdf', 'uploaded_by' => $this->owner->id,
            'kind' => 'id', 'expires_at' => now()->addDays(5)]);

        $this->actingAs($this->owner)->post(route('att.access', $doc->id), [
            'effect' => 'deny', 'action' => '*', 'users' => [$this->owner->id],
        ]);
        $rule = DB::table('document_access_rules')->where('resource_id', $doc->id)->first();
        $this->assertNotNull($rule, 'تهيئةٌ خاطئة: القاعدةُ لم تُكتب');

        $before = (string) \Illuminate\Support\Facades\Cache::get('hub:stamp:document_access_rules', 0);
        $this->actingAs($this->owner)->delete(route('att.access.clear', [$doc->id, $rule->id]));
        $after = (string) \Illuminate\Support\Facades\Cache::get('hub:stamp:document_access_rules', 0);

        $this->assertNotSame($before, $after,
            'رفعُ المنعِ لا يُحرّك الختمَ كذلك — فالوثيقةُ تبقى مخفيّةً خمسَ دقائقَ بعد السماح');
    }

    // ═════════ ٣ · المسيّرُ القديمُ المتضارّ ═════════

    /**
     * **الرسالةُ تأمر «عدّل القائم» ثمّ يرفض الحارسُ ذلك التعديلَ بعينِه.**
     *
     * قاعدةٌ مُرقّاةٌ تحمل مسيّرَين قديمَين لشهرٍ واحد: كلٌّ يرى الآخرَ فيُرفض
     * حفظُه — **بلا أن يُمسّ الشهرُ أصلاً**. فصفوفٌ قائمةٌ صارت للقراءةِ فقط،
     * والمحاسبُ محاصَر: لا يُنشئ، ولا يُعدّل، ولا الأمرُ العلاجيُّ يدمج.
     *
     * والحارسُ يجب أن يمنع **إحداثَ** تضارٍّ لا أن يُجمّد ما سبقه: فيُفحَص
     * حين يُنشأ الصفُّ أو حين **يتغيّر شهرُه**، لا في كلِّ حفظ.
     */
    public function test_a_legacy_duplicate_run_can_still_be_edited(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة', 'name_en' => 'C' . Str::random(4), 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => 'تشغيلة أولى', 'month' => '2026-08', 'companyId' => $c->id, 'status' => 'مسودة',
        ]);
        $first = PayrollRun::where('company_id', $c->id)->firstOrFail();

        // محاكاةُ قاعدةٍ مُرقّاةٍ كانت تحمل مكرَّراً: الهجرةُ تتخطّى الفهرسَ الفريدَ
        // فيها عمداً (وإلّا سقطت الترقية) — فيُسقَط هنا كما هو حالُها
        \Illuminate\Support\Facades\Schema::table('payroll_runs',
            fn ($t) => $t->dropUnique('payroll_runs_company_month_uniq'));
        DB::table('payroll_runs')->insert([
            'id' => (string) Str::uuid(), 'name' => 'تشغيلة ثانية', 'month' => 'أغسطس 2026',
            'month_key' => '2026-08-01', 'company_id' => $c->id, 'version' => 1,
            'archived' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $first->total = 7500;
        $first->save();     // لا يُمسّ الشهر

        $this->assertSame('7500.000', (string) $first->fresh()->total,
            '**تجميدٌ**: مسيّرٌ قائمٌ صار غيرَ قابلٍ للتعديل لأنّ مكرَّراً قديماً يقاسمه الشهر. '
            . 'والرسالةُ تأمر «عدّل القائم» ثمّ يرفض الحارسُ ذلك التعديل.');
    }

    public function test_moving_a_run_into_an_occupied_month_is_still_refused(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة', 'name_en' => 'C' . Str::random(4), 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => 'أغسطس', 'month' => '2026-08', 'companyId' => $c->id, 'status' => 'مسودة',
        ]);
        $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => 'سبتمبر', 'month' => '2026-09', 'companyId' => $c->id, 'status' => 'مسودة',
        ]);
        $sep = PayrollRun::where('company_id', $c->id)->where('month', '2026-09')->firstOrFail();

        $sep->month = 'أغسطس 2026';      // نقلُه إلى شهرٍ مشغول
        try {
            $sep->save();
            $this->fail('**تضارٌّ جديد**: نُقل مسيّرٌ إلى شهرٍ له مسيّرٌ بالفعل');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('لنفس الشهر', $e->getMessage());
        }
    }

    public function test_a_new_duplicate_is_still_refused(): void
    {
        $this->seedCore();
        $c = Company::create(['name_ar' => 'شركة', 'name_en' => 'C' . Str::random(4), 'status' => 'نشط']);

        $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => 'أولى', 'month' => '2026-08', 'companyId' => $c->id, 'status' => 'مسودة',
        ]);
        $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => 'ثانية', 'month' => 'أغسطس 2026', 'companyId' => $c->id, 'status' => 'مسودة',
        ]);

        $this->assertSame(1, PayrollRun::where('company_id', $c->id)->count(),
            '**قدرةٌ نُزعت من الحارس**: مكرَّرٌ جديدٌ حُفظ');
    }
}
