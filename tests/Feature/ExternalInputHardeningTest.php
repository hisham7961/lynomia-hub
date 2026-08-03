<?php

namespace Tests\Feature;

use App\Models\MetricPoint;
use App\Models\Project;
use Tests\TestCase;

/**
 * تصليب المدخلات الخارجية — الموجة الثالثة:
 * (١) استقبال المقاييس يقبل رقماً بلا حدّ في decimal(18,4) فيُفيض، والحلقة
 *     بلا معاملة فتُكتب نصف الدفعة — خرق ضمان «كلٌّ أو لا شيء»؛
 * (٢) الويبهوك لا يمرّ بـhub_outbound_ok (SSRF أعمى)؛
 * (٣) رمز بوت تلجرام يتسرّب إلى outbox.error عند فشل اتصال.
 */
class ExternalInputHardeningTest extends TestCase
{
    /** (١) قيمةٌ خارج مدى العمود تُرفض ولا تُكتب نصف الدفعة */
    public function test_metric_ingest_rejects_out_of_range_and_writes_nothing(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع API', 'status' => 'قيد التنفيذ']);
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok];

        // نقطةٌ سليمة ثم نقطةٌ فائضة في الدفعة نفسها — الرفض يجب أن يمنع الاثنتين
        $this->withHeaders($h)->postJson('/api/v1/metrics', ['points' => [
            ['module' => 'projects', 'record_id' => $p->id, 'metric' => 'سليم', 'value' => 42],
            ['module' => 'projects', 'record_id' => $p->id, 'metric' => 'فائض', 'value' => 1e15],
        ]])->assertStatus(422);

        $this->assertSame(0, MetricPoint::count(),
            'قيمةٌ فائضة قبلها التحقق فوقعت 500 وكُتبت النقطة السليمة قبلها — نصفُ دفعة');
    }

    public function test_metric_ingest_writes_valid_batch(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع API٢', 'status' => 'قيد التنفيذ']);
        $tok = $this->apiToken($this->owner);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tok])
            ->postJson('/api/v1/metrics', ['points' => [
                ['module' => 'projects', 'record_id' => $p->id, 'metric' => 'زوّار', 'value' => 1500],
            ]])->assertOk()->assertJsonPath('saved', 1);
        $this->assertSame(1500.0, (float) MetricPoint::where('metric', 'زوّار')->value('value'));
    }

    /** والمعاملة تلفّ حلقة الكتابة — حارس مصدر */
    public function test_metric_write_loop_is_transactional(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Api/V1Controller.php'));
        $this->assertMatchesRegularExpression('/between:-?\d+,\d+/', $src,
            'قيمة المقياس بلا حدّ — تُفيض decimal(18,4) وتقع 500');
        $this->assertStringContainsString('DB::transaction', $src,
            'حلقة كتابة المقاييس بلا معاملة — نصفُ دفعة يُكتب عند عطلٍ في المنتصف');
    }

    /** (٢) إنشاء الويبهوك يرفض العنوان الخاصّ — SSRF عند الإدخال */
    public function test_webhook_creation_rejects_private_targets(): void
    {
        $this->seedCore();
        // عنوان بيانات السحابة — يُرفض إلا بمهرب الإعداد
        $this->actingAs($this->owner)->post('/admin/webhooks', [
            'name' => 'خبيث', 'url' => 'http://169.254.169.254/latest/meta-data/', 'events' => '*',
        ])->assertSessionHasErrors('url');
        $this->assertDatabaseMissing('webhooks', ['name' => 'خبيث']);

        // وعنوانٌ عامّ يُقبل
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
        $this->actingAs($this->owner)->post('/admin/webhooks', [
            'name' => 'سليم', 'url' => 'https://hooks.example.com/x', 'events' => '*',
        ])->assertSessionDoesntHaveErrors('url');
    }

    /** (٣) رمز تلجرام لا يتسرّب إلى الخطأ المخزَّن — حارس مصدر */
    public function test_telegram_token_is_scrubbed_from_errors(): void
    {
        $src = file_get_contents(app_path('Console/Commands/HubOutbox.php'));
        $this->assertMatchesRegularExpression('#/bot\[0-9\]|bot\*\*\*|preg_replace.*bot#u', $src,
            'رمز البوت في مسار الطلب يتسرّب إلى outbox.error المعروض عند فشل اتصال');
    }
}
