<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٤ · §13/§14/§15) — إدارةُ القنوات.**
 *
 * المفضّلةُ الشخصيّة (كلُّ عضوٍ ينجّم)، والأرشفةُ (لمالكها)، ودليلُ القنوات المفتوحةِ
 * مع الانضمامِ الذاتيّ — والظهورُ (public/company) مُفعَّلٌ للاكتشاف، والخاصّةُ بالدعوة.
 * العزلُ خادميّ: لا تُكتشَف ولا تُنضَمّ قناةٌ خاصّة. إثباتٌ لا ادّعاء.
 */
class CollabChannelMgmtTest extends TestCase
{
    private function channel(string $vis, array $members, ?string $title = null): Conversation
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => $title ?? ('قناة-' . $vis),
            'visibility' => $vis, 'audience' => 'internal', 'created_by' => $this->owner->id]);
        foreach ($members as $uid => $role) {
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => $role]);
        }

        return $conv;
    }

    /* ───────── §15 المفضّلة ───────── */

    public function test_member_toggles_favorite_and_non_member_cannot(): void
    {
        $this->seedCore();
        $conv = $this->channel('private', [$this->owner->id => 'owner', $this->employee->id => 'member']);

        $this->actingAs($this->employee)->post(route('conversations.favorite', $conv->id))->assertRedirect();
        $m = ConversationMember::where('conversation_id', $conv->id)->where('user_id', $this->employee->id)->first();
        $this->assertNotNull($m->favorite_at);
        $this->assertTrue($m->isFavorite());

        // تبديلٌ يُزيل
        $this->actingAs($this->employee)->post(route('conversations.favorite', $conv->id));
        $this->assertNull($m->fresh()->favorite_at);

        // غيرُ العضو (مشاهد) — ٤٠٤
        $this->actingAs($this->viewer)->post(route('conversations.favorite', $conv->id))->assertNotFound();
    }

    /* ───────── §14 الأرشفة ───────── */

    public function test_owner_archives_and_unarchives_but_member_cannot(): void
    {
        $this->seedCore();
        $conv = $this->channel('private', [$this->owner->id => 'owner', $this->employee->id => 'member']);

        // عضوٌ عاديّ لا يؤرشف — ٤٠٣
        $this->actingAs($this->employee)->post(route('conversations.archive', $conv->id))->assertForbidden();
        $this->assertNull($conv->fresh()->archived_at);

        // المالكُ يؤرشف
        $this->actingAs($this->owner)->post(route('conversations.archive', $conv->id))->assertRedirect();
        $this->assertNotNull($conv->fresh()->archived_at);

        // المؤرشفةُ لا تُفتَح (الحارسُ يستثنيها) — ٤٠٤
        $this->actingAs($this->owner)->get(route('conversations.show', $conv->id))->assertNotFound();

        // المالكُ يعيدها نشطة
        $this->actingAs($this->owner)->post(route('conversations.archive', $conv->id));
        $this->assertNull($conv->fresh()->archived_at);
        $this->actingAs($this->owner)->get(route('conversations.show', $conv->id))->assertOk();
    }

    /* ───────── §13 الدليلُ والانضمام ───────── */

    public function test_directory_lists_discoverable_channels_only(): void
    {
        $this->seedCore();
        $pub = $this->channel('public', [$this->owner->id => 'owner'], 'قناةٌ عامّةٌ مكتشَفة');
        $comp = $this->channel('company', [$this->owner->id => 'owner'], 'قناةُ الشركةِ المكتشَفة');
        $priv = $this->channel('private', [$this->owner->id => 'owner'], 'قناةٌ خاصّةٌ مخفيّة');
        $mine = $this->channel('public', [$this->owner->id => 'owner', $this->viewer->id => 'member'], 'قناتي العامّة');

        $res = $this->actingAs($this->viewer)->get(route('conversations.directory'))->assertOk();
        $res->assertSee($pub->title);        // عامّة → تظهر
        $res->assertSee($comp->title);       // الشركة → تظهر
        $res->assertDontSee($priv->title);   // خاصّة → لا تظهر (بالدعوة)
        $res->assertDontSee($mine->title);   // عضوٌ فيها أصلاً → لا تظهر في الدليل
    }

    public function test_self_join_works_for_public_but_not_for_private(): void
    {
        $this->seedCore();
        $pub = $this->channel('public', [$this->owner->id => 'owner']);
        $priv = $this->channel('private', [$this->owner->id => 'owner']);

        // انضمامٌ ذاتيٌّ لقناةٍ عامّة — يصير عضواً ويصله عرضُها
        $this->actingAs($this->viewer)->post(route('conversations.join', $pub->id))
            ->assertRedirect(route('conversations.show', $pub->id));
        $this->assertNotNull(ConversationMember::where('conversation_id', $pub->id)
            ->where('user_id', $this->viewer->id)->first());
        $this->actingAs($this->viewer)->get(route('conversations.show', $pub->id))->assertOk();

        // انضمامٌ ذاتيٌّ لقناةٍ خاصّة — ٤٠٤ (لا تُكتشَف ولا تُنضَمّ)، ولا عضويّة
        $this->actingAs($this->viewer)->post(route('conversations.join', $priv->id))->assertNotFound();
        $this->assertNull(ConversationMember::where('conversation_id', $priv->id)
            ->where('user_id', $this->viewer->id)->first());
    }
}
