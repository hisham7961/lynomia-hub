<?php

namespace Tests\Feature;

use App\Support\Api;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** تحليلاتُ استخدام الـAPI على سكّة المقاييس القائمة — لكل مفتاحٍ في اليوم. */
class ApiUsageAnalyticsTest extends TestCase
{
    public function test_requests_and_errors_are_counted_per_token_per_day(): void
    {
        $this->seedCore();
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner)];
        $this->withHeaders($h)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/nope')->assertStatus(404);

        $tid = DB::table('api_tokens')->value('id');
        $row = DB::table('api_usage')->where('token_id', $tid)->first();
        $this->assertSame(3, (int) $row->requests);
        $this->assertSame(1, (int) $row->errors);
        $this->assertGreaterThanOrEqual(0, (int) $row->ms);
        $this->assertSame(1, DB::table('api_usage')->count(), 'صفٌّ واحد لليوم لا صفٌّ لكل طلب');
        $this->assertSame(0, DB::table('metric_points')->count(), 'مقاييسُ الأعمال لا تُلوَّث بعدّادات تقنية');

        $u = Api::usage(7);
        $this->assertSame(3, $u['total']['requests']);
        $this->assertSame(1, $u['total']['errors']);
        $this->assertSame(33.3, $u['total']['error_rate']);
        $this->assertSame('اختبار', $u['tokens'][0]['name']);

        $this->actingAs($this->owner)->get('/admin/integrations')->assertOk()->assertSee('استخدام REST API')->assertSee('اختبار');
    }

    public function test_unauthenticated_requests_are_not_counted(): void
    {
        $this->seedCore();
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->assertSame(0, DB::table('api_usage')->count());
    }
}
