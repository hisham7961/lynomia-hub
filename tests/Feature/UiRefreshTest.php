<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * تلميع v2.84 البصريّ: يحرس أن التحسين لم يكسر العقد —
 * حقل الملف صار عربياً مخصّصاً، وشاشة الدخول لها شعارٌ مميّز حين لا شعار مخصّص.
 */
class UiRefreshTest extends TestCase
{
    public function test_module_file_field_renders_arabic_control_not_native_english(): void
    {
        $this->seedCore();
        // مشاريع فيها حقل شعار (img) → يمرّ عبر partials/_field
        $html = $this->actingAs($this->owner)->get('/m/projects/create')->assertOk()->getContent();

        $this->assertStringContainsString('class="filefield"', $html, 'حقل الملف المخصّص غائب');
        $this->assertStringContainsString('اختر ملفاً', $html, 'زر الاختيار العربيّ غائب');
        $this->assertStringContainsString('لم يُحدَّد ملف', $html, 'عنوان «لا ملف» العربيّ غائب');
        // لم يعد الحقل الخام <input class="inp" type="file"> يُعرض للوحدات
        $this->assertStringNotContainsString('class="inp" type="file"', $html, 'بقي حقل الملف الخام');
        // الحقل يظلّ قابلاً للرفع: اسم الإدخال محفوظ داخل العنصر المخصّص
        $this->assertMatchesRegularExpression('/<label class="filefield">.*type="file" name="logo"/s', $html,
            'اسم الإدخال ضاع فانكسر الرفع');
    }

    public function test_login_shows_brand_emblem_when_no_custom_logo(): void
    {
        // بلا شعارٍ مخصّص → يظهر الشعار المميّز الذي تعتمده CSS الجديدة
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('class="loginmark"', $html, 'الشعار المميّز غائب عن شاشة الدخول');
    }
}
