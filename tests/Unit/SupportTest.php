<?php

namespace Tests\Unit;

use App\Models\ApiToken;
use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_totp_rfc6238_vector(): void
    {
        // متجه RFC 6238 الرسمي (آخر ٦ أرقام): السر ASCII «12345678901234567890» بترميز Base32
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
    }

    protected function token(array $attrs): ApiToken
    {
        $t = new ApiToken;                       // تعبئة مباشرة — اختبار الوحدة بلا قاعدة
        foreach ($attrs as $k => $v) $t->setAttribute($k, $v);

        return $t;
    }

    public function test_token_scopes_parsing(): void
    {
        $t = $this->token(['scopes' => 'tickets:va, projects:v']);
        $this->assertTrue($t->allows('tickets', 'v'));
        $this->assertTrue($t->allows('tickets', 'a'));
        $this->assertFalse($t->allows('tickets', 'd'));
        $this->assertTrue($t->allows('projects', 'v'));
        $this->assertFalse($t->allows('projects', 'e'));
        $this->assertFalse($t->allows('clients', 'v'));

        $this->assertTrue($this->token(['scopes' => null])->allows('clients', 'd'));
        $this->assertTrue($this->token(['scopes' => '*'])->allows('clients', 'd'));
        $this->assertTrue($this->token(['scopes' => 'tickets'])->allows('tickets', 'd'));   // بلا عمليات = كلها
    }

    public function test_token_ip_rules(): void
    {
        $t = $this->token(['allowed_ips' => '1.2.3.4, 10.0.0.0/8']);
        $this->assertTrue($t->ipAllowed('1.2.3.4'));
        $this->assertTrue($t->ipAllowed('10.200.1.7'));
        $this->assertFalse($t->ipAllowed('11.0.0.1'));
        $this->assertFalse($t->ipAllowed('1.2.3.5'));
        $this->assertTrue($this->token(['allowed_ips' => null])->ipAllowed('8.8.8.8'));
    }

    /**
     * حارسُ حصر شبكة الحساب (ip_allowed) — يفرضه الدخولُ وSessionSentry وApiAuth.
     * كان يطابق بالبادئة النصية لأي قاعدة، فـ«203.0.113.7» يقبل «203.0.113.70»،
     * و«10.0.0.1» يفتح ١١١ عنواناً؛ والـCIDR السادس يسمح بالكل أو يسقط بـ500.
     */
    public function test_ip_allowed_is_exact_unless_explicit_wildcard(): void
    {
        // قاعدةٌ دقيقة لا تطابق بالبادئة — جوهرُ العطل
        $this->assertFalse(ip_allowed('203.0.113.70', '203.0.113.7'), 'قاعدةٌ دقيقة لا يجوز أن تطابق بالبادئة');
        $this->assertFalse(ip_allowed('10.0.0.155', '10.0.0.1'), 'قاعدةٌ دقيقة لا تفتح مدىً بالبادئة');
        $this->assertTrue(ip_allowed('203.0.113.7', '203.0.113.7'), 'المطابقة الدقيقة تعمل');

        // البدل الصريح (*) وحده يطابق بالبادئة
        $this->assertTrue(ip_allowed('203.0.113.70', '203.0.113.*'), 'البدل الصريح يطابق بالبادئة');
        $this->assertFalse(ip_allowed('203.0.114.1', '203.0.113.*'), 'البدل الصريح لا يتجاوز البادئة');

        // CIDR رابعة تعمل
        $this->assertTrue(ip_allowed('192.168.1.50', '192.168.1.0/24'));
        $this->assertFalse(ip_allowed('192.168.2.50', '192.168.1.0/24'));

        // CIDR سادسة: تُطابِق ضمن الشبكة ولا تسمح بالخارج ولا تسقط
        $this->assertTrue(ip_allowed('2001:db8::1', '2001:db8::/32'), 'IPv6 داخل الشبكة مسموح');
        $this->assertFalse(ip_allowed('2001:dead::1', '2001:db8::/32'), 'IPv6 خارج الشبكة مرفوض');

        // اختلافُ العائلة لا يُطابِق ولا يرمي
        $this->assertFalse(ip_allowed('10.0.0.5', '2001:db8::/32'), 'عائلةٌ مختلفة لا تُطابِق ولا تسقط');
    }
}
