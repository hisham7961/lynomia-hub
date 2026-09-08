<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\User;
use Tests\TestCase;

/**
 * **التزامُن + عدمُ الأثرِ + التوافقُ الرجعيّ (Mobile Readiness · الطور G · G.3/G.4)**.
 *
 * G.3 — الكتابةُ على الجوال تحفظ `If-Match` ⇒ `VERSION_CONFLICT` عبر محرّكِ v1 المُعادِ
 * استعمالُه (`Api::assertVersion`) — لا مسارٌ ثانٍ. هذا يغطّي مسارَ الكتابةِ المباشرَ
 * (`parent::apiUpdate/apiPatch`) الذي لم يكن مُغطّىً (تعارُضُ الموافقة مُغطّىً في
 * `MobileApprovalWriteTest`، وعدمُ أثرِ الإنشاء/الإجراء في `MobileIdempotencyTest`/
 * `MobileActionsTest`). G.4 — تأكيدُ عدمِ أثرِ إعادةِ المحاولة بمالكِ الجوال.
 *
 * والتوافقُ الرجعيّ: `/api/v1` سليمٌ ولا مسارَ مزامنةٍ فيه — الإضافةُ لا الكسر.
 */
class MobileSyncConcurrencyTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    // ═══════════════════════ G.3 · القفلُ التفاؤليّ (If-Match) ═══════════════════════

    public function test_if_match_mismatch_on_put_is_a_version_conflict(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'قبل التعديل']);   // النسخةُ تبدأ ١
        $h = $this->auth($this->owner, 'inst-conf-put-111');

        // If-Match بنسخةٍ خاطئة ⇒ ٤٠٩ VERSION_CONFLICT، والسجلُّ لا يتغيّر
        $res = $this->withHeaders($h + ['If-Match' => '"999"'])
            ->putJson('/api/mobile/v1/clients/' . $c->id, ['name' => 'محاولةُ دهس']);
        $res->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame('قبل التعديل', $c->fresh()->name, 'التعارُضُ يمنع الكتابة');
    }

    public function test_if_match_mismatch_on_patch_is_a_version_conflict(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'ثابت']);
        $h = $this->auth($this->owner, 'inst-conf-patch-11');

        $this->withHeaders($h + ['If-Match' => '"7"'])
            ->patchJson('/api/mobile/v1/clients/' . $c->id, ['name' => 'دهسٌ جزئيّ'])
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertSame('ثابت', $c->fresh()->name);
    }

    public function test_if_match_matching_the_current_version_succeeds_and_bumps_it(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'أصل']);
        $this->assertSame(1, (int) $c->version);
        $h = $this->auth($this->owner, 'inst-conf-ok-1111');

        // If-Match بالنسخة الصحيحة (١) ⇒ نجاحٌ، وترتفع النسخةُ إلى ٢
        $this->withHeaders($h + ['If-Match' => '"1"'])
            ->putJson('/api/mobile/v1/clients/' . $c->id, ['name' => 'مُحدَّث'])
            ->assertOk()->assertJsonPath('data.name', 'مُحدَّث');
        $fresh = $c->fresh();
        $this->assertSame('مُحدَّث', $fresh->name);
        $this->assertSame(2, (int) $fresh->version, 'النسخةُ ترتفع بعد كتابةٍ ناجحة (رمزُ التعارُض التالي)');
    }

    public function test_a_write_without_if_match_is_allowed_for_old_clients(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'بلا قفل']);
        $h = $this->auth($this->owner, 'inst-conf-noif-11');

        // غيابُ If-Match لا يمنع (عميلٌ قديم) — العقدُ نفسُه في الويب و/api/v1
        $this->withHeaders($h)->patchJson('/api/mobile/v1/clients/' . $c->id, ['name' => 'مرّ بلا قفل'])
            ->assertOk()->assertJsonPath('data.name', 'مرّ بلا قفل');
    }

    // ═══════════════════════ G.4 · عدمُ الأثر بمالكِ الجوال ═══════════════════════

    public function test_a_retried_mobile_write_under_a_key_executes_once(): void
    {
        $this->seedCore();
        // إعادةُ محاولةٍ لجانبٍ قابلٍ لإعادة التنفيذ (إنشاء) بالمفتاح نفسِه ⇒ حجزٌ لمرّة
        // (المالكُ = mobile_session->id لا NULL · Critic F1) — سجلٌّ واحدٌ لا سجلّان.
        $h = $this->auth($this->owner, 'inst-conf-idem-11') + ['Idempotency-Key' => 'g4-mob-key'];

        $a = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'مرّةٌ واحدة G4'])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'مرّةٌ واحدة G4'])->assertCreated();

        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Client::where('name', 'مرّةٌ واحدة G4')->count(),
            'إعادةُ المحاولة بمفتاحِ الجوال تُحجَز لمرّة (G.4)');
    }

    // ═══════════════════════ التوافقُ الرجعيّ: /api/v1 سليمٌ بلا مزامنة ═══════════════════════

    public function test_api_v1_is_untouched_and_has_no_sync_route(): void
    {
        $this->seedCore();
        Client::create(['name' => 'عميلُ التكامل']);
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok];

        // CRUD التكامل يعمل كما هو
        $this->withHeaders($h)->getJson('/api/v1/clients')->assertOk();

        // ولا مسارَ مزامنةٍ في /api/v1 — «sync» يُحلّ كوحدةٍ مجهولةٍ عبر الـcatch-all
        // فيعيد RESOURCE_NOT_FOUND — دليلٌ أنّ سطحَ الجوال لم يتسرّب إلى /api/v1 (إضافةٌ لا كسر)
        $this->withHeaders($h)->getJson('/api/v1/sync/clients')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
