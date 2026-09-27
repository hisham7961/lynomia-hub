<?php

namespace App\Http\Controllers\Api;

use App\Models\Comment;
use App\Support\Collaboration\CommentActions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **أفعالُ التعليق على الجوال** (خطّة التطبيق · 4.3) — تحريرٌ وحذفٌ وتثبيتٌ وحلٌّ
 * وتحويلٌ لمهمة، كلُّها عبر `CommentActions` التي يستدعيها `CommentController`
 * الويبيّ حرفاً: الهدفُ مرئيٌّ (`guardTarget`)، والملكيّةُ/الإدارةُ بحكم الوحدة أو
 * القناة أو الخلاصة، و`tasks:a` للتحويل.
 *
 * **داخليّةٌ كالويب:** الأسماءُ `mobile.comment_actions.*` خارجَ قائمة
 * `MobilePortalGuard` البيضاء — فحسابُ العميل ٤٠٤ (نظيرُ `PortalGuard` الذي يردّه عن
 * `comments/*` في الويب)، والمتحكّمُ يعيد الطيَّ دفاعاً في العمق.
 */
class MobileCommentActionsController extends MobileWorkflowController
{
    /** `PATCH comments/{id}` — تحريرُ تعليقي (لصاحبه؛ المحوَّلُ لمهمةٍ ٤٢٢) */
    public function commentEdit(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;
        $c = Comment::findOrFail($id);
        CommentActions::guardEdit($r->user(), $c);   // الحرّاسُ قبل التحقّق (ترتيبُ الويب)

        $data = $r->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'النص']);
        $c = CommentActions::edit($r->user(), $c, $data['body']);

        return $this->ok(['comment' => $this->commentShape($c)]);
    }

    /** `DELETE comments/{id}` — حذفٌ (صاحبُه أو المالك) */
    public function commentDestroy(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        CommentActions::destroy($r->user(), Comment::findOrFail($id));

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /** `POST comments/{id}/pin` — تبديلُ التثبيت (مديرُ الوحدة/القناة/الخلاصة) */
    public function commentPin(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $c = CommentActions::togglePin($r->user(), Comment::findOrFail($id));

        return $this->ok(['comment' => $this->commentShape($c)]);
    }

    /** `POST comments/{id}/resolve` — تبديلُ الحلّ (صاحبُه أو مديرُه) */
    public function commentResolve(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        $c = CommentActions::toggleResolve($r->user(), Comment::findOrFail($id));

        return $this->ok(['comment' => $this->commentShape($c)]);
    }

    /** `POST comments/{id}/to-task` — تحويلٌ لمهمة (`tasks:a` + الهدفُ مرئيّ · مرّةً واحدة · Idempotency) */
    public function commentToTask(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        if ($deny = $this->denyClient()) return $deny;

        return $this->idempotent($r, function () use ($r, $id) {
            $c = Comment::findOrFail($id);
            $task = CommentActions::toTask($r->user(), $c);

            return $this->ok([
                'task' => [
                    'id' => (string) $task->id,
                    'title' => (string) $task->title,
                    'status' => (string) $task->status,
                    'project_id' => $task->project_id ? (string) $task->project_id : null,
                    'assignee_id' => $task->assignee_id ? (string) $task->assignee_id : null,
                ],
                'comment' => $this->commentShape($c->fresh() ?? $c),
                'target' => ['module' => 'tasks', 'id' => (string) $task->id],
            ], 201);
        });
    }

    /** بطاقةُ تعليقٍ بعد الفعل — الحالةُ الجديدةُ لما مسّه الفعل */
    private function commentShape(Comment $c): array
    {
        $c->loadMissing('user:id,name');

        return [
            'id' => (string) $c->id,
            'module' => (string) $c->module,
            'record_id' => $c->module !== 'feed' && $c->record_id !== null ? (string) $c->record_id : null,
            'parent_id' => $c->parent_id !== null ? (string) $c->parent_id : null,
            'user' => ['id' => (string) $c->user_id, 'name' => (string) ($c->user->name ?? '')],
            'body' => (string) $c->body,
            'pinned' => (bool) $c->pinned,
            'resolved' => $c->resolved_at !== null,
            'edited' => $c->edited_at !== null,
            'task_id' => $c->task_id ? (string) $c->task_id : null,
            'created_at' => self::iso($c->created_at),
        ];
    }
}
