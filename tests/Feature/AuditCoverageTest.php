<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AuditController;
use App\Support\SecurityEvents;
use Tests\TestCase;

/**
 * (WP-5.5) محلّلُ التغطية — «هل ثمّة عملياتٌ مهمّة بلا تدقيق؟» (§45 · §1.7).
 *
 * المقارنة: سجلُّ الوحدات × سمةُ `Auditable` × كتالوجُ `SecurityEvents::CODES`
 * × مواضعُ الكتابة الفعلية في `app/` (مسحٌ نصّيٌّ مقيَّدٌ مخبّأ). والقاعدةُ
 * الصارمة: **لا يقينَ مزيَّفاً** — المسحُ النصّيّ يُثبت وجودَ موضع كتابةٍ حرفيّ
 * ولا يُثبت تنفيذَه، فما لا يُثبَت يُعلَن «يحتاج مراجعة» صراحةً، والفعلُ
 * المبنيّ بسمةٍ أو بكاتبٍ آخر يُوسم «مشتق» لا «مغطّى».
 */
class AuditCoverageTest extends TestCase
{
    /** للمالك وحده — الصفحة خريطةُ ما يُدقَّق وما لا يُدقَّق: خريطةُ هجومٍ لغيره */
    public function test_coverage_is_owner_only(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get('/admin/audit/coverage')->assertForbidden();
        $this->actingAs($this->viewer)->get('/admin/audit/coverage')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/audit/coverage')->assertOk();
    }

    /** كلُّ وحدةٍ في السجل نموذجُها موسومٌ بـAuditable — والتقرير يعدّها كلَّها */
    public function test_every_module_model_is_auditable_and_counted(): void
    {
        $report = AuditController::coverageReport();

        $this->assertSame(count(hub_modules()), $report['modules']['total']);
        $this->assertSame([], $report['modules']['missing'],
            "وحداتٌ بلا سمة Auditable:\n" . implode("\n", $report['modules']['missing']));
        $this->assertSame($report['modules']['total'], $report['modules']['audited']);
    }

    /** فعلٌ لا موضعَ كتابةٍ له يُعلَن «يحتاج مراجعة» — لا يقينَ مزيَّفاً */
    public function test_deliberately_uncovered_action_is_flagged_for_review(): void
    {
        $report = AuditController::coverageReport([
            'FAKE_EVENT' => ['حدثٌ مختلق', 'high', ['فعلٌ لا يكتبه أحدٌ إطلاقاً']],
        ]);

        $this->assertSame('review', $report['events']['FAKE_EVENT']['status'],
            'فعلٌ بلا موضع كتابةٍ ادُّعي مغطّىً — يقينٌ مزيَّف');
        $this->assertSame('review', $report['events']['FAKE_EVENT']['actions'][0]['status']);
    }

    /** الحرفيُّ الموجود «مغطّى»، والوسومُ البنيوية «مشتق» لا «مغطّى» */
    public function test_statuses_are_honest_per_action_kind(): void
    {
        $report = AuditController::coverageReport();

        // صيغةٌ حرفية لها موضعُ كتابةٍ فعليّ (AuthController)
        $auth = collect($report['events']['AUTH_SUCCESS']['actions'])
            ->firstWhere('action', 'دخول ناجح');
        $this->assertNotNull($auth);
        $this->assertSame('proven', $auth['status']);

        // وسمُ رادار المنع: يكتبه SecurityRadar لا التدقيق — مشتقٌّ بنيوياً لا مثبَتٌ حرفياً
        $denial = collect($report['events']['ACCESS_DENIED']['actions'])->first();
        $this->assertSame('derived', $denial['status'],
            'وسمُ @denial ادُّعي مغطّىً حرفياً — التصنيف يكذب على مصدره');

        // وسمُ @module: التغطية من سمة Auditable على نموذج الوحدة
        $role = collect($report['events']['ROLE_CHANGED']['actions'])->first();
        $this->assertSame('derived', $role['status']);
    }

    /** الثغراتُ المعروفة تُعرض بصدق: المفتوحُ مفتوحاً والمُغلَقُ (الطور ٣) مغلقاً */
    public function test_known_gaps_reflect_the_actual_source(): void
    {
        $this->seedCore();
        $report = AuditController::coverageReport();
        $gaps = collect($report['gaps'])->keyBy('key');

        // دورةُ حياة الخطأ أُغلقت في الطور ٣ (WP-3.3) — التقرير يعكس لا يفترض
        $this->assertSame('closed', $gaps['error_lifecycle']['state'],
            'الطور ٣ غطّى أفعال دورة حياة الخطأ — التقرير ما زال يعدّها ثغرة');

        // حفظُ التحقيق مدقَّق (WP-5.3)؛ وحذفُه بلا قيدٍ — ثغرةٌ مفتوحة تُقال بصدق
        $this->assertSame('closed', $gaps['saved_view_store']['state']);
        $prefSrc = (string) file_get_contents(app_path('Http/Controllers/Web/PrefController.php'));
        $expected = str_contains($prefSrc, "'حذف تحقيق تدقيق'") ? 'closed' : 'open';
        $this->assertSame($expected, $gaps['saved_view_destroy']['state'],
            'حالُ ثغرة حذف التحقيق لا يطابق المصدر — التقرير يفترض لا يفحص');

        // الاستعادةُ تُكتب «تعديل» — تغطيةٌ جزئية بنيوية تُعلَن دائماً
        $this->assertSame('partial', $gaps['restore']['state']);

        // والصفحة تعرضها بالعربية الصادقة
        $html = $this->actingAs($this->owner)->get('/admin/audit/coverage')->assertOk()->getContent();
        $this->assertStringContainsString('يحتاج مراجعة', $html, 'وسمُ الصدق غائب عن الصفحة');
        $this->assertStringContainsString('مشتق', $html);
        $this->assertStringContainsString('حذف', $gaps['saved_view_destroy']['title']);
    }

    /** الصفحة تعرض كلَّ كودٍ في الكتالوج — لا كودَ يسقط من الخريطة صامتاً */
    public function test_page_lists_every_catalog_code(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/audit/coverage')->assertOk()->getContent();

        foreach (array_keys(SecurityEvents::CODES) as $code) {
            $this->assertStringContainsString($code, $html, "الكود {$code} غائب عن خريطة التغطية");
        }
        // والوحدات تُعدّ ٨٢/٨٢ (أو عددَها الحقيقي) لا رقماً مزخرفاً
        $this->assertStringContainsString((string) count(hub_modules()), $html);
    }
}
