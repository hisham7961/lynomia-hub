<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\HubNotification;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\HubEvents;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **أوامرُ المحادثة** (Work OS · الطور C · WP-C.3 · §8).
 *
 * رسالةٌ تبدأ بـ`/task` `/issue` `/assign` تُنفَّذ **خدمةً حقيقيّة** لا نصّاً يُخزَّن:
 * تمتدّ نمطَ `CommentController@toTask` — خدمةٌ حقيقية (`Task`/`Issue`) + `hub_can(op)`
 * على الوحدة الهدف + نطاقُ السياق (`guardConversation`/`guardTarget` مرّ توّاً) +
 * أثرُ تدقيقٍ + حدثُ الوحدةِ القائم + رسالةُ ربطٍ (back-link).
 *
 * والرفضُ **صريح**: مجهولٌ (‏/أمرٌ لا نعرفه) → ٤٢٢، وغيرُ مصرّحٍ (لا `hub_can`) → ٤٠٣،
 * وقناةٌ لا يراها القارئ → ٤٠٤ — لا نجاحٌ زائف، ولا أمرٌ يُبتلع صامتاً، ولا مهمةٌ تُخلق
 * بلا صلاحية. لا محرّكَ أوامرَ ثانٍ: الخدماتُ هي `Task`/`Issue` والربطُ `Comment`.
 */
class WorkOsChatCommandsTest extends TestCase
{
    /** مستخدمٌ داخليٌّ بمصفوفةٍ صريحة (كما في WorkOsChannelsTest، لا استنساخ) */
    private function internal(string $name, array $matrix = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(6), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** قناةٌ بمالكها، ثم إضافةُ عضوٍ بدورٍ بعينه */
    private function channelOwnedBy(User $owner, array $attrs = []): Conversation
    {
        $conv = Conversation::create(array_merge([
            'kind' => 'channel', 'title' => 'قناةُ الأوامر',
            'audience' => 'internal', 'visibility' => 'private',
            'created_by' => $owner->id,
        ], $attrs));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id,
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);

        return $conv;
    }

    private function member(Conversation $conv, User $u, string $role = 'member'): void
    {
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $u->id,
            'role' => $role, 'source' => 'explicit', 'last_read_at' => now()]);
    }

    /** مصفوفةٌ تمنح إضافةَ وحدةٍ بعينها فقط */
    private function canAdd(string $module): array
    {
        return [$module => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]];
    }

    /* ────────── ١) ‏/task يُنشئ مهمةً حقيقيّةً وربطاً، ويُطلق الحدث ────────── */

    public function test_task_command_creates_real_task_with_backlink_and_event(): void
    {
        $this->seedCore();
        $owner = $this->internal('صاحبُ القناة', $this->canAdd('tasks'));
        $conv = $this->channelOwnedBy($owner);

        $captured = [];
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e, string $mod) use (&$captured) { $captured[] = "$e|$mod"; });

        $res = $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/task إصلاحُ تسجيل الدخول',
        ]);
        $res->assertRedirect();

        // مهمةٌ حقيقيّةٌ أُنشئت — لا نصٌّ خُزّن
        $task = Task::where('title', 'إصلاحُ تسجيل الدخول')->first();
        $this->assertNotNull($task, 'الأمرُ /task لم يُنشئ مهمةً حقيقيّة');
        $this->assertSame(0, Comment::where('body', '/task إصلاحُ تسجيل الدخول')->count(),
            'نصُّ الأمرِ خُزّن كتعليقٍ بدل تنفيذِه — نجاحٌ زائف');

        // رسالةُ ربطٍ في القناة تحمل task_id (كنمطِ toTask: back-link مبنيّ)
        $back = Comment::where('conversation_id', $conv->id)->where('task_id', $task->id)->first();
        $this->assertNotNull($back, 'لا رسالةَ ربطٍ (back-link) تشير إلى المهمة');
        $this->assertSame((string) $conv->id, (string) $back->record_id);

        // الحدثُ القائمُ للوحدة الهدف انبثق (نفسُ FlowRunner::fire('created','tasks') في ModuleController)
        $this->assertContains('created|tasks', $captured, 'حدثُ إنشاءِ المهمة لم يُطلَق');
        HubEvents::forgetListeners();
    }

    /* ────────── ٢) غيرُ المصرّح (لا hub_can) → ٤٠٣ ولا مهمة ────────── */

    public function test_unauthorized_member_cannot_run_task_command(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك', $this->canAdd('tasks'));
        // عضوٌ يكتب في القناة لكن لا يملك «إضافة مهام» (a=0) — يقرأ ويحادث لا يأمر
        $poor = $this->internal('عضوٌ بلا صلاحيةِ مهام', ['tasks' => ['v' => 1, 'a' => 0]]);
        $conv = $this->channelOwnedBy($owner);
        $this->member($conv, $poor, 'member');

        $before = Task::count();

        $this->actingAs($poor)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/task مهمةٌ مسروقة',
        ])->assertForbidden();

        $this->assertSame($before, Task::count(), 'مهمةٌ أُنشئت رغم انعدام الصلاحية');
        $this->assertSame(0, Comment::where('body', '/task مهمةٌ مسروقة')->count(),
            'الأمرُ المرفوضُ خُزّن كتعليق');
    }

    /* ────────── ٣) أمرٌ مجهول → رفضٌ صريح (لا صامت، لا نجاحٌ زائف) ────────── */

    public function test_unknown_command_is_rejected_explicitly(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك', $this->canAdd('tasks'));
        $conv = $this->channelOwnedBy($owner);

        $beforeComments = Comment::where('conversation_id', $conv->id)->count();

        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/frobnicate شيءٌ ما',
        ])->assertStatus(422);

        // لم يُخزَّن كتعليق، ولا نجاحٌ صامت
        $this->assertSame($beforeComments, Comment::where('conversation_id', $conv->id)->count(),
            'أمرٌ مجهولٌ مرّ صامتاً أو خُزّن كتعليق');

        // ونصٌّ يبدأ بمسارٍ (لا أمر) يبقى رسالةً عاديّةً — لا يُختطف
        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/etc/passwd هو الملف',
        ])->assertRedirect();
        $this->assertSame(1, Comment::where('body', '/etc/passwd هو الملف')->count(),
            'رسالةٌ تبدأ بمسارٍ عوملت كأمر');
    }

    /* ────────── ٤) ‏/issue يُنشئ مشكلةً حقيقيّةً وربطاً ────────── */

    public function test_issue_command_creates_real_issue(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك', $this->canAdd('issues'));
        $conv = $this->channelOwnedBy($owner);

        $captured = [];
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e, string $mod) use (&$captured) { $captured[] = "$e|$mod"; });

        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/issue تسرّبٌ محتمَلٌ في الطابور',
        ])->assertRedirect();

        $issue = Issue::where('title', 'تسرّبٌ محتمَلٌ في الطابور')->first();
        $this->assertNotNull($issue, 'الأمرُ /issue لم يُنشئ مشكلةً حقيقيّة');
        $this->assertContains('created|issues', $captured, 'حدثُ إنشاءِ المشكلة لم يُطلَق');
        HubEvents::forgetListeners();

        // رسالةُ ربطٍ في القناة تُشير إلى المشكلة (بمعرّفها — لا عمودَ issue_id على comments)
        $back = Comment::where('conversation_id', $conv->id)
            ->where('body', 'like', '%' . $issue->id . '%')->first();
        $this->assertNotNull($back, 'لا رسالةَ ربطٍ تشير إلى المشكلة');

        // غيرُ المصرّحِ بالمشاكل يُرفض — ٤٠٣ ولا مشكلة
        $poor = $this->internal('بلا صلاحيةِ مشاكل', ['issues' => ['v' => 1, 'a' => 0]]);
        $this->member($conv, $poor, 'member');
        $n = Issue::count();
        $this->actingAs($poor)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/issue مشكلةٌ مسروقة',
        ])->assertForbidden();
        $this->assertSame($n, Issue::count(), 'مشكلةٌ أُنشئت رغم انعدام الصلاحية');
    }

    /* ────────── ٥) ‏/assign يُنشئ مهمةً مُسنَدةً ويُشعِر المسند إليه ────────── */

    public function test_assign_command_creates_assigned_task_and_notifies(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك', $this->canAdd('tasks'));
        $target = $this->internal('زيدونُ المسؤول', ['tasks' => ['v' => 1]]);
        $conv = $this->channelOwnedBy($owner);

        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/assign @زيدون إصلاحُ الشبكة',
        ])->assertRedirect();

        $task = Task::where('title', 'إصلاحُ الشبكة')->first();
        $this->assertNotNull($task, 'الأمرُ /assign لم يُنشئ مهمة');
        $this->assertSame((string) $target->id, (string) $task->assignee_id,
            'المهمةُ لم تُسنَد إلى المذكور');

        // إشعارُ الإسناد للمسند إليه (نفسُ سكّة hub_notify)
        $this->assertTrue(HubNotification::where('user_id', $target->id)->where('kind', 'assign')->exists(),
            'المسند إليه لم يُشعَر');

        // رسالةُ ربطٍ تحمل task_id
        $this->assertTrue(Comment::where('conversation_id', $conv->id)->where('task_id', $task->id)->exists(),
            'لا رسالةَ ربطٍ للإسناد');

        // ‏/assign بلا مذكورٍ صالح → رفضٌ صريح (لا مهمةٍ بلا مسند)
        $n = Task::count();
        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/assign مهمةٌ بلا مسند',
        ])->assertStatus(422);
        $this->assertSame($n, Task::count(), 'مهمةُ إسنادٍ أُنشئت بلا مسند');
    }

    /* ────────── ٦) أمرٌ في قناةٍ لا يراها القارئ → ٤٠٤ ولا شيء ────────── */

    public function test_command_in_unseen_channel_is_refused(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك', $this->canAdd('tasks'));
        // غريبٌ يملك «إضافة مهام» لكنّه ليس عضواً في القناة — العضويّةُ فوق الصلاحية
        $stranger = $this->internal('غريبٌ مصرَّح', $this->canAdd('tasks'));
        $conv = $this->channelOwnedBy($owner);

        $before = Task::count();
        $this->actingAs($stranger)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/task مهمةٌ من خارج القناة',
        ])->assertNotFound();

        $this->assertSame($before, Task::count(), 'أمرٌ نُفّذ في قناةٍ لا يراها القارئ');
        $this->assertSame(0, Comment::where('conversation_id', $conv->id)->count());
    }

    /* ────────── ٧) الأمرُ يعمل عند إنشاءِ القناة (conversations.store) ────────── */

    public function test_command_runs_at_channel_creation(): void
    {
        $this->seedCore();
        // المالكُ (is_owner) يملك كلَّ شيء — ينشئ قناةً برسالةِ افتتاحٍ آمرة
        $this->actingAs($this->owner)->post('/conversations', [
            'title' => 'قناةُ الإطلاق',
            'body'  => '/task تجهيزُ الإطلاق',
        ])->assertRedirect();

        $conv = Conversation::where('title', 'قناةُ الإطلاق')->first();
        $this->assertNotNull($conv);
        $task = Task::where('title', 'تجهيزُ الإطلاق')->first();
        $this->assertNotNull($task, 'أمرُ الإنشاء لم يُنشئ مهمة');
        $this->assertTrue(Comment::where('conversation_id', $conv->id)->where('task_id', $task->id)->exists(),
            'لا رسالةَ ربطٍ في القناةِ الجديدة');
    }
}
