<?php

namespace Tests\Feature;

use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Support\DmService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣·د · §21) — تفاعلاتُ الرسائلِ المباشرة.**
 *
 * على **جدول `reactions` نفسِه** لا جدولَ ثانٍ (`dm_message_id` بدل `comment_id`):
 * إيموجي واحدٌ لكل مستخدمٍ لكل رمز، الضغطُ ثانيةً يزيله، لطرفَي المحادثة وحدهما،
 * ولا تفاعلَ على محذوفة. إثباتٌ لا ادّعاء.
 */
class CollabDmReactionTest extends TestCase
{
    private function dmReactionCount(string $msgId, string $emoji): int
    {
        return DB::table('reactions')->where('dm_message_id', $msgId)->where('emoji', $emoji)->count();
    }

    public function test_party_can_toggle_a_reaction_and_author_is_notified(): void
    {
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'رسالةٌ للتفاعل');

        // المستلمُ يتفاعل — يُضاف الصفُّ، ويُشعَر صاحبُ الرسالة (المالك)
        $this->actingAs($this->employee)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '👍'])
            ->assertRedirect();
        $this->assertSame(1, $this->dmReactionCount($m->id, '👍'));
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)->where('kind', 'react')->exists(),
            'صاحبُ الرسالة لم يُشعَر بالتفاعل');

        // الضغطُ ثانيةً بالرمز نفسِه يزيله (تبديل)
        $this->actingAs($this->employee)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '👍']);
        $this->assertSame(0, $this->dmReactionCount($m->id, '👍'));
    }

    public function test_non_party_cannot_react(): void
    {
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'خاصّة بيننا');

        // المشاهدُ ليس طرفاً — ٤٠٣، ولا صفَّ تفاعل
        $this->actingAs($this->viewer)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '👍'])
            ->assertForbidden();
        $this->assertSame(0, $this->dmReactionCount($m->id, '👍'));
    }

    public function test_unknown_emoji_and_deleted_message_are_refused(): void
    {
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'رسالة');

        // إيموجي خارج القائمة — ٤٢٢
        $this->actingAs($this->owner)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '💀'])
            ->assertStatus(422);

        // المحذوفةُ لا تُتفاعَل معها — ٤٢٢
        $m->forceFill(['deleted_at' => now()])->save();
        $this->actingAs($this->owner)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '👍'])
            ->assertStatus(422);
        $this->assertSame(0, $this->dmReactionCount($m->id, '👍'));
    }

    public function test_comment_reactions_still_work_alongside_dm_reactions(): void
    {
        // القيدان الفريدان مستقلّان: صفُّ DM (comment_id فارغ) لا يصطدم بصفِّ تعليق
        $this->seedCore();
        $m = DmService::send($this->owner, $this->employee, 'رسالة');
        $c = \App\Support\CommentService::create($this->owner, 'feed', null, 'منشور');

        // تفاعلٌ على DM وتفاعلٌ على تعليقٍ بالرمز نفسِه لنفس المستخدم — كلاهما يُقبل
        $this->actingAs($this->employee)->post('/dm/msg/' . $m->id . '/react', ['emoji' => '❤️']);
        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/react', ['emoji' => '❤️']);

        $this->assertSame(1, $this->dmReactionCount($m->id, '❤️'));
        $this->assertSame(1, DB::table('reactions')->where('comment_id', $c->id)->where('emoji', '❤️')->count());
    }
}
