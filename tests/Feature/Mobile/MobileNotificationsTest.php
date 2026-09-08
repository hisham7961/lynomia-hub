<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **E.1/E.2 · إشعاراتُ الجوال (هويّةٌ خاصّةٌ صارمة)** — Mobile Readiness · الطور E.
 *
 * يُثبِت العقدَ كما يراه العميلُ الأصيل عبر HTTP حيّاً على `/api/mobile/v1/notifications`:
 *  • كلُّ استعلامٍ منطَّقٌ بـ`user_id = auth()->id()` وحدَه — لا يبلغ المُنادي إشعارَ
 *    أحدٍ سواه أبداً (قائمةً أو عدّاً أو تعليمَ قراءةٍ أو وجهةً). إشعارُ غيري ⇒ ٤٠٤.
 *  • الترقيمُ بمؤشّرٍ حتميٍّ على `(created_at DESC, id DESC)` يمشي على **كلّ** الصفوف
 *    (نظيرُ حذرِ CLAUDE.md من القرعة — لا صفَّ واحداً يُخفي البقيّة).
 *  • الوجهةُ القانونيّة `{module,id,action}` (E.2) من `NotificationLink::target` —
 *    لا رابطَ ويبٍ صلبٌ.
 */
class MobileNotificationsTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** رؤوسُ حاملِ رمزٍ حيٍّ لمستخدم */
    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** إشعارٌ مباشرٌ لمستخدم (يتجاوز طبقةَ HTTP — تهيئةً) */
    private function notify(string $userId, string $kind = 'assign', string $text = 'نصّ',
                           ?string $module = null, ?string $recordId = null, $createdAt = null): HubNotification
    {
        return HubNotification::create([
            'user_id' => $userId, 'kind' => $kind, 'text' => $text,
            'module' => $module, 'record_id' => $recordId, 'read' => false,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    // ═══════════════════════ هويّةٌ خاصّةٌ صارمة (لا IDOR) ═══════════════════════

    public function test_list_returns_only_my_own_notifications_never_another_users(): void
    {
        $this->seedCore();
        $mine = $this->notify($this->owner->id, 'assign', 'إشعاري');
        $hers = $this->notify($this->viewer->id, 'assign', 'إشعارُ غيري');

        $h = $this->auth($this->owner, 'inst-notif-own-11');
        $data = $this->withHeaders($h)->getJson('/api/mobile/v1/notifications')->assertOk()->json('data');

        $ids = collect($data['notifications'])->pluck('id')->all();
        $this->assertContains((string) $mine->id, $ids, 'إشعاري ظاهرٌ لي');
        $this->assertNotContains((string) $hers->id, $ids, 'إشعارُ غيري لا يتسرّب (هويّةٌ خاصّة)');
    }

    public function test_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/notifications')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_unread_flag_filters_to_unread_only(): void
    {
        $this->seedCore();
        $u = $this->notify($this->owner->id, 'assign', 'غيرُ مقروء');
        $read = $this->notify($this->owner->id, 'assign', 'مقروء');
        $read->forceFill(['read' => true])->save();

        $h = $this->auth($this->owner, 'inst-notif-unread-1');
        $ids = collect($this->withHeaders($h)->getJson('/api/mobile/v1/notifications?unread=1')
            ->assertOk()->json('data.notifications'))->pluck('id')->all();

        $this->assertContains((string) $u->id, $ids);
        $this->assertNotContains((string) $read->id, $ids, '`unread=1` يقصر على غير المقروء');
    }

    // ═══════════════════════ الترقيمُ بمؤشّرٍ حتميّ (يمشي على الكلّ) ═══════════════════════

    public function test_cursor_pagination_walks_every_row_in_deterministic_order(): void
    {
        $this->seedCore();
        // خمسةُ إشعاراتٍ بطوابعَ متمايزة: A أحدثُها ⇒ E أقدمُها (ترتيبٌ DESC حتميّ)
        $expected = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $i => $label) {
            $expected[] = (string) $this->notify($this->owner->id, 'assign', $label,
                null, null, now()->subMinutes($i))->id;
        }

        $h = $this->auth($this->owner, 'inst-notif-cursor-1');

        $collected = [];
        $cursor = '';
        $guard = 0;
        do {
            $url = '/api/mobile/v1/notifications?per=2' . ($cursor !== '' ? '&cursor=' . urlencode($cursor) : '');
            $page = $this->withHeaders($h)->getJson($url)->assertOk()->json('data');
            foreach ($page['notifications'] as $n) $collected[] = $n['id'];
            $this->assertLessThanOrEqual(2, count($page['notifications']), 'الصفحةُ لا تتجاوز per');
            $cursor = $page['cursor']['next'] ?? '';
            $this->assertLessThan(10, ++$guard, 'حلقةُ مؤشّرٍ لا تنتهي — عيبُ ترقيم');
        } while ($cursor);

        // مُرّ على **كلّ** الصفوف بالترتيب الحتميّ (لا صفَّ مكرّرٌ ولا مُسقَط · CLAUDE.md)
        $this->assertSame($expected, $collected, 'المؤشّرُ يمشي على كلِّ الصفوف بترتيبِ (created_at,id) DESC');
    }

    // ═══════════════════════ العدُّ + تعليمُ القراءة (هويّتي وحدي) ═══════════════════════

    public function test_unread_count_is_my_own_identity_only(): void
    {
        $this->seedCore();
        $this->notify($this->owner->id);
        $this->notify($this->owner->id);
        $this->notify($this->viewer->id);   // إشعارُ غيري لا يُحتسب لي

        $h = $this->auth($this->owner, 'inst-notif-count-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread', 2);
    }

    public function test_mark_read_flips_one_notification_and_decrements_unread(): void
    {
        $this->seedCore();
        $a = $this->notify($this->owner->id);
        $this->notify($this->owner->id);

        $h = $this->auth($this->owner, 'inst-notif-read-1');
        $this->withHeaders($h)->postJson('/api/mobile/v1/notifications/' . $a->id . '/read')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $a->id)
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.unread', 1);

        $this->assertTrue((bool) $a->fresh()->read, 'الإشعارُ صار مقروءاً في القاعدة');
    }

    public function test_mark_all_read_marks_only_my_unread(): void
    {
        $this->seedCore();
        $this->notify($this->owner->id);
        $this->notify($this->owner->id);
        $hers = $this->notify($this->viewer->id);   // لا يمسّها تعليمُ الكلّ

        $h = $this->auth($this->owner, 'inst-notif-all-1');
        $this->withHeaders($h)->postJson('/api/mobile/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2)
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, HubNotification::where('user_id', $this->owner->id)->where('read', false)->count());
        $this->assertFalse((bool) $hers->fresh()->read, 'تعليمُ الكلّ لا يمسّ إشعارَ غيري');
    }

    // ═══════════════════════ الوجهةُ القانونيّة (E.2) ═══════════════════════

    public function test_target_returns_canonical_module_id_action_for_a_module_notification(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        $n = $this->notify($this->owner->id, 'assign', 'أُسندت إليك', 'clients', $rid);

        $h = $this->auth($this->owner, 'inst-notif-target-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/notifications/' . $n->id . '/target')
            ->assertOk()
            ->assertJsonPath('data.target.module', 'clients')
            ->assertJsonPath('data.target.id', $rid)
            ->assertJsonPath('data.target.action', 'show')
            ->assertJsonPath('data.module', 'clients')
            ->assertJsonPath('data.record_id', $rid);
    }

    public function test_target_is_null_for_a_notification_without_a_record(): void
    {
        $this->seedCore();
        $n = $this->notify($this->owner->id, 'sec', 'تنبيهٌ أمنيّ عامّ');   // بلا module/record

        $h = $this->auth($this->owner, 'inst-notif-target-2');
        $this->withHeaders($h)->getJson('/api/mobile/v1/notifications/' . $n->id . '/target')
            ->assertOk()->assertJsonPath('data.target', null);
    }

    public function test_list_item_carries_its_deep_link_target(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        $this->notify($this->owner->id, 'assign', 'مهمّة', 'tasks', $rid);

        $h = $this->auth($this->owner, 'inst-notif-listtgt-1');
        $item = collect($this->withHeaders($h)->getJson('/api/mobile/v1/notifications')
            ->assertOk()->json('data.notifications'))->firstWhere('record_id', $rid);

        $this->assertNotNull($item);
        $this->assertSame('tasks', $item['target']['module']);
        $this->assertSame($rid, $item['target']['id']);
        $this->assertSame('show', $item['target']['action']);
    }

    // ═══════════════════════ إشعارُ غيري ⇒ ٤٠٤ (لا IDOR) ═══════════════════════

    public function test_marking_another_users_notification_read_is_404(): void
    {
        $this->seedCore();
        $hers = $this->notify($this->viewer->id, 'assign', 'سرّي');

        $h = $this->auth($this->owner, 'inst-notif-idor-1');
        $this->withHeaders($h)->postJson('/api/mobile/v1/notifications/' . $hers->id . '/read')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->assertFalse((bool) $hers->fresh()->read, 'إشعارُ غيري لم يُمَسّ');
    }

    public function test_reading_another_users_notification_target_is_404(): void
    {
        $this->seedCore();
        $hers = $this->notify($this->viewer->id, 'assign', 'سرّي', 'clients', (string) Str::uuid());

        $h = $this->auth($this->owner, 'inst-notif-idor-2');
        $this->withHeaders($h)->getJson('/api/mobile/v1/notifications/' . $hers->id . '/target')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_unknown_notification_id_is_404(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-notif-missing-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/notifications/' . Str::uuid() . '/target')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }
}
