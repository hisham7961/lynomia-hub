<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * حارس سجل الوحدات: يفحص **كل** وحدة مسجَّلة بنيوياً.
 * أي وحدة جديدة تُضاف بعمود ناقص أو نموذج مفقود أو مرجع لوحدة غير موجودة
 * تُسقط هذا الاختبار فوراً بدل أن تنفجر أمام المستخدم.
 */
class ModuleRegistryTest extends TestCase
{
    public function test_every_module_is_structurally_sound(): void
    {
        $this->seedCore();
        $problems = [];

        foreach (config('hub.modules') as $key => $def) {
            foreach (['table', 'model', 'label', 'display', 'fields'] as $req) {
                if (empty($def[$req])) $problems[] = "$key: مفتاح «$req» ناقص";
            }
            if (empty($def['table']) || empty($def['fields'])) continue;

            if (! Schema::hasTable($def['table'])) {
                $problems[] = "$key: الجدول {$def['table']} غير موجود";
                continue;
            }

            $model = '\\App\\Models\\' . ($def['model'] ?? '');
            if (! class_exists($model)) $problems[] = "$key: النموذج {$def['model']} غير موجود";

            foreach ($def['fields'] as $f) {
                foreach (['key', 'col', 'label', 'type'] as $req) {
                    if (! isset($f[$req])) { $problems[] = "$key: حقل بلا «$req»"; continue 2; }
                }
                if (! Schema::hasColumn($def['table'], $f['col'])) {
                    $problems[] = "$key.{$f['key']}: العمود {$f['col']} غير موجود في {$def['table']}";
                }
                if (($f['type'] ?? '') === 'ref' && ! hub_mod($f['ref'] ?? '') && ($f['ref'] ?? '') !== 'users' && ($f['ref'] ?? '') !== 'roles') {
                    $problems[] = "$key.{$f['key']}: يشير لوحدة غير مسجَّلة «{$f['ref']}»";
                }
                if (($f['type'] ?? '') === 'sel' && empty($f['options'])) {
                    $problems[] = "$key.{$f['key']}: قائمة اختيار بلا خيارات";
                }
            }

            // عمود العرض لا بد أن يكون عموداً حقيقياً وإلا انكسر كل جدول ومرجع
            if (! Schema::hasColumn($def['table'], hub_display_col($key))) {
                $problems[] = "$key: عمود العرض " . hub_display_col($key) . ' غير موجود';
            }

            // أعمدة المنصة التي يعتمدها المحرك في كل وحدة
            foreach (['id', 'created_at', 'deleted_at'] as $core) {
                if (! Schema::hasColumn($def['table'], $core)) $problems[] = "$key: العمود الأساسي $core ناقص";
            }
        }

        $this->assertSame([], $problems, "مشاكل في سجل الوحدات:\n" . implode("\n", $problems));
    }

    public function test_every_navigation_entry_points_at_a_real_module(): void
    {
        $bad = [];
        foreach (config('hub_nav') as $g) {
            foreach ($g['items'] as $k) {
                if (! hub_mod($k)) $bad[] = "{$g['g']} → $k";
            }
        }
        $this->assertSame([], $bad, 'عناصر تنقل تشير لوحدات غير موجودة: ' . implode(', ', $bad));
    }

    public function test_the_new_modules_are_usable_end_to_end(): void
    {
        $this->seedCore();

        foreach (['incidents', 'deploys', 'restores', 'skills', 'policies', 'policyacks', 'requests', 'deps'] as $m) {
            $this->actingAs($this->owner)->get('/m/' . $m)->assertOk();
            $this->actingAs($this->owner)->get('/m/' . $m . '/create')->assertOk();
        }
    }

    public function test_incident_captures_the_full_postmortem_record(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/m/incidents', [
            'title' => 'انقطاع بوابة الدفع', 'severity' => 'حرج', 'status' => 'مفتوح',
            'downtimeMin' => 45, 'affected' => 'تطبيق المتجر',
            'rootCause' => 'انتهاء شهادة SSL', 'lessons' => 'مراقبة الشهادات آلياً',
            'prevention' => 'تنبيه قبل ٣٠ يوماً', 'postmortem' => 1,
        ])->assertRedirect();

        $i = \App\Models\Incident::firstOrFail();
        $this->assertSame('حرج', $i->severity);
        $this->assertSame(45, (int) $i->downtime_min);
        $this->assertSame('انتهاء شهادة SSL', $i->root_cause);
        $this->assertTrue((bool) $i->postmortem);
        $this->assertDatabaseHas('audits', ['module' => 'incidents', 'action' => 'إضافة']);
    }

    public function test_internal_request_carries_its_evaluation_fields(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/m/requests', [
            'title' => 'طلب ميزة تقارير', 'reqType' => 'ميزة جديدة',
            'description' => 'نحتاج تقريراً شهرياً', 'prioReq' => 'عالية',
            'estCost' => 500, 'estDays' => 7, 'status' => 'جديد',
        ])->assertRedirect();

        $r = \App\Models\InternalRequest::firstOrFail();
        $this->assertSame('ميزة جديدة', $r->req_type);
        $this->assertEquals(500, (float) $r->est_cost);
    }

    /**
     * جولة تدقيق ٦ — حارس الأعمدة اليتيمة: columnsAndLabels يقاطع columns مع
     * مفاتيح fields فيُسقط اليتيم بصمت — العمود المقصود يختفي ولا أحد يعلم
     * (كانت سبع وحدات مصابة: عمود الحالة في الموافقات، معدل تفاعل المنشورات،
     * وجدول تحديثات العمل كله بلا أعمدة).
     */
    public function test_declared_columns_are_subset_of_declared_fields(): void
    {
        foreach (hub_modules() as $mk => $md) {
            $keys = array_column($md['fields'], 'key');
            $cols = $md['columns'] ?? null;
            if ($cols === null) continue;
            $this->assertNotEmpty($cols, "وحدة {$mk} تعلن columns فارغة — جدولها يظهر بلا أي عمود بيانات");
            foreach ($cols as $c) {
                $this->assertContains($c, $keys,
                    "عمود يتيم {$mk}.{$c}: معلن في columns بلا حقل يقابله — يُسقطه العرض بصمت");
            }
        }
    }

    /** حارس المرادفات: خياران بنفس اللفظ بعد التطبيع = عمودا كانبان مكرران ومطابقة منطق تتسرب */
    public function test_no_normalized_duplicates_inside_field_options(): void
    {
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                if (empty($f['options'])) continue;
                $norm = array_map(fn ($o) => hub_ar_norm((string) $o), $f['options']);
                $this->assertSame(count($norm), count(array_unique($norm)),
                    "خيارات {$mk}.{$f['key']} فيها مرادفان بعد التطبيع: " . implode('، ', $f['options']));
            }
        }
    }

    /** الموافقة تولد «معلّق» لا 'pending' — فلا تولد ميتة عن كل المحركات */
    public function test_new_approval_is_born_pending_in_arabic(): void
    {
        $this->seedCore();

        // الإنشاء البرمجي بلا حالة يأخذ افتراضي العمود — كان 'pending' فلا يراه أي منطق
        $ap = \App\Models\Approval::create(['title' => 'موافقة برمجية', 'type' => 'مصروف']);
        $this->assertSame('معلّق', $ap->fresh()->status,
            'الافتراضي الإنجليزي pending كان يولّد موافقة لا يراها الموجز ولا بوابة /me ولا حالات الإغلاق');

        // ومن النموذج: الحالة مطلوبة (كي لا يُكتب NULL فوق الافتراضي) وتظهر في العمود المعلن
        $this->actingAs($this->owner)->post('/m/approvals', [
            'title' => 'موافقة من النموذج', 'type' => 'مصروف',
            'approverId' => $this->owner->id, 'status' => 'معلّق',
        ])->assertSessionHasNoErrors();
        $this->assertSame('معلّق', \App\Models\Approval::where('title', 'موافقة من النموذج')->firstOrFail()->status);
    }
}
