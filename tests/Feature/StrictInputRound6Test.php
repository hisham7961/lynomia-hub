<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٣: **قيمةٌ تمرّ على SQLite وتُسقط MySQL، ومعاملٌ
 * يُسقط شاشة.**
 *
 * كلُّ ما هنا من صنفٍ واحد: مدخلٌ يقبله المحرّكُ المتساهل ويرفضه الصارم — فيقع
 * العطلُ **في الإنتاج وحده** بينما الحزمةُ خضراء. أو معاملُ رابطٍ يتحكّم به
 * الطالب فيرمي استثناءً غير ملتقط.
 *
 *  ١) `ImportController:166` — `cast()` تفحص طولَ النصّ ولا تفحص **مدى العدد**:
 *     قيمةٌ فائضة تُقبل على SQLite وتُسقط **الدفعة كلَّها** بـ22003 على MySQL.
 *  ٢) `ImportController:216` — الاستيراد يتجاوز قائمةَ خيارات حقول `sel`:
 *     يزرع حالاتٍ لا يعرفها السجل ولا تظهر في أي مرشِّح.
 *  ٣) `StaffController:123` — التوحيد يدهس اسمَ/بريدَ الحساب بقيمة الملف
 *     **بلا قصّ**: أعمدةُ الملف أوسع من أعمدة الحساب ← 1406 على MySQL.
 *  ٤) `KpiController:147` — هدفُ المؤشر بلا **حدٍّ أدنى**: قيمةٌ سالبة ضخمة
 *     تمرّ التحقّق وتُسقط MySQL.
 *  ٥) `CalendarController:34` — `?m[]=` يصل `preg_match` مصفوفةً ← TypeError.
 *  ٦) `CapacityController:26` — `Carbon::parse` على نصٍّ حرٍّ من الرابط.
 */
class StrictInputRound6Test extends TestCase
{
    /* ── ١+٢) الاستيراد: مدى العدد وقائمة الخيارات ── */

    /** رفعٌ ثمّ تنفيذٌ بمطابقةٍ صريحة — مسارُ الاستيراد خطوتان */
    private function importCsv(string $module, string $csv, array $map): \Illuminate\Testing\TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'data.csv', 'text/csv', null, true);

        $this->actingAs($this->owner)->post('/m/' . $module . '/import', ['file' => $file])->assertOk();

        return $this->actingAs($this->owner)->post('/m/' . $module . '/import/run', ['map' => $map]);
    }

    public function test_import_refuses_a_number_beyond_the_column_range(): void
    {
        $this->seedCore();

        // عمودُ `value` في العقود decimal(16,3) — أقصاه 9999999999999.999
        $this->importCsv('contracts',
            "العنوان,النوع,الحالة,القيمة\nعقدٌ فائض,خدمات,مسودة,99999999999999999\n",
            [0 => 'title', 1 => 'type', 2 => 'status', 3 => 'value']);

        $row = \App\Models\Contract::where('title', 'عقدٌ فائض')->first();
        $this->assertTrue($row === null || (float) $row->value <= 9999999999999.999,
            'الاستيرادُ كتب عدداً يتجاوز مدى العمود — يمرّ على SQLite ويُسقط الدفعة كلها '
            . 'بـ22003 على MySQL، فالعطلُ في الإنتاج وحده');
    }

    public function test_import_refuses_a_status_outside_the_defined_options(): void
    {
        $this->seedCore();

        $this->importCsv('contracts',
            "العنوان,النوع,الحالة\nعقدٌ بحالةٍ مخترعة,خدمات,حالةٌ لا وجود لها\n",
            [0 => 'title', 1 => 'type', 2 => 'status']);

        $row = \App\Models\Contract::where('title', 'عقدٌ بحالةٍ مخترعة')->first();
        $this->assertTrue($row === null || (string) $row->status !== 'حالةٌ لا وجود لها',
            'الاستيرادُ زرع حالةً خارج قائمة الخيارات — لا تظهر في أي مرشِّح ولا يعرفها السجل');
    }

    /* ── ٣) التوحيد يقصّ لعرض عمود الحساب ── */

    public function test_align_truncates_to_the_account_column_width(): void
    {
        $this->seedCore();

        // ‏٢٠٠ محرفاً: يسع عمودَ الملف (`employees.name` = 300) ويتجاوز عمودَ
        // الحساب (`users.name` = 160) — وهذا بالضبط ما يجعل التوحيدَ يدهسه
        $long = mb_substr(str_repeat('اسمٌ طويل ', 40), 0, 200);
        $emp = Employee::create(['name' => $long, 'status' => 'نشط']);
        $u = User::create(['name' => 'حسابٌ مربوط', 'email' => 'al' . Str::random(5) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $emp->forceFill(['user_id' => $u->id])->saveQuietly();

        $res = $this->actingAs($this->owner)->post('/staff/' . $emp->id . '/align');

        $this->assertNotSame(500, $res->getStatusCode(),
            'التوحيدُ يدهس اسمَ الحساب بقيمةٍ أوسع من عموده — 1406 على MySQL');
        $this->assertLessThanOrEqual(160, mb_strlen((string) $u->fresh()->name),
            'الاسمُ المكتوب أطولُ من عمود الحساب');
    }

    /* ── ٤) هدفُ المؤشر بحدٍّ أدنى ── */

    public function test_kpi_target_has_a_lower_bound(): void
    {
        $this->seedCore();

        $res = $this->actingAs($this->owner)->post('/kpis', [
            'name' => 'مؤشرٌ سالب', 'target' => '-999999999999999999',
            'good' => 'up', 'a_agg' => 'count', 'a_module' => 'clients',
        ]);

        $this->assertNotSame(500, $res->getStatusCode(),
            'هدفٌ سالبٌ ضخم يمرّ التحقّق ويُسقط MySQL بـ22003');
        $k = \App\Models\KpiDef::where('name', 'مؤشرٌ سالب')->first();
        $this->assertTrue($k === null || (float) $k->target >= -999999999999.0,
            'كُتب هدفٌ خارج مدى العمود');
    }

    /* ── ٥+٦) معاملُ رابطٍ لا يُسقط شاشة ── */

    public function test_calendar_and_capacity_survive_hostile_query_params(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/calendar?m[]=x')->assertStatus(200);
        $this->actingAs($this->owner)->get('/calendar?m=' . urlencode('٢٠٢٦-١٣'))->assertStatus(200);
        $this->actingAs($this->owner)->get('/capacity?from=not-a-date&to=]]]')->assertStatus(200);
        $this->actingAs($this->owner)->get('/capacity?from[]=x')->assertStatus(200);
    }
}
