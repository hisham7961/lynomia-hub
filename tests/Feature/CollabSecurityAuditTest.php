<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\SavedMessage;
use App\Models\User;
use App\Support\Collaboration;
use App\Support\CommentService;
use App\Support\DmService;
use Tests\TestCase;

/**
 * **مركزُ التواصل — بطاريّةُ التدقيقِ الخصميّ** (المرحلة ١٠ · §106).
 *
 * تدقيقٌ خصميٌّ موحّدٌ لسطحِ التعاونِ كلِّه على السطوحِ الجديدة (المركز · الجلبُ
 * التدريجيّ · الكتابة · المجموعات · المحفوظات) وعلى المسّاتِ الكلاسيكيّة: **لا اكتشافَ
 * غيرِ مصرَّحٍ به، ولا تسريبَ عميلٍ/داخليّ، ولا IDOR، ولا التفافَ بمؤشّرٍ مزوَّر، ولا
 * وصولٍ تاريخيٍّ بعد الإزالة أو توسيعِ الجمهور**. إثباتٌ لا ادّعاء — كلُّ متجهٍ يُهاجَم.
 */
class CollabSecurityAuditTest extends TestCase
{
    private function channel(User $owner, string $title, array $extra = []): Conversation
    {
        $conv = Conversation::create(array_merge(
            ['kind' => 'channel', 'title' => $title, 'created_by' => $owner->id], $extra));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $conv;
    }

    private function member(Conversation $c, User $u, string $role = 'member'): void
    {
        ConversationMember::create(['conversation_id' => $c->id, 'user_id' => $u->id, 'role' => $role]);
    }

    private function clientUser(): User
    {
        $role = Role::create(['name' => 'عميل ' . \Illuminate\Support\Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميلٌ خارجيّ', 'email' => \Illuminate\Support\Str::random(6) . '@ext.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ═══════════ ١) اكتشافٌ غيرُ مصرَّحٍ به: قناة/محادثة/مجموعة ═══════════ */

    public function test_non_member_cannot_discover_or_open_a_private_channel(): void
    {
        $this->seedCore();
        $secret = $this->channel($this->viewer, 'سرّية', ['visibility' => 'members']);

        // لا في المركز، ولا show، ولا since، ولا typing — كلُّها ٤٠٤ لغيرِ العضو
        $this->actingAs($this->employee)->get(route('collab.center'))->assertOk()->assertDontSee('سرّية');
        $this->actingAs($this->employee)->get(route('collab.center', ['c' => $secret->id]))->assertNotFound();
        $this->actingAs($this->employee)->get(route('conversations.show', $secret->id))->assertNotFound();
        $this->actingAs($this->employee)->getJson(route('conversations.since', $secret->id))->assertNotFound();
        $this->actingAs($this->employee)->post(route('conversations.typing', $secret->id))->assertNotFound();
    }

    public function test_a_group_dm_is_not_discoverable_and_refuses_non_members(): void
    {
        $this->seedCore();
        $g = Conversation::create(['kind' => 'group', 'title' => 'مجموعةٌ خاصّة',
            'audience' => 'internal', 'visibility' => 'private', 'created_by' => $this->owner->id]);
        $this->member($g, $this->owner, 'owner');

        // لا تظهر في دليل القنوات (خاصّةٌ غيرُ قابلةٍ للاكتشاف)
        $this->actingAs($this->employee)->get(route('conversations.directory'))->assertOk()->assertDontSee('مجموعةٌ خاصّة');
        // وغيرُ العضو لا يفتحها في المركز (٤٠٤)
        $this->actingAs($this->employee)->get(route('collab.center', ['c' => $g->id]))->assertNotFound();
    }

    /* ═══════════ ٢) العميلُ لا يبلغ الداخليّ ═══════════ */

    public function test_client_cannot_reach_any_internal_collab_surface(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'داخليّة', ['audience' => 'internal']);
        $client = $this->clientUser();

        foreach ([
            route('collab.center'),
            route('collab.center', ['c' => $conv->id]),
            route('conversations.index'),
            route('conversations.directory'),
            route('groups.index'),
            route('saved.index'),
        ] as $url) {
            $this->actingAs($client)->get($url)->assertNotFound();
        }
    }

    /* ═══════════ ٣) IDOR + التفافُ المؤشّر عبر since ═══════════ */

    public function test_cursor_tampering_never_crosses_into_another_conversation(): void
    {
        $this->seedCore();
        $mine = $this->channel($this->owner, 'قناتي');
        $this->member($mine, $this->employee);
        $other = $this->channel($this->viewer, 'قناةُ الغير');
        CommentService::create($this->viewer, 'channel', (string) $other->id, 'سرُّ الغير', ['conversation_id' => (string) $other->id]);

        // مؤشّرٌ مصنوعٌ من صفرٍ لا يفتح قناةً لست عضواً فيها — الحارسُ قبل المؤشّر
        $forged = Collaboration::encodeCursor('1970-01-01 00:00:00', '0');
        $this->actingAs($this->employee)
            ->getJson(route('conversations.since', $other->id) . '?cursor=' . urlencode($forged))
            ->assertNotFound();

        // وحتى في قناتي، مؤشّرٌ مزوَّرٌ لا يُسرّب رسائلَ قناةٍ أخرى (النطاقُ على conversation_id)
        $res = $this->actingAs($this->employee)
            ->getJson(route('conversations.since', $mine->id) . '?cursor=' . urlencode($forged))->assertOk();
        $this->assertStringNotContainsString('سرُّ الغير', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_reacting_to_a_message_in_an_inaccessible_channel_is_refused(): void
    {
        $this->seedCore();
        $other = $this->channel($this->viewer, 'قناةُ الغير');
        $c = CommentService::create($this->viewer, 'channel', (string) $other->id, 'رسالة', ['conversation_id' => (string) $other->id]);

        // IDOR: معرّفُ رسالةٍ في قناةٍ لست عضواً فيها — التفاعلُ يمرّ بـguardTarget (٤٠٤)
        $this->actingAs($this->employee)->post(route('comments.react', $c->id), ['emoji' => '👍'])->assertNotFound();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('reactions')->where('comment_id', $c->id)->count());
    }

    /* ═══════════ ٤) الكتابةُ في المؤرشفة مرفوضة ═══════════ */

    public function test_posting_into_an_archived_channel_is_refused(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'مؤرشفة');
        $this->member($conv, $this->employee);
        $conv->forceFill(['archived_at' => now()])->save();

        // المؤرشفةُ خارجُ الحارس الافتراضيّ (٤٠٤ على النشر عبرها)
        $this->actingAs($this->employee)->post(route('comments.store'), [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'كتابةٌ في مؤرشفة',
        ])->assertNotFound();
        $this->assertSame(0, Comment::where('conversation_id', $conv->id)->count());
    }

    /* ═══════════ ٥) العضوُ المُزال يفقد الوصولَ التاريخيّ ═══════════ */

    public function test_a_removed_member_loses_access_to_history(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        $this->member($conv, $this->employee);
        CommentService::create($this->owner, 'channel', (string) $conv->id, 'تاريخٌ سابق', ['conversation_id' => (string) $conv->id]);

        // كان يقرأ قبل الإزالة
        $this->actingAs($this->employee)->get(route('collab.center', ['c' => $conv->id]))->assertOk()->assertSee('تاريخٌ سابق');

        // تُزال عضويّتُه ⇒ لا يبلغ الخيطَ ولا تاريخَه بعدها (٤٠٤)
        ConversationMember::where('conversation_id', $conv->id)->where('user_id', $this->employee->id)->delete();
        $this->actingAs($this->employee)->get(route('collab.center', ['c' => $conv->id]))->assertNotFound();
        $this->actingAs($this->employee)->getJson(route('conversations.since', $conv->id))->assertNotFound();
    }

    /* ═══════════ ٦) توسيعُ جمهورِ المجموعة لا يكشف التاريخ ═══════════ */

    public function test_forking_a_group_hides_prior_history_from_newcomers(): void
    {
        $this->seedCore();
        $g = Conversation::create(['kind' => 'group', 'title' => 'مجموعة',
            'audience' => 'internal', 'visibility' => 'private', 'created_by' => $this->owner->id]);
        $this->member($g, $this->owner, 'owner');
        $this->member($g, $this->employee);
        CommentService::create($this->owner, 'channel', (string) $g->id, 'سرٌّ قديمٌ قبل الإضافة', ['conversation_id' => (string) $g->id]);

        // إضافةُ المشاهدِ تُنشئ مجموعةً جديدةً بتاريخٍ فارغ — لا يرى القديمَ
        $this->actingAs($this->owner)->post(route('groups.fork', $g->id), ['participants' => [$this->viewer->id]])->assertRedirect();

        $newGroup = Conversation::groups()->where('id', '!=', $g->id)
            ->whereHas('members', fn ($q) => $q->where('user_id', $this->viewer->id))->first();
        $this->assertNotNull($newGroup, 'الإضافةُ لم تُنشئ مجموعةً جديدة');
        $this->assertNotSame((string) $g->id, (string) $newGroup->id);

        // المشاهدُ ليس عضواً في القديمة، ولا يبلغها (٤٠٤)، والجديدةُ بلا تاريخٍ قديم
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $this->viewer->id)->exists());
        $this->actingAs($this->viewer)->get(route('collab.center', ['c' => $g->id]))->assertNotFound();
        $this->assertSame(0, Comment::where('conversation_id', $newGroup->id)->count(), 'المجموعةُ الجديدةُ ورثت تاريخاً');
    }

    /* ═══════════ ٧) المحفوظةُ تُعادُ تخويلاً — لا تسريبَ بعد فقدِ الوصول ═══════════ */

    public function test_a_saved_message_re_authorizes_on_open(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');
        $this->member($conv, $this->employee);
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id, 'رسالةٌ محفوظةٌ سرّية', ['conversation_id' => (string) $conv->id]);

        // الموظّفةُ تحفظها (عضوٌ الآن)
        SavedMessage::create(['user_id' => $this->employee->id, 'target_type' => 'comment', 'target_id' => $c->id]);
        $this->actingAs($this->employee)->get(route('saved.index'))->assertOk()->assertSee('رسالةٌ محفوظةٌ سرّية');

        // تُزال عضويّتُها ⇒ المحفوظةُ تبقى صفّاً لكن جسمَها لا يُكشَف (أُعيد تخويلُها ففشل)
        ConversationMember::where('conversation_id', $conv->id)->where('user_id', $this->employee->id)->delete();
        $this->actingAs($this->employee)->get(route('saved.index'))->assertOk()->assertDontSee('رسالةٌ محفوظةٌ سرّية');
    }

    /* ═══════════ ٨) الإشارةُ (mention) لا تمنح وصولاً ═══════════ */

    public function test_mentioning_a_non_member_does_not_grant_channel_access(): void
    {
        $this->seedCore();
        $conv = $this->channel($this->owner, 'قناة');

        // المالكُ يشير للمشاهدِ (ليس عضواً) — الإشارةُ لا تُدخِله القناة
        CommentService::create($this->owner, 'channel', (string) $conv->id, 'مرحباً @مشاهد', [
            'conversation_id' => (string) $conv->id, 'mention' => [(string) $this->viewer->id],
        ]);

        $this->assertFalse(ConversationMember::where('conversation_id', $conv->id)->where('user_id', $this->viewer->id)->exists(),
            'الإشارةُ أدخلت غيرَ العضوِ القناةَ');
        $this->actingAs($this->viewer)->get(route('collab.center', ['c' => $conv->id]))->assertNotFound();
    }

    /* ═══════════ ٩) البحثُ لا يُرجِع رسائلَ قناةٍ لست فيها ═══════════ */

    public function test_message_search_never_returns_messages_from_inaccessible_channels(): void
    {
        $this->seedCore();
        $other = $this->channel($this->viewer, 'قناةُ الغير');
        CommentService::create($this->viewer, 'channel', (string) $other->id, 'كلمةٌ سحريّةٌ سرّية', ['conversation_id' => (string) $other->id]);

        // الموظّفةُ تبحث عن الكلمةِ — لا تبلغها (ليست عضواً في تلك القناة)
        $this->actingAs($this->employee)->get(route('search.messages', ['q' => 'سحريّةٌ سرّية']))
            ->assertOk()->assertDontSee('كلمةٌ سحريّةٌ سرّية');
    }

    /* ═══════════ ١٠) خصوصيّةُ الطرفين في DM — لا A تُعدِّد B↔C ═══════════ */

    public function test_dm_since_and_typing_reject_self_and_the_client_cannot_reach_dm_routes(): void
    {
        $this->seedCore();

        // خيطٌ مع النفس ٤٠٤
        $this->actingAs($this->owner)->getJson(route('dm.since', $this->owner->id))->assertNotFound();
        $this->actingAs($this->owner)->post(route('dm.typing', $this->owner->id))->assertNotFound();

        // العزلُ الحقيقيّ: حاويةُ DM داخليّةٌ دائماً، والعميلُ محجوبٌ عن مسارات dm/*
        // بحاجزِ البوابة (PortalGuard) — فلا يبلغ خيطاً ولو أُقحم معرّفُ طرفٍ داخليّ.
        $client = $this->clientUser();
        $this->actingAs($client)->getJson(route('dm.since', $this->owner->id))->assertNotFound();
        $this->actingAs($client)->post(route('dm.typing', $this->owner->id))->assertNotFound();
        $this->actingAs($client)->get(route('dm.inbox'))->assertNotFound();
    }
}
