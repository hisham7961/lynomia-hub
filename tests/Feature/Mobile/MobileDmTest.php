<?php

namespace Tests\Feature\Mobile;

use App\Models\DmMessage;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **E.4 · مراسلةُ الجوال المباشرة — خصوصيّةُ الطرفَين (Critic F8)** — Mobile
 * Readiness · الطور E.
 *
 * العقدُ الجوهريّ: المسارُ يأخذ **معرّفَ الطرفِ الآخر** لا خيطاً، والمفتاحُ يُبنى
 * خادميّاً من `auth()->id()`+الطرف (`DmService::thread/send/markThreadRead` عبر
 * `DmMessage::threadKey`). فلا A تقرأ ولا تُعدِّد خيطَ B↔C ولو خمّنت المعرّفات —
 * مخاطبةُ C من A تُعطي خيطَ A↔C (خاصّتها) لا رسائلَ B↔C. الإرسالُ **idempotent** (F1).
 */
class MobileDmTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    // ═══════════════════════ A↔B: إرسال/قراءة/غير مقروء ═══════════════════════

    public function test_a_b_send_read_and_unread_flow(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-A-1');
        $B = $this->auth($this->employee, 'inst-dm-B-1');

        // A ⇒ B
        $this->withHeaders($A)->postJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/send',
            ['body' => 'مرحبا يا زميلة'])
            ->assertOk()
            ->assertJsonPath('data.message.mine', true)
            ->assertJsonPath('data.message.body', 'مرحبا يا زميلة')
            ->assertJsonPath('data.message.from_id', (string) $this->owner->id)
            ->assertJsonPath('data.message.to_id', (string) $this->employee->id);

        // B يقرأ الخيطَ مع A — الرسالةُ واردةٌ (mine=false)
        $msgs = $this->withHeaders($B)->getJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/messages')
            ->assertOk()->json('data.messages');
        $this->assertCount(1, $msgs);
        $this->assertFalse($msgs[0]['mine']);
        $this->assertSame('مرحبا يا زميلة', $msgs[0]['body']);

        // قائمةُ خيوطِ B فيها خيطُ A بغيرِ مقروءٍ = ١ (والقراءةُ لم تُختَم بعد)
        $threads = $this->withHeaders($B)->getJson('/api/mobile/v1/dm/threads')->assertOk()->json('data.threads');
        $thread = collect($threads)->firstWhere('user.id', (string) $this->owner->id);
        $this->assertNotNull($thread);
        $this->assertSame(1, $thread['unread'], 'قراءةُ الرسائلِ (GET) لا تختم القراءة — العدُّ باقٍ');

        // ختمُ القراءة صراحةً ⇒ يعود العدُّ صفراً
        $this->withHeaders($B)->postJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/read')
            ->assertOk()->assertJsonPath('data.marked', 1);

        $after = $this->withHeaders($B)->getJson('/api/mobile/v1/dm/threads')->assertOk()->json('data.threads');
        $thread2 = collect($after)->firstWhere('user.id', (string) $this->owner->id);
        $this->assertSame(0, $thread2['unread'], 'بعد الختمِ لا غيرَ مقروء');
    }

    // ═══════════════════════ F8 · A لا تقرأ ولا تُعدِّد B↔C ═══════════════════════

    public function test_a_cannot_read_or_enumerate_the_b_c_thread(): void
    {
        $this->seedCore();
        $B = $this->auth($this->employee, 'inst-dm-B-2');

        // B ⇒ C (سرٌّ بين اثنَين لا ثالثَ لهما)
        $secret = 'سرٌّ بين الاثنين لا يبلغه ثالث';
        $this->withHeaders($B)->postJson('/api/mobile/v1/dm/threads/' . $this->viewer->id . '/send',
            ['body' => $secret])->assertOk();

        // A تخاطب C ⇒ تحصل على خيطِ A↔C (خاصّتها، فارغٌ) لا رسائلَ B↔C
        $A = $this->auth($this->owner, 'inst-dm-A-2');
        $acThread = $this->withHeaders($A)->getJson('/api/mobile/v1/dm/threads/' . $this->viewer->id . '/messages')
            ->assertOk();
        $this->assertCount(0, $acThread->json('data.messages'), 'خيطُ A↔C فارغٌ — لا رسائلَ B↔C');
        $this->assertStringNotContainsString($secret, $acThread->getContent(), 'سرُّ B↔C لا يظهر لـ A أبداً (F8)');

        // وقائمةُ خيوطِ A لا تحمل خيطَ B↔C (ليست طرفاً فيه)
        $threads = $this->withHeaders($A)->getJson('/api/mobile/v1/dm/threads')->assertOk();
        $this->assertCount(0, $threads->json('data.threads'), 'A ليست طرفاً في أيّ خيطٍ — لا تعداد لـ B↔C');
        $this->assertStringNotContainsString($secret, $threads->getContent());

        // برهانٌ في القاعدة: رسالةُ B↔C موجودةٌ بمفتاحِ (B,C) لا (A,C)
        $bcKey = DmMessage::threadKey($this->employee->id, $this->viewer->id);
        $this->assertSame(1, DmMessage::where('thread_key', $bcKey)->count());
        $acKey = DmMessage::threadKey($this->owner->id, $this->viewer->id);
        $this->assertSame(0, DmMessage::where('thread_key', $acKey)->count());
    }

    public function test_thread_key_is_server_derived_per_pair_not_client_supplied(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-key-A');
        $B = $this->auth($this->employee, 'inst-dm-key-B');

        // A ⇒ C و B ⇒ C: خيطان مستقلّان لكلِّ ثنائيّ
        $this->withHeaders($A)->postJson('/api/mobile/v1/dm/threads/' . $this->viewer->id . '/send',
            ['body' => 'من أ إلى ج'])->assertOk();
        $this->withHeaders($B)->postJson('/api/mobile/v1/dm/threads/' . $this->viewer->id . '/send',
            ['body' => 'من ب إلى ج'])->assertOk();

        // C يخاطب A ⇒ خيطُ A↔C وحدَه (لا رسالةُ B)
        $C = $this->auth($this->viewer, 'inst-dm-key-C');
        $fromA = collect($this->withHeaders($C)->getJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/messages')
            ->assertOk()->json('data.messages'))->pluck('body')->all();
        $this->assertContains('من أ إلى ج', $fromA);
        $this->assertNotContains('من ب إلى ج', $fromA, 'مخاطبةُ A تُعطي خيطَ A↔C لا B↔C (المفتاحُ خادميّ)');

        // C يخاطب B ⇒ خيطُ B↔C وحدَه
        $fromB = collect($this->withHeaders($C)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/messages')
            ->assertOk()->json('data.messages'))->pluck('body')->all();
        $this->assertContains('من ب إلى ج', $fromB);
        $this->assertNotContains('من أ إلى ج', $fromB);
    }

    // ═══════════════════════ حرّاسُ الإرسال ═══════════════════════

    public function test_send_to_self_is_rejected(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-self-1');
        $this->withHeaders($A)->postJson('/api/mobile/v1/dm/threads/' . $this->owner->id . '/send',
            ['body' => 'إلى نفسي'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_send_to_nonexistent_user_is_404_no_existence_leak(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-missing-1');
        $this->withHeaders($A)->postJson('/api/mobile/v1/dm/threads/' . Str::uuid() . '/send',
            ['body' => 'إلى لا أحد'])
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_reading_a_nonexistent_partner_thread_is_404(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-missing-2');
        $this->withHeaders($A)->getJson('/api/mobile/v1/dm/threads/' . Str::uuid() . '/messages')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    // ═══════════════════════ الإرسالُ idempotent (F1) ═══════════════════════

    public function test_send_is_idempotent_on_the_same_key(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-idem-1');
        $key = ['Idempotency-Key' => 'dm-key-999'];
        $body = ['body' => 'رسالةٌ واحدةٌ لا رسالتان'];

        $first = $this->withHeaders($A + $key)
            ->postJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/send', $body)->assertOk();
        $second = $this->withHeaders($A + $key)
            ->postJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/send', $body)->assertOk();

        $this->assertSame($first->json('data.message.id'), $second->json('data.message.id'),
            'المفتاحُ نفسُه يعيد الردَّ المحفوظ — لا رسالةَ ثانية');
        $key0 = DmMessage::threadKey($this->owner->id, $this->employee->id);
        $this->assertSame(1, DmMessage::where('thread_key', $key0)->count(), 'صفٌّ واحدٌ فقط رغم إعادة المحاولة');
    }

    // ═══════════════════════ الترتيبُ الزمنيُّ الحتميّ ═══════════════════════

    public function test_messages_return_in_chronological_order(): void
    {
        $this->seedCore();
        $A = $this->auth($this->owner, 'inst-dm-order-1');

        foreach (['الأولى', 'الثانية', 'الثالثة'] as $body) {
            $this->withHeaders($A)->postJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/send',
                ['body' => $body])->assertOk();
            $this->travel(1)->minutes();   // طوابعُ متمايزةٌ ⇒ ترتيبٌ حتميّ لا قرعة
        }

        $bodies = collect($this->withHeaders($A)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/messages')
            ->assertOk()->json('data.messages'))->pluck('body')->all();

        $this->assertSame(['الأولى', 'الثانية', 'الثالثة'], $bodies, 'الرسائلُ تصاعديّاً بالزمن');
    }

    public function test_threads_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/dm/threads')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
