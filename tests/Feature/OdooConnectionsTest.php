<?php

namespace Tests\Feature;

use App\Models\OdooConnection;
use App\Support\Odoo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesOdoo;
use Tests\TestCase;

/**
 * **اتصالات أودو المتعددة** — بعض المشاريع لها أودو خاص.
 *
 * الاتصال الافتراضي (إعدادات odoo.*) يبقى كما هو، والخوادم الإضافية في
 * `odoo_connections`. أخطر عيوب هذا التعدد اثنان يثبَّتان هنا:
 * تسرُّب الكاش بين خادمين (أرقام شركةٍ تظهر لأخرى)، وسقوطُ اتصالٍ
 * محذوفٍ صامتاً إلى الخادم الافتراضي.
 */
class OdooConnectionsTest extends TestCase
{
    use FakesOdoo;

    protected function setUp(): void
    {
        parent::setUp();
        // مُحلِّل DNS مزيف — القرار الحقيقي لحارس SSRF يبقى، بلا DNS حيّ:
        // أسماء example.com عامة، والعناوين الرقمية تمرّ للفلتر كما هي
        $this->app->instance('hub.dns', fn (string $h) => ['93.184.216.34']);
    }

    protected function conn(array $over = []): OdooConnection
    {
        return OdooConnection::create($over + [
            'name' => 'أودو المتجر', 'url' => 'https://shop.example.com',
            'db' => 'shopdb', 'username' => 'ro@shop.example.com',
            'key_cipher' => 'secret-key-1', 'active' => true,
        ]);
    }

    public function test_center_odoo_screen_is_owner_only(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get('/admin/integrations/odoo')->assertForbidden();
        $this->actingAs($this->viewer)->get('/admin/integrations/odoo')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/integrations/odoo')->assertOk()
            ->assertSee('خوادم أودو')->assertSee('الافتراضي');
    }

    public function test_store_encrypts_key_and_never_echoes_it(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/integrations/odoo', [
            'name' => 'أودو المصنع', 'url' => 'https://factory.example.com',
            'db' => 'fabdb', 'username' => 'ro@fab.com', 'key' => 'plain-api-key-xyz',
        ])->assertRedirect();

        $raw = (string) DB::table('odoo_connections')->value('key_cipher');
        $this->assertNotSame('plain-api-key-xyz', $raw, 'المفتاح مخزونٌ نصاً صريحاً');
        $this->assertSame('plain-api-key-xyz', OdooConnection::first()->key_cipher, 'الكاست لا يفكّ التشفير');

        $html = $this->actingAs($this->owner)->get('/admin/integrations/odoo')->getContent();
        $this->assertStringNotContainsString('plain-api-key-xyz', $html, 'المفتاح يُرَدّ للشاشة');
    }

    public function test_update_with_blank_key_keeps_stored_secret(): void
    {
        $this->seedCore();
        $c = $this->conn();

        $this->actingAs($this->owner)->put("/admin/integrations/odoo/{$c->id}", [
            'name' => 'اسم جديد', 'url' => $c->url, 'db' => $c->db,
            'username' => $c->username, 'key' => '',
        ])->assertRedirect();

        $fresh = $c->fresh();
        $this->assertSame('اسم جديد', $fresh->name);
        $this->assertSame('secret-key-1', $fresh->key_cipher, 'مفتاحٌ فارغ محا المخزون');
    }

    public function test_store_rejects_private_hosts_unless_allowed(): void
    {
        $this->seedCore();
        $post = fn () => $this->actingAs($this->owner)->post('/admin/integrations/odoo', [
            'name' => 'داخلي', 'url' => 'http://169.254.169.254/odoo',
            'db' => 'x', 'username' => 'a@b.c', 'key' => 'k',
        ]);

        $post()->assertSessionHasErrors('url');
        $this->assertSame(0, OdooConnection::count(), 'عنوانُ بوابة السحابة قُبل');

        // التنصيب الداخلي المغلق يفتحها عمداً — المهرب القائم نفسه
        $this->hubSetting('monitor.allow_private', '1');
        $post()->assertSessionHasNoErrors();
        $this->assertSame(1, OdooConnection::count());
    }

    public function test_default_connection_is_virtual_and_legacy_statics_still_work(): void
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://main.example.com', 'odoo.db' => 'maindb',
                  'odoo.user' => 'ro@main.com', 'odoo.key' => 'main-key'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        $this->fakeOdoo('https://main.example.com', [
            'common.version' => ['server_version' => '17.0'],
            'common.authenticate' => 7,
        ]);

        $this->assertTrue(Odoo::configured());
        $this->assertSame('17.0', Odoo::version());
        $this->assertSame(7, Odoo::uid());

        $list = Odoo::connections();
        $this->assertSame('default', $list[0]['id']);
        $this->assertTrue($list[0]['ready']);
    }

    /** أخطرُ عيوب التعدد: أرقامُ خادمٍ تُقرأ من كاش خادمٍ آخر */
    public function test_cache_keys_are_scoped_per_connection(): void
    {
        $this->seedCore();
        $a = $this->conn(['name' => 'أ', 'url' => 'https://a.example.com']);
        $b = $this->conn(['name' => 'ب', 'url' => 'https://b.example.com']);
        $this->fakeOdoo('https://a.example.com', ['common.authenticate' => 7]);
        $this->fakeOdoo('https://b.example.com', ['common.authenticate' => 9]);

        // Http::fake الثاني يمسح الأول — فتُبنى الخريطتان معاً
        \Illuminate\Support\Facades\Http::fake([
            'https://a.example.com/jsonrpc' => \Illuminate\Support\Facades\Http::response(['jsonrpc' => '2.0', 'result' => 7], 200),
            'https://b.example.com/jsonrpc' => \Illuminate\Support\Facades\Http::response(['jsonrpc' => '2.0', 'result' => 9], 200),
        ]);

        $this->assertSame(7, Odoo::for($a->id)->login());
        $this->assertSame(9, Odoo::for($b->id)->login(), 'هوية الخادم الأول تسرّبت للثاني عبر الكاش');
        $this->assertNotNull(Cache::get('odoo:' . $a->id . ':uid'));
        $this->assertNotNull(Cache::get('odoo:' . $b->id . ':uid'));
    }

    public function test_connection_test_button_records_last_ok_and_version(): void
    {
        $this->seedCore();
        $c = $this->conn();
        $this->fakeOdoo('https://shop.example.com', [
            'common.version' => ['server_version' => '18.0'],
            'common.authenticate' => 11,
        ]);

        $this->actingAs($this->owner)->post("/admin/integrations/odoo/{$c->id}/test")
            ->assertRedirect()->assertSessionHas('ok');

        $fresh = $c->fresh();
        $this->assertNotNull($fresh->last_ok_at);
        $this->assertSame('18.0', $fresh->last_version);
    }

    public function test_failed_test_reports_and_leaves_history_untouched(): void
    {
        $this->seedCore();
        $c = $this->conn(['last_ok_at' => now()->subDay(), 'last_version' => '16.0']);
        $this->fakeOdoo('https://shop.example.com', [
            'common.version' => ['#error' => 'Access Denied'],
        ]);

        $this->actingAs($this->owner)->post("/admin/integrations/odoo/{$c->id}/test")
            ->assertSessionHasErrors('conn');

        $fresh = $c->fresh();
        $this->assertSame('16.0', $fresh->last_version, 'الفشل محا تاريخ آخر نجاح');
        $this->assertNotNull($fresh->last_ok_at);
    }

    /** اتصالٌ محذوف أو معطّل: خطأ ظاهر — لا سقوطَ صامتاً لخادمٍ آخر */
    public function test_deleted_or_disabled_connection_yields_visible_error_not_silent_default(): void
    {
        $this->seedCore();
        $c = $this->conn();
        $id = $c->id;

        $c->update(['active' => false]);
        $off = Odoo::for($id);
        $this->assertFalse($off->ready());
        $this->assertStringContainsString('معطّل', (string) $off->error());

        $c->delete();
        $dead = Odoo::for($id);
        $this->assertFalse($dead->ready());
        $this->assertStringContainsString('محذوف', (string) $dead->error());

        // ولا نداء يمرّ من نسخةٍ ميتة
        $this->expectException(\RuntimeException::class);
        $dead->serverVersion();
    }
}
