<?php

namespace Tests\Feature;

use App\Support\Integrations;
use App\Support\TimeRange;
use App\Support\Uptime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-2.6) بطاقاتُ الاعتماديات وتاريخُ التوافر في مركز التشغيل.
 *
 * المبدآن المحروسان هنا:
 * ١) البطاقاتُ من **الإعداد الفعليّ** وحده — لا بطاقةَ أودو بلا اتصالٍ مضبوط، ولا
 *    بطاقةَ بريدٍ والمُرسِل log/array (نجاحُه كاذبٌ أصلاً).
 * ٢) التوافرُ يُجمَّع في **قاعدة البيانات** لا بجلب ٣٠ يوماً إلى PHP كما تفعل
 *    hub_uptime — والدليل أنّ أرقام المجمِّع الجديد تساوي أرقامَها حرفياً.
 */
class OpsDependenciesTest extends TestCase
{
    /** لا بطاقةَ أودو ولا بريدَ بلا ضبط — والبنيةُ (قاعدة/كاش/تخزين/طابور) حاضرةٌ دائماً */
    public function test_no_odoo_or_mail_card_without_config(): void
    {
        $this->seedCore();
        config(['mail.default' => 'array']);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();

        $this->assertStringContainsString('dep-core-db', $html, 'بطاقة قاعدة البيانات غائبة');
        $this->assertStringContainsString('dep-core-cache', $html);
        $this->assertStringContainsString('dep-core-storage', $html);
        $this->assertStringContainsString('dep-core-queue', $html);
        $this->assertStringNotContainsString('dep-odoo', $html, 'بطاقةُ أودو ظهرت بلا إعداد');
        $this->assertStringNotContainsString('dep-mail', $html, 'بطاقةُ البريد ظهرت والمُرسِل array');
    }

    /** بطاقةُ أودو تظهر مع الضبط، وتعرض المدّة الملتقطة integration.odoo.last_ms */
    public function test_odoo_card_appears_with_config_and_shows_captured_latency(): void
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://odoo.example.com', 'odoo.db' => 'main',
                  'odoo.user' => 'svc', 'odoo.key' => 'k-123'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        Integrations::pulse('odoo', true, null, 234);

        $this->assertSame('234', (string) setting('integration.odoo.last_ms'),
            'النبضة لم تلتقط مدّةَ النداء');

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('dep-odoo', $html, 'بطاقةُ أودو غائبة رغم الضبط');
        $this->assertStringContainsString('234', $html, 'مدّةُ آخر نداءٍ لا تُعرض');
    }

    /** والبريد الحقيقي (غير log/array) يصنع بطاقته */
    public function test_real_mailer_makes_a_mail_card(): void
    {
        $this->seedCore();
        config(['mail.default' => 'smtp']);

        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('dep-mail', $html, 'المُرسِل الحقيقي بلا بطاقة');
    }

    /** Odoo::rpc يقيس مدّتَه بنفسه ويكتبها عبر النبضة — على النجاح والفشل معاً */
    public function test_odoo_rpc_measures_its_own_duration(): void
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://odoo.example.com', 'odoo.db' => 'main',
                  'odoo.user' => 'svc', 'odoo.key' => 'k-123'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0',
            'result' => ['server_version' => '17.0']], 200)]);

        \App\Support\Odoo::version();

        $ms = (string) setting('integration.odoo.last_ms', '');
        $this->assertNotSame('', $ms, 'مدّةُ نداء أودو لم تُكتب في integration.odoo.last_ms');
        $this->assertGreaterThanOrEqual(0, (int) $ms);
    }

    /**
     * قلبُ الحزمة: التجميعُ في القاعدة يساوي hub_uptime رقماً برقم، ويشتقّ
     * **فتراتِ الانقطاع** (تتابعُ up=0) التي لا تعرفها الدالّة القديمة أصلاً.
     */
    public function test_db_aggregated_uptime_matches_hub_uptime_and_derives_outages(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        $t0 = now()->subDays(3)->startOfMinute();

        // النمط: ✓ ✓ ✗ ✗ ✓ ✗ ✓ ✓  ⇒ توافر 6/8=75٪ وانقطاعان (طولاهما ٢ و١)
        $pattern = [1, 1, 0, 0, 1, 0, 1, 1];
        foreach ($pattern as $i => $v) {
            $at = $t0->copy()->addMinutes($i * 5);
            hub_metric_put('servers', $id, 'up', $v, $at, 'monitor');
            if ($v) hub_metric_put('servers', $id, 'latency', 100 + $i * 10, $at, 'monitor');
        }

        $old = hub_uptime('servers', $id, 30);
        $agg = Uptime::history('servers', $id,
            TimeRange::fromRequest(new \Illuminate\Http\Request(['range' => '30d'])));

        $this->assertSame($old['checks'], $agg['checks'], 'عدد الفحوص لا يطابق hub_uptime');
        $this->assertSame($old['pct'], $agg['pct'], 'نسبة التوافر المجمَّعة في القاعدة لا تطابق hub_uptime');
        $this->assertSame($old['ms'], $agg['ms'], 'متوسط الاستجابة المجمَّع لا يطابق hub_uptime');
        $this->assertSame($old['down'], $agg['down']);

        $this->assertCount(2, $agg['outages'], 'فترتا الانقطاع لم تُشتقّا من السلسلة');
        $this->assertSame(2, (int) $agg['outages'][0]['checks'], 'الانقطاع الأول فحصان متتاليان');
        $this->assertSame(1, (int) $agg['outages'][1]['checks'], 'الانقطاع الثاني فحص واحد');
        $this->assertTrue($agg['outages'][0]['from'] < $agg['outages'][1]['from'], 'الانقطاعات مرتّبة زمنياً');
        $this->assertFalse($agg['outages'][1]['open'], 'آخر فحصٍ ناجح — لا انقطاعَ مفتوحاً');
    }

    /** انقطاعٌ في ذيل السلسلة يُعلَن مفتوحاً — «تعافى» لا تُقال قبل فحصٍ ناجح */
    public function test_trailing_outage_is_reported_open(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        $t0 = now()->subHours(2)->startOfMinute();
        foreach ([1, 0, 0] as $i => $v) {
            hub_metric_put('servers', $id, 'up', $v, $t0->copy()->addMinutes($i * 5), 'monitor');
        }

        $agg = Uptime::history('servers', $id,
            TimeRange::fromRequest(new \Illuminate\Http\Request(['range' => '30d'])));

        $this->assertCount(1, $agg['outages']);
        $this->assertTrue($agg['outages'][0]['open'], 'الانقطاع الجاري لم يُعلَن مفتوحاً');
        $this->assertFalse($agg['live'], 'آخرُ فحصٍ فاشل والحالةُ تقول يعمل');
    }

    /** لا بيانات ⇒ null لا أصفارٌ مُختلَقة — والشاشة تصارح بالفراغ */
    public function test_empty_series_yields_nulls_and_honest_screen(): void
    {
        $this->seedCore();

        $agg = Uptime::history('servers', (string) Str::uuid(),
            TimeRange::fromRequest(new \Illuminate\Http\Request(['range' => '30d'])));
        $this->assertSame(0, $agg['checks']);
        $this->assertNull($agg['pct']);
        $this->assertNull($agg['ms']);
        $this->assertSame([], $agg['outages']);

        // لا أهدافَ مراقبةً في النظام ⇒ الشاشةُ تقولها لا جدولاً فارغاً صامتاً
        $html = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('سيبدأ القياس من الآن', $html);
    }
}
