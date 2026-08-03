<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** انحدارات الجولة الثالثة: سقف قوائم المراجع، وحجز الصفوف، والواجهة */
class AuditRound3Test extends TestCase
{
    public function test_editing_keeps_a_reference_beyond_the_option_limit(): void
    {
        $this->seedCore();

        // ٥٠٥ شركة: المرجع المستهدف خارج أول ٥٠٠ حسب الترتيب الأبجدي
        $rows = [];
        for ($i = 1; $i <= 505; $i++) {
            $rows[] = ['id' => (string) \Illuminate\Support\Str::uuid(),
                'name_ar' => 'شركة ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('companies')->insert($rows);
        $last = Company::orderByDesc('name_ar')->first();

        $c = Client::create(['name' => 'عميل مرتبط', 'company_id' => $last->id]);

        // القائمة وحدها تقطع عند ٥٠٠ فلا تحوي الشركة
        $this->assertArrayNotHasKey($last->id, hub_ref_options('companies'));
        // ومع ضمان القيمة الحالية تظهر
        $this->assertArrayHasKey($last->id, hub_ref_options('companies', $last->id));

        // وصفحة التعديل يجب أن تحوي خيارها فعلاً
        $this->actingAs($this->owner)->get('/m/clients/' . $c->id . '/edit')
            ->assertOk()->assertSee($last->id, false);

        // وحفظ النموذج كما هو لا يمحو الرابط
        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, [
            'name' => 'عميل مرتبط', 'companyId' => $last->id,
        ])->assertRedirect();
        $this->assertSame($last->id, $c->fresh()->company_id);
    }

    public function test_reclaim_uses_claim_time_not_creation_time(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('ok', 200)]);

        $h = Webhook::create(['name' => 'h', 'url' => 'http://r.test/h',
            'secret' => 's', 'events' => '*', 'active' => true]);

        // تسليم أُنشئ منذ ساعة وحُجز قبل لحظات — تشغيلٌ آخر يرسله الآن
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'clients.created',
            'event_id' => 'e', 'payload' => '{}', 'state' => 'sending',
            'claimed_at' => now(), 'created_at' => now()->subHour()]);

        \Illuminate\Support\Facades\Artisan::call('hub:outbox');

        // لا يُخطف ولا يُرسل مرتين
        $this->assertSame('sending', $d->fresh()->state);
        Http::assertNothingSent();

        // أما المحجوز منذ أكثر من عشر دقائق فيُسترجع
        $d->forceFill(['claimed_at' => now()->subMinutes(20)])->save();
        \Illuminate\Support\Facades\Artisan::call('hub:outbox');
        $this->assertSame('sent', $d->fresh()->state);
    }

    public function test_active_webhook_cache_is_busted_on_change(): void
    {
        $this->seedCore();
        Cache::forget('webhooks:active');

        // مُحلِّلٌ للاختبار: إنشاء الويبهوك يمرّ بحارس SSRF (hub_outbound_ok)
        // الذي يرفض مضيفاً لا يُحلّ — نجعل r.test يُحلّ لعنوانٍ عامّ
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);

        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'قبل الاشتراك'])->assertRedirect();
        $this->assertSame(0, WebhookDelivery::count());

        $this->actingAs($this->owner)->post('/admin/webhooks', [
            'name' => 'جديد', 'url' => 'http://r.test/h', 'events' => '*',
        ])->assertRedirect();

        // لولا نسف الكاش لبقي الاشتراك الجديد غير مرئي دقيقة كاملة
        $this->actingAs($this->owner)->post('/m/clients', ['name' => 'بعد الاشتراك'])->assertRedirect();
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_read_only_reference_shows_its_name_not_a_raw_id(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة الاختبار المرئية']);
        $c = Client::create(['name' => 'عميل', 'company_id' => $co->id]);
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['companyId' => 'ro']]])->save();

        $this->actingAs($this->employee)->get('/m/clients/' . $c->id . '/edit')->assertOk()
            ->assertSee('شركة الاختبار المرئية')      // كان يعرض معرّفاً خاماً
            ->assertSee('قراءة فقط');
    }

    public function test_a_disabled_hooks_backlog_cannot_block_live_subscriptions(): void
    {
        $this->seedCore();
        Http::fake(['*' => Http::response('ok', 200)]);

        $dead = Webhook::create(['name' => 'معطّل بمتراكمات', 'url' => 'http://dead.test/h',
            'secret' => 's', 'events' => '*', 'active' => false]);
        $live = Webhook::create(['name' => 'حيّ', 'url' => 'http://live.test/h',
            'secret' => 's', 'events' => '*', 'active' => true]);

        // ٦٠ تسليماً قديماً للمعطّل تسبق تسليم الحيّ في الترتيب — كانت تملأ الدفعة كلها
        for ($i = 0; $i < 60; $i++) {
            WebhookDelivery::create(['webhook_id' => $dead->id, 'event' => 'clients.created',
                'event_id' => 'd' . $i, 'payload' => '{}', 'state' => 'queued',
                'created_at' => now()->subDays(2)->addSeconds($i)]);
        }
        $fresh = WebhookDelivery::create(['webhook_id' => $live->id, 'event' => 'clients.created',
            'event_id' => 'live-1', 'payload' => '{}', 'state' => 'queued', 'created_at' => now()]);

        \Illuminate\Support\Facades\Artisan::call('hub:outbox');

        $this->assertSame('sent', $fresh->fresh()->state, 'متراكمات اشتراك معطّل كانت تشلّ الطابور كله');
        $this->assertSame('queued', WebhookDelivery::where('webhook_id', $dead->id)->first()->state);
    }

    public function test_ops_reports_the_newest_backup_by_time_not_by_name(): void
    {
        $this->seedCore();
        $dir = storage_path('app/backups');
        @mkdir($dir, 0775, true);
        $old = $dir . '/hub-zzz-manual.json';
        $new = $dir . '/hub-2026-07-30-090000.json';
        file_put_contents($old, '{}');
        file_put_contents($new, '{}');
        touch($old, time() - 86400 * 30);
        touch($new, time() - 60);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        @unlink($old); @unlink($new);

        // الاسم اليدوي كان يتصدر الترتيب الأبجدي فيُعرض كآخر نسخة ويوهم أن النسخ تعمل
        $this->assertStringContainsString('hub-2026-07-30-090000.json', $html);
        $this->assertStringNotContainsString('hub-zzz-manual.json', $html);
    }

    public function test_new_ui_classes_are_defined_in_the_stylesheet(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        foreach (['.tabs', '.tab.on', '.btn.danger', '.vh', '.ro'] as $cls) {
            $this->assertStringContainsString($cls, $css, "الصنف {$cls} مستعمل في القوالب وغير معرّف");
        }
    }
}
