<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Tests\TestCase;

/**
 * **تحويلُ مواضعِ الإجازات إلى مدىً لم يغيّر صفّاً واحداً** (#33 · §٥ · v2.596.0).
 *
 * ── **ما قِيس قبل التحويل، وما صحّحه القياس** ──
 *
 * قال السجلُّ إنّ `date_from`/`date_to` **بلا أيِّ فهرس** — وهو صحيح. **لكنّ
 * الأساسَ لم يكن مسحاً كاملاً** كما يوحي، لأنّ `emp_id` مفهرسٌ وحدَه وكلُّ
 * قارئٍ من العشرةِ يسأل عن موظّفٍ بعينِه. فـ`EXPLAIN` على أربعةِ آلافِ صفٍّ
 * لأربعين موظّفاً على MariaDB 10.11:
 *
 * | الحال | type | key | rows | Extra |
 * |---|---|---|---|---|
 * | قبل الفهرس المركّب | `ref` | `…emp_id_index` | ١٠٠ | `Using index condition` |
 * | بالفهرس المركّب، بلا تحويل | `ref` | `…emp_dates_idx` | ١٠٠ | **`Using index`** (مُغطٍّ) |
 * | بالفهرس المركّب + المدى | **`range`** | `…emp_dates_idx` | **٥٤** | `Using index` |
 *
 * **فالمكسبان اثنان لا واحد:** الفهرسُ يجعل القراءةَ **مُغطّاةً** (لا يلمس
 * صفوفَ الجدول)، والمدى **يُنصّف** ما يُفحَص منه. ولا يُغني أحدُهما عن الآخر.
 *
 * ── **والخطرُ في التحويل لا في بُطئه** ──
 *
 * `whereDate(col, '<=', X)` تعني «كلَّ لحظاتِ يومِ X»، و`col <= 'X'` نصّاً
 * **تُخطئ الصفَّ** ذا الوقت. فالمدى نصفُ المفتوح وحدَه يلتقط الصيغتَين —
 * وهذا الملفُّ يُثبِت التكافؤَ **صفّاً بصفّ** على المحرّكَين لا يدّعيه.
 */
class LeaveRangeEquivalenceTest extends TestCase
{
    /** **حدودُ اليوم مقطوعةٌ من الطرفَين** — يومٌ قبلَ البدء، ويومٌ بعد الانتهاء */
    public function test_الإجازة_تُرى_في_أيّامها_وحدَها(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'نورةُ المطيري', 'status' => 'نشط', 'leave_bal' => 365]);
        $this->leave($e->id, '2026-06-10', '2026-06-12');

        $this->assertFalse(Workday::onLeave($e->id, '2026-06-09'), 'يومٌ قبلَ البدءِ عُدَّ إجازة');
        $this->assertTrue(Workday::onLeave($e->id, '2026-06-10'),  'يومُ البدءِ نفسُه سقط');
        $this->assertTrue(Workday::onLeave($e->id, '2026-06-11'),  'يومٌ في المنتصفِ سقط');
        $this->assertTrue(Workday::onLeave($e->id, '2026-06-12'),  'يومُ الانتهاءِ نفسُه سقط — وهذه كسرةُ `<= "Y-m-d"` نصّاً');
        $this->assertFalse(Workday::onLeave($e->id, '2026-06-13'), 'يومٌ بعدَ الانتهاءِ عُدَّ إجازة');
    }

    /**
     * **الصفُّ القديمُ بـ`date_to` فارغٍ يبقى مفتوحاً** — وفرعُ `orWhereNull`
     * لم يُمَسّ بالتحويل.
     *
     * **ولا يُنشَأ هذا الصفُّ من النموذج**: خطّافُ `saving` يملأ `date_to`
     * بـ`date_from` متى تُرك فارغاً، فالمفتوحُ **غيرُ قابلٍ للإنشاءِ اليوم**.
     * والفرعُ يحرس صفوفاً كُتبت قبل الخطّاف — يقول ذلك تعليقُ النموذجِ نفسُه
     * («وصفٌّ قائمٌ بـ`date_to` فارغ»). فيُكتَب هنا **بـ`DB::table` مباشرةً**،
     * وهو شكلُه الحقيقيُّ في قاعدةٍ قديمة. واكتُشف هذا بمسبارٍ قارن القارئَ
     * المحوَّلَ بـ`whereDate` فوجدهما **متّفقَين على `false`** — فالخطأُ كان
     * في توقّعِ الاختبار لا في التحويل.
     */
    public function test_صفٌّ_قديمٌ_بلا_نهايةٍ_يبقى_مفتوحاً(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'بدرُ العتيبي', 'status' => 'نشط', 'leave_bal' => 365]);
        $types = (array) config('hub.leave.deduct_types', []);

        \Illuminate\Support\Facades\DB::table('leave_requests')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'emp_id' => $e->id,
            'status' => 'معتمد', 'type' => (string) ($types[0] ?? 'سنوية'),
            'date_from' => '2026-06-10', 'date_to' => null, 'version' => 1, 'archived' => 0,
        ]);

        $this->assertFalse(Workday::onLeave($e->id, '2026-06-09'), 'يومٌ قبلَ البدءِ عُدَّ إجازة');
        $this->assertTrue(Workday::onLeave($e->id, '2026-06-10'), 'يومُ البدءِ سقط');
        $this->assertTrue(Workday::onLeave($e->id, '2027-01-01'),
            'الصفُّ المفتوحُ أُغلق بالتحويل — وفرعُ `orWhereNull` هو حارسُ القديم');
    }

    /**
     * **والتكافؤُ يُثبَت بالمقارنةِ لا بالدعوى** — القارئُ المحوَّلُ مقابلَ
     * `whereDate` على البيانات نفسِها، يوماً بيوم عبر أحدَ عشرَ يوماً.
     */
    public function test_القارئُ_المحوَّل_يطابق_whereDate_يوماً_بيوم(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'هيا الرشيد', 'status' => 'نشط', 'leave_bal' => 365]);
        $this->leave($e->id, '2026-06-10', '2026-06-12');
        $this->leave($e->id, '2026-06-20', null);

        $types = (array) config('hub.leave.deduct_types', []);
        for ($d = 8; $d <= 22; $d++) {
            $day = sprintf('2026-06-%02d', $d);

            $viaFunction = LeaveRequest::whereNull('deleted_at')->where('emp_id', $e->id)
                ->where('status', 'معتمد')->whereIn('type', $types)
                ->whereDate('date_from', '<=', $day)
                ->where(fn ($q) => $q->whereDate('date_to', '>=', $day)->orWhereNull('date_to'))
                ->exists();

            $this->assertSame($viaFunction, Workday::onLeave($e->id, $day),
                "التحويلُ غيّر جوابَ يومِ {$day} — والتحويلُ الذي يغيّر صفّاً ليس تحسيناً");
        }
    }

    /**
     * **والقارئُ الجَمعيُّ كذلك** — `excusedMap` على مدىً لا يومٍ واحد.
     *
     * **ونوعُه ليس نوعَ `onLeave`**: هذا القارئُ يقرأ `nonLeaveExcuseTypes()`
     * (إذنُ خروجٍ وعملٌ عن بُعد) **دون** أنواعِ الخصم — وهو فرقٌ مقصودٌ موصوفٌ
     * في الصنف. فالتهيئةُ هنا بعذرٍ غيرِ خصميّ، وإلّا قِيس قارئٌ بغير بياناتِه.
     */
    public function test_القارئُ_الجمعيّ_يلتقط_المتداخلةَ_مع_المدى(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'سلمانُ الحربي', 'status' => 'نشط', 'leave_bal' => 365]);
        $this->excuse($e->id, '2026-06-10', '2026-06-12');

        $inside  = DailyWorkCompliance::excusedMap([$e->id], '2026-06-11', '2026-06-11');
        $touches = DailyWorkCompliance::excusedMap([$e->id], '2026-06-12', '2026-06-20');
        $outside = DailyWorkCompliance::excusedMap([$e->id], '2026-06-13', '2026-06-20');

        $this->assertArrayHasKey($e->id, $inside, 'يومٌ داخلَ الإجازةِ لم يلتقطها');
        $this->assertArrayHasKey($e->id, $touches, 'مدىً يبدأ بيومِ الانتهاءِ نفسِه لم يلتقطها');
        $this->assertArrayNotHasKey($e->id, $outside, 'مدىً بعدَ الانتهاءِ التقط إجازةً منتهية');
    }

    /** عذرٌ معتمَدٌ **غيرُ خصميّ** — كما يقرؤه `excusedMap` وحدَه */
    protected function excuse(string $empId, string $from, string $to): void
    {
        $types = DailyWorkCompliance::nonLeaveExcuseTypes();
        LeaveRequest::create([
            'emp_id' => $empId, 'status' => 'معتمد',
            'type' => (string) ($types[0] ?? 'إذن خروج'),
            'date_from' => $from, 'date_to' => $to,
        ]);
    }

    /** إجازةٌ معتمَدةٌ من نوعٍ يُخصَم — كما يقرؤها `Workday::onLeave` */
    protected function leave(string $empId, string $from, ?string $to): void
    {
        $types = (array) config('hub.leave.deduct_types', []);
        LeaveRequest::create([
            'emp_id' => $empId, 'status' => 'معتمد',
            'type' => (string) ($types[0] ?? 'سنوية'),
            'date_from' => $from, 'date_to' => $to,
        ]);
    }
}
