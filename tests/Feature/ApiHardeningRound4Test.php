<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Task;
use App\Models\VaultSecret;
use Tests\TestCase;

/**
 * الموجة الرابعة — تصليب واجهة API v1:
 * (أ) GET /api/v1/vault كان يعيد كل الأسرار نصّاً صريحاً دون قيد تدقيق — بينما كل
 *     كشفٍ مفردٍ في الويب يُسجَّل «عرض حساس». نقطةٌ عمياء على أخطر مجموعة بيانات.
 * (ب) مفتاح Idempotency كان يُطابَق بـ(token_id, ikey) وحدهما بلا بصمة جسم/مسار —
 *     فطلبٌ ثانٍ بمفتاحٍ مُعاد وجسمٍ مختلف يعيد ردَّ الأول ولا يُنشئ الثاني (فقدٌ صامت).
 */
class ApiHardeningRound4Test extends TestCase
{
    /** (أ) قراءة أسرار الخزنة عبر API تُسجَّل في التدقيق */
    public function test_api_vault_read_is_audited(): void
    {
        $this->seedCore();
        VaultSecret::create(['title' => 'سر الإنتاج', 'secret_cipher' => 'p@ssw0rd!']);

        $tok = $this->apiToken($this->owner, 'vault:v');
        $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/vault')->assertOk();

        $this->assertTrue(
            AuditEntry::where('action', 'عرض حساس عبر API')->where('module', 'vault')->exists(),
            'قراءة أسرار الخزنة عبر API لم تُسجَّل — نقطة عمياء رقابية');
    }

    /** (ب) إعادة مفتاح idempotency بجسمٍ/مسارٍ مختلف تُرفض لا تعيد رد الأول */
    public function test_idempotency_key_reused_with_different_request_is_rejected(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'reuse-key-1'];

        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل أول'])->assertStatus(201);

        // نفس المفتاح، مسار وجسم مختلفان → يُرفض بـ422 (لا 201 يحمل بيانات العميل)
        $this->withHeaders($h)->postJson('/api/v1/tasks', ['title' => 'مهمة ضائعة'])
            ->assertStatus(422);

        $this->assertFalse(Task::where('title', 'مهمة ضائعة')->exists(),
            'أُنشئت أو فُقدت المهمة بصمت رغم إعادة المفتاح بطلبٍ مختلف');
    }

    /** ولا يُكسر الاستخدام الصحيح: نفس المفتاح ونفس الطلب يُعاد بلا تكرار */
    public function test_idempotency_same_request_still_replays(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'same-req-1'];

        $a = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل مكرّر'])->assertStatus(201);
        $b = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل مكرّر']);
        $b->assertStatus(201)->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame(1, \App\Models\Client::where('name', 'عميل مكرّر')->count(), 'كُرّر رغم idempotency');
    }
}
