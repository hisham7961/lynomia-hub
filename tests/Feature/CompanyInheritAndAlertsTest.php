<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عيب «السجل يختفي بعد الإضافة»: مع شركةٍ نشطة في الشريط العلوي، وحدةٌ بلا حقل
 * شركة في نموذجها (الخدمات، قواعد التنبيه) كانت تولد سجلاً بلا شركة فيُفلتر فوراً.
 * الإصلاح: السجل الجديد يرث الشركة النشطة. + عدّة قواعد التنبيه المتوقفة.
 */
class CompanyInheritAndAlertsTest extends TestCase
{
    /** الحالة المُبلَّغة حرفياً: خدمة تُضاف مع شركة نشطة — يجب أن تظهر في القائمة */
    public function test_service_added_under_active_company_appears_in_list(): void
    {
        $this->seedCore();
        $co = \App\Models\Company::create(['name_ar' => 'شركة نشطة']);
        session(['hub.company' => $co->id]);

        $this->actingAs($this->owner)
            ->post('/m/services', ['name' => 'خدمة تصميم هوية', 'kind' => 'خدمة'])
            ->assertRedirect();

        // ورثت الشركة النشطة
        $svc = DB::table('services')->where('name', 'خدمة تصميم هوية')->first();
        $this->assertNotNull($svc);
        $this->assertSame($co->id, $svc->company_id, 'الخدمة لم ترث الشركة النشطة');

        // وتظهر في القائمة المفلترة بها — قبل الإصلاح كانت تختفي هنا
        $this->actingAs($this->owner)->get('/m/services')
            ->assertOk()->assertSee('خدمة تصميم هوية');
    }

    /** قواعد التنبيه: نفس العيب ونفس الضمان */
    public function test_alert_rule_added_under_active_company_appears(): void
    {
        $this->seedCore();
        $co = \App\Models\Company::create(['name_ar' => 'شركة القواعد']);
        session(['hub.company' => $co->id]);

        $this->actingAs($this->owner)
            ->post('/m/rules', ['name' => 'قاعدة اختبار', 'mod' => 'domains', 'field' => 'expiry',
                                'op' => 'أيام متبقية أقل من', 'val' => '30', 'status' => 'متوقفة'])
            ->assertRedirect();

        $this->actingAs($this->owner)->get('/m/rules')->assertOk()->assertSee('قاعدة اختبار');
    }

    /** بلا شركة نشطة («كل الشركات») يبقى السلوك القديم: لا شركة تُفرض */
    public function test_no_active_company_means_no_forced_company(): void
    {
        $this->seedCore();
        session()->forget('hub.company');
        $this->actingAs($this->owner)->post('/m/services', ['name' => 'خدمة عامة', 'kind' => 'خدمة']);

        $this->assertNull(DB::table('services')->where('name', 'خدمة عامة')->value('company_id'));
    }

    /** عدّة قواعد التنبيه: ١٦ قاعدة متوقفة بأعمدة حقيقية، ولا تكرار */
    public function test_alerts_starter_seeds_disabled_rules_idempotently(): void
    {
        $this->seedCore();
        Artisan::call('hub:alerts-starter');
        $n = DB::table('alert_rules')->count();
        $this->assertGreaterThanOrEqual(16, $n);
        $this->assertSame(0, DB::table('alert_rules')->where('status', 'مفعّلة')->count(),
            'قاعدة انطلاقية وُلدت مفعّلة!');

        // كل قاعدة تشير لوحدة وعمود حقيقيين
        foreach (DB::table('alert_rules')->get() as $rule) {
            $def = config("hub.modules.{$rule->mod}");
            $this->assertNotNull($def, "قاعدة «{$rule->name}» لوحدة معدومة");
            $this->assertTrue(
                collect($def['fields'])->contains(fn ($f) => $f['col'] === $rule->field),
                "قاعدة «{$rule->name}» لعمود معدوم: {$rule->field}");
        }

        Artisan::call('hub:alerts-starter');
        $this->assertSame($n, DB::table('alert_rules')->count(), 'النداء الثاني كرّر');
    }
}
