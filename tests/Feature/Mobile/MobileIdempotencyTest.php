<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **F1 · مالكُ مفتاحِ الـIdempotency للجوال** — Mobile Readiness · الطور D · Critic F1.
 *
 * العيبُ الأصليّ: `V1Controller::ikeyOf` كان يقرأ `api_token` وحدَه فيعيد `[null,null]`
 * لطلب الجوال — فالحجزُ يُتجاوَز صامتاً (كلُّ إعادةِ محاولةٍ تُنفَّذ مرّتين)، وأخطرُ: مالكٌ
 * `NULL` قد يطابق صفَّ مستخدمٍ آخر فيعيد ردَّه (تسريبٌ عابرٌ). الإصلاحُ: المالكُ =
 * `mobile_session->id` (لا NULL أبداً)، فكلُّ جلسةٍ معزولةٌ عن غيرها.
 *
 * ويُثبِت هذا الملفُّ أيضاً أنّ سطحَ التكامل `/api/v1` بقي **حرفاً بحرف** (المالكُ
 * `api_token->id` يفوز حتماً في مجموعة ApiAuth) — لا انحراف.
 */
class MobileIdempotencyTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    // ═══════════════════════ إعادةُ المحاولة بنفس المفتاح — حجزٌ لمرّة ═══════════════════════

    public function test_same_key_create_retry_dedupes_to_one_row(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-idem-dedupe-11') + ['Idempotency-Key' => 'mob-key-1'];

        $a = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'مرّةٌ واحدة'])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'مرّةٌ واحدة'])->assertCreated();

        // الثاني إعادةُ ردٍّ مخزّن — لا تنفيذٌ ثانٍ
        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'), 'نفسُ السجل — لا ازدواج');
        $this->assertSame(1, Client::where('name', 'مرّةٌ واحدة')->count(),
            'إعادةُ المحاولة بمفتاح الجوال يجب أن تُحجَز لمرّة (كان الحجزُ يُتجاوَز صامتاً · F1)');
    }

    // ═══════════════════════ دفاعُ إعادةِ التنفيذ عبر المستخدمين (IDOR) ═══════════════════════

    public function test_user_b_replaying_user_a_key_never_receives_a_response(): void
    {
        $this->seedCore();
        $ha = $this->auth($this->owner, 'inst-idem-A-11111') + ['Idempotency-Key' => 'shared-key-X'];
        $hb = $this->auth($this->employee, 'inst-idem-B-11111') + ['Idempotency-Key' => 'shared-key-X'];

        // المستخدمُ أ يُنشئ بجسمٍ خاصٍّ به
        $a = $this->withHeaders($ha)->postJson('/api/mobile/v1/clients', ['name' => 'سجلُّ أ'])->assertCreated();
        $aId = $a->json('data.id');

        // المستخدمُ ب يعيد **نفسَ المفتاح** بجسمٍ آخر — يجب ألّا يتلقّى ردَّ أ ولا معرّفَه
        $b = $this->withHeaders($hb)->postJson('/api/mobile/v1/clients', ['name' => 'سجلُّ ب'])->assertCreated();
        $bId = $b->json('data.id');

        $b->assertHeaderMissing('X-Idempotent-Replay');
        $this->assertNotSame($aId, $bId, 'ب تلقّى معرّفَ أ — تسريبُ إعادةِ تنفيذٍ عبر المستخدمين (F1)');
        $this->assertSame('سجلُّ ب', $b->json('data.name'), 'ب يجب أن يرى سجلَّه هو لا سجلَّ أ');
        $this->assertSame(1, Client::where('name', 'سجلُّ أ')->count());
        $this->assertSame(1, Client::where('name', 'سجلُّ ب')->count(),
            'كلُّ مستخدمٍ يُنشئ سجلَّه — المفتاحُ معزولٌ بالجلسة لا بـNULL مشترك');
    }

    // ═══════════════════════ تنفيذٌ متزامنٌ جارٍ ═══════════════════════

    public function test_in_flight_reservation_returns_idempotency_in_progress(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-idem-inflight-1');
        $h = $this->bearer($data['access_token']) + ['Idempotency-Key' => 'inflight-key'];

        // حجزٌ يتيمٌ حديثٌ بمالكِ هذه الجلسة (token_id = session_id) بلا ردٍّ بعد ⇒ «قيد المعالجة»
        DB::table('idempotency_keys')->insert([
            'id'         => (string) \Illuminate\Support\Str::uuid(),
            'token_id'   => $data['session_id'],   // المالكُ = معرّفُ جلسة الجوال (F1)
            'ikey'       => 'inflight-key',
            'fingerprint' => null,                 // بلا بصمةٍ ⇒ لا فحصَ إعادةِ استعمال، يمرّ لفرع «جارٍ»
            'code'       => null,
            'response'   => null,
            'created_at' => now(),
        ]);

        $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'متزامن'])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');
        $this->assertSame(0, Client::where('name', 'متزامن')->count(), 'لم يُنفَّذ الطلبُ الثاني المتزامن');
    }

    public function test_same_key_different_body_is_rejected_as_key_reused(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-idem-reuse-11') + ['Idempotency-Key' => 'reuse-key'];

        $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'الأول'])->assertCreated();
        // نفسُ المفتاح، جسمٌ مختلف ⇒ لا يُعاد ردُّ الأول (بياناتُ سجلٍّ آخر) بل يُرفَض صراحةً
        $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'الثاني'])
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    // ═══════════════════════ توافقٌ رجعيّ: /api/v1 لم يتغيّر ═══════════════════════

    public function test_api_v1_idempotency_is_unchanged_by_the_mobile_owner_fix(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'v1-key'];

        $a = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'تكاملٌ مرّة'])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'تكاملٌ مرّة'])->assertCreated();
        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Client::where('name', 'تكاملٌ مرّة')->count());
    }
}
