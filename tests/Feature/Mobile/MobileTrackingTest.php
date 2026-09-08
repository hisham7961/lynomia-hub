<?php

namespace Tests\Feature\Mobile;

use App\Models\Employee;
use App\Models\TrackPoint;
use App\Models\TrackSession;
use App\Models\User;
use Tests\TestCase;

/**
 * **F.4 · الموقع (التتبّع الميدانيّ)** — Mobile Readiness · الطور F.
 *
 * `MobileFileController::trackingStart/Points/End` تعيد استعمالَ
 * `V1Controller::trackStart/trackIngest/trackEnd` (لا محرّكٌ ثانٍ). القواعدُ المُثبَتة:
 *  • **بدءٌ صريحٌ بموافقة** (`consent=true`) وإلا ٤٢٢ — لا تتبّعَ بلا إقرار.
 *  • **دفعاتٌ** محدودةٌ، **منعُ تكرارٍ بنيويّ** (قيدُ `client_operation_id` الفريد)، **تسلسل**.
 *  • **Idempotency** (مالكُ الجوال · F1) على دفعةِ النقاط — إعادةُ المحاولةِ لا تُضاعف.
 *  • **إنهاءٌ صريح** يُغلق الجلسة — فلا تتبّعٌ خفيٌّ دائم؛ ولا نقطةَ بلا جلسةٍ بُدئت.
 *  • **بوّابةُ الدور الميدانيّ:** من ليس مندوباً ميدانيّاً لا يبدأ تتبّعاً (٤٠٣).
 */
class MobileTrackingTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** مندوبٌ ميدانيٌّ مربوطٌ بالموظفة (فيصير `Workday::emp` يجده بـ field_role) */
    private function fieldRep(): Employee
    {
        return Employee::create(['name' => 'مندوب مسار', 'status' => 'نشط',
            'field_role' => 'مندوب طبي', 'user_id' => $this->employee->id]);
    }

    private function goodPoints(): array
    {
        return [
            ['lat' => 29.37, 'lng' => 47.97, 'acc' => 10, 'op' => 'p1', 'at' => now()->timestamp],
            ['lat' => 29.38, 'lng' => 47.98, 'acc' => 12, 'op' => 'p2', 'at' => now()->timestamp + 60],
        ];
    }

    // ═══════════════════════ بدءٌ صريحٌ بموافقة ═══════════════════════

    public function test_start_requires_explicit_consent(): void
    {
        $this->seedCore();
        $this->fieldRep();
        $h = $this->auth($this->employee, 'inst-trk-consent-1');

        // بلا موافقة ⇒ ٤٢٢ (لا تتبّعَ بلا إقرار)
        $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => false])
            ->assertStatus(422);
        $this->assertSame(0, TrackSession::count(), 'لا جلسةَ بلا موافقة');

        // بموافقةٍ صريحة ⇒ ٢٠١ + جلسةٌ نشطة
        $start = $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(201);
        $sid = $start->json('session');
        $this->assertNotEmpty($sid);
        $this->assertNotNull(TrackSession::find($sid)->consent_at, 'الموافقةُ مختومةٌ على الجلسة');
    }

    public function test_a_non_field_employee_cannot_start_tracking(): void
    {
        $this->seedCore();
        // المالكُ بلا ملفِّ موظفٍ ميدانيّ ⇒ ٤٠٣ (بوّابةُ الدور الميدانيّ)
        $h = $this->auth($this->owner, 'inst-trk-nonfield-1');
        $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(403);
    }

    // ═══════════════════════ الدفعات: تخزينٌ + تسلسلٌ + منعُ تكرار ═══════════════════════

    public function test_points_batch_is_stored_then_end_closes_the_session(): void
    {
        $this->seedCore();
        $this->fieldRep();
        $h = $this->auth($this->employee, 'inst-trk-flow-111');

        $sid = $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(201)->json('session');

        // دفعةٌ تُستوعَب (تسلسلٌ بـ at) — النقطتان تُحفظان
        $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/points", ['points' => $this->goodPoints()])
            ->assertOk()->assertJsonPath('saved', 2);
        $this->assertSame(2, TrackPoint::where('session_id', $sid)->count());

        // إنهاءٌ صريح ⇒ الجلسةُ تُغلق (لا تتبّعٌ دائمٌ خفيّ)
        $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/end")
            ->assertOk()->assertJsonPath('points', 2);
        $this->assertSame('منتهية', TrackSession::find($sid)->status);
    }

    public function test_resent_batch_is_structurally_deduped(): void
    {
        $this->seedCore();
        $this->fieldRep();
        $h = $this->auth($this->employee, 'inst-trk-dedupe-11');

        $sid = $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(201)->json('session');
        $batch = ['points' => $this->goodPoints()];

        $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/points", $batch)
            ->assertOk()->assertJsonPath('saved', 2);
        // إعادةُ الدفعةِ نفسِها (نفس op) بلا مفتاح idempotency ⇒ القيدُ الفريدُ يمنع الازدواج بنيويّاً
        $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/points", $batch)
            ->assertOk()->assertJsonPath('saved', 0);
        $this->assertSame(2, TrackPoint::where('session_id', $sid)->count(),
            'التكرارُ البنيويُّ يمنع مضاعفةَ النقاط');
    }

    public function test_retried_points_batch_with_same_key_replays_and_does_not_double_store(): void
    {
        $this->seedCore();
        $this->fieldRep();
        $base = $this->auth($this->employee, 'inst-trk-idem-111');

        $sid = $this->withHeaders($base)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(201)->json('session');

        $h = $base + ['Idempotency-Key' => 'points-batch-1'];
        $batch = ['points' => $this->goodPoints()];

        $first = $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/points", $batch)
            ->assertOk()->assertJsonPath('saved', 2);
        $first->assertHeaderMissing('X-Idempotent-Replay');

        // إعادةُ المحاولةِ بالمفتاحِ نفسِه ⇒ ردٌّ مخزَّنٌ (saved=2 الأصليّة) لا تنفيذٌ ثانٍ (F1)
        $replay = $this->withHeaders($h)->postJson("/api/mobile/v1/tracking/{$sid}/points", $batch)->assertOk();
        $replay->assertHeader('X-Idempotent-Replay', 'true')->assertJsonPath('saved', 2);
        $this->assertSame(2, TrackPoint::where('session_id', $sid)->count(),
            'إعادةُ الدفعةِ بمفتاحِ الجوال لا تُضاعف النقاط');
    }

    // ═══════════════════════ لا نقطةَ بلا جلسةٍ بُدئت صراحةً ═══════════════════════

    public function test_no_points_accepted_for_a_session_that_was_never_started(): void
    {
        $this->seedCore();
        $this->fieldRep();
        $h = $this->auth($this->employee, 'inst-trk-nosession');

        // جلسةٌ لم تُبدأ (معرّفٌ عشوائيّ) ⇒ ٤٠٤ — لا استيعابَ خارجَ جلسةٍ صريحة
        $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/' . \Illuminate\Support\Str::uuid() . '/points',
            ['points' => $this->goodPoints()])->assertStatus(404);
    }

    public function test_a_users_session_cannot_be_fed_points_by_another_field_rep(): void
    {
        $this->seedCore();
        // مندوبةٌ (الموظفة) تبدأ جلستها
        $this->fieldRep();
        $hEmp = $this->auth($this->employee, 'inst-trk-owner-sess');
        $sid = $this->withHeaders($hEmp)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(201)->json('session');

        // مندوبٌ آخرُ (المشاهد) — الجلسةُ ليست له (emp_id مختلف) ⇒ ٤٠٤ لا حقنَ نقاطٍ في جلسةِ غيره
        Employee::create(['name' => 'مندوبٌ آخر', 'status' => 'نشط',
            'field_role' => 'مندوب طبي', 'user_id' => $this->viewer->id]);
        $hView = $this->auth($this->viewer, 'inst-trk-other-rep');
        $this->withHeaders($hView)->postJson("/api/mobile/v1/tracking/{$sid}/points", ['points' => $this->goodPoints()])
            ->assertStatus(404);
        $this->assertSame(0, TrackPoint::where('session_id', $sid)->count(), 'لا نقطةَ من مندوبٍ ليس صاحبَ الجلسة');
    }

    public function test_tracking_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->postJson('/api/mobile/v1/tracking/start', ['consent' => true])
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
