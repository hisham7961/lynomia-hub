<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\CommentService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٦ · §35) — مجموعاتُ الرسائل + أمنُ الجمهورِ التاريخيّ.**
 *
 * محادثاتٌ جماعيّةٌ داخليّةٌ خاصّةٌ فوق حاويةِ المحادثةِ نفسِها (`kind=group`) لا محرّكَ
 * ثانٍ. الحرجُ: تغييرُ المشاركين ماديّاً **لا يطفر** العضويّةَ فيرى الجديدُ ما مضى —
 * بل يُنشئ مجموعةً جديدة. ولا تُكتشَف، ولا يبلغها عميل. إثباتٌ لا ادّعاء.
 */
class CollabGroupDmTest extends TestCase
{
    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => 'cl@test.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function group(array $memberIds, ?User $creator = null): Conversation
    {
        $creator = $creator ?? $this->owner;
        $conv = Conversation::create(['kind' => 'group', 'audience' => 'internal', 'visibility' => 'private',
            'created_by' => $creator->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $creator->id, 'role' => 'owner', 'last_read_at' => now()]);
        foreach ($memberIds as $uid) {
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => 'member']);
        }

        return $conv;
    }

    /* ───────── الإنشاء: خاصّةٌ داخليّةٌ غيرُ قابلةٍ للاكتشاف ───────── */

    public function test_create_group_is_private_internal_and_not_discoverable(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [$this->employee->id], 'title' => 'فريقُ الإطلاق',
        ])->assertRedirect();

        $g = Conversation::groups()->first();
        $this->assertNotNull($g);
        $this->assertSame('internal', $g->audience);
        $this->assertSame('private', $g->visibility);
        $this->assertSame(2, ConversationMember::where('conversation_id', $g->id)->count());

        // لا تظهر في دليل القنوات (channels() = kind=channel) لأيّ أحد
        $this->actingAs($this->viewer)->get(route('conversations.directory'))->assertOk()->assertDontSee('فريقُ الإطلاق');
    }

    public function test_non_member_cannot_open_or_poll_a_group(): void
    {
        $this->seedCore();
        $g = $this->group([$this->employee->id]);   // owner + employee؛ المشاهدُ خارجها

        $this->actingAs($this->viewer)->get(route('conversations.show', $g->id))->assertNotFound();
        $this->actingAs($this->viewer)->getJson(route('conversations.since', $g->id))->assertNotFound();
    }

    /* ───────── أمنُ العميل: لا يُنشئ ولا يُضاف ───────── */

    public function test_client_cannot_create_group_and_cannot_be_a_participant(): void
    {
        $this->seedCore();
        $client = $this->client();

        // العميلُ لا يبلغ مسارَ المجموعات أصلاً (PortalGuard قائمةٌ بيضاء → ٤٠٤)،
        // وحارسُ hub_is_client في المتحكّم دفاعٌ في العمق — وكلاهما يمنع الإنشاء
        $this->actingAs($client)->post(route('groups.store'), ['participants' => [$this->employee->id]])
            ->assertNotFound();
        $this->assertSame(0, Conversation::groups()->count());

        // ولا يُضاف عميلٌ مشاركاً في مجموعةٍ داخليّة (يرفضه المتحكّم · ٤٢٢)
        $this->actingAs($this->owner)->post(route('groups.store'), ['participants' => [$client->id]])
            ->assertStatus(422);
        $this->assertSame(0, ConversationMember::where('user_id', $client->id)->count());
    }

    /* ───────── الحرج: الإضافةُ تُنشئ مجموعةً جديدةً حفظاً للجمهور التاريخيّ ───────── */

    public function test_adding_a_participant_forks_and_hides_history_from_the_newcomer(): void
    {
        $this->seedCore();
        $g1 = $this->group([$this->employee->id]);   // [owner, employee]
        CommentService::create($this->owner, 'channel', (string) $g1->id, 'سرٌّ قديمٌ للمجموعة الأولى', ['conversation_id' => (string) $g1->id]);

        // المالكُ «يضيف» المشاهدَ — فتُنشأ مجموعةٌ ثانية، والأولى محفوظة
        $this->actingAs($this->owner)->post(route('groups.fork', $g1->id), ['participants' => [$this->viewer->id]])
            ->assertRedirect();

        $g2 = Conversation::groups()->where('id', '!=', $g1->id)->first();
        $this->assertNotNull($g2, 'الإضافةُ لم تُنشئ مجموعةً جديدة');

        // المشاهدُ عضوٌ في الثانية لا الأولى
        $this->assertTrue(ConversationMember::where('conversation_id', $g2->id)->where('user_id', $this->viewer->id)->exists());
        $this->assertFalse(ConversationMember::where('conversation_id', $g1->id)->where('user_id', $this->viewer->id)->exists());

        // والثانيةُ بلا تاريخ — «سرُّ» الأولى لا يبلغه المشاهد
        $this->assertSame(0, \App\Models\Comment::where('conversation_id', $g2->id)->count());
        $this->actingAs($this->viewer)->get(route('conversations.show', $g1->id))->assertNotFound();
        $this->actingAs($this->viewer)->get(route('conversations.show', $g2->id))->assertOk();
    }

    public function test_direct_add_member_to_a_group_is_refused(): void
    {
        $this->seedCore();
        $g = $this->group([$this->employee->id]);

        // بابُ إضافةِ عضوِ القناة ممنوعٌ على المجموعة (يفرض مسارَ الـfork)
        $this->actingAs($this->owner)->post(route('conversations.member.add', $g->id), [
            'user_id' => $this->viewer->id, 'role' => 'member',
        ])->assertStatus(422);
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $this->viewer->id)->exists());
    }

    public function test_leaving_removes_membership_and_last_leaver_archives(): void
    {
        $this->seedCore();
        $g = $this->group([$this->employee->id]);

        $this->actingAs($this->employee)->post(route('groups.leave', $g->id))->assertRedirect();
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $this->employee->id)->exists());

        $this->actingAs($this->owner)->post(route('groups.leave', $g->id));
        $this->assertNotNull($g->fresh()->archived_at, 'المجموعةُ بلا أعضاءٍ لم تُطوَ');
    }
}
