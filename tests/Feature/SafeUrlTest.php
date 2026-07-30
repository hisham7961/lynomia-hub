<?php

namespace Tests\Feature;

use Tests\TestCase;

/** hub_safe_url: قائمة بيضاء للمخططات تمنع javascript:/data: في href */
class SafeUrlTest extends TestCase
{
    public function test_dangerous_schemes_are_neutralized(): void
    {
        foreach ([
            'javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)",
            ' javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox', '//evil.com',
        ] as $bad) {
            $this->assertSame('#', hub_safe_url($bad), "should neutralize: $bad");
        }
    }

    public function test_safe_urls_pass_through(): void
    {
        foreach ([
            'https://example.com/x', 'http://a.b', 'mailto:a@b.com', 'tel:+96599',
            '/relative/path', 'page?x=1',
        ] as $ok) {
            $this->assertSame($ok, hub_safe_url($ok), "should allow: $ok");
        }
        $this->assertSame('#', hub_safe_url(''));
        $this->assertSame('#', hub_safe_url(null));
    }

    public function test_url_field_in_display_escapes_javascript(): void
    {
        $this->seedCore();
        // حقل url في وحدة ذات حقل رابط: نتأكد أن العرض لا يُخرج javascript: في href
        \App\Models\Company::create(['name_ar' => 'ش', 'website' => 'javascript:alert(1)', 'status' => 'نشطة']);
        // الصفحة تعرض الرابط عبر hub_safe_url — لا يظهر المخطط الخبيث في href
        $resp = $this->actingAs($this->owner)->get('/m/companies');
        $resp->assertOk();
        $this->assertStringNotContainsString('href="javascript:', $resp->getContent());
    }
}
