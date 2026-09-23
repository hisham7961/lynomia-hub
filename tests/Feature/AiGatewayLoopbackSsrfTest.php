<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AiGateway;
use App\Support\Settings;
use Tests\TestCase;

/**
 * **SSRF-1 (تدقيق أمنيّ v2.600) — بوّابةُ الذكاء لا تُوجَّه إلى منافذِ البنيةِ الحسّاسة.**
 *
 * استثناءُ الـloopback في بوّابةِ الخروج كان يقبل **أيَّ منفذٍ** على 127.0.0.1
 * بلا تقييد — فيضبط محرِّرُ الذكاء (`aiAdmin`) العنوانَ على `http://127.0.0.1:6379`
 * (Redis) أو `:5432` (Postgres) ثمّ يُطلق «افحص الاتصال» فينعكس رمزُ HTTP وزمنُ
 * الاستجابة: مِجَسٌّ داخليٌّ وعرّافُ خدمات. الآن منفذُ البوّابةِ الحقيقيُّ (~4000)
 * يمرّ، ومنافذُ البنيةِ الحسّاسة (قواعدُ البيانات · المخازن · الوكلاءُ الإداريّون)
 * تُرفَض على مستوى بوّابةِ الخروج نفسِها. CWE-918.
 */
class AiGatewayLoopbackSsrfTest extends TestCase
{
    private function aiAdmin(): User
    {
        $role = Role::create(['name' => 'مشرف ذكاء', 'scope' => 'all',
            'flags' => ['aiAdmin' => 1], 'matrix' => []]);

        return User::create(['name' => 'مشرف', 'email' => 'ai'.uniqid().'@t.test',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    public function test_gateway_cannot_be_pointed_at_a_sensitive_loopback_port(): void
    {
        $this->seedCore();
        $u = $this->aiAdmin();

        // منفذُ Redis الداخليّ — يُرفَض بخطأِ حقلٍ ولا يُخزَّن
        $this->actingAs($u)->post('/admin/ai', ['url' => 'http://127.0.0.1:6379', 'enabled' => '1'])
            ->assertSessionHasErrors('url');
        $this->assertNotSame('http://127.0.0.1:6379', (string) setting('ai.gateway_url'),
            'عنوانُ منفذٍ حسّاسٍ حُفظ رغم رفضِ بوّابة الخروج');
    }

    public function test_the_real_litellm_loopback_gateway_still_works(): void
    {
        $this->seedCore();
        $u = $this->aiAdmin();

        // بوّابةُ LiteLLM الحقيقيّة على 127.0.0.1:4000 — تُقبَل
        $this->actingAs($u)->post('/admin/ai', ['url' => 'http://127.0.0.1:4000', 'enabled' => '1'])
            ->assertStatus(302);
        $this->assertSame('http://127.0.0.1:4000', (string) setting('ai.gateway_url'));

        // وبوّابةُ الخروجِ تُجيز التوليدَ إلى المنفذِ نفسِه لا إلى منفذٍ حسّاس
        Settings::put('ai.gateway_url', 'http://127.0.0.1:4000', 'ai');
        $this->assertTrue(AiGateway::outboundGate('http://127.0.0.1:4000/v1/models')['ok']);
        Settings::put('ai.gateway_url', 'http://127.0.0.1:6379', 'ai');
        $this->assertFalse(AiGateway::outboundGate('http://127.0.0.1:6379/v1/models')['ok'],
            'منفذُ Redis يجب أن يُرفَض حتّى لو صار العنوانَ المحفوظ');
    }
}
