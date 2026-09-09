<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\CommentService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣ · §16/§18) — نطاقُ الإشارة (@mention) أمنيّ.**
 *
 * الإشارةُ ليست زينةً: هي **إشعارٌ يحمل مقتطفاً من نصّ الرسالة** إلى المُشار إليه.
 * فحلُّها على «كلِّ مستخدمي المنظّمة» كان تسريباً: يكفي أن يبدأ اسمُ غريبٍ بما
 * كُتب بعد `@` كي يصله سطرٌ من محادثةٍ لا يراها — قناةٌ ليس عضواً فيها، أو سجلٌّ
 * خارجَ صلاحيته. إثباتٌ لا ادّعاء: هذا الاختبارُ يفشل قبل الإصلاح ويخضرّ بعده.
 *
 * القاعدة: لا تُحلّ الإشارةُ إلّا لمن **يقدر فتحَ الهدف** — عضوُ القناة، أو من
 * يملك رؤيةَ السجلِّ ضمن نطاقه. غيرُ ذلك يُسقَط بلا إشعار.
 */
class CollabMentionScopeTest extends TestCase
{
    /* ───────── §16 قناة: الإشارةُ لا تبلغ غيرَ العضو أبداً ───────── */

    public function test_channel_mention_never_reaches_a_non_member(): void
    {
        $this->seedCore();

        // قناةٌ عضواها المالكُ والموظفةُ — والمشاهدُ خارجُها (ليس عضواً)
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'سرّية', 'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        // المالكُ يكتب مُشيراً إلى عضوٍ (موظفة) وإلى غيرِ عضوٍ (مشاهد)
        $c = CommentService::create($this->owner, 'channel', (string) $conv->id,
            'خطّةٌ داخليّة @موظفة راجعيها، و @مشاهد لا شأن له', ['conversation_id' => (string) $conv->id]);

        $mentions = (array) $c->fresh()->mentions;

        // العضوُ مُشارٌ إليه ومُشعَرٌ …
        $this->assertContains($this->employee->id, $mentions, 'إشارةُ عضوِ القناةِ لم تُحلّ');
        $this->assertTrue(HubNotification::where('user_id', $this->employee->id)
            ->where('kind', 'mention')->exists(), 'العضوُ لم يُشعَر بالإشارة');

        // … وغيرُ العضوِ لا يُشارُ إليه ولا يصله مقتطفُ القناة (التسريبُ المُصلَح)
        $this->assertNotContains($this->viewer->id, $mentions, 'أُشير إلى غيرِ عضوِ القناة — تسريب');
        $this->assertFalse(HubNotification::where('user_id', $this->viewer->id)
            ->where('kind', 'mention')->exists(), 'وصل مقتطفُ القناةِ إلى غيرِ عضو — تسريب');
    }

    /* ───────── §18 سجلّ: الإشارةُ تُسقَط لمن لا يرى السجلّ ───────── */

    public function test_record_mention_dropped_when_recipient_cannot_view_the_module(): void
    {
        $this->seedCore();

        // مستخدمٌ لا يملك رؤيةَ «المهام» أصلاً (مصفوفةٌ فيها tasks.v=0)
        $modules = array_keys(config('hub.modules'));
        $blindMatrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $blindMatrix['tasks'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $blindRole = Role::create(['name' => 'بلا مهام', 'scope' => 'all', 'flags' => [], 'matrix' => $blindMatrix]);
        $blind = User::create(['name' => 'أعمى', 'email' => 'blind@test.local',
            'password' => 'Secret!2026x', 'role_id' => $blindRole->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $task = Task::create(['title' => 'مهمّةٌ سرّية', 'status' => 'جديدة']);

        // المالكُ يعلّق على المهمّة مُشيراً إلى من لا يراها
        $c = CommentService::create($this->owner, 'tasks', (string) $task->id,
            'تنبيه @أعمى انظر هذا', []);

        $mentions = (array) $c->fresh()->mentions;
        $this->assertNotContains($blind->id, $mentions, 'أُشير إلى من لا يرى السجلّ — تسريب');
        $this->assertFalse(HubNotification::where('user_id', $blind->id)
            ->where('kind', 'mention')->exists(), 'وصل مقتطفُ السجلِّ إلى من لا يراه — تسريب');
    }

    /* ───────── الإشارةُ المشروعةُ على سجلٍّ ما زالت تعمل ───────── */

    public function test_record_mention_still_resolves_for_an_authorized_recipient(): void
    {
        $this->seedCore();
        $task = Task::create(['title' => 'مهمّةٌ عادية', 'status' => 'جديدة']);

        // الموظفةُ ترى المهامَ (seedCore يمنحها v=1) — فإشارتُها تُحلّ وتُشعَر
        $c = CommentService::create($this->owner, 'tasks', (string) $task->id,
            'للمتابعة @موظفة شكراً', []);

        $this->assertContains($this->employee->id, (array) $c->fresh()->mentions);
        $this->assertTrue(HubNotification::where('user_id', $this->employee->id)
            ->where('kind', 'mention')->exists());
    }
}
