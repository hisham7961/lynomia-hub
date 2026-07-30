<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\HubNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * محرك التعليقات: على أي سجل من أي وحدة + قناة الفريق العامة (module=feed).
 * منشن @اسم يولّد إشعاراً، الرد يُشعر صاحب التعليق، تثبيت، تحويل لمهمة، سجل قراءة.
 */
class CommentController extends Controller
{
    /** قناة الفريق — منشورات عامة يراها كل مستخدم */
    public function feed()
    {
        $q = Comment::where('module', 'feed')->whereNull('parent_id')->with('user', 'replies.user');
        $posts = (clone $q)->orderByDesc('pinned')->orderByDesc('created_at')->paginate(15);

        $this->markRead($posts->getCollection());

        return view('feed.index', ['posts' => $posts, 'users' => $this->userNames()]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'module'    => ['required', 'string', 'max:60'],
            'record_id' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'exists:comments,id'],
            'body'      => ['required', 'string', 'max:4000'],
            'att'       => ['nullable', 'file', 'max:512000'],
            'mention'   => ['nullable', 'array'],
        ]);

        [$module, $recordId] = $this->guardTarget($data['module'], $data['record_id'] ?? null);

        $mentions = $this->extractMentions($data['body'], (array) $r->input('mention', []));

        $c = Comment::create([
            'module'     => $module,
            'record_id'  => $recordId,
            'parent_id'  => $data['parent_id'] ?? null,
            'user_id'    => auth()->id(),
            'body'       => $data['body'],
            'att'        => $r->hasFile('att') ? $r->file('att')->store('hub', 'local') : null,
            'mentions'   => $mentions ?: null,
            'read_by'    => [auth()->id()],
            'created_at' => now(),
        ]);

        $this->notifyAround($c);

        return back()->with('ok', 'نُشر التعليق')->withFragment('c-' . $c->id);
    }

    /** تثبيت/فك تثبيت — لمن يملك تعديل الوحدة (وللقناة: المالك أو صاحب علم monitor) */
    public function pin(string $id)
    {
        $c = Comment::findOrFail($id);
        $can = $c->module === 'feed'
            ? (auth()->user()->role?->is_owner || hub_flag(auth()->user(), 'monitor'))
            : hub_can(auth()->user(), $c->module, 'e');
        abort_unless($can, 403);

        $c->update(['pinned' => ! $c->pinned, 'updated_at' => now()]);

        return back()->with('ok', $c->pinned ? 'ثُبّت' : 'أُلغي التثبيت');
    }

    /** حذف — صاحب التعليق أو المالك */
    public function destroy(string $id)
    {
        $c = Comment::findOrFail($id);
        abort_unless($c->user_id === auth()->id() || auth()->user()->role?->is_owner, 403);
        $c->delete();

        return back()->with('ok', 'حُذف التعليق');
    }

    /** تحويل تعليق إلى مهمة — يرث مشروع السجل الأصلي إن وُجد */
    public function toTask(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, 'تحويل التعليقات لمهام يتطلب صلاحية إضافة مهام');
        $c = Comment::findOrFail($id);
        abort_if($c->task_id, 422, 'حُوّل هذا التعليق لمهمة من قبل');

        // مشروع المهمة: من عمود مشروع السجل الأصلي إن وُجد
        $projectId = null;
        if ($c->record_id && $c->module !== 'feed' && ($md = hub_mod($c->module)) && ($col = hub_project_col($c->module))) {
            $projectId = \Illuminate\Support\Facades\DB::table($md['table'])->where('id', $c->record_id)->value($col);
        }

        $task = Task::create([
            'title'       => Str::limit(trim(preg_replace('/\s+/u', ' ', $c->body)), 70),
            'project_id'  => $projectId,
            'assignee_id' => $c->mentions[0] ?? $c->user_id,
            'status'      => 'جديدة',
            'description' => $c->body . "\n\n— حُوّلت من تعليق بواسطة " . auth()->user()->name,
        ]);
        $c->update(['task_id' => $task->id, 'updated_at' => now()]);

        if ($task->assignee_id && $task->assignee_id !== auth()->id()) {
            $this->notify($task->assignee_id, 'assign',
                'أُسندت إليك مهمة من تعليق: ' . Str::limit($task->title, 60) . ' — بواسطة ' . auth()->user()->name,
                'tasks', $task->id);
        }

        return back()->with('ok', 'أُنشئت مهمة من التعليق');
    }

    /** تفاعل على تعليق: إيموجي واحد لكل مستخدم لكل رمز — الضغط ثانيةً يزيله */
    public function react(Request $r, string $id)
    {
        $c = Comment::findOrFail($id);
        $this->guardTarget($c->module, $c->record_id);          // يرى السجل = يتفاعل

        $emoji = (string) $r->input('emoji');
        abort_unless(in_array($emoji, self::REACTIONS, true), 422, 'تفاعل غير معروف');

        $q = \Illuminate\Support\Facades\DB::table('reactions')
            ->where('comment_id', $c->id)->where('user_id', auth()->id())->where('emoji', $emoji);

        if ($q->exists()) {
            $q->delete();
        } else {
            try {
                \Illuminate\Support\Facades\DB::table('reactions')->insert([
                    'id' => (string) Str::uuid(), 'comment_id' => $c->id,
                    'user_id' => auth()->id(), 'emoji' => $emoji, 'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // ضغطتان متزامنتان — القيد الفريد حسمها
            }
            if ($c->user_id !== auth()->id()) {
                $this->notify($c->user_id, 'react',
                    $emoji . ' تفاعل ' . auth()->user()->name . ' مع ' . ($c->module === 'feed' ? 'منشورك' : 'تعليقك') . ': ' . Str::limit(trim($c->body), 50),
                    $c->module, $c->record_id);
            }
        }

        return back()->withFragment('c-' . $c->id);
    }

    /** التفاعلات المتاحة — قائمة مغلقة كي لا تُحقن رموز عشوائية */
    public const REACTIONS = ['👍', '❤️', '🎉', '😂', '🤔', '🙏'];

    /** تفاعلات مجموعة تعليقات: [comment_id => [emoji => [أسماء]]] */
    public static function reactionsFor($comments): array
    {
        $ids = collect($comments)->flatMap(fn ($c) => [$c->id, ...($c->relationLoaded('replies') ? $c->replies->pluck('id') : [])]);
        if ($ids->isEmpty()) return [];

        $rows = \Illuminate\Support\Facades\DB::table('reactions')
            ->join('users', 'users.id', '=', 'reactions.user_id')
            ->whereIn('comment_id', $ids)->get(['comment_id', 'emoji', 'users.name', 'reactions.user_id']);

        $out = [];
        foreach ($rows as $r) $out[$r->comment_id][$r->emoji][] = ['name' => $r->name, 'id' => $r->user_id];

        return $out;
    }

    /* ────────── أدوات مشتركة (يستخدمها أيضاً عرض الوحدات) ────────── */

    /** تعليقات سجل مع الردود، ويسجَّل أن المستخدم الحالي قرأها */
    public static function forRecord(string $module, string $recordId)
    {
        $items = Comment::where('module', $module)->where('record_id', $recordId)
            ->whereNull('parent_id')->with('user', 'replies.user')
            ->orderByDesc('pinned')->orderBy('created_at')->get();

        (new self)->markRead($items);

        return $items;
    }

    /** أسماء المستخدمين للمنشن [id => name] */
    public static function userNames(): array
    {
        return User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    /* ────────── داخلي ────────── */

    /** التحقق من هدف التعليق وصلاحية رؤيته */
    protected function guardTarget(string $module, ?string $recordId): array
    {
        if ($module === 'feed') return ['feed', null];

        $def = hub_mod($module);
        abort_unless($def && $recordId, 404);
        abort_unless(hub_can(auth()->user(), $module, 'v'), 403);
        // ضمن نطاق المستخدم — الوصول لسجل خارج نطاقه = 404
        $class = '\\App\\Models\\' . $def['model'];
        hub_scope($class::query(), $module)->findOrFail($recordId);

        return [$module, $recordId];
    }

    /** المنشن: من القائمة الصريحة + @اسم في النص (تطابق بادئة الاسم) */
    protected function extractMentions(string $body, array $explicit): array
    {
        $users = self::userNames();
        $ids = array_values(array_intersect(array_keys($users), array_filter($explicit)));

        preg_match_all('/@([\p{Arabic}\w]+)/u', $body, $m);
        foreach ($m[1] ?? [] as $token) {
            foreach ($users as $uid => $name) {
                if (mb_stripos($name, $token) === 0) { $ids[] = $uid; break; }
            }
        }

        return array_values(array_unique(array_diff($ids, [auth()->id()])));
    }

    /** إشعارات التعليق: للمذكورين، ولصاحب التعليق الأصلي عند الرد */
    protected function notifyAround(Comment $c): void
    {
        $label = $c->module === 'feed' ? 'قناة الفريق' : (hub_mod($c->module)['label'] ?? $c->module);
        $excerpt = Str::limit(trim($c->body), 60);

        foreach ((array) $c->mentions as $uid) {
            $this->notify($uid, 'mention', 'ذكرك ' . auth()->user()->name . " في {$label}: {$excerpt}", $c->module, $c->record_id);
        }

        if ($c->parent_id) {
            $parent = Comment::find($c->parent_id);
            if ($parent && $parent->user_id !== auth()->id() && ! in_array($parent->user_id, (array) $c->mentions, true)) {
                $this->notify($parent->user_id, 'reply', 'ردّ ' . auth()->user()->name . " على تعليقك في {$label}: {$excerpt}", $c->module, $c->record_id);
            }
        }
    }

    protected function notify(string $uid, string $kind, string $text, ?string $module, ?string $recordId): void
    {
        HubNotification::create([
            'user_id' => $uid, 'kind' => $kind, 'text' => Str::limit($text, 590),
            'module' => $module === 'feed' ? null : $module, 'record_id' => $module === 'feed' ? null : $recordId,
            'read' => false, 'created_at' => now(),
        ]);
    }

    /** سجل القراءة: يُضاف المستخدم الحالي لمن قرأ (التعليقات والردود المعروضة) */
    protected function markRead($comments): void
    {
        $me = auth()->id();
        foreach ($comments as $c) {
            foreach ([$c, ...($c->relationLoaded('replies') ? $c->replies : [])] as $one) {
                $rb = (array) $one->read_by;
                if (! in_array($me, $rb, true)) {
                    $one->read_by = [...$rb, $me];
                    $one->saveQuietly();
                }
            }
        }
    }
}
