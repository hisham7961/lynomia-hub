<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ContractEvent;
use App\Models\PolicyAck;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١: **قصُّ بصمة الجهاز بالبايتات يُنتج UTF-8 فاسدة.**
 *
 * `substr($ua, 0, 200)` تقطع الحرف العربي (بايتان) نصفين حين يقع الحدُّ في
 * منتصفه. النتيجة على MySQL الصارمة: «Incorrect string value» فيسقط **كل مسارٍ
 * مُدقَّق** — والتدقيق يُستدعى من كل كتابةٍ في النظام. وعلى SQLite تمرّ صامتةً
 * فتفسد النسخة الاحتياطية لاحقاً (`json_encode` تعيد false على البايتة اليتيمة).
 *
 * الحارس: `hub_fit` تقصّ **بالمحارف** وتُطهّر الترميز — والمواضع الثلاثة كلها
 * (`hub_audit`، `hub_ack_do`، `ContractEvent::log`) تمرّ بها.
 */
class AuditDeviceEncodingTest extends TestCase
{
    /** وكيلُ متصفحٍ يقع حدُّ ٢٠٠ في منتصف حرفٍ عربي — بالضبط الحالة القاتلة */
    private function splittingUserAgent(int $max): string
    {
        // ‏(max-1) بايتة ASCII ثم حرفٌ عربي بايتاه على طرفَي الحدّ
        return str_repeat('A', $max - 1) . 'عربي طويل ' . str_repeat('ب', 50);
    }

    public function test_audit_device_is_valid_utf8_when_truncated(): void
    {
        $this->seedCore();

        // ‏id تزايديّ (bigIncrements) — الترتيب الدلاليّ منه لا من created_at
        request()->headers->set('User-Agent', $this->splittingUserAgent(200));
        $this->actingAs($this->owner);
        hub_audit('اختبار الترميز', 'clients', (string) \Illuminate\Support\Str::uuid());

        $dev = (string) (AuditEntry::query()->orderByDesc('id')->value('device') ?? '');

        $this->assertNotSame('', $dev, 'بصمة الجهاز مسجَّلة');
        $this->assertTrue(mb_check_encoding($dev, 'UTF-8'),
            'device مقطوعةٌ في منتصف حرف — MySQL ترفضها فيسقط كل مسار مُدقَّق');
        $this->assertLessThanOrEqual(200, mb_strlen($dev), 'القصّ يحترم عرض العمود بالمحارف');
    }

    public function test_policy_ack_device_is_valid_utf8(): void
    {
        $this->seedCore();
        $p = \App\Models\Policy::create(['title' => 'سياسةُ الاختبار', 'version' => '2.0', 'status' => 'سارية']);

        request()->headers->set('User-Agent', $this->splittingUserAgent(200));
        $this->actingAs($this->owner);
        hub_ack_do('policies', $p->id);

        $dev = (string) (PolicyAck::query()->orderByDesc('id')->value('device') ?? '');
        $this->assertNotSame('', $dev, 'إقرار السياسة مسجَّل');
        $this->assertTrue(mb_check_encoding($dev, 'UTF-8'),
            'policy_acks.device مقطوعةٌ في منتصف حرف');
    }

    public function test_contract_event_agent_is_valid_utf8(): void
    {
        $this->seedCore();

        request()->headers->set('User-Agent', $this->splittingUserAgent(250));
        $ev = ContractEvent::log('اختبار الترميز');

        $this->assertTrue(mb_check_encoding((string) $ev->agent, 'UTF-8'),
            'contract_events.agent مقطوعةٌ في منتصف حرف — وهي سجلُّ أدلةٍ قانونيّ');
        $this->assertLessThanOrEqual(250, mb_strlen((string) $ev->agent));
    }

    /** ترميزٌ فاسدٌ أصلاً في الوكيل (لا مجرد قصٍّ) يُطهَّر ولا يصل القاعدة */
    public function test_invalid_encoding_is_scrubbed_not_stored(): void
    {
        $this->seedCore();

        request()->headers->set('User-Agent', "Mozilla\xD8\x00 5.0");
        $this->actingAs($this->owner);
        $row = hub_audit('اختبار', 'clients', 'x');

        $this->assertTrue(mb_check_encoding((string) $row->device, 'UTF-8'),
            'البايتة اليتيمة تُطهَّر قبل التخزين — وإلا أفسدت النسخة الاحتياطية');
    }
}
