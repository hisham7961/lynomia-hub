<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * حارس قواعد المنتج الميداني — **المواصفة قالت «ممنوع» فليقلها اختبارٌ دائم.**
 *
 * دليلُ مقدمي الرعاية سجلُّ معرفةٍ مهنيّ للتخطيط والزيارات، وليس طرفاً بيعياً:
 * لا باركود لطبيب، ولا كود QR بيعيّ، ولا أكواد إحالة، ولا ربطَ طلباتٍ أو
 * مبيعاتٍ بطبيب، ولا عمولات أو حوافز مالية. وثيقةُ المتطلبات تُنسى بعد سنة —
 * هذا الملف يُسقط الحزمةَ يومَ يحاول أحدٌ إضافتها.
 */
class FieldForceGuardrailsTest extends TestCase
{
    public function test_no_sales_or_commission_or_referral_field_exists_on_hcps(): void
    {
        $banned = ['barcode', 'qr', 'referral', 'commission', 'incentive', 'affiliate',
            'sales', 'order', 'revenue', 'discount', 'coupon'];

        $cols = collect(hub_mod('hcps')['fields'])->pluck('col');
        foreach ($cols as $col) {
            foreach ($banned as $b) {
                $this->assertStringNotContainsString($b, (string) $col,
                    "حقل «{$col}» على دليل مقدمي الرعاية يلامس مفهوماً بيعياً ممنوعاً «{$b}» — المواصفة تمنعه نصاً");
            }
        }

        // ولا يُسجَّل لمقدم رعايةٍ معرِّفُ هويةٍ قابلٌ للمسح — سجل المعرفات
        // (record_identifiers) للمنتجات والأصول، والطبيب ليس صنفاً يُمسح
        $this->assertSame(0, \App\Models\RecordIdentifier::where('module', 'hcps')->count(),
            'دليل مقدمي الرعاية دخل سجل المعرفات القابلة للمسح');
    }

    public function test_no_route_ties_an_hcp_to_orders_or_sales(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! str_contains($uri, 'hcp')) continue;
            foreach (['order', 'sale', 'referral', 'commission', 'label', 'barcode'] as $b) {
                $this->assertStringNotContainsString($b, $uri,
                    "مسار «{$uri}» يربط مقدم رعايةٍ بمفهومٍ بيعيّ أو ملصقٍ ممنوع");
            }
        }

        // اكتمالُ الفحص لا فراغُه: مسارات الوحدة العامة لمقدمي الرعاية موجودة
        $this->assertNotNull(route('m.index', 'hcps', false));
    }

    public function test_hcp_notes_hint_says_professional_data_only(): void
    {
        // التلميح على حقل الملاحظات يقولها في مكان الكتابة نفسه — لا في وثيقة
        $notes = collect(hub_mod('hcps')['fields'])->firstWhere('key', 'notes');
        $this->assertStringContainsString('لا بيانات شخصية', (string) ($notes['hint'] ?? ''),
            'تلميح الملاحظات فقد تحذيرَ البيانات الشخصية');
    }
}
