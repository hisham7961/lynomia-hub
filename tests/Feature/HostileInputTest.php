<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use App\Models\Meeting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولةُ تدقيقٍ عدائية كتبت اختباراتٍ وحاولت الكسر فعلاً — فوقعت أخطاء ٥٠٠ من
 * مدخلاتٍ لا تحتاج صلاحيةً ولا مهارة:
 *
 *   — **معامِلٌ يصل مصفوفةً**: `?k[]=x` أو `?t[]=x` أو `?p[]=x`. الكود يكتب
 *     `(string) $request->query(...)` — وتحويل المصفوفة إلى نصّ يرمي
 *     `Array to string conversion`. خمس شاشاتٍ تسقط برابطٍ يُلصق.
 *   — **مصفوفةٌ متداخلة في حقل مرجعٍ متعدد**: تمرّ من نموذج التعديل العادي،
 *     ثم **تقتل صفحة السجل وصفحة تعديله معاً** — فلا سبيل لإصلاحه من الواجهة.
 *   — **معادلةُ KPI مشوّهة**: صفٌّ واحد يُعطّل `/kpis` **واللوحة الرئيسية
 *     لكل المستخدمين**.
 *   — **`target=1e400`**: يُحفظ `INF`، ثم يفشل ترميز قيد التدقيق، فيقع ٥٠٠
 *     **بعد** أن يكون التغيير قد كُتب — تغييرٌ بلا أثر، وهو نقضُ «إثبات لا ادّعاء».
 */
class HostileInputTest extends TestCase
{
    /** معامِلٌ يصل مصفوفةً لا يُسقط شاشة */
    public function test_array_query_parameters_never_500(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        foreach (['/me?k[]=x', '/feed?t[]=x', '/delivery?p[]=x', '/ceo?p[]=x',
                  '/apps-projects?p[]=x', '/compliance-board?p[]=x', '/assets-life?fresh[]=1',
                  '/kpis?edit[]=x', '/m/tasks?q[]=x', '/m/tasks?status[]=x'] as $url) {
            $res = $this->actingAs($this->owner)->get($url);
            $this->assertLessThan(500, $res->status(), "سقطت الشاشة بمعامِلٍ مصفوفة: {$url}");
        }
    }

    /** ومدخلٌ نصّيٌّ يصل مصفوفةً في طلب POST */
    public function test_an_array_where_a_string_is_expected_does_not_500(): void
    {
        $this->seedCore();
        $m = Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => [$this->owner->id], 'status' => 'انعقد']);

        $res = $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}", ['note' => ['x']]);
        $this->assertLessThan(500, $res->status(), 'تحفّظٌ يصل مصفوفةً أسقط تسجيل الإقرار');
    }

    /**
     * مصفوفةٌ متداخلة في «الحضور»: لا تُحفظ أصلاً، فلا تقتل السجل بعدها.
     * كانت تمرّ ثم تُسقط العرض والتعديل والإقرار معاً — سجلٌّ يستحيل إصلاحه.
     */
    public function test_a_nested_array_in_a_multi_ref_never_reaches_the_record(): void
    {
        $this->seedCore();
        $m = Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => [$this->owner->id], 'status' => 'انعقد']);

        $this->actingAs($this->owner)->put("/m/meetings/{$m->id}", [
            'title' => 'اجتماع', 'dt' => now()->toDateTimeString(), 'status' => 'انعقد',
            'parts' => [['x' => 'y'], $this->owner->id],
        ]);

        $parts = (array) $m->fresh()->parts;
        foreach ($parts as $p) $this->assertIsString($p, 'قيمةٌ غير نصّية دخلت قائمة الحضور');

        // والسجل يبقى صالحاً للفتح والتعديل والإقرار
        $this->actingAs($this->owner)->get("/m/meetings/{$m->id}")->assertOk();
        $this->actingAs($this->owner)->get("/m/meetings/{$m->id}/edit")->assertOk();
        $this->assertLessThan(500, $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}")->status());
    }

    /** معادلةٌ مشوّهة لا تُعطّل لوحة الجميع */
    public function test_a_malformed_kpi_formula_does_not_break_the_boards(): void
    {
        $this->seedCore();
        $bad = [
            '{"a":{"agg":["x"],"module":"tasks"},"combine":"none"}',
            '{"a":{"agg":"count","module":["tasks"]},"combine":"none"}',
            '{"a":{"agg":"count","module":"tasks","st":{"deep":{"er":1}}},"combine":"none"}',
            '{"a":{"agg":"sum","module":"tasks","col":["progress"]},"combine":"none"}',
        ];

        foreach ($bad as $i => $f) {
            $k = KpiDef::create(['name' => "مؤشر {$i}", 'good' => 'up',
                'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks'], 'combine' => 'none']]);
            DB::table('kpi_defs')->where('id', $k->id)->update(['formula' => $f]);

            $this->actingAs($this->owner)->get('/kpis')->assertOk();
            $this->actingAs($this->owner)->get('/')->assertOk();
            $this->actingAs($this->owner)->get("/kpis?edit={$k->id}")->assertOk();

            $k->delete();
        }
    }

    /**
     * رقمٌ خارج المدى: يُرفض قبل الكتابة — لا يُحفظ ثم يسقط ترميزُ أثره.
     * «تغييرٌ وقع بلا أثر تدقيق» أسوأ من «تغييرٌ لم يقع».
     */
    public function test_an_out_of_range_number_is_rejected_before_it_is_written(): void
    {
        $this->seedCore();
        $k = KpiDef::create(['name' => 'مؤشر', 'good' => 'up', 'target' => 5,
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks'], 'combine' => 'none']]);

        $res = $this->actingAs($this->owner)->put("/kpis/{$k->id}", [
            'name' => 'مؤشر', 'good' => 'up', 'target' => '1e400',
            'a_agg' => 'count', 'a_module' => 'tasks', 'combine' => 'none',
        ]);

        $this->assertLessThan(500, $res->status(), 'العدد اللانهائي أسقط الطلب بعد الكتابة');
        $this->assertSame(5.0, (float) $k->fresh()->target, 'كُتبت قيمةٌ لا نهائية في الهدف');
        $this->assertFalse(is_infinite((float) $k->fresh()->target));
    }

    /** والتعديل الصحيح بعده لا يزال يعمل ويترك أثره */
    public function test_a_sane_edit_after_a_rejected_one_still_audits(): void
    {
        $this->seedCore();
        $k = KpiDef::create(['name' => 'مؤشر', 'good' => 'up', 'target' => 5,
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks'], 'combine' => 'none']]);

        $this->actingAs($this->owner)->put("/kpis/{$k->id}", ['name' => 'مؤشر', 'good' => 'up',
            'target' => '1e400', 'a_agg' => 'count', 'a_module' => 'tasks', 'combine' => 'none']);
        $this->actingAs($this->owner)->put("/kpis/{$k->id}", ['name' => 'مؤشر محدَّث', 'good' => 'up',
            'target' => '9', 'a_agg' => 'count', 'a_module' => 'tasks', 'combine' => 'none'])
            ->assertRedirect();

        $this->assertSame('مؤشر محدَّث', $k->fresh()->name);
        $this->assertTrue(DB::table('audits')->where('action', 'تعديل مؤشر KPI')->exists(),
            'تعديلٌ وقع بلا أثر تدقيق');
    }

    /** ومعرّفٌ يصل مصفوفةً في مسارٍ لا يُسقطه */
    public function test_an_array_id_on_a_record_route_is_not_fatal(): void
    {
        $this->seedCore();
        $this->assertLessThan(500,
            $this->actingAs($this->owner)->get('/m/tasks?f[projectId][]=x')->status());
    }
}
