<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * لا يظهر النظامُ في بحث جوجل — ثلاثُ طبقاتٍ متكاملة:
 *
 *  ١) ترويسةُ `X-Robots-Tag: noindex` على **كل** استجابة (أقوى إشارةٍ للزاحف،
 *     وتُطبَّق حتى على المرفقات وروابط التوقيع المشارَكة خارجاً).
 *  ٢) `public/robots.txt` يمنع كلَّ زاحف من كلِّ مسار.
 *  ٣) وسمُ `<meta name="robots" content="noindex">` في رأس صفحات الدخول والمستقلة.
 */
class NoIndexTest extends TestCase
{
    public function test_login_response_carries_noindex_header(): void
    {
        $r = $this->get('/login');
        $r->assertOk();
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'),
            'صفحةُ الدخول بلا ترويسة noindex — قد تُفهرَس في جوجل');
    }

    public function test_authenticated_response_carries_noindex_header(): void
    {
        $this->seedCore();
        $r = $this->actingAs($this->owner)->get('/');
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'),
            'صفحةٌ داخليةٌ بلا ترويسة noindex');
    }

    public function test_robots_txt_disallows_all_crawlers(): void
    {
        $path = public_path('robots.txt');
        $this->assertFileExists($path, 'لا ملفَّ robots.txt يمنع الزحف');
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertMatchesRegularExpression('/^\s*Disallow:\s*\/\s*$/m', $body,
            'robots.txt لا يمنع كلَّ المسارات (Disallow: /)');
    }

    public function test_login_page_html_has_robots_meta(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('noindex', false);
    }
}
