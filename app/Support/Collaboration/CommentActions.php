<?php

namespace App\Support\Collaboration;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * **أفعالُ التعليق — القاعدةُ الواحدة للسطحَين** (§22/§28 · v2.123 · خطّة التطبيق 4.3).
 * مُستخرَجةٌ حرفاً من `CommentController` (تحرير · تثبيت · حلّ · حذف · تحويلٌ لمهمة)
 * كي يستدعيها الويبُ والجوال معاً — كلُّ فعلٍ بحرّاسه كما كانت:
 *
 *  • **تحرير:** لصاحبه وحده (٤٠٣)، والهدفُ ما زال مرئيّاً (`guardTarget` — قناةٌ لم
 *    يعد عضواً فيها ٤٠٤)، والمحوَّلُ لمهمةٍ لا يُحرَّر (٤٢٢).
 *  • **تثبيت:** الهدفُ ضمن النطاق، ثمّ الخلاصةُ لحامل `monitor`، والقناةُ لمالكها/مشرفها،
 *    وغيرُهما لمن يملك تعديلَ الوحدة.
 *  • **حلّ:** الهدفُ ضمن النطاق، ثمّ صاحبُ التعليق أو من يملك التثبيتَ نفسَه.
 *  • **حذف:** صاحبُ التعليق أو المالك.
 *  • **تحويلٌ لمهمة:** `tasks:a` + الهدفُ مرئيّ + لم يُحوَّل قبلاً؛ وراثةُ المشروع
 *    والشركة والعميل من السجلّ الأصل أو من نطاق المحوِّل (v2.399).
 */
final class CommentActions
{
    /** **تحريرُ تعليقي** — يعيد التعليقَ المُحرَّر (ختمُ `edited_at` + إعادةُ استخلاص الإشارات) */
    public static function edit(User $actor, Comment $c, string $body): Comment
    {
        self::guardEdit($actor, $c);

        return CommentService::edit($actor, $c, trim($body));
    }

    /** حرّاسُ التحرير وحدها — يستدعيها السطحُ قبل التحقّق من الجسم (ترتيبُ الويب) */
    public static function guardEdit(User $actor, Comment $c): void
    {
        abort_unless((string) $c->user_id === (string) $actor->getKey(), 403, 'التحريرُ لصاحب الرسالة وحده');
        CommentService::guardTarget($actor, (string) $c->module, $c->record_id);
        abort_if($c->task_id, 422, 'حُوّل هذا التعليق لمهمة — لا يُحرَّر');
    }

    /** مَن يملك إدارةَ تعليقٍ (تثبيتاً وحلّاً) بحكم الوحدة/القناة/الخلاصة */
    public static function canModerate(User $actor, Comment $c): bool
    {
        return match ((string) $c->module) {
            'feed'    => hub_monitor($actor),
            'channel' => Conversation::roleCanManage(Conversation::roleOf((string) $c->record_id, (string) $actor->getKey())),
            default   => hub_can($actor, (string) $c->module, 'e'),
        };
    }

    /** **تثبيتٌ/فكّ** — يعيد التعليقَ بحالته الجديدة (وبيانُ من ثبّت ومتى حين وُجد عموده) */
    public static function togglePin(User $actor, Comment $c): Comment
    {
        CommentService::guardTarget($actor, (string) $c->module, $c->record_id);
        abort_unless(self::canModerate($actor, $c), 403);

        $now = ! $c->pinned;
        $attrs = ['pinned' => $now, 'updated_at' => now()];
        if (hub_has_col('comments', 'pinned_at')) {
            $attrs['pinned_at'] = $now ? now() : null;
            $attrs['pinned_by'] = $now ? $actor->getKey() : null;
        }
        $c->update($attrs);

        return $c;
    }

    /** **حلٌّ/إعادةُ فتح** — صاحبُ التعليق أو من يملك إدارتَه */
    public static function toggleResolve(User $actor, Comment $c): Comment
    {
        CommentService::guardTarget($actor, (string) $c->module, $c->record_id);
        $can = (string) $c->user_id === (string) $actor->getKey() || self::canModerate($actor, $c);
        abort_unless($can, 403);

        $done = $c->resolved_at === null;
        $c->update(['resolved_at' => $done ? now() : null,
            'resolved_by' => $done ? $actor->getKey() : null, 'updated_at' => now()]);

        return $c;
    }

    /** **حذف** — صاحبُ التعليق أو المالك */
    public static function destroy(User $actor, Comment $c): void
    {
        abort_unless((string) $c->user_id === (string) $actor->getKey() || hub_is_owner($actor), 403);
        $c->delete();
    }

    /**
     * **تحويلُ تعليقٍ إلى مهمة** — يرث مشروعَ السجلّ الأصل وشركتَه وعميلَه (أو نطاقَ
     * المحوِّل)، ويُسنَد لأوّلِ مذكورٍ أو لكاتبه، ويُشعَر المُسنَدُ إليه.
     */
    public static function toTask(User $actor, Comment $c): Task
    {
        abort_unless(hub_can($actor, 'tasks', 'a'), 403, 'تحويل التعليقات لمهام يتطلب صلاحية إضافة مهام');
        CommentService::guardTarget($actor, (string) $c->module, $c->record_id);
        abort_if($c->task_id, 422, 'حُوّل هذا التعليق لمهمة من قبل');

        $projectId = null;
        if ($c->record_id && $c->module !== 'feed' && ($md = hub_mod($c->module)) && ($col = hub_project_col($c->module))) {
            $projectId = \Illuminate\Support\Facades\DB::table($md['table'])->where('id', $c->record_id)->value($col);
        }

        $inherit = [];
        if ($c->record_id && $c->module !== 'feed' && ($md0 = hub_mod($c->module))) {
            foreach (['company_id' => hub_company_col($c->module), 'client_id' => hub_client_col($c->module)] as $k => $col) {
                if ($col && hub_has_col('tasks', $k)) {
                    $inherit[$k] = \Illuminate\Support\Facades\DB::table($md0['table'])->where('id', $c->record_id)->value($col);
                }
            }
        }
        if (empty($inherit['company_id']) && hub_has_col('tasks', 'company_id') && ($cids = hub_company_ids($actor)) !== null && $cids) $inherit['company_id'] = $cids[0];
        if (empty($inherit['client_id']) && hub_has_col('tasks', 'client_id') && ($kids = hub_client_ids($actor)) !== null && $kids) $inherit['client_id'] = $kids[0];

        $task = Task::create(array_filter($inherit) + [
            'title'       => Str::limit(trim(preg_replace('/\s+/u', ' ', $c->body)), 70),
            'project_id'  => $projectId,
            'assignee_id' => $c->mentions[0] ?? $c->user_id,
            'status'      => 'جديدة',
            'description' => $c->body . "\n\n— حُوّلت من تعليق بواسطة " . $actor->name,
        ]);
        $c->update(['task_id' => $task->id, 'updated_at' => now()]);

        if ($task->assignee_id && (string) $task->assignee_id !== (string) $actor->getKey()) {
            CommentService::notify($task->assignee_id, 'assign',
                'أُسندت إليك مهمة من تعليق: ' . Str::limit($task->title, 60) . ' — بواسطة ' . $actor->name,
                'tasks', $task->id);
        }

        return $task;
    }
}
