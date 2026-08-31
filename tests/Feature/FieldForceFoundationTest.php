<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Facility;
use App\Models\Hcp;
use App\Models\Territory;
use App\Models\TerritoryAssignment;
use Tests\TestCase;

/**
 * أساس العمليات الميدانية — المرحلة أ: الدليل والجغرافيا والهرمية والإسناد.
 *
 * أربع وحدات جديدة على السكة العامة نفسها، فيُثبَت هنا ما هو **خاص** بها:
 * الهرمية الأولى في السجل (بلا دور)، وأول حساب مسافةٍ في المشروع، والإسناد
 * المؤرَّخ الذي يُنهى ولا يُمحى، وملف المندوب امتداداً لملف الموظف.
 */
class FieldForceFoundationTest extends TestCase
{
    public function test_the_four_modules_work_end_to_end_from_the_generic_engine(): void
    {
        $this->seedCore();

        foreach (['hcps', 'facilities', 'territories', 'terrassigns'] as $m) {
            $this->actingAs($this->owner)->get('/m/' . $m)->assertOk();
            $this->actingAs($this->owner)->get('/m/' . $m . '/create')->assertOk();
        }

        // إنشاءٌ من المسار العام — كما سيُنشئ المستخدم فعلاً
        $this->actingAs($this->owner)->post('/m/territories', ['name' => 'الكويت', 'kind' => 'بلد'])
            ->assertSessionHasNoErrors();
        $t = Territory::where('name', 'الكويت')->first();
        $this->assertNotNull($t);

        $this->actingAs($this->owner)->post('/m/facilities', [
            'name' => 'مستشفى الصباح', 'type' => 'مستشفى', 'territoryId' => $t->id,
            'lat' => '29.3375000', 'lng' => '47.9744000', 'radiusM' => '300',
        ])->assertSessionHasNoErrors();
        $f = Facility::where('name', 'مستشفى الصباح')->first();
        $this->assertNotNull($f);

        $this->actingAs($this->owner)->post('/m/hcps', [
            'name' => 'د. سعاد العنزي', 'specialty' => 'قلب', 'class' => 'أ — تأثير عالٍ',
            'facilityIds' => [$f->id], 'territoryId' => $t->id, 'status' => 'نشط',
        ])->assertSessionHasNoErrors();
        $h = Hcp::where('name', 'د. سعاد العنزي')->first();
        $this->assertNotNull($h);
        $this->assertSame([$f->id], $h->facility_ids);

        // صفحة العرض ببطاقاتها المخصصة — المنشأة تعرف من يعمل فيها والعكس
        $this->actingAs($this->owner)->get('/m/hcps/' . $h->id)->assertOk()
            ->assertSee('مستشفى الصباح')->assertSee('بطاقة الميدان');
        $this->actingAs($this->owner)->get('/m/facilities/' . $f->id)->assertOk()
            ->assertSee('د. سعاد العنزي')->assertSee('الموقع والنطاق');
    }

    public function test_territory_hierarchy_holds_and_a_cycle_cannot_be_written(): void
    {
        $this->seedCore();

        $country = Territory::create(['name' => 'الكويت', 'kind' => 'بلد']);
        $gov = Territory::create(['name' => 'حولي', 'kind' => 'محافظة', 'parent_id' => $country->id]);
        $sector = Territory::create(['name' => 'السالمية', 'kind' => 'قطاع', 'parent_id' => $gov->id]);

        // النسل يجمع الشجرة كاملة — عليه تُبنى تغطية المحافظة من قطاعاتها
        $this->assertEqualsCanonicalizing([$country->id, $gov->id, $sector->id], $country->subtreeIds());
        $this->assertEqualsCanonicalizing([$gov->id, $sector->id], $gov->subtreeIds());

        // منطقةٌ أبوها نفسُها تُقطع بصمت
        $gov->parent_id = $gov->id;
        $gov->save();
        $this->assertNull($gov->fresh()->parent_id);

        // ودورٌ عبر وسيط (الجد ابنَ حفيده) يُقطع أيضاً — فلا حلقةَ تعلّق قارئاً
        $gov->parent_id = $country->id;
        $gov->save();
        $country->parent_id = $sector->id;
        $country->save();
        $this->assertNull($country->fresh()->parent_id);

        // وصفحة المنطقة تعرض شجرتها
        $this->actingAs($this->owner)->get('/m/territories/' . $gov->id)->assertOk()
            ->assertSee('موقعها في الشجرة')->assertSee('السالمية');
    }

    public function test_reassigning_a_territory_keeps_history_instead_of_rewriting_it(): void
    {
        $this->seedCore();

        $t = Territory::create(['name' => 'الفروانية', 'kind' => 'محافظة']);
        $e1 = Employee::create(['name' => 'أحمد المندوب', 'status' => 'نشط', 'field_role' => 'مندوب طبي']);
        $e2 = Employee::create(['name' => 'نورة المندوبة', 'status' => 'نشط', 'field_role' => 'مندوب طبي']);

        $a1 = TerritoryAssignment::create(['territory_id' => $t->id, 'emp_id' => $e1->id, 'role' => 'أساسي']);

        // الاسم والتاريخ والحالة تُولَّد — فالقوائم تعرض «المنطقة ← المندوب»
        $this->assertSame('الفروانية ← أحمد المندوب', $a1->name);
        $this->assertSame('ساري', $a1->status);
        $this->assertNotNull($a1->date_start);

        // النقل: إنهاءُ الإسناد وفتحُ غيره — فيبقى صفّان يرويان التاريخ
        $a1->update(['status' => 'منتهٍ', 'date_end' => now()->toDateString()]);
        TerritoryAssignment::create(['territory_id' => $t->id, 'emp_id' => $e2->id, 'role' => 'أساسي']);

        $rows = TerritoryAssignment::where('territory_id', $t->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->where('status', 'ساري')->count());

        // وصفحة المنطقة تعرض الساري وحده في «من يغطيها الآن»
        $this->actingAs($this->owner)->get('/m/territories/' . $t->id)->assertOk()
            ->assertSee('نورة المندوبة');
    }

    public function test_facility_geofence_math_is_honest_about_the_unknown(): void
    {
        // برج الكويت ← سوق شرق ≈ كيلومتر ونصف: المسافة تُحسب بخطأ < ٥٪
        $d = Facility::distanceM(29.3789, 47.9927, 29.3702, 48.0003);
        $this->assertGreaterThan(1100, $d);
        $this->assertLessThan(1400, $d);

        $f = new Facility(['lat' => 29.3789, 'lng' => 47.9927, 'radius_m' => 300]);
        $this->assertTrue($f->within(29.3790, 47.9928));    // على بُعد أمتار
        $this->assertFalse($f->within(29.3702, 48.0003));   // على بُعد كيلومتر

        // منشأةٌ بلا إحداثيات أو بلا نطاق **لا تحكم** — null لا «خارج الموقع»:
        // غيابُ الضبط ليس مخالفةً يُتَّهم بها مندوب
        $this->assertNull((new Facility(['radius_m' => 300]))->within(29.0, 47.0));
        $this->assertNull((new Facility(['lat' => 29.0, 'lng' => 47.0]))->within(29.0, 47.0));
    }

    public function test_the_rep_profile_is_the_employee_file_itself(): void
    {
        $this->seedCore();

        // الحقل على وحدة hr القائمة — لا وحدةَ مندوبين موازية في السجل
        $keys = collect(hub_mod('hr')['fields'])->pluck('key');
        $this->assertTrue($keys->contains('fieldRole'));
        $this->assertArrayNotHasKey('reps', config('hub.modules'));
        $this->assertArrayNotHasKey('medreps', config('hub.modules'));

        $e = Employee::create(['name' => 'مندوب جديد', 'status' => 'نشط', 'field_role' => 'مندوب طبي']);
        $this->assertSame('مندوب طبي', $e->fresh()->field_role);
    }

    public function test_a_viewer_without_the_module_permission_is_refused(): void
    {
        $this->seedCore();

        // دورٌ بلا أي صلاحية على وحدات الميدان — القوائم تُرد بـ403
        $role = \App\Models\Role::create(['name' => 'بلا ميدان', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'خارجي', 'email' => 'noff@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        foreach (['hcps', 'facilities', 'territories', 'terrassigns'] as $m) {
            $this->actingAs($u)->get('/m/' . $m)->assertForbidden();
        }
    }
}
