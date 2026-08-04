<?php

namespace Tests\Feature;

use App\Support\Odoo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الموجة الرابعة — استنزاف عمّال FPM (availability):
 * بطاقة قنوات أودو تُطلق نداءً حاجباً لكل قناة تسلسلياً داخل تصيير الصفحة، وكل
 * نداء بمهلةٍ كلية ١٢ث بلا connectTimeout منفصل. خادمٌ يقبل الاتصال ثم يتوقف
 * (جدارٌ ناريّ/تحميلٌ زائد) يحجب العاملَ ١٢ث لكل قناةٍ من عشراتٍ — تضخيمٌ يسمح
 * لمستخدمٍ واحد بتثبيت العمّال وإسقاط الموقع.
 *
 * الإصلاح: (١) connectTimeout قصير منفصل على نداء rpc؛ (٢) قاطعٌ داخل النسخة
 * الواحدة — أول فشل اتصال يوسم الاتصال «ساقطاً» فتُرفض بقيةُ النداءات فوراً بلا
 * شبكة. فصفحةٌ بكاشٍ بارد وخادمٍ ميت تحجب مرةً واحدة لا عشراتٍ.
 */
class OdooFailFastRound4Test extends TestCase
{
    protected function readyOdoo(): Odoo
    {
        $this->hubSetting('odoo.url', 'http://odoo.test');
        $this->hubSetting('odoo.db', 'db1');
        $this->hubSetting('odoo.user', 'admin');
        $this->hubSetting('odoo.key', 'apikey123');
        $cli = Odoo::for('default');
        $this->assertTrue($cli->ready(), 'نسخة أودو الافتراضية غير جاهزة رغم اكتمال الإعدادات');

        return $cli;
    }

    protected function rpc(Odoo $cli, string $service, string $method, array $args): void
    {
        $ref = new \ReflectionMethod($cli, 'rpc');
        $ref->setAccessible(true);
        $ref->invoke($cli, $service, $method, $args);
    }

    public function test_dead_server_is_hit_once_not_per_call(): void
    {
        $this->readyOdoo();
        $cli = $this->readyOdoo();

        // خادمٌ لا يستجيب: كل إرسالٍ فعليّ يرمي فشل اتصال — نعدّ الإرساليات الفعلية
        $hits = 0;
        Http::fake(function () use (&$hits) {
            $hits++;
            throw new ConnectionException('لا استجابة من الخادم');
        });

        // نداءان متتاليان على نفس النسخة (كحلقة القنوات في البطاقة)
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->rpc($cli, 'object', 'execute_kw', ['db1', 1, 'apikey123', 'sale.order', 'search_count', [[]]]);
            } catch (\Throwable $e) {
                // متوقّع: النداء يفشل — يهمّنا كم مرة لمس الشبكة فعلاً
            }
        }

        $this->assertSame(1, $hits,
            'خادمٌ ميت لُمس مرتين — لا قاطع: كل قناةٍ تحجب عاملاً ١٢ث على حدة');
    }
}
