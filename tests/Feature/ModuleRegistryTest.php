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
}
