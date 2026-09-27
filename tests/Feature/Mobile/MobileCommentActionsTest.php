<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\Collaboration\CommentService;
use Tests\TestCase;

/**
 * **أفعالُ التعليق على الجوال** (خطّة التطبيق 4.3) — `CommentActions` التي يستدعيها
 * `CommentController` الويبيّ حرفاً. لكلِّ فعلٍ رفضُه: غيرُ الصاحب ٤٠٣، الهدفُ خارجَ
 * النطاق ٤٠٤، من لا يملك تعديلَ الوحدة لا يثبّت، `tasks:a` للتحويل ومرّةً واحدة، وحسابُ
 * العميل ٤٠٤ (داخليّةٌ كالويب).
 */
class MobileCommentActionsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    private function note(User $u, Client $c, string $body): Comment
    {
        return CommentService::create($u, 'clients', (string) $c->id, $body);
    }

    public function test_edit_is_author_only_and_refused_after_conversion(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $mine = $this->note($this->employee, $c, 'مسودةٌ أولى');
        $url = '/api/mobile/v1/comments/' . $mine->id;

        $this->withHeaders($this->h($this->owner))->patchJson($url, ['body' => 'تحريفُ المالك'])->assertForbidden();
        $E = $this->h($this->employee);
        $this->withHeaders($E)->patchJson($url, ['body' => ''])->assertStatus(422);
        $this->withHeaders($E)->patchJson($url, ['body' => 'نصٌّ مُصحَّح'])->assertOk()
            ->assertJsonPath('data.comment.body', 'نصٌّ مُصحَّح')->assertJsonPath('data.comment.edited', true);
        $this->assertSame('نصٌّ مُصحَّح', (string) $mine->fresh()->body);

        $mine->forceFill(['task_id' => (string) Task::create(['title' => 'مهمة'])->id])->save();
        $this->withHeaders($E)->patchJson($url, ['body' => 'بعد التحويل'])->assertStatus(422);
        $this->withHeaders($E)->patchJson('/api/mobile/v1/comments/00000000-0000-0000-0000-000000000000', ['body' => 'x'])
            ->assertNotFound();
    }

    public function test_pin_and_resolve_follow_module_edit_and_authorship(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $viewerNote = $this->note($this->viewer, $c, 'ملاحظةُ المشاهد');
        $V = $this->h($this->viewer);

        // المشاهدُ (عرضٌ فقط) لا يثبّت — لكنّه يحلّ تعليقَه هو
        $this->withHeaders($V)->postJson('/api/mobile/v1/comments/' . $viewerNote->id . '/pin')->assertForbidden();
        $this->withHeaders($V)->postJson('/api/mobile/v1/comments/' . $viewerNote->id . '/resolve')->assertOk()
            ->assertJsonPath('data.comment.resolved', true);

        $ownerNote = $this->note($this->owner, $c, 'ملاحظةُ المالك');
        $this->withHeaders($V)->postJson('/api/mobile/v1/comments/' . $ownerNote->id . '/resolve')->assertForbidden();
        $this->assertNull($ownerNote->fresh()->resolved_at);

        // من يملك تعديلَ الوحدة يثبّت ويحلّ ما ليس له
        $E = $this->h($this->employee);
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $ownerNote->id . '/pin')->assertOk()
            ->assertJsonPath('data.comment.pinned', true);
        $this->assertTrue((bool) $ownerNote->fresh()->pinned);
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $ownerNote->id . '/pin')->assertOk()
            ->assertJsonPath('data.comment.pinned', false);
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $ownerNote->id . '/resolve')->assertOk()
            ->assertJsonPath('data.comment.resolved', true);
    }

    public function test_actions_on_a_record_outside_my_company_are_404(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);
        $foreign = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);
        $note = $this->note($this->owner, $foreign, 'سرُّ شركةِ باء');
        $E = $this->h($this->employee);

        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $note->id . '/pin')->assertNotFound();
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $note->id . '/resolve')->assertNotFound();
        $this->withHeaders($E)->postJson('/api/mobile/v1/comments/' . $note->id . '/to-task')->assertNotFound();
        $this->assertFalse((bool) $note->fresh()->pinned);
        $this->assertSame(0, Task::count(), 'نصُّ تعليقٍ لا يُقرأ بتحويله لمهمة');
    }

    public function test_to_task_requires_task_add_happens_once_and_replays_idempotently(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $note = $this->note($this->owner, $c, 'اتصل بالعميل لتأكيد الموعد');
        $url = '/api/mobile/v1/comments/' . $note->id . '/to-task';

        $this->withHeaders($this->h($this->viewer))->postJson($url)->assertForbidden();

        $E = $this->h($this->employee) + ['Idempotency-Key' => 'to-task-1'];
        $a = $this->withHeaders($E)->postJson($url)->assertStatus(201);
        $a->assertJsonPath('data.task.title', 'اتصل بالعميل لتأكيد الموعد')->assertJsonPath('data.target.module', 'tasks');
        $b = $this->withHeaders($E)->postJson($url)->assertStatus(201)->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.task.id'), $b->json('data.task.id'));
        $this->assertSame(1, Task::count());
        $this->assertSame($a->json('data.task.id'), (string) $note->fresh()->task_id);

        // بلا مفتاح (الترويساتُ تُصفّى) ⇒ الحارسُ نفسُه: حُوّل من قبل
        $this->flushHeaders();
        $this->withHeaders($this->h($this->employee))->postJson($url)->assertStatus(422);
    }

    public function test_delete_is_author_or_owner_only(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $empNote = $this->note($this->employee, $c, 'تعليقُ الموظفة');
        $viewerNote = $this->note($this->viewer, $c, 'تعليقُ المشاهد');

        $this->withHeaders($this->h($this->viewer))->deleteJson('/api/mobile/v1/comments/' . $empNote->id)->assertForbidden();
        $this->assertNotNull(Comment::find($empNote->id));

        $this->withHeaders($this->h($this->employee))->deleteJson('/api/mobile/v1/comments/' . $empNote->id)->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->assertNull(Comment::find($empNote->id));

        $this->withHeaders($this->h($this->owner))->deleteJson('/api/mobile/v1/comments/' . $viewerNote->id)->assertOk();
        $this->assertNull(Comment::find($viewerNote->id));
    }

    public function test_client_account_cannot_reach_comment_actions(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $c = Client::create(['name' => 'عميل']);
        $note = $this->note($this->owner, $c, 'داخليّ');
        $H = $this->h($client);

        $this->withHeaders($H)->patchJson('/api/mobile/v1/comments/' . $note->id, ['body' => 'x'])->assertNotFound();
        $this->withHeaders($H)->deleteJson('/api/mobile/v1/comments/' . $note->id)->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/comments/' . $note->id . '/pin')->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/comments/' . $note->id . '/to-task')->assertNotFound();
        $this->assertNotNull(Comment::find($note->id));
    }
}
