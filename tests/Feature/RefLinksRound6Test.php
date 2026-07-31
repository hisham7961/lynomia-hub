<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Domain;
use App\Models\RestoreTest;
use App\Models\Ticket;
use App\Models\Website;
use Tests\TestCase;

/**
 * المرحلة ١ — الربط منخفض الجهد:
 *  1) tickets.client_id يُكمل رؤية العميل ٣٦٠° — كانت التذاكر الحلقة المفقودة
 *     الوحيدة (نص حر) فلا تظهر في رحلته ولا صفحته.
 *  2) websites.domainId يفعّل العلاقة العكسية تلقائياً — الموقع يظهر في صفحة دومينه.
 *  3) restores.nextTest بوسم expiry يدخل رادار الانتهاءات — وحدة الانضباط
 *     الدوري كانت بلا أي موعد يذكّر.
 */
class RefLinksRound6Test extends TestCase
{
    public function test_client_journey_and_page_show_linked_tickets(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل التذاكر', 'stage' => 'عميل حالي']);
        Ticket::create(['subject' => 'مشكلة الدخول للنظام', 'client_id' => $client->id, 'status' => 'جديدة']);

        // رحلة العميل تعرض تذكرته
        $this->actingAs($this->owner)->get('/journey/' . $client->id)
            ->assertOk()->assertSee('مشكلة الدخول للنظام');

        // وصفحة سجله تعرضها ضمن السجلات المرتبطة (hub_related)
        $this->actingAs($this->owner)->get('/m/clients/' . $client->id)
            ->assertOk()->assertSee('مشكلة الدخول للنظام');
    }

    public function test_website_appears_under_its_domain(): void
    {
        $this->seedCore();
        $domain = Domain::create(['name' => 'linked-site.com', 'expiry' => now()->addYear()->toDateString()]);
        Website::create(['name' => 'موقع الشركة الرسمي', 'url' => 'https://linked-site.com',
            'domain_id' => $domain->id]);

        $this->actingAs($this->owner)->get('/m/domains/' . $domain->id)
            ->assertOk()->assertSee('موقع الشركة الرسمي');
    }

    public function test_next_restore_test_enters_expiry_radar(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        RestoreTest::create(['title' => 'اختبار استعادة الإنتاج',
            'next_test' => now()->addDays(10)->toDateString()]);

        $keys = collect(hub_expiry(true, $this->owner))
            ->map(fn ($e) => $e['module'] . ':' . $e['name'])->all();
        $this->assertContains('restores:اختبار استعادة الإنتاج', $keys,
            'موعد الاختبار الدوري القادم يجب أن يذكّر عبر الرادار');
    }
}
