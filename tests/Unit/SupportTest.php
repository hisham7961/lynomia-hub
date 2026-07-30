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
}
